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

    // ── Virement ────────────────────────────────────────────────────────────────────
    public const CLE_VIREMENT = '__virement_reversement__';
    public const GROUPE = 'groupe';
    public const ISOLE = 'isole';

    // ── Type de bénéficiaire ────────────────────────────────────────────────────────
    //
    // La rubrique porte désormais les DEUX familles d'intermédiaires sur le même
    // enregistrement : un agent interne (salarié, charge en 6611) ou un partenaire externe
    // (intermédiaire, charge en 632). Ce chip est le seul moyen de lire l'une sans l'autre.
    public const CLE_TYPE = '__type_beneficiaire__';
    public const TYPE_AGENT = 'agent';
    public const TYPE_PARTENAIRE = 'partenaire';

    /** Le champ de relation du bénéficiaire : un critère ORDINAIRE, pas un synthétique. */
    public const CHAMP_BENEFICIAIRE = 'agent';

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
        self::CLE_VIREMENT => [
            self::GROUPE => 'Groupé',
            self::ISOLE => 'Isolé',
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
     * Le critère de BÉNÉFICIAIRE — une relation, donc la forme ordinaire d'un filtre par
     * identité, celle que produit déjà `lieA` pour les autres rubriques.
     *
     * @return array<string, array{operator: string, value: int, label: string}>
     */
    public static function critereBeneficiaire(int $agentId, string $nom): array
    {
        return [self::CHAMP_BENEFICIAIRE => [
            'operator' => '=',
            'value' => $agentId,
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
