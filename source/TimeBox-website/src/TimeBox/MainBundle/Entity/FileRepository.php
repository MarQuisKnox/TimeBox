<?php

namespace TimeBox\MainBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * ProjectRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class FileRepository extends EntityRepository
{
    public function getRootFiles($user, $folderId, $isDeleted)
    {
        $param = array(
            'user' => $user,
            'isDeleted' => $isDeleted
        );
        if (is_null($folderId)) {
            $sql = 'AND f.folder IS NULL';
        }
        else {
            $sql = 'AND f.folder = :folderId';
            $param['folderId'] = $folderId;
        }
        $query = $this->getEntityManager()
            ->createQuery('
                SELECT f.id, f.name, f.type, MAX(v.date) as date, v.size
                FROM TimeBoxMainBundle:File f
                JOIN f.version v
                WHERE f.user = :user
                AND f.isDeleted = :isDeleted
                '.$sql.'
                GROUP BY f.id
              ')->setParameters($param);
        try {
            return $query->getResult();
        } catch (\Doctrine\ORM\NoResultException $e) {
            return null;
        }
    }
}