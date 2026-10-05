<?php

namespace App\Services\Canvas\Autocomplete;

/**
 * UN CHIFFRE QU'ON PROPOSE POUR AIDER À CHOISIR.
 *
 * ── POURQUOI UN OBJET PLUTÔT QU'UN TABLEAU ──────────────────────────────────────
 * Parce que les constructeurs nommés disent au point d'appel ce que le chiffre EST, et
 * que l'appelant n'a donc rien d'autre à savoir :
 *
 *     Chiffre::montant('Prime', $assureur->primeTotale ?? null)
 *     Chiffre::solde('Comm. due', $assureur->solde_restant_du ?? null)
 *
 * Un tableau `['libelle' => …, 'genre' => …]` aurait laissé chaque provider libre
 * d'inventer ses clés — c'est exactement la dispersion que ce lot referme.
 *
 * ── LA DISTINCTION QUI COMPTE : ZÉRO N'EST PAS « RIEN » ─────────────────────────
 * `null` signifie « non calculé » ; `0.0` signifie « calculé, et il vaut zéro ». Les
 * confondre ferait disparaître un solde nul — or un solde à zéro est précisément
 * l'information qu'un utilisateur cherche. On n'affiche donc PAS une valeur nulle, et on
 * affiche TOUJOURS un zéro.
 */
final class Chiffre
{
    private function __construct(
        public readonly string $libelle,
        public readonly ?float $valeur,
        public readonly GenreDeChiffre $genre,
    ) {
    }

    /** Une somme posée ou encaissée. */
    public static function montant(string $libelle, ?float $valeur): self
    {
        return new self($libelle, $valeur, GenreDeChiffre::Montant);
    }

    /** Un reste : le seul genre dont la valeur porte un jugement. */
    public static function solde(string $libelle, ?float $valeur): self
    {
        return new self($libelle, $valeur, GenreDeChiffre::Solde);
    }

    /** Un pourcentage. */
    public static function taux(string $libelle, ?float $valeur): self
    {
        return new self($libelle, $valeur, GenreDeChiffre::Taux);
    }

    /**
     * Ce chiffre a-t-il quelque chose à dire ?
     *
     * Seul `null` se tait. Un zéro parle — voir le commentaire de classe.
     */
    public function estCalcule(): bool
    {
        return $this->valeur !== null;
    }
}
