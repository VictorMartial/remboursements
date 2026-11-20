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

#[Route('/api/interlocuteur')]
class InterlocuteurProfileApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepo,
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    private function isInterlocuteur(): bool
    {
        $u = $this->getUser();
        return $u && method_exists($u, 'getRoles')
            && (in_array('ROLE_INTERLOCUTEUR', $u->getRoles(), true)
                || in_array('ROLE_ADMIN', $u->getRoles(), true));
    }

    #[Route('/profile', name: 'api_interlocuteur_profile', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function profile(): JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user) {
                return $this->json(['error' => 'Utilisateur non authentifie'], 401);
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

            return $this->json([
                'email'     => $email,
                'roles'     => $roles,
                'role'      => method_exists($user, 'getRole') ? $user->getRole() : ($roles[0] ?? null),
                'verified'  => $verified,
                'createdAt' => $createdAt,
                'bio'       => method_exists($user, 'getBio') ? $user->getBio() : null,
            ]);
        } catch (\Exception $e) {
            error_log('Erreur dans /api/interlocuteur/profile : ' . $e->getMessage());
            return $this->json(['error' => 'Erreur interne : ' . $e->getMessage()], 500);
        }
    }

    #[Route('/profile', name: 'api_interlocuteur_profile_update', methods: ['PUT', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'Non authentifie'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (isset($data['email'])) {
            $email = trim((string) $data['email']);
            if ($email === '') {
                return $this->json(['success' => false, 'message' => "L'email est obligatoire."], 400);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json(['success' => false, 'message' => "Format d'email invalide."], 400);
            }
            $existing = $this->userRepo->findOneBy(['email' => $email]);
            if ($existing && $existing->getId() !== $user->getId()) {
                return $this->json(['success' => false, 'message' => 'Cet email est deja utilise.'], 400);
            }
            $user->setEmail($email);
        }

        if (array_key_exists('bio', $data) && method_exists($user, 'setBio')) {
            $user->setBio(trim((string) $data['bio']));
        }

        $passwordChanged = false;

if (!empty($data['password'])) {
    $password = (string) $data['password'];
    if (strlen($password) < 6) {
        return $this->json([
            'success' => false,
            'message' => 'Le mot de passe doit contenir au moins 6 caractères.',
        ], 400);
    }
    $user->setPassword($this->passwordHasher->hashPassword($user, $password));
    $user->setPasswordChangedAt(new \DateTimeImmutable());
    $passwordChanged = true;
}

$this->em->flush();

return $this->json([
    'success'   => true,
    'message'   => $passwordChanged
        ? 'Mot de passe modifié. Veuillez vous reconnecter.'
        : 'Profil mis à jour avec succès.',
    'logout'    => $passwordChanged,
    'email'     => $user->getEmail(),
    'bio'       => method_exists($user, 'getBio') ? $user->getBio() : null,
    'createdAt' => $user->getCreatedAt()?->format('d/m/Y H:i'),
]);
    }

    #[Route('/tickets', name: 'api_interlocuteur_tickets', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function tickets(TicketRepository $ticketRepo): JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user) {
                return $this->json(['error' => 'Non authentifie'], 401);
            }

            $criteria = $this->isInterlocuteur() ? [] : ['user' => $user];
            $tickets = $ticketRepo->findBy($criteria, ['id' => 'DESC']);

                        $data = array_map(function (Ticket $ticket) {
                return [
                    'id'            => $ticket->getId(),
                    'id_commande'   => method_exists($ticket, 'getIdCommande') ? ($ticket->getIdCommande() ?? '') : '',
                    'reference'     => $ticket->getReferenceCommande() ?? '-',
                    'references'    => $ticket->getReferences()
                        ? array_values(array_filter(explode("\n", $ticket->getReferences())))
                        : [],
                    'montant'       => $ticket->getMontantARembourser() ?? 0,
                    'devise'        => $ticket->getDevise() ?? 'EUR',
                    'statut'        => $ticket->getStatut() ?? 'Inconnu',
                    'date'          => $ticket->getDateCommande()?->format('d/m/Y') ?? '-',
                    'date_iso'      => $ticket->getDateCommande()?->format('Y-m-d') ?? null,
                    'type'                  => $ticket->getTypeRemboursement() ?? '',
                    'type_remboursement'    => $ticket->getTypeRemboursement() ?? '',
                    'suivi_banque'  => method_exists($ticket, 'getSuiviBanque')  ? ($ticket->getSuiviBanque()  ?? null) : null,
                    'suivi_client'  => method_exists($ticket, 'getSuiviClient')  ? ($ticket->getSuiviClient()  ?? null) : null,
                    'suivi_madaozi' => method_exists($ticket, 'getSuiviMadaozi') ? ($ticket->getSuiviMadaozi() ?? null) : null,
                ];
            }, $tickets);

            return $this->json($data);
        } catch (\Exception $e) {
            error_log('Erreur dans /api/interlocuteur/tickets : ' . $e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
