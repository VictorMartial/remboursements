<?php

namespace App\Controller\Api;

use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/user')]
#[IsGranted('ROLE_USER')]
class UserProfileApiController extends AbstractController
{
    #[Route('/profile', name: 'api_user_profile', methods: ['GET'])]
    public function profile(): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = $this->getUser();
            if (!$user) {
                return $this->json(['error' => 'Utilisateur non authentifié'], 401);
            }

            // Récupération sécurisée des données
            $email = $user->getEmail();

            // Rôles (toujours disponible via l'interface)
            $roles = $user->getRoles();

            // Vérification (si la méthode existe)
            $verified = false;
            if (method_exists($user, 'isVerified')) {
                $verified = $user->isVerified();
            } elseif (method_exists($user, 'getVerified')) {
                $verified = (bool) $user->getVerified();
            }

            // Date de création
            $createdAt = null;
            if (method_exists($user, 'getCreatedAt') && $user->getCreatedAt()) {
                $createdAt = $user->getCreatedAt()->format('d/m/Y H:i');
            } elseif (method_exists($user, 'getDateCreation') && $user->getDateCreation()) {
                $createdAt = $user->getDateCreation()->format('d/m/Y H:i');
            }

            return $this->json([
                'email' => $email,
                'roles' => $roles,
                'verified' => $verified,
                'createdAt' => $createdAt,
            ]);
        } catch (\Exception $e) {
            // Journalise l'erreur et retourne un message clair
            error_log('Erreur dans /api/user/profile : ' . $e->getMessage());
            return $this->json([
                'error' => 'Erreur interne : ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/tickets', name: 'api_user_tickets', methods: ['GET'])]
    public function tickets(TicketRepository $ticketRepo): JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user) {
                return $this->json(['error' => 'Non authentifié'], 401);
            }

            $tickets = $ticketRepo->findBy(['user' => $user], ['id' => 'DESC']);

            $data = array_map(function (Ticket $ticket) {
                return [
                    'id' => $ticket->getId(),
                    'id_commande' => method_exists($ticket, 'getIdCommande') ? ($ticket->getIdCommande() ?? '') : '',
                    'reference' => $ticket->getReferenceCommande() ?? '-',
                    'references' => $ticket->getReferences()
                        ? array_values(array_filter(explode("\n", $ticket->getReferences())))
                        : [],
                    'montant' => $ticket->getMontantARembourser() ?? 0,
                    'devise' => $ticket->getDevise() ?? 'EUR',
                    'statut' => $ticket->getStatut() ?? 'Inconnu',
                    'date' => $ticket->getDateCommande()?->format('d/m/Y') ?? '-',
                ];
            }, $tickets);

            return $this->json($data);
        } catch (\Exception $e) {
            error_log('Erreur dans /api/user/tickets : ' . $e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/ticket/{id}', name: 'api_user_ticket_show', methods: ['GET'])]
    public function showTicket(Ticket $ticket): JsonResponse
    {
        try {
            if ($ticket->getUser() !== $this->getUser()) {
                return $this->json(['error' => 'Accès refusé'], 403);
            }

            return $this->json([
                'id' => $ticket->getId(),
                'id_commande' => method_exists($ticket, 'getIdCommande') ? ($ticket->getIdCommande() ?? '') : '',
                'reference' => $ticket->getReferenceCommande() ?? '-',
                'references' => $ticket->getReferences()
                    ? array_values(array_filter(explode("\n", $ticket->getReferences())))
                    : [],
                'montant' => $ticket->getMontantARembourser() ?? 0,
                'devise' => $ticket->getDevise() ?? 'EUR',
                'statut' => $ticket->getStatut() ?? 'Inconnu',
                'date' => $ticket->getDateCommande()?->format('d/m/Y') ?? '-',
                'description' => $ticket->getDescription() ?? '',
                'type' => $ticket->getTypeRemboursement() ?? '',
                'rib' => $ticket->getRib() ?? null,
            ]);
        } catch (\Exception $e) {
            error_log('Erreur dans /api/user/ticket/{id} : ' . $e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/ticket/{id}/delete', name: 'api_user_ticket_delete', methods: ['POST', 'DELETE'])]
    public function deleteTicket(Ticket $ticket, EntityManagerInterface $em): JsonResponse
    {
        try {
            if ($ticket->getUser() !== $this->getUser()) {
                return $this->json(['error' => 'Accès refusé'], 403);
            }

            $em->remove($ticket);
            $em->flush();

            return $this->json(['success' => true, 'message' => 'Ticket supprimé']);
        } catch (\Exception $e) {
            error_log('Erreur dans /api/user/ticket/delete : ' . $e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}