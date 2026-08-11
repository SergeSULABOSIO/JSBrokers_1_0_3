<?php

namespace App\Ai\Mutation;

/**
 * TRADUIT LES DATES DICTÉES par l'utilisateur dans le format qu'attend le
 * formulaire — le seul juge, en fin de course, de la validité d'un plan.
 *
 * POURQUOI CE SERVICE EXISTE (incident du 2026-08-11, conversation 35). Le
 * courtier écrit « Le 11/08/2026, 150 $, entretien du véhicule ». Le modèle
 * recopie fidèlement ce qu'il a lu — `dateDepense: "11/08/2026"` — et le
 * formulaire répond « Veuillez entrer une date valide » : il attend `2026-08-11`.
 * Le plan n'est donc JAMAIS prêt, aucun bouton n'apparaît, et l'utilisateur
 * redonne trois fois les mêmes informations sans jamais rien enregistrer. La
 * faute n'est ni à lui — il a écrit une date française dans une application
 * française — ni au modèle, qui a transmis exactement ce qu'on lui a dicté.
 * Elle est à NOUS : le serveur savait de quel champ il s'agissait, et de quel
 * format il avait besoin.
 *
 * C'EST DONC UNE RÈGLE D'AUTONOMIE, pas une tolérance. Rien de ce qu'un outil
 * peut déduire seul ne doit être exigé du modèle : chaque exigence de format
 * transmise par le prompt est une consigne de plus à respecter, donc une
 * occasion de plus de la manquer — et le manquement, ici, coûtait la mission
 * entière. Le même piège avait déjà été rencontré sur `Tranche.payableAt`
 * (« Y-m-d\TH:i »), et documenté comme un GOTCHA à mémoriser plutôt que comme
 * un défaut à corriger.
 *
 * LE FORMAT DE SORTIE DÉPEND DU TYPE DOCTRINE DU CHAMP, jamais d'une devinette :
 *  - `date` / `date_immutable`         => `Y-m-d` (DateType) ;
 *  - `datetime` / `datetime_immutable` => `Y-m-d\TH:i` (DateTimeType, qui refuse
 *    une date nue — c'est exactement le GOTCHA payableAt).
 *
 * JOUR/MOIS, ET PAS L'INVERSE. « 11/08/2026 » vaut le 11 août : la plateforme est
 * francophone (RDC), ses écrans affichent d/m/Y et ses utilisateurs dictent ainsi.
 * Interpréter à l'américaine écrirait une date fausse en silence — le pire des
 * résultats. Une valeur déjà ISO (`2026-08-11`) reste lue comme telle : elle n'est
 * pas ambiguë.
 */
final class NormaliseurDeDates
{
    /** Formats acceptés en ENTRÉE, du plus explicite au plus court. L'ordre tranche : d/m/Y avant Y-m-d ne changerait rien, mais d/m/Y avant m/d/Y, si. */
    private const FORMATS = [
        'Y-m-d\TH:i:s',
        'Y-m-d\TH:i',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d',
        'd/m/Y H:i',
        'd/m/Y',
        'd-m-Y H:i',
        'd-m-Y',
        'd.m.Y',
        'd/m/y',
        'd-m-y',
        'Y/m/d',
    ];

    /** Mois écrits en clair — « le 11 août 2026 » est une date, et l'utilisateur l'écrit ainsi. */
    private const MOIS = [
        'janvier' => '01', 'janv' => '01', 'jan' => '01',
        'fevrier' => '02', 'février' => '02', 'fev' => '02', 'fév' => '02',
        'mars' => '03',
        'avril' => '04', 'avr' => '04',
        'mai' => '05',
        'juin' => '06',
        'juillet' => '07', 'juil' => '07',
        'aout' => '08', 'août' => '08',
        'septembre' => '09', 'sept' => '09', 'sep' => '09',
        'octobre' => '10', 'oct' => '10',
        'novembre' => '11', 'nov' => '11',
        'decembre' => '12', 'décembre' => '12', 'dec' => '12', 'déc' => '12',
    ];

    /**
     * La valeur normalisée, ou la valeur d'ORIGINE quand elle n'est pas une date
     * reconnaissable.
     *
     * On ne « corrige » jamais au jugé : ce qui n'est pas compris repart tel quel
     * et c'est le formulaire qui refusera, en nommant le champ. Un refus explicite
     * vaut infiniment mieux qu'une date inventée.
     *
     * @param string $typeDoctrine 'date' | 'datetime' (préfixes reconnus, suffixe
     *                             `_immutable` compris)
     */
    public function normaliser(mixed $valeur, string $typeDoctrine): mixed
    {
        if (!is_string($valeur)) {
            return $valeur;
        }
        $texte = trim($valeur);
        if ($texte === '') {
            return $valeur;
        }

        $sortie = str_starts_with($typeDoctrine, 'datetime') ? 'Y-m-d\TH:i' : 'Y-m-d';

        // Déjà exactement au bon format : ne rien toucher. Reformater une valeur
        // qui marche ferait varier les plans stockés sans rien apporter.
        if (\DateTimeImmutable::createFromFormat($sortie, $texte) !== false) {
            return $texte;
        }

        $date = $this->interpreter($texte);

        return $date === null ? $valeur : $date->format($sortie);
    }

    /**
     * Les champs d'une opération dont le type Doctrine est temporel, normalisés.
     *
     * @param array<string, mixed>  $champs
     * @param array<string, string> $typesTemporels champ => type Doctrine
     *
     * @return array<string, mixed>
     */
    public function normaliserChamps(array $champs, array $typesTemporels): array
    {
        foreach ($champs as $champ => $valeur) {
            $type = $typesTemporels[$champ] ?? null;
            if ($type === null) {
                continue;
            }
            $champs[$champ] = $this->normaliser($valeur, $type);
        }

        return $champs;
    }

    /**
     * Interprète un texte comme une date, sans jamais s'en remettre au parseur
     * « intelligent » de PHP : `new DateTime('11/08/2026')` lit le 8 NOVEMBRE
     * (convention américaine). C'est précisément l'erreur silencieuse à ne pas
     * commettre — d'où une liste de formats explicites, jour d'abord.
     */
    private function interpreter(string $texte): ?\DateTimeImmutable
    {
        $texte = preg_replace('/\s+/u', ' ', $texte) ?? $texte;

        // Mois en clair : « 11 août 2026 », « 1er septembre 2026 ».
        if (preg_match('/^(\d{1,2})(?:er)?\s+([\p{L}]+)\.?\s+(\d{4})$/u', $texte, $m) === 1) {
            $mois = self::MOIS[mb_strtolower($m[2])] ?? null;
            if ($mois !== null) {
                $texte = sprintf('%02d/%s/%s', (int) $m[1], $mois, $m[3]);
            }
        }

        foreach (self::FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $texte);
            if ($date === false) {
                continue;
            }
            // createFromFormat est TOLÉRANT : « 32/13/2026 » lui arrache une date
            // « valide » par report (le 1er février 2027). On rejette donc tout ce
            // que PHP a dû rattraper — une date fausse acceptée en silence est le
            // seul résultat pire qu'un refus.
            $erreurs = \DateTimeImmutable::getLastErrors();
            if (($erreurs['warning_count'] ?? 0) > 0 || ($erreurs['error_count'] ?? 0) > 0) {
                continue;
            }
            // « 11/08/26 » satisfait AUSSI le format d/m/Y, qui en tire l'an 26 sans
            // se plaindre. Un millésime à trois chiffres ou moins n'est jamais ce que
            // l'utilisateur a voulu dire : on laisse le format à deux chiffres (d/m/y),
            // essayé plus loin, faire son travail.
            if (str_contains($format, 'Y') && (int) $date->format('Y') < 1000) {
                continue;
            }

            // Formats sans heure : minuit, et non « maintenant » (ce que fait
            // createFromFormat par défaut, rendant la valeur non reproductible).
            return str_contains($format, 'H:i') ? $date : $date->setTime(0, 0);
        }

        return null;
    }
}
