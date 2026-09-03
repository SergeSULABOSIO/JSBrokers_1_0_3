<?php

namespace App\Echange\Classeur;

/**
 * Une ligne de données lue dans un classeur, AVEC SON ADRESSE.
 *
 * L'adresse n'est pas un ornement : un rapport qui dit « une date est invalide » sans
 * dire où oblige l'utilisateur à relire trois mille lignes. C'est ce qui distingue un
 * rapport exploitable d'un constat d'échec poli.
 */
final class LigneLue
{
    /**
     * @param string               $feuille     nom de l'onglet, tel que l'utilisateur le voit
     * @param string               $codeRessource
     * @param int                  $numero      numéro de ligne Excel (1-indexé, donc ≥ 3)
     * @param array<string, mixed> $valeurs     code technique => valeur brute de la cellule
     * @param array<string, string> $colonnes   code technique => lettre de colonne Excel
     */
    public function __construct(
        public readonly string $feuille,
        public readonly string $codeRessource,
        public readonly int $numero,
        public readonly array $valeurs,
        public readonly array $colonnes,
    ) {
    }

    public function valeur(string $code): mixed
    {
        return $this->valeurs[$code] ?? null;
    }

    /** Valeur ramenée à une chaîne propre, ou chaîne vide. */
    public function texte(string $code): string
    {
        $valeur = $this->valeur($code);
        if ($valeur === null || is_array($valeur) || is_object($valeur)) {
            return '';
        }

        return trim((string) $valeur);
    }

    /** Lettre de colonne Excel d'un code technique — pour situer une anomalie. */
    public function colonne(string $code): ?string
    {
        return $this->colonnes[$code] ?? null;
    }

    /**
     * La ligne est-elle VIDE de tout contenu métier ?
     *
     * Une ligne blanche laissée sous les données — un tri, un copier-coller, une ligne
     * de total effacée — ne doit pas devenir une création à champs vides, puis une
     * volée d'erreurs « champ obligatoire manquant » que l'utilisateur n'a pas
     * provoquées.
     *
     * @param string[] $colonnesTechniques
     */
    public function estVide(array $colonnesTechniques): bool
    {
        foreach ($this->valeurs as $code => $valeur) {
            if (in_array($code, $colonnesTechniques, true)) {
                continue;
            }
            if ($valeur !== null && trim((string) (is_scalar($valeur) ? $valeur : '')) !== '') {
                return false;
            }
        }

        // Une ligne sans contenu métier mais portant un identifiant et une action
        // explicite reste significative : c'est ainsi qu'on demande une suppression.
        return trim((string) ($this->valeurs['_action'] ?? '')) === '';
    }
}
