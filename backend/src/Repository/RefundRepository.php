<?php

namespace App\Repository;

use App\Entity\Refund;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Refund>
 */
class RefundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Refund::class);
    }

    /**
     * Trouver les remboursements en attente
     */
    public function findPendingRefunds(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.statut = :statut')
            ->setParameter('statut', 'En attente')
            ->orderBy('r.dateCreation', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver les remboursements obligatoires
     */
    public function findObligatoryRefunds(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.obligatoire = :val')
            ->setParameter('val', true)
            ->andWhere('r.statut = :statut')
            ->setParameter('statut', 'En attente')
            ->orderBy('r.dateCreation', 'ASC')
            ->getQuery()
            ->getResult();
    }
}