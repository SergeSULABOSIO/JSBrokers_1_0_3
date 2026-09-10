<?php

namespace App\Echange\Reprise;

use App\Echange\Classeur\LigneLue;
use App\Echange\Service\Anomalie;

/**
 * LES PARTS D'ÉCHÉANCE D'UNE MÊME POLICE DOIVENT FAIRE UN TOUT.
 *
 * La prime d'une police est la somme de ses composantes ; la prime d'une échéance vaut
 * cette somme multipliée par la PART de l'échéance. Les deux ne coïncident donc que si les
 * parts d'une police font cent pour cent — et personne ne le vérifiait.
 *
 * ⚠ QUATRE ÉCHÉANCES À CENT POUR CENT FONT QUATRE FOIS LA POLICE. C'est le geste le plus
 * naturel du monde pour qui remplit un tableau : chaque ligne décrit « toute » l'échéance,
 * alors on écrit 100 partout. Le fichier passait le contrôle, la reprise écrivait, et le
 * portefeuille annonçait une prime quadruple — d'aspect parfaitement normal.
 *
 * ⚠ ET LA MÊME PART SERT DE DIVISEUR. `ReconstitueurDeTranche::chargementsDeLaLigne()`
 * remonte le prorata en divisant par elle : une part fausse ne se contente pas de fausser
 * l'échéance, elle fausse la prime de la police entière.
 *
 * ⚠ ON NE JUGE QUE CE QUE LE FICHIER MONTRE. Une police dont une seule échéance figure au
 * dépôt n'est pas contrôlable : il manque les autres, et rien ne dit si c'est un oubli ou
 * une reprise partielle voulue. Le silence est ici la seule réponse honnête.
 */
final class CoherenceDesParts
{
    /** La somme attendue, en POINTS — la convention du projet pour tous les taux. */
    private const TOTAL_ATTENDU = 100.0;

    /**
     * Ce qu'on tolère d'écart sur la somme.
     *
     * ⚠ IL EN FAUT UNE. Trois échéances d'un tiers s'écrivent 33,33 et somment à 99,99 :
     * refuser cela serait refuser une répartition parfaitement légitime, pour une décimale
     * que le cabinet ne peut pas écrire autrement.
     */
    private const TOLERANCE = 0.5;

    /**
     * Les reproches à faire aux lignes de cette fenêtre.
     *
     * @param LigneLue[] $lignes lignes d'un palier — les polices y tiennent entières
     *
     * @return Anomalie[]
     */
    public static function verifier(array $lignes): array
    {
        $anomalies = [];

        foreach (self::grouperParPolice($lignes) as $reference => $groupe) {
            // Une police d'une seule ligne ne dit rien de sa répartition : la part absente
            // y vaut la totalité, ce qui est le comportement de la reprise depuis toujours.
            if (count($groupe) < 2) {
                continue;
            }

            $somme = 0.0;
            $sansPart = [];

            foreach ($groupe as $ligne) {
                $part = self::part($ligne);
                if ($part === null) {
                    $sansPart[] = $ligne;
                    continue;
                }
                $somme += $part;
            }

            if ($sansPart !== []) {
                foreach ($sansPart as $ligne) {
                    $anomalies[] = self::refus($ligne, sprintf(
                        'Cette police compte %d échéances dans votre fichier, mais celle-ci '
                        . 'n\'indique pas quelle part de la prime elle représente. Indiquez-la '
                        . 'en pourcentage — par exemple 25 pour un quart —, et faites en sorte '
                        . 'que les %d échéances totalisent 100.',
                        count($groupe),
                        count($groupe),
                    ));
                }

                continue;
            }

            if (abs($somme - self::TOTAL_ATTENDU) <= self::TOLERANCE) {
                continue;
            }

            // Le reproche est porté par CHAQUE ligne du groupe : le classeur annoté doit
            // les surligner toutes, puisque c'est ensemble qu'elles se contredisent — et
            // qu'on ne sait pas laquelle est fautive.
            foreach ($groupe as $ligne) {
                $anomalies[] = self::refus($ligne, sprintf(
                    'Les %d échéances de la police « %s » totalisent %s %% de la prime, au '
                    . 'lieu de 100. Chaque échéance doit porter la part qui lui revient : '
                    . 'quatre échéances égales font 25 chacune, deux font 50. Tant qu\'elles '
                    . 'ne font pas 100, la prime de la police sera fausse.',
                    count($groupe),
                    $reference,
                    self::lisible($somme),
                ));
            }
        }

        return $anomalies;
    }

    /**
     * @param LigneLue[] $lignes
     *
     * @return array<string, LigneLue[]>
     */
    private static function grouperParPolice(array $lignes): array
    {
        $groupes = [];

        foreach ($lignes as $ligne) {
            $reference = trim($ligne->texte('policeReference'));
            if ($reference === '') {
                continue;
            }

            // L'avenant fait partie de l'identité : deux avenants d'une même police sont
            // deux échéanciers, et leurs parts ne s'additionnent pas.
            $cle = $reference . '|' . trim($ligne->texte('policeNumeroAvenant'));
            $groupes[$cle][] = $ligne;
        }

        // La clé technique porte l'avenant ; le reproche, lui, nomme la police telle que
        // l'utilisateur l'a écrite.
        $parReference = [];
        foreach ($groupes as $cle => $groupe) {
            $parReference[explode('|', $cle)[0]] = $groupe;
        }

        return $parReference;
    }

    private static function part(LigneLue $ligne): ?float
    {
        $brut = trim($ligne->texte('tranchePart'));
        if ($brut === '') {
            return null;
        }

        $part = (float) str_replace(',', '.', $brut);

        return $part <= 0.0 ? null : $part;
    }

    private static function refus(LigneLue $ligne, string $motif): Anomalie
    {
        return Anomalie::erreur(
            Anomalie::VALEUR_INVALIDE,
            $motif,
            $ligne->feuille,
            $ligne->numero,
            $ligne->colonne('tranchePart'),
        );
    }

    private static function lisible(float $valeur): string
    {
        $texte = number_format($valeur, 2, ',', ' ');

        return str_ends_with($texte, ',00') ? substr($texte, 0, -3) : $texte;
    }
}
