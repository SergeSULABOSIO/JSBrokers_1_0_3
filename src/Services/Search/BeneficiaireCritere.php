<?php

namespace App\Services\Search;

/**
 * LE COUPLE « TYPE D'INTERMÉDIAIRE » ↔ « BÉNÉFICIAIRE NOMMÉ », écrit UNE fois.
 *
 * Deux rubriques posent exactement la même paire de chips : « Rétros intermédiaires »
 * (ce que le cabinet a versé) et « Production intermédiaires » (ce que chacun apporte).
 * Toutes deux doivent traiter les DEUX familles — un agent interne, un partenaire externe —
 * qui ne vivent pas sur la même colonne et ne se nomment pas de la même façon.
 *
 * Recopier ces mécanismes d'une rubrique à l'autre aurait garanti qu'un jour l'une traite
 * le partenaire et l'autre non : c'est très exactement le défaut que l'unification des
 * rétrocommissions a dû corriger, et il n'y a aucune raison de le refaire. Les règles
 * vivent donc ici, et chaque scope les habille de SES clés de critère.
 *
 * ── CE QUI EST GÉNÉRIQUE, ET CE QUI NE L'EST PAS ────────────────────────────────────
 * Générique : l'encodage « famille:id », son décodage fail-closed, la compatibilité d'un
 * couple, la condition de visibilité d'un sélecteur et l'implication d'un choix.
 * Propre à chaque rubrique : le NOM des critères, leurs libellés, et la traduction en SQL —
 * une production ne se filtre pas comme un décaissement.
 */
final class BeneficiaireCritere
{
    /** Les deux familles d'intermédiaires, et il n'y en a pas de troisième. */
    public const TYPE_AGENT = 'agent';
    public const TYPE_PARTENAIRE = 'partenaire';

    /** La valeur d'un critère de bénéficiaire : « famille:id ». */
    public static function valeur(string $type, int $id): string
    {
        return $type . ':' . $id;
    }

    /**
     * L'inverse : « agent:12 » => ['agent', 12], ou null si la valeur ne dit rien de bon.
     *
     * FAIL-CLOSED : une valeur illisible retire le filtre plutôt que d'en inventer un. Un
     * identifiant nu (« 12 ») est refusé à dessein — il confondrait l'agent #12 avec le
     * partenaire #12, et la liste rendue serait fausse sans être vide.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function decoder(?string $valeur): ?array
    {
        if ($valeur === null || !str_contains($valeur, ':')) {
            return null;
        }

        [$type, $id] = explode(':', $valeur, 2);
        if (!in_array($type, [self::TYPE_AGENT, self::TYPE_PARTENAIRE], true) || (int) $id <= 0) {
            return null;
        }

        return [$type, (int) $id];
    }

    /**
     * LA FAMILLE PORTÉE PAR UNE VALEUR — « partenaire:5 » => 'partenaire'.
     *
     * Elle se déduit du décodage, jamais d'une seconde lecture du séparateur : deux façons
     * de lire une même valeur finissent par en désigner deux.
     */
    public static function famille(?string $valeur): ?string
    {
        return self::decoder($valeur)[0] ?? null;
    }

    /**
     * LE COUPLE (type, bénéficiaire) EST-IL TENABLE ?
     *
     * Un type qui exclut la famille du bénéficiaire nommé décrit un ensemble vide. À
     * l'écran, la visibilité des sélecteurs rend ce couple inatteignable ; ailleurs —
     * l'assistant, une URL fabriquée — il faut pouvoir le refuser EN LE NOMMANT, plutôt
     * que d'ouvrir une liste vide dont personne ne saura dire la cause.
     */
    public static function compatible(?string $type, ?string $valeurBeneficiaire): bool
    {
        $famille = self::famille($valeurBeneficiaire);

        return $type === null || $type === '' || $famille === null || $famille === $type;
    }

    /**
     * R1 — LA CONDITION DE PRÉSENCE d'un sélecteur de bénéficiaire.
     *
     * « Choisir un partenaire… » n'a rien à proposer quand le chip « Type » est sur Agent :
     * le filtre rendrait la liste NÉCESSAIREMENT vide, et l'offrir est déjà un mensonge.
     *
     * La chaîne vide est dans la liste À DESSEIN : elle vaut « aucun filtre de type », et
     * c'est une réponse, pas un silence. Sans elle, il aurait fallu ajouter un opérateur
     * `empty` au moteur de visibilité que ~39 dialogues partagent.
     *
     * Le format est celui de `visibility_conditions` — même grammaire, même évaluateur
     * (`assets/controllers/visibilite-conditions.js`). Les chips n'en inventent pas une
     * seconde.
     *
     * @return array<int, array{field: string, operator: string, value: array<int, string>}>
     */
    public static function visibiliteDuSelecteur(string $cleType, string $type): array
    {
        return [[
            'field' => $cleType,
            'operator' => 'in',
            'value' => ['', $type],
        ]];
    }

    /**
     * R3 — CE QU'IMPLIQUE LE CHOIX d'un bénéficiaire de cette famille.
     *
     * Choisir « SUNU Courtage » dit déjà que le type est « partenaire » : le chip « Type »
     * s'aligne du MÊME geste, donc en une seule recherche. Sans cela les deux chips
     * racontaient la même chose de deux façons, et l'un pouvait contredire l'autre.
     *
     * Le LIBELLÉ voyage avec la valeur : c'est lui que lira le badge de la barre de
     * recherche, où « partenaire » brut serait illisible.
     *
     * @return array<string, array{value: string, label: string}>
     */
    public static function implicationDuSelecteur(string $cleType, string $type, string $libelle): array
    {
        return [$cleType => ['value' => $type, 'label' => $libelle]];
    }

    /**
     * Les deux options du chip-sélecteur, plus son « Tous ».
     *
     * Chaque option ne porte pas une valeur mais une ENTITÉ où aller chercher les valeurs
     * au clic : c'est ce qui permet de filtrer sur une relation sans figer une option par
     * enregistrement dans un canevas de liste — lesquels sont partagés et ignorent
     * l'entreprise.
     *
     * « TOUS » NE DÉCLARE RIEN, et c'est délibéré : c'est le seul moyen de retirer le
     * filtre, il ne peut donc jamais disparaître de l'écran, quel que soit le type actif.
     *
     * @param array<string, string> $libellesDesTypes famille => libellé du chip « Type »
     *
     * @return array<int, array<string, mixed>>
     */
    public static function optionsDuSelecteur(string $cleType, array $libellesDesTypes): array
    {
        $options = [];
        foreach ([
            self::TYPE_AGENT => ['entite' => 'Invite', 'label' => 'Choisir un agent…', 'icon' => 'invite'],
            self::TYPE_PARTENAIRE => ['entite' => 'Partenaire', 'label' => 'Choisir un partenaire…', 'icon' => 'partenaire'],
        ] as $type => $forme) {
            $options[] = [
                'selecteur' => [
                    'entite' => $forme['entite'],
                    'displayField' => 'nom',
                    'prefixe' => $type,
                ],
                'label' => $forme['label'],
                'icon' => $forme['icon'],
                'visibility_conditions' => self::visibiliteDuSelecteur($cleType, $type),
                'implique' => self::implicationDuSelecteur(
                    $cleType,
                    $type,
                    $libellesDesTypes[$type] ?? $type,
                ),
            ];
        }

        $options[] = ['value' => '', 'label' => 'Tous', 'icon' => 'action:filter'];

        return $options;
    }
}
