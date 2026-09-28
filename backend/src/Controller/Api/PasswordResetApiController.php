<?php

namespace App\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class PasswordResetApiController extends AbstractController
{
    #[Route('/api/forgot-password', name: 'api_forgot_password', methods: ['POST'])]
    public function forgotPassword(
        Request $request,
        EntityManagerInterface $em,
        MailerInterface $mailer
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['success' => false, 'message' => 'Données JSON invalides.'], 400);
        }

        $email = trim($data['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'message' => 'Email invalide.'], 400);
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        // Compte inexistant
        if (!$user) {
            return $this->json([
                'success' => false,
                'message' => 'Aucun compte n\'est associé à cet email.'
            ], 404);
        }

        // Génération du code
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->setResetCode($code);
        $user->setResetCodeExpiresAt(new \DateTimeImmutable('+15 minutes'));
        $em->flush();

        $from = $_ENV['APP_EMAIL_FROM'] ?? 'remboursement@madaozi.mg';

        try {
            $mailEmail = (new Email())
                ->from($from)
                ->to($user->getEmail())
                ->subject('Réinitialisation de mot de passe - adminHMD')
                ->text(
                    "Bonjour,\n\n" .
                    "Votre code de réinitialisation est : $code\n\n" .
                    "Ce code expire dans 15 minutes.\n\n" .
                    "Si vous n'avez pas demandé cette réinitialisation, ignorez ce message.\n\n" .
                    "— L'équipe adminHMD"
                );

            $mailer->send($mailEmail);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[PasswordReset] Échec envoi mail à %s | Code: %s | Erreur: %s',
                $email,
                $code,
                $e->getMessage()
            ));
        }

        return $this->json([
            'success' => true,
            'message' => 'Un code de réinitialisation a été envoyé à votre adresse email.'
        ]);
    }

    #[Route('/api/verify-reset-code', name: 'api_verify_reset_code', methods: ['POST'])]
    public function verifyResetCode(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['success' => false, 'message' => 'Données invalides.'], 400);
        }

        $email = trim($data['email'] ?? '');
        $code  = trim($data['code'] ?? '');

        if (empty($email) || empty($code)) {
            return $this->json(['success' => false, 'message' => 'Email et code requis.'], 400);
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user || $user->getResetCode() !== $code) {
            return $this->json(['success' => false, 'message' => 'Code invalide.'], 400);
        }

        if (!$user->getResetCodeExpiresAt() || $user->getResetCodeExpiresAt() < new \DateTimeImmutable()) {
            return $this->json([
                'success' => false,
                'message' => 'Code expiré. Veuillez en demander un nouveau.'
            ], 400);
        }

        return $this->json([
            'success' => true,
            'message' => 'Code valide. Vous pouvez maintenant choisir un nouveau mot de passe.'
        ]);
    }

    #[Route('/api/reset-password', name: 'api_reset_password', methods: ['POST'])]
    public function resetPassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['success' => false, 'message' => 'Données JSON invalides.'], 400);
        }

        $email       = trim($data['email'] ?? '');
        $code        = trim($data['code'] ?? '');
        $newPassword = $data['password'] ?? '';

        if (empty($email) || empty($code) || empty($newPassword)) {
            return $this->json(['success' => false, 'message' => 'Tous les champs sont requis.'], 400);
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user || $user->getResetCode() !== $code) {
            return $this->json(['success' => false, 'message' => 'Code invalide.'], 400);
        }

        if (!$user->getResetCodeExpiresAt() || $user->getResetCodeExpiresAt() < new \DateTimeImmutable()) {
            return $this->json(['success' => false, 'message' => 'Code expiré.'], 400);
        }

        if (strlen($newPassword) < 6) {
            return $this->json(['success' => false, 'message' => 'Mot de passe trop court (6 caractères min).'], 400);
        }

        $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
        $user->setResetCode(null);
        $user->setResetCodeExpiresAt(null);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Mot de passe réinitialisé avec succès.'
        ]);
    }
}