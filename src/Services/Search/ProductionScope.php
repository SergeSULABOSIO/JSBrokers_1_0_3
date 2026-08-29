<?php

namespace App\Services\Search;

/**
 * LE VOCABULAIRE DE LA RUBRIQUE « PRODUCTION INTERMÉDIAIRES ».
 *
 * Une source unique pour les trois surfaces qui doivent dire la même chose : les chips de
 * l'écran, la lecture des critères par le contrôleur, et le schéma que l'assistant expose.
 * Deux listes de valeurs finissent toujours par désigner deux sous-ensembles — c'est la
 * leçon que `ReversementScope` a déjà tirée, et cette rubrique en hérite la forme.
 *
 * ── CE QUI N'EST PAS D'ICI ──────────────────────────────────────────────────────────
 * Le STATUT (souscrites / en attente / caduques) vient de `CotationSouscriptionScope` : il
 * gouverne déjà la rubrique « Propositions » et l'ancien rapport, et le recopier aurait
 * fait deux partitions d'un même ensemble.
 *
 * Le couple TYPE ↔ BÉNÉFICIAIRE vient de `BeneficiaireCritere`, partagé avec les « Rétros
 * intermédiaires ». Ne restent ici que les CLÉS de critère — un nom par rubrique, sans quoi
 * un filtre posé sur l'une se serait appliqué à l'autre.
 *
 * ⚠ AUCUNE TRADUCTION SQL. Les affaires d'un intermédiaire sont choisies par le MOTEUR de
 * partage, cotation par cotation (`BeneficiaireRetro::cotations()`), et non par une requête.
 * Ce scope ne fait donc que NOMMER les critères ; c'est le contrôleur qui demande les lignes
 * au constructeur du rapport.
 */
final class ProductionScope
{
    /** La pseudo-entité gouvernée : hors d'elle, tous les critères d'ici sont ignorés. */
    public const ENTITE = 'ProductionIntermediaire';

    // ── Statut de souscription ──────────────────────────────────────────────────────
    //
    // À VALEUR UNIQUE, et c'est ce qui rend l'identité mixte des lignes praticable : sous
    // « Souscrites » toutes les lignes portent un avenant ; sous les deux autres, aucune
    // n'en a et toutes portent leur proposition. Une vue ne mélange jamais les deux natures,
    // donc l'identifiant d'une ligne reste un entier — la sélection et les totaux marchent
    // sans encodage, et « Ouvrir » sait quelle fiche ouvrir.
    public const CLE_STATUT = '__statut_production__';

    // ── Type d'intermédiaire ────────────────────────────────────────────────────────
    public const CLE_TYPE = '__type_production__';
    public const TYPE_AGENT = BeneficiaireCritere::TYPE_AGENT;
    public const TYPE_PARTENAIRE = BeneficiaireCritere::TYPE_PARTENAIRE;

    // ── Bénéficiaire nommé ──────────────────────────────────────────────────────────
    //
    // La valeur porte la FAMILLE puis l'identifiant (« agent:12 », « partenaire:5 ») : un
    // identifiant nu confondrait l'agent #12 avec le partenaire #12, et la production
    // rendue serait celle de quelqu'un d'autre.
    public const CLE_BENEFICIAIRE = '__beneficiaire_production__';

    /**
     * @var array<string, array<string, string>> clé de critère => valeur => libellé.
     *      Un seul tableau pour les chips, les badges et l'énumération du schéma de
     *      l'assistant : ajouter une valeur ici la rend disponible partout.
     */
    public const GROUPES = [
        self::CLE_STATUT => CotationSouscriptionScope::VALEURS,
        self::CLE_TYPE => [
            self::TYPE_AGENT => 'Agent',
            self::TYPE_PARTENAIRE => 'Partenaire',
        ],
    ];

    /** Le statut retenu quand rien n'est demandé — celui de l'ancien rapport. */
    public const STATUT_PAR_DEFAUT = CotationSouscriptionScope::STATUT_SOUSCRITES;

    public static function estValide(string $cle, ?string $valeur): bool
    {
        return $valeur !== null && isset(self::GROUPES[$cle][$valeur]);
    }

    public static function libelle(string $cle, string $valeur): string
    {
        return self::GROUPES[$cle][$valeur] ?? $valeur;
    }

    /** La valeur d'un critère de bénéficiaire : « famille:id ». */
    public static function valeurBeneficiaire(string $type, int $id): string
    {
        return BeneficiaireCritere::valeur($type, $id);
    }

    /**
     * Le bénéficiaire demandé, ou null. Fail-closed : une valeur illisible retire le
     * filtre plutôt que d'en inventer un — et la rubrique reste alors sans portée.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function decoderBeneficiaire(?string $valeur): ?array
    {
        return BeneficiaireCritere::decoder($valeur);
    }

    /** Le couple (type, bénéficiaire) est-il tenable ? Voir BeneficiaireCritere. */
    public static function beneficiaireCompatibleAvecType(?string $type, ?string $valeurBeneficiaire): bool
    {
        return BeneficiaireCritere::compatible($type, $valeurBeneficiaire);
    }

    /**
     * LE STATUT DEMANDÉ, TOUJOURS VALIDE.
     *
     * Une valeur inconnue retombe sur « Souscrites » plutôt que de vider l'écran : c'est
     * la partition entière, et un statut illisible n'est pas une demande de ne rien voir.
     *
     * @param array<string, mixed> $criteres
     */
    public static function statutDemande(array $criteres): string
    {
        $valeur = self::valeurDe($criteres, self::CLE_STATUT);

        return self::estValide(self::CLE_STATUT, $valeur) ? (string) $valeur : self::STATUT_PAR_DEFAUT;
    }

    /**
     * LA VALEUR D'UN CRITÈRE, quelle que soit sa forme.
     *
     * Les chips posent `{operator, value, label}`, l'assistant et les URL posent parfois la
     * valeur nue. Les deux formes traversent le même chemin — une seule lecture, donc une
     * seule interprétation.
     *
     * @param array<string, mixed> $criteres
     */
    public static function valeurDe(array $criteres, string $cle): ?string
    {
        $valeur = $criteres[$cle] ?? null;
        if (is_array($valeur)) {
            $valeur = $valeur['value'] ?? null;
        }

        return is_string($valeur) ? $valeur : null;
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
     * Le critère qui DÉSIGNE un bénéficiaire — celui que pose le chip-sélecteur, le clic
     * depuis une fiche, et l'assistant. Un seul constructeur pour les trois.
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
     * Les options d'un chip ordinaire, plus son « Tous ».
     *
     * @param array<string, string> $icones valeur => alias d'icône
     *
     * @return array<int, array<string, string>>
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

    /**
     * Les deux sélecteurs de bénéficiaire, avec leur visibilité et leur implication.
     * La mécanique est celle du socle ; seules les clés sont d'ici.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function optionsBeneficiaire(): array
    {
        return BeneficiaireCritere::optionsDuSelecteur(self::CLE_TYPE, self::GROUPES[self::CLE_TYPE]);
    }

    /**
     * La description d'une propriété du schéma de l'assistant : MÊME énumération que les
     * chips, par construction.
     *
     * @return array<string, mixed>
     */
    public static function proprieteSchema(string $cle, string $note): array
    {
        return [
            'type' => 'string',
            'enum' => array_keys(self::GROUPES[$cle]),
            'description' => $note,
        ];
    }
}
