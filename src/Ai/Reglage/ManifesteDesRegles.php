<?php

namespace App\Ai\Reglage;

/**
 * LES RÈGLES QUI TIENNENT KET, NUMÉROTÉES — et rien de plus.
 *
 * ── POURQUOI UN MANIFESTE, ET POURQUOI IL NE SERT QU'À LIRE ─────────────────
 * Ces règles n'avaient aucune identité. On en parlait en réunion par leur contenu
 * (« la règle du bénéficiaire unique », « celle qui dit que la prime n'est pas la
 * commission »), et deux personnes ne désignaient pas toujours la même. Leur donner
 * un numéro stable ne les change pas d'un octet : cela permet de les citer, de les
 * retrouver, et de constater qu'aucune n'a été ajoutée sans être dite.
 *
 * AUCUNE N'EST MODIFIABLE, et ce n'est pas une précaution — c'est leur nature :
 * une règle d'intégrité comptable, de cloisonnement ou de réglementation ne se règle
 * pas depuis un écran. Le manifeste est donc en lecture seule, définitivement.
 *
 * ── TROIS FAMILLES, ET DEUX PRÉFIXES DISTINCTS ──────────────────────────────
 * R — appliquées par le CODE. Un service en est la source unique, et elles valent
 *     aussi pour le formulaire du workspace : les enfreindre corrompt des données.
 * K — appliquées par le PROMPT. Elles régissent le DISCOURS de Ket : les enfreindre
 *     produit une réponse fausse, pas une donnée fausse.
 * B — la BOUSSOLE : ce que le cabinet poursuit, et l'ordre de la chaîne de valeur.
 *
 * ⚠ R ET K NE SE CONFONDENT PAS, d'où les deux lettres. R13 (« proposition sans
 * avenant = projet ») et K1 disent la même chose de deux côtés : R13 l'impose aux
 * CHIFFRES par un filtre SQL, K1 l'impose au DISCOURS par le prompt. Les fondre
 * ferait croire à un doublon, et l'un des deux finirait supprimé — on perdrait alors
 * soit l'exactitude des totaux, soit celle des phrases.
 *
 * ── LES SOURCES SONT VÉRIFIÉES ──────────────────────────────────────────────
 * Chaque entrée porte son fichier et sa ligne. `ManifesteDesReglesTest` relit les
 * fichiers : une source qui ne pointe plus la bonne chose fait échouer la suite —
 * sans quoi ce manifeste deviendrait, en quelques mois, une carte périmée.
 */
final class ManifesteDesRegles
{
    /**
     * Règles appliquées par le CODE. Source unique par règle, valables aussi pour le
     * formulaire du workspace.
     *
     * Colonnes : intitulé, service porteur (chemin), ancre vérifiable dans ce
     * fichier, risque encouru si la règle saute.
     *
     * @var array<string, array{titre: string, source: string, ancre: string, risque: string}>
     */
    public const CODE = [
        'R1' => [
            'titre'  => 'Réserve = commission pure − rétro partenaire − rétro agent',
            'source' => 'src/Service/Partage/Reserve.php',
            'ancre'  => 'function calculer',
            'risque' => 'Annoncer au cabinet un revenu qu’il n’a pas.',
        ],
        'R2' => [
            'titre'  => 'Exigibilité proportionnelle : min(1, encaissé ÷ dû)',
            'source' => 'src/Service/Partage/Exigibilite.php',
            'ancre'  => 'ratio',
            'risque' => 'Proposer de payer un intermédiaire avec de l’argent qui n’est pas rentré.',
        ],
        'R3' => [
            'titre'  => 'Un bénéficiaire par condition : un partenaire OU un agent, jamais les deux',
            'source' => 'src/Entity/ConditionPartage.php',
            'ancre'  => 'function estValide',
            'risque' => 'Une rétrocommission versée deux fois.',
        ],
        'R4' => [
            'titre'  => 'Un bénéficiaire par famille et par affaire ; retrait refusé après versement',
            'source' => 'src/Service/Partage/RattachementDuPartage.php',
            'ancre'  => 'par famille',
            'risque' => 'Réécrire une histoire comptable déjà soldée.',
        ],
        'R5' => [
            'titre'  => 'Les taux sont en POINTS : jamais multiplier une assiette par getTaux()',
            'source' => 'src/Entity/ConditionPartage.php',
            'ancre'  => 'function getFraction',
            'risque' => 'Des montants cent fois trop grands.',
        ],
        'R6' => [
            'titre'  => 'Commission exonérée si le client est exonéré OU le risque non imposable',
            'source' => 'src/Services/ServiceTaxes.php',
            'ancre'  => 'function commissionExoneree',
            'risque' => 'Une taxe facturée à tort, et une déclaration fausse.',
        ],
        'R7' => [
            'titre'  => 'Supprimer une piste n’emporte jamais la police',
            'source' => 'src/Service/Workspace/LiensProteges.php',
            'ancre'  => 'AVANT_SUPPRESSION',
            'risque' => 'Perdre le contrat, ses échéanciers et ses paiements.',
        ],
        'R8' => [
            'titre'  => 'Un seul mouvement par police : le sort scellé se refuse sec',
            'source' => 'src/Ai/Tool/PreparerMouvementAvenantTool.php',
            'ancre'  => 'idempotence',
            'risque' => 'Un double jeu d’écritures sur la même police.',
        ],
        'R9' => [
            'titre'  => 'La chaîne fait foi sur l’intention enregistrée',
            'source' => 'src/Services/AvenantRenouvellementResolver.php',
            'ancre'  => 'class AvenantRenouvellementResolver',
            'risque' => 'Déclarer perdue une police qui est reconduite.',
        ],
        'R10' => [
            'titre'  => 'Droits par entité et par niveau, fail-closed',
            'source' => 'src/Service/Workspace/WorkspaceAccessResolver.php',
            'ancre'  => 'function can',
            'risque' => 'Une fuite de données hors du périmètre de l’invité.',
        ],
        'R11' => [
            'titre'  => 'Cloisonnement entre cabinets : toute donnée porte son entreprise',
            'source' => 'src/Entity/Traits/AuditableTrait.php',
            'ancre'  => 'nullable: false',
            'risque' => 'Un courtier lisant le portefeuille d’un concurrent.',
        ],
        'R12' => [
            'titre'  => 'Une suppression bloquée rend un 409 qui explique, jamais un 500 muet',
            'source' => 'src/Controller/Admin/ControllerUtilsTrait.php',
            'ancre'  => 'function executerLaSuppression',
            'risque' => 'Une erreur que l’utilisateur ne peut ni comprendre ni contourner.',
        ],
        'R13' => [
            'titre'  => 'Proposition sans avenant = projet : ses montants ne comptent nulle part',
            'source' => 'src/Services/Canvas/Indicator/IndicatorCalculationHelper.php',
            'ancre'  => 'function isCotationBound',
            'risque' => 'Annoncer comme engagés les chiffres d’un projet.',
        ],
        'R14' => [
            'titre'  => 'Cohérence des dates d’avenant : seule l’inversion est refusée',
            'source' => 'src/Service/Workspace/ChampsObligatoiresInspector.php',
            'ancre'  => 'function incoherencesMetier',
            'risque' => 'Une police à durée négative enregistrée en silence.',
        ],
    ];

    /**
     * Règles de RESTITUTION : elles régissent le discours de Ket, pas les données.
     * Toutes vivent dans le prompt système, assemblées par `AiContextBuilder`.
     *
     * @var array<string, array{titre: string, source: string, ancre: string}>
     */
    public const RESTITUTION = [
        'K1'  => ['titre' => 'isBound : une cotation sans avenant n’est qu’un PROJET (pendant de R13, côté discours)', 'source' => 'src/Ai/AiContextBuilder.php', 'ancre' => 'RÈGLE isBound'],
        'K2'  => ['titre' => 'Proposition concurrente caduque : le marché attribué éteint les autres offres', 'source' => 'src/Ai/AiContextBuilder.php', 'ancre' => 'proposition concurrente caduque'],
        'K3'  => ['titre' => 'Renouvellement amorcé ≠ renouvelée : rien n’est acquis sans avenant successeur', 'source' => 'src/Ai/AiContextBuilder.php', 'ancre' => 'RENOUVELLEMENT AMORCÉ'],
        'K4'  => ['titre' => 'Non renouvelable ≠ soldée : tout ce qui reste dû reste à recouvrer', 'source' => 'src/Ai/AiContextBuilder.php', 'ancre' => 'NON RENOUVELABLE ≠ SOLDÉE'],
        'K5'  => ['titre' => 'Non renouvelable ≠ résiliée : la couverture en cours n’est pas interrompue', 'source' => 'src/Ai/AiContextBuilder.php', 'ancre' => 'NON RENOUVELABLE ≠ RÉSILIÉE'],
        'K6'  => ['titre' => 'Risque = couverture = type d’assurance = produit : c’est le catalogue du cabinet', 'source' => 'src/Ai/AiContextBuilder.php', 'ancre' => 'RISQUE = COUVERTURE'],
        'K7'  => ['titre' => 'Chiffre d’affaires = commissions réellement ENCAISSÉES', 'source' => 'src/Ai/AiContextBuilder.php', 'ancre' => 'CHIFFRE D\'AFFAIRES du courtier'],
        'K8'  => ['titre' => 'Commission générée ≠ exigible ≠ encaissée', 'source' => 'src/Ai/AiContextBuilder.php', 'ancre' => 'Commission EXIGIBLE'],
        'K9'  => ['titre' => 'La prime n’est pas la commission : deux dettes, deux débiteurs', 'source' => 'src/Ai/AiContextBuilder.php', 'ancre' => 'PRIME'],
        'K10' => ['titre' => 'Trois dettes, trois débiteurs : ne jamais les confondre dans un état', 'source' => 'src/Ai/AiContextBuilder.php', 'ancre' => 'TROIS DETTES'],
        'K11' => ['titre' => 'Un résultat vide SE DIT : l’absence n’est pas un zéro', 'source' => 'src/Ai/AiContextBuilder.php', 'ancre' => 'RÉSULTAT VIDE'],
        'K12' => ['titre' => 'N’invente jamais un solde', 'source' => 'src/Ai/AiContextBuilder.php', 'ancre' => 'N\'INVENTE JAMAIS UN SOLDE'],
        'K13' => ['titre' => 'Taxes : deux mondes distincts — sur la prime, et sur la commission', 'source' => 'src/Ai/AiContextBuilder.php', 'ancre' => 'DEUX MONDES TOTALEMENT DISTINCTS'],
        'K14' => ['titre' => 'N’invente jamais un taux de taxe', 'source' => 'src/Ai/AiContextBuilder.php', 'ancre' => 'taux de taxe'],
    ];

    /**
     * LA BOUSSOLE. Deux objectifs jumeaux, les dix maillons de la chaîne de valeur,
     * et le préalable de la configuration.
     *
     * ⚠ ELLE EST ÉCRITE À DEUX ENDROITS : la fiche complète
     * (`src/Ai/Guide/fiches/boussole-du-courtier.md`, servie à la demande par
     * `consulter_guide`) et sa version condensée (`AiContextBuilder::chaineDeValeur()`,
     * injectée à CHAQUE message). Rien ne les tient synchronisées — c'est la vraie
     * dette de la boussole, et l'écran de console allume un voyant quand les deux
     * cessent de concorder.
     *
     * @var array<string, array{titre: string, source: string}>
     */
    public const BOUSSOLE = [
        'B1'  => ['titre' => 'SATURER — cross-selling à 100 % : chaque client souscrit tous les risques du catalogue', 'source' => 'fiche + chaineDeValeur()'],
        'B2'  => ['titre' => 'La chaîne de valeur se surveille de bout en bout ; ne jamais sauter une marche', 'source' => 'fiche + chaineDeValeur()'],
        'B3'  => ['titre' => 'Proposition sans avenant = projet : aucun chiffre agrégé, aucun suivi, aucun impayé', 'source' => 'fiche'],
        'B4'  => ['titre' => 'Proportionnalité : 60 % encaissés ⇒ 60 % des taxes et des rétros exigibles', 'source' => 'fiche'],
        'B5'  => ['titre' => 'Vocabulaire non interchangeable : exigible / à recouvrer / encaissé / à reverser / réserve', 'source' => 'fiche'],
        'B6'  => ['titre' => 'Deux familles de bénéficiaires jamais confondues : partenaire externe (632), agent interne (6611)', 'source' => 'fiche'],
        'B7'  => ['titre' => 'Nommer ce qui manque, jamais le pourcentage ; ne le rappeler qu’au propriétaire', 'source' => 'fiche'],
        'B8'  => ['titre' => 'Rappel de boussole bref, un seul point, muet en saisie, en salutation et à l’oral', 'source' => 'AiContextBuilder::sectionBoussole()'],
        'B9'  => ['titre' => 'Jamais un chiffre inventé : les comptes viennent de la boussole, le détail d’un outil', 'source' => 'AiContextBuilder::sectionBoussole()'],
        'B10' => ['titre' => 'Ket ne dit jamais « je ne peux pas » — elle propose le chemin', 'source' => 'SelecteurDeTrousse'],
        'B11' => ['titre' => 'La sécurité vit dans l’outil, jamais dans le prompt : execute() re-vérifie, fail-closed', 'source' => 'AiToolInterface'],
        'B12' => ['titre' => 'Une règle vit à un seul endroit ; deux copies finissent par diverger', 'source' => 'Reserve, Exigibilite, ServiceTaxes, LiensProteges'],
        'B13' => ['titre' => 'Le travail d’un message = deux appels, un troisième qui se mérite', 'source' => 'OrchestrateurDeMessage, Phase'],
    ];

    /** Les trois familles, dans l'ordre où l'écran les présente. */
    public static function familles(): array
    {
        return [
            'code'        => ['libelle' => 'Règles appliquées par le code', 'entrees' => self::CODE],
            'restitution' => ['libelle' => 'Règles de restitution de Ket', 'entrees' => self::RESTITUTION],
            'boussole'    => ['libelle' => 'Boussole du courtier', 'entrees' => self::BOUSSOLE],
        ];
    }
}
