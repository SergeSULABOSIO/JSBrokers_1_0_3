<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\DocumentRepository;
use App\Entity\Traits\AuditableTrait;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Annotation\Groups;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class Document
{
    use AuditableTrait;
    
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    #[Vich\UploadableField(mapping: 'piece_sinistre_documents', fileNameProperty: 'nomFichierStocke')]
    private ?File $fichier = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $nomFichierStocke = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Classeur $classeur = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?PieceSinistre $pieceSinistre = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?OffreIndemnisationSinistre $offreIndemnisationSinistre = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Cotation $cotation = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Avenant $avenant = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Tache $tache = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Feedback $feedback = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Client $client = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Bordereau $bordereau = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?CompteBancaire $compteBancaire = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Piste $piste = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Partenaire $partenaire = null;

    #[ORM\ManyToOne(inversedBy: 'preuves')]
    private ?Paiement $paiement = null;

    // Preuve d'un signalement de paiement de prime (déclaratif, hors trésorerie).
    #[ORM\ManyToOne(inversedBy: 'preuves')]
    private ?PaiementPrime $paiementPrime = null;

    // Preuve d'un reversement de rétrocommission à un agent interne (bordereau, reçu).
    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?ReversementRetroAgent $reversementRetroAgent = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Fournisseur $fournisseur = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Assureur $assureur = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?AutoriteFiscale $autoriteFiscale = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Charge $charge = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?ChargeCourtier $chargeCourtier = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Chargement $chargement = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?ChargementPourPrime $chargementPourPrime = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?ConditionPartage $conditionPartage = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Contact $contact = null;

    // Justificatif d'une demande de congé (certificat médical, acte…). La relation
    // suffit : DocumentFichier::parentsPossibles() la découvre par les métadonnées
    // Doctrine, et les actions « Attacher des pièces » / « Voir les documents »
    // apparaissent d'elles-mêmes sur la rubrique.
    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?DemandeConge $demandeConge = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Depense $depense = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?DepenseCourtier $depenseCourtier = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Entreprise $entrepriseRattachee = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Evaluation $evaluation = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Groupe $groupe = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Invite $inviteRattache = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?ModelePieceSinistre $modelePieceSinistre = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Monnaie $monnaie = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Note $note = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?NotificationSinistre $notificationSinistre = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Objectif $objectif = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Operation $operation = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Portefeuille $portefeuille = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?ReglementTaxe $reglementTaxe = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?RevenuPourCourtier $revenuPourCourtier = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Risque $risque = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Taxe $taxe = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?TaxeVente $taxeVente = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Tranche $tranche = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?TypeRevenu $typeRevenu = null;

    #[Groups(['list:read'])]
    public ?string $parent_string;

    #[Groups(['list:read'])]
    public ?string $classeur_string;

    #[Groups(['list:read'])]
    public ?string $ageDocument;

    #[Groups(['list:read'])]
    public ?string $typeFichier;

    #[Groups(['list:read'])]
    public ?string $tailleFichier;

    public function setFichier(?File $fichier = null): void
    {
        $this->fichier = $fichier;
        if (null !== $fichier) {
            // Il faut mettre à jour updatedAt pour que le bundle sache qu'il y a eu un changement
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getFichier(): ?File
    {
        return $this->fichier;
    }

    public function setNomFichierStocke(?string $nomFichierStocke): void
    {
        $this->nomFichierStocke = $nomFichierStocke;
    }

    public function getNomFichierStocke(): ?string
    {
        return $this->nomFichierStocke;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getClasseur(): ?Classeur
    {
        return $this->classeur;
    }

    public function setClasseur(?Classeur $classeur): static
    {
        $this->classeur = $classeur;

        return $this;
    }

    public function getPieceSinistre(): ?PieceSinistre
    {
        return $this->pieceSinistre;
    }

    public function setPieceSinistre(?PieceSinistre $pieceSinistre): static
    {
        $this->pieceSinistre = $pieceSinistre;

        return $this;
    }

    public function getOffreIndemnisationSinistre(): ?OffreIndemnisationSinistre
    {
        return $this->offreIndemnisationSinistre;
    }

    public function setOffreIndemnisationSinistre(?OffreIndemnisationSinistre $offreIndemnisationSinistre): static
    {
        $this->offreIndemnisationSinistre = $offreIndemnisationSinistre;

        return $this;
    }

    // public function getPaiement(): ?Paiement
    // {
    //     return $this->paiement;
    // }

    // public function setPaiement(?Paiement $paiement): static
    // {
    //     $this->paiement = $paiement;

    //     return $this;
    // }

    public function getCotation(): ?Cotation
    {
        return $this->cotation;
    }

    public function setCotation(?Cotation $cotation): static
    {
        $this->cotation = $cotation;

        return $this;
    }

    public function getAvenant(): ?Avenant
    {
        return $this->avenant;
    }

    public function setAvenant(?Avenant $avenant): static
    {
        $this->avenant = $avenant;

        return $this;
    }

    public function getTache(): ?Tache
    {
        return $this->tache;
    }

    public function setTache(?Tache $tache): static
    {
        $this->tache = $tache;

        return $this;
    }

    public function getFeedback(): ?Feedback
    {
        return $this->feedback;
    }

    public function setFeedback(?Feedback $feedback): static
    {
        $this->feedback = $feedback;

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getBordereau(): ?Bordereau
    {
        return $this->bordereau;
    }

    public function setBordereau(?Bordereau $bordereau): static
    {
        $this->bordereau = $bordereau;

        return $this;
    }

    public function getCompteBancaire(): ?CompteBancaire
    {
        return $this->compteBancaire;
    }

    public function setCompteBancaire(?CompteBancaire $compteBancaire): static
    {
        $this->compteBancaire = $compteBancaire;

        return $this;
    }

    public function getPiste(): ?Piste
    {
        return $this->piste;
    }

    public function setPiste(?Piste $piste): static
    {
        $this->piste = $piste;

        return $this;
    }

    public function getPartenaire(): ?Partenaire
    {
        return $this->partenaire;
    }

    public function setPartenaire(?Partenaire $partenaire): static
    {
        $this->partenaire = $partenaire;

        return $this;
    }

    public function getPaiement(): ?Paiement
    {
        return $this->paiement;
    }

    public function setPaiement(?Paiement $paiement): static
    {
        $this->paiement = $paiement;

        return $this;
    }

    public function getPaiementPrime(): ?PaiementPrime
    {
        return $this->paiementPrime;
    }

    public function setPaiementPrime(?PaiementPrime $paiementPrime): static
    {
        $this->paiementPrime = $paiementPrime;

        return $this;
    }

    public function getReversementRetroAgent(): ?ReversementRetroAgent
    {
        return $this->reversementRetroAgent;
    }

    public function setReversementRetroAgent(?ReversementRetroAgent $reversementRetroAgent): static
    {
        $this->reversementRetroAgent = $reversementRetroAgent;

        return $this;
    }

    public function getFournisseur(): ?Fournisseur
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?Fournisseur $fournisseur): static
    {
        $this->fournisseur = $fournisseur;

        return $this;
    }

    public function getAssureur(): ?Assureur
    {
        return $this->assureur;
    }

    public function setAssureur(?Assureur $assureur): static
    {
        $this->assureur = $assureur;

        return $this;
    }

    public function getAutoriteFiscale(): ?AutoriteFiscale
    {
        return $this->autoriteFiscale;
    }

    public function setAutoriteFiscale(?AutoriteFiscale $autoriteFiscale): static
    {
        $this->autoriteFiscale = $autoriteFiscale;

        return $this;
    }

    public function getCharge(): ?Charge
    {
        return $this->charge;
    }

    public function setCharge(?Charge $charge): static
    {
        $this->charge = $charge;

        return $this;
    }

    public function getChargeCourtier(): ?ChargeCourtier
    {
        return $this->chargeCourtier;
    }

    public function setChargeCourtier(?ChargeCourtier $chargeCourtier): static
    {
        $this->chargeCourtier = $chargeCourtier;

        return $this;
    }

    public function getChargement(): ?Chargement
    {
        return $this->chargement;
    }

    public function setChargement(?Chargement $chargement): static
    {
        $this->chargement = $chargement;

        return $this;
    }

    public function getChargementPourPrime(): ?ChargementPourPrime
    {
        return $this->chargementPourPrime;
    }

    public function setChargementPourPrime(?ChargementPourPrime $chargementPourPrime): static
    {
        $this->chargementPourPrime = $chargementPourPrime;

        return $this;
    }

    public function getConditionPartage(): ?ConditionPartage
    {
        return $this->conditionPartage;
    }

    public function setConditionPartage(?ConditionPartage $conditionPartage): static
    {
        $this->conditionPartage = $conditionPartage;

        return $this;
    }

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(?Contact $contact): static
    {
        $this->contact = $contact;

        return $this;
    }

    public function getDemandeConge(): ?DemandeConge
    {
        return $this->demandeConge;
    }

    public function setDemandeConge(?DemandeConge $demandeConge): static
    {
        $this->demandeConge = $demandeConge;

        return $this;
    }

    public function getDepense(): ?Depense
    {
        return $this->depense;
    }

    public function setDepense(?Depense $depense): static
    {
        $this->depense = $depense;

        return $this;
    }

    public function getDepenseCourtier(): ?DepenseCourtier
    {
        return $this->depenseCourtier;
    }

    public function setDepenseCourtier(?DepenseCourtier $depenseCourtier): static
    {
        $this->depenseCourtier = $depenseCourtier;

        return $this;
    }

    public function getEntrepriseRattachee(): ?Entreprise
    {
        return $this->entrepriseRattachee;
    }

    public function setEntrepriseRattachee(?Entreprise $entrepriseRattachee): static
    {
        $this->entrepriseRattachee = $entrepriseRattachee;

        return $this;
    }

    public function getEvaluation(): ?Evaluation
    {
        return $this->evaluation;
    }

    public function setEvaluation(?Evaluation $evaluation): static
    {
        $this->evaluation = $evaluation;

        return $this;
    }

    public function getGroupe(): ?Groupe
    {
        return $this->groupe;
    }

    public function setGroupe(?Groupe $groupe): static
    {
        $this->groupe = $groupe;

        return $this;
    }

    public function getInviteRattache(): ?Invite
    {
        return $this->inviteRattache;
    }

    public function setInviteRattache(?Invite $inviteRattache): static
    {
        $this->inviteRattache = $inviteRattache;

        return $this;
    }

    public function getModelePieceSinistre(): ?ModelePieceSinistre
    {
        return $this->modelePieceSinistre;
    }

    public function setModelePieceSinistre(?ModelePieceSinistre $modelePieceSinistre): static
    {
        $this->modelePieceSinistre = $modelePieceSinistre;

        return $this;
    }

    public function getMonnaie(): ?Monnaie
    {
        return $this->monnaie;
    }

    public function setMonnaie(?Monnaie $monnaie): static
    {
        $this->monnaie = $monnaie;

        return $this;
    }

    public function getNote(): ?Note
    {
        return $this->note;
    }

    public function setNote(?Note $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getNotificationSinistre(): ?NotificationSinistre
    {
        return $this->notificationSinistre;
    }

    public function setNotificationSinistre(?NotificationSinistre $notificationSinistre): static
    {
        $this->notificationSinistre = $notificationSinistre;

        return $this;
    }

    public function getObjectif(): ?Objectif
    {
        return $this->objectif;
    }

    public function setObjectif(?Objectif $objectif): static
    {
        $this->objectif = $objectif;

        return $this;
    }

    public function getOperation(): ?Operation
    {
        return $this->operation;
    }

    public function setOperation(?Operation $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    public function getPortefeuille(): ?Portefeuille
    {
        return $this->portefeuille;
    }

    public function setPortefeuille(?Portefeuille $portefeuille): static
    {
        $this->portefeuille = $portefeuille;

        return $this;
    }

    public function getReglementTaxe(): ?ReglementTaxe
    {
        return $this->reglementTaxe;
    }

    public function setReglementTaxe(?ReglementTaxe $reglementTaxe): static
    {
        $this->reglementTaxe = $reglementTaxe;

        return $this;
    }

    public function getRevenuPourCourtier(): ?RevenuPourCourtier
    {
        return $this->revenuPourCourtier;
    }

    public function setRevenuPourCourtier(?RevenuPourCourtier $revenuPourCourtier): static
    {
        $this->revenuPourCourtier = $revenuPourCourtier;

        return $this;
    }

    public function getRisque(): ?Risque
    {
        return $this->risque;
    }

    public function setRisque(?Risque $risque): static
    {
        $this->risque = $risque;

        return $this;
    }

    public function getTaxe(): ?Taxe
    {
        return $this->taxe;
    }

    public function setTaxe(?Taxe $taxe): static
    {
        $this->taxe = $taxe;

        return $this;
    }

    public function getTaxeVente(): ?TaxeVente
    {
        return $this->taxeVente;
    }

    public function setTaxeVente(?TaxeVente $taxeVente): static
    {
        $this->taxeVente = $taxeVente;

        return $this;
    }

    public function getTranche(): ?Tranche
    {
        return $this->tranche;
    }

    public function setTranche(?Tranche $tranche): static
    {
        $this->tranche = $tranche;

        return $this;
    }

    public function getTypeRevenu(): ?TypeRevenu
    {
        return $this->typeRevenu;
    }

    public function setTypeRevenu(?TypeRevenu $typeRevenu): static
    {
        $this->typeRevenu = $typeRevenu;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom ?? 'Nouveau document';
    }
}
