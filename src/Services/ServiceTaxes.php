<?php

namespace App\Services;

use App\Entity\AutoriteFiscale;
use App\Entity\Entreprise;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

class ServiceTaxes implements ResetInterface
{
    /**
     * Barème par identifiant d'entreprise, le temps d'une requête.
     *
     * @var array<int, Taxe[]>
     */
    private array $taxesParEntreprise = [];

    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
    ) {}

    public function reset(): void
    {
        $this->taxesParEntreprise = [];
    }

    public function getUtilisateurConnecte(): ?Utilisateur
    {
        return $this->security->getUser();
    }

    /**
     * Taxes SCOPÉES à une entreprise (colonne entreprise_id). findAll() renvoyait
     * celles de TOUTES les entreprises → getMontantTaxe les sommait toutes (ex. 3
     * entreprises avec une TVA 16% → taxe appliquée ×3, et pire à chaque nouvelle
     * entreprise). L'entreprise cible est, dans l'ordre : celle passée explicitement
     * (contextes SANS utilisateur connecté, ex. écritures comptables/suivi fiscal),
     * sinon l'entreprise active de l'utilisateur (contexte workspace HTTP). Aucune →
     * liste vide (jamais toutes les entreprises).
     */
    public function getTaxes(?Entreprise $entreprise = null)
    {
        $entreprise ??= $this->getUtilisateurConnecte()?->getConnectedTo();
        if ($entreprise === null) {
            return [];
        }

        // LE BARÈME NE CHANGE PAS PENDANT UNE REQUÊTE. Il était pourtant relu à chaque
        // calcul de commission — quarante lectures de `taxe` pour dix lignes de rubrique,
        // toutes identiques. Le cache est revalidé contre l'identity map : après un
        // em->clear(), les taxes gardées seraient détachées et leurs relations mortes.
        $cle = (int) $entreprise->getId();
        $connues = $this->taxesParEntreprise[$cle] ?? null;
        if ($connues !== null && ($connues === [] || $this->entityManager->contains($connues[0]))) {
            return $connues;
        }

        return $this->taxesParEntreprise[$cle] = $this->entityManager
            ->getRepository(Taxe::class)
            ->findBy(['entreprise' => $entreprise]);
    }

    public function getTaxesPayableParCourtier(?Entreprise $entreprise = null)
    {
        return array_values(array_filter(
            $this->getTaxes($entreprise),
            static fn (Taxe $tx) => $tx->getRedevable() == Taxe::REDEVABLE_COURTIER,
        ));
    }

    public function getTaxesPayableParAssureur(?Entreprise $entreprise = null)
    {
        return array_values(array_filter(
            $this->getTaxes($entreprise),
            static fn (Taxe $tx) => $tx->getRedevable() == Taxe::REDEVABLE_ASSUREUR,
        ));
    }

    /**
     * NOUVEAU : Récupère l'objet Taxe applicable.
     *
     * @param boolean $isTaxeAssureur
     * @return Taxe|null
     */
    public function getTaxeApplicable(bool $isIARD, bool $isTaxeAssureur, ?Entreprise $entreprise = null): ?Taxe
    {
        $taxes = $isTaxeAssureur ? $this->getTaxesPayableParAssureur($entreprise) : $this->getTaxesPayableParCourtier($entreprise);
        // Dans la logique actuelle, il n'y a qu'une seule taxe par redevable.
        // On retourne donc la première trouvée.
        return count($taxes) > 0 ? $taxes[0] : null;
    }


    public function getMontantTaxe($montantNet, bool $tauxIARD, bool $taxeAssureur, ?Entreprise $entreprise = null)
    {
        $gross = 0;
        if ($taxeAssureur == true) {
            foreach ($this->getTaxesPayableParAssureur($entreprise) as $taxeAss) {
                // Conversion pourcentage→montant centralisée dans le VO (fini le ÷100).
                $gross += $taxeAss->tauxPourcentage($tauxIARD)->appliquerA((float) $montantNet);
            }
        } else {
            foreach ($this->getTaxesPayableParCourtier($entreprise) as $taxeCou) {
                $gross += $taxeCou->tauxPourcentage($tauxIARD)->appliquerA((float) $montantNet);
            }
        }

        return $gross;
    }


    /**
     * LA TAXE SUR UNE COMMISSION — ZÉRO QUAND L'AFFAIRE EN EST EXONÉRÉE.
     *
     * ⚠ PASSAGE OBLIGÉ DE TOUT CALCUL DE TAXE SUR COMMISSION. `getMontantTaxe()` ignore
     * le contexte : il applique le barème, point. Or deux réglages excluent la taxe, et
     * ils étaient lus NULLE PART — `Client::$exonere` et `Risque::$imposable` existaient
     * dans les formulaires, dans les fiches, et dans aucun calcul. Le cabinet cochait
     * « exonere » et l'application facturait ses seize pour cent quand meme.
     *
     * ⚠ LES DEUX TAXES TOMBENT ENSEMBLE. Une affaire exonérée ne porte aucune TVA : ni
     * l'assureur n'en précompte, ni le cabinet n'en reverse. N'en neutraliser qu'une
     * laisserait le courtier provisionner une dette fiscale sur une commission qui n'a
     * jamais été taxée.
     *
     * ⚠ ET C'EST ICI, PAS CHEZ L'APPELANT. Une vingtaine de sites calculent cette taxe —
     * l'écran, les indicateurs, les écritures comptables OHADA, le suivi fiscal. Une garde
     * recopiée vingt fois, c'est dix-neuf occasions d'en oublier une, et une comptabilité
     * qui ne dirait pas la même chose que l'écran.
     */
    public function getMontantTaxeSurCommission(
        $montantNet,
        bool $tauxIARD,
        bool $taxeAssureur,
        ?Cotation $cotation,
        ?Entreprise $entreprise = null,
    ): float {
        if ($this->commissionExonereePour($cotation)) {
            return 0.0;
        }

        return (float) $this->getMontantTaxe($montantNet, $tauxIARD, $taxeAssureur, $entreprise);
    }

    /** La commission de cette affaire échappe-t-elle à la taxe ? */
    public function commissionExonereePour(?Cotation $cotation): bool
    {
        $piste = $cotation?->getPiste();

        return $this->commissionExoneree($piste?->getClient(), $piste?->getRisque());
    }

    /**
     * LA RÈGLE, ET ELLE N'A QU'UN SEUL ENDROIT.
     *
     * Deux reglages l'emportent, et il suffit de l'un : un CLIENT exonéré de taxes, ou un
     * RISQUE déclaré non imposable.
     *
     * ⚠ SUR LE RISQUE, C'EST UN ÉLARGISSEMENT ASSUMÉ. `Risque::$imposable` décrivait la
     * taxation de la PRIME — le semis officiel l'affirme (« en assurance, la prime est
     * taxée ») —, et le projet sépare soigneusement les deux mondes : taxe SUR LA PRIME
     * (un chargement saisi) et taxe SUR LA COMMISSION (calculée ici). Le drapeau sert
     * désormais aux deux, par décision explicite : un risque que le fisc n'impose pas ne
     * fait pas naître de TVA sur la rémunération qu'il génère.
     *
     * Ne le « corrigez » donc pas en le croyant egare : il est la ou on l'a voulu.
     */
    public function commissionExoneree(?Client $client, ?Risque $risque): bool
    {
        return $client?->isExonere() === true || $risque?->isImposable() === false;
    }

    public function getMontantTaxeAutorite($montantNet, ?bool $tauxIARD, ?AutoriteFiscale $autoriteFiscale, ?Entreprise $entreprise = null)
    {
        $montantTaxe = 0;
        foreach ($this->getTaxes($entreprise) as $taxe) {
            if ($autoriteFiscale->getTaxe() == $taxe) {
                // Le taux est stocké en POURCENTAGE ENTIER (16 = 16 %) : la conversion
                // passe par le VO Pourcentage (base × fraction), plus jamais × taux brut
                // (le montant sortait sinon ×100 trop grand : 16 au lieu de 0,16).
                $montantTaxe += $taxe->tauxPourcentage((bool) $tauxIARD)->appliquerA((float) $montantNet);
            }
        }
        return $montantTaxe;
    }
}
