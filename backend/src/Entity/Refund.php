<?php

namespace App\Entity;

use App\Repository\RefundRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RefundRepository::class)]
class Refund
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'refunds')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Ticket $ticket = null;

    #[ORM\Column(length: 255)]
    private ?string $montantRembourse = null;

    #[ORM\Column(length: 50)]
    private ?string $statut = 'En attente';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $methodePaiement = null;

    #[ORM\Column]
    private ?bool $obligatoire = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateValidation = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifEchec = null;

    // Getters et Setters
    public function getId(): ?int { return $this->id; }
    
    public function getTicket(): ?Ticket { return $this->ticket; }
    public function setTicket(?Ticket $ticket): static { $this->ticket = $ticket; return $this; }
    
    public function getMontantRembourse(): ?string { return $this->montantRembourse; }
    public function setMontantRembourse(string $montantRembourse): static { $this->montantRembourse = $montantRembourse; return $this; }
    
    public function getStatut(): ?string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = $statut; return $this; }
    
    public function getMethodePaiement(): ?string { return $this->methodePaiement; }
    public function setMethodePaiement(?string $methodePaiement): static { $this->methodePaiement = $methodePaiement; return $this; }
    
    public function isObligatoire(): ?bool { return $this->obligatoire; }
    public function setObligatoire(bool $obligatoire): static { $this->obligatoire = $obligatoire; return $this; }
    
    public function getDateCreation(): ?\DateTimeImmutable { return $this->dateCreation; }
    public function setDateCreation(?\DateTimeImmutable $dateCreation): static { $this->dateCreation = $dateCreation; return $this; }
    
    public function getDateValidation(): ?\DateTimeImmutable { return $this->dateValidation; }
    public function setDateValidation(?\DateTimeImmutable $dateValidation): static { $this->dateValidation = $dateValidation; return $this; }
    
    public function getMotifEchec(): ?string { return $this->motifEchec; }
    public function setMotifEchec(?string $motifEchec): static { $this->motifEchec = $motifEchec; return $this; }
}