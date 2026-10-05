<?php

namespace App\Services\Canvas\Autocomplete;

use App\Entity\AutoriteFiscale;
use App\Entity\Note;
use App\Entity\RevenuPourCourtier;
use App\Entity\Taxe;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Le libellé d'un RevenuPourCourtier dans un champ d'autocomplétion.
 *
 * ── CINQ COLONNES DONT UNE SEULE SERVAIT ────────────────────────────────────────
 * L'ancien rendu affichait cinq colonnes de trois chiffres — commission, détail de
 * commission, rétro, taxe courtier, taxe assureur — et SURLIGNAIT celle qui concernait le
 * destinataire de la note en cours. Quinze nombres pour en lire trois : le surlignage
 * disait déjà que les douze autres ne servaient pas.
 *
 * Il devient donc une SÉLECTION. La détection du destinataire est conservée au mot près —
 * c'est elle qui portait l'intelligence ; seul son effet change : elle ne décore plus,
 * elle choisit.
 *
 * ── CE QUI EST PERDU, ET ASSUMÉ ─────────────────────────────────────────────────
 * Pour un destinataire assureur ou client, l'ancien rendu surlignait DEUX colonnes : la
 * commission, et son détail (HT, pure, réserve). Trois chiffres n'en gardent qu'une. Le
 * détail reste lisible sur la fiche du revenu, où l'on vient pour comprendre — alors
 * qu'ici l'on vient pour choisir.
 *
 * ── « RÉTRO (N/A) » ─────────────────────────────────────────────────────────────
 * Le libellé portait le nom du partenaire : `$revenu->partenaireNom ?? $revenu->partenaire_nom`.
 * Aucune des deux propriétés n'est jamais hydratée — la stratégie ne les produit pas — et
 * le libellé affichait donc « Rétro (N/A) » en toutes circonstances. Le nouveau libellé
 * n'a plus besoin de ce nom : le défaut disparaît sans correctif.
 */
class RevenuPourCourtierAutocompleteCanvasProvider
{
    /** À qui la note est adressée, et donc quels chiffres aident à choisir. */
    private const POUR_COMMISSION = 'commission';
    private const POUR_RETRO = 'retro';
    private const POUR_TAXE_COURTIER = 'taxe_courtier';
    private const POUR_TAXE_ASSUREUR = 'taxe_assureur';

    /**
     * La réponse mémorisée, et la CLÉ qui dit pour quel contexte elle vaut.
     *
     * Le service est partagé et les tests réutilisent le noyau : mémoriser sans clé ferait
     * fuir le destinataire d'une note dans la suivante.
     */
    private ?string $destinationMemorisee = null;
    private ?string $cleDeLaMemoire = null;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private RenduOptionAutocomplete $rendu,
        private RequestStack $requestStack,
        private EntityManagerInterface $em,
    ) {
    }

    public function getChoiceLabel(RevenuPourCourtier $revenu, ?Note $parentNote = null): string
    {
        $this->canvasBuilder->loadAllCalculatedValues($revenu);

        $cotation = $revenu->getCotation();
        $avenant = ($cotation && !$cotation->getAvenants()->isEmpty())
            ? $cotation->getAvenants()->first()
            : null;

        return $this->rendu->libelle(
            titre: $revenu->getNom(),
            contact: [
                $avenant?->getReferencePolice(),
                $cotation?->getPiste()?->getClient()?->getNom(),
            ],
            chiffres: $this->chiffresPour($this->destination($parentNote), $revenu),
        );
    }

    /**
     * Les trois chiffres qui répondent à la question posée.
     *
     * @return list<Chiffre>
     */
    private function chiffresPour(string $destination, RevenuPourCourtier $revenu): array
    {
        return match ($destination) {
            self::POUR_RETRO => [
                Chiffre::montant('Rétro due', $revenu->retroCommission ?? null),
                Chiffre::montant('Reversée', $revenu->retroCommissionReversee ?? null),
                Chiffre::solde('Reste dû', $revenu->retroCommissionSolde ?? null),
            ],
            self::POUR_TAXE_COURTIER => [
                Chiffre::montant('Taxe due', $revenu->taxeCourtierMontant ?? null),
                Chiffre::montant('Payée', $revenu->taxeCourtierPayee ?? null),
                Chiffre::solde('Reste dû', $revenu->taxeCourtierSolde ?? null),
            ],
            self::POUR_TAXE_ASSUREUR => [
                Chiffre::montant('T. assureur', $revenu->taxeAssureurMontant ?? null),
                Chiffre::montant('Payée', $revenu->taxeAssureurPayee ?? null),
                Chiffre::solde('Reste dû', $revenu->taxeAssureurSolde ?? null),
            ],
            // La commission est aussi le défaut : hors contexte de facturation, c'est elle
            // qu'on vient chercher dans ce champ.
            default => [
                Chiffre::montant('Comm. TTC', $revenu->montantCalculeTTC ?? null),
                Chiffre::montant('Encaissée', $revenu->montant_paye ?? null),
                Chiffre::solde('Comm. due', $revenu->solde_restant_du ?? null),
            ],
        };
    }

    /**
     * À QUI CETTE NOTE EST-ELLE ADRESSÉE ?
     *
     * Deux sources, dans cet ordre, exactement comme avant : le contexte LIVE que le
     * contrôleur Stimulus pose en paramètres de requête pendant la saisie, puis la note
     * déjà enregistrée quand on rouvre une ligne existante. La première seule ne suffirait
     * pas en mode édition ; la seconde seule ne suivrait pas la saisie en cours.
     */
    private function destination(?Note $parentNote): string
    {
        $requete = $this->requestStack->getCurrentRequest();
        $liveAssureur = $requete?->query->get('live_assureur_id');
        $liveClient = $requete?->query->get('live_client_id');
        $livePartenaire = $requete?->query->get('live_partenaire_id');
        $liveAutorite = $requete?->query->get('live_autorite_id');
        $adresseeA = $parentNote?->getAddressedTo();

        $cle = implode('|', [
            $liveAssureur, $liveClient, $livePartenaire, $liveAutorite,
            $parentNote?->getId(), $adresseeA,
        ]);
        if ($this->cleDeLaMemoire === $cle && $this->destinationMemorisee !== null) {
            return $this->destinationMemorisee;
        }
        $this->cleDeLaMemoire = $cle;

        if ($liveAssureur || $liveClient
            || ($parentNote && \in_array($adresseeA, [Note::TO_ASSUREUR, Note::TO_CLIENT], true))) {
            return $this->destinationMemorisee = self::POUR_COMMISSION;
        }

        if ($livePartenaire || ($parentNote && $adresseeA === Note::TO_PARTENAIRE)) {
            return $this->destinationMemorisee = self::POUR_RETRO;
        }

        if ($liveAutorite || ($parentNote && $adresseeA === Note::TO_AUTORITE_FISCALE)) {
            return $this->destinationMemorisee = $this->selonLeRedevable(
                $liveAutorite ?? $parentNote?->getAutoritefiscale()?->getId(),
            );
        }

        return $this->destinationMemorisee = self::POUR_COMMISSION;
    }

    /**
     * Une taxe est due par le courtier ou par l'assureur, et ce n'est pas le même chiffre.
     *
     * C'est la seule branche qui interroge la base — d'où la mémoïsation : ce champ rend
     * dix options par frappe, et la réponse est la même pour les dix.
     */
    private function selonLeRedevable(mixed $idAutorite): string
    {
        if (!$idAutorite) {
            return self::POUR_COMMISSION;
        }

        $autorite = $this->em->getRepository(AutoriteFiscale::class)->find($idAutorite);
        $redevable = $autorite?->getTaxe()?->getRedevable();

        return match ($redevable) {
            Taxe::REDEVABLE_COURTIER => self::POUR_TAXE_COURTIER,
            Taxe::REDEVABLE_ASSUREUR => self::POUR_TAXE_ASSUREUR,
            default => self::POUR_COMMISSION,
        };
    }
}
