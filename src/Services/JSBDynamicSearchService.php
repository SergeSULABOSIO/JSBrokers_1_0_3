<?php
namespace App\Services;



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
    public function search(array $data): array
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

        $entityName = $data['entityName'] ?? null;
        $criteria = $data['criteria'] ?? [];

        if (!$entityName || !in_array($entityName, self::$allowedEntities, true)) {
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
            $repository = $this->em->getRepository('App\\Entity\\' . $entityName);
            // 'e' est l'alias de notre entité principale dans la requête DQL (ex: SELECT e FROM App\Entity\NotificationSinistre e)
            $qb = $repository->createQueryBuilder('e');

            // AMÉLIORATION : On applique les filtres en utilisant la nouvelle méthode factorisée.
            $this->applyCriteriaToQueryBuilder($qb, $criteria, $status);

            // MISSION 2 : Trier les résultats par ID décroissant pour afficher les plus récents en premier.
            $qb->orderBy('e.id', 'DESC');

            // Exécuter la requête pour obtenir les résultats (objets Doctrine)
            $results = $qb->getQuery()->getResult();

            // --- AMÉLIORATION : Logique de comptage simplifiée ---
            $totalItemsQb = $repository->createQueryBuilder('e_count');
            $this->applyCriteriaToQueryBuilder($totalItemsQb, $criteria, $status, '_count');

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
    private function applyCriteriaToQueryBuilder(QueryBuilder $qb, array $criteria, array &$status, string $suffix = ''): void
    {
        $rootAlias = $qb->getRootAliases()[0];
        $joinedEntities = [];
        $parameterIndex = 0;

        foreach ($criteria as $field => $value) {
            $parameterName = str_replace('.', '_', $field) . $suffix . '_' . $parameterIndex++;
            $currentAlias = $rootAlias;
            $actualField = $field;

            $fieldParts = explode('.', $field);
            if (count($fieldParts) > 1) {
                $relationName = $fieldParts[0];
                $actualField = $fieldParts[1];
                $relationAlias = $relationName . $suffix;

                if (!isset($joinedEntities[$relationAlias])) {
                    $qb->leftJoin("{$currentAlias}.{$relationName}", $relationAlias);
                    $joinedEntities[$relationAlias] = $relationAlias;
                }
                $currentAlias = $joinedEntities[$relationAlias];
            }

            if (is_array($value) && isset($value['operator']) && (isset($value['value']) && $value['value'] !== '')) {
                $operator = strtoupper($value['operator']);
                $filterValue = $value['value'];

                if (!in_array($operator, $this->allowedOperators, true)) {
                    $status = ["error" => "Opérateur non autorisé.", "code" => 403, "message" => "Opérateur '{$operator}' non autorisé."];
                    return; // Stop processing if operator is invalid
                }

                if ($operator === 'BETWEEN') {
                    $from = $filterValue['from'] ?? null;
                    $to = $filterValue['to'] ?? null;

                    if ($from && $to) {
                        $qb->andWhere("{$currentAlias}.{$actualField} BETWEEN :{$parameterName}_from AND :{$parameterName}_to")
                           ->setParameter("{$parameterName}_from", (new \DateTime($from))->format('Y-m-d 00:00:00'))
                           ->setParameter("{$parameterName}_to", (new \DateTime($to))->format('Y-m-d 23:59:59'));
                    } elseif ($from) {
                        $qb->andWhere("{$currentAlias}.{$actualField} >= :{$parameterName}_from")
                           ->setParameter("{$parameterName}_from", (new \DateTime($from))->format('Y-m-d 00:00:00'));
                    } elseif ($to) {
                        $qb->andWhere("{$currentAlias}.{$actualField} <= :{$parameterName}_to")
                           ->setParameter("{$parameterName}_to", (new \DateTime($to))->format('Y-m-d 23:59:59'));
                    }
                } else {
                    $metadata = $this->em->getClassMetadata($qb->getRootEntities()[0]);
                    if ($metadata->hasField($actualField) && in_array($metadata->getTypeOfField($actualField), ['datetime', 'datetime_immutable', 'date', 'date_immutable'])) {
                        try {
                            $dateObj = new \DateTime($filterValue);
                            switch ($operator) {
                                case '=':
                                    $qb->andWhere("{$currentAlias}.{$actualField} BETWEEN :{$parameterName}_start AND :{$parameterName}_end")
                                       ->setParameter("{$parameterName}_start", $dateObj->format('Y-m-d 00:00:00'))
                                       ->setParameter("{$parameterName}_end", $dateObj->format('Y-m-d 23:59:59'));
                                    break;
                                case '!=':
                                    $qb->andWhere("{$currentAlias}.{$actualField} NOT BETWEEN :{$parameterName}_start AND :{$parameterName}_end")
                                       ->setParameter("{$parameterName}_start", $dateObj->format('Y-m-d 00:00:00'))
                                       ->setParameter("{$parameterName}_end", $dateObj->format('Y-m-d 23:59:59'));
                                    break;
                                case '>':
                                    $qb->andWhere("{$currentAlias}.{$actualField} > :{$parameterName}")
                                       ->setParameter($parameterName, $dateObj->format('Y-m-d 23:59:59'));
                                    break;
                                case '>=':
                                    $qb->andWhere("{$currentAlias}.{$actualField} >= :{$parameterName}")
                                       ->setParameter($parameterName, $dateObj->format('Y-m-d 00:00:00'));
                                    break;
                                case '<':
                                    $qb->andWhere("{$currentAlias}.{$actualField} < :{$parameterName}")
                                       ->setParameter($parameterName, $dateObj->format('Y-m-d 00:00:00'));
                                    break;
                                case '<=':
                                    $qb->andWhere("{$currentAlias}.{$actualField} <= :{$parameterName}")
                                       ->setParameter($parameterName, $dateObj->format('Y-m-d 23:59:59'));
                                    break;
                            }
                        } catch (\Exception $e) {
                            $status = ["error" => "Format de date invalide.", "code" => 400, "message" => "Format de date invalide pour '{$field}'."];
                            return;
                        }
                    } else {
                        // NOUVEAU : Table de correspondance pour les opérateurs
                        $operatorMap = [
                            '=' => 'eq',
                            '!=' => 'neq',
                            '>' => 'gt',
                            '>=' => 'gte',
                            '<' => 'lt',
                            '<=' => 'lte',
                            'LIKE' => 'like',
                        ];

                        $doctrineOperator = $operatorMap[strtoupper($operator)] ?? null;
                        if (!$doctrineOperator) continue; // Ignore les opérateurs inconnus

                        $qb->andWhere($qb->expr()->{$doctrineOperator}($currentAlias . '.' . $actualField, ':' . $parameterName));
                        $paramValue = ($operator === 'LIKE') ? '%' . $filterValue . '%' : $filterValue;
                        $qb->setParameter($parameterName, $paramValue);
                    }
                }
            } else {
                if (is_string($value) && $value !== '') {
                    $qb->andWhere($qb->expr()->like($currentAlias . '.' . $actualField, ':' . $parameterName))
                       ->setParameter($parameterName, '%' . $value . '%');
                }
            }
        }
    }
}
