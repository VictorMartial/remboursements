<?php

namespace App\Service;

use App\Entity\Refund;
use App\Entity\Ticket;
use Doctrine\ORM\EntityManagerInterface;

class RefundManager
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Créer un remboursement
     */
    public function creerRemboursement(Ticket $ticket, string $montant, bool $obligatoire = false): Refund
    {
        $refund = new Refund();
        $refund->setTicket($ticket);
        $refund->setMontantRembourse($montant);
        $refund->setStatut('En attente');
        $refund->setMethodePaiement($ticket->getMethodePaiement());
        $refund->setObligatoire($obligatoire);
        $refund->setDateCreation(new \DateTimeImmutable());

        $this->entityManager->persist($refund);
        $this->entityManager->flush();

        return $refund;
    }

    /**
     * Valider un remboursement
     */
    public function validerRemboursement(Refund $refund): void
    {
        $refund->setStatut('Réussi');
        $refund->setDateValidation(new \DateTimeImmutable());
        
        $ticket = $refund->getTicket();
        $ticket->setStatutRemboursement('Réussi');
        $ticket->setARembourser(false);
        $ticket->setStatut('Remboursée');
        
        $this->entityManager->flush();
    }

    /**
     * Échouer un remboursement
     */
    public function echouerRemboursement(Refund $refund, string $motif): void
    {
        $refund->setStatut('Échoué');
        $refund->setMotifEchec($motif);
        
        $ticket = $refund->getTicket();
        $ticket->setStatutRemboursement('Échoué');
        
        $this->entityManager->flush();
    }

    /**
     * Récupérer les remboursements en attente
     */
    public function getRemboursementsEnAttente(): array
    {
        return $this->entityManager
            ->getRepository(Refund::class)
            ->findBy(['statut' => 'En attente']);
    }

    /**
     * Récupérer les remboursements obligatoires
     */
    public function getRemboursementsObligatoires(): array
    {
        return $this->entityManager
            ->getRepository(Refund::class)
            ->findBy([
                'obligatoire' => true,
                'statut' => 'En attente'
            ]);
    }

    /**
     * Récupérer un remboursement par ID
     */
    public function getRemboursementById(int $id): ?Refund
    {
        return $this->entityManager
            ->getRepository(Refund::class)
            ->find($id);
    }

    /**
     * Récupérer les remboursements d'un ticket
     */
    public function getRemboursementsByTicket(Ticket $ticket): array
    {
        return $this->entityManager
            ->getRepository(Refund::class)
            ->findBy(['ticket' => $ticket]);
    }

    /**
     * Compter les remboursements en attente
     */
    public function countPendingRefunds(): int
    {
        return $this->entityManager
            ->getRepository(Refund::class)
            ->count(['statut' => 'En attente']);
    }
}