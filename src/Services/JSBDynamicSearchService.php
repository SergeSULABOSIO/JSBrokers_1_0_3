<?php
namespace App\Services;



use App\Entity\Entreprise;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;

class JSBDynamicSearchService
{
    private EntityManagerInterface $em;

    /**
     * @var string[] Liste blanche des entités autorisées pour la recherche.
     * C'est ici que vous centraliserez la gestion des entités consultables.
     */
    public static array $allowedEntities = [
        'NotificationSinistre',
        'Client',
        'Assureur',
        'Police',
        'Paiement',
        'Document',
        'Tache',
        'Bordereau',
        'PieceSinistre',
        'Contact',
        'OffreIndemnisationSinistre',
        'Feedback',
        'Piste',
        'Cotation',
        'Avenant',
        'Partenaire',
        'Monnaie',
        'Invite',
        'AutoriteFiscale',
        'OffreIndemnisationSinistre',
        // Ajoutez ici toutes les autres entités que vous voulez rendre cherchables
    ];

    /**
     * @var string[] Liste blanche des opérateurs de comparaison autorisés.
     */
    private array $allowedOperators = ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'BETWEEN'];

    /**
     * Le service a besoin de l'EntityManager de Doctrine pour fonctionner.
     * Symfony l'injectera automatiquement ici.
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * La logique de votre ancienne fonction "chercher".
     * Elle ne dépend plus de l'objet Request, mais d'un simple tableau.
     */
    public function search(string $entityClass, array $criteria, Entreprise $entreprise, ?array $parentContext = null): array
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
            'status' => $status,
            'data' => $results,
            'totalItems' => $totalItems,
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
        $joinedEntities = [];
        $parameterIndex = 0;

        // SÉCURITÉ : On s'assure que la recherche est bien limitée à l'entreprise courante.
        if ($this->em->getClassMetadata($entityClass)->hasAssociation('entreprise')) {
            $qb->andWhere("{$rootAlias}.entreprise = :entrepriseId{$suffix}")
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

                // SOUS-CAS 2.1 : Le champ est une relation (ex: 'assure').
                if ($metadata->hasAssociation($actualField)) {
                    $relationAlias = $actualField . $suffix;
                    $qb->leftJoin("{$currentAlias}.{$actualField}", $relationAlias);

                    // Le champ cible sur lequel chercher (ex: 'nom') est fourni dans le critère.
                    $targetField = $value['targetField'] ?? 'nom';

                    $qb->andWhere($qb->expr()->like("{$relationAlias}.{$targetField}", ':' . $parameterName))
                        ->setParameter($parameterName, '%' . $filterValue . '%');
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
                    $paramValue = ($operator === 'LIKE') ? '%' . $filterValue . '%' : $filterValue;
                    $qb->setParameter($parameterName, $paramValue);
                }
            }
        }
    }
}
