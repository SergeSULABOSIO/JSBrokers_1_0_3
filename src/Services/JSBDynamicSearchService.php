<?php
namespace App\Services;



use App\Entity\Entreprise;
use App\Entity\ReversementRetroAgent;
use App\Services\Search\AvenantEcheanceScope;
use App\Services\Search\AvenantSuccessionScope;
use App\Services\Search\CotationSouscriptionScope;
use App\Services\Search\ReversementScope;
use App\Services\Search\PisteTransformationScope;
use App\Services\Search\PortefeuilleScope;
use App\Services\Search\TranchePaiementScope;
use App\Service\Retro\LotDeVersement;
use App\Services\Tranche\TranchePaiementService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\EntityManagerInterface;

class JSBDynamicSearchService
{
    /**
     * Critère synthétique « lien multi-chemins » : restreint aux enregistrements reliés à une
     * fiche par AU MOINS un chemin de relations to-one (OR). Posé par rechercher_entites (lieA)
     * quand plusieurs chemins mènent à l'entité de rattachement — le plus court n'étant pas
     * toujours le bon (ex. Avenant → Client via pisteDeRenouvellement.client, souvent nul, OU via
     * cotation.piste.client, le vrai lien). Valeur : ['paths' => string[], 'id' => int].
     */
    public const LIEN_MULTI_CHEMINS = '__lien_multi_chemins__';

    /**
     * Critère synthétique « un même texte, plusieurs colonnes, en OU ».
     *
     * Les critères ordinaires se combinent en ET : poser `nom` LIKE x et
     * `nomFichierStocke` LIKE x exigerait que les DEUX correspondent, ce qui n'arrive
     * jamais. Or une même chose peut porter deux noms sans que l'utilisateur ait à
     * savoir lequel il cite — un document a un libellé de fiche ET un nom de fichier,
     * et « retrouve-moi CONTRAT-2026 » désigne indifféremment l'un ou l'autre.
     *
     * Valeur : ['champs' => string[], 'valeur' => string]. Les champs inexistants sur
     * l'entité sont ignorés (fail-open : mieux vaut chercher sur ce qui existe que ne
     * rien chercher du tout).
     */
    public const OU_TEXTE_LIBRE = '__ou_texte_libre__';

    private EntityManagerInterface $em;
    private LoggerInterface $logger;

    /**
     * @var string[] Liste blanche des entités autorisées pour la recherche.
     * C'est ici que vous centraliserez la gestion des entités consultables.
     */
    public static array $allowedEntities = [
        'Assureur',
        'AutoriteFiscale',
        'Avenant',
        'Bordereau',
        'Chargement',
        'ChargementPourPrime',
        'ChargeCourtier',
        'Classeur',
        'Client',
        'CompteBancaire',
        'ConditionPartage',
        'Contact',
        'Cotation',
        'DemandeConge',
        'DepenseCourtier',
        'Document',
        'Entreprise',
        'Feedback',
        'Fournisseur',
        'Groupe',
        'HistoriqueDemande',
        'Invite',
        'JourFerie',
        'ModelePieceSinistre',
        'Monnaie',
        'Note',
        'NotificationSinistre',
        'OffreIndemnisationSinistre',
        'Paiement',
        'PaiementPrime',
        'Partenaire',
        'PieceSinistre',
        'Piste',
        'Portefeuille',
        'ReversementRetroAgent',
        'RevenuPourCourtier',
        'Risque',
        'Tache',
        'Taxe',
        'Tranche',
        'TypeAbsence',
        'TypeRevenu',
    ];

    /**
     * @var string[] Liste blanche des opérateurs de comparaison autorisés.
     * NB : la recherche par plage passe par le format { from, to } (CAS 1), il n'y a
     * donc pas d'opérateur BETWEEN à gérer ici.
     */
    private array $allowedOperators = ['=', '!=', '<', '<=', '>', '>=', 'LIKE'];

    /**
     * Le service a besoin de l'EntityManager de Doctrine pour fonctionner.
     * Symfony l'injectera automatiquement ici.
     */
    public function __construct(
        EntityManagerInterface $em,
        private readonly TranchePaiementService $tranchePaiement,
        // La maille de lecture des reversements se déclare ici et se lit ailleurs : voir
        // le repli, plus bas.
        private readonly LotDeVersement $lotDeVersement,
        ?LoggerInterface $logger = null,
    ) {
        $this->em = $em;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * La logique de votre ancienne fonction "chercher".
     * Elle ne dépend plus de l'objet Request, mais d'un simple tableau.
     */
    public function search(string $entityClass, array $criteria, Entreprise $entreprise, ?array $parentContext = null, int $page = 1, int $limit = 20): array
    {
        $results = [];
        $status = [
            "error" => null,
            "code" => 200,
            "message" => "Requête exécutée avec succès."
        ];
        $totalItems = 0;

        // Note : le code ci-dessous est votre logique, simplement copiée ici.
        // Les références à "$this->" pointent maintenant vers les propriétés du service.
        $entityName = (new \ReflectionClass($entityClass))->getShortName();

        if (!in_array($entityName, self::$allowedEntities, true)) {
            $status = [
                "error" => "Entité non autorisée.",
                "code" => 403,
                "message" => "L'interrogation de l'entité '{$entityName}' n'est pas autorisée."
            ];
            return ['status' => $status, 'data' => [], 'totalItems' => 0];
        }

        // Critères synthétiques « Paiement » (Tranche uniquement) : les soldes de prime, de
        // commission et de rétro sont dérivés à la volée (jamais stockés), impossibles à
        // filtrer/trier en SQL. On retire TOUTES les clés d'axe des critères, on charge
        // l'ensemble scopé (entreprise + autres critères) SANS pagination ni tri id, puis
        // TranchePaiementService filtre par la COMBINAISON d'axes (cumul en ET), trie par
        // urgence et pagine en mémoire. Forme de retour identique à ce chemin-ci.
        if ($entityName === 'Tranche' && TranchePaiementScope::porteUnAxe($criteria)) {
            $axes = TranchePaiementScope::extraireAxes($criteria);
            $criteria = TranchePaiementScope::retirerAxes($criteria);

            if ($axes !== []) {
                try {
                    $qb = $this->em->getRepository($entityClass)->createQueryBuilder('e');
                    $this->applyCriteriaToQueryBuilder($qb, $criteria, $entreprise, $parentContext, $status);
                    if ($status['error'] !== null) {
                        return ['status' => $status, 'data' => [], 'totalItems' => 0];
                    }

                    return $this->tranchePaiement->filtrerTrierPaginer($qb->getQuery()->getResult(), $axes, $page, $limit);
                } catch (\Exception $e) {
                    return [
                        'status' => [
                            'error' => 'Une erreur inattendue est survenue: ' . $e->getMessage(),
                            'code' => 500,
                            'message' => 'Erreur interne du serveur.',
                        ],
                        'data' => [], 'totalItems' => 0, 'currentPage' => $page,
                        'totalPages' => 1, 'itemsPerPage' => $limit,
                    ];
                }
            }
            // Axes vides ou inconnus : clés déjà retirées, la recherche standard reprend.
        }

        // Critère synthétique « Échéance » (Avenant uniquement). À la différence de Tranche,
        // l'échéance est une VRAIE colonne (Avenant.endingAt) : filtrage + tri par urgence
        // directement en SQL (pas de service en mémoire). On retire la clé, on applique les
        // autres critères (scope entreprise + « Mon portefeuille »), on borne endingAt selon
        // le statut, on trie du plus proche (le plus urgent) au plus lointain, on pagine.
        if ($entityName === 'Avenant' && array_key_exists(AvenantEcheanceScope::CRITERION_KEY, $criteria)) {
            $raw = $criteria[AvenantEcheanceScope::CRITERION_KEY];
            $statutEcheance = is_array($raw) ? (string) ($raw['value'] ?? '') : (string) $raw;
            unset($criteria[AvenantEcheanceScope::CRITERION_KEY]);

            if (AvenantEcheanceScope::estValide($statutEcheance)) {
                try {
                    $bornes = AvenantEcheanceScope::bornes($statutEcheance, new \DateTimeImmutable('today'));

                    $qb = $this->em->getRepository($entityClass)->createQueryBuilder('e');
                    $this->applyCriteriaToQueryBuilder($qb, $criteria, $entreprise, $parentContext, $status);
                    if ($status['error'] !== null) {
                        return ['status' => $status, 'data' => [], 'totalItems' => 0];
                    }

                    $countQb = $this->em->getRepository($entityClass)->createQueryBuilder('e_count');
                    $this->applyCriteriaToQueryBuilder($countQb, $criteria, $entreprise, $parentContext, $status, '_count');

                    // Bornes de la fenêtre d'échéance (convention [min, max[ à minuit).
                    if ($bornes['min'] !== null) {
                        $qb->andWhere('e.endingAt >= :echMin')->setParameter('echMin', $bornes['min']);
                        $countQb->andWhere('e_count.endingAt >= :echMin_count')->setParameter('echMin_count', $bornes['min']);
                    }
                    if ($bornes['max'] !== null) {
                        $qb->andWhere('e.endingAt < :echMax')->setParameter('echMax', $bornes['max']);
                        $countQb->andWhere('e_count.endingAt < :echMax_count')->setParameter('echMax_count', $bornes['max']);
                    }

                    // DEUX RÔLES OPPOSÉS POUR UN MÊME CRITÈRE.
                    //
                    // Les QUATRE fenêtres de dates APPLIQUENT la règle du pipeline : une police
                    // dont le sort est SCELLÉ n'a plus rien à réclamer et en sort. Reprise par
                    // un avenant successeur, la couverture continue sous lui — l'assuré EST
                    // couvert, la finalité est atteinte ; résiliée, la décision est prise ;
                    // signalée non renouvelable, le courtier a tranché. Restent celles qui
                    // appellent une action : renouvellement amorcé sans avenant, ou aucune suite.
                    //
                    // Le groupe « Non renouvelables » l'INVERSE : il rassemble précisément ce
                    // que les autres écartent. Lui appliquer l'exclusion le rendrait VIDE par
                    // construction — et cette page vide n'aurait dit à personne qu'il ne
                    // s'agissait pas d'une absence de données.
                    //
                    // Dans les deux cas, appliqué aux DEUX requêtes : un comptage qui ne filtre
                    // pas comme la liste fait mentir la pagination.
                    $etatNonRenouvelable = AvenantEcheanceScope::estEtatNonRenouvelable($statutEcheance);
                    foreach ([[$qb, 'e', ''], [$countQb, 'e_count', '_count']] as [$builder, $alias, $suffixe]) {
                        if ($etatNonRenouvelable) {
                            $builder->andWhere(sprintf('%s.nonRenouvelable = true', $alias));
                            continue;
                        }
                        $builder
                            ->andWhere(AvenantSuccessionScope::predicatSortNonScelle($this->em, $alias, $suffixe))
                            ->setParameter(
                                AvenantSuccessionScope::parametreMouvementsScellants($suffixe),
                                AvenantSuccessionScope::MOUVEMENTS_SCELLANTS
                            );
                    }

                    // Tri par urgence : échéance la plus proche (ou le retard le plus ancien) en
                    // tête. Vaut aussi pour les non renouvelables : à défaut d'urgence, l'ordre
                    // chronologique reste le plus lisible.
                    $qb->orderBy('e.endingAt', 'ASC');
                    $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
                    $results = $qb->getQuery()->getResult();

                    $identifierField = $this->em->getRepository($entityClass)->getClassMetadata()->getSingleIdentifierFieldName();
                    $countQb->select("COUNT(DISTINCT e_count.{$identifierField})")->setMaxResults(null)->setFirstResult(null);
                    $totalItems = (int) $countQb->getQuery()->getSingleScalarResult();

                    return [
                        'status' => ['error' => null, 'code' => 200, 'message' => 'Requête de filtre exécutée avec succès.'],
                        'data' => $results,
                        'totalItems' => $totalItems,
                        'currentPage' => $page,
                        'totalPages' => max(1, (int) ceil($totalItems / $limit)),
                        'itemsPerPage' => $limit,
                    ];
                } catch (\Exception $e) {
                    return [
                        'status' => [
                            'error' => 'Une erreur inattendue est survenue: ' . $e->getMessage(),
                            'code' => 500,
                            'message' => 'Erreur interne du serveur.',
                        ],
                        'data' => [], 'totalItems' => 0, 'currentPage' => $page,
                        'totalPages' => 1, 'itemsPerPage' => $limit,
                    ];
                }
            }
            // Statut vide ou inconnu : critère déjà retiré, la recherche standard reprend.
        }

        try {
            // Obtenir le repository de l'entité demandée pour construire la requête.
            // Le chemin complet de la classe est 'App\\Entity\\NomDeLEntite'.
            $repository = $this->em->getRepository($entityClass);
            // 'e' est l'alias de notre entité principale dans la requête DQL (ex: SELECT e FROM App\Entity\NotificationSinistre e)
            $qb = $repository->createQueryBuilder('e');

            // AMÉLIORATION : On applique les filtres en utilisant la nouvelle méthode factorisée.
            $this->applyCriteriaToQueryBuilder($qb, $criteria, $entreprise, $parentContext, $status);

            // MISSION 2 : Trier les résultats par ID décroissant pour afficher les plus récents en premier.
            $qb->orderBy('e.id', 'DESC');

            // Pagination : on applique l'offset et la limite avant d'exécuter la requête.
            $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);

            // Exécuter la requête pour obtenir les résultats (objets Doctrine)
            $results = $qb->getQuery()->getResult(); // Renommé pour clarté

            // --- AMÉLIORATION : Logique de comptage simplifiée ---
            $totalItemsQb = $repository->createQueryBuilder('e_count'); // Alias différent pour la requête de comptage
            $this->applyCriteriaToQueryBuilder($totalItemsQb, $criteria, $entreprise, $parentContext, $status, '_count');

            // Sélectionner le COUNT de l'identifiant unique (généralement 'id') pour le comptage total.
            // On utilise l'alias de l'entité de comptage 'e_count'.
            $identifierField = $repository->getClassMetadata()->getSingleIdentifierFieldName();
            $totalItemsQb->select("COUNT(DISTINCT {$totalItemsQb->getRootAliases()[0]}.{$identifierField})")
                ->setMaxResults(null)    // Annule la limite
                ->setFirstResult(null); // Annule l'offset

            // Exécuter la requête de comptage
            $totalItems = $totalItemsQb->getQuery()->getSingleScalarResult();

            $status['code'] = 200; // Si tout s'est bien passé
            $status['message'] = "Requête de filtre exécutée avec succès.";
        } catch (\Exception $e) {
            $status = [
                "error" => "Une erreur inattendue est survenue: " . $e->getMessage(),
                "code" => 500,
                "message" => "Erreur interne du serveur."
            ];
        }

        return [
            'status'      => $status,
            'data'        => $results,
            'totalItems'  => (int)$totalItems,
            'currentPage' => $page,
            'totalPages'  => max(1, (int)ceil((int)$totalItems / $limit)),
            'itemsPerPage' => $limit,
        ];
    }

    /**
     * Applique les critères de recherche (filtres et jointures) à un QueryBuilder donné.
     *
     * @param QueryBuilder $qb Le QueryBuilder à modifier.
     * @param array $criteria Les critères de recherche.
     * @param array &$status Le tableau de statut pour y rapporter les erreurs.
     * @param string $suffix Un suffixe à ajouter aux alias et paramètres pour garantir leur unicité (utile pour les requêtes de comptage).
     */
    private function applyCriteriaToQueryBuilder(QueryBuilder $qb, array $criteria, Entreprise $entreprise, ?array $parentContext, array &$status, string $suffix = ''): void
    {
        $rootAlias = $qb->getRootAliases()[0];
        $entityClass = $qb->getRootEntities()[0];
        $metadata = $this->em->getClassMetadata($entityClass);
        $joinedEntities = [];
        $parameterIndex = 0;

        // SÉCURITÉ : On s'assure que la recherche est bien limitée à l'entreprise courante.
        if ($metadata->hasAssociation('entreprise')) {
            $qb->andWhere("{$rootAlias}.entreprise = :entrepriseId{$suffix}")
               ->setParameter("entrepriseId{$suffix}", $entreprise->getId());
        } elseif ($metadata->hasAssociation('invite')) {
            // NOUVEAU : Gérer les entités liées à l'entreprise via un Invité (ex: NotificationSinistre, Piste).
            $inviteAlias = 'search_invite' . $suffix;
            $qb->join("{$rootAlias}.invite", $inviteAlias);
            $qb->andWhere("{$inviteAlias}.entreprise = :entrepriseId{$suffix}")
               ->setParameter("entrepriseId{$suffix}", $entreprise->getId());
        }

        // ── UNE LIGNE = UN VIREMENT ──────────────────────────────────────────────────
        //
        // La rubrique des reversements REPLIE chaque lot sur son porteur, sauf quand le
        // chip demande le détail par échéance. C'est posé ici, avant les critères, pour
        // deux raisons : la règle ne dépend d'aucun d'eux, et cette méthode sert AUSSI la
        // requête de comptage — un repli appliqué à la seule page aurait annoncé « 4
        // affiché(s) sur 10 » sur un écran qui n'en montre que quatre.
        if ($entityClass === ReversementRetroAgent::class) {
            $replie = ReversementScope::estReplie($criteria);
            if ($replie) {
                $qb->andWhere(ReversementScope::dqlPorteurDuLot($rootAlias));
            }
            // LA COLONNE ET LA BARRE DES TOTAUX DOIVENT DIRE LE MÊME NOMBRE. Elles
            // ne le peuvent que si elles savent à quelle maille la liste est lue :
            // repliée, une ligne vaut son virement entier ; dépliée, elle ne vaut
            // qu'elle-même. Le drapeau est posé ICI, là où le repli est décidé.
            $this->lotDeVersement->marquerReplie($replie);
        }

        // NOUVEAU : Filtrer par le parent si le contexte est fourni (recherche dans une collection).
        if ($parentContext && !empty($parentContext['id']) && !empty($parentContext['fieldName'])) {
            $fieldName = $parentContext['fieldName'];
            // Sécurité : on vérifie que le champ de la relation existe bien sur l'entité.
            if ($this->em->getClassMetadata($entityClass)->hasAssociation($fieldName)) {
                $qb->andWhere("{$rootAlias}.{$fieldName} = :parentId{$suffix}")
                   ->setParameter("parentId{$suffix}", $parentContext['id']);
            }
        }

        foreach ($criteria as $field => $value) {
            $parameterName = str_replace('.', '_', $field) . $suffix . '_' . $parameterIndex++;
            $currentAlias = $rootAlias;
            $actualField = $field;

            // CAS 0 : Périmètre « Mon portefeuille » (critère synthétique). On filtre les
            // éléments rattachés — directement ou indirectement — à un portefeuille géré par
            // l'invité (Portefeuille.gestionnaire = :id), via un OU sur les chemins déclarés
            // pour l'entité (cf. PortefeuilleScope). Les entités polymorphes (Tâche, Feedback)
            // ont plusieurs chemins ; un élément est visible si AU MOINS un s'applique.
            if ($field === PortefeuilleScope::CRITERION_KEY) {
                $inviteId = is_array($value) ? ($value['value'] ?? null) : $value;
                $shortName = (new \ReflectionClass($entityClass))->getShortName();
                $paths = PortefeuilleScope::pathsFor($shortName);

                if ($inviteId === null || $inviteId === '' || empty($paths)) {
                    continue; // rien à appliquer (entité non concernée ou id manquant)
                }

                $orParts = [];
                foreach ($paths as $path) {
                    $finalAlias = $this->joinPath($qb, $rootAlias, $metadata, $path, $joinedEntities, $suffix);
                    if ($finalAlias === null) {
                        $this->logger->warning('[JSBDynamicSearch] Chemin de périmètre invalide ignoré.', [
                            'entity' => $entityClass, 'path' => $path,
                        ]);
                        continue;
                    }
                    $orParts[] = $qb->expr()->eq("{$finalAlias}.id", ':scopeInvite' . $suffix);
                }

                // L'ORPHELIN RESTE VISIBLE, quand l'entité le tolère (cf.
                // PortefeuilleScope::ORPHELINS_TOLERES). Un document rattaché à un
                // bordereau, à un fournisseur ou à un simple classeur n'atteint AUCUN
                // portefeuille : la règle stricte le ferait disparaître de la rubrique
                // sans qu'il appartienne pour autant à quelqu'un d'autre. On ajoute donc
                // « ou aucune de ses relations de périmètre n'est renseignée » — le
                // filtre garde son objet, écarter les pièces des clients d'un AUTRE
                // gestionnaire, sans masquer ce qui est commun à l'entreprise.
                //
                // Les relations testées sont les PREMIERS SEGMENTS des chemins ci-dessus,
                // dérivés d'eux : elles vivent sur l'entité racine, donc aucune jointure
                // supplémentaire n'est nécessaire.
                if ($orParts !== [] && PortefeuilleScope::tolereLesOrphelins($shortName)) {
                    $sansParent = [];
                    foreach (PortefeuilleScope::relationsDirectes($shortName) as $relation) {
                        if ($metadata->hasAssociation($relation)) {
                            $sansParent[] = $qb->expr()->isNull("{$rootAlias}.{$relation}");
                        }
                    }
                    if ($sansParent !== []) {
                        $orParts[] = $qb->expr()->andX(...$sansParent);
                    }
                }

                if (!empty($orParts)) {
                    $qb->andWhere($qb->expr()->orX(...$orParts))
                       ->setParameter('scopeInvite' . $suffix, $inviteId);
                }
                continue;
            }

            // CAS 0 quater : Lien MULTI-CHEMINS (rechercher_entites, paramètre lieA). Une même
            // entité peut atteindre l'entité de rattachement par PLUSIEURS chemins de relations
            // to-one — et le plus court n'est pas forcément le bon : un Avenant rejoint un Client
            // par pisteDeRenouvellement.client (relation de renouvellement, NULLE pour une police
            // ordinaire) ET par cotation.piste.client (le vrai lien). On matche donc dès qu'AU
            // MOINS un chemin pointe sur l'id demandé (OR), exactement comme le périmètre
            // portefeuille.
            //
            // CHAQUE CHEMIN EST UNE SOUS-REQUÊTE « EXISTS », ET NON UNE JOINTURE DE PLUS.
            // Ces chemins étaient autrefois joints à la requête principale, comme le
            // périmètre portefeuille. C'était tenable tant que Document portait quinze
            // relations de parent ; le 2026-08-15 il en a gagné vingt-huit — une par
            // entité métier, pour que TOUT objet puisse porter une pièce jointe — et le
            // nombre de chemins vers un client a triplé. La requête est alors passée
            // au-dessus de la limite dure de MariaDB : SOIXANTE ET UNE tables par SELECT.
            // Elle n'a pas ralenti, elle a ÉCHOUÉ, et l'outil répondait « aucun document
            // ne correspond » sur une police qui en portait deux.
            //
            // Une sous-requête corrélée emporte son propre budget de jointures : la
            // requête principale n'en garde AUCUNE de ce chef, et le graphe peut
            // continuer de grandir avec le métier sans jamais retoucher à ceci. Elle
            // évite au passage toute multiplication de lignes.
            if ($field === self::LIEN_MULTI_CHEMINS) {
                $paths = is_array($value) ? ($value['paths'] ?? []) : [];
                $lienId = is_array($value) ? ($value['id'] ?? null) : null;
                if ($lienId === null || $lienId === '' || !is_array($paths) || $paths === []) {
                    continue; // rien à appliquer (aucun chemin ou id manquant)
                }

                $orParts = [];
                foreach (array_values($paths) as $rang => $path) {
                    $alias = 'lmr' . $rang . $suffix;
                    $sousRequete = $this->em->createQueryBuilder()
                        ->select('1')
                        ->from($entityClass, $alias)
                        ->andWhere(sprintf('%s.id = %s.id', $alias, $rootAlias));

                    $aliasSousRequete = [];
                    $finalAlias = $this->joinPath(
                        $sousRequete,
                        $alias,
                        $metadata,
                        (string) $path,
                        $aliasSousRequete,
                        $suffix . '_lm' . $rang,
                    );
                    if ($finalAlias === null) {
                        $this->logger->warning('[JSBDynamicSearch] Chemin de lien invalide ignoré.', [
                            'entity' => $entityClass, 'path' => $path,
                        ]);
                        continue;
                    }
                    $sousRequete->andWhere($qb->expr()->eq("{$finalAlias}.id", ':lienMulti' . $suffix));
                    $orParts[] = $qb->expr()->exists($sousRequete->getDQL());
                }

                if (!empty($orParts)) {
                    $qb->andWhere($qb->expr()->orX(...$orParts))
                       ->setParameter('lienMulti' . $suffix, $lienId);
                }
                continue;
            }

            // CAS 0 quinquies : un même texte cherché sur PLUSIEURS colonnes, en OU.
            // Les colonnes visées vivent sur l'entité racine (aucune jointure), et une
            // colonne absente est simplement sautée — ce critère sert des recherches
            // « par nom » où l'utilisateur ne sait pas quel nom il cite.
            if ($field === self::OU_TEXTE_LIBRE) {
                $champs = is_array($value) ? (array) ($value['champs'] ?? []) : [];
                $texte = is_array($value) ? trim((string) ($value['valeur'] ?? '')) : '';
                if ($texte === '' || $champs === []) {
                    continue;
                }

                $orParts = [];
                foreach ($champs as $champ) {
                    if (!$metadata->hasField((string) $champ)) {
                        continue;
                    }
                    $orParts[] = $qb->expr()->like("{$rootAlias}." . $champ, ':ouTexte' . $suffix);
                }

                if (!empty($orParts)) {
                    $qb->andWhere($qb->expr()->orX(...$orParts))
                       ->setParameter('ouTexte' . $suffix, '%' . $texte . '%');
                }
                continue;
            }

            // CAS 0 bis : Statut de souscription (Cotation uniquement). Une cotation est
            // « souscrite » dès qu'elle porte au moins un avenant (= transformée en police),
            // « en attente » ou « caduque » sinon — même définition que l'indicateur calculé
            // statutSouscription / isCotationConcurrenteCaduque. La présence d'un avenant est
            // exprimable en SQL (Avenant.cotation est une vraie relation) : on filtre par
            // EXISTS / NOT EXISTS sur des sous-requêtes d'avenants, sans service en mémoire ni
            // tri spécial. Ce CAS compose automatiquement avec les autres critères, le périmètre
            // portefeuille, la pagination et le comptage.
            //
            // Partition des cotations (mutuellement exclusive et exhaustive) :
            //  - souscrites : la cotation porte au moins un avenant.
            //  - en attente : la cotation N'est PAS souscrite ET sa piste non plus (aucune sœur
            //    souscrite) — proposition encore en course, effort commercial à fournir.
            //  - caduques   : la cotation N'est PAS souscrite MAIS sa piste l'est (une sœur porte
            //    un avenant = marché attribué à un concurrent) — sans suite, hors course.
            if ($field === CotationSouscriptionScope::CRITERION_KEY) {
                $statut = is_array($value) ? ($value['value'] ?? null) : $value;
                if (!CotationSouscriptionScope::estValide(is_string($statut) ? $statut : null)) {
                    continue; // valeur vide/inconnue (« Toutes ») : filtre ignoré
                }

                // Sous-requête A : les cotations DIRECTEMENT souscrites (au moins un avenant).
                $avAlias = 'souscription_av' . $suffix;
                $cotationsSouscrites = $this->em->createQueryBuilder()
                    ->select("IDENTITY({$avAlias}.cotation)")
                    ->from(\App\Entity\Avenant::class, $avAlias)
                    ->getDQL();

                if ($statut === CotationSouscriptionScope::STATUT_SOUSCRITES) {
                    $qb->andWhere($qb->expr()->in("{$rootAlias}.id", $cotationsSouscrites));
                    continue;
                }

                // « en attente » et « caduques » : la cotation elle-même n'est jamais souscrite.
                $qb->andWhere($qb->expr()->notIn("{$rootAlias}.id", $cotationsSouscrites));

                // On distingue par l'état de la PISTE : est-elle « bound » (au moins une de ses
                // cotations souscrite) ? Sous-requête B, alias distincts pour éviter toute
                // collision avec la sous-requête A ci-dessus.
                $avpAlias = 'souscription_avp' . $suffix;
                $cotpAlias = 'souscription_cotp' . $suffix;
                $pistesBound = $this->em->createQueryBuilder()
                    ->select("IDENTITY({$cotpAlias}.piste)")
                    ->from(\App\Entity\Avenant::class, $avpAlias)
                    ->join("{$avpAlias}.cotation", $cotpAlias)
                    ->getDQL();

                if ($statut === CotationSouscriptionScope::STATUT_CADUQUES) {
                    $qb->andWhere($qb->expr()->in("IDENTITY({$rootAlias}.piste)", $pistesBound));
                } else { // STATUT_EN_ATTENTE
                    $qb->andWhere($qb->expr()->notIn("IDENTITY({$rootAlias}.piste)", $pistesBound));
                }
                continue;
            }

            // CAS 0 ter : Statut de transformation (Piste uniquement). Une piste est
            // « transformée » dès qu'une de ses cotations est souscrite (porte un avenant),
            // « en cours » sinon — même définition que l'indicateur calculé statutTransformation,
            // pendant un cran plus haut du statut de souscription d'une cotation. Exprimable en
            // SQL : on filtre par EXISTS / NOT EXISTS sur une sous-requête des pistes ayant au
            // moins un avenant rattaché via l'une de leurs cotations. Compose automatiquement
            // avec les autres critères, le périmètre portefeuille, la pagination et le comptage.
            if ($field === PisteTransformationScope::CRITERION_KEY) {
                $statut = is_array($value) ? ($value['value'] ?? null) : $value;
                if (!PisteTransformationScope::estValide(is_string($statut) ? $statut : null)) {
                    continue; // valeur vide/inconnue (« Toutes ») : filtre ignoré
                }

                $avAlias = 'transformation_av' . $suffix;
                $cotAlias = 'transformation_cot' . $suffix;
                $sousRequete = $this->em->createQueryBuilder()
                    ->select("IDENTITY({$cotAlias}.piste)")
                    ->from(\App\Entity\Avenant::class, $avAlias)
                    ->join("{$avAlias}.cotation", $cotAlias)
                    ->getDQL();

                if ($statut === PisteTransformationScope::STATUT_TRANSFORMEES) {
                    $qb->andWhere($qb->expr()->in("{$rootAlias}.id", $sousRequete));
                } else {
                    $qb->andWhere($qb->expr()->notIn("{$rootAlias}.id", $sousRequete));
                }
                continue;
            }

            // CAS 0 quater : les trois filtres rapides des REVERSEMENTS de rétrocommission.
            // Tous exprimables en SQL, donc filtrés en base — jamais en mémoire, sans quoi la
            // pagination et les totaux porteraient sur un ensemble et l'affichage sur un autre.
            if ($field === ReversementScope::CLE_JUSTIFICATIF) {
                $valeur = is_array($value) ? ($value['value'] ?? null) : $value;
                if (!ReversementScope::estValide(ReversementScope::CLE_JUSTIFICATIF, is_string($valeur) ? $valeur : null)) {
                    continue; // « Tous » : filtre ignoré
                }

                // LA PREUVE EST CELLE DU VIREMENT, pas de la ligne. Un bordereau couvre tout un
                // lot : filtrer sur les documents de la SEULE ligne ferait passer pour « sans
                // pièce » deux lignes sur trois d'un virement pourtant justifié. On regarde donc
                // les pièces de toutes les lignes partageant la même référence de lot — et, pour
                // un versement isolé (lotReference nulle), les siennes.
                $docAlias = 'justif_doc' . $suffix;
                $revAlias = 'justif_rev' . $suffix;
                $couverts = $this->em->createQueryBuilder()
                    ->select("{$revAlias}.id")
                    ->from(\App\Entity\ReversementRetroAgent::class, $revAlias)
                    ->join(\App\Entity\ReversementRetroAgent::class, 'lot' . $suffix, 'WITH',
                        "(lot{$suffix}.lotReference IS NOT NULL AND lot{$suffix}.lotReference = {$revAlias}.lotReference)"
                        . " OR lot{$suffix}.id = {$revAlias}.id")
                    ->join(\App\Entity\Document::class, $docAlias, 'WITH', "{$docAlias}.reversementRetroAgent = lot{$suffix}.id")
                    ->getDQL();

                if ($valeur === ReversementScope::AVEC_PIECE) {
                    $qb->andWhere($qb->expr()->in("{$rootAlias}.id", $couverts));
                } else {
                    $qb->andWhere($qb->expr()->notIn("{$rootAlias}.id", $couverts));
                }
                continue;
            }

            if ($field === ReversementScope::CLE_PERIODE) {
                $valeur = is_array($value) ? ($value['value'] ?? null) : $value;
                if (!ReversementScope::estValide(ReversementScope::CLE_PERIODE, is_string($valeur) ? $valeur : null)) {
                    continue;
                }

                // Les bornes viennent du scope : le filtre et tout libellé de rapprochement
                // doivent parler des mêmes dates.
                $bornes = ReversementScope::bornes($valeur);
                if ($bornes === null) {
                    continue;
                }
                $qb->andWhere("{$rootAlias}.paidAt BETWEEN :periodeDebut{$suffix} AND :periodeFin{$suffix}")
                    ->setParameter('periodeDebut' . $suffix, $bornes[0])
                    ->setParameter('periodeFin' . $suffix, $bornes[1]);
                continue;
            }

            // LE CHIP « VIREMENT » NE FILTRE RIEN : il choisit la MAILLE de lecture, et il a
            // déjà agi plus haut, là où le repli est décidé. Les deux modes portent donc
            // exactement le même argent — c'est la propriété qui les définit.
            if ($field === ReversementScope::CLE_VIREMENT) {
                continue;
            }

            // LA FAMILLE DU BÉNÉFICIAIRE. Les deux vivent sur le même enregistrement, en
            // XOR : on lit donc la présence de l'une des deux relations plutôt qu'un
            // discriminant, qui n'existe pas et qui pourrait diverger de la réalité.
            if ($field === ReversementScope::CLE_TYPE) {
                $valeur = is_array($value) ? ($value['value'] ?? null) : $value;
                if (!ReversementScope::estValide(ReversementScope::CLE_TYPE, is_string($valeur) ? $valeur : null)) {
                    continue;
                }

                $qb->andWhere($valeur === ReversementScope::TYPE_AGENT
                    ? "{$rootAlias}.agent IS NOT NULL"
                    : "{$rootAlias}.partenaire IS NOT NULL");
                continue;
            }

            // LE BÉNÉFICIAIRE NOMMÉ, quelle que soit sa famille. La valeur porte la famille
            // puis l'identifiant, et c'est elle qui désigne la colonne : filtrer en dur sur
            // `agent` aurait rendu tout partenaire infiltrable, et un identifiant nu aurait
            // confondu l'agent #3 avec le partenaire #3.
            if ($field === ReversementScope::CLE_BENEFICIAIRE) {
                $valeur = is_array($value) ? ($value['value'] ?? null) : $value;
                $decode = ReversementScope::decoderBeneficiaire(is_string($valeur) ? $valeur : null);
                if ($decode === null) {
                    continue; // « Tous » ou valeur illisible : filtre retiré, jamais inventé
                }

                [$type, $id] = $decode;
                $colonne = ReversementScope::colonneBeneficiaire($type);
                $qb->andWhere("{$rootAlias}.{$colonne} = :beneficiaire{$suffix}")
                    ->setParameter('beneficiaire' . $suffix, $id);
                continue;
            }
            // CAS 1 : C'est une plage de dates (recherche avancée pour les champs de type DateTimeRange).
            // La valeur est un tableau comme { from: 'YYYY-MM-DD', to: 'YYYY-MM-DD' }.
            if (is_array($value) && (isset($value['from']) || isset($value['to'])) && !isset($value['operator'])) {
                $from = $value['from'] ?? null;
                $to = $value['to'] ?? null;

                if ($from) {
                    $qb->andWhere("{$currentAlias}.{$actualField} >= :{$parameterName}_from")
                        ->setParameter("{$parameterName}_from", (new \DateTime($from))->format('Y-m-d 00:00:00'));
                }
                if ($to) {
                    $qb->andWhere("{$currentAlias}.{$actualField} <= :{$parameterName}_to")
                        ->setParameter("{$parameterName}_to", (new \DateTime($to))->format('Y-m-d 23:59:59'));
                }
            }
            // CAS 2 : C'est un critère structuré (recherche simple ou avancée pour les champs Texte, Nombre, etc.).
            // La valeur est un objet comme { operator: 'LIKE', value: '...', targetField: '...' }.
            elseif (is_array($value) && isset($value['operator']) && isset($value['value']) && $value['value'] !== '') {
                $operator = strtoupper($value['operator']);
                $filterValue = $value['value'];

                if (!in_array($operator, $this->allowedOperators, true)) {
                    $status = ["error" => "Opérateur non autorisé.", "code" => 403, "message" => "Opérateur '{$operator}' non autorisé."];
                    return;
                }

                $metadata = $this->em->getClassMetadata($qb->getRootEntities()[0]);

                // SOUS-CAS 2.1 : Le champ est une relation, éventuellement à PLUSIEURS niveaux
                // (ex: 'assure', ou chemin 'client.portefeuille', 'cotation.piste.client.portefeuille').
                // On traverse chaque segment par un leftJoin, en dédupliquant les jointures via
                // $joinedEntities (alias uniques, compatibles requête de comptage grâce à $suffix).
                if (str_contains($actualField, '.') || $metadata->hasAssociation($actualField)) {
                    $segments = explode('.', $actualField);
                    $joinAlias = $currentAlias;
                    $currentMeta = $metadata;
                    $pathKey = $currentAlias;
                    $pathIsValid = true;

                    foreach ($segments as $segment) {
                        // Sécurité : chaque segment doit être une véritable association.
                        if (!$currentMeta->hasAssociation($segment)) {
                            $pathIsValid = false;
                            break;
                        }
                        $pathKey .= '.' . $segment;
                        if (!isset($joinedEntities[$pathKey])) {
                            $newAlias = 'search_' . str_replace('.', '_', $actualField) . '_' . $segment . $suffix;
                            $qb->leftJoin("{$joinAlias}.{$segment}", $newAlias);
                            $joinedEntities[$pathKey] = $newAlias;
                        }
                        $joinAlias = $joinedEntities[$pathKey];
                        $currentMeta = $this->em->getClassMetadata($currentMeta->getAssociationTargetClass($segment));
                    }

                    // Chemin invalide (segment inexistant) : on ignore le critère mais on
                    // le trace pour faciliter le débogage (typo de code de champ, etc.).
                    if (!$pathIsValid) {
                        $this->logger->warning('[JSBDynamicSearch] Chemin de relation invalide ignoré.', [
                            'entity' => $entityClass,
                            'field'  => $actualField,
                        ]);
                        continue;
                    }

                    // Filtrage par IDENTITÉ (nouveau sélecteur autocomplété) : le critère porte
                    // l'id de l'entité liée et un opérateur d'égalité, sans targetField texte.
                    if (in_array($operator, ['=', '!='], true) && !isset($value['targetField'])) {
                        $comparison = $operator === '!='
                            ? $qb->expr()->neq("{$joinAlias}.id", ':' . $parameterName)
                            : $qb->expr()->eq("{$joinAlias}.id", ':' . $parameterName);
                        $qb->andWhere($comparison)->setParameter($parameterName, $filterValue);
                    } else {
                        // Recherche texte de repli (recherche simple) : LIKE sur le champ
                        // d'affichage de la relation (ex: 'nom'), fourni dans le critère.
                        $targetField = $value['targetField'] ?? 'nom';
                        $qb->andWhere($qb->expr()->like("{$joinAlias}.{$targetField}", ':' . $parameterName))
                            ->setParameter($parameterName, '%' . $filterValue . '%');
                    }
                }
                // SOUS-CAS 2.2 : Le champ est un attribut simple (texte, nombre, etc.).
                else {
                    $operatorMap = [
                        '=' => 'eq', '!=' => 'neq', '>' => 'gt', '>=' => 'gte',
                        '<' => 'lt', '<=' => 'lte', 'LIKE' => 'like',
                    ];
                    $doctrineOperator = $operatorMap[$operator] ?? null;
                    if (!$doctrineOperator) continue;

                    $qb->andWhere($qb->expr()->{$doctrineOperator}($currentAlias . '.' . $actualField, ':' . $parameterName));
                    // Mode de correspondance texte : 'starts' => "valeur%", sinon "%valeur%".
                    // (le mode 'exact' est envoyé par le frontend avec l'opérateur '=').
                    if ($operator === 'LIKE') {
                        $mode = $value['mode'] ?? 'contains';
                        $paramValue = $mode === 'starts' ? $filterValue . '%' : '%' . $filterValue . '%';
                    } else {
                        $paramValue = $filterValue;
                    }
                    $qb->setParameter($parameterName, $paramValue);
                }
            }
            // CAS 3 : C'est une valeur simple (Objet, chaîne, nombre) pour une égalité stricte.
            // Cela gère les critères passés manuellement par les contrôleurs (ex: extraCriteria).
            elseif (!is_array($value) && $value !== null && $value !== '') {
                $qb->andWhere("{$currentAlias}.{$actualField} = :{$parameterName}")
                    ->setParameter($parameterName, $value);
            }
            // CAS 4 : IS NULL OR égalité. Format : ['IS_NULL_OR_EQ' => $entity].
            // Utilisé pour les champs optionnels où l'absence de valeur signifie "visible par tous".
            elseif (is_array($value) && array_key_exists('IS_NULL_OR_EQ', $value)) {
                $entity = $value['IS_NULL_OR_EQ'];
                $qb->andWhere($qb->expr()->orX(
                    $qb->expr()->isNull("{$currentAlias}.{$actualField}"),
                    $qb->expr()->eq("{$currentAlias}.{$actualField}", ":{$parameterName}")
                ))->setParameter($parameterName, $entity);
            }
        }
    }

    /**
     * Traverse un chemin de relations pointillé (ex. « cotation.piste.client.portefeuille »)
     * en enchaînant des leftJoin, et retourne l'alias final. Les jointures sont dédupliquées
     * via $joinedEntities (clé = préfixe du chemin), ce qui permet de partager les segments
     * communs entre plusieurs chemins (ex. les chemins d'une Tâche/Feedback). Les associations
     * étant toutes « to-one », aucun risque de multiplication de lignes.
     *
     * @param array<string, string> $joinedEntities Registre des jointures déjà posées (par référence).
     * @return string|null L'alias final, ou null si un segment n'est pas une association valide.
     */
    private function joinPath(QueryBuilder $qb, string $rootAlias, ClassMetadata $rootMeta, string $path, array &$joinedEntities, string $suffix): ?string
    {
        $joinAlias = $rootAlias;
        $currentMeta = $rootMeta;
        $pathKey = $rootAlias;

        foreach (explode('.', $path) as $segment) {
            if (!$currentMeta->hasAssociation($segment)) {
                return null;
            }
            $pathKey .= '.' . $segment;
            if (!isset($joinedEntities[$pathKey])) {
                $newAlias = 'sc_' . substr(md5($pathKey), 0, 10) . $suffix;
                $qb->leftJoin("{$joinAlias}.{$segment}", $newAlias);
                $joinedEntities[$pathKey] = $newAlias;
            }
            $joinAlias = $joinedEntities[$pathKey];
            $currentMeta = $this->em->getClassMetadata($currentMeta->getAssociationTargetClass($segment));
        }

        return $joinAlias;
    }
}
