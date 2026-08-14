<?php

namespace App\Ai\Mutation;

use App\Ai\AiText;

/**
 * UN CHAMP DICTÉ QUI N'EXISTE PAS NE DISPARAÎT PLUS EN SILENCE.
 *
 * L'INCIDENT (2026-08-13, sur la tâche la plus simple qui soit : créer un risque).
 * Ket a dicté « nom : Assurance Voyage ». Or `Risque` est la SEULE entité du
 * périmètre à nommer ce champ `nomComplet` — toutes les autres disent `nom`. Le
 * champ inconnu a été jeté sans un mot, `nomComplet` est revenu « obligatoire à
 * renseigner », et Ket a redemandé à l'utilisateur un nom qu'il avait donné trois
 * fois. Pire : le plan AFFICHAIT « Nom : Assurance Voyage », c'est-à-dire un champ
 * qui n'allait pas être écrit. Encore un plan qui ment.
 *
 * Deux réponses, et la seconde compte autant que la première :
 *  1. RATTRAPER quand c'est sans ambiguïté. Un champ inconnu dont le nom est le
 *     début d'un champ réel — et d'UN SEUL — désigne évidemment celui-là :
 *     « nom » → « nomComplet ». S'il y a plusieurs candidats (« date » face à
 *     `dateDebut` et `dateFin`), on ne tranche pas : deviner écrirait une donnée
 *     fausse dans le dossier d'un client.
 *  2. DIRE ce qu'on ignore. Un champ qu'on ne sait pas rattacher est RETIRÉ des
 *     champs de l'opération — pour qu'il cesse de figurer dans le tableau du plan —
 *     et signalé, afin que l'utilisateur voie qu'il ne sera pas écrit.
 *
 * LE LIBELLÉ D'ÉCRAN EST UN NOM DE CHAMP (incident du 2026-08-13). « Modifie le
 * taux de commission de ce risque » : le modèle dicte `tauxCommission`, quand le
 * formulaire nomme ce champ `pourcentageCommissionSpecifiqueHT`. Aucun préfixe ne
 * les rapproche — mais le champ porte le LIBELLÉ « Taux de commission », que le
 * modèle a lu sur la fiche et camel-casé. Ce n'est donc pas une devinette : on
 * rattache un champ dicté au champ dont le libellé porte EXACTEMENT les mêmes mots
 * (mots vides ôtés), et à condition qu'il soit le seul. « montantCommission » face
 * à un unique « Montant de la prime » ne correspond à rien et reste écarté — c'est
 * précisément ce qu'on veut, un mot commun ne fait pas un champ.
 *
 * Rien n'est deviné, tout est annoncé : c'est la même règle que partout ailleurs.
 */
final class AliasDeChamps
{
    /**
     * En deçà, un préfixe ne désigne rien : « id » ou « n » s'accrocheraient à
     * n'importe quoi.
     */
    private const LONGUEUR_MINIMALE = 3;

    /**
     * Mots qui n'apprennent rien sur le champ désigné : les retenir ferait échouer
     * la correspondance entre « tauxCommission » et « Taux de commission ».
     */
    private const MOTS_VIDES = [
        'de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'un', 'une', 'et', 'en',
        'a', 'au', 'aux', 'par', 'pour', 'sur', 'dans',
    ];

    /**
     * @param array<string, mixed>  $champs       ce que le modèle a dicté
     * @param list<string>          $champsConnus les champs réels du formulaire
     * @param array<string, string> $libelles     champ réel => libellé affiché à l'écran
     *
     * @return array{champs: array<string, mixed>, alias: list<string>, ignores: list<string>}
     */
    public function normaliser(array $champs, array $champsConnus, array $libelles = []): array
    {
        // Sans inventaire exploitable, on ne touche à RIEN : mieux vaut le
        // comportement d'avant qu'un rattrapage à l'aveugle.
        if ($champsConnus === []) {
            return ['champs' => $champs, 'alias' => [], 'ignores' => []];
        }

        $normalises = [];
        foreach ($champsConnus as $connu) {
            $normalises[AiText::normalize($connu)] = $connu;
        }

        $resultat = [];
        $alias = [];
        $ignores = [];

        foreach ($champs as $champ => $valeur) {
            if (in_array($champ, $champsConnus, true)) {
                $resultat[$champ] = $valeur;
                continue;
            }

            // UNE PIÈCE JOINTE NE SE RENOMME JAMAIS (incident du 2026-08-14).
            //
            // Un champ portant « @fichier:<id> » vise une propriété d'UPLOAD (Vich),
            // qui n'est PAS une colonne Doctrine : elle est donc absente de
            // l'inventaire, donc « inconnue », donc offerte au rapprochement par
            // libellé. Sur un Document, « fichier » se faisait ainsi rattacher à
            // « nomFichierStocke » — dont le libellé humanisé « Nom fichier stocke »
            // contient le mot « fichier ». La clé devenait un champ que DocumentType
            // n'expose pas, l'upload était jeté plus loin sans un mot, et Ket
            // annonçait un contrat enregistré dans un document vide.
            //
            // Aucun rapprochement par ressemblance ne peut être juste ici : le nom du
            // champ d'upload est fixé par le FormType, pas devinable depuis les
            // colonnes. On laisse donc la clé INTACTE — le formulaire tranchera, et
            // s'il ne connaît pas ce champ il le dira (cf. WorkspaceMutationService).
            if (ConversationFichierRef::estMarqueur($valeur)) {
                $resultat[$champ] = $valeur;
                continue;
            }

            $cible = $this->cibleUnique((string) $champ, $normalises)
                ?? $this->cibleParLibelle((string) $champ, $libelles, $champsConnus);
            if ($cible === null) {
                $ignores[] = (string) $champ;
                continue;
            }
            // Ne jamais écraser une valeur DICTÉE pour le champ réel : si le modèle a
            // donné les deux, celle du bon nom l'emporte.
            if (!array_key_exists($cible, $champs)) {
                $resultat[$cible] = $valeur;
                $alias[] = sprintf('« %s » lu comme « %s ».', (string) $champ, $cible);
            } else {
                $ignores[] = (string) $champ;
            }
        }

        return ['champs' => $resultat, 'alias' => $alias, 'ignores' => $ignores];
    }

    /**
     * Le champ réel que ce nom désigne SANS AMBIGUÏTÉ, ou null.
     *
     * @param array<string, string> $normalises nom normalisé => nom réel
     */
    private function cibleUnique(string $champ, array $normalises): ?string
    {
        $cherche = AiText::normalize($champ);
        if (mb_strlen($cherche) < self::LONGUEUR_MINIMALE) {
            return null;
        }

        $candidats = [];
        foreach ($normalises as $normalise => $reel) {
            // Dans les deux sens : « nom » désigne « nomComplet », et « nomcomplet »
            // désignerait « nom » sur une entité qui n'a que celui-là.
            if (str_starts_with($normalise, $cherche) || str_starts_with($cherche, $normalise)) {
                $candidats[$reel] = true;
            }
        }

        return count($candidats) === 1 ? (string) array_key_first($candidats) : null;
    }

    /**
     * Le champ dont le LIBELLÉ dit ce que ce nom dit, ou null.
     *
     * DEUX ÉTAGES, dans cet ordre, et le second ne sert que si le premier n'a rien
     * trouvé :
     *  1. ÉGALITÉ des ensembles de mots significatifs, ordre et mots de liaison
     *     indifférents : « tauxCommission » == « Taux de commission ».
     *  2. INCLUSION d'un ensemble dans l'autre. Le 2026-08-13, à la deuxième
     *     tentative, le modèle a dicté `tauxCommissionPercent` — le même champ, avec
     *     l'unité en plus. Exiger l'égalité stricte revenait à parier sur le nombre
     *     exact de mots que le modèle emploierait, ce qui n'est pas une propriété
     *     stable. L'inclusion, elle, reste sûre : « montantCommission » face au seul
     *     « Montant de la prime » n'est inclus dans aucun sens et demeure écarté.
     *
     * Les étages sont séparés pour que le meilleur candidat gagne : là où un libellé
     * « Montant » et un libellé « Montant de la commission » coexistent, l'égalité
     * tranche au premier étage, alors que l'inclusion les rendrait tous deux
     * candidats — donc ambigus, donc perdus. Dans les deux étages, le candidat doit
     * être UNIQUE.
     *
     * @param array<string, string> $libelles     champ réel => libellé
     * @param list<string>          $champsConnus les champs réellement exposés
     */
    private function cibleParLibelle(string $champ, array $libelles, array $champsConnus): ?string
    {
        $mots = $this->motsSignificatifs($champ);
        if ($mots === []) {
            return null;
        }

        $egalite = [];
        $inclusion = [];
        foreach ($libelles as $reel => $libelle) {
            // Un libellé sans champ exposé ne désigne rien d'écrivable.
            if (!in_array($reel, $champsConnus, true)) {
                continue;
            }
            $motsLibelle = $this->motsSignificatifs((string) $libelle);
            if ($motsLibelle === []) {
                continue;
            }
            if ($motsLibelle === $mots) {
                $egalite[$reel] = true;
                continue;
            }
            if (array_diff($motsLibelle, $mots) === [] || array_diff($mots, $motsLibelle) === []) {
                $inclusion[$reel] = true;
            }
        }

        $candidats = $egalite !== [] ? $egalite : $inclusion;

        return count($candidats) === 1 ? (string) array_key_first($candidats) : null;
    }

    /**
     * Les mots d'un nom de champ ou d'un libellé, normalisés, triés et dédoublonnés :
     * « pourcentageCommissionSpecifiqueHT » comme « Taux de commission » se ramènent
     * à un ENSEMBLE de mots comparable.
     *
     * @return list<string>
     */
    private function motsSignificatifs(string $texte): array
    {
        // Frontières camelCase d'abord (« tauxCommission » → « taux Commission »),
        // puis tout ce qui n'est pas alphanumérique fait séparateur.
        $espace = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/u', ' ', $texte);
        $brut = preg_split('/[^\p{L}\p{N}]+/u', AiText::normalize($espace), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $mots = [];
        foreach ($brut as $mot) {
            if (!in_array($mot, self::MOTS_VIDES, true)) {
                $mots[$mot] = true;
            }
        }
        $mots = array_keys($mots);
        sort($mots);

        return $mots;
    }
}
