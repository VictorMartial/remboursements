<?php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\Refund;
use Doctrine\ORM\EntityManagerInterface;

class TicketManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RefundManager $refundManager // 👈 Maintenant ça fonctionne
    ) {
    }

    /**
     * Traiter un échec de paiement → AUTO-REMBOURSEMENT
     */
    public function traiterEchecPaiement(Ticket $ticket, string $motif): void
    {
        $ticket->setStatutPaiement('Échoué');
        $ticket->setMotifEchec($motif);
        $ticket->setDateEchec(new \DateTimeImmutable());
        $ticket->setStatut('Paiement échoué');
        $ticket->setARembourser(true);
        
        $this->entityManager->flush();

        // 🔥 AUTO-REMBOURSEMENT OBLIGATOIRE
        $this->creerRemboursementObligatoire($ticket);
    }

    /**
     * Créer un remboursement OBLIGATOIRE
     */
    private function creerRemboursementObligatoire(Ticket $ticket): Refund
    {
        // Utilisation du RefundManager
        $refund = $this->refundManager->creerRemboursement(
            $ticket,
            $ticket->getMontantCommande(),
            true // obligatoire
        );

        $ticket->setStatutRemboursement('En attente');
        $ticket->setDateRemboursement(new \DateTimeImmutable());

        $this->entityManager->flush();

        return $refund;
    }

    /**
     * Gérer les paiements multiples qui échouent
     */
    public function gererPaiementsMultiplesEchoues(Ticket $ticketParent): array
    {
        $ticketsEchoues = $this->entityManager
            ->getRepository(Ticket::class)
            ->findBy([
                'ticketParent' => $ticketParent,
                'statutPaiement' => 'Échoué'
            ]);

        $remboursements = [];
        foreach ($ticketsEchoues as $ticket) {
            if (!$ticket->isARembourser()) {
                $remboursements[] = $this->creerRemboursementObligatoire($ticket);
            }
        }

        return $remboursements;
    }

    /**
     * Vérifier et traiter les tickets à rembourser
     */
    public function verifierEtRembourserTickets(): array
    {
        $ticketsARembourser = $this->entityManager
            ->getRepository(Ticket::class)
            ->findBy([
                'aRembourser' => true,
                'statutRemboursement' => null
            ]);

        $resultats = [];
        foreach ($ticketsARembourser as $ticket) {
            $resultats[] = [
                'ticket' => $ticket,
                'remboursement' => $this->creerRemboursementObligatoire($ticket)
            ];
        }

        return $resultats;
    }

    /**
     * Créer un nouveau ticket (qui sera auto-remboursé en cas d'échec)
     */
    public function creerTicketAvecAutoRemboursement(Ticket $ticketOriginal): Ticket
    {
        $newTicket = new Ticket();
        $newTicket->setUser($ticketOriginal->getUser());
        $newTicket->setDateCommande($ticketOriginal->getDateCommande());
        $newTicket->setIdCommande($ticketOriginal->getIdCommande());
        $newTicket->setReferenceCommande($ticketOriginal->getReferenceCommande());
        $newTicket->setIdTransaction($ticketOriginal->getIdTransaction());
        $newTicket->setMethodePaiement($ticketOriginal->getMethodePaiement());
        $newTicket->setIdentifiantCompte($ticketOriginal->getIdentifiantCompte());
        $newTicket->setTypeTransaction($ticketOriginal->getTypeTransaction());
        $newTicket->setMontantCommande($ticketOriginal->getMontantCommande());
        $newTicket->setRib($ticketOriginal->getRib());
        $newTicket->setDescription($ticketOriginal->getDescription());
        $newTicket->setTicketParent($ticketOriginal);
        $newTicket->setNombreTentatives($ticketOriginal->getNombreTentatives() + 1);
        $newTicket->setStatut('Créée');
        $newTicket->setStatutPaiement('En attente');
        $newTicket->setARembourser(false);

        $ticketOriginal->setStatut('Refait');

        $this->entityManager->persist($newTicket);
        $this->entityManager->flush();

        return $newTicket;
    }

    /**
     * Traiter un ticket approuvé mais avec paiement en échec (cas du AEV non reçu)
     */
    public function traiterAevNonRecu(Ticket $ticket): void
    {
        if ($ticket->getStatut() === 'Approuvée' && $ticket->getStatutPaiement() === 'Échoué') {
            $this->traiterEchecPaiement(
                $ticket,
                'AEV non reçu - Remboursement automatique obligatoire'
            );
        }
    }
}