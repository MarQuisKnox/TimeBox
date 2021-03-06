<?php

namespace TimeBox\MainBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use TimeBox\MainBundle\Entity\File;
use TimeBox\MainBundle\Entity\Folder;
use TimeBox\MainBundle\Entity\Version;

use TimeBox\MainBundle\Form\FileType;
use \ZipArchive;

class FileController extends Controller
{
    /**
     * Get current logged user.
     *
     */
    public function getConnectedUser()
    {
        $user = $this->container->get('security.context')->getToken()->getUser();

        if (!is_object($user)) {
            throw new AccessDeniedException('You are not logged in.');
        }

        return $user;
    }


    /**
     * Show files for current logged user.
     *
     * @param Folder   $folderId   The current folder id.
     * @param Boolean  $isDeleted  Switch view between deleted files and files not deleted.
     */
    public function showAction($folderId, $isDeleted)
    {
        $user = $this->getConnectedUser();
        $em = $this->getDoctrine()->getManager();

        $files = $em->getRepository('TimeBoxMainBundle:File')->getRootFiles($user, $folderId, $isDeleted);

        $folders = $em->getRepository('TimeBoxMainBundle:Folder')->findBy(array(
            'parent' => $folderId,
            'user' => $user,
            'isDeleted' => $isDeleted
        ));

        // generate breadcrumb for current folder
        // retrieve sorted folders parents list in an array
        $breadcrumb = array();
        if (!is_null($folderId)) {
            $currentFolder = $em->getRepository('TimeBoxMainBundle:Folder')->find($folderId);
            if (!is_null($currentFolder)) {
                $breadcrumb[] = $currentFolder;
                $parent = $currentFolder->getParent();
                while (!is_null($parent)) {
                    $breadcrumb[] = $parent;
                    $parent = $parent->getParent();
                }
                $breadcrumb = array_reverse($breadcrumb);
            }
        }

        // types that have an icon
        $types = array(
            "avi", "bmp", "css", "doc", "gif", "htm", "jpg", "js", "mov", "mp3", "mp4",
            "mpg", "pdf", "php", "png", "ppt", "rar", "txt", "xls", "xml", "zip"
        );

        return $this->render('TimeBoxMainBundle:File:show.html.twig', array(
            "files" => $files,
            "types" => $types,
            "user" => $user,
            "folderId" => $folderId,
            "folders" => $folders,
            "breadcrumb" => $breadcrumb,
            "isDeleted" => $isDeleted
        ));
    }


    /**
     * Delete one or more files or folders.
     *
     */
    public function deleteAction()
    {
        $user = $this->getConnectedUser();

        $em = $this->getDoctrine()->getManager();

        $currentFolderId = null;

        $request = $this->get('request');
        if ($request->getMethod() == 'POST') {
            $foldersId = $request->request->get('foldersId');
            $foldersId = json_decode($foldersId);
            $filesId = $request->request->get('filesId');
            $filesId = json_decode($filesId);
            $currentFolderId = $request->request->get('currentFolderId');
            $permanentDelete = $request->request->get('permanentDelete');
            $permanentDelete = ($permanentDelete == "true") ? true : false;

            if (!is_null($filesId) && sizeof($filesId)>0) {
                $filesToDelete = $em->getRepository('TimeBoxMainBundle:File')->findBy(array(
                    'id'   => $filesId,
                    'user' => $user
                ));
                foreach ($filesToDelete as $file) {
                    if ($permanentDelete) {
                        $user->setStorage(max($user->getStorage() - $file->getTotalSize(), 0));
                        $em->persist($user);
                        $em->remove($file);
                    }
                    else {
                        $file->setIsDeleted(true);
                        $file->setFolder();
                    }
                }
                $em->flush();
            }

            if (!is_null($foldersId) && sizeof($foldersId)>0) {
                $foldersToDelete = $em->getRepository('TimeBoxMainBundle:Folder')->findBy(array(
                    'id'   => $foldersId,
                    'user' => $user
                ));
                foreach ($foldersToDelete as $folder) {
                    if ($permanentDelete) {
                        $em->remove($folder);
                    }
                    else {
                        $folder->setParent();
                        $this->manageFolderContent($folder, true);
                    }
                }
                $em->flush();
            }
        }

        $url = $this->get('router')->generate('time_box_main_file_deleted', array(
            'folderId' => $currentFolderId
        ));
        return new Response($url);
    }


    /**
     * Add a folder, its contents and children to a zip archive's subdirectory.
     *
     * @param Folder       $folder The entity
     * @param Zip          $zip    The archive
     * @param Subdirectory $subdir The subdirectory (optional)
     */
    private function addFolderToZip($folder, $zip, $subdir = "")
    {
        $user = $this->getConnectedUser();
        $em = $this->getDoctrine()->getManager();

        $folderPath = $folder->getName();
        if ($subdir != "")
            $folderPath = $subdir.'/'.$folder->getName();

        $zip->addEmptyDir($folderPath);

        $contents = $folder->getFiles();
        foreach ($contents as $content) {
            $f = $em->getRepository('TimeBoxMainBundle:Version')
                    ->getLastestFileVersion($user, $content);

            $version = $f[0];
            $file = $version->getFile();

            $filePath = $version->getAbsolutePath();
            $filename = $file->getName();
            $type = $file->getType();
            if (!is_null($type)) {
                $filename .= '.'.$type;
            }

            $zip->addFile($filePath, $folderPath.'/'.$filename);
        }

        $children = $folder->getChildren();

        foreach ($children as $child) {
            $this->addFolderToZip($child, $zip, $folderPath);
        }
    }


    /**
     * Download a file, or a zip archive with all files when user is logged.
     *
     */
    public function downloadAction()
    {
        $user = $this->getConnectedUser();

        $em = $this->getDoctrine()->getManager();

        $request = $this->get('request');

        if ($request->getMethod() == 'POST') {
            $foldersId = $request->request->get('foldersId');
            $foldersId = json_decode($foldersId);
            $filesId = $request->request->get('filesId');
            $filesId = json_decode($filesId);

            if ((!is_null($filesId) && sizeof($filesId) > 0) ||
                (!is_null($foldersId) && sizeof($foldersId) > 0)) {
                $filesToDownload = $em->getRepository('TimeBoxMainBundle:Version')->getLastestFileVersion($user, $filesId);
                $foldersToDownload = $em->getRepository('TimeBoxMainBundle:Folder')->findBy(array(
                    'id'   => $foldersId,
                    'user' => $user
                ));

                if (!is_null($filesToDownload)) {
                    // One file and no folder is requested
                    if (sizeof($filesToDownload) == 1 && sizeof($foldersToDownload) == 0) {
                        $version = $filesToDownload[0];
                        $file = $version->getFile();

                        $filePath = $version->getAbsolutePath();
                        $filename = $file->getName();
                        $type = $file->getType();
                        if (!is_null($type)) {
                            $filename .= '.'.$type;
                        }

                        if (!file_exists($filePath)) {
                            throw $this->createNotFoundException();
                        }

                        // Trigger file download
                        $response = new Response();
                        $response->headers->set('Content-type', 'application/octet-stream');
                        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
                        $response->setContent(file_get_contents($filePath));
                        return $response;
                    }

                    // Create zip folder on server if not exist
                    $zipFolder = $this->get('kernel')->getRootDir() . '/../web/uploads/zip/';
                    if (!file_exists($zipFolder)) {
                        mkdir($zipFolder, 0755, true);
                    }

                    // Create zip archive
                    $zip = new ZipArchive();
                    $zipName = 'TimeBoxDownloads-'.time().'.zip';
                    $zipPath = $zipFolder . $zipName;
                    $zip->open($zipPath, ZipArchive::CREATE);

                    // Fill zip with folders
                    foreach($foldersToDownload as $folder){
                        $this->addFolderToZip($folder, $zip);
                    }

                    // Fill zip with files
                    foreach ($filesToDownload as $f) {
                        $version = $f;
                        $file = $version->getFile();

                        $filePath = $version->getAbsolutePath();
                        $filename = $file->getName();
                        $type = $file->getType();
                        if (!is_null($type)) {
                            $filename .= '.'.$type;
                        }

                        $zip->addFile($filePath, $filename);
                    }

                    $zip->close();

                    // Trigger file download
                    $response = new Response();
                    $response->headers->set('Content-type', 'application/octet-stream');
                    $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $zipName));
                    $response->setContent(file_get_contents($zipPath));
                    return $response;
                }
            }
        }
        return new Response('An error has occured.');
    }


    /**
     * Download a file with a shared download link without being logged.
     *
     * @param String $hash The download hash
     */
    public function downloadFileAction($hash)
    {
        $em = $this->getDoctrine()->getManager();

        $link = $em->getRepository('TimeBoxMainBundle:Link')->findOneByDownloadHash($hash);
        if (!$link) {
            return $this->redirect($this->generateUrl('fos_user_registration_register'));
        }

        $file = $link->getFile();
        $version = $link->getVersion();

        if (!is_null($file)) {
            $fileId = $file->getId();
            $version = $em->getRepository('TimeBoxMainBundle:Version')->getLastestFileVersionById($fileId);
        }

        if (!is_null($version)) {
            $file = $version->getFile();
            $filename = $file->getName();
            $type = $file->getType();
            if (!is_null($type)) {
                $filename .= '.'.$type;
            }

            $filePath = $version->getAbsolutePath();
            if (!file_exists($filePath)) {
                throw $this->createNotFoundException();
            }

            // Trigger file download
            $response = new Response();
            $response->headers->set('Content-type', 'application/octet-stream');
            $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
            $response->setContent(file_get_contents($filePath));
            return $response;
        }

        return $this->redirect($this->generateUrl('fos_user_registration_register'));
    }


    /**
     * Delete or restore folder contents recursively.
     *
     * @param Folder  $folder The entity
     * @param Boolean $bool   true:  delete
     *                        false: restore
     */
    private function manageFolderContent($folder, $bool)
    {
        $folder->setIsDeleted($bool);
        $files = $folder->getFiles();
        $children = $folder->getChildren();
        if (!is_null($files) && sizeof($files)>0) {
            foreach($files as $file) {
                $file->setIsDeleted($bool);
            }
        }
        if (!is_null($children) && sizeof($children)>0) {
            foreach($children as $child) {
                $child->setIsDeleted($bool);
                $this->manageFolderContent($child, $bool);
            }
        }
    }


    /**
     * Move one or more files or folders to another folder.
     *
     */
    public function moveAction()
    {
        $user = $this->getConnectedUser();
        $em = $this->getDoctrine()->getManager();

        $request = $this->get('request');
        if ($request->getMethod() == 'POST') {
            $currentFolderId = $request->request->get('currentFolderId');
            $moveFolderId = $request->request->get('moveFolderId');
            $foldersId = $request->request->get('foldersId');
            $foldersId = json_decode($foldersId);
            $filesId = $request->request->get('filesId');
            $filesId = json_decode($filesId);

            if (!is_null($moveFolderId)) {
                $parent = null;
                if (is_numeric($moveFolderId)) {
                    $parent = $em->getRepository('TimeBoxMainBundle:Folder')->findOneById($moveFolderId);
                    if (!$parent) {
                        throw $this->createNotFoundException('Unable to find Folder entity.');
                    }
                }

                $files = $em->getRepository('TimeBoxMainBundle:File')->findBy(array(
                    'user' => $user,
                    'id' => $filesId
                ));
                $folders = $em->getRepository('TimeBoxMainBundle:Folder')->findBy(array(
                    'user' => $user,
                    'id' => $foldersId
                ));

                if (!is_null($files) && sizeof($files) > 0) {
                    foreach ($files as $file) {
                        is_null($parent) ? $file->setFolder() : $file->setFolder($parent);
                        $file->setIsDeleted(false);
                    }
                }
                if (!is_null($folders) && sizeof($folders) > 0) {
                    foreach ($folders as $folder) {
                        is_null($parent) ? $folder->setParent() : $folder->setParent($parent);
                        $this->manageFolderContent($folder, false);
                    }
                }

                $em->flush();

                return $this->redirect($this->generateUrl('time_box_main_file', array(
                    'folderId' => $moveFolderId
                )));
            }

            $filesId = json_encode($filesId);
            $foldersId = json_encode($foldersId);

            $folders = $em->getRepository('TimeBoxMainBundle:Folder')->findBy(
                array(
                    'user' => $user,
                    'isDeleted' => false
                ),
                array(
                    'parent' => 'ASC',
                    'name' => 'ASC'
                )
            );

            return $this->render('TimeBoxMainBundle:File:move.html.twig', array(
                'folders'   => $folders,
                'folderId'  => $currentFolderId,
                'filesId'   => $filesId,
                'foldersId' => $foldersId
            ));
        }
        return new Response('');
    }


    /**
     * Rename a file or a folder.
     *
     */
    public function renameAction()
    {
        $user = $this->getConnectedUser();
        $em = $this->getDoctrine()->getManager();

        $request = $this->get('request');
        if ($request->getMethod() == 'POST') {
            $currentFolderId = $request->request->get('currentFolderId');
            $newName = $request->request->get('newName');
            $foldersId = $request->request->get('foldersId');
            $foldersId = json_decode($foldersId);
            $filesId = $request->request->get('filesId');
            $filesId = json_decode($filesId);

            if (!is_null($newName)) {

                $files = $em->getRepository('TimeBoxMainBundle:File')->findBy(array(
                    'user' => $user,
                    'id' => $filesId
                ));
                $folders = $em->getRepository('TimeBoxMainBundle:Folder')->findBy(array(
                    'user' => $user,
                    'id' => $foldersId
                ));

                if (!is_null($files) && sizeof($files) > 0) {
                    foreach ($files as $file) {
                        $file->setName($newName);
                        $file->setIsDeleted(false);
                    }
                }
                if (!is_null($folders) && sizeof($folders) > 0) {
                    foreach ($folders as $folder) {
                        $folder->setName($newName);
                        $this->manageFolderContent($folder, false);
                    }
                }

                $em->flush();

                return $this->redirect($this->generateUrl('time_box_main_file', array(
                    'folderId' => $currentFolderId
                )));
            }

            $filesId = json_encode($filesId);
            $foldersId = json_encode($foldersId);

            return $this->render('TimeBoxMainBundle:File:rename.html.twig', array(
                'folderId'  => $currentFolderId,
                'filesId'   => $filesId,
                'foldersId' => $foldersId
            ));
        }
        return new Response('');
    }


    /**
     * Upload a new file.
     * @Template()
     *
     * @param Request  $request    The request.
     */
    public function uploadAction(Request $request)
    {
        $user = $this->getConnectedUser();

        $version = new Version();
        $form = $this->createFormBuilder($version)
            ->add('uploadedFile')
            ->add('comment', 'textarea', array('required' => false))
            ->getForm();

        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();

            $filename = $request->request->get('name');
            $filetype = $version->getUploadType();
            $size = $version->getUploadSize();

            $folder = null;
            $folderId = $request->request->get('folderId');
            if (is_numeric($folderId)) {
                $folder = $em->getRepository('TimeBoxMainBundle:Folder')->findOneById($folderId);
            }

            $existingFile = $em->getRepository('TimeBoxMainBundle:File')->findOneBy(array(
                'user'   => $user,
                'folder' => $folder,
                'name' => $filename,
                'type' => $filetype,
                'isDeleted' => false
            ));

            $versionDisplayId = 1;

            if (!$existingFile) {
                $file = new File();
                $file->setUser($user);
                $file->setName($filename);
                $file->setType($filetype);
                $file->setFolder($folder);
                $file->setTotalSize($size);
                $em->persist($file);
                $em->flush();
            }
            else {
                $file = $existingFile;
                $file->setTotalSize($file->getTotalSize() + $size);
                $lastVersion = $em->getRepository('TimeBoxMainBundle:Version')->findOneBy(
                    array('file' => $file),
                    array('displayId' => 'DESC')
                );
                $versionDisplayId = $lastVersion->getDisplayId() + 1;
            }

            $version->setDate(new \DateTime);
            $version->setFile($file);
            $version->setSize($size);
            $version->setDisplayId($versionDisplayId);
            $version->setDescription("Uploaded file");

            $user->setStorage(max($user->getStorage() + $size, 0));

            $em->persist($user);
            $em->persist($version);
            $em->flush();

            return $this->redirect($this->generateUrl('time_box_main_file'));
        }

        return array(
            'form' => $form->createView(),
            'folderId' => $request->request->get('folderId')
        );
    }
}
