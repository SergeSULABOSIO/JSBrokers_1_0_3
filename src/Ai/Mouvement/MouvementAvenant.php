<?php

namespace App\Ai\Mouvement;

use App\Entity\Piste;
use App\Form\PisteType;

/**
 * Les quatre MOUVEMENTS qui font évoluer une police EXISTANTE, tels que le
 * métier les nomme et tels que le modèle les porte déjà.
 *
 * Le modèle n'avait rien à inventer : un mouvement est une PISTE DÉRIVÉE typée
 * par Piste::AVENANT_*, reliée à la police de base par le double lien
 * Piste::avenantDeBase ⇄ Avenant::pisteDeRenouvellement. C'est ce lien que
 * Constante::Avenant_getRenewalStatus() lit pour afficher « Renouvelé »,
 * « Prorogé » ou « Résilié », et que DashboardDataProvider::getAllRenouvellements()
 * lit pour sortir la police de la vigie des échéances.
 *
 * Cet enum est la SOURCE UNIQUE de ce que chaque mouvement implique — il
 * répond aux quatre questions dont dépend tout le reste :
 *  - quel type de piste dérivée ? (typeAvenant)
 *  - l'utilisateur doit-il fournir une date ? (exigeDate)
 *  - l'affaire poursuit-elle son cycle de vie ? (poursuitLeCycle → tâche de
 *    suivi du paiement, car c'est le paiement de la prime qui rend la
 *    commission exigible)
 *  - la police de base meurt-elle ? (annuleLaPolice → renewalStatus CANCELLED)
 *
 * Les libellés ne sont pas redéclarés ici : ils viennent de
 * PisteType::TYPE_AVENANT_LABELS, déjà source unique du champ de formulaire et
 * du préfixage Stimulus « piste-name-sync ».
 */
enum MouvementAvenant: string
{
    case Renouvellement = 'renouvellement';
    case Prorogation    = 'prorogation';
    case Annulation     = 'annulation';
    case Resiliation    = 'resiliation';

    /** Type de la piste dérivée à créer (Piste::AVENANT_*). */
    public function typeAvenant(): int
    {
        return match ($this) {
            self::Renouvellement => Piste::AVENANT_RENOUVELLEMENT,
            self::Prorogation    => Piste::AVENANT_PROROGATION,
            self::Annulation     => Piste::AVENANT_ANNULATION,
            self::Resiliation    => Piste::AVENANT_RESILIATION,
        };
    }

    /** Libellé métier (« Renouvellement », « Prorogation »…), source unique PisteType. */
    public function libelle(): string
    {
        return PisteType::TYPE_AVENANT_LABELS[$this->typeAvenant()];
    }

    /**
     * L'utilisateur DOIT-il fournir une date (ou une durée) ? Faux pour le seul
     * renouvellement : tout y est puisé dans la police de base, il n'a rien à
     * fournir. Pour les trois autres, la date d'effet est la SEULE information
     * que l'assistant est autorisé à demander.
     */
    public function exigeDate(): bool
    {
        return $this !== self::Renouvellement;
    }

    /**
     * L'affaire poursuit-elle son cycle de vie (une prime va être due) ? Si oui,
     * le plan ajoute une tâche de suivi du paiement de la prime auprès de
     * l'assuré — condition de l'exigibilité de la commission du courtier.
     */
    public function poursuitLeCycle(): bool
    {
        return $this === self::Renouvellement || $this === self::Prorogation;
    }

    /**
     * Le mouvement met-il fin à la police de base ? Si oui, le plan pose
     * renewalStatus = CANCELLED dessus, sans quoi elle resterait comptée parmi
     * les « polices actives » et dans les primes totales du tableau de bord
     * (DashboardDataProvider::getPoliciesActives / getAvenantsActifsHydrates,
     * qui filtrent sur cette colonne stockée).
     */
    public function annuleLaPolice(): bool
    {
        return $this === self::Annulation || $this === self::Resiliation;
    }

    /** Le mouvement porte-t-il une prime (chargements, échéancier, revenus) ? */
    public function porteUnePrime(): bool
    {
        return !$this->annuleLaPolice();
    }

    /** @return string[] valeurs acceptées (enum du schéma d'outil). */
    public static function valeurs(): array
    {
        return array_map(static fn (self $m) => $m->value, self::cases());
    }

    /** Résolution tolérante depuis un argument du LLM ; null si non reconnu. */
    public static function depuis(string $valeur): ?self
    {
        $normalise = mb_strtolower(trim($valeur));
        // Tolérance d'accent : « résiliation » est la graphie naturelle, la
        // valeur technique est sans accent.
        $normalise = strtr($normalise, ['é' => 'e', 'è' => 'e', 'ê' => 'e']);

        return self::tryFrom($normalise);
    }
}
