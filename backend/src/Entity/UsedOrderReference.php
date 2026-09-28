<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Historique permanent des références de commande déjà utilisées.
 * Même si le ticket est supprimé, la référence reste ici et ne peut plus être réutilisée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'used_order_reference')]
#[ORM\UniqueConstraint(name: 'uniq_used_order_ref', columns: ['reference_normalized'])]
class UsedOrderReference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Référence telle que saisie par l'utilisateur */
    #[ORM\Column(type: 'string', length: 255)]
    private string $reference;

    /** Version normalisée (minuscules, trim) pour l'unicité */
    #[ORM\Column(type: 'string', length: 255)]
    private string $referenceNormalized;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $ticketId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $reference, ?int $ticketId = null, ?int $userId = null)
    {
        $this->reference = trim($reference);
        $this->referenceNormalized = mb_strtolower($this->reference);
        $this->ticketId = $ticketId;
        $this->userId = $userId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getReferenceNormalized(): string
    {
        return $this->referenceNormalized;
    }

    public function getTicketId(): ?int
    {
        return $this->ticketId;
    }

    public function setTicketId(?int $ticketId): self
    {
        $this->ticketId = $ticketId;
        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
