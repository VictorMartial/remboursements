<?php

namespace App\Controller\Api;

use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/user')]
class UserProfileApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepo,
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    /**
     * GET /api/user/profile
     * Accessible à tout utilisateur connecté
     */
    #[Route('/profile', name: 'api_user_profile', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function profile(): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = $this->getUser();
            if (!$user) {
                return $this->json(['error' => 'Utilisateur non authentifié'], 401);
            }

            $email = $user->getEmail();
            $roles = $user->getRoles();

            $verified = false;
            if (method_exists($user, 'isVerified')) {
                $verified = $user->isVerified();
            } elseif (method_exists($user, 'getVerified')) {
                $verified = (bool) $user->getVerified();
            }

            $createdAt = null;
            if (method_exists($user, 'getCreatedAt') && $user->getCreatedAt()) {
                $createdAt = $user->getCreatedAt()->format('d/m/Y H:i');
            } elseif (method_exists($user, 'getDateCreation') && $user->getDateCreation()) {
                $createdAt = $user->getDateCreation()->format('d/m/Y H:i');
            }

            $bio = method_exists($user, 'getBio') ? $user->getBio() : null;

            return $this->json([
                'email'     => $email,
                'roles'     => $roles,
                'role'      => method_exists($user, 'getRole') ? $user->getRole() : ($roles[0] ?? null),
                'verified'  => $verified,
                'createdAt' => $createdAt,
                'bio'       => $bio,
            ]);
        } catch (\Exception $e) {
            error_log('Erreur dans /api/user/profile : ' . $e->getMessage());
            return $this->json(['error' => 'Erreur interne : ' . $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/user/profile
     * Mise à jour du profil → RÉSERVÉ AUX ADMINS uniquement
     * Body : { "email": "...", "bio": "...", "password": "..." }
     * password est optionnel
     */
    #[Route('/profile', name: 'api_user_profile_update', methods: ['PUT', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'Non authentifié'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        // --- Email ---
        if (isset($data['email'])) {
            $email = trim((string) $data['email']);

            if ($email === '') {
                return $this->json([
                    'success' => false,
                    'message' => 'L\'email est obligatoire.',
                ], 400);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Format d\'email invalide.',
                ], 400);
            }

            $existing = $this->userRepo->findOneBy(['email' => $email]);
            if ($existing && $existing->getId() !== $user->getId()) {
                return $this->json([
                    'success' => false,
                    'message' => 'Cet email est déjà utilisé.',
                ], 400);
            }

            $user->setEmail($email);
        }

        // --- Bio ---
        if (array_key_exists('bio', $data) && method_exists($user, 'setBio')) {
            $user->setBio(trim((string) $data['bio']));
        }

        // --- Mot de passe (optionnel) ---
        if (!empty($data['password'])) {
            $password = (string) $data['password'];

            if (strlen($password) < 6) {
                return $this->json([
                    'success' => false,
                    'message' => 'Le mot de passe doit contenir au moins 6 caractères.',
                ], 400);
            }

            $user->setPassword(
                $this->passwordHasher->hashPassword($user, $password)
            );
        }

        $this->em->flush();

        return $this->json([
            'success'   => true,
            'message'   => 'Profil mis à jour avec succès.',
            'email'     => $user->getEmail(),
            'bio'       => method_exists($user, 'getBio') ? $user->getBio() : null,
            'createdAt' => $user->getCreatedAt() ? $user->getCreatedAt()->format('d/m/Y H:i') : null,
        ]);
    }

    // ------------------------------------------------------------------
    // Tickets de l'utilisateur connecté
    // ------------------------------------------------------------------

    #[Route('/tickets', name: 'api_user_tickets', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
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
                    'suivi_banque' => method_exists($ticket, 'getSuiviBanque') ? ($ticket->getSuiviBanque() ?? null) : null,
                    'suivi_client' => method_exists($ticket, 'getSuiviClient') ? ($ticket->getSuiviClient() ?? null) : null,
                    'suivi_madaozi' => method_exists($ticket, 'getSuiviMadaozi') ? ($ticket->getSuiviMadaozi() ?? null) : null,
                ];
            }, $tickets);

            return $this->json($data);
        } catch (\Exception $e) {
            error_log('Erreur dans /api/user/tickets : ' . $e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/ticket/{id}', name: 'api_user_ticket_show', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
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
                'suivi_banque' => method_exists($ticket, 'getSuiviBanque') ? ($ticket->getSuiviBanque() ?? null) : null,
                'suivi_client' => method_exists($ticket, 'getSuiviClient') ? ($ticket->getSuiviClient() ?? null) : null,
                'suivi_madaozi' => method_exists($ticket, 'getSuiviMadaozi') ? ($ticket->getSuiviMadaozi() ?? null) : null,
            ]);
        } catch (\Exception $e) {
            error_log('Erreur dans /api/user/ticket/{id} : ' . $e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/ticket/{id}/delete', name: 'api_user_ticket_delete', methods: ['POST', 'DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function deleteTicket(Ticket $ticket): JsonResponse
    {
        try {
            if ($ticket->getUser() !== $this->getUser()) {
                return $this->json(['error' => 'Accès refusé'], 403);
            }

            $this->em->remove($ticket);
            $this->em->flush();

            return $this->json(['success' => true, 'message' => 'Ticket supprimé']);
        } catch (\Exception $e) {
            error_log('Erreur dans /api/user/ticket/delete : ' . $e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
