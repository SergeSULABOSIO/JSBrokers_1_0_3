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
     * Libellés du périmètre d'un invité qui ne gère AUCUN portefeuille, quand l'entité suit
     * (au moins en partie) le responsable de la piste : il y voit encore ce dont il est
     * responsable — les pistes qu'il a créées pour un client sans gestionnaire, et ce qui en
     * descend. Ket les reconnaît pour expliquer une liste vide (AiContextBuilder).
     */
    public const LIBELLE_PISTES_RESPONSABLE = 'les pistes dont vous êtes responsable';
    public const LIBELLE_AFFAIRES_RESPONSABLE = 'les affaires dont vous êtes responsable';
    public const LIBELLE_AUCUN_PORTEFEUILLE = 'aucun portefeuille';

    /**
     * LES CHEMINS QUI SUIVENT LE CLIENT : vers « …portefeuille.gestionnaire », combinés en OU.
     *
     * Ce qui ne dépend que du CLIENT (le client lui-même, ses sinistres, un document qui lui
     * est rattaché directement) appartient au gestionnaire de son portefeuille. Ce qui
     * descend d'une PISTE suit une autre règle : {@see CHEMINS_VERS_LA_PISTE}.
     *
     * @var array<string, string[]> Nom court d'entité => chemins de relation vers
     *      « …portefeuille.gestionnaire » (combinés en OU).
     */
    private const PATHS = [
        'Client' => ['portefeuille.gestionnaire'],
        'NotificationSinistre' => ['assure.portefeuille.gestionnaire'],
        'OffreIndemnisationSinistre' => ['notificationSinistre.assure.portefeuille.gestionnaire'],
        'Tache' => [
            'notificationSinistre.assure.portefeuille.gestionnaire',
            'offreIndemnisationSinistre.notificationSinistre.assure.portefeuille.gestionnaire',
        ],
        'Feedback' => [
            'tache.notificationSinistre.assure.portefeuille.gestionnaire',
            'tache.offreIndemnisationSinistre.notificationSinistre.assure.portefeuille.gestionnaire',
        ],
        /**
         * LES DOCUMENTS SUIVENT LE DOSSIER AUQUEL ILS APPARTIENNENT.
         *
         * Document n'était soumis à aucun périmètre : la rubrique — et Ket avec elle —
         * montrait à chaque invité les pièces de TOUS les clients de l'entreprise, y
         * compris ceux d'un autre gestionnaire. « Les fichiers de mon portefeuille » ne
         * voulait donc rien dire.
         *
         * Un document n'a qu'un seul parent renseigné parmi une quinzaine : les chemins
         * couvrent ceux qui mènent à un client, chacun essayé en OU. Les parents SANS
         * client (bordereau, fournisseur, compte bancaire, partenaire, classeur) n'en
         * ont aucun — c'est le rôle de {@see ORPHELINS_TOLERES} de les garder visibles
         * plutôt que de les faire disparaître de l'écran.
         */
        'Document' => [
            'client.portefeuille.gestionnaire',
            'pieceSinistre.notificationSinistre.assure.portefeuille.gestionnaire',
            'offreIndemnisationSinistre.notificationSinistre.assure.portefeuille.gestionnaire',
            'tache.notificationSinistre.assure.portefeuille.gestionnaire',
        ],
    ];

    /**
     * CE QUI DESCEND D'UNE PISTE SUIT LE RESPONSABLE DE LA PISTE (décision du 2026-10-14).
     *
     * Une piste est à MOI quand j'en suis le RESPONSABLE : le gestionnaire du portefeuille de
     * son client, sinon son créateur ({@see ResponsableDePiste}). Ses cotations, ses polices,
     * ses tranches, les tâches et les documents qui lui sont rattachés la suivent : avant,
     * ils ne suivaient que le gestionnaire, et une piste montée pour un client sans
     * portefeuille était dans « Mon portefeuille » de son créateur… sans sa cotation ni sa
     * police.
     *
     * Chaque chemin mène à la PISTE ; '' désigne l'entité elle-même (la Piste). Les chemins
     * d'une entité se combinent en OU avec ceux de {@see PATHS}.
     *
     * Cette règle ne fait qu'AJOUTER : « le gestionnaire, ou à défaut le créateur » contient
     * « le gestionnaire ». Personne ne perd ce qu'il voyait.
     *
     * @var array<string, string[]>
     */
    private const CHEMINS_VERS_LA_PISTE = [
        'Piste' => [''],
        'Cotation' => ['piste'],
        'Avenant' => ['cotation.piste'],
        'Tranche' => ['cotation.piste'],
        // Sous-entité structurelle de la Tranche (signalement déclaratif du paiement de
        // la prime) : même périmètre que sa tranche, un cran plus loin. N'affecte PAS le
        // widget de collection du dialogue Tranche, exempté du filtre (usage 'dialog').
        'PaiementPrime' => ['tranche.cotation.piste'],
        'Tache' => ['piste', 'cotation.piste'],
        'Feedback' => ['tache.piste', 'tache.cotation.piste'],
        'Document' => [
            'piste',
            'cotation.piste',
            'avenant.cotation.piste',
            'paiementPrime.tranche.cotation.piste',
            'tache.piste',
            'tache.cotation.piste',
            'feedback.tache.piste',
        ],
    ];

    /**
     * Les chemins de l'entité qui mènent à une PISTE ('' = l'entité est la piste), à
     * soumettre à la règle du responsable ({@see ResponsableDePiste}).
     *
     * @return list<string>
     */
    public static function cheminsVersLaPiste(string $entityShortName): array
    {
        return self::CHEMINS_VERS_LA_PISTE[$entityShortName] ?? [];
    }

    /** Une partie au moins du périmètre de cette entité suit-elle le responsable de la piste ? */
    public static function suitLeResponsableDePiste(string $entityShortName): bool
    {
        return isset(self::CHEMINS_VERS_LA_PISTE[$entityShortName]);
    }

    /**
     * Entités dont un enregistrement N'ATTEIGNANT AUCUN portefeuille reste VISIBLE.
     *
     * POURQUOI CETTE EXCEPTION EXISTE, ET POURQUOI ELLE NE VAUT QUE POUR LES DOCUMENTS.
     * Les autres entités scopées appartiennent toutes, par construction, à un client :
     * une cotation sans client n'existe pas. Un document, si — celui d'un bordereau,
     * d'un fournisseur, d'un compte bancaire, ou rangé dans un simple classeur. Appliquer
     * la règle stricte les ferait disparaître de la rubrique sans que personne ne
     * comprenne pourquoi, alors qu'ils n'appartiennent au portefeuille de PERSONNE.
     *
     * Le filtre garde donc son objet — écarter les pièces des clients d'un AUTRE
     * gestionnaire — sans masquer ce qui est commun à l'entreprise.
     *
     * Conséquence assumée : un document rattaché à un parent dont le chemin n'est pas
     * déclaré ci-dessus est traité comme orphelin, donc visible. C'est la bonne
     * direction de défaut — le périmètre portefeuille est un CONFORT DE LECTURE, pas une
     * frontière de sécurité ; celle-ci est le scoping entreprise, qui s'applique
     * toujours et n'a pas d'exception.
     *
     * @var list<string>
     */
    public const ORPHELINS_TOLERES = ['Document'];

    /** Un enregistrement de cette entité sans aucun portefeuille reste-t-il visible ? */
    public static function tolereLesOrphelins(string $entityShortName): bool
    {
        return \in_array($entityShortName, self::ORPHELINS_TOLERES, true);
    }

    /**
     * Le suffixe commun à TOUS les chemins ci-dessus : ce qui mène du client à son
     * gestionnaire. Le retirer d'un chemin de périmètre donne un chemin vers le CLIENT.
     */
    private const SUFFIXE_GESTIONNAIRE = '.portefeuille.gestionnaire';

    /**
     * LES CHEMINS QUI MÈNENT AU CLIENT, dérivés de ceux qui mènent au gestionnaire.
     *
     * POURQUOI ILS SONT DÉRIVÉS ET NON ÉCRITS. Savoir de quel client relève un
     * enregistrement était déjà nécessaire ailleurs, et cette connaissance existait en
     * TROIS versions désaccordées : onze chemins ici, quatre dans
     * {@see \App\Service\Soa\SoaPoliceDocumentsCollector::documentAppartientAuClient()},
     * et une dérivation Doctrine bornée à trois segments dans
     * {@see \App\Ai\Resolution\CheminsDeRelation} — trop courte pour
     * `avenant.cotation.piste.client`, qui en compte quatre. Trois vérités pour une
     * question, donc au moins deux qui se trompent : le rangement automatique des
     * documents aurait manqué des clients selon le chemin emprunté par la pièce.
     *
     * La liste la plus complète est celle-ci, parce que c'est elle qui gouverne ce que
     * l'écran affiche et qu'un oubli s'y voit tout de suite. On en dérive plutôt que de
     * la recopier : ajouter un chemin de périmètre suffit désormais à ce que le
     * rangement en tienne compte.
     *
     * @return list<string> chemins de relations vers un Client, à essayer dans l'ordre
     */
    public static function cheminsVersLeClient(string $entityShortName): array
    {
        $chemins = [];
        // Ce qui mène à une piste mène à son client, un segment plus loin.
        foreach (self::cheminsVersLaPiste($entityShortName) as $chemin) {
            $chemins[$chemin === '' ? 'client' : $chemin . '.client'] = true;
        }
        foreach (self::pathsFor($entityShortName) as $chemin) {
            if (!str_ends_with($chemin, self::SUFFIXE_GESTIONNAIRE)) {
                // Un chemin qui n'atteint pas le gestionnaire par ce suffixe n'est pas
                // tronquable en chemin vers un client : on l'écarte plutôt que d'en
                // fabriquer un faux, qui rendrait silencieusement le mauvais client.
                continue;
            }
            $chemins[substr($chemin, 0, -\strlen(self::SUFFIXE_GESTIONNAIRE))] = true;
        }

        return array_keys($chemins);
    }

    /**
     * Les relations DIRECTES de l'entité qui mènent à un portefeuille — les premiers
     * segments des chemins, dédoublonnés.
     *
     * Elles servent à reconnaître un orphelin : un enregistrement dont AUCUNE de ces
     * relations n'est renseignée n'appartient à aucun portefeuille. La liste est dérivée
     * des chemins eux-mêmes, jamais réécrite : ajouter un chemin suffit.
     *
     * @return list<string>
     */
    public static function relationsDirectes(string $entityShortName): array
    {
        $premiers = [];
        foreach ([...self::cheminsVersLaPiste($entityShortName), ...self::pathsFor($entityShortName)] as $chemin) {
            if ($chemin !== '') {
                $premiers[strtok($chemin, '.')] = true;
            }
        }

        return array_keys($premiers);
    }

    /**
     * Les chemins de l'entité qui suivent le CLIENT (vers « …portefeuille.gestionnaire »),
     * ou un tableau vide. ⚠ Ce n'est qu'UNE des deux familles : ce qui descend d'une piste
     * est dans {@see cheminsVersLaPiste()}.
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
        return isset(self::PATHS[$entityShortName]) || isset(self::CHEMINS_VERS_LA_PISTE[$entityShortName]);
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
