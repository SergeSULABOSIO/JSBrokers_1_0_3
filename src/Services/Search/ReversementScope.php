<?php

namespace App\Services\Search;

/**
 * Les filtres rapides de la rubrique « Rétros intermédiaires » — source UNIQUE
 * partagée par les chips de l'écran et par l'assistant.
 *
 * Quatre questions reviennent sur cette liste, et chacune a sa chip :
 *
 *  — QU'EST-CE QUI N'EST PAS JUSTIFIÉ ? La dette de preuve, rendue actionnable. C'est le
 *    pendant direct de la règle « pas de versement sans justificatif » : elle vaut à
 *    l'écriture, mais les versements enregistrés AVANT elle sont restés nus, et c'est ainsi
 *    qu'on les retrouve.
 *  — QU'AI-JE VERSÉ CE MOIS-CI ? Le rapprochement bancaire et la clôture.
 *  — QUELS VIREMENTS SOLDENT PLUSIEURS AFFAIRES ? Retrouver un lot à partir de l'une de ses
 *    lignes.
 *  — AGENT OU PARTENAIRE ? Les deux familles vivent sur le même enregistrement depuis que
 *    le partenaire est réglé en clair ; elles n'ont ni la même dette ni le même compte
 *    comptable, et ce chip est le seul moyen de lire l'une sans l'autre.
 *
 * Le BÉNÉFICIAIRE, lui, n'est pas ici : c'est une vraie relation (`agent`), filtrée par le
 * critère de relation ordinaire — le chip-sélecteur et le bouton du rapport de production
 * posent tous deux `agent = id`, la forme que le moteur de recherche connaît déjà.
 *
 * ── POURQUOI CETTE CLASSE PLUTÔT QUE DES CHAÎNES ÉPARSES ────────────────────────────
 * Le même vocabulaire doit piloter la chip, la traduction SQL et le paramètre de
 * `ouvrir_rubrique`. Trois copies auraient fini par désigner trois sous-ensembles, et
 * l'assistant aurait annoncé une liste que l'écran ne montrait pas — la contradiction que
 * `OuvrirRubriqueTool` a été écrit pour éliminer.
 */
final class ReversementScope
{
    /** L'entité gouvernée : hors d'elle, tous les critères d'ici sont ignorés. */
    public const ENTITE = 'ReversementRetroAgent';

    // ── Justificatif ────────────────────────────────────────────────────────────────
    public const CLE_JUSTIFICATIF = '__justificatif_reversement__';
    public const AVEC_PIECE = 'avec_piece';
    public const SANS_PIECE = 'sans_piece';

    // ── Période ─────────────────────────────────────────────────────────────────────
    public const CLE_PERIODE = '__periode_reversement__';
    public const CE_MOIS = 'ce_mois';
    public const TRENTE_JOURS = '30j';
    public const EXERCICE = 'exercice';

    // ── Virement : LA MAILLE DE LECTURE, ET RIEN D'AUTRE ────────────────────────────
    //
    // Ce chip ne restreint pas la liste — il choisit à quelle maille on la lit :
    //
    //   • GROUPÉ (défaut, valeur vide) : une ligne par VERSEMENT effectué au bénéficiaire ;
    //   • DÉTAIL : la même chose, ventilée par ÉCHÉANCE (tranche).
    //
    // Les DEUX modes portent le même argent, et leurs totaux sont donc identiques : c'est
    // la propriété qui les définit, et elle est vérifiée par un test plutôt que supposée.
    //
    // La base tient une ligne par échéance réglée : un virement qui en solde six y occupe
    // six lignes, portant six fois la même date, la même référence et le même compte. La
    // rubrique replie donc chaque lot sur son porteur, sauf sous DÉTAIL.
    //
    // ⚠ IL Y AVAIT ICI DEUX VALEURS DE PLUS — « groupe » et « isole » —, qui filtraient les
    // virements selon qu'ils couvrent une ou plusieurs échéances. Elles ont disparu : ce
    // n'est pas une maille, et deux notions dans un même chip se lisaient comme une seule.
    public const CLE_VIREMENT = '__virement_reversement__';
    public const DETAIL = 'detail';

    // ── Type de bénéficiaire ────────────────────────────────────────────────────────
    //
    // La rubrique porte désormais les DEUX familles d'intermédiaires sur le même
    // enregistrement : un agent interne (salarié, charge en 6611) ou un partenaire externe
    // (intermédiaire, charge en 632). Ce chip est le seul moyen de lire l'une sans l'autre.
    public const CLE_TYPE = '__type_beneficiaire__';
    public const TYPE_AGENT = 'agent';
    public const TYPE_PARTENAIRE = 'partenaire';

    // ── Bénéficiaire ────────────────────────────────────────────────────────────────
    //
    // DEUX COLONNES, UN SEUL FILTRE. Le bénéficiaire vit tantôt dans `agent`, tantôt dans
    // `partenaire` — le XOR de l'entité. Un critère ordinaire sur `agent` ne pouvait donc
    // désigner qu'une famille sur deux, et le chip de l'écran comme `ouvrir_rubrique`
    // seraient restés aveugles aux partenaires.
    //
    // La valeur porte la FAMILLE puis l'identifiant (« agent:12 », « partenaire:5 »), ce qui
    // permet à un chip unique de proposer les deux sélecteurs et à la traduction SQL de
    // viser la bonne colonne. La forme est celle des trois autres critères synthétiques de
    // cette rubrique : rien de nouveau à comprendre.
    public const CLE_BENEFICIAIRE = '__beneficiaire_reversement__';

    /**
     * L'ancien nom du critère : la COLONNE `agent`. Conservé parce que la recherche avancée
     * et la fiche l'exposent toujours comme un champ ordinaire — mais le CHIP, lui, ne
     * l'utilise plus.
     */
    public const CHAMP_BENEFICIAIRE = 'agent';

    /** La valeur d'un critère de bénéficiaire : « famille:id ». */
    public static function valeurBeneficiaire(string $type, int $id): string
    {
        return $type . ':' . $id;
    }

    /**
     * L'inverse : « agent:12 » => ['agent', 12], ou null si la valeur ne dit rien de bon.
     * Fail-closed : une valeur illisible retire le filtre plutôt que d'en inventer un.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function decoderBeneficiaire(?string $valeur): ?array
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
     * LA FAMILLE PORTÉE PAR UNE VALEUR DE BÉNÉFICIAIRE — « partenaire:5 » => 'partenaire'.
     *
     * Elle se déduit du décodage, jamais d'une seconde lecture du séparateur : deux façons
     * de lire une même valeur finissent par en désigner deux.
     */
    public static function familleDuBeneficiaire(?string $valeur): ?string
    {
        return self::decoderBeneficiaire($valeur)[0] ?? null;
    }

    /**
     * R1 — LA CONDITION DE PRÉSENCE d'un sélecteur de bénéficiaire.
     *
     * « Choisir un partenaire… » n'a rien à proposer quand le chip « Type » est sur Agent :
     * le filtre rendrait la liste NÉCESSAIREMENT vide (agent IS NOT NULL ET partenaire = 5
     * est impossible), et l'offrir est déjà un mensonge.
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
    public static function conditionsVisibiliteSelecteur(string $type): array
    {
        return [[
            'field' => self::CLE_TYPE,
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
    public static function implicationDuSelecteur(string $type): array
    {
        return [self::CLE_TYPE => [
            'value' => $type,
            'label' => self::libelle(self::CLE_TYPE, $type),
        ]];
    }

    /**
     * LE COUPLE (type, bénéficiaire) EST-IL TENABLE ?
     *
     * Un type qui exclut la famille du bénéficiaire nommé décrit un ensemble vide. À
     * l'écran, R1 et R2 rendent ce couple inatteignable ; ailleurs — l'assistant, une URL
     * fabriquée — il faut pouvoir le refuser en le nommant, plutôt que d'ouvrir une liste
     * vide dont personne ne saura dire la cause.
     */
    public static function beneficiaireCompatibleAvecType(?string $type, ?string $valeurBeneficiaire): bool
    {
        $famille = self::familleDuBeneficiaire($valeurBeneficiaire);

        return $type === null || $type === '' || $famille === null || $famille === $type;
    }

    /** La colonne de relation qui porte cette famille de bénéficiaire. */
    public static function colonneBeneficiaire(string $type): string
    {
        return $type === self::TYPE_PARTENAIRE ? 'partenaire' : 'agent';
    }

    /**
     * @var array<string, array<string, string>> clé de critère => valeur => libellé.
     *      Un seul tableau pour les chips, les libellés de badge et les énumérations du
     *      schéma de l'assistant : ajouter une valeur ici la rend disponible partout.
     */
    public const GROUPES = [
        self::CLE_JUSTIFICATIF => [
            self::AVEC_PIECE => 'Avec pièce',
            self::SANS_PIECE => 'Sans pièce',
        ],
        self::CLE_PERIODE => [
            self::CE_MOIS => 'Ce mois',
            self::TRENTE_JOURS => '30 derniers jours',
            self::EXERCICE => 'Cet exercice',
        ],
        // UNE SEULE VALEUR NOMMÉE : « Groupé » est le défaut, donc la valeur VIDE — il n'y
        // a pas de critère à poser pour lire une liste à sa maille naturelle.
        self::CLE_VIREMENT => [
            self::DETAIL => 'Détail par échéance',
        ],
        self::CLE_TYPE => [
            self::TYPE_AGENT => 'Agent',
            self::TYPE_PARTENAIRE => 'Partenaire',
        ],
    ];

    public static function estValide(string $cle, ?string $valeur): bool
    {
        return $valeur !== null && isset(self::GROUPES[$cle][$valeur]);
    }

    public static function libelle(string $cle, string $valeur): string
    {
        return self::GROUPES[$cle][$valeur] ?? $valeur;
    }

    /**
     * LA RUBRIQUE EST-ELLE REPLIÉE — une ligne par virement plutôt qu'une par échéance ?
     *
     * Oui par défaut, et pour toutes les valeurs du chip sauf DETAIL : « Groupé » et
     * « Isolé » restreignent l'ensemble des virements, ils ne le déplient pas. Un seul
     * endroit décide, lu par la requête comme par le schéma que l'assistant expose.
     *
     * @param array<string, mixed> $criteres les critères de la recherche en cours
     */
    public static function estReplie(array $criteres): bool
    {
        $valeur = $criteres[self::CLE_VIREMENT] ?? null;
        if (is_array($valeur)) {
            $valeur = $valeur['value'] ?? null;
        }

        return $valeur !== self::DETAIL;
    }

    /**
     * Le fragment DQL qui ne garde d'un lot que son PORTEUR.
     *
     * La règle du porteur — le plus petit id — est celle de `LotDeVersement::porteurParmi()`,
     * et elle ne se recopie pas : elle est simplement dite ici dans le dialecte de la
     * requête. On ne groupe PAS en SQL (`GROUP BY` casserait l'hydratation d'entités dont
     * dépendent la sélection, les actions et les documents) : on FILTRE.
     *
     * Un reversement isolé n'a pas de `lotReference` : il est toujours son propre porteur.
     */
    public static function dqlPorteurDuLot(string $alias): string
    {
        return "({$alias}.lotReference IS NULL OR {$alias}.lotReference = '' OR {$alias}.id = ("
            . "SELECT MIN(membre_lot.id) FROM " . \App\Entity\ReversementRetroAgent::class . " membre_lot"
            . " WHERE membre_lot.lotReference = {$alias}.lotReference"
            . " AND membre_lot.entreprise = {$alias}.entreprise))";
    }

    /**
     * Le fragment de critère pour un groupe donné, ou un tableau vide si la valeur est
     * absente ou inconnue (« Tous » : le filtre est simplement retiré).
     *
     * @return array<string, array{operator: string, value: string, label: string}>
     */
    public static function critereRecherche(string $entityShortName, string $cle, ?string $valeur): array
    {
        if ($entityShortName !== self::ENTITE || !self::estValide($cle, $valeur)) {
            return [];
        }

        return [$cle => [
            'operator' => '=',
            'value' => $valeur,
            'label' => self::libelle($cle, $valeur),
        ]];
    }

    /**
     * Les quatre critères d'un coup, tels que les reçoit `ouvrir_rubrique`.
     *
     * @return array<string, array{operator: string, value: string, label: string}>
     */
    public static function criteresDepuisArguments(string $entityShortName, array $args): array
    {
        return self::critereRecherche($entityShortName, self::CLE_JUSTIFICATIF, $args['justificatif'] ?? null)
            + self::critereRecherche($entityShortName, self::CLE_PERIODE, $args['periode'] ?? null)
            + self::critereRecherche($entityShortName, self::CLE_VIREMENT, $args['virement'] ?? null)
            + self::critereRecherche($entityShortName, self::CLE_TYPE, $args['type'] ?? null);
    }

    /**
     * Le critère de BÉNÉFICIAIRE, valable pour les DEUX familles.
     *
     * Il désignait la colonne `agent` en clair, ce qui rendait le filtre inapplicable à un
     * partenaire — dont les reversements vivent sur l'autre colonne du XOR. La valeur porte
     * désormais la famille, et c'est la traduction SQL qui choisit la colonne.
     *
     * @return array<string, array{operator: string, value: string, label: string}>
     */
    public static function critereBeneficiaire(int $id, string $nom, string $type = self::TYPE_AGENT): array
    {
        return [self::CLE_BENEFICIAIRE => [
            'operator' => '=',
            'value' => self::valeurBeneficiaire($type, $id),
            'label' => $nom,
        ]];
    }

    /**
     * Les bornes d'une période, ou null. Rendues ici pour que le filtre SQL et tout libellé
     * de rapprochement parlent des MÊMES dates.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    public static function bornes(string $valeur, ?\DateTimeImmutable $maintenant = null): ?array
    {
        $maintenant ??= new \DateTimeImmutable('now');

        return match ($valeur) {
            self::CE_MOIS => [
                $maintenant->modify('first day of this month')->setTime(0, 0),
                $maintenant->modify('last day of this month')->setTime(23, 59, 59),
            ],
            // Trente jours GLISSANTS, et non « le mois dernier » : c'est la question qu'on
            // pose après un virement (« qu'ai-je versé récemment ? »), pas une borne de mois.
            self::TRENTE_JOURS => [
                $maintenant->modify('-30 days')->setTime(0, 0),
                $maintenant->setTime(23, 59, 59),
            ],
            self::EXERCICE => [
                $maintenant->modify('first day of January')->setTime(0, 0),
                $maintenant->modify('last day of December')->setTime(23, 59, 59),
            ],
            default => null,
        };
    }

    /**
     * Le schéma d'une propriété d'outil, pour que l'assistant dispose EXACTEMENT des mêmes
     * valeurs que les chips.
     */
    public static function proprieteSchema(string $cle, string $note): array
    {
        return [
            'type' => 'string',
            'enum' => array_keys(self::GROUPES[$cle] ?? []),
            'description' => $note . ' Valeurs : '
                . implode(', ', array_map(
                    static fn (string $v, string $l) => sprintf('%s (%s)', $v, $l),
                    array_keys(self::GROUPES[$cle] ?? []),
                    array_values(self::GROUPES[$cle] ?? []),
                )) . '.',
        ];
    }

    /**
     * Les options d'un groupe, au format attendu par `filtres_predefinis`. L'option « Tous »
     * est ajoutée en fin : elle porte une valeur vide, ce que le cerveau lit comme « retire
     * ce critère » — pas comme un filtre de plus.
     *
     * @return array<int, array{value: string, label: string, icon: string}>
     */
    public static function optionsChips(string $cle, array $icones, string $libelleTous): array
    {
        $options = [];
        foreach (self::GROUPES[$cle] ?? [] as $valeur => $libelle) {
            $options[] = ['value' => $valeur, 'label' => $libelle, 'icon' => $icones[$valeur] ?? 'action:filter'];
        }
        $options[] = ['value' => '', 'label' => $libelleTous, 'icon' => 'action:filter'];

        return $options;
    }
}
