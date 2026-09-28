<?php

namespace App\Repository;

use App\Entity\ActivityLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ActivityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityLog::class);
    }

    public function findRecent(int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByAction(string $action, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.action = :action')
            ->setParameter('action', $action)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.entityType = :entityType')
            ->setParameter('entityType', $entityType)
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityId', $entityId)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByUser(int $userId, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.user', 'u')
            ->andWhere('u.id = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findFiltered(?string $action, ?int $userId, int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($action) {
            $qb->andWhere('a.action = :action')
               ->setParameter('action', $action);
        }

        if ($userId) {
            $qb->join('a.user', 'u')
               ->andWhere('u.id = :userId')
               ->setParameter('userId', $userId);
        }

        return $qb->getQuery()->getResult();
    }

    public function getStatsByAction(): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(
                'SELECT action, COUNT(id) AS count
                 FROM activity_log
                 GROUP BY action
                 ORDER BY count DESC, action ASC'
            );

        return array_map(static function (array $row): array {
            return [
                'action' => $row['action'],
                'count' => (int) ($row['count'] ?? 0),
            ];
        }, $rows);
    }

    public function getLastDaysActivity(int $days = 7): array
    {
        $date = new \DateTimeImmutable("-$days days");
        
        return $this->createQueryBuilder('a')
            ->select('DATE(a.createdAt) as date, COUNT(a.id) as count')
            ->andWhere('a.createdAt >= :date')
            ->setParameter('date', $date)
            ->groupBy('date')
            ->orderBy('date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getTotalByAction(string $action): int
    {
        return $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.action = :action')
            ->setParameter('action', $action)
            ->getQuery()
            ->getSingleScalarResult();
    }
}