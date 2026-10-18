<?php

namespace App\Repository;

use App\Entity\Warehouse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Warehouse>
 */
class WarehouseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Warehouse::class);
    }

    /**
     * @return list<Warehouse>
     */
    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('w')
            ->orderBy('w.isDefault', 'DESC')
            ->addOrderBy('w.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findDefault(): ?Warehouse
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.isDefault = :true')
            ->setParameter('true', true)
            ->orderBy('w.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function clearDefaultExcept(?Warehouse $except = null): void
    {
        $qb = $this->createQueryBuilder('w')
            ->update()
            ->set('w.isDefault', ':false')
            ->where('w.isDefault = :true')
            ->setParameter('false', false)
            ->setParameter('true', true);

        if (null !== $except?->getId()) {
            $qb->andWhere('w.id != :id')->setParameter('id', $except->getId());
        }

        $qb->getQuery()->execute();
    }
}
