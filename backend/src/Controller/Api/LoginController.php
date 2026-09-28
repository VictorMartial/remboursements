<?php

namespace App\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class LoginController extends AbstractController
{
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        JWTTokenManagerInterface $jwtManager
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        /** @var User|null $user */
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user || !$passwordHasher->isPasswordValid($user, $password)) {
            return $this->json(['success' => false, 'message' => 'Identifiants invalides.'], 401);
        }

        $token = $jwtManager->create($user);

        // Redirection selon les rôles (ordre prioritaire, une seule fois)
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true)) {
            $redirect = '/admin/index.html';
        } elseif (in_array('ROLE_INTERLOCUTEUR', $roles, true)) {
            $redirect = '/interlocuteur/interlocuteur-dashboard.html';
        } elseif (in_array('ROLE_USER', $roles, true)) {
            $redirect = '/user/dashboard-user.html';
        } else {
            $redirect = '/auth/login.html';
        }

        return $this->json([
            'success' => true,
            'token' => $token,
            'redirect' => $redirect
        ]);
    }
}