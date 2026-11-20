<?php

namespace App\EventListener;

use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class JwtPasswordVersionListener
{
    public function __construct(private UserRepository $userRepo) {}

    #[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_created')]
    public function onCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        $payload = $event->getData();
        $payload['pca'] = method_exists($user, 'getPasswordChangedAt')
            ? ($user->getPasswordChangedAt()?->getTimestamp() ?? 0)
            : 0;
        $event->setData($payload);
    }

    #[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_decoded')]
    public function onDecoded(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();
        $identity = $payload['username'] ?? $payload['email'] ?? null;

        $user = $identity ? $this->userRepo->findOneBy(['email' => $identity]) : null;
        if (!$user) {
            $event->markAsInvalid();
            return;
        }

        $current = $user->getPasswordChangedAt()?->getTimestamp() ?? 0;
        if ((int) ($payload['pca'] ?? 0) !== $current) {
            $event->markAsInvalid();
        }
    }
}