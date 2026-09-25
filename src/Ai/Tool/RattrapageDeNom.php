<?php

namespace App\Ai\Tool;

/**
 * RETROUVE L'OUTIL QUE LE MODÈLE A VOULU APPELER quand il en écorche le nom.
 *
 * POURQUOI. Cinquante-deux outils sont déclarés, et plusieurs se ressemblent —
 * `rechercher_entites`, `compter_entites`, `analyse_portefeuille`. Mesuré sur les
 * journaux au 2026-09-25 : **10 appels sur 188 (5,3 %) visaient un nom qui n'existe
 * pas**, et tous étaient des quasi-homonymes du vrai — `analyser_portefeuille` pour
 * `analyse_portefeuille` (4 fois), `rechercher_entite` au singulier, `consultar_guide`
 * en espagnol. Chacun coûtait un tour entier — de l'ordre de quarante mille jetons
 * d'entrée — pour un « introuvable » que le modèle devait ensuite corriger seul.
 *
 * CE QUE CETTE CLASSE NE FAIT PAS, et c'est l'essentiel. Elle ne devine pas une
 * INTENTION : elle ne corrige qu'une faute de frappe, sur des noms qu'on lui donne.
 * L'appelant ne lui remet que les outils DÉCLARÉS AU TOUR EN COURS — jamais le
 * catalogue entier. Un outil d'écriture n'est donc pas atteignable depuis une
 * trousse de lecture, ni un outil coupé en console depuis nulle part : la frontière
 * est tenue par la liste des candidats, pas par un jugement porté ici.
 *
 * DEUX GARDE-FOUS, et ils sont volontairement sévères. La distance doit être PETITE
 * (au plus {@see DISTANCE_MAX} substitutions, insertions ou suppressions), et le
 * plus proche doit être SEUL à cette distance. Une hésitation entre deux outils
 * n'est plus une faute de frappe : c'est une ambiguïté, et exécuter l'un des deux
 * au hasard ferait bien pire que de répondre « introuvable ». On préfère toujours
 * ne rien faire à faire la mauvaise chose.
 */
final class RattrapageDeNom
{
    /**
     * Combien de caractères peuvent séparer le nom demandé du nom réel.
     *
     * Deux, et pas davantage. Les trois écorchures relevées en production sont
     * toutes à UNE distance de 1 (« analyser_ » pour « analyse_ », un « s » manquant,
     * « consultar » pour « consulter »). Monter à trois ferait entrer des outils qui
     * ne se ressemblent que par leur suffixe — `lister_entites` et `compter_entites`
     * n'ont rien à voir, et les confondre exécuterait la mauvaise lecture.
     */
    public const DISTANCE_MAX = 2;

    /**
     * Le nom réel que le modèle visait, ou null s'il n'y a pas de certitude.
     *
     * @param string       $demande   le nom tel que le modèle l'a écrit
     * @param list<string> $candidats les outils DÉCLARÉS au tour en cours, et eux seuls
     */
    public static function leProche(string $demande, array $candidats): ?string
    {
        $demande = trim($demande);
        if ($demande === '' || $candidats === []) {
            return null;
        }

        // ⚠ UN NOM QUI EXISTE N'EST JAMAIS UNE FAUTE DE FRAPPE. S'il figure parmi les
        // candidats, l'appelant l'aurait déjà exécuté ; s'il n'y figure pas mais
        // existe ailleurs, c'est un outil VOLONTAIREMENT absent de ce tour — coupé en
        // console, ou réservé à l'autre trousse. Le rattraper vers un voisin
        // reviendrait à contourner la décision qui l'a écarté.
        if (in_array($demande, $candidats, true)) {
            return null;
        }

        $meilleurs = [];
        $meilleure = PHP_INT_MAX;
        foreach ($candidats as $candidat) {
            $distance = levenshtein($demande, $candidat);
            if ($distance > self::DISTANCE_MAX || $distance > $meilleure) {
                continue;
            }
            if ($distance < $meilleure) {
                $meilleure = $distance;
                $meilleurs = [];
            }
            $meilleurs[] = $candidat;
        }

        // Seul à cette distance, ou rien : une hésitation n'est pas une faute de frappe.
        return count($meilleurs) === 1 ? $meilleurs[0] : null;
    }
}
