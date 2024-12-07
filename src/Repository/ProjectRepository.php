<?php

namespace App\Repository;

use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    public function searchProjects($filter){
        $dql = 'SELECT p 
            FROM App\Entity\Project p ';

        if(!empty($filter['name'])){
            $where[] = " p.name LIKE :name";
            $params["name"] = "%" .$filter['name']. "%";
        }

        if(!empty($filter['deliveryDateMin'])){
            $where[] = " p.deliveryDate >= :deliveryDateMin";
            $params["deliveryDateMin"] = $filter['deliveryDateMin'];
        }

        if(!empty($filter['deliveryDateMax'])){
            $where[] = " p.deliveryDate <= :deliveryDateMax";
            $params["deliveryDateMax"] = $filter['deliveryDateMax'];
        }
        
        if (!empty($where)) {
            $dql .= ' WHERE ' . implode(' AND ', $where);
        }

        return $this->getEntityManager()
            ->createQuery($dql)
            ->setParameters($params)
            // ->setMaxResults(4000)
            ->getArrayResult();
    }

    //    /**
    //     * @return Project[] Returns an array of Project objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Project
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
