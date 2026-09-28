<?php

namespace App\Controller\Api;

use App\Entity\Ticket;
use App\Entity\UsedOrderReference;
use App\Service\ActivityLogger;
use App\Service\RealtimeNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
        private RealtimeNotifier $realtimeNotifier
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
        $ticket->setStatut('En attente confirmation');
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

        $this->activityLogger->log(
            $type === 'AEV_NON_RECU' ? 'AEV NON RECU' : 'PAIEMENT MULTIPLE',
            sprintf('Ticket #%d créé via API', $ticket->getId()),
            'Ticket',
            $ticket->getId()
        );

        // 🔔 Temps réel : l'interlocuteur voit le nouveau ticket apparaître
        $this->realtimeNotifier->ticketUpdated('ticket.created', $ticket);

        return $this->json([
            'success' => true,
            'id' => $ticket->getId(),
            'message' => 'Ticket créé avec succès. En attente de confirmation.'
        ], 201);
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
        if ($ticket->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        if ($ticket->getStatut() !== 'En attente RIB') {
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
        $ticket->setStatut(Ticket::STATUT_RIB_RECU);
        $this->em->flush();

        $this->activityLogger->log(
            'RIB UPLOAD',
            sprintf('RIB ajouté au ticket #%d', $ticket->getId()),
            'Ticket',
            $ticket->getId()
        );

        // 🔔 Temps réel : l'interlocuteur voit le RIB arriver
        $this->realtimeNotifier->ticketUpdated('ticket.rib_uploaded', $ticket);

        return $this->json(['success' => true, 'message' => 'RIB envoyé avec succès']);
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
            ];
        }, $tickets);

        return $this->json($data);
    }
}