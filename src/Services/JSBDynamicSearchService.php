<?php
namespace App\Services;



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

            // Tableau pour stocker les alias des entités déjà jointes.
            // Cela est crucial pour éviter de joindre la même entité plusieurs fois si plusieurs critères y font référence.
            $joinedEntities = [];

            // Index pour garantir des noms de paramètres uniques dans la requête DQL.
            // Ex: :param0, :param1, etc. pour éviter les conflits si plusieurs critères utilisent le même nom de champ.
            $parameterIndex = 0;

            // --- BOUCLE PRINCIPALE DE CONSTRUCTION DES FILTRES ---
            // On parcourt chaque critère fourni par le frontend.
            foreach ($criteria as $field => $value) {
                // Création d'un nom de paramètre unique pour Doctrine.
                // str_replace('.', '_', $field) remplace les points dans les noms de champs de relation (ex: assure.nom devient assure_nom).
                $parameterName = str_replace('.', '_', $field) . '_' . $parameterIndex++;

                // Alias de l'entité actuelle et nom du champ réel à utiliser dans la clause WHERE.
                // Par défaut, ils pointent vers l'entité principale ('e') et le champ tel quel.
                $currentAlias = 'e';
                $actualField = $field;

                // --- GESTION DES RELATIONS (Jointures) ---
                // Si le nom du champ contient un point, cela indique une relation (ex: "assure.nom").
                $fieldParts = explode('.', $field);
                if (count($fieldParts) > 1) {
                    $relationName = $fieldParts[0]; // Le nom de la relation (ex: "assure")
                    $actualField = $fieldParts[1];   // Le nom du champ sur l'entité liée (ex: "nom")

                    // Vérifier si cette relation n'a pas déjà été jointe pour éviter les doublons.
                    if (!isset($joinedEntities[$relationName])) {
                        // Effectuer une LEFT JOIN.
                        // Utilisez LEFT JOIN si vous voulez inclure les entités principales même si la relation n'existe pas.
                        // Utilisez INNER JOIN si le critère DOIT avoir une correspondance dans la relation pour être inclus.
                        $qb->leftJoin("{$currentAlias}.{$relationName}", $relationName); // Joint 'e.assure' comme 'assure'
                        $joinedEntities[$relationName] = $relationName; // Stocke l'alias utilisé pour cette relation.
                    }
                    // Mettre à jour l'alias courant pour qu'il pointe vers l'entité jointe.
                    $currentAlias = $joinedEntities[$relationName];
                }

                // --- DÉBUT DE LA LOGIQUE DE FILTRAGE DES VALEURS ---
                // Distinguer les critères simples (chaîne directe) des critères complexes (tableau avec opérateur et valeur).
                if (is_array($value) && isset($value['operator']) && (isset($value['value']) && $value['value'] !== '')) {
                    // C'est un critère complexe (ex: pour nombres, dates, plages de dates).
                    $operator = strtoupper($value['operator']); // Convertir l'opérateur en majuscules pour normalisation.
                    $filterValue = $value['value'];

                    // Sécurité : valider l'opérateur contre la liste blanche autorisée.
                    if (!in_array($operator, $this->allowedOperators, true)) {
                        // throw new \InvalidArgumentException("Opérateur '{$operator}' non autorisé pour le champ '{$field}'.");
                        $status = [
                            "error" => "Opérateur non autorisé.",
                            "code" => 403,
                            "message" => "Opérateur '{$operator}' non autorisé pour le champ '{$field}'."
                        ];
                    }

                    // Logique spécifique pour les plages de dates (opérateur BETWEEN)
                    if ($operator === 'BETWEEN') {
                        if (!is_array($filterValue) || (!isset($filterValue['from']) && !isset($filterValue['to']))) {
                            // throw new \InvalidArgumentException("La valeur pour l'opérateur BETWEEN doit être un tableau avec 'from' et/ou 'to'.");
                            $status = [
                                "error" => "Opérateur non conforme au format.",
                                "code" => 403,
                                "message" => "La valeur pour l'opérateur BETWEEN doit être un tableau avec 'from' et/ou 'to'."
                            ];
                        }

                        $from = $filterValue['from'] ?? null;
                        $to = $filterValue['to'] ?? null;

                        if ($from && $to) {
                            $fromObj = new \DateTime($from);
                            $toObj = new \DateTime($to);
                            $qb->andWhere("{$currentAlias}.{$actualField} BETWEEN :{$parameterName}_from AND :{$parameterName}_to");
                            $qb->setParameter("{$parameterName}_from", $fromObj->format('Y-m-d 00:00:00'));
                            $qb->setParameter("{$parameterName}_to", $toObj->format('Y-m-d 23:59:59'));
                        } elseif ($from) {
                            $fromObj = new \DateTime($from);
                            $qb->andWhere("{$currentAlias}.{$actualField} >= :{$parameterName}_from");
                            $qb->setParameter("{$parameterName}_from", $fromObj->format('Y-m-d 00:00:00'));
                        } elseif ($to) {
                            $toObj = new \DateTime($to);
                            $qb->andWhere("{$currentAlias}.{$actualField} <= :{$parameterName}_to");
                            $qb->setParameter("{$parameterName}_to", $toObj->format('Y-m-d 23:59:59'));
                        }
                    } else {
                        // Logique pour les autres opérateurs (y compris les dates simples avec =, !=, >, etc.)
                        // Gérer les conversions de date pour les champs date (s'ils ne sont pas déjà des objets DateTime)
                        $metadata = $this->em->getClassMetadata($repository->getClassName());
                        if ($metadata->hasField($actualField) && in_array($metadata->getTypeOfField($actualField), ['datetime', 'datetime_immutable', 'date', 'date_immutable'])) {
                            try {
                                $dateObj = new \DateTime($filterValue);
                                // Ajuster les valeurs des paramètres pour couvrir toute la journée si l'opérateur est '=' ou '!='
                                if ($operator === '=') {
                                    $qb->andWhere("{$currentAlias}.{$actualField} BETWEEN :{$parameterName}_start AND :{$parameterName}_end");
                                    $qb->setParameter("{$parameterName}_start", $dateObj->format('Y-m-d 00:00:00'));
                                    $qb->setParameter("{$parameterName}_end", $dateObj->format('Y-m-d 23:59:59'));
                                } elseif ($operator === '!=') {
                                    $qb->andWhere("{$currentAlias}.{$actualField} NOT BETWEEN :{$parameterName}_start AND :{$parameterName}_end");
                                    $qb->setParameter("{$parameterName}_start", $dateObj->format('Y-m-d 00:00:00'));
                                    $qb->setParameter("{$parameterName}_end", $dateObj->format('Y-m-d 23:59:59'));
                                } elseif ($operator === '>') {
                                    $qb->andWhere("{$currentAlias}.{$actualField} > :{$parameterName}");
                                    $qb->setParameter($parameterName, $dateObj->format('Y-m-d 23:59:59'));
                                } elseif ($operator === '>=') {
                                    $qb->andWhere("{$currentAlias}.{$actualField} >= :{$parameterName}");
                                    $qb->setParameter($parameterName, $dateObj->format('Y-m-d 00:00:00'));
                                } elseif ($operator === '<') {
                                    $qb->andWhere("{$currentAlias}.{$actualField} < :{$parameterName}");
                                    $qb->setParameter($parameterName, $dateObj->format('Y-m-d 00:00:00'));
                                } elseif ($operator === '<=') {
                                    $qb->andWhere("{$currentAlias}.{$actualField} <= :{$parameterName}");
                                    $qb->setParameter($parameterName, $dateObj->format('Y-m-d 23:59:59'));
                                }
                            } catch (\Exception $e) {
                                // throw new \InvalidArgumentException("Format de date invalide pour le champ '{$field}': " . $filterValue);
                                $status = [
                                    "error" => "Format de la date invalide.",
                                    "code" => 403,
                                    "message" => "Format de date invalide pour le champ '{$field}': " . $filterValue
                                ];
                            }
                        } else {
                            // Pour les opérateurs numériques ou LIKE standard
                            $exprMethod = $qb->expr()->{'__call'}(strtolower($operator), [$currentAlias . '.' . $actualField, ':' . $parameterName]);
                            $qb->andWhere($exprMethod);

                            // Pour LIKE, la valeur doit être entourée de % pour une recherche partielle
                            $paramValue = ($operator === 'LIKE') ? '%' . $filterValue . '%' : $filterValue;
                            $qb->setParameter($parameterName, $paramValue);
                        }
                    }
                } else {
                    // C'est un critère simple (chaîne de caractères directe), utilise LIKE par défaut pour la recherche textuelle.
                    // S'applique aussi bien aux champs directs qu'aux champs de relation.
                    // Ex: 'descriptionDeFait': 'accident' => e.descriptionDeFait LIKE '%accident%'
                    if (is_string($value) && $value !== '') {
                        $qb->andWhere($qb->expr()->like($currentAlias . '.' . $actualField, ':' . $parameterName))
                            ->setParameter($parameterName, '%' . $value . '%');
                    }
                    // Si $value est vide ou non string, on ignore ce critère.
                }
            } // --- FIN DE LA BOUCLE PRINCIPALE DE CONSTRUCTION DES FILTRES ---

            // Exécuter la requête pour obtenir les résultats (objets Doctrine)
            $results = $qb->getQuery()->getResult();

            // --- Logique de COMPTAGE DES ÉLÉMENTS TOTAUX ---
            // Il est essentiel de calculer le nombre total de résultats SANS la pagination,
            // mais AVEC tous les filtres appliqués, pour informer le frontend du nombre total de pages.
            // On clone le QueryBuilder principal pour ne pas perturber sa configuration de pagination.
            $totalItemsQb = $repository->createQueryBuilder('e_count');

            // Réappliquer les mêmes jointures et critères pour le QueryBuilder de comptage.
            // Il est crucial que les alias et paramètres soient uniques pour ce QB de comptage.
            $joinedEntitiesCount = []; // Un nouveau tableau de suivi des jointures pour le comptage.
            $parameterIndexCount = 0;  // Un nouvel index de paramètre pour le comptage.

            foreach ($criteria as $field => $value) {
                $parameterNameCount = str_replace('.', '_', $field) . '_count_' . $parameterIndexCount++;

                $currentAliasCount = 'e_count'; // Alias pour le QueryBuilder de comptage
                $actualFieldCount = $field;

                $fieldParts = explode('.', $field);
                if (count($fieldParts) > 1) {
                    $relationName = $fieldParts[0];
                    $actualFieldCount = $fieldParts[1];

                    if (!isset($joinedEntitiesCount[$relationName])) {
                        $totalItemsQb->leftJoin("{$currentAliasCount}.{$relationName}", $relationName . '_count'); // Utilise un alias distinct pour le comptage
                        $joinedEntitiesCount[$relationName] = $relationName . '_count';
                    }
                    $currentAliasCount = $joinedEntitiesCount[$relationName];
                }

                // Réappliquer la logique de filtrage pour le comptage (identique à la requête principale)
                if (is_array($value) && isset($value['operator']) && (isset($value['value']) && $value['value'] !== '')) {
                    $operator = strtoupper($value['operator']);
                    $filterValue = $value['value'];

                    if ($operator === 'BETWEEN') {
                        $from = $filterValue['from'] ?? null;
                        $to = $filterValue['to'] ?? null;
                        if ($from && $to) {
                            $fromObj = new \DateTime($from);
                            $toObj = new \DateTime($to);
                            $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} BETWEEN :{$parameterNameCount}_from AND :{$parameterNameCount}_to");
                            $totalItemsQb->setParameter("{$parameterNameCount}_from", $fromObj->format('Y-m-d 00:00:00'));
                            $totalItemsQb->setParameter("{$parameterNameCount}_to", $toObj->format('Y-m-d 23:59:59'));
                        } elseif ($from) {
                            $fromObj = new \DateTime($from);
                            $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} >= :{$parameterNameCount}_from");
                            $totalItemsQb->setParameter("{$parameterNameCount}_from", $fromObj->format('Y-m-d 00:00:00'));
                        } elseif ($to) {
                            $toObj = new \DateTime($to);
                            $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} <= :{$parameterNameCount}_to");
                            $totalItemsQb->setParameter("{$parameterNameCount}_to", $toObj->format('Y-m-d 23:59:59'));
                        }
                    } else {
                        $metadata = $this->em->getClassMetadata($repository->getClassName());
                        if ($metadata->hasField($actualFieldCount) && in_array($metadata->getTypeOfField($actualFieldCount), ['datetime', 'datetime_immutable', 'date', 'date_immutable'])) {
                            try {
                                $dateObj = new \DateTime($filterValue);
                                if ($operator === '=') {
                                    $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} BETWEEN :{$parameterNameCount}_start AND :{$parameterNameCount}_end");
                                    $totalItemsQb->setParameter("{$parameterNameCount}_start", $dateObj->format('Y-m-d 00:00:00'));
                                    $totalItemsQb->setParameter("{$parameterNameCount}_end", $dateObj->format('Y-m-d 23:59:59'));
                                } elseif ($operator === '!=') {
                                    $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} NOT BETWEEN :{$parameterNameCount}_start AND :{$parameterNameCount}_end");
                                    $totalItemsQb->setParameter("{$parameterNameCount}_start", $dateObj->format('Y-m-d 00:00:00'));
                                    $totalItemsQb->setParameter("{$parameterNameCount}_end", $dateObj->format('Y-m-d 23:59:59'));
                                } elseif ($operator === '>') {
                                    $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} > :{$parameterNameCount}");
                                    $totalItemsQb->setParameter($parameterNameCount, $dateObj->format('Y-m-d 23:59:59'));
                                } elseif ($operator === '>=') {
                                    $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} >= :{$parameterNameCount}");
                                    $totalItemsQb->setParameter($parameterNameCount, $dateObj->format('Y-m-d 00:00:00'));
                                } elseif ($operator === '<') {
                                    $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} < :{$parameterNameCount}");
                                    $totalItemsQb->setParameter($parameterNameCount, $dateObj->format('Y-m-d 00:00:00'));
                                } elseif ($operator === '<=') {
                                    $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} <= :{$parameterNameCount}");
                                    $totalItemsQb->setParameter($parameterNameCount, $dateObj->format('Y-m-d 23:59:59'));
                                }
                            } catch (\Exception $e) {
                                // Ignorer les erreurs de date pour le comptage si déjà gérées par la requête principale
                            }
                        } else {
                            $exprMethod = $totalItemsQb->expr()->{'__call'}(strtolower($operator), [$currentAliasCount . '.' . $actualFieldCount, ':' . $parameterNameCount]);
                            $totalItemsQb->andWhere($exprMethod);
                            $paramValue = ($operator === 'LIKE') ? '%' . $filterValue . '%' : $filterValue;
                            $totalItemsQb->setParameter($parameterNameCount, $paramValue);
                        }
                    }
                } else {
                    if (is_string($value) && $value !== '') {
                        $totalItemsQb->andWhere($totalItemsQb->expr()->like($currentAliasCount . '.' . $actualFieldCount, ':' . $parameterNameCount))
                            ->setParameter($parameterNameCount, '%' . $value . '%');
                    }
                }
            }

            // Sélectionner le COUNT de l'identifiant unique (généralement 'id') pour le comptage total.
            // On utilise l'alias de l'entité de comptage 'e_count'.
            $identifierField = $repository->getClassMetadata()->getSingleIdentifierFieldName();
            $totalItemsQb->select("COUNT(DISTINCT {$totalItemsQb->getRootAliases()[0]}.{$identifierField})") // Utilise le premier alias racine (e_count)
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
}
