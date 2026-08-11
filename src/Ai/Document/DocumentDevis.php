<?php

namespace App\Ai\Document;

/**
 * Le CHIFFRAGE d'un document, ventilé — jamais un simple entier.
 *
 * POURQUOI LA VENTILATION. L'utilisateur doit pouvoir vérifier le prix qu'on lui
 * annonce, pas seulement le subir : « 7 812 caractères → 4 pages de 2 500 →
 * (60 + 4 × 30) = 180 → Word ×1,5 → 270 tokens ». Chaque terme de cette phrase est
 * un champ d'ici, si bien que la barre de décision la compose sans recalculer quoi
 * que ce soit — et ne peut donc pas afficher un total qui contredit son détail.
 *
 * Le même objet alimente le budget du plan, le pré-vol de solvabilité, le métrage
 * et le journal de consommation : un seul calcul, quatre lecteurs.
 */
final readonly class DocumentDevis
{
    public function __construct(
        public DocumentFormat $format,
        public int $caracteres,
        public int $pages,
        public int $caracteresParPage,
        public int $base,
        public int $parPage,
        public float $multiplicateur,
        /** Sous-total avant application du multiplicateur de format. */
        public int $coutAvantFormat,
        public int $cout,
    ) {
    }

    /**
     * Forme sérialisable — stockée dans la meta du message, relue par l'endpoint
     * de production et par la restauration après rechargement de page.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format'            => $this->format->value,
            'formatLibelle'     => $this->format->libelle(),
            'formatBadge'       => $this->format->badge(),
            'caracteres'        => $this->caracteres,
            'pages'             => $this->pages,
            'caracteresParPage' => $this->caracteresParPage,
            'base'              => $this->base,
            'parPage'           => $this->parPage,
            'multiplicateur'    => $this->multiplicateur,
            'coutAvantFormat'   => $this->coutAvantFormat,
            'cout'              => $this->cout,
        ];
    }

    /**
     * Phrase de contrôle, telle que Ket doit la restituer et que la barre de
     * décision l'affiche. Rendue ICI pour qu'il n'existe qu'une façon de dire ce
     * prix — le modèle n'a plus qu'à la recopier.
     */
    public function explication(): string
    {
        return sprintf(
            '%d caractères → %d page%s de %d → (%d + %d × %d) = %d → %s ×%s → %d tokens',
            $this->caracteres,
            $this->pages,
            $this->pages > 1 ? 's' : '',
            $this->caracteresParPage,
            $this->base,
            $this->pages,
            $this->parPage,
            $this->coutAvantFormat,
            $this->format->libelle(),
            rtrim(rtrim(number_format($this->multiplicateur, 2, ',', ''), '0'), ','),
            $this->cout,
        );
    }
}
