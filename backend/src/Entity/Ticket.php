<?php

namespace App\Entity;

use App\Repository\TicketRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'ticket')]
class Ticket
{
    // =========================
    // STATUTS
    // =========================
    public const STATUT_CREE = 'Créée';
    public const STATUT_RIB = 'En attente RIB';
    public const STATUT_ATTENTE_CONFIRMATION = 'En attente confirmation';
    public const STATUT_ATTENTE_RIB = 'En attente RIB';
    public const STATUT_RIB_RECU = 'RIB reçu';
    public const STATUT_ATTENTE_REPONSE_BANQUE = 'En attente réponse banque';
    public const STATUT_VALIDE = 'Validé';
    public const STATUT_REFUSE = 'Refusé';

    // =========================
    // TYPES REMBOURSEMENT
    // =========================
    public const TYPE_AEV = 'AEV_NON_RECU';
    public const TYPE_MULTIPLE = 'PAIEMENT_MULTIPLE';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateCommande = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $idCommande = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referenceCommande = null;

    #[ORM\Column(name: 'liste_references', length: 255, nullable: true)]
    private ?string $references = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $idTransaction = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $methodePaiement = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $identifiantCompte = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $montantCommande = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rib = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $devise = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    private ?string $statut = self::STATUT_CREE;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $statutPaiement = 'En attente';

    #[ORM\Column]
    private ?int $nombreTentatives = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateEchec = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifEchec = null;

    #[ORM\Column]
    private ?bool $aRembourser = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateRemboursement = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $statutRemboursement = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Ticket $ticketParent = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $typeTransaction = null;

    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 1])]
    private int $quantite = 1;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $montantUnitaire = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $montantARembourser = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $typeRemboursement = null; // AEV_NON_RECU, PAIEMENT_MULTIPLE

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referencePaiement = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailBanqueEnvoyeAt = null;

    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: Refund::class, cascade: ['persist', 'remove'])]
    private Collection $refunds;

    public function __construct()
    {
        $this->refunds = new ArrayCollection();
    }

    // Getters et Setters
    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getDateCommande(): ?\DateTimeImmutable { return $this->dateCommande; }
    public function setDateCommande(?\DateTimeImmutable $dateCommande): static { $this->dateCommande = $dateCommande; return $this; }
    public function getIdCommande(): ?string { return $this->idCommande; }
    public function setIdCommande(?string $idCommande): static { $this->idCommande = $idCommande; return $this; }
    public function getReferenceCommande(): ?string { return $this->referenceCommande; }
    public function setReferenceCommande(?string $referenceCommande): static { $this->referenceCommande = $referenceCommande; return $this; }
    public function getReferences(): ?string { return $this->references; }
    public function setReferences(?string $references): static { $this->references = $references; return $this; }
    public function getIdTransaction(): ?string { return $this->idTransaction; }
    public function setIdTransaction(?string $idTransaction): static { $this->idTransaction = $idTransaction; return $this; }
    public function getMethodePaiement(): ?string { return $this->methodePaiement; }
    public function setMethodePaiement(?string $methodePaiement): static { $this->methodePaiement = $methodePaiement; return $this; }
    public function getIdentifiantCompte(): ?string { return $this->identifiantCompte; }
    public function setIdentifiantCompte(?string $identifiantCompte): static { $this->identifiantCompte = $identifiantCompte; return $this; }
    public function getMontantCommande(): ?string { return $this->montantCommande; }
    public function setMontantCommande(?string $montantCommande): static { $this->montantCommande = $montantCommande; return $this; }
    public function getRib(): ?string { return $this->rib; }
    public function setRib(?string $rib): static { $this->rib = $rib; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getStatut(): ?string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = $statut; return $this; }
    public function getStatutPaiement(): ?string { return $this->statutPaiement; }
    public function setStatutPaiement(?string $statutPaiement): static { $this->statutPaiement = $statutPaiement; return $this; }
    public function getNombreTentatives(): ?int { return $this->nombreTentatives; }
    public function setNombreTentatives(int $nombreTentatives): static { $this->nombreTentatives = $nombreTentatives; return $this; }
    public function getDateEchec(): ?\DateTimeImmutable { return $this->dateEchec; }
    public function setDateEchec(?\DateTimeImmutable $dateEchec): static { $this->dateEchec = $dateEchec; return $this; }
    public function getMotifEchec(): ?string { return $this->motifEchec; }
    public function setMotifEchec(?string $motifEchec): static { $this->motifEchec = $motifEchec; return $this; }
    public function isARembourser(): ?bool { return $this->aRembourser; }
    public function setARembourser(bool $aRembourser): static { $this->aRembourser = $aRembourser; return $this; }
    public function getDateRemboursement(): ?\DateTimeImmutable { return $this->dateRemboursement; }
    public function setDateRemboursement(?\DateTimeImmutable $dateRemboursement): static { $this->dateRemboursement = $dateRemboursement; return $this; }
    public function getStatutRemboursement(): ?string { return $this->statutRemboursement; }
    public function setStatutRemboursement(?string $statutRemboursement): static { $this->statutRemboursement = $statutRemboursement; return $this; }
    public function getTicketParent(): ?Ticket { return $this->ticketParent; }
    public function setTicketParent(?Ticket $ticketParent): static { $this->ticketParent = $ticketParent; return $this; }
    public function getTypeTransaction(): ?string { return $this->typeTransaction; }
    public function setTypeTransaction(?string $typeTransaction): static { $this->typeTransaction = $typeTransaction; return $this; }
    public function getQuantite(): ?int { return $this->quantite; }
    public function setQuantite(?int $quantite): static { $this->quantite = $quantite; return $this; }
    public function getMontantUnitaire(): ?string { return $this->montantUnitaire; }
    public function setMontantUnitaire(?string $montantUnitaire): static { $this->montantUnitaire = $montantUnitaire; return $this; }
    public function getMontantARembourser(): ?string { return $this->montantARembourser; }
    public function setMontantARembourser(?string $montantARembourser): static { $this->montantARembourser = $montantARembourser; return $this; }
    public function getTypeRemboursement(): ?string { return $this->typeRemboursement; }
    public function setTypeRemboursement(?string $typeRemboursement): static { $this->typeRemboursement = $typeRemboursement; return $this; }
    public function getReferencePaiement(): ?string { return $this->referencePaiement; }
    public function setReferencePaiement(?string $referencePaiement): static { $this->referencePaiement = $referencePaiement; return $this; }
    public function getEmailBanqueEnvoyeAt(): ?\DateTimeImmutable { return $this->emailBanqueEnvoyeAt; }
    public function setEmailBanqueEnvoyeAt(?\DateTimeImmutable $emailBanqueEnvoyeAt): static { $this->emailBanqueEnvoyeAt = $emailBanqueEnvoyeAt; return $this; }
    public function getDevise(): ?string { return $this->devise; }
    public function setDevise(?string $devise): static { $this->devise = $devise; return $this; }
    public function getRefunds(): Collection { return $this->refunds; }
    public function addRefund(Refund $refund): static { if (!$this->refunds->contains($refund)) { $this->refunds->add($refund); $refund->setTicket($this); } return $this; }
    public function removeRefund(Refund $refund): static { if ($this->refunds->removeElement($refund)) { if ($refund->getTicket() === $this) { $refund->setTicket(null); } } return $this; }
}