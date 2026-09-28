<?php

namespace App\Service;

use App\Entity\Ticket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class TicketValidationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private ParameterBagInterface $parameterBag,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * Valide le ticket suite à un "ok" de la banque (manuel ou détecté par IMAP)
     * et notifie l'utilisateur propriétaire du ticket.
     */
    public function validate(Ticket $ticket, string $source = 'manuel'): void
    {
        $ticket->setStatut(Ticket::STATUT_VALIDE);
        $ticket->setStatutRemboursement('Remboursé');
        $ticket->setDateRemboursement(new \DateTimeImmutable());
        $this->em->flush();

        $this->activityLogger->log(
            'VALIDATION',
            sprintf('Ticket #%d validé (source : %s)', $ticket->getId(), $source),
            'Ticket',
            $ticket->getId(),
            ['ancien_statut' => Ticket::STATUT_ATTENTE_REPONSE_BANQUE, 'nouveau_statut' => Ticket::STATUT_VALIDE, 'source' => $source]
        );

        $this->notifyUser(
            $ticket,
            'Votre demande de remboursement a été validée',
            sprintf(
                '<h2>Remboursement validé</h2>
                <p>Bonjour,</p>
                <p>Votre ticket #%d a été validé par la banque et le remboursement a été effectué.</p>
                <p>Cordialement,<br>L\'équipe Remboursement</p>',
                $ticket->getId()
            )
        );
    }

    /**
     * Alerte l'interlocuteur qu'une réponse banque est arrivée mais n'est pas
     * clairement positive : il doit vérifier manuellement et renvoyer si besoin.
     */
    public function alertInterlocuteurAmbiguousReply(Ticket $ticket, string $rawReplyExcerpt): void
    {
        $this->activityLogger->log(
            'REPONSE_BANQUE_AMBIGUE',
            sprintf('Réponse banque reçue pour le ticket #%d mais aucun "ok" détecté.', $ticket->getId()),
            'Ticket',
            $ticket->getId()
        );

        try {
            $email = (new Email())
                ->from($this->parameterBag->get('app_email_from'))
                ->to($this->parameterBag->get('interlocuteur_email'))
                ->subject(sprintf('Réponse banque à vérifier - Ticket #%d', $ticket->getId()))
                ->html(sprintf(
                    '<h2>Réponse banque à vérifier manuellement</h2>
                    <p>Une réponse est arrivée pour le ticket #%d mais elle ne contient pas de confirmation claire ("ok").</p>
                    <p><strong>Extrait :</strong><br>%s</p>
                    <p>Merci de vérifier et de renvoyer l\'email banque si nécessaire depuis le tableau de bord.</p>',
                    $ticket->getId(),
                    nl2br(htmlspecialchars(mb_substr($rawReplyExcerpt, 0, 500)))
                ));

            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->activityLogger->log(
                'EMAIL_ECHEC',
                sprintf('Échec de l\'alerte interlocuteur pour le ticket #%d : %s', $ticket->getId(), $e->getMessage()),
                'Ticket',
                $ticket->getId()
            );
        }
    }

    private function notifyUser(Ticket $ticket, string $subject, string $htmlBody): void
    {
        $ticketUser = $ticket->getUser();
        if (!$ticketUser || !$ticketUser->getEmail()) {
            return;
        }

        try {
            $email = (new Email())
                ->from($this->parameterBag->get('app_email_from'))
                ->to($ticketUser->getEmail())
                ->replyTo($this->parameterBag->get('app_email_reply_to'))
                ->subject($subject)
                ->html($htmlBody);

            $this->mailer->send($email);
        } catch (\Exception $e) {
            // Échec silencieux : le statut est déjà mis à jour.
        }
    }
}