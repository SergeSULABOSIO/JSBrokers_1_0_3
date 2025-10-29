<?php
namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;

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
            // The searchService is expected to handle sorting, filtering, and pagination.
            // The user's requirement to sort by most recent should be configured in the search criteria sent by the frontend.
            $reponseData = $this->searchService->search($requestData);
            $data = $reponseData["data"];
            $template = 'components/_list_content.html.twig';
        } else {
            $repository = $this->em->getRepository($entityClass);
            // For initial load, sort by ID DESC to show most recent first.
            $data = $repository->findBy([], ['id' => 'DESC']);
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
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data),
            'idInvite' => $idInvite,
            'idEntreprise' => $idEntreprise,
        ];

        if ($isQueryResult && $reponseData) {
            $parameters['status'] = $reponseData["status"];
            $parameters['totalItems'] = $reponseData["totalItems"];
        }

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
     * @param EntityManagerInterface $em The entity manager.
     * @param SerializerInterface $serializer The serializer.
     * @param ?callable $initializer A function to set default values on a new or existing entity before validation.
     *                               It receives the entity instance and the submitted data array as arguments.
     * @return JsonResponse
     */
    private function handleFormSubmission(
        Request $request,
        string $entityClass,
        string $formTypeClass,
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ?callable $initializer = null
    ): JsonResponse {
        $data = $request->request->all();
        $files = $request->files->all();
        $submittedData = array_merge($data, $files);

        $entity = isset($data['id']) && $data['id']
            ? $em->getRepository($entityClass)->find($data['id'])
            : new $entityClass();

        if (is_callable($initializer)) {
            $initializer($entity, $submittedData);
        }

        $form = $this->createForm($formTypeClass, $entity);
        $form->submit($submittedData, false);

        if ($form->isSubmitted() && $form->isValid()) {
            if (method_exists($this, 'associateParent')) {
                $this->associateParent($entity, $data, $em);
            }
            $em->persist($entity);
            $em->flush();

            $jsonEntity = $serializer->serialize($entity, 'json', ['groups' => 'list:read']);
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
     * @param EntityManagerInterface $em The entity manager.
     * @return JsonResponse
     */
    private function handleDeleteApi(object $entity, EntityManagerInterface $em): JsonResponse
    {
        try {
            $entityName = $this->getEntityName($entity);
            $em->remove($entity);
            $em->flush();
            
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
}