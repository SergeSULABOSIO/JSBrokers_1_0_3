<?php
namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @trait ControllerUtilsTrait
 * @description Fournit des méthodes utilitaires pour les contrôleurs, notamment pour la validation d'accès et la déduction de noms.
 */
trait ControllerUtilsTrait
{
    /**
     * Valide l'accès à un espace de travail en se basant sur idEntreprise et idInvite.
     *
     * Cette méthode vérifie que l'entreprise et l'invité existent et que l'invité
     * appartient bien à l'entreprise spécifiée. Elle retourne les entités validées.
     *
     * @param Request $request La requête HTTP actuelle.
     * @return array{entreprise: Entreprise, invite: Invite} Un tableau contenant les entités validées.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException Si l'entreprise n'est pas trouvée.
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException Si l'accès est refusé.
     */
    private function validateWorkspaceAccess(Request $request): array
    {
        $idEntreprise = $request->query->get('idEntreprise');
        $idInvite = $request->query->get('idInvite');

        if (!$idEntreprise) {
            $entreprise = $this->getEntreprise();
        } else {
            $entreprise = $this->entrepriseRepository->find($idEntreprise);
        }
        if (!$entreprise) {
            throw $this->createNotFoundException("L'entreprise n'a pas été trouvée pour générer le formulaire.");
        }

        if (!$idInvite) {
            $invite = $this->getInvite();
        } else {
            $invite = $this->inviteRepository->find($idInvite);
        }
        if (!$invite || $invite->getEntreprise()->getId() !== $entreprise->getId()) {
            throw $this->createAccessDeniedException("Vous n'avez pas les droits pour générer ce formulaire.");
        }

        return ['entreprise' => $entreprise, 'invite' => $invite];
    }

    private function getEntreprise(): Entreprise
    {
        /** @var Invite $invite */
        $invite = $this->getInvite();
        return $invite->getEntreprise();
    }

    private function getInvite(): Invite
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException("Utilisateur non authentifié.");
        }
        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());
        if (!$invite) {
            throw $this->createNotFoundException("Aucun invité trouvé pour l'utilisateur actuel.");
        }
        return $invite;
    }

    private function getEntityName(object|string $objectOrClass): string
    {
        $shortClassName = (new \ReflectionClass($objectOrClass))->getShortName();
        return str_replace('Controller', '', $shortClassName);
    }

    private function getServerRootName(object|string $objectOrClass): string
    {
        return strtolower($this->getEntityName($objectOrClass));
    }

    /**
     * Extrait les options d'un widget de collection depuis le canevas de formulaire.
     *
     * @param array $entityFormCanvas Le tableau de configuration du formulaire.
     * @param string $collectionFieldName Le 'field_code' du widget de collection à trouver.
     * @return array Les options trouvées, ou un tableau vide.
     */
    private function getCollectionOptionsFromCanvas(array $entityFormCanvas, string $collectionFieldName): array
    {
        foreach (($entityFormCanvas['form_layout'] ?? []) as $row) {
            foreach (($row['colonnes'] ?? []) as $col) {
                foreach (($col['champs'] ?? []) as $field) {
                    if (is_array($field) && ($field['widget'] ?? null) === 'collection' && ($field['field_code'] ?? null) === $collectionFieldName) {
                        return $field['options'] ?? [];
                    }
                }
            }
        }
        return [];
    }

    /**
     * Finds a parent entity by its ID or returns a new instance if the ID is 0.
     *
     * @param string $entityClass The Fully Qualified Class Name of the entity.
     * @param int $id The ID of the entity to find.
     * @return object A persisted entity or a new instance.
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException If ID is not 0 and entity is not found.
     */
    private function findParentOrNew(string $entityClass, int $id): object
    {
        if ($id === 0) {
            return new $entityClass();
        }

        $entity = $this->em->getRepository($entityClass)->find($id);
        if (!$entity) {
            throw $this->createNotFoundException("L'entité parente de type " . (new \ReflectionClass($entityClass))->getShortName() . " avec l'ID $id n'a pas été trouvée.");
        }
        return $entity;
    }

    /**
     * Renders a list component, choosing between a generic list and a dialog collection list.
     *
     * @param string $usage 'generic' for main lists, 'dialog' for collections in modals.
     * @param string $entityClass The FQCN of the child entity.
     * @param object $parentEntity The parent entity instance.
     * @param int $parentId The ID of the parent entity.
     * @param Collection|array $data The collection of child entities to display.
     * @param string $collectionFieldName The name of the collection field on the parent entity's form canvas.
     * @return Response
     */
    private function renderCollectionOrList(
        string $usage,
        string $entityClass,
        object $parentEntity,
        int $parentId,
        $data,
        string $collectionFieldName
    ): Response {
        $entityCanvas = $this->constante->getEntityCanvas($entityClass);
        $this->constante->loadCalculatedValue($entityCanvas, $data);
        $entityFormCanvas = $this->constante->getEntityFormCanvas($parentEntity, $this->getEntreprise()->getId());

        $template = "components/_" . $usage . "_list_component.html.twig";
        $parameters = [
            'data' => $data,
            'entite_nom' => $this->getEntityName($entityClass),
            'listeCanvas' => $this->constante->getListeCanvas($entityClass),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $entityFormCanvas,
            'serverRootName' => $this->getServerRootName($entityClass),
            'idInvite' => $this->getInvite()->getId(),
            'idEntreprise' => $this->getEntreprise()->getId(),
            'customAddAction' => "click->collection#addItem",
            'parentEntityId' => $parentId,
        ];

        if ($usage === "dialog") {
            $parameters['collectionOptions'] = $this->getCollectionOptionsFromCanvas($entityFormCanvas, $collectionFieldName);
        } else {
            // This part is for the generic list, which is less likely to be used in this context, but we keep it for completeness
        }

        return $this->render($template, $parameters);
    }

    /**
     * Renders either the full view manager or just the list component for a given entity.
     * This method handles both initial page loads (index) and dynamic search queries (query).
     *
     * @param string $entityClass The FQCN of the entity to display.
     * @param Request $request The current HTTP request.
     * @param bool $isQueryResult If true, performs a search and renders only the list content.
     * @return Response
     */
    private function renderViewOrListComponent(string $entityClass, Request $request, bool $isQueryResult = false): Response
    {
        $idInvite = $request->attributes->get('idInvite');
        $idEntreprise = $request->attributes->get('idEntreprise');
        $data = [];
        $reponseData = null;

        if ($isQueryResult) {
            $requestData = json_decode($request->getContent(), true) ?? [];
            $reponseData = $this->searchService->search($requestData);
            $data = $reponseData["data"];
            $template = 'components/_list_content.html.twig';
        } else {
            // NOUVEAU : On ne charge plus les données lors de l'affichage initial.
            // La barre de recherche déclenchera le premier chargement.
            $data = [];
            $template = 'components/_view_manager.html.twig';
        }

        $entityCanvas = $this->constante->getEntityCanvas($entityClass);
        $this->constante->loadCalculatedValue($entityCanvas, $data);

        $parameters = [
            'data' => $data,
            'entite_nom' => $this->getEntityName($entityClass),
            'serverRootName' => $this->getServerRootName($entityClass),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas($entityClass),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new $entityClass(), (int)$idEntreprise), 
            'searchCanvas' => $this->constante->getSearchCanvas($entityClass),
            'numericAttributesAndValues' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // Pass for dynamic queries
            'idInvite' => $idInvite,
            'idEntreprise' => $idEntreprise,
        ];

        if ($isQueryResult && $reponseData) {
            $parameters['status'] = $reponseData["status"];
            $parameters['totalItems'] = $reponseData["totalItems"];
        }
        // dd("Paramètres - searchCanvas:", $parameters["searchCanvas"]);
        return $this->render($template, $parameters);
    }

    /**
     * Renders the form canvas component for creating or editing an entity.
     *
     * @param Request $request The current HTTP request.
     * @param string $entityClass The FQCN of the entity.
     * @param string $formTypeClass The FQCN of the form type.
     * @param ?object $entity The entity instance (from ParamConverter), or null for creation.
     * @param ?callable $initializer A function to set default values on a new entity instance.
     *                               It receives the new entity and the current Invite as arguments.
     * @return Response
     */
    private function renderFormCanvas(
        Request $request,
        string $entityClass,
        string $formTypeClass,
        ?object $entity,
        ?callable $initializer = null
    ): Response {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->validateWorkspaceAccess($request);

        if (!$entity) {
            $entity = new $entityClass();
            if (is_callable($initializer)) {
                // Call the initializer function with the new entity and the invite
                $initializer($entity, $invite);
            }
        }

        $form = $this->createForm($formTypeClass, $entity);
        $entityCanvas = $this->constante->getEntityCanvas($entityClass);
        $this->constante->loadCalculatedValue($entityCanvas, [$entity]);

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $this->constante->getEntityFormCanvas($entity, $entreprise->getId()),
            'entityCanvas' => $entityCanvas,
            'idEntreprise' => $entreprise->getId(),
            'idInvite' => $invite->getId(),
        ]);
    }

    /**
     * Handles the submission of a form via API, for both creation and editing.
     *
     * @param Request $request The current HTTP request.
     * @param string $entityClass The FQCN of the entity.
     * @param string $formTypeClass The FQCN of the form type.
     * @param ?callable $initializer A function to set default values on a new or existing entity before validation.
     *                               It receives the entity instance and the submitted data array as arguments.
     * @return JsonResponse
     */
    private function handleFormSubmission(
        Request $request,
        string $entityClass,
        string $formTypeClass,
        ?callable $initializer = null
    ): JsonResponse {
        $data = $request->request->all();
        $files = $request->files->all();
        $submittedData = array_merge($data, $files);

        $entity = isset($data['id']) && $data['id']
            ? $this->em->getRepository($entityClass)->find($data['id'])
            : new $entityClass();

        if (is_callable($initializer)) {
            $initializer($entity, $submittedData);
        }

        $form = $this->createForm($formTypeClass, $entity);
        $form->submit($submittedData, false);

        if ($form->isSubmitted() && $form->isValid()) {
            if (method_exists($this, 'associateParent')) {
                $this->associateParent($entity, $data, $this->em);
            }
            $this->em->persist($entity);
            $this->em->flush();

            $jsonEntity = $this->serializer->serialize($entity, 'json', ['groups' => 'list:read']);
            return $this->json(['message' => 'Enregistrée avec succès!', 'entity' => json_decode($jsonEntity)]);
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[$error->getOrigin()->getName()][] = $error->getMessage();
        }

        return $this->json(['message' => 'Veuillez corriger les erreurs ci-dessous.', 'errors' => $errors], 422);
    }

    /**
     * Handles the API deletion of any given entity.
     *
     * @param object $entity The entity instance to delete.
     * @return JsonResponse
     */
    private function handleDeleteApi(object $entity): JsonResponse
    {
        try {
            $entityName = $this->getEntityName($entity);
            $this->em->remove($entity);
            $this->em->flush();
            
            // Using (e) to be more generic with gender.
            return $this->json(['message' => ucfirst($entityName) . ' supprimé(e) avec succès.']);
        } catch (\Exception $e) {
            return $this->json(['message' => 'Erreur lors de la suppression.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Doit être implémentée par le contrôleur qui utilise ce Trait.
     * Cette fonction définit la carte de correspondance entre le nom de la collection dans l'URL
     * et la classe de l'entité correspondante pour un contrôleur donné.
     *
     * Exemple :
     * ```php
     * protected function getCollectionMap(): array
     * {
     *     return [
     *         'contacts' => Contact::class,
     *         'pieces' => PieceSinistre::class,
     *     ];
     * }
     * ```
     * @return array<string, string>
     */
    abstract protected function getCollectionMap(): array;

    /**
     * Gère une requête API générique pour une collection liée à une entité parente.
     *
     * @param int $id L'ID de l'entité parente.
     * @param string $collectionName Le nom de la collection (ex: 'contacts', 'pieces').
     * @param string $parentEntityClass Le FQCN de l'entité parente (ex: NotificationSinistre::class).
     * @param string|null $usage Le contexte de rendu ('generic' ou 'dialog').
     * @return Response
     */
    protected function handleCollectionApiRequest(int $id, string $collectionName, string $parentEntityClass, ?string $usage = "generic"): Response
    {
        $collectionMap = $this->getCollectionMap();
        if (!isset($collectionMap[$collectionName])) {
            throw new NotFoundHttpException("La collection '$collectionName' n'existe pas ou n'est pas autorisée.");
        }
        $parentEntity = $this->findParentOrNew($parentEntityClass, $id);
        $getter = 'get' . ucfirst($collectionName);
        if (!method_exists($parentEntity, $getter)) {
            throw new \BadMethodCallException(sprintf('La méthode "%s" n\'existe pas sur l\'entité "%s".', $getter, get_class($parentEntity)));
        }
        $data = $parentEntity->$getter();
        $entityClass = $collectionMap[$collectionName];
        return $this->renderCollectionOrList($usage, $entityClass, $parentEntity, $id, $data, $collectionName);
    }

    /**
     * Construit dynamiquement la carte des collections autorisées pour une entité donnée
     * en inspectant ses métadonnées Doctrine.
     *
     * @param string $entityClass Le FQCN de l'entité à inspecter.
     * @return array<string, string> La carte des collections (nom de la propriété => FQCN de l'entité cible).
     */
    protected function buildCollectionMapFromEntity(string $entityClass): array
    {
        $collectionMap = [];
        // Assure que l'EntityManager est disponible. Il est injecté dans les contrôleurs qui utilisent ce trait.
        if (!property_exists($this, 'em') || !$this->em instanceof EntityManagerInterface) {
            return []; // Retourne une carte vide si l'em n'est pas disponible.
        }
        $metadata = $this->em->getClassMetadata($entityClass);
        foreach ($metadata->getAssociationMappings() as $fieldName => $mapping) {
            if ($mapping['type'] === ClassMetadata::ONE_TO_MANY) {
                $collectionMap[$fieldName] = $mapping['targetEntity'];
            }
        }
        return $collectionMap;
    }

    /**
     * Construit dynamiquement la carte des associations parentes pour une entité enfant donnée
     * en inspectant ses relations ManyToOne.
     *
     * @param string $entityClass Le FQCN de l'entité enfant à inspecter.
     * @return array<string, string> La carte d'association (nom de la propriété => FQCN de l'entité parente).
     */
    protected function buildParentAssociationMapFromEntity(string $entityClass): array
    {
        $parentMap = [];
        if (!property_exists($this, 'em') || !$this->em instanceof EntityManagerInterface) {
            return [];
        }
        $metadata = $this->em->getClassMetadata($entityClass);
        foreach ($metadata->getAssociationMappings() as $fieldName => $mapping) {
            if ($mapping['type'] === ClassMetadata::MANY_TO_ONE) {
                $parentMap[$fieldName] = $mapping['targetEntity'];
            }
        }
        return $parentMap;
    }

    /**
     * Traite récursivement un tableau de configuration (ex: menu) pour transformer
     * les noms de classe complets (FQCN) en noms courts.
     *
     * @param array $data Le tableau de données à traiter.
     * @return array Le tableau de données traité.
     */
    protected function processDataForShortEntityNames(array $data): array
    {
        $process = function (&$array) use (&$process) {
            foreach ($array as $key => &$value) {
                if (is_array($value)) {
                    $process($value); // Appel récursif sur les sous-tableaux
                } elseif ($key === 'entity_name' && is_string($value) && class_exists($value)) {
                    $value = (new \ReflectionClass($value))->getShortName();
                }
            }
        };
        $process($data);
        return $data;
    }

    /**
     * Retourne la carte de correspondance entre les composants Twig et les actions de contrôleur.
     *
     * @return array<string, string|array<string, string>>
     */
    private function getComponentMap(): array
    {
        // Ce tableau était auparavant une constante dans EspaceDeTravailComponentController.
        // Le déplacer ici le rend accessible à tous les contrôleurs utilisant ce trait.
        return [
            '_tableau_de_bord_component.html.twig' => 'App\Controller\Admin\EntrepriseDashbordController::index',
            // FINANCES
            '_view_manager.html.twig' => [
                \App\Entity\Monnaie::class => 'App\Controller\Admin\MonnaieController::index',
                \App\Entity\CompteBancaire::class => 'App\Controller\Admin\CompteBancaireController::index',
                \App\Entity\Taxe::class => 'App\Controller\Admin\TaxeController::index',
                \App\Entity\TypeRevenu::class => 'App\Controller\Admin\TypeRevenuController::index',
                \App\Entity\Tranche::class => 'App\Controller\Admin\TrancheController::index',
                \App\Entity\Chargement::class => 'App\Controller\Admin\ChargementController::index',
                \App\Entity\Note::class => 'App\Controller\Admin\NoteController::index',
                \App\Entity\Paiement::class => 'App\Controller\Admin\PaiementController::index',
                \App\Entity\Bordereau::class => 'App\Controller\Admin\BordereauController::index',
                \App\Entity\RevenuPourCourtier::class => 'App\Controller\Admin\RevenuCourtierController::index',
            ],
            // MARKETING
            '_view_manager_marketing.html.twig' => [
                \App\Entity\Piste::class => 'App\Controller\Admin\PisteController::index',
                \App\Entity\Tache::class => 'App\Controller\Admin\TacheController::index',
                \App\Entity\Feedback::class => 'App\Controller\Admin\FeedbackController::index',
            ],
            // PRODUCTION
            '_view_manager_production.html.twig' => [
                \App\Entity\Groupe::class => 'App\Controller\Admin\GroupeController::index',
                \App\Entity\Client::class => 'App\Controller\Admin\ClientController::index',
                \App\Entity\Assureur::class => 'App\Controller\Admin\AssureurController::index',
                \App\Entity\Contact::class => 'App\Controller\Admin\ContactController::index',
                \App\Entity\Risque::class => 'App\Controller\Admin\RisqueController::index',
                \App\Entity\Avenant::class => 'App\Controller\Admin\AvenantController::index',
                \App\Entity\Partenaire::class => 'App\Controller\Admin\PartenaireController::index',
                \App\Entity\Cotation::class => 'App\Controller\Admin\CotationController::index',
            ],
            // SINISTRE
            '_view_manager_sinistre.html.twig' => [
                \App\Entity\ModelePieceSinistre::class => 'App\Controller\Admin\ModelePieceSinistreController::index',
                \App\Entity\NotificationSinistre::class => 'App\Controller\Admin\NotificationSinistreController::index',
                \App\Entity\OffreIndemnisationSinistre::class => 'App\Controller\Admin\OffreIndemnisationSinistreController::index',
            ],
            // ADMINISTRATION
            '_view_manager_administration.html.twig' => [
                \App\Entity\Document::class => 'App\Controller\Admin\DocumentController::index',
                \App\Entity\Classeur::class => 'App\Controller\Admin\ClasseurController::index',
                \App\Entity\Invite::class => 'App\Controller\Admin\InviteController::index',
            ],
            //PARAMETRES
            '_mon_compte_component.html.twig' => 'App\Controller\RegistrationController::register',
            '_licence_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index', // TODO: A remplacer par le bon contrôleur
        ];
    }

    protected function forwardToComponent(Request $request): Response
    {
        $componentName = $request->query->get('component');
        $entityName = $request->query->get('entity');

        if (!$componentName) {
            return new Response('Nom de composant manquant.', Response::HTTP_BAD_REQUEST);
        }

        $componentMap = $this->getComponentMap();
        $controllerAction = $componentMap[$componentName] ?? null;

        if (is_array($controllerAction)) {
            $actionFound = false;
            foreach ($controllerAction as $classFqcn => $action) {
                if ((new \ReflectionClass($classFqcn))->getShortName() === $entityName) {
                    $controllerAction = $action;
                    $actionFound = true;
                    break;
                }
            }
            if (!$actionFound) {
                return new Response('Action de contrôleur non trouvée pour l\'entité: ' . $entityName, Response::HTTP_NOT_FOUND);
            }
        }

        if (!$controllerAction || !is_string($controllerAction)) {
            return new Response('Composant non autorisé ou action de contrôleur invalide.', Response::HTTP_FORBIDDEN);
        }

        return $this->forward($controllerAction, $request->attributes->all());
    }

    public function loadCalculatedValues($entityCanvas, $entity)
    {
        // --- MODIFICATION : AJOUT DES VALEURS CALCULÉES ---
        foreach ($entityCanvas['liste'] as $field) {
            if ($field['type'] === 'Calcul') {
                $functionName = $field['fonction'];
                $args = []; // Initialiser le tableau d'arguments

                // On vérifie si la clé "params" existe et n'est pas vide
                if (!empty($field['params'])) {
                    // CAS 1 : Des paramètres spécifiques sont listés (logique existante)
                    $paramNames = $field['params'];
                    $args = array_map(function ($paramName) use ($entity) {
                        $getter = 'get' . ucfirst($paramName);
                        if (method_exists($entity, $getter)) {
                            return $entity->$getter();
                        }
                        return null;
                    }, $paramNames);
                } else {
                    // CAS 2 : La clé "params" est absente, on passe l'entité entière
                    $args[] = $entity;
                }

                // On appelle la fonction du service avec les arguments préparés
                if (method_exists($this->constante, $functionName)) {
                    $calculatedValue = $this->constante->$functionName(...$args);
                    // On ajoute le résultat à l'objet entité pour la sérialisation
                    $entity->{$field['code']} = $calculatedValue;
                }
            }
        }
        // --- FIN DE LA MODIFICATION ---
    }

    /**
     * Récupère une collection d'entités d'un type donné après validation.
     *
     * @param string $entityType Le nom court de l'entité (ex: 'Client').
     * @return array La collection d'entités trouvées.
     * @throws AccessDeniedException Si le type d'entité n'est pas autorisé.
     */
    protected function getEntitiesForType(string $entityType): array
    {
        // Sécurité : Vérifier si l'entité est dans la liste autorisée du service de recherche.
        // Note: Il serait préférable que cette liste soit un paramètre de service plutôt qu'une propriété statique.
        if (!in_array($entityType, JSBDynamicSearchService::$allowedEntities)) {
            throw $this->createAccessDeniedException("Cette entité n'est pas accessible.");
        }

        $entityClass = 'App\\Entity\\' . $entityType;
        $repository = $this->em->getRepository($entityClass);

        return $repository->findAll();
    }

    /**
     * Récupère les détails d'une entité spécifique, son canvas, et charge les valeurs calculées.
     *
     * @param string $entityType Le nom court de l'entité (ex: 'Client').
     * @param int $id L'ID de l'entité à récupérer.
     * @return array Un tableau contenant l'entité, son type et son canvas.
     * @throws AccessDeniedException Si le type d'entité n'est pas autorisé.
     * @throws NotFoundHttpException Si l'entité n'est pas trouvée.
     */
    protected function getEntityDetailsForType(string $entityType, int $id): array
    {
        // Sécurité : Vérifier si l'entité est autorisée
        if (!in_array($entityType, JSBDynamicSearchService::$allowedEntities)) {
            throw $this->createAccessDeniedException("Cette entité n'est pas accessible.");
        }

        $entityClass = 'App\\Entity\\' . $entityType;
        $entity = $this->em->getRepository($entityClass)->find($id);

        if (!$entity) {
            throw new NotFoundHttpException("L'entité '$entityType' avec l'ID '$id' n'a pas été trouvée.");
        }

        $entityCanvas = $this->constante->getEntityCanvas($entityClass);
        $this->loadCalculatedValues($entityCanvas, $entity);

        // On retourne le tableau de données prêt à être sérialisé.
        return ['entity' => $entity, 'entityType' => $entityType, 'entityCanvas' => $entityCanvas];
    }
}