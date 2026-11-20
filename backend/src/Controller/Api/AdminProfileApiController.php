<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/profile')]
#[IsGranted('ROLE_ADMIN')]
class AdminProfileApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepo,
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    /**
     * GET /api/admin/profile
     * Profil de l'admin connecté
     */
    #[Route('', name: 'api_admin_profile', methods: ['GET'])]
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

            return $this->json([
                'email'     => $email,
                'roles'     => $roles,
                'role'      => method_exists($user, 'getRole') ? $user->getRole() : ($roles[0] ?? null),
                'verified'  => $verified,
                'createdAt' => $createdAt,
                'bio'       => method_exists($user, 'getBio') ? $user->getBio() : null,
            ]);
        } catch (\Exception $e) {
            error_log('Erreur dans /api/admin/profile : ' . $e->getMessage());
            return $this->json(['error' => 'Erreur interne : ' . $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/admin/profile
     * Mise à jour du profil admin
     */
    #[Route('', name: 'api_admin_profile_update', methods: ['PUT', 'POST'])]
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'Non authentifié'], 401);
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
                return $this->json(['success' => false, 'message' => 'Cet email est déjà utilisé.'], 400);
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
}