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
     * @param array<string, mixed> $champs       ce que le modèle a dicté
     * @param list<string>         $champsConnus les champs réels du formulaire
     *
     * @return array{champs: array<string, mixed>, alias: list<string>, ignores: list<string>}
     */
    public function normaliser(array $champs, array $champsConnus): array
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

            $cible = $this->cibleUnique((string) $champ, $normalises);
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
}
