<?php

namespace App\Repository;

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
        return $this->findBy([], ['createdAt' => 'DESC']);
    }
}
