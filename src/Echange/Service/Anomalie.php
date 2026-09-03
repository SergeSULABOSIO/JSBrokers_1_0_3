<?php

namespace App\Echange\Service;

/**
 * UNE ANOMALIE DU CONTRÔLE, située.
 *
 * Trois choses la rendent utile, et l'absence d'une seule la rend inexploitable :
 * OÙ (feuille, ligne, colonne), QUOI (un code stable, pour compter et filtrer) et
 * POURQUOI en français. Un rapport qui dit « erreur de format » sans dire où oblige à
 * relire le fichier entier.
 */
final class Anomalie
{
    /** Bloque la confirmation : tant qu'il en reste une, rien ne sera écrit. */
    public const ERREUR = 'ERREUR';

    /** Signale sans bloquer : l'utilisateur décide en connaissance de cause. */
    public const AVERTISSEMENT = 'AVERTISSEMENT';

    // Codes stables — ils servent au décompte et aux tests, jamais à l'affichage.
    public const FICHIER_ILLISIBLE     = 'fichier_illisible';
    public const MANIFESTE_ABSENT      = 'manifeste_absent';
    public const AUTRE_CABINET         = 'autre_cabinet';
    public const STRUCTURE_ALTEREE     = 'structure_alteree';
    public const FEUILLE_INCONNUE      = 'feuille_inconnue';
    public const PLAFOND_DEPASSE       = 'plafond_depasse';
    public const UID_INVALIDE          = 'uid_invalide';
    public const ACTION_INVALIDE       = 'action_invalide';
    public const LIGNE_INTROUVABLE     = 'ligne_introuvable';
    public const RENVOI_IRRESOLU       = 'renvoi_irresolu';
    public const RENVOI_AMBIGU         = 'renvoi_ambigu';
    public const VALEUR_INVALIDE       = 'valeur_invalide';
    public const CHAMP_OBLIGATOIRE     = 'champ_obligatoire';
    public const DROIT_INSUFFISANT     = 'droit_insuffisant';
    public const CONFLIT_MODIFICATION  = 'conflit_modification';
    public const SUPPRESSION_REFUSEE   = 'suppression_refusee';
    public const SUPPRESSION_BLOQUEE   = 'suppression_bloquee';
    public const COLONNE_IGNOREE       = 'colonne_ignoree';

    public function __construct(
        public readonly string $gravite,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $feuille = null,
        public readonly ?int $ligne = null,
        public readonly ?string $colonne = null,
    ) {
    }

    public static function erreur(string $code, string $message, ?string $feuille = null, ?int $ligne = null, ?string $colonne = null): self
    {
        return new self(self::ERREUR, $code, $message, $feuille, $ligne, $colonne);
    }

    public static function avertissement(string $code, string $message, ?string $feuille = null, ?int $ligne = null, ?string $colonne = null): self
    {
        return new self(self::AVERTISSEMENT, $code, $message, $feuille, $ligne, $colonne);
    }

    public function bloque(): bool
    {
        return $this->gravite === self::ERREUR;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'gravite' => $this->gravite,
            'code'    => $this->code,
            'message' => $this->message,
            'feuille' => $this->feuille,
            'ligne'   => $this->ligne,
            'colonne' => $this->colonne,
        ];
    }

    /** Adresse lisible : « Clients, ligne 12, colonne D ». */
    public function ou(): string
    {
        $morceaux = array_filter([
            $this->feuille,
            $this->ligne !== null ? 'ligne ' . $this->ligne : null,
            $this->colonne !== null ? 'colonne ' . $this->colonne : null,
        ]);

        return $morceaux === [] ? '—' : implode(', ', $morceaux);
    }
}
