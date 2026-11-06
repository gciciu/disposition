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
            ->leftJoin('r.warehouse', 'w')
            ->addSelect('w')
            ->leftJoin('r.orders', 'o')
            ->addSelect('o')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Courier-facing list: today and future scheduled routes only.
     *
     * @return list<DeliveryRoute>
     */
    public function findAssignedToCourierFromToday(Courier $courier): array
    {
        $today = new \DateTimeImmutable('today');

        return $this->createQueryBuilder('r')
            ->andWhere('r.courier = :courier')
            ->andWhere('r.scheduledDate IS NOT NULL')
            ->andWhere('r.scheduledDate >= :today')
            ->setParameter('courier', $courier)
            ->setParameter('today', $today)
            ->orderBy('r.scheduledDate', 'ASC')
            ->addOrderBy('r.createdAt', 'DESC')
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

    public function findOneByCourierAndScheduledDate(
        Courier $courier,
        \DateTimeImmutable $scheduledDate,
        ?int $excludeRouteId = null,
    ): ?DeliveryRoute {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.courier = :courier')
            ->andWhere('r.scheduledDate = :scheduledDate')
            ->setParameter('courier', $courier)
            ->setParameter('scheduledDate', $scheduledDate)
            ->setMaxResults(1);

        if (null !== $excludeRouteId) {
            $qb->andWhere('r.id != :excludeId')
                ->setParameter('excludeId', $excludeRouteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return list<DeliveryRoute>
     */
    public function findByCourierAndDateRange(
        Courier $courier,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?int $excludeRouteId = null,
    ): array {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.courier = :courier')
            ->andWhere('r.scheduledDate IS NOT NULL')
            ->andWhere('r.scheduledDate >= :from')
            ->andWhere('r.scheduledDate <= :to')
            ->setParameter('courier', $courier)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.scheduledDate', 'ASC');

        if (null !== $excludeRouteId) {
            $qb->andWhere('r.id != :excludeId')
                ->setParameter('excludeId', $excludeRouteId);
        }

        return $qb->getQuery()->getResult();
    }
}
