<?php

namespace App\Controller\Api;

use App\Entity\Ticket;
use App\Entity\UsedOrderReference;
use App\Service\ActivityLogger;
use App\Service\RealtimeNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/ticket')]
class TicketApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SluggerInterface $slugger,
        private ActivityLogger $activityLogger,
        private ValidatorInterface $validator,
        private RealtimeNotifier $realtimeNotifier,
        private MailerInterface $mailer,
        private ParameterBagInterface $params,
        private LoggerInterface $logger
    ) {}

    #[Route('/create', name: 'api_ticket_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $type = $request->request->get('type_remboursement');
        if (!in_array($type, ['AEV_NON_RECU', 'PAIEMENT_MULTIPLE'], true)) {
            return $this->json(['error' => 'Type de remboursement invalide'], 400);
        }

        $devise = $request->request->get('devise');
        if (!in_array($devise, ['EUR', 'USD'], true)) {
            return $this->json(['error' => 'Devise invalide'], 400);
        }

        // ---- Unicité ID commande (contrôle robuste côté serveur) ----
        $idCommande = trim((string) $request->request->get('id_commande', ''));
        if ($idCommande === '' || mb_strlen($idCommande) < 4) {
            return $this->json([
                'error' => 'L\'ID commande est obligatoire et doit contenir au moins 4 caractères.',
                'field' => 'id_commande',
            ], 400);
        }

        $existing = $this->em->getRepository(Ticket::class)->findOneBy(['idCommande' => $idCommande]);
        if ($existing) {
            return $this->json([
                'error' => 'Cet ID commande existe déjà. Choisissez un autre ID commande.',
                'field' => 'id_commande',
                'code' => 'ID_COMMANDE_DUPLICATE',
            ], 409);
        }
        // ---- Fin unicité ID commande ----

        $ticket = new Ticket();
        $ticket->setUser($user);
        $ticket->setTypeRemboursement($type);
        $ticket->setDevise($devise);
        $ticket->setDateCommande(new \DateTimeImmutable($request->request->get('date_commande')));
        $ticket->setIdCommande($idCommande);

        // ---- Gestion des références + unicité PERMANENTE (même après suppression ticket) ----
        $usedRefs = [];

        $historyRows = $this->em->getRepository(UsedOrderReference::class)
            ->createQueryBuilder('u')
            ->select('u.referenceNormalized')
            ->getQuery()
            ->getArrayResult();
        foreach ($historyRows as $row) {
            $usedRefs[$row['referenceNormalized']] = true;
        }

        $allTickets = $this->em->getRepository(Ticket::class)->createQueryBuilder('t')
            ->select('t.referenceCommande', 't.references')
            ->getQuery()
            ->getArrayResult();
        foreach ($allTickets as $row) {
            foreach (['referenceCommande', 'references'] as $field) {
                if (empty($row[$field])) {
                    continue;
                }
                foreach (preg_split('/[\n,]+/', (string) $row[$field]) as $r) {
                    $r = trim($r);
                    if ($r !== '') {
                        $usedRefs[mb_strtolower($r)] = true;
                    }
                }
            }
        }

        $checkRefsUnique = function (array $refsToCheck) use ($usedRefs) {
            $duplicates = [];
            foreach ($refsToCheck as $r) {
                $key = mb_strtolower(trim($r));
                if ($key !== '' && isset($usedRefs[$key])) {
                    $duplicates[] = trim($r);
                }
            }
            return array_values(array_unique($duplicates));
        };

        /** @var string[] $refsForHistory */
        $refsForHistory = [];

        if ($type === 'PAIEMENT_MULTIPLE') {
            $referencesRaw = $request->request->get('references_commande');
            if ($referencesRaw) {
                $refs = explode("\n", $referencesRaw);
                $refs = array_map('trim', $refs);
                $refs = array_values(array_filter($refs, function ($v) { return $v !== ''; }));
                if (empty($refs)) {
                    return $this->json(['error' => 'Au moins une référence est requise pour un paiement multiple', 'field' => 'references_commande'], 400);
                }
                $dups = $checkRefsUnique($refs);
                if (!empty($dups)) {
                    return $this->json([
                        'error' => 'Référence(s) déjà utilisée(s) (y compris sur un ticket supprimé) : ' . implode(', ', $dups) . '. Une référence ne peut être utilisée qu\'une seule fois.',
                        'field' => 'references_commande',
                        'code' => 'REFERENCE_DUPLICATE',
                        'duplicates' => $dups,
                    ], 409);
                }
                $ticket->setReferences(implode("\n", $refs));
                $ticket->setReferenceCommande(implode(', ', $refs));
                $refsForHistory = $refs;
            } else {
                return $this->json(['error' => 'Le champ "references_commande" est requis pour un paiement multiple', 'field' => 'references_commande'], 400);
            }
        } else {
            $reference = trim((string) $request->request->get('reference_commande', ''));
            if ($reference === '') {
                return $this->json(['error' => 'La référence de commande est requise', 'field' => 'reference_commande'], 400);
            }
            $dups = $checkRefsUnique([$reference]);
            if (!empty($dups)) {
                return $this->json([
                    'error' => 'Cette référence est déjà utilisée (y compris sur un ticket supprimé). Choisissez une autre référence.',
                    'field' => 'reference_commande',
                    'code' => 'REFERENCE_DUPLICATE',
                    'duplicates' => $dups,
                ], 409);
            }
            $ticket->setReferenceCommande($reference);
            $ticket->setReferences(null);
            $refsForHistory = [$reference];
        }
        // ---- Fin gestion références ----

        $ticket->setMethodePaiement($request->request->get('methode_paiement'));
        $ticket->setIdentifiantCompte($request->request->get('identifiant_compte'));
        $ticket->setMontantCommande($request->request->get('montant_commande'));
        $ticket->setQuantite((int) $request->request->get('quantite', 1));
        $ticket->setMontantUnitaire($request->request->get('montant_unitaire'));
        $ticket->setMontantARembourser($request->request->get('montant_remboursement'));
        $referencePaiement = $request->request->get('reference_paiement');
        $ticket->setReferencePaiement(
        $referencePaiement !== null && $referencePaiement !== ''
        ? trim((string) $referencePaiement)
        : null
);
        $ticket->setDescription($request->request->get('description'));

        // Email du client (obligatoire) — demande de RIB envoyée immédiatement, sans attente de confirmation
        $clientEmail = trim((string) $request->request->get('client_email', ''));
        if ($clientEmail === '' || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->json([
                'error' => 'L\'email du client est obligatoire et doit être valide.',
                'field' => 'client_email',
            ], 400);
        }
        $ticket->setClientEmail($clientEmail);

        // Plus d'attente de confirmation : on passe directement en "En attente RIB"
        $ticket->setStatut('En attente RIB');
        $ticket->setARembourser(true);

        $errors = $this->validator->validate($ticket);
        if (count($errors) > 0) {
            return $this->json(['error' => (string) $errors], 400);
        }

        $this->em->persist($ticket);
        $this->em->flush();

        // Enregistrement PERMANENT des références
        $userId = method_exists($user, 'getId') ? $user->getId() : null;
        $seenNorm = [];
        foreach ($refsForHistory as $refVal) {
            $norm = mb_strtolower(trim($refVal));
            if ($norm === '' || isset($seenNorm[$norm])) {
                continue;
            }
            $seenNorm[$norm] = true;
            $already = $this->em->getRepository(UsedOrderReference::class)->findOneBy([
                'referenceNormalized' => $norm,
            ]);
            if (!$already) {
                $hist = new UsedOrderReference($refVal, $ticket->getId(), $userId);
                $this->em->persist($hist);
            }
        }
        $this->em->flush();

        // Envoi immédiat de l'email professionnel demandant le RIB au client
        $this->sendRibRequestEmail($ticket);

        $this->activityLogger->log(
            $type === 'AEV_NON_RECU' ? 'AEV NON RECU' : 'PAIEMENT MULTIPLE',
            sprintf('Ticket #%d créé via API — email RIB envoyé à %s', $ticket->getId(), $clientEmail),
            'Ticket',
            $ticket->getId()
        );

        // 🔔 Temps réel : l'interlocuteur voit le nouveau ticket apparaître
        $this->realtimeNotifier->ticketUpdated('ticket.created', $ticket);

        return $this->json([
            'success' => true,
            'id' => $ticket->getId(),
            'message' => 'Ticket créé avec succès. Email de demande de RIB envoyé au client.'
        ], 201);
    }

    /**
     * Envoie un email professionnel au client pour demander son RIB.
     * N'utilise plus le transport "client" : on utilise l'email professionnel (banque / interlocuteur).
     */
    private function sendRibRequestEmail(Ticket $ticket): void
    {
        $to = $ticket->getClientEmail();
        if (!$to) {
            return;
        }
        try {
            // Email professionnel (plus de transport "client")
            $from = $this->params->has('bank_email_from')
                ? $this->params->get('bank_email_from')
                : ($this->params->has('app_email_from') ? $this->params->get('app_email_from') : 'noreply@example.com');
            $reply = $this->params->has('bank_email_reply_to')
                ? $this->params->get('bank_email_reply_to')
                : $from;

            $email = (new Email())
                ->from($from)
                ->to($to)
                ->replyTo($reply)
                ->subject(sprintf('Demande de RIB pour votre remboursement #%d', $ticket->getId()))
                ->html(sprintf(
                    '<h2>Demande de RIB</h2>
                    <p>Bonjour,</p>
                    <p>Votre demande de remboursement (ticket #%d) a été enregistrée.</p>
                    <p>Merci de nous transmettre votre RIB afin que nous puissions procéder au remboursement.</p>
                    <p>Référence commande : %s</p>
                    <p>Montant : %s %s</p>
                    <p>Cordialement,<br>L\'équipe remboursements</p>',
                    $ticket->getId(),
                    $ticket->getReferenceCommande() ?? $ticket->getIdCommande() ?? 'N/A',
                    $ticket->getMontantARembourser() ?? '0',
                    $ticket->getDevise() ?: 'EUR'
                ));
            // Transport professionnel (banque) — plus de "client"
            $email->getHeaders()->addTextHeader('X-Transport', 'banque');
            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logger->error('Erreur envoi email demande RIB au client : ' . $e->getMessage());
        }
    }

    /**
     * Liste des références déjà utilisées (y compris tickets supprimés).
     */
    #[Route('/used-references', name: 'api_ticket_used_references', methods: ['GET'])]
    public function usedReferences(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $rows = $this->em->getRepository(UsedOrderReference::class)
            ->createQueryBuilder('u')
            ->select('u.reference', 'u.referenceNormalized', 'u.ticketId', 'u.createdAt')
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $list = array_map(static function (array $r) {
            return [
                'reference' => $r['reference'],
                'normalized' => $r['referenceNormalized'],
                'ticket_id' => $r['ticketId'],
                'created_at' => $r['createdAt'] instanceof \DateTimeInterface
                    ? $r['createdAt']->format('c')
                    : (string) $r['createdAt'],
            ];
        }, $rows);

        return $this->json(['references' => $list]);
    }

    #[Route('/{id}/rib', name: 'api_ticket_rib', methods: ['POST'])]
    public function uploadRib(Ticket $ticket, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $isOwner = $ticket->getUser() === $user;
        $isInterlocuteur = $user && method_exists($user, 'getRoles')
            && (in_array('ROLE_INTERLOCUTEUR', $user->getRoles(), true)
                || in_array('ROLE_ADMIN', $user->getRoles(), true));

        // Propriétaire du ticket OU interlocuteur/admin peuvent déposer le RIB
        if (!$isOwner && !$isInterlocuteur) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        // Statut cohérent avec Ticket::STATUT_ATTENTE_RIB / STATUT_RIB
        if ($ticket->getStatut() !== Ticket::STATUT_ATTENTE_RIB
            && $ticket->getStatut() !== 'En attente RIB') {
            return $this->json(['error' => 'Le ticket n\'est pas en attente de RIB'], 400);
        }

        /** @var UploadedFile|null $ribFile */
        $ribFile = $request->files->get('rib_file');
        if (!$ribFile) {
            return $this->json(['error' => 'Fichier RIB manquant'], 400);
        }

        $newFilename = $this->slugger->slug(pathinfo($ribFile->getClientOriginalName(), PATHINFO_FILENAME))
            . '-' . uniqid() . '.' . $ribFile->guessExtension();

        $ribFile->move($this->getParameter('uploads_directory'), $newFilename);

        $ticket->setRib($newFilename);
        // → statut "RIB reçu" → apparaît dans le tableau "RIB reçus — à envoyer à la banque"
        $ticket->setStatut(Ticket::STATUT_RIB_RECU);
        $this->em->flush();

        $this->activityLogger->log(
            'RIB UPLOAD',
            sprintf('RIB ajouté au ticket #%d (statut: %s)', $ticket->getId(), Ticket::STATUT_RIB_RECU),
            'Ticket',
            $ticket->getId()
        );

        // 🔔 Temps réel : l'interlocuteur voit le RIB arriver dans le tableau
        $this->realtimeNotifier->ticketUpdated('ticket.rib_uploaded', $ticket);

        return $this->json([
            'success' => true,
            'message' => 'RIB envoyé avec succès',
            'statut' => Ticket::STATUT_RIB_RECU,
        ]);
    }

    #[Route('/{id}/delete', name: 'api_ticket_delete', methods: ['POST', 'DELETE'])]
    public function delete(Ticket $ticket): JsonResponse
    {
        if ($ticket->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        // 🔔 Temps réel AVANT suppression (on a encore besoin de l'entité)
        $this->realtimeNotifier->ticketUpdated('ticket.deleted', $ticket);

        // IMPORTANT : on ne supprime PAS les entrées UsedOrderReference.
        $this->em->remove($ticket);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Ticket supprimé. Les références de commande restent réservées et ne pourront pas être réutilisées.',
        ]);
    }

    #[Route('/{id}', name: 'api_ticket_show', methods: ['GET'])]
    public function show(Ticket $ticket): JsonResponse
    {
        if ($ticket->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        return $this->json([
            'id' => $ticket->getId(),
            'reference' => $ticket->getReferenceCommande(),
            'montant' => $ticket->getMontantARembourser(),
            'statut' => $ticket->getStatut(),
            'date' => $ticket->getDateCommande()?->format('d/m/Y'),
            'description' => $ticket->getDescription(),
            'type' => $ticket->getTypeRemboursement(),
            'rib' => $ticket->getRib(),
            'devise' => $ticket->getDevise(),
            'suivi_banque' => method_exists($ticket, 'getSuiviBanque') ? $ticket->getSuiviBanque() : null,
            'suivi_client' => method_exists($ticket, 'getSuiviClient') ? $ticket->getSuiviClient() : null,
            'suivi_madaozi' => method_exists($ticket, 'getSuiviMadaozi') ? $ticket->getSuiviMadaozi() : null,
        ]);
    }

    #[Route('/list', name: 'api_ticket_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        $tickets = $this->em->getRepository(Ticket::class)->findBy(['user' => $user], ['id' => 'DESC']);

        $data = array_map(function (Ticket $t) {
            return [
                'id' => $t->getId(),
                'reference' => $t->getReferenceCommande(),
                'montant' => $t->getMontantARembourser(),
                'statut' => $t->getStatut(),
                'date' => $t->getDateCommande()?->format('d/m/Y'),
                'devise' => $t->getDevise(),
                'suivi_banque' => method_exists($t, 'getSuiviBanque') ? $t->getSuiviBanque() : null,
                'suivi_client' => method_exists($t, 'getSuiviClient') ? $t->getSuiviClient() : null,
                'suivi_madaozi' => method_exists($t, 'getSuiviMadaozi') ? $t->getSuiviMadaozi() : null,
            ];
        }, $tickets);

        return $this->json($data);
    }
}