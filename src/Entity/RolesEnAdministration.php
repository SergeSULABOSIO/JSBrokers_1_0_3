<?php

namespace App\Entity;

use App\Repository\RolesEnAdministrationRepository;
use Doctrine\DBAL\Types\Types;
use App\Entity\Traits\AuditableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: RolesEnAdministrationRepository::class)]
#[ORM\HasLifecycleCallbacks]
class RolesEnAdministration implements OwnerAwareInterface
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

    #[ORM\Column(type: Types::ARRAY)]
    #[Groups(['list:read'])]
    private array $accessDocument = [];

    #[ORM\Column(type: Types::ARRAY)]
    #[Groups(['list:read'])]
    private array $accessClasseur = [];

    #[ORM\Column(type: Types::ARRAY)]
    #[Groups(['list:read'])]
    private array $accessInvite = [];

    /**
     * Accès au module Assistant IA (pseudo-entité « AssistantIa » de la carte
     * de permissions — chat et conversations du workspace). Lecture suffit.
     */
    #[ORM\Column(type: Types::ARRAY)]
    #[Groups(['list:read'])]
    private array $accessAssistantIa = [];

    /**
     * Accès à la rubrique « Congés » (demandes de congé du cabinet).
     *
     * LE NIVEAU DIT LE RÔLE, et c'est tout ce qu'il y a à paramétrer :
     *  - Lecture      : consulter (ses propres demandes, cf. CongeVisibiliteScope) ;
     *  - Écriture     : poser une demande ;
     *  - Modification : DÉCIDER — approuver, refuser, annuler une absence commencée.
     *                   C'est ce niveau, et lui seul, qui fait un VALIDEUR, et c'est
     *                   aussi lui qui ouvre la vue sur les demandes de tout le cabinet ;
     *  - Suppression  : supprimer une demande.
     *
     * Aucune liste de valideurs n'est stockée ailleurs : elle se déduit de ce champ, et
     * se règle donc dans le gestionnaire de rôles comme n'importe quel autre accès.
     */
    #[ORM\Column(type: Types::ARRAY)]
    #[Groups(['list:read'])]
    private array $accessConge = [];

    /**
     * Accès au PARAMÉTRAGE des congés : types d'absence et jours fériés. Distinct du
     * précédent — on peut confier la validation des demandes sans ouvrir le réglage des
     * droits à congé, qui engage le cabinet bien au-delà d'un dossier.
     */
    #[ORM\Column(type: Types::ARRAY)]
    #[Groups(['list:read'])]
    private array $accessCongeParametre = [];

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

    public function getAccessDocument(): array
    {
        return $this->accessDocument;
    }

    public function setAccessDocument(array $accessDocument): static
    {
        $this->accessDocument = $accessDocument;

        return $this;
    }

    public function getAccessClasseur(): array
    {
        return $this->accessClasseur;
    }

    public function setAccessClasseur(array $accessClasseur): static
    {
        $this->accessClasseur = $accessClasseur;

        return $this;
    }

    public function getAccessInvite(): array
    {
        return $this->accessInvite;
    }

    public function setAccessInvite(array $accessInvite): static
    {
        $this->accessInvite = $accessInvite;

        return $this;
    }

    public function getAccessAssistantIa(): array
    {
        return $this->accessAssistantIa;
    }

    public function setAccessAssistantIa(array $accessAssistantIa): static
    {
        $this->accessAssistantIa = $accessAssistantIa;

        return $this;
    }

    public function getAccessConge(): array
    {
        return $this->accessConge;
    }

    public function setAccessConge(array $accessConge): static
    {
        $this->accessConge = $accessConge;

        return $this;
    }

    public function getAccessCongeParametre(): array
    {
        return $this->accessCongeParametre;
    }

    public function setAccessCongeParametre(array $accessCongeParametre): static
    {
        $this->accessCongeParametre = $accessCongeParametre;

        return $this;
    }
}
