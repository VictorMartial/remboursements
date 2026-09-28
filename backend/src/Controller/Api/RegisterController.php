<?php

namespace App\Controller\Api;

use App\Entity\EmailVerificationCode;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class RegisterController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private MailerInterface $mailer,
        private ParameterBagInterface $params,
        private LoggerInterface $logger,
    ) {}

    /**
     * Étape 1 : demande d'inscription → envoi d'un code à 6 chiffres par email.
     * Ne crée pas encore le compte User.
     */
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($data['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');

        $errors = [];
        if ($name === '') {
            $errors[] = 'Le nom est obligatoire.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email invalide.';
        }
        if (strlen($password) < 6) {
            $errors[] = 'Le mot de passe doit faire au moins 6 caractères.';
        }
        if ($errors) {
            return $this->json(['success' => false, 'message' => implode(' ', $errors), 'errors' => $errors], 400);
        }

        // Déjà inscrit ?
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing) {
            return $this->json([
                'success' => false,
                'message' => 'Un compte existe déjà avec cet email. Connectez-vous ou utilisez un autre email.',
            ], 409);
        }

        // Hash du mot de passe (on stocke le hash en attente, pas le mot de passe clair)
        $tmpUser = new User();
        if (method_exists($tmpUser, 'setEmail')) {
            $tmpUser->setEmail($email);
        }
        $passwordHash = $this->passwordHasher->hashPassword($tmpUser, $password);

        // Code à 6 chiffres
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Supprime les anciens codes pour cet email
        $old = $this->em->getRepository(EmailVerificationCode::class)->findBy(['email' => $email]);
        foreach ($old as $row) {
            $this->em->remove($row);
        }

        $pending = new EmailVerificationCode($email, $name, $passwordHash, $code, 15);
        $this->em->persist($pending);
        $this->em->flush();

        // Envoi email via transport CLIENT (refund@evisamada-mg.com)
        try {
            $this->sendVerificationEmail($email, $name, $code);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur envoi code inscription : ' . $e->getMessage());
            return $this->json([
                'success' => false,
                'message' => 'Impossible d\'envoyer le code de vérification. Réessayez plus tard.',
            ], 500);
        }

        return $this->json([
            'success' => true,
            'step' => 'verify_code',
            'message' => 'Un code de vérification a été envoyé à ' . $email . '. Saisissez-le pour finaliser l\'inscription.',
            'email' => $email,
        ]);
    }

    /**
     * Étape 2 : validation du code → création du compte User.
     */
    #[Route('/api/register/verify', name: 'api_register_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $code = trim((string) ($data['code'] ?? ''));

        if ($email === '' || $code === '') {
            return $this->json(['success' => false, 'message' => 'Email et code sont obligatoires.'], 400);
        }

        /** @var EmailVerificationCode|null $pending */
        $pending = $this->em->getRepository(EmailVerificationCode::class)
            ->findOneBy(['email' => $email], ['id' => 'DESC']);

        if (!$pending) {
            return $this->json([
                'success' => false,
                'message' => 'Aucune demande d\'inscription en cours pour cet email. Recommencez l\'inscription.',
            ], 404);
        }

        if ($pending->isExpired()) {
            $this->em->remove($pending);
            $this->em->flush();
            return $this->json([
                'success' => false,
                'message' => 'Le code a expiré. Recommencez l\'inscription pour recevoir un nouveau code.',
                'expired' => true,
            ], 400);
        }

        if ($pending->getAttempts() >= 5) {
            $this->em->remove($pending);
            $this->em->flush();
            return $this->json([
                'success' => false,
                'message' => 'Trop de tentatives. Recommencez l\'inscription.',
            ], 429);
        }

        if (!$pending->matches($code)) {
            $pending->incrementAttempts();
            $this->em->flush();
            return $this->json([
                'success' => false,
                'message' => 'Code incorrect. Vérifiez votre email et réessayez.',
            ], 400);
        }

        // Déjà créé entre-temps ?
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing) {
            $this->em->remove($pending);
            $this->em->flush();
            return $this->json([
                'success' => false,
                'message' => 'Un compte existe déjà avec cet email.',
            ], 409);
        }

        // Création du User
        $user = new User();
        $user->setEmail($pending->getEmail());
        if (method_exists($user, 'setName')) {
            $user->setName($pending->getName());
        } elseif (method_exists($user, 'setFullName')) {
            $user->setFullName($pending->getName());
        }
        // Mot de passe déjà hashé : setPassword direct
        if (method_exists($user, 'setPassword')) {
            $user->setPassword($pending->getPasswordHash());
        }
        if (method_exists($user, 'setRoles')) {
            $user->setRoles(['ROLE_USER']);
        }
        if (method_exists($user, 'setVerified')) {
            $user->setVerified(true);
        } elseif (method_exists($user, 'setIsVerified')) {
            $user->setIsVerified(true);
        }

        $this->em->persist($user);
        $this->em->remove($pending);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Inscription réussie. Vous pouvez vous connecter.',
            'redirect' => '/auth/login.html?registered=1',
        ]);
    }

    /**
     * Renvoi d'un nouveau code (si non expiré / encore en attente).
     */
    #[Route('/api/register/resend-code', name: 'api_register_resend', methods: ['POST'])]
    public function resendCode(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));

        if ($email === '') {
            return $this->json(['success' => false, 'message' => 'Email obligatoire.'], 400);
        }

        /** @var EmailVerificationCode|null $pending */
        $pending = $this->em->getRepository(EmailVerificationCode::class)
            ->findOneBy(['email' => $email], ['id' => 'DESC']);

        if (!$pending) {
            return $this->json(['success' => false, 'message' => 'Aucune inscription en cours pour cet email.'], 404);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        // Nouvelle entrée (invalide l'ancienne)
        $this->em->remove($pending);
        $newPending = new EmailVerificationCode(
            $pending->getEmail(),
            $pending->getName(),
            $pending->getPasswordHash(),
            $code,
            15
        );
        $this->em->persist($newPending);
        $this->em->flush();

        try {
            $this->sendVerificationEmail($pending->getEmail(), $pending->getName(), $code);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur renvoi code : ' . $e->getMessage());
            return $this->json(['success' => false, 'message' => 'Échec de l\'envoi du code.'], 500);
        }

        return $this->json([
            'success' => true,
            'message' => 'Un nouveau code a été envoyé à ' . $email . '.',
        ]);
    }

    private function sendVerificationEmail(string $to, string $name, string $code): void
    {
        $from = $this->params->has('client_email_from')
            ? $this->params->get('client_email_from')
            : $this->params->get('app_email_from');
        $reply = $this->params->has('client_email_reply_to')
            ? $this->params->get('client_email_reply_to')
            : $this->params->get('app_email_reply_to');

        $html = sprintf(
            '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;">
                <h2 style="color:#4f46e5;">Vérification de votre inscription</h2>
                <p>Bonjour %s,</p>
                <p>Voici votre code de vérification pour finaliser votre inscription sur <strong>Mada Ozi</strong> :</p>
                <p style="font-size:32px;font-weight:bold;letter-spacing:8px;text-align:center;background:#eef2ff;padding:16px;border-radius:12px;color:#312e81;">%s</p>
                <p>Ce code est valable <strong>15 minutes</strong>.</p>
                <p style="color:#6b7280;font-size:13px;">Si vous n\'avez pas demandé cette inscription, ignorez cet email.</p>
            </div>',
            htmlspecialchars($name),
            htmlspecialchars($code)
        );

        $email = (new Email())
            ->from($from)
            ->to($to)
            ->replyTo($reply)
            ->subject('Votre code de vérification Mada Ozi')
            ->html($html);

        $email->getHeaders()->addTextHeader('X-Transport', 'client');
        $this->mailer->send($email);
    }
}
