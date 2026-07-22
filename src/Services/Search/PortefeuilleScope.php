<?php

namespace App\Services\Search;

/**
 * Registre du « périmètre portefeuille » : pour chaque rubrique concernée, décrit le(s)
 * chemin(s) de relation menant au gestionnaire d'un portefeuille (Portefeuille.gestionnaire).
 *
 * Ce périmètre est appliqué par défaut au premier chargement de ces listes (cf.
 * ControllerUtilsTrait::getInitialSearchCriteria) : l'invité connecté ne voit d'abord que
 * les éléments rattachés — directement ou indirectement — à un portefeuille qu'il gère.
 *
 * Certaines entités (Tâche, Feedback) n'ont pas de lien unique : elles peuvent être reliées
 * à une Piste, une Cotation, un Sinistre… D'où une LISTE de chemins combinés en OU par le
 * moteur de recherche (JSBDynamicSearchService) : l'élément est visible si AU MOINS un de
 * ses parents renseignés relève d'un portefeuille géré par l'invité.
 */
final class PortefeuilleScope
{
    /**
     * Clé de critère synthétique portée par la barre de recherche (badge « Mon portefeuille »).
     * La valeur transmise est l'id de l'invité gestionnaire ; le moteur l'étend aux chemins
     * ci-dessous selon l'entité interrogée.
     */
    public const CRITERION_KEY = '__mon_portefeuille__';

    /**
     * Valeurs du périmètre de restitution des outils de l'assistant IA. Par défaut, Ket
     * répond dans le portefeuille de l'invité — EXACTEMENT ce que la rubrique affiche à
     * l'écran (le critère ci-dessus y est posé par défaut). L'élargissement à l'entreprise
     * entière reste possible, mais seulement sur demande explicite de l'utilisateur.
     */
    public const PERIMETRE_PORTEFEUILLE = 'mon_portefeuille';
    public const PERIMETRE_ENTREPRISE = 'entreprise';

    /** @var array<string, string> Valeur => libellé (enum des schémas d'outils IA). */
    public const PERIMETRES = [
        self::PERIMETRE_PORTEFEUILLE => 'Mon portefeuille',
        self::PERIMETRE_ENTREPRISE => "Toute l'entreprise",
    ];

    /**
     * Libellé du périmètre « entreprise entière », restitué par les outils IA lorsque
     * l'utilisateur a explicitement demandé de sortir de son portefeuille.
     */
    public const LIBELLE_ENTREPRISE = "toute l'entreprise";

    /**
     * @var array<string, string[]> Nom court d'entité => chemins de relation vers
     *      « …portefeuille.gestionnaire » (combinés en OU).
     */
    private const PATHS = [
        'Client' => ['portefeuille.gestionnaire'],
        'Piste' => ['client.portefeuille.gestionnaire'],
        'Cotation' => ['piste.client.portefeuille.gestionnaire'],
        'Avenant' => ['cotation.piste.client.portefeuille.gestionnaire'],
        'Tranche' => ['cotation.piste.client.portefeuille.gestionnaire'],
        // Sous-entité structurelle de la Tranche (signalement déclaratif du paiement de
        // la prime) : même périmètre que sa tranche, un cran plus loin. N'affecte PAS le
        // widget de collection du dialogue Tranche, exempté du filtre (usage 'dialog').
        'PaiementPrime' => ['tranche.cotation.piste.client.portefeuille.gestionnaire'],
        'NotificationSinistre' => ['assure.portefeuille.gestionnaire'],
        'OffreIndemnisationSinistre' => ['notificationSinistre.assure.portefeuille.gestionnaire'],
        'Tache' => [
            'piste.client.portefeuille.gestionnaire',
            'cotation.piste.client.portefeuille.gestionnaire',
            'notificationSinistre.assure.portefeuille.gestionnaire',
            'offreIndemnisationSinistre.notificationSinistre.assure.portefeuille.gestionnaire',
        ],
        'Feedback' => [
            'tache.piste.client.portefeuille.gestionnaire',
            'tache.cotation.piste.client.portefeuille.gestionnaire',
            'tache.notificationSinistre.assure.portefeuille.gestionnaire',
            'tache.offreIndemnisationSinistre.notificationSinistre.assure.portefeuille.gestionnaire',
        ],
    ];

    /**
     * Retourne les chemins de périmètre pour une entité (nom court), ou un tableau vide si
     * l'entité n'est pas concernée par ce filtre par défaut.
     *
     * @return string[]
     */
    public static function pathsFor(string $entityShortName): array
    {
        return self::PATHS[$entityShortName] ?? [];
    }

    /**
     * Indique si l'entité (nom court) est soumise au périmètre portefeuille.
     */
    public static function isScopable(string $entityShortName): bool
    {
        return isset(self::PATHS[$entityShortName]);
    }

    /**
     * Fragment de schéma JSON décrivant l'argument `perimetre` des outils de données de
     * l'assistant. Factorisé ici pour que les trois outils concernés (compter_entites,
     * rechercher_entites, suivi_impayes) décrivent au modèle EXACTEMENT la même règle.
     *
     * @return array{type: string, enum: string[], description: string}
     */
    public static function proprieteSchema(): array
    {
        return [
            'type' => 'string',
            'enum' => array_keys(self::PERIMETRES),
            'description' => 'Périmètre des données. Par défaut (' . self::PERIMETRE_PORTEFEUILLE
                . '), les résultats sont restreints aux enregistrements rattachés au portefeuille '
                . "géré par l'utilisateur — EXACTEMENT ce que la rubrique affiche à l'écran, filtres "
                . "rapides compris. N'utiliser « " . self::PERIMETRE_ENTREPRISE . " » que si "
                . "l'utilisateur demande EXPLICITEMENT l'ensemble de l'entreprise, tous les "
                . 'portefeuilles ou tous les collaborateurs.',
        ];
    }

    /**
     * L'argument `perimetre` demande-t-il l'entreprise entière ? Toute autre valeur (absente,
     * vide ou inconnue) retombe sur le défaut : le portefeuille de l'invité.
     */
    public static function estEntreprise(?string $perimetre): bool
    {
        return $perimetre === self::PERIMETRE_ENTREPRISE;
    }

    /**
     * Libellé du périmètre effectivement appliqué, restitué au modèle pour qu'il l'annonce
     * dans sa réponse. `null` quand la notion ne s'applique pas (entité non scopable : le
     * résultat couvre alors naturellement toute l'entreprise, sans ambiguïté à lever).
     *
     * @param array<string, array{label?: string}> $critereApplique Retour de PortefeuilleCritereFactory::pour()
     */
    public static function libellePerimetre(bool $elargiEntreprise, array $critereApplique): ?string
    {
        if ($elargiEntreprise) {
            return self::LIBELLE_ENTREPRISE;
        }

        return $critereApplique[self::CRITERION_KEY]['label'] ?? null;
    }

    /**
     * Détecte une demande EXPLICITE d'élargissement à l'entreprise entière dans une question
     * en langage naturel déjà normalisée (AiText::normalize : minuscules, sans accents — la
     * ponctuation, elle, est CONSERVÉE). Miroir de AvenantEcheanceScope::detecterDepuisTexte
     * pour le moteur simulé. Retourne null quand rien n'est exprimé : le défaut (portefeuille
     * de l'invité) s'applique alors, comme à l'écran.
     */
    public static function detecterPerimetreDepuisTexte(string $texteNormalise): ?string
    {
        $motifs = [
            '/\btout(?:e|es)? (?:l\'|la |le )?(?:entreprise|societe|cabinet|boite)\b/',
            '/\b(?:pour|dans|sur) (?:l\'|la )?(?:entreprise|societe|cabinet) (?:entiere|complete|globale)\b/',
            '/\btous les portefeuilles\b|\bl\'ensemble des portefeuilles\b/',
            '/\b(?:hors|au[- ]dela de) mon portefeuille\b/',
            '/\b(?:tous|toutes) (?:les )?(?:collaborateurs|gestionnaires|agents)\b/',
            '/\bglobalement\b|\bau (?:niveau|total) (?:de l\'|du )?(?:entreprise|cabinet)\b/',
        ];

        foreach ($motifs as $motif) {
            if (preg_match($motif, $texteNormalise)) {
                return self::PERIMETRE_ENTREPRISE;
            }
        }

        return null;
    }
}
