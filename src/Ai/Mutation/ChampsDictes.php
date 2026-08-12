<?php

namespace App\Ai\Mutation;

/**
 * SOURCE UNIQUE du décodage des CHAMPS dictés par le modèle, quel que soit le
 * dialecte dans lequel il les a écrits.
 *
 * POURQUOI CETTE CLASSE EXISTE (incident du 2026-08-12). Un même concept — « les
 * champs de l'enregistrement » — est déclaré sous DEUX formes dans le même tour :
 *
 *  - preparer_operations.operations[].champs : un OBJET   {"nom":"MBUSA","prime":95}
 *  - preparer_programme.etapes[].champs      : des PAIRES  [{"cle":"nom","valeur":"MBUSA"}]
 *
 * La seconde forme existe pour une bonne raison (les modèles omettent les
 * paramètres complexes optionnels, cf. OutilsDeProgramme), mais le modèle voit les
 * deux schémas simultanément et intervertit. Ce jour-là, une étape « Créer le
 * risque Assurance Voyage » a été envoyée avec ses champs en MAP : l'ancienne
 * boucle testait `is_array($paire)`, chaque valeur scalaire était donc ignorée, la
 * création se retrouvait SANS AUCUN CHAMP, et tout le programme était refusé. Le
 * courtier a perdu quatre échanges et n'a rien enregistré.
 *
 * Le symétrique était tout aussi vrai et tout aussi silencieux : des champs
 * envoyés en PAIRES à preparer_operations donnaient un `foreach` à clés entières,
 * `is_string($champ)` échouait, et TOUS les champs disparaissaient sans un mot.
 *
 * On ne demande donc plus au modèle de choisir : on accepte les deux formes, à un
 * seul endroit. Ce qui reste FAIL-CLOSED, et le reste volontairement : une liste
 * de scalaires (["nom","MBUSA"]) est REFUSÉE — deviner un appariement positionnel
 * écrirait des données fausses dans le dossier d'un client, ce qui est pire que
 * de refuser.
 */
final class ChampsDictes
{
    /** Alias de clé tolérés dans la forme « paires » (le contrat reste « cle »). */
    private const CLES = ['cle', 'nom', 'champ', 'key', 'field', 'name'];

    /** Alias de valeur tolérés dans la forme « paires » (le contrat reste « valeur »). */
    private const VALEURS = ['valeur', 'value'];

    /**
     * Ramène les champs dictés à une MAP « champ => valeur », quelle que soit la
     * forme reçue. Aucun filtrage de TYPE ici : les appelants appliquent le leur
     * (MutationOperation ne garde que scalaires et listes de scalaires).
     *
     * @return array<string, mixed> vide si rien d'exploitable
     */
    public static function normaliser(mixed $brut): array
    {
        if (!is_array($brut) || $brut === []) {
            return [];
        }

        if (self::estDesPaires($brut)) {
            return self::depuisPaires($brut);
        }

        // Forme MAP. Une clé entière n'est pas un nom de champ : la conserver
        // ferait écrire dans un champ « 0 » qui n'existe nulle part.
        $champs = [];
        foreach ($brut as $champ => $valeur) {
            if (is_string($champ) && trim($champ) !== '') {
                $champs[trim($champ)] = $valeur;
            }
        }

        return $champs;
    }

    /**
     * Forme reçue, pour la JOURNALISATION de diagnostic uniquement — jamais pour
     * décider. Sans elle, l'incident du 2026-08-12 était indiagnosticable : les
     * journaux ne portaient que le nom de l'outil appelé, jamais la tête de ses
     * arguments.
     *
     * @return 'absent'|'map'|'paires'|'liste-scalaires'|'autre'
     */
    public static function forme(mixed $brut): string
    {
        if (!is_array($brut) || $brut === []) {
            return 'absent';
        }
        if (self::estDesPaires($brut)) {
            return 'paires';
        }
        if (array_is_list($brut)) {
            return 'liste-scalaires';
        }

        foreach (array_keys($brut) as $cle) {
            if (is_string($cle)) {
                return 'map';
            }
        }

        return 'autre';
    }

    /**
     * Une LISTE dont au moins une entrée est elle-même un tableau : c'est le
     * dialecte des paires. Le « au moins une » (plutôt que « toutes ») est
     * délibéré — un modèle qui glisse un scalaire parasite au milieu de ses paires
     * ne doit pas faire basculer toute l'étape dans l'autre interprétation.
     *
     * @param array<mixed> $brut
     */
    private static function estDesPaires(array $brut): bool
    {
        if (!array_is_list($brut)) {
            return false;
        }

        foreach ($brut as $entree) {
            if (is_array($entree)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $paires
     *
     * @return array<string, mixed>
     */
    private static function depuisPaires(array $paires): array
    {
        $champs = [];
        foreach ($paires as $paire) {
            if (!is_array($paire)) {
                continue;
            }

            $cle = '';
            foreach (self::CLES as $alias) {
                if (isset($paire[$alias]) && is_scalar($paire[$alias]) && trim((string) $paire[$alias]) !== '') {
                    $cle = trim((string) $paire[$alias]);
                    break;
                }
            }
            if ($cle === '') {
                continue;
            }

            // array_key_exists et NON isset : une valeur null est une valeur
            // dictée (« vide ce champ »), pas une absence.
            foreach (self::VALEURS as $alias) {
                if (array_key_exists($alias, $paire)) {
                    $champs[$cle] = $paire[$alias];
                    break;
                }
            }
        }

        return $champs;
    }
}
