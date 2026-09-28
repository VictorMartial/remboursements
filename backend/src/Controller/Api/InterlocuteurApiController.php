<?php

namespace App\Controller\Api;

use App\Entity\Ticket;
use App\Repository\TicketRepository;
use App\Service\ActivityLogger;
use App\Service\RealtimeNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[Route('/api/interlocuteur')]
#[IsGranted('ROLE_INTERLOCUTEUR')]
class InterlocuteurApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TicketRepository $ticketRepo,
        private ActivityLogger $activityLogger,
        private LoggerInterface $logger,
        private MailerInterface $mailer,
        private RealtimeNotifier $realtimeNotifier,
        private ParameterBagInterface $params
    ) {}

    // ==== 1. Tickets en attente de confirmation ====
    #[Route('/tickets/en-attente', name: 'api_interlocuteur_tickets_attente', methods: ['GET'])]
    public function ticketsEnAttente(): JsonResponse
    {
        try {
            $tickets = $this->ticketRepo->findBy(['statut' => 'En attente confirmation']);
            $data = array_map(fn(Ticket $t) => [
                'id' => $t->getId(),
                'reference' => $t->getReferenceCommande(),
                'montant' => $t->getMontantARembourser(),
                'devise' => $t->getDevise() ?: 'EUR',
                'type' => $t->getTypeRemboursement(),
                'date' => $t->getDateCommande()?->format('d/m/Y'),
                'user' => $t->getUser()?->getEmail(),
                'description' => $t->getDescription(),
            ], $tickets);
            return $this->json($data);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    // ==== 2. Confirmer un ticket ====
    #[Route('/ticket/{id}/confirmer', name: 'api_interlocuteur_ticket_confirmer', methods: ['POST'])]
    public function confirmerTicket(Ticket $ticket): JsonResponse
    {
        try {
            if ($ticket->getStatut() !== 'En attente confirmation') {
                return $this->json(['success' => false, 'message' => 'Statut invalide.'], 400);
            }
            $ticket->setStatut('En attente RIB');
            $this->em->flush();

            $this->notifyUserRib($ticket);

            // 🔔 Temps réel : l'utilisateur voit son ticket passer en "En attente RIB"
            $this->realtimeNotifier->ticketUpdated('ticket.confirmed', $ticket);

            return $this->json(['success' => true, 'message' => 'Ticket confirmé.']);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    // ==== 3. RIB reçus ====
    #[Route('/tickets/a-envoyer-banque', name: 'api_interlocuteur_tickets_a_envoyer', methods: ['GET'])]
    public function ticketsAEnvoyerBanque(): JsonResponse
    {
        try {
            $tickets = $this->ticketRepo->findBy(['statut' => 'RIB reçu']);
            $data = array_map(fn(Ticket $t) => [
                'id' => $t->getId(),
                // Référence : on affiche reference_paiement si présent, sinon reference_commande
                'reference' => $t->getReferencePaiement() ?? $t->getReferenceCommande(),
                'montant' => $t->getMontantARembourser(),
                'devise' => $t->getDevise() ?: 'EUR',
                'type' => $t->getTypeRemboursement(),
                'rib' => $t->getRib(),
                'user' => $t->getUser()?->getEmail(),
            ], $tickets);
            return $this->json($data);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    // ==== 4. Envoyer à la banque (avec email) ====
    #[Route('/ticket/{id}/envoyer-banque', name: 'api_interlocuteur_ticket_envoyer_banque', methods: ['POST'])]
    public function envoyerBanque(Ticket $ticket): JsonResponse
    {
        try {
            if ($ticket->getStatut() !== 'RIB reçu') {
                return $this->json(['success' => false, 'message' => 'Statut invalide.'], 400);
            }
            if (!$ticket->getRib()) {
                return $this->json(['success' => false, 'message' => 'RIB manquant.'], 400);
            }
            $sent = $this->sendBankEmail($ticket);
            if ($sent) {
                $ticket->setStatut('En attente réponse banque');
                $ticket->setEmailBanqueEnvoyeAt(new \DateTimeImmutable());
                $this->em->flush();

                // 🔔 Temps réel
                $this->realtimeNotifier->ticketUpdated('ticket.sent_to_bank', $ticket);

                return $this->json(['success' => true, 'message' => 'Envoyé à la banque.']);
            }
            return $this->json(['success' => false, 'message' => 'Échec de l\'envoi de l\'email.'], 500);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    // ==== 5. Tickets en attente de réponse banque ====
    #[Route('/tickets/en-attente-banque', name: 'api_interlocuteur_tickets_attente_banque', methods: ['GET'])]
    public function ticketsEnAttenteBanque(): JsonResponse
    {
        try {
            $tickets = $this->ticketRepo->findBy(
                ['statut' => 'En attente réponse banque'],
                ['emailBanqueEnvoyeAt' => 'DESC']
            );
            $data = array_map(fn(Ticket $t) => [
                'id' => $t->getId(),
                'type' => $t->getTypeRemboursement(),
                'user' => $t->getUser()?->getEmail(),
                // Référence : fallback sur reference_commande si reference_paiement est null
                'reference' => $t->getReferencePaiement() ?? $t->getReferenceCommande(),
                'montant' => $t->getMontantARembourser(),
                'devise' => $t->getDevise() ?: 'EUR',
                'emailEnvoyeAt' => $t->getEmailBanqueEnvoyeAt()?->format('d/m/Y H:i'),
            ], $tickets);
            return $this->json($data);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    // ==== 6. Valider un ticket (avec modale) ====
    #[Route('/ticket/{id}/valider', name: 'api_interlocuteur_ticket_valider', methods: ['POST'])]
    public function validerTicket(Ticket $ticket, Request $request): JsonResponse
    {
        try {
            if ($ticket->getStatut() !== 'En attente réponse banque') {
                return $this->json(['success' => false, 'message' => 'Statut invalide.'], 400);
            }

            $data = json_decode($request->getContent(), true);
            $devise = $data['devise'] ?? 'EUR';
            $idTransaction = $data['idTransaction'] ?? $ticket->getIdTransaction();
            $montant = $data['montant'] ?? $ticket->getMontantARembourser();
            $dateRemboursement = $data['dateRemboursement'] ?? (new \DateTimeImmutable())->format('Y-m-d');

            // ==== Vérification : les infos saisies doivent être identiques à la demande initiale du client ====
            $deviseAttendue = $ticket->getDevise() ?: 'EUR';
            $montantAttendu = (float) $ticket->getMontantARembourser();
            $referenceAttendue = $ticket->getReferenceCommande();

            $ecarts = [];
            if (strtoupper((string) $devise) !== strtoupper((string) $deviseAttendue)) {
                $ecarts[] = 'devise';
            }
            if (round((float) $montant, 2) !== round($montantAttendu, 2)) {
                $ecarts[] = 'montant';
            }
            if ($referenceAttendue !== null && trim((string) $idTransaction) !== trim((string) $referenceAttendue)) {
                $ecarts[] = 'ID transaction';
            }

            if (!empty($ecarts)) {
                // Ça ne correspond pas à la demande du client : on ne valide pas, on renvoie la demande à la banque
                $this->sendBankEmail($ticket);
                $ticket->setEmailBanqueEnvoyeAt(new \DateTimeImmutable());
                $this->em->flush();

                // Notifier l'utilisateur que la validation ne correspond pas et que la banque a été recontactée
                try {
                    $this->notifyUserMismatch($ticket, $ecarts);
                } catch (\Exception $e) {
                    $this->logger->error('Erreur notification mismatch : ' . $e->getMessage());
                }

                // 🔔 Temps réel : mise à jour visible par les deux pages
                $this->realtimeNotifier->ticketUpdated('ticket.updated', $ticket, [
                    'mismatch' => true,
                    'ecarts' => $ecarts,
                ]);

                // Log d'activité
                $this->activityLogger->log(
                    'VALIDATION_MISMATCH',
                    sprintf('Validation banque différente pour le ticket #%d : %s', $ticket->getId(), implode(', ', $ecarts)),
                    'Ticket',
                    $ticket->getId()
                );

                return $this->json([
                    'success' => false,
                    'mismatch' => true,
                    'message' => sprintf(
                        "Les informations saisies (%s) ne correspondent pas à la demande initiale du client. La demande a été renvoyée à la banque.",
                        implode(', ', $ecarts)
                    ),
                ]);
            }

            $ticket->setDevise($devise);
            $ticket->setIdTransaction($idTransaction);
            $ticket->setMontantARembourser((string) $montant);
            $ticket->setDateRemboursement(new \DateTimeImmutable($dateRemboursement));
            $ticket->setStatut('Validé');
            $ticket->setStatutRemboursement('Remboursé');
            $this->em->flush();

            // Emails
            $this->notifyUserValidation($ticket, $devise, $idTransaction, $montant, $dateRemboursement);
            $this->notifyBankValidation($ticket, $devise, $idTransaction, $montant, $dateRemboursement);

            // 🔔 Temps réel : l'utilisateur voit son ticket passer en "Validé"
            $this->realtimeNotifier->ticketUpdated('ticket.validated', $ticket);

            return $this->json(['success' => true, 'message' => 'Ticket validé.']);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    // ==== 7. Renvoyer l'email à la banque ====
    #[Route('/ticket/{id}/resend-banque', name: 'api_interlocuteur_ticket_resend_banque', methods: ['POST'])]
    public function resendBanque(Ticket $ticket): JsonResponse
    {
        try {
            if ($ticket->getStatut() !== 'En attente réponse banque') {
                return $this->json(['success' => false, 'message' => 'Statut invalide.'], 400);
            }
            $sent = $this->sendBankEmail($ticket);
            if ($sent) {
                $ticket->setEmailBanqueEnvoyeAt(new \DateTimeImmutable());
                $this->em->flush();

                // 🔔 Temps réel
                $this->realtimeNotifier->ticketUpdated('ticket.updated', $ticket);

                return $this->json(['success' => true, 'message' => 'Email renvoyé.']);
            }
            return $this->json(['success' => false, 'message' => 'Échec du renvoi.'], 500);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    // ==== 8. Détail d'un ticket ====
    #[Route('/ticket/{id}', name: 'api_interlocuteur_ticket_show', methods: ['GET'])]
    public function showTicket(Ticket $ticket): JsonResponse
    {
        try {
            return $this->json([
                'id' => $ticket->getId(),
                'reference' => $ticket->getReferenceCommande(),
                'references' => $ticket->getReferences()
                    ? array_values(array_filter(explode("\n", $ticket->getReferences())))
                    : [],
                'montant' => $ticket->getMontantARembourser(),
                'devise' => $ticket->getDevise() ?: 'EUR',
                'statut' => $ticket->getStatut(),
                'type' => $ticket->getTypeRemboursement(),
                'rib' => $ticket->getRib(),
                'user' => $ticket->getUser()?->getEmail(),
                'dateCommande' => $ticket->getDateCommande()?->format('d/m/Y'),
                'description' => $ticket->getDescription(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    // ==================== MÉTHODES PRIVÉES ====================

    private function notifyUserRib(Ticket $ticket): void
    {
        $user = $ticket->getUser();
        if (!$user || !$user->getEmail()) return;
        try {
            // CLIENT : refund@evisamada-mg.com (transport "client")
            $from = $this->params->has('client_email_from')
                ? $this->params->get('client_email_from')
                : $this->params->get('app_email_from');
            $reply = $this->params->has('client_email_reply_to')
                ? $this->params->get('client_email_reply_to')
                : $this->params->get('app_email_reply_to');

            $email = (new Email())
                ->from($from)
                ->to($user->getEmail())
                ->replyTo($reply)
                ->subject('Votre demande de remboursement est confirmée')
                ->html(sprintf(
                    '<h2>Ticket confirmé</h2><p>Votre ticket #%d a été confirmé. Vous pouvez maintenant envoyer votre RIB.</p>',
                    $ticket->getId()
                ));
            $email->getHeaders()->addTextHeader('X-Transport', 'client');
            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logger->error('Erreur email confirmation RIB : ' . $e->getMessage());
        }
    }

    private function sendBankEmail(Ticket $ticket): bool
    {
        if (!$ticket->getRib()) return false;
        $ribPath = rtrim($this->getParameter('uploads_directory'), '/') . '/' . $ticket->getRib();
        $destinataire = $this->params->get('bank_email');
        try {
            $devise = $ticket->getDevise() ?: 'EUR';
            // BANQUE : remboursement@madaozi.mg (transport "banque")
            $from = $this->params->has('bank_email_from')
                ? $this->params->get('bank_email_from')
                : 'remboursement@madaozi.mg';
            $reply = $this->params->has('bank_email_reply_to')
                ? $this->params->get('bank_email_reply_to')
                : $from;

            // Référence affichée dans l'email : fallback si reference_paiement est null
            $reference = $ticket->getReferencePaiement()
                ?? $ticket->getReferenceCommande()
                ?? 'N/A';

            $email = (new Email())
                ->from($from)
                ->to($destinataire)
                ->replyTo($reply)
                ->subject(sprintf('Demande de remboursement #%d - RIB joint', $ticket->getId()))
                ->html(sprintf(
                    '<p>Ticket #%d<br>Référence : %s<br>Montant : %s %s</p>',
                    $ticket->getId(),
                    $reference,
                    $ticket->getMontantARembourser() ?? '0',
                    $devise
                ))
                ->attachFromPath($ribPath, $ticket->getRib());
            $email->getHeaders()->addTextHeader('X-Transport', 'banque');
            $this->mailer->send($email);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Erreur envoi email banque : ' . $e->getMessage());
            return false;
        }
    }

    private function notifyUserValidation(Ticket $ticket, string $devise, string $idTransaction, float $montant, string $date): void
    {
        $user = $ticket->getUser();
        if (!$user || !$user->getEmail()) return;
        try {
            // CLIENT : refund@evisamada-mg.com (transport "client") — Remboursement validé
            $from = $this->params->has('client_email_from')
                ? $this->params->get('client_email_from')
                : $this->params->get('app_email_from');
            $reply = $this->params->has('client_email_reply_to')
                ? $this->params->get('client_email_reply_to')
                : $this->params->get('app_email_reply_to');

            $email = (new Email())
                ->from($from)
                ->to($user->getEmail())
                ->replyTo($reply)
                ->subject('✅ Votre remboursement a été validé')
                ->html(sprintf(
                    '<h2>Remboursement validé</h2>
                    <p>Ticket #%d</p>
                    <ul>
                        <li>ID Transaction : %s</li>
                        <li>Montant : %s %s</li>
                        <li>Date : %s</li>
                    </ul>',
                    $ticket->getId(),
                    $idTransaction,
                    number_format($montant, 2),
                    $devise,
                    (new \DateTimeImmutable($date))->format('d/m/Y')
                ));
            $email->getHeaders()->addTextHeader('X-Transport', 'client');
            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logger->error('Erreur email validation : ' . $e->getMessage());
        }
    }

    private function notifyBankValidation(Ticket $ticket, string $devise, string $idTransaction, float $montant, string $date): void
    {
        $destinataire = $this->params->get('bank_email');
        if (!$destinataire) return;
        try {
            // BANQUE : remboursement@madaozi.mg (transport "banque")
            $from = $this->params->has('bank_email_from')
                ? $this->params->get('bank_email_from')
                : 'remboursement@madaozi.mg';
            $reply = $this->params->has('bank_email_reply_to')
                ? $this->params->get('bank_email_reply_to')
                : $from;

            $email = (new Email())
                ->from($from)
                ->to($destinataire)
                ->replyTo($reply)
                ->subject(sprintf('✅ Remboursement validé - Ticket #%d', $ticket->getId()))
                ->html(sprintf(
                    '<p>Remboursement finalisé pour le ticket #%d</p>
                    <ul>
                        <li>ID Transaction : %s</li>
                        <li>Montant : %s %s</li>
                        <li>Date : %s</li>
                        <li>Utilisateur : %s</li>
                    </ul>',
                    $ticket->getId(),
                    $idTransaction,
                    number_format($montant, 2),
                    $devise,
                    (new \DateTimeImmutable($date))->format('d/m/Y'),
                    $ticket->getUser()?->getEmail() ?? 'N/A'
                ));
            $email->getHeaders()->addTextHeader('X-Transport', 'banque');
            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logger->error('Erreur email banque validation : ' . $e->getMessage());
        }
    }

    private function notifyUserMismatch(Ticket $ticket, array $ecarts): void
    {
        $user = $ticket->getUser();
        if (!$user || !$user->getEmail()) return;
        try {
            // CLIENT : refund@evisamada-mg.com (transport "client")
            $from = $this->params->has('client_email_from')
                ? $this->params->get('client_email_from')
                : $this->params->get('app_email_from');
            $reply = $this->params->has('client_email_reply_to')
                ? $this->params->get('client_email_reply_to')
                : $this->params->get('app_email_reply_to');

            $email = (new Email())
                ->from($from)
                ->to($user->getEmail())
                ->replyTo($reply)
                ->subject('⚠️ Validation banque non conforme à votre demande')
                ->html(sprintf(
                    '<h2>Différence détectée lors de la validation</h2><p>Ticket #%d</p><p>Les informations envoyées par la banque ne correspondent pas à votre demande initiale (%s). Nous avons renvoyé la demande à la banque pour correction.</p>',
                    $ticket->getId(),
                    implode(', ', $ecarts)
                ));
            $email->getHeaders()->addTextHeader('X-Transport', 'client');
            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logger->error('Erreur email mismatch utilisateur : ' . $e->getMessage());
        }
    }
}