<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Project> */
final class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    public function findActiveOwned(int $id, User $owner): ?Project
    {
        return $this->findOneBy(['id' => $id, 'owner' => $owner, 'active' => true]);
    }

    /** @return array{items: list<Project>, total: int} */
    public function searchOwned(User $owner, array $filters, int $page, int $limit): array
    {
        $query = $this->createQueryBuilder('p')->andWhere('p.owner = :owner')->andWhere('p.active = :active')->setParameter('owner', $owner)->setParameter('active', true);
        if (isset($filters['name'])) {
            $query->andWhere('p.name LIKE :name')->setParameter('name', '%'.$filters['name'].'%');
        }
        if (isset($filters['deliveryDateMin'])) {
            $query->andWhere('p.deliveryDate >= :min')->setParameter('min', $filters['deliveryDateMin']);
        }
        if (isset($filters['deliveryDateMax'])) {
            $query->andWhere('p.deliveryDate <= :max')->setParameter('max', $filters['deliveryDateMax']);
        }
        $total = (int) (clone $query)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
        $items = $query->orderBy('p.id', 'ASC')->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();
        return ['items' => $items, 'total' => $total];
    }
}