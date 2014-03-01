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

class FileController extends Controller
{
    public function getConnectedUser()
    {
        $user = $this->container->get('security.context')->getToken()->getUser();

        if (!is_object($user)) {
            throw new AccessDeniedException('You are not logged in.');
        }

        return $user;
    }

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

        $url = $this->get('router')->generate('time_box_main_file', array(
            'folderId' => $currentFolderId
        ));
        return new Response($url);
    }

    /**
     * Delete or restore folder contents.
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
                    'user' => $user
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
     * @Template()
     */
    public function uploadAction(Request $request, $folderId)
    {
        $user = $this->getConnectedUser();

        $file = new File();
        $version = new Version();
        $form = $this->createFormBuilder($version)
            ->add('file', new FileType)
            ->add('comment', 'textarea', array('required' => false))
            ->getForm();

        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();

            $file = $version->getFile();

            $folderId = $request->request->get('folderId');
            if (is_numeric($folderId)) {
                $folder = $em->getRepository('TimeBoxMainBundle:Folder')->findOneById($folderId);
                if ($folderId) {
                    $file->setFolder($folder);
                }
            }

            $file->setUser($user);
            $file->setUploadName();
            $file->setUploadType();
            $size = $file->getUploadSize();

            $existingFile = $em->getRepository('TimeBoxMainBundle:File')->findOneBy(array(
                'user' => $file->getUser(),
                'folder' => $file->getFolder(),
                'name' => $file->getName(),
                'type' => $file->getType()
            ));

            $versionDisplayId = 0;

            if ($existingFile == null) {
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

            $user->setStorage(max($user->getStorage() + $size, 0));

            $em->persist($user);
            $em->persist($version);
            $em->flush();

            return $this->redirect($this->generateUrl('time_box_main_file'));
        }

        return array(
            'form' => $form->createView(),
            'folderId' => $folderId
        );
    }
}
