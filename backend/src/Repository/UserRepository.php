<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Nombre total d'utilisateurs.
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre d'utilisateurs ayant un rôle donné.
     * Utilisation de findAll() + filtre PHP pour éviter les problèmes avec le champ JSON.
     */
    public function countByRole(string $role): int
    {
        $users = $this->findAll();
        $count = 0;
        foreach ($users as $user) {
            // La méthode getRoles() retourne un tableau, on vérifie la présence du rôle
            if (in_array($role, $user->getRoles(), true)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Nombre d'utilisateurs créés depuis une date donnée.
     */
    public function countNewSince(\DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre d'utilisateurs inscrits depuis le début du mois en cours.
     */
    public function countNewThisMonth(): int
    {
        return $this->countNewSince(new \DateTimeImmutable('first day of this month 00:00:00'));
    }

    /**
     * Derniers utilisateurs inscrits (les plus récents en premier).
     *
     * @return User[]
     */
    public function findRecent(int $limit = 5): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}