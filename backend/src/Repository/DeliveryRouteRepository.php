<?php

namespace App\Repository;

use App\Entity\Courier;
use App\Entity\DeliveryRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeliveryRoute>
 */
class DeliveryRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliveryRoute::class);
    }

    /**
     * @return list<DeliveryRoute>
     */
    public function findAllNewestFirst(): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.courier', 'c')
            ->addSelect('c')
            ->leftJoin('c.user', 'u')
            ->addSelect('u')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<DeliveryRoute>
     */
    public function findAssignedToCourierNewestFirst(Courier $courier): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.courier = :courier')
            ->setParameter('courier', $courier)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneAssignedToCourier(int $id, Courier $courier): ?DeliveryRoute
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.id = :id')
            ->andWhere('r.courier = :courier')
            ->setParameter('id', $id)
            ->setParameter('courier', $courier)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
