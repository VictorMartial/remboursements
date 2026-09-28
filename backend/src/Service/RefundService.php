<?php

namespace App\Service;

use App\Entity\Ticket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class RefundService
{
    private string $bankEmail;
    private string $appEmailFrom;
    private string $appEmailReplyTo;
    private string $uploadsDirectory;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private ParameterBagInterface $parameterBag,
        private RefundManager $refundManager
    ) {
        $this->bankEmail = $this->parameterBag->get('bank_email');
        $this->appEmailFrom = $this->parameterBag->get('app_email_from');
        $this->appEmailReplyTo = $this->parameterBag->get('app_email_reply_to');
        $this->uploadsDirectory = $this->parameterBag->get('uploads_directory');
    }

    /**
     * Calculer le montant à rembourser pour paiement multiple
     */
    public function calculerMontantRemboursementMultiple(Ticket $ticket): string
    {
        $montantTotal = floatval($ticket->getMontantCommande());
        $quantite = $ticket->getQuantite() ?? 1;
        
        // Si pas de quantité ou quantité = 1, on rembourse le total
        if ($quantite <= 1) {
            return number_format($montantTotal, 2, '.', '');
        }
        
        $montantUnitaire = floatval($ticket->getMontantUnitaire()) ?? ($montantTotal / $quantite);
        $quantiteARembourser = $quantite - 1;
        $montantARembourser = $montantUnitaire * $quantiteARembourser;
        
        return number_format($montantARembourser, 2, '.', '');
    }

    /**
     * Traiter un remboursement AEV non reçu
     */
    public function traiterAevNonRecu(Ticket $ticket, UploadedFile $ribFile): array
    {
        $ticket->setTypeRemboursement('AEV_NON_RECU');
        $ticket->setMontantARembourser($ticket->getMontantCommande());
        $ticket->setARembourser(true);
        $ticket->setStatutRemboursement('En attente');
        
        // Sauvegarder le RIB
        $ribPath = $this->saveRibFile($ribFile, $ticket);
        $ticket->setRib($ribPath);
        
        // Créer le remboursement
        $refund = $this->refundManager->creerRemboursement(
            $ticket,
            $ticket->getMontantCommande(),
            true
        );
        
        $this->entityManager->flush();
        $this->sendBankEmail($ticket);
        
        return [
            'success' => true,
            'message' => 'AEV non reçu - Remboursement automatique enclenché',
            'montant' => $ticket->getMontantARembourser(),
            'refund_id' => $refund->getId()
        ];
    }

    /**
     * Traiter un remboursement pour paiement multiple
     */
    public function traiterPaiementMultiple(Ticket $ticket, UploadedFile $ribFile): array
    {
        $montantARembourser = $this->calculerMontantRemboursementMultiple($ticket);
        
        $ticket->setTypeRemboursement('PAIEMENT_MULTIPLE');
        $ticket->setMontantARembourser($montantARembourser);
        $ticket->setARembourser(true);
        $ticket->setStatutRemboursement('En attente');
        
        // Sauvegarder le RIB
        $ribPath = $this->saveRibFile($ribFile, $ticket);
        $ticket->setRib($ribPath);
        
        // Créer le remboursement
        $refund = $this->refundManager->creerRemboursement(
            $ticket,
            $montantARembourser,
            true
        );
        
        $this->entityManager->flush();
        $this->sendBankEmail($ticket);
        
        return [
            'success' => true,
            'message' => 'Paiement multiple - Remboursement du surplus enclenché',
            'montant' => $montantARembourser,
            'quantite' => $ticket->getQuantite(),
            'montant_unitaire' => $ticket->getMontantUnitaire(),
            'refund_id' => $refund->getId()
        ];
    }

    /**
     * Sauvegarder le fichier RIB
     */
    private function saveRibFile(UploadedFile $file, Ticket $ticket): string
    {
        if (!is_dir($this->uploadsDirectory)) {
            mkdir($this->uploadsDirectory, 0777, true);
        }
        
        $filename = sprintf(
            'rib_%s_%s.%s',
            $ticket->getId() ?? time(),
            uniqid(),
            $file->guessExtension()
        );
        
        $file->move($this->uploadsDirectory, $filename);
        return $this->uploadsDirectory . '/' . $filename;
    }

    /**
     * Envoyer email à la banque
     */
    private function sendBankEmail(Ticket $ticket): void
    {
        try {
            $email = (new Email())
                ->from($this->appEmailFrom)
                ->to($this->bankEmail)
                ->replyTo($this->appEmailReplyTo)
                ->subject('Demande de remboursement - Ticket #' . $ticket->getId())
                ->html($this->generateBankEmailContent($ticket));

            if ($ticket->getRib() && file_exists($ticket->getRib())) {
                $email->attachFromPath($ticket->getRib());
            }

            $this->mailer->send($email);
            
            $this->logger->info('Email envoyé à la banque', [
                'ticket_id' => $ticket->getId(),
                'bank_email' => $this->bankEmail,
                'montant' => $ticket->getMontantARembourser()
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Erreur envoi email banque', [
                'ticket_id' => $ticket->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Générer le contenu de l'email pour la banque
     */
    private function generateBankEmailContent(Ticket $ticket): string
    {
        $devise = $ticket->getDevise() ?: 'EUR';

        return sprintf(
            '
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .header { background: #1e72e7; color: white; padding: 20px; }
                    .content { padding: 20px; }
                    .info { background: #f5f5f5; padding: 15px; margin: 10px 0; }
                    .highlight { color: #1e72e7; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h2>🏦 Demande de Remboursement</h2>
                    <p>Ticket #%d</p>
                </div>
                <div class="content">
                    <h3>Détails du remboursement</h3>
                    <div class="info">
                        <p><strong>Référence Paiement :</strong> %s</p>
                        <p><strong>Type :</strong> %s</p>
                        <p><strong>Montant à rembourser :</strong> <span class="highlight">%s %s</span></p>
                        <p><strong>Motif :</strong> %s</p>
                    </div>
                    <h3>Informations client</h3>
                    <div class="info">
                        <p><strong>Email :</strong> %s</p>
                        <p><strong>Identifiant :</strong> %s</p>
                        <p><strong>Méthode de paiement :</strong> %s</p>
                    </div>
                    <h3>Détails de la commande</h3>
                    <div class="info">
                        <p><strong>ID Commande :</strong> %s</p>
                        <p><strong>Référence :</strong> %s</p>
                        <p><strong>Date :</strong> %s</p>
                        <p><strong>Montant total :</strong> %s %s</p>
                        %s
                    </div>
                    <p><strong>RIB joint en pièce jointe</strong></p>
                    <hr>
                    <p><small>Merci de procéder au remboursement dans les plus brefs délais.</small></p>
                </div>
            </body>
            </html>
            ',
            $ticket->getId(),
            $ticket->getReferencePaiement() ?? $ticket->getReferenceCommande(),
            $ticket->getTypeRemboursement(),
            $ticket->getMontantARembourser(),
            $devise,
            $ticket->getDescription() ?? 'Remboursement automatique',
            $ticket->getUser()->getEmail(),
            $ticket->getUser()->getUserIdentifier(),
            $ticket->getMethodePaiement(),
            $ticket->getIdCommande(),
            $ticket->getReferenceCommande(),
            $ticket->getDateCommande()?->format('d/m/Y H:i') ?? 'Non spécifiée',
            $ticket->getMontantCommande(),
            $devise,
            $this->getPaiementMultipleDetails($ticket)
        );
    }

    /**
     * Ajouter les détails du paiement multiple si applicable
     */
    private function getPaiementMultipleDetails(Ticket $ticket): string
    {
        if ($ticket->getTypeRemboursement() === 'PAIEMENT_MULTIPLE' && $ticket->getQuantite() > 1) {
            return sprintf(
                '
                <p><strong>Quantité :</strong> %d</p>
                <p><strong>Montant unitaire :</strong> %s €</p>
                <p><strong>Montant remboursé :</strong> <span class="highlight">%s €</span></p>
                ',
                $ticket->getQuantite(),
                $ticket->getMontantUnitaire(),
                $ticket->getMontantARembourser()
            );
        }
        return '';
    }

    /**
     * Confirmer le remboursement
     */
    public function confirmerRemboursement(Ticket $ticket, string $reference): void
    {
        $refunds = $this->refundManager->getRemboursementsByTicket($ticket);
        $refund = $refunds[0] ?? null;
        
        if ($refund) {
            $this->refundManager->validerRemboursement($refund);
        }
        
        $this->sendUserConfirmation($ticket, $reference);
    }

    /**
     * Envoyer email de confirmation à l'utilisateur
     */
    private function sendUserConfirmation(Ticket $ticket, string $reference): void
    {
        try {
            $email = (new Email())
                ->from($this->appEmailFrom)
                ->to($ticket->getUser()->getEmail())
                ->replyTo($this->appEmailReplyTo)
                ->subject('✅ Confirmation de remboursement - Ticket #' . $ticket->getId())
                ->html(sprintf(
                    '
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; }
                            .header { background: #28a745; color: white; padding: 20px; }
                            .content { padding: 20px; }
                            .info { background: #f5f5f5; padding: 15px; margin: 10px 0; }
                        </style>
                    </head>
                    <body>
                        <div class="header">
                            <h2>✅ Votre remboursement a été effectué</h2>
                        </div>
                        <div class="content">
                            <p>Bonjour %s,</p>
                            <p>Nous vous confirmons que votre remboursement a été traité avec succès.</p>
                            <div class="info">
                                <p><strong>Ticket :</strong> #%d</p>
                                <p><strong>Référence :</strong> %s</p>
                                <p><strong>Montant remboursé :</strong> %s €</p>
                                <p><strong>Date :</strong> %s</p>
                            </div>
                            <p>Le montant sera crédité sur votre compte sous 2 à 5 jours ouvrés.</p>
                            <hr>
                            <p><small>Pour toute question, contactez-nous à %s</small></p>
                            <p>Cordialement,<br>L\'équipe de remboursement</p>
                        </div>
                    </body>
                    </html>
                    ',
                    $ticket->getUser()->getUserIdentifier(),
                    $ticket->getId(),
                    $reference,
                    $ticket->getMontantARembourser(),
                    (new \DateTime())->format('d/m/Y H:i'),
                    $this->appEmailReplyTo
                ));

            $this->mailer->send($email);
            
            $this->logger->info('Email de confirmation envoyé à l\'utilisateur', [
                'ticket_id' => $ticket->getId(),
                'user_email' => $ticket->getUser()->getEmail()
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Erreur envoi email confirmation', [
                'ticket_id' => $ticket->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }
}