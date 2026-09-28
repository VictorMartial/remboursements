<?php

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    // =============================================
    //  MÉTHODES EXISTANTES
    // =============================================

    public function countByUserAndStatus(User $user, string $status): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.user = :user')
            ->andWhere('t.statut = :status')
            ->setParameter('user', $user)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getTotalAmountToRefund(): float
    {
        $total = 0.0;
        foreach ($this->createQueryBuilder('t')
            ->select('t.montantARembourser')
            ->where('t.aRembourser = :val')
            ->setParameter('val', true)
            ->getQuery()
            ->getScalarResult() as $row) {
            $total += (float) ($row['montantARembourser'] ?? 0);
        }
        return $total;
    }

    // =============================================
    //  MÉTHODES AVEC CONSTANTES
    // =============================================

    public function countTicketsToRefund(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.aRembourser = :val')
            ->setParameter('val', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPendingTickets(): int
    {
        return $this->count(['statut' => Ticket::STATUT_CREE]);
    }

    public function countTicketsToResend(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.statut = :statut')
            ->andWhere('t.typeRemboursement = :type')
            ->setParameter('statut', Ticket::STATUT_VALIDE)
            ->setParameter('type', Ticket::TYPE_AEV)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Statistiques mensuelles (6 derniers mois).
     * NB: on évite les fonctions DQL YEAR()/MONTH() car elles ne sont pas
     * enregistrées nativement dans Doctrine ORM (nécessite beberlei/doctrine-extensions),
     * ce qui provoquait une QueryException ("Unknown function 'YEAR'") -> HTTP 500.
     * On récupère donc les dates brutes et on groupe en PHP.
     */
    public function getMonthlyStats(int $months = 6): array
    {
        $since = new \DateTimeImmutable("-$months months");

        $rows = $this->createQueryBuilder('t')
            ->select('t.dateCommande')
            ->where('t.dateCommande >= :date')
            ->setParameter('date', $since)
            ->getQuery()
            ->getResult();

        // Initialiser tous les mois (y compris ceux à 0) dans le bon ordre
        $allMonths = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $label = (new \DateTime("-$i months"))->format('M');
            $allMonths[$label] = 0;
        }

        foreach ($rows as $row) {
            $date = $row['dateCommande'] ?? null;
            if ($date instanceof \DateTimeInterface) {
                $label = $date->format('M');
                if (array_key_exists($label, $allMonths)) {
                    $allMonths[$label]++;
                }
            }
        }

        return $allMonths;
    }

    /**
     * Statistiques complètes pour le tableau de bord (optionnel).
     */
    public function getDashboardStats(): array
    {
        $totalTickets = $this->count([]);
        $totalRemboursement = $this->getTotalAmountToRefund();
        $enAttenteRib = $this->count(['statut' => Ticket::STATUT_ATTENTE_RIB]);
        $crees = $this->count(['statut' => Ticket::STATUT_CREE]);
        $paiementMultiple = $this->count(['typeRemboursement' => Ticket::TYPE_MULTIPLE]);
        $aevNonRecu = $this->count(['typeRemboursement' => Ticket::TYPE_AEV]);

        return [
            'total_tickets'          => $totalTickets,
            'total_a_rembourser'     => $totalRemboursement,
            'en_attente_rib'         => $enAttenteRib,
            'crees'                  => $crees,
            'paiement_multiple'      => $paiementMultiple,
            'aev_non_recu'           => $aevNonRecu,
            'tickets_a_rembourser'   => $this->countTicketsToRefund(),
            'tickets_en_attente'     => $this->countPendingTickets(),
            'tickets_a_renvoyer'     => $this->countTicketsToResend(),
        ];
    }
}