<?php

namespace App\Ai\Oreille;

/**
 * Ce qu'une oreille a entendu. `statut` décide de la suite : COMPLET part à Ket et se
 * facture ; QUOTA et INDISPONIBLE passent la main à l'oreille suivante, puis au
 * navigateur ; ECHEC ne facture rien.
 *
 * Les statuts sont ceux de la voix — mêmes mots, même sens (cf. FournisseurDeVoix).
 */
final class Transcription
{
    public const COMPLET = 'complet';
    public const QUOTA = 'quota';
    public const INDISPONIBLE = 'indisponible';
    public const ECHEC = 'echec';

    private function __construct(
        public readonly string $statut,
        public readonly string $texte,
        public readonly string $fournisseur = '',
    ) {
    }

    public static function entendue(string $texte, string $fournisseur = ''): self
    {
        return new self(self::COMPLET, trim($texte), $fournisseur);
    }

    public static function refus(string $statut): self
    {
        return new self($statut, '');
    }

    public function aDuTexte(): bool
    {
        return $this->statut === self::COMPLET && $this->texte !== '';
    }
}
