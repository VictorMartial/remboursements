<?php

namespace App\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RegisterApiController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['success' => false, 'message' => 'Données JSON invalides.'], 400);
        }

        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            return $this->json(['success' => false, 'message' => 'Email et mot de passe requis.'], 400);
        }

        if (strlen($password) < 6) {
            return $this->json(['success' => false, 'message' => 'Le mot de passe doit faire au moins 6 caractères.'], 400);
        }

        $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing) {
            return $this->json(['success' => false, 'message' => 'Cet email est déjà utilisé.'], 409);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setRole('ROLE_USER');          // ← setRole() existe bien dans votre entité
        $user->setPlainPassword($password);

        $errors = $validator->validate($user);
        if (count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[] = $error->getMessage();
            }
            return $this->json(['success' => false, 'errors' => $messages], 400);
        }

        $hashed = $passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashed);
        $user->setPlainPassword(null);

        $em->persist($user);
        $em->flush();

        // Redirect ABSOLU pour éviter les problèmes de chemin relatif
        return $this->json([
            'success'  => true,
            'message'  => 'Compte créé avec succès.',
            'redirect' => '/auth/login.html?registered=1'
        ], 201);
    }
}