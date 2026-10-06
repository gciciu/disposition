<?php

namespace App\Repository;

use App\Entity\Courier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Courier>
 */
class CourierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Courier::class);
    }

    /**
     * @return list<Courier>
     */
    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.user', 'u')
            ->addSelect('u')
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
