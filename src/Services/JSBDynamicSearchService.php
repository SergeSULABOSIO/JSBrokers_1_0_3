<?php
namespace App\Services;



use App\Entity\Entreprise;
use App\Services\Search\PortefeuilleScope;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\EntityManagerInterface;

class JSBDynamicSearchService
{
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
        'DepenseCourtier',
        'Document',
        'Entreprise',
        'Feedback',
        'Fournisseur',
        'Groupe',
        'Invite',
        'ModelePieceSinistre',
        'Monnaie',
        'Note',
        'NotificationSinistre',
        'OffreIndemnisationSinistre',
        'Paiement',
        'Partenaire',
        'PieceSinistre',
        'Piste',
        'Portefeuille',
        'RevenuPourCourtier',
        'Risque',
        'Tache',
        'Taxe',
        'Tranche',
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
    public function __construct(EntityManagerInterface $em, ?LoggerInterface $logger = null)
    {
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

                if (!empty($orParts)) {
                    $qb->andWhere($qb->expr()->orX(...$orParts))
                       ->setParameter('scopeInvite' . $suffix, $inviteId);
                }
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
