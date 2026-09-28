<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cet email.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank(message: 'L\'email est obligatoire.')]
    #[Assert\Email(message: 'Veuillez saisir un email valide.')]
    private ?string $email = null;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(type: 'json')]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 6, nullable: true)]
    private ?string $resetCode = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetCodeExpiresAt = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $locale = null;

    public function getResetCode(): ?string
    {
        return $this->resetCode;
    }

    public function setResetCode(?string $resetCode): static
    {
        $this->resetCode = $resetCode;
        return $this;
    }

    public function getResetCodeExpiresAt(): ?\DateTimeImmutable
    {
        return $this->resetCodeExpiresAt;
    }

    public function setResetCodeExpiresAt(?\DateTimeImmutable $resetCodeExpiresAt): static
    {
        $this->resetCodeExpiresAt = $resetCodeExpiresAt;
        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;
        return $this;
    }

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRole(): ?string
{
    return $this->roles[0] ?? 'ROLE_USER';
}

public function setRole(string $role): static
{
    $this->roles = [$role];
    return $this;
}

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    private ?string $plainPassword = null;

public function getPlainPassword(): ?string
{
    return $this->plainPassword;
}

public function setPlainPassword(?string $plainPassword): static
{
    $this->plainPassword = $plainPassword;
    return $this;
}

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->email ?? '';
    }
}