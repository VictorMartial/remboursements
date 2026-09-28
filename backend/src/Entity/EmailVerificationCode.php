<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Code de vérification envoyé par email pour finaliser l'inscription.
 */
#[ORM\Entity]
#[ORM\Table(name: 'email_verification_code')]
#[ORM\Index(columns: ['email'], name: 'idx_evc_email')]
class EmailVerificationCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180)]
    private string $email;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /** Mot de passe déjà hashé (ne jamais stocker en clair) */
    #[ORM\Column(type: 'string', length: 255)]
    private string $passwordHash;

    /** Code à 6 chiffres */
    #[ORM\Column(type: 'string', length: 10)]
    private string $code;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    public function __construct(string $email, string $name, string $passwordHash, string $code, int $ttlMinutes = 15)
    {
        $this->email = mb_strtolower(trim($email));
        $this->name = trim($name);
        $this->passwordHash = $passwordHash;
        $this->code = $code;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify(sprintf('+%d minutes', $ttlMinutes));
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getName(): string { return $this->name; }
    public function getPasswordHash(): string { return $this->passwordHash; }
    public function getCode(): string { return $this->code; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getAttempts(): int { return $this->attempts; }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    public function matches(string $code): bool
    {
        return hash_equals($this->code, trim($code));
    }
}
