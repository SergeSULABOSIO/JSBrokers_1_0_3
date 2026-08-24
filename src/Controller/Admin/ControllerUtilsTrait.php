<?php

namespace App\Controller\Admin;

use App\Entity\Assureur;
use App\Entity\AutoriteFiscale;
use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\ChargeCourtier;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\ConditionPartage;
use App\Entity\Contact;
use App\Entity\Cotation;
use App\Entity\DepenseCourtier;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Fournisseur;
use App\Entity\Groupe;
use App\Entity\Portefeuille;
use App\Entity\Invite;
use App\Entity\ModelePieceSinistre;
use App\Entity\Monnaie;
use App\Entity\Note;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\PieceSinistre;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Tache;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use App\Token\InsufficientTokensException;
use App\Token\TokenAccountService;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Service\Workspace\WorkspaceMutationService;
use App\Service\Workspace\ChampsObligatoiresInspector;
use App\Service\Workspace\InvitePerimetreNotifier;
use Symfony\Contracts\Service\Attribute\Required;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\SerializerInterface;
use DateTimeImmutable;
use Symfony\Component\Form\FormInterface;

/**
 * @trait ControllerUtilsTrait
 * @description Fournit des méthodes utilitaires pour les contrôleurs, notamment pour la validation d'accès et la déduction de noms.
 *
 * @property EntityManagerInterface $em
 * @property EntrepriseRepository $entrepriseRepository
 * @property InviteRepository $inviteRepository
 * @property JSBDynamicSearchService $searchService
 * @property SerializerInterface $serializer
 *
 * @method Response render(string $view, array $parameters = [], ?Response $response = null)
 * @method string renderView(string $view, array $parameters = [])
 * @method JsonResponse json($data, int $status = 200, array $headers = [], array $context = [])
 * @method Response forward(string $controller, array $path = [], array $query = [])
 * @method FormInterface createForm(string $type, $data = null, array $options = [])
 * @method UserInterface|null getUser()
 * @method NotFoundHttpException createNotFoundException(string $message = '', \Throwable $previous = null)
 * @method AccessDeniedException createAccessDeniedException(string $message = 'Access Denied.', \Throwable $previous = null)
 * @method string generateUrl(string $route, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH)
 */
trait ControllerUtilsTrait
{
    protected CanvasBuilder $canvasBuilder;

    /**
     * Service de métrage des tokens. Injecté par setter autowiré (#[Required])
     * pour couvrir TOUS les contrôleurs utilisant ce trait sans toucher à leurs
     * constructeurs respectifs.
     */
    private TokenAccountService $tokenAccountService;

    #[Required]
    public function setTokenAccountService(TokenAccountService $tokenAccountService): void
    {
        $this->tokenAccountService = $tokenAccountService;
    }

    /**
     * Contrôle d'accès de l'espace de travail selon les rôles de l'invité connecté.
     * Injectés par setter autowiré (#[Required]) — même mécanique que le métrage de
     * tokens — pour couvrir TOUS les contrôleurs du trait sans toucher aux constructeurs.
     */
    private WorkspaceAccessResolver $workspaceAccessResolver;
    private InvitePerimetreNotifier $invitePerimetreNotifier;

    #[Required]
    public function setWorkspaceAccessResolver(WorkspaceAccessResolver $workspaceAccessResolver): void
    {
        $this->workspaceAccessResolver = $workspaceAccessResolver;
    }

    #[Required]
    public function setInvitePerimetreNotifier(InvitePerimetreNotifier $invitePerimetreNotifier): void
    {
        $this->invitePerimetreNotifier = $invitePerimetreNotifier;
    }

    /**
     * Fabrique du critère « Mon portefeuille ». SOURCE UNIQUE partagée avec les outils de
     * l'assistant IA : les listes du workspace et Ket posent littéralement le même critère,
     * donc répondent le même nombre à la même question.
     */
    private \App\Services\Search\PortefeuilleCritereFactory $portefeuilleCritereFactory;

    #[Required]
    public function setPortefeuilleCritereFactory(\App\Services\Search\PortefeuilleCritereFactory $factory): void
    {
        $this->portefeuilleCritereFactory = $factory;
    }

    /**
     * Cœur de mutation partagé (métrage + persistance / suppression). Injecté par
     * setter autowiré (#[Required]) comme les autres services du trait. Point de
     * passage UNIQUE réutilisé aussi par l'assistant IA (DRY) : le CRUD HTTP et
     * l'exécuteur de Ket écrivent/suppriment par les mêmes gardes.
     */
    private WorkspaceMutationService $workspaceMutationService;

    #[Required]
    public function setWorkspaceMutationService(WorkspaceMutationService $workspaceMutationService): void
    {
        $this->workspaceMutationService = $workspaceMutationService;
    }

    /**
     * Payload des fiches : relations du canevas que la sérialisation ne publie pas.
     * Injecté par setter autowiré (#[Required]) comme les autres services du trait —
     * aucun constructeur de contrôleur à toucher.
     */
    private \App\Services\Canvas\CanvasRelationHydrator $canvasRelationHydrator;

    #[Required]
    public function setCanvasRelationHydrator(\App\Services\Canvas\CanvasRelationHydrator $hydrator): void
    {
        $this->canvasRelationHydrator = $hydrator;
    }

    /**
     * Inspecteur des champs obligatoires (source UNIQUE de la notion « obligatoire »,
     * dérivée des métadonnées Doctrine et partagée avec l'assistant IA). Injecté par
     * setter autowiré (#[Required]) comme les autres services du trait. Permet de
     * transformer un champ obligatoire vide en erreur 422 propre au lieu d'un 500 au
     * flush — la validation HTML5 native étant neutralisée dans les modales.
     */
    private ChampsObligatoiresInspector $champsObligatoiresInspector;

    #[Required]
    public function setChampsObligatoiresInspector(ChampsObligatoiresInspector $champsObligatoiresInspector): void
    {
        $this->champsObligatoiresInspector = $champsObligatoiresInspector;
    }

    /**
     * Dernier identifiant d'un chemin de propriété (« a.b[nom] » → « nom »), pour
     * rattacher une erreur de liaison au bon champ de formulaire.
     */
    private function extraireChampChemin(string $propertyPath): string
    {
        return preg_match('/([A-Za-z_][A-Za-z0-9_]*)\]?$/', $propertyPath, $m) ? $m[1] : $propertyPath;
    }

    /**
     * L'invité connecté a-t-il le droit d'agir au niveau $level sur cette entité ?
     * Point de contrôle unique réutilisé par tous les points de passage CRUD génériques.
     */
    private function mayAccessEntity(object|string $entityOrClass, int $level): bool
    {
        return $this->workspaceAccessResolver->can(
            $this->getInvite(),
            $this->getEntityName($entityOrClass),
            $level
        );
    }

    /** Panneau « Accès restreint » rendu dans la colonne de travail (lecture refusée). */
    private function accessDeniedComponent(object|string $entityOrClass): Response
    {
        return $this->render('components/_access_denied.html.twig', [
            'entiteNom' => $this->getEntityName($entityOrClass),
        ]);
    }

    /** Réponse JSON 403 servie quand une mutation est hors périmètre (même forme que 402 tokens). */
    private function accessDeniedJson(): JsonResponse
    {
        return $this->json([
            'message' => "Action hors de votre périmètre d'accès. Contactez le propriétaire de l'espace de travail pour ajuster vos droits.",
            'blocked' => true,
        ], Response::HTTP_FORBIDDEN);
    }

    /**
     * Retire du canevas de formulaire les points d'entrée d'action (submit/delete) hors
     * périmètre : la barre d'outils masque alors automatiquement Ajouter/Modifier/
     * Supprimer (elle se base sur endpoint_submit_url / endpoint_delete_url). Aucun
     * template ni JS à modifier ; la sécurité reste garantie côté serveur.
     */
    private function applyPermissionToFormCanvas(array $canvas, object|string $entityOrClass): array
    {
        $invite = $this->getInvite();
        $short = $this->getEntityName($entityOrClass);

        $canWrite = $this->workspaceAccessResolver->can($invite, $short, Invite::ACCESS_ECRITURE)
            || $this->workspaceAccessResolver->can($invite, $short, Invite::ACCESS_MODIFICATION);
        $canDelete = $this->workspaceAccessResolver->can($invite, $short, Invite::ACCESS_SUPPRESSION);

        if (!$canWrite) {
            unset($canvas['parametres']['endpoint_submit_url']);
        }
        if (!$canDelete) {
            unset($canvas['parametres']['endpoint_delete_url']);
        }

        return $canvas;
    }

    /**
     * Notifie l'invité concerné quand son périmètre change (enregistrement d'un rôle).
     * Ne se déclenche QUE pour les entités de rôle RolesEn* : une édition de la fiche
     * invité (nom/email) n'émet donc aucun e-mail. Tolérant aux pannes.
     */
    private function notifyPerimetreIfRoleEntity(?object $roleInvite): void
    {
        if ($roleInvite instanceof Invite) {
            $this->invitePerimetreNotifier->notify($roleInvite);
        }
    }

    /**
     * Le code de champ $fieldCode est-il déjà présent dans le layout de formulaire ?
     * Les champs sont soit une chaîne, soit un tableau avec la clé 'field_code'.
     */
    /**
     * Masque, dans le layout, la rangée qui porte un champ donné.
     *
     * La rangée entière quand elle ne contient que lui — c'est le cas courant, et le
     * gabarit sait déjà rendre `hidden` (le champ compte alors parmi les « faits » rappelés
     * en tête du dialogue). Sinon, le seul champ, pour ne pas emporter ses voisins avec lui.
     */
    private function masquerLaRangeeDuChamp(array &$formLayout, string $fieldCode): void
    {
        foreach ($formLayout as $indexRangee => $rangee) {
            $champsDeLaRangee = 0;
            $porteLeChamp = false;

            foreach (($rangee['colonnes'] ?? []) as $col) {
                foreach (($col['champs'] ?? []) as $field) {
                    $champsDeLaRangee++;
                    $code = is_array($field) ? ($field['field_code'] ?? null) : $field;
                    if ($code === $fieldCode) {
                        $porteLeChamp = true;
                    }
                }
            }

            if (!$porteLeChamp) {
                continue;
            }

            if ($champsDeLaRangee === 1) {
                $formLayout[$indexRangee]['hidden'] = true;

                return;
            }

            // Rangée partagée : on ne masque que le champ concerné, en normalisant sa
            // déclaration (une chaîne devient une configuration) pour pouvoir lui poser
            // l'attribut de rangée.
            foreach (($rangee['colonnes'] ?? []) as $indexCol => $col) {
                foreach (($col['champs'] ?? []) as $indexChamp => $field) {
                    $code = is_array($field) ? ($field['field_code'] ?? null) : $field;
                    if ($code !== $fieldCode) {
                        continue;
                    }
                    $config = is_array($field) ? $field : ['field_code' => $field];
                    $classe = trim(($config['options']['row_attr']['class'] ?? '') . ' d-none');
                    $config['options']['row_attr']['class'] = $classe;
                    $formLayout[$indexRangee]['colonnes'][$indexCol]['champs'][$indexChamp] = $config;
                }
            }

            return;
        }
    }
    private function layoutHasField(array $formLayout, string $fieldCode): bool
    {
        foreach ($formLayout as $row) {
            foreach (($row['colonnes'] ?? []) as $col) {
                foreach (($col['champs'] ?? []) as $field) {
                    $code = is_array($field) ? ($field['field_code'] ?? null) : $field;
                    if ($code === $fieldCode) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** L'invité cible d'une entité de rôle RolesEn*, sinon null (pour l'e-mail de périmètre). */
    private function roleTargetInvite(object $entity): ?Invite
    {
        if (str_starts_with($this->getEntityName($entity), 'RolesEn') && method_exists($entity, 'getInvite')) {
            $target = $entity->getInvite();

            return $target instanceof Invite ? $target : null;
        }

        return null;
    }

    /** Construit la réponse JSON 402 servie quand le solde de tokens est épuisé. */
    private function tokensBlockedJson(InsufficientTokensException $e): JsonResponse
    {
        return $this->json([
            'message'       => "Quota de tokens épuisé. Rechargez votre solde ou attendez le renouvellement de votre allocation gratuite.",
            'blocked'       => true,
            'required'      => $e->required,
            'available'     => $e->available,
            'nextRenewalAt' => $e->nextRenewalAt?->format(DateTimeImmutable::ATOM),
        ], Response::HTTP_PAYMENT_REQUIRED);
    }
    /**
     * Valide l'accès à un espace de travail en se basant sur idEntreprise et idInvite.
     *
     * Cette méthode vérifie que l'entreprise et l'invité existent et que l'invité
     * appartient bien à l'entreprise spécifiée. Elle retourne les entités validées.
     * @return array{entreprise: Entreprise, invite: Invite} Un tableau contenant les entités validées.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException Si l'entreprise n'est pas trouvée.
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException Si l'accès est refusé.
     */
    private function validateWorkspaceAccess(int $idEntreprise, int $idInvite): array
    {
        $entreprise = $this->entrepriseRepository->find($idEntreprise) ?? throw $this->createNotFoundException("L'entreprise n'a pas été trouvée pour générer le formulaire.");
        $invite = $idInvite
            ? ($this->inviteRepository->find($idInvite) ?? throw $this->createNotFoundException("L'invité n'a pas été trouvé."))
            : $this->getInvite();

        if ($invite->getEntreprise()->getId() !== $entreprise->getId()) {
            throw $this->createAccessDeniedException("L'invité n'appartient pas à l'entreprise spécifiée.");
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

        // L'entreprise active est celle du workspace courant (connectedTo), synchronisée à chaque
        // entrée de workspace par EspaceDeTravailComponentController. Un utilisateur peut posséder
        // plusieurs Invite (un par entreprise) : sans ce filtre, findOneBy renverrait un invité
        // arbitraire, souvent celui d'une entreprise déjà quittée — ce qui faussait notamment le SOA.
        $criteria = ['utilisateur' => $user];
        $connectedTo = $user->getConnectedTo();
        if ($connectedTo !== null) {
            $criteria['entreprise'] = $connectedTo;
        }

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneBy($criteria);

        // Filet de sécurité : si aucun invité ne correspond à l'entreprise connectée (données
        // incohérentes), on retombe sur l'ancien comportement pour ne pas casser l'accès.
        if (!$invite && $connectedTo !== null) {
            $invite = $this->inviteRepository->findOneBy(['utilisateur' => $user]);
        }

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
        // OPTIMISATION : On vérifie d'abord si une carte aplatie des champs existe.
        // Cette carte est générée par le FormCanvasProvider pour de meilleures performances.
        if (isset($entityFormCanvas['fields_map'][$collectionFieldName])) {
            $fieldConfig = $entityFormCanvas['fields_map'][$collectionFieldName];
            // On s'assure que le champ trouvé est bien un widget de collection
            if (($fieldConfig['widget'] ?? null) === 'collection') {
                return $fieldConfig['options'] ?? [];
            }
        }

        // Fallback : Si la carte n'existe pas ou si le champ n'est pas une collection,
        // on utilise l'ancienne méthode de recherche pour assurer la rétrocompatibilité.
        foreach (($entityFormCanvas['form_layout'] ?? []) as $row) {
            foreach (($row['colonnes'] ?? []) as $col) {
                // La colonne peut contenir directement un champ ou un tableau de champs
                $champs = $col['champs'] ?? (is_array($col) ? [$col] : []);
                foreach ($champs as $field) {
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
    /**
     * Rend UNE ligne de collection pour une entité qui n'est pas encore en base.
     *
     * Strictement le gabarit des lignes enregistrées (`components/_list_row.html.twig`),
     * avec les mêmes canevas : même pastille d'entité, mêmes colonnes, mêmes actions. La
     * création et la modification ne doivent pas offrir deux habillages pour la même chose.
     *
     * Les indicateurs calculés sont chargés comme pour n'importe quelle ligne — ils vaudront
     * simplement zéro, l'entité n'ayant encore aucune histoire.
     *
     * @param array $data la charge soumise, d'où proviennent les options d'action de ligne
     */
    private function rendreLigneEnAttente(object $entity, string $entityClass, array $data): string
    {
        $entityCanvas = $this->canvasBuilder->getEntityCanvas($entityClass);
        $this->loadCalculatedValues($entityCanvas, $entity);

        return $this->renderView('components/_list_row.html.twig', [
            'entity' => $entity,
            'listeCanvas' => $this->canvasBuilder->getListeCanvas($entityClass),
            'entite_nom' => $this->getEntityName($entityClass),
            'entityCanvas' => $entityCanvas,
            // « dialog » : c'est le contexte d'une collection, celui qui rend la colonne
            // d'actions à droite de la ligne.
            'usage' => 'dialog',
            'listActionOptions' => [
                // Une collection de RATTACHEMENT dit « Retirer » plutôt que « Supprimer ».
                // Le navigateur transmet ces libellés : ils vivent dans la configuration du
                // widget, que le serveur ne connaît pas depuis ce point d'entrée.
                'deleteActionLabel' => $data['ligne_delete_label'] ?? null,
                'deleteActionIcon' => $data['ligne_delete_icon'] ?? null,
                // Une ligne en attente ne s'ouvre pas : elle n'existe nulle part encore.
                'hideEditAction' => true,
            ],
        ]);
    }
    private function renderCollectionOrList(
        string $usage,
        string $entityClass,
        object $parentEntity,
        int $parentId,
        $data,
        string $collectionFieldName,
        ?string $totalizableField = null,
        ?string $secondaryField = null,
        ?string $secondaryLabel = null,
        int $page = 1,
        int $limit = 20,
        array $listActionOptions = []
    ): Response {
        // Pagination pour les onglets génériques (non dialog).
        $dataArray = ($data instanceof \Doctrine\Common\Collections\Collection) ? $data->toArray() : (array)$data;
        $dataArray = array_reverse($dataArray); // derniers éléments (ID le plus élevé) en premier

        // PÉRIMÈTRE PORTEFEUILLE : les onglets de collection appliquent la même logique
        // de filtrage par défaut que les listes principales (getInitialSearchCriteria) :
        // pour une entité enfant scopable (Cotation, Tâche…), seuls les éléments rattachés
        // à un portefeuille géré par l'invité sont affichés au chargement. Le critère est
        // renvoyé au frontend (badge « Mon portefeuille » retirable) et le moteur de
        // recherche le ré-applique aux refresh/paginations. Sans objet pour les dialogues
        // (édition des éléments du parent).
        $initialSearchCriteria = [];
        if ($usage !== 'dialog') {
            $initialSearchCriteria = $this->getInitialSearchCriteria($entityClass, $this->getInvite()->getId(), $this->getEntreprise());
            if ($initialSearchCriteria !== []) {
                $dataArray = $this->filterByPortefeuilleScope($dataArray, $entityClass, $this->getInvite()->getId());
            }
        }
        $totalItems = count($dataArray);
        if ($usage !== 'dialog') {
            $data = array_slice($dataArray, ($page - 1) * $limit, $limit);
        }
        $paginationMeta = ($usage !== 'dialog') ? [
            'currentPage'  => $page,
            'totalPages'   => max(1, (int)ceil($totalItems / $limit)),
            'totalItems'   => $totalItems,
            'itemsPerPage' => $limit,
        ] : ['currentPage' => 1, 'totalPages' => 1, 'totalItems' => $totalItems, 'itemsPerPage' => $totalItems ?: 20];

        $entityCanvas = $this->canvasBuilder->getEntityCanvas($entityClass);
        foreach ($data as $item) {
            $this->loadCalculatedValues($entityCanvas, $item);
        }
        // CORRECTION : Le list-manager de la collection a besoin du formCanvas de l'entité qu'il gère (l'enfant),
        // et non celui du parent, pour que la barre des totaux et la barre d'outils fonctionnent correctement.
        $entityFormCanvas = $this->canvasBuilder->getEntityFormCanvas(new $entityClass(), $this->getEntreprise()->getId());

        // dd($entityFormCanvas);

        // CORRECTION : Le template a été renommé '_list_manager.html.twig'.
        // On utilise directement le nom correct au lieu de le construire dynamiquement.
        $template = "components/_list_manager.html.twig";
        // NOUVEAU : On génère un ID unique et prédictible pour la liste de l'onglet.
        $listId = 'collection-' . $collectionFieldName . '-for-' . $parentId;

        // NOUVEAU : Récupérer les détails du champ totalisable s'il existe.
        $totalizableFieldDetails = null;
        if ($totalizableField) {
            foreach ($entityCanvas['liste'] as $fieldDef) {
                if ($fieldDef['code'] === $totalizableField) {
                    $totalizableFieldDetails = [
                        'description' => $fieldDef['description'] ?? '',
                        'unite' => $fieldDef['unite'] ?? ''
                    ];
                    break;
                }
            }
        }

        // Récupérer les détails du champ secondaire pour le formatage (ex: date)
        $secondaryFieldDetails = null;
        if ($secondaryField) {
            foreach (($entityCanvas['liste'] ?? []) as $fieldDef) {
                if (($fieldDef['code'] ?? null) === $secondaryField) {
                    $secondaryFieldDetails = $fieldDef;
                    break;
                }
            }
        }

        $parameters = [
            'listId' => $listId, // NOUVEAU : ID unique pour le contrôleur list-manager.
            'can_add' => true, // On autorise l'ajout pour les listes de collection
            'data' => $data,
            'usage' => $usage,
            'entite_nom' => $this->getEntityName($entityClass),
            'listeCanvas' => $this->canvasBuilder->getListeCanvas($entityClass),
            'entityCanvas' => $entityCanvas,
            'numericAttributesAndValues' => $this->canvasBuilder->getNumericAttributesAndValuesForCollection($data), // NOUVEAU : Pour la barre des totaux.
            'entityFormCanvas' => $entityFormCanvas,
            // Canvas de recherche de l'entité ENFANT : permet à la barre de recherche
            // (partagée dans l'entête) de basculer ses critères quand l'onglet de
            // collection devient actif.
            'searchCanvas' => $this->canvasBuilder->getSearchCanvas($entityClass),
            'serverRootName' => $this->getServerRootName($entityClass),
            'idInvite' => $this->getInvite()->getId(),
            'idEntreprise' => $this->getEntreprise()->getId(),
            'customAddAction' => "click->collection#addItem",
            'parentEntityId' => $parentId,
            'parentEntity' => $parentEntity,
            'totalizableField' => $totalizableField,
            'secondaryField' => $secondaryField,
            'secondaryLabel' => $secondaryLabel,
            'totalizableFieldDetails' => $totalizableFieldDetails,
            'secondaryFieldDetails' => $secondaryFieldDetails,
            'paginationMeta' => $paginationMeta,
            'listActionOptions' => $listActionOptions,
            // Amorce l'état du Cerveau pour cet onglet (badge « Mon portefeuille »
            // + persistance du périmètre en refresh/pagination), comme en liste principale.
            'initialSearchCriteria' => $initialSearchCriteria,
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
    private function renderViewOrListComponent(string $entityClass, Request $request, bool $isQueryResult = false, array $extraCriteria = []): Response
    {
        $idInvite = $request->attributes->get('idInvite');
        $idEntreprise = $request->attributes->get('idEntreprise');
        $data = [];

        // CONTRÔLE D'ACCÈS (lecture) : l'invité connecté doit avoir le droit de lecture
        // sur cette entité. Sinon, panneau « Accès restreint » (chargement initial) ou
        // 403 JSON (rafraîchissement de liste), sans casser la coquille de l'espace.
        if (!$this->mayAccessEntity($entityClass, Invite::ACCESS_LECTURE)) {
            return $isQueryResult ? $this->accessDeniedJson() : $this->accessDeniedComponent($entityClass);
        }

        // CAS 1: C'est une requête API pour rafraîchir la liste (recherche, etc.)
        if ($isQueryResult) {
            $entreprise = $this->entrepriseRepository->find($idEntreprise);
            if (!$entreprise) {
                return new JsonResponse(['error' => 'Entreprise non trouvée.'], Response::HTTP_BAD_REQUEST);
            }
            // CORRECTION : Le payload contient maintenant 'criteria', 'parentContext' et 'page'.
            $payload = json_decode($request->getContent(), true) ?? [];
            $criteria = $payload['criteria'] ?? [];
            $criteria = array_merge($criteria, $extraCriteria);
            $parentContext = $payload['parentContext'] ?? null;
            $page = max(1, (int)($payload['page'] ?? 1));

            // Appel du service de recherche refactorisé et sécurisé
            // CORRECTION : On passe le contexte du parent au service de recherche.
            $searchResult = $this->searchService->search($entityClass, $criteria, $entreprise, $parentContext, $page, 20);
            $data = $searchResult['data'];

            // MÉTRAGE TOKENS (lecture) : bloquant. Chaque entité envoyée vers le
            // frontend pèse 2 tokens, débités au propriétaire de l'entreprise.
            try {
                $this->tokenAccountService->meterRead($entityClass, count($data), $entreprise, $this->getUser());
            } catch (InsufficientTokensException $e) {
                return $this->tokensBlockedJson($e);
            }

            // Preload batch avant le calcul des indicateurs (évite N×M lazy-loads pour Cotation/Avenant).
            $this->canvasBuilder->batchPreloadForCollection($data);

            // CORRECTION : Charger les valeurs calculées pour chaque entité de la liste.
            // C'est le chaînon manquant qui empêchait les attributs comme 'compensation' d'être calculés.
            $entityCanvas = $this->canvasBuilder->getEntityCanvas($entityClass);
            foreach ($data as $item) {
                $this->loadCalculatedValues($entityCanvas, $item);
            }

            // Extraction des données numériques et rendu du HTML partiel de la liste
            $numericData = $this->canvasBuilder->getNumericAttributesAndValuesForCollection($data);
            // OPTIMISATION : On ne passe au template que les variables strictement nécessaires.
            $html = $this->renderView('components/_list_content.html.twig', [
                'data' => $data,
                'listeCanvas' => $this->canvasBuilder->getListeCanvas($entityClass),
                'entite_nom' => $this->getEntityName($entityClass), // CORRECTION : On passe le nom de l'entité
                'entityCanvas' => $this->canvasBuilder->getEntityCanvas($entityClass) // CORRECTION : On passe le canvas de l'entité
            ]);

            // On retourne la réponse JSON structurée attendue par le Cerveau
            return new JsonResponse([
                'html'                       => $html,
                'numericAttributesAndValues' => $numericData,
                'pagination'                 => [
                    'currentPage'  => $searchResult['currentPage'],
                    'totalPages'   => $searchResult['totalPages'],
                    'totalItems'   => $searchResult['totalItems'],
                    'itemsPerPage' => $searchResult['itemsPerPage'],
                ],
            ]);
        }
        // CAS 2: C'est le chargement initial de la page, on rend le conteneur principal.
        else {
            $template = 'components/_view_manager.html.twig';
            // Chargement automatique de la première page (20 premiers éléments).
            $entreprise = $this->entrepriseRepository->find($idEntreprise);
            // Filtre(s) actif(s) par défaut au premier chargement (ex. rubrique Client :
            // périmètre du portefeuille de l'invité). Vide pour la plupart des entités.
            // Le même jeu de critères est renvoyé au frontend pour amorcer l'état du Cerveau
            // (badge de filtre + persistance en pagination), et rester retirable par l'usager.
            $initialCriteria = [];
            if ($entreprise) {
                $initialCriteria = $this->getInitialSearchCriteria($entityClass, (int)$idInvite, $entreprise);
                $searchResult = $this->searchService->search($entityClass, $initialCriteria, $entreprise, null, 1, 20);
                $data = $searchResult['data'];

                // MÉTRAGE TOKENS (lecture) au chargement initial de la liste. En cas
                // d'épuisement, on remplace le contenu par un panneau de blocage tout
                // en conservant la coquille de l'espace de travail.
                try {
                    $this->tokenAccountService->meterRead($entityClass, count($data), $entreprise, $this->getUser());
                } catch (InsufficientTokensException $e) {
                    return $this->render('components/_tokens_blocked.html.twig', [
                        'nextRenewalAt' => $e->nextRenewalAt,
                        'required'      => $e->required,
                        'available'     => $e->available,
                    ]);
                }

                $paginationMeta = [
                    'currentPage'  => 1,
                    'totalPages'   => $searchResult['totalPages'],
                    'totalItems'   => $searchResult['totalItems'],
                    'itemsPerPage' => 20,
                ];
            } else {
                $data = [];
                $paginationMeta = ['currentPage' => 1, 'totalPages' => 0, 'totalItems' => 0, 'itemsPerPage' => 20];
            }
        }

        // S'assurer que le canevas de l'entité est chargé.
        $entityCanvas = $this->canvasBuilder->getEntityCanvas($entityClass);
        $this->canvasBuilder->batchPreloadForCollection($data);
        foreach ($data as $item) {
            $this->loadCalculatedValues($entityCanvas, $item);
        }

        // CORRECTION : Forcer un objet vide si les attributs numériques sont un tableau vide.
        // Cela évite une erreur JS dans Stimulus qui attend un objet `{}` et non un tableau `[]`.
        $numericAttributesAndValues = $this->canvasBuilder->getNumericAttributesAndValuesForCollection($data);
        if (empty($numericAttributesAndValues)) {
            $numericAttributesAndValues = new \stdClass();
        }

        // NOUVEAU : Construire l'URL de la liste principale pour la persistance de l'état.
        // C'est l'URL que le list-manager utilisera pour s'identifier.
        $mainListUrl = $this->generateUrl('admin.' . $this->getServerRootName($entityClass) . '.app_dynamic_query', ['idInvite' => $idInvite, 'idEntreprise' => $idEntreprise]);

        $parameters = [
            'data' => $data,
            'entite_nom' => $this->getEntityName($entityClass),
            'serverRootName' => $this->getServerRootName($entityClass),
            'listeCanvas' => $this->canvasBuilder->getListeCanvas($entityClass),
            'entityCanvas' => $entityCanvas,
            // Le canevas de formulaire est filtré selon le périmètre : sans droit
            // d'écriture/modification (ou de suppression), les points d'entrée
            // correspondants sont retirés et la barre d'outils masque les actions.
            'entityFormCanvas' => $this->applyPermissionToFormCanvas(
                $this->canvasBuilder->getEntityFormCanvas(new $entityClass(), (int)$idEntreprise),
                $entityClass
            ),
            'searchCanvas' => $this->canvasBuilder->getSearchCanvas($entityClass), // Pass for dynamic queries
            'numericAttributesAndValues' => $numericAttributesAndValues,
            'idInvite' => $idInvite,
            'idEntreprise' => $idEntreprise,
            'mainListUrl' => $mainListUrl, // On passe l'URL au template
            'paginationMeta' => $paginationMeta ?? ['currentPage' => 1, 'totalPages' => 0, 'totalItems' => 0, 'itemsPerPage' => 20],
            // Critères actifs par défaut (amorçage de l'état Cerveau + badge de filtre).
            'initialSearchCriteria' => $initialCriteria ?? [],
        ];

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
        // CORRECTION : On extrait les IDs de la requête pour les passer à la méthode de validation.
        $entreprise = $this->getEntreprise();
        $invite = $this->getInvite();
        $isCreationMode = ($entity === null);

        // CONTRÔLE D'ACCÈS (ouverture du formulaire) : créer exige l'Écriture, éditer
        // exige la Modification. Pour les invités et les rôles, exige la gestion des
        // invités (propriétaire ou délégué), géré par le resolver.
        $requiredLevel = $isCreationMode ? Invite::ACCESS_ECRITURE : Invite::ACCESS_MODIFICATION;
        if (!$this->mayAccessEntity($entity ?? $entityClass, $requiredLevel)) {
            return $this->accessDeniedComponent($entityClass);
        }

        if (!$entity) {
            $entity = new $entityClass();
            // On appelle l'initialiseur pour définir les valeurs par défaut (ex: Entreprise, dates, etc.)
            if (is_callable($initializer)) {
                // Call the initializer function with the new entity and the invite
                $initializer($entity, $invite);
            }
        }

        // Preload batch des relations avant le calcul des indicateurs (entité existante uniquement).
        if ($entity->getId() !== null) {
            $this->canvasBuilder->batchPreloadForCollection([$entity]);
        }
        // LE PARENT EST POSÉ AVANT QUE LE CANEVAS NE SOIT CONSTRUIT.
        //
        // Un canevas se bâtit à partir de l'entité : c'est ce qui permet à un provider de
        // dire « cette rangée n'a de sens que dans une affaire ». L'entité étant remplie
        // APRÈS, elle arrivait vierge au provider, et toute règle qui dépendait du parent
        // se trouvait ignorée — sans erreur : le champ concerné tombait simplement en bas
        // du formulaire via render_rest, sans la condition de visibilité qui devait le
        // gouverner. C'est ce qui laissait le sélecteur d'agent à l'écran alors que la
        // part revenait à l'intermédiaire.
        $parentFieldName = $request->query->get('parent_field_name');
        $parentId = $request->query->get('parent_id');

        if ($isCreationMode && $parentFieldName && $parentId) {
            $setter = 'set' . ucfirst($parentFieldName);
            $parentMap = $this->buildParentAssociationMapFromEntity($entityClass);
            if (method_exists($entity, $setter) && isset($parentMap[$parentFieldName])) {
                $parentClass = $parentMap[$parentFieldName];
                $parentEntity = $this->em->getRepository($parentClass)->find($parentId);
                if ($parentEntity) {
                    $entity->$setter($parentEntity);
                }
            }
        }

        // Hydratation avant la génération du canevas pour que le Provider
        // évalue correctement le solde (évite le solde NULL).
        $this->loadCalculatedValues(null, $entity);

        $formCanvas = $this->canvasBuilder->getEntityFormCanvas($entity, $entreprise->getId());

        if ($isCreationMode && $parentFieldName && $parentId) {
            // LE PARENT EST CONNU : on ne le demande pas, on le rappelle.
            //
            // Ouvrir « Ajouter un contact » depuis la fiche d'un client ne laisse aucun
            // doute sur le client concerné. Lui présenter malgré tout un champ « Client
            // associé » — parfois développé en carte, avec les chiffres du client — c'est
            // poser une question dont la réponse est déjà donnée, et occuper le haut du
            // formulaire avec elle. Le parent reste rappelé en tête du dialogue, sous forme
            // de fait (`parentContextFacts`), qui est sa place.
            //
            // Deux situations, un seul résultat :
            //  - le provider ne déclare pas le champ → on l'injecte, masqué ;
            //  - il le déclare (RolesEn* pour « invite », Contact pour « client »
            //    ...) → on masque SA rangée, plutôt que d'en ajouter une seconde qui
            //    provoquerait « Field has already been rendered ».
            if (isset($formCanvas['form_layout']) && is_array($formCanvas['form_layout'])) {
                if ($this->layoutHasField($formCanvas['form_layout'], $parentFieldName)) {
                    $this->masquerLaRangeeDuChamp($formCanvas['form_layout'], $parentFieldName);
                } else {
                    // Le masquage passe par le DRAPEAU DE RANGÉE, seul honoré par le
                    // gabarit. `row_attr` ne l'était pas : il n'est transmis à aucun des
                    // deux chemins de rendu, si bien que le champ parent restait visible
                    // — silencieusement, et depuis toujours. Le drapeau, lui, fait aussi
                    // remonter le parent parmi les FAITS rappelés en tête du dialogue.
                    array_unshift($formCanvas['form_layout'], [
                        "hidden" => true,
                        "colonnes" => [["champs" => [$parentFieldName]]]
                    ]);
                }
            }
        }

        // CONTEXTE PARENT (présentation uniquement) : rappel, dans l'entête contextuel
        // du formulaire, des objets parents déjà rattachés à l'entité (associations
        // ManyToOne) — indispensable en ÉDITION, où le parent n'est ni injecté dans le
        // layout ni masqué (ex. la tâche d'un feedback). Lecture best-effort, aucune
        // incidence métier. L'entreprise (contexte implicite du workspace) et
        // l'utilisateur (déjà porté par les attributs calculés) sont exclus.
        $parentContextFacts = [];
        foreach ($this->buildParentAssociationMapFromEntity($entityClass) as $parentField => $parentClass) {
            if (in_array($parentField, ['entreprise', 'utilisateur'], true)) {
                continue;
            }
            $getter = 'get' . ucfirst($parentField);
            if (!method_exists($entity, $getter)) {
                continue;
            }
            try {
                $parentEntity = $entity->$getter();
            } catch (\Throwable) {
                continue;
            }
            if (!is_object($parentEntity)) {
                continue;
            }
            $display = $this->describeEntityForContext($parentEntity);
            if ($display !== null) {
                $parentContextFacts[$parentField] = $display;
            }
        }

        $form = $this->createForm($formTypeClass, $entity);
        $entityCanvas = $this->canvasBuilder->getEntityCanvas($entityClass);

        // dd("idEntreprise:", $entreprise->getId(), "idInvite:", $invite->getId(), "isCreationMode:", $isCreationMode, "canvas:", $formCanvas);

        return $this->render('components/dialog/_form_content.html.twig', [
            'form' => $form->createView(),
            'formCanvas' => $formCanvas, // On passe le canvas potentiellement modifié.
            'entityCanvas' => $entityCanvas,
            'entity' => $entity,
            'isCreationMode' => $isCreationMode,
            'idEntreprise' => $entreprise->getId(),
            'idInvite' => $invite->getId(),
            'parentContextFacts' => $parentContextFacts,
        ]);
    }

    /**
     * Handles the submission of a form via API, for both creation and editing.
     *
     * @param Request $request The current HTTP request.
     * @param string $entityClass The FQCN of the entity.
     * @param string $formTypeClass The FQCN of the form type.
     * @param ?callable $beforePersist A function to execute logic on the entity before persisting it.
     *                                 It receives the entity instance as argument.
     * @return JsonResponse
     */
    private function handleFormSubmission(
        Request $request,
        string $entityClass,
        string $formTypeClass,
        ?callable $beforePersist = null
    ): JsonResponse {
        $data = $request->request->all();
        $files = $request->files->all();
        $submittedData = array_merge($data, $files);

        $isCreationMode = !isset($data['id']) || !$data['id'];
        $entity = $isCreationMode ? new $entityClass() : $this->em->getRepository($entityClass)->find($data['id'] ?? null);

        if (!$entity) {
            throw $this->createNotFoundException("L'entité avec l'ID " . ($data['id'] ?? 'null') . " n'a pas été trouvée.");
        }

        // CORRECTION : On extrait les IDs des données soumises pour valider l'accès.
        // On utilise les données du formulaire (`$data`) plutôt que la requête pour plus de fiabilité.
        $idEntreprise = isset($data['idEntreprise']) ? (int)$data['idEntreprise'] : 0;
        $idInvite = isset($data['idInvite']) ? (int)$data['idInvite'] : 0;
        ['entreprise' => $currentEntreprise, 'invite' => $currentInvite] = $this->validateWorkspaceAccess($idEntreprise, $idInvite);

        // CONTRÔLE D'ACCÈS (mutation) : création → Écriture, édition → Modification.
        // Pour Invite et les rôles RolesEn*, le resolver exige la gestion des invités.
        $requiredLevel = $isCreationMode ? Invite::ACCESS_ECRITURE : Invite::ACCESS_MODIFICATION;
        if (!$this->mayAccessEntity($entity, $requiredLevel)) {
            return $this->accessDeniedJson();
        }

        if ($isCreationMode) {
            // Renseigne automatiquement l'entreprise et l'invité pour les nouvelles entités
            // si elles utilisent AuditableTrait et que ces champs ne sont pas déjà définis.
            if (method_exists($entity, 'setEntreprise') && (method_exists($entity, 'getEntreprise') && $entity->getEntreprise() === null)) {
                $entity->setEntreprise($currentEntreprise);
            }
            if (method_exists($entity, 'setInvite') && (method_exists($entity, 'getInvite') && $entity->getInvite() === null)) {
                $entity->setInvite($currentInvite);
            }
            // CORRECTION CRUCIALE : Pour les formulaires complexes comme ArticleType, le contexte
            // (la Note parente) est nécessaire AVANT la soumission pour que les QueryBuilders des
            // champs Autocomplete (Revenu, Tranche) puissent valider les IDs soumis.
            // On pré-hydrate l'entité avec son parent si on est en mode création.
            $parentMap = $this->buildParentAssociationMapFromEntity($entityClass);
            foreach ($parentMap as $parentField => $parentClass) {
                if (isset($data[$parentField]) && !empty($data[$parentField])) {
                    $parentEntity = $this->em->getRepository($parentClass)->find($data[$parentField]);
                    if ($parentEntity) {
                        $setter = 'set' . ucfirst($parentField);
                        if (method_exists($entity, $setter)) {
                            $entity->$setter($parentEntity);
                        }
                    }
                }
            }
        }

        $form = $this->createForm($formTypeClass, $entity);

        // CORRECTION : S'assure que les champs à choix multiples (checkboxes) sont bien vidés.
        // Quand un champ 'multiple' est soumis sans aucune option cochée, sa clé est absente des données POST.
        // Le paramètre `clearMissing=false` dans `submit()` empêchait alors la mise à jour, conservant l'ancienne valeur.
        // En ajoutant manuellement une clé avec un tableau vide pour les champs multiples absents,
        // on force le formulaire à enregistrer la sélection vide.
        foreach ($form->all() as $child) {
            $config = $child->getConfig();
            if ($config->getOption('multiple') === true && !array_key_exists($child->getName(), $submittedData)) {
                $submittedData[$child->getName()] = [];
            }
        }
        // GARDE-FOU LIAISON : un champ obligatoire soumis VIDE est converti en `null`
        // par Symfony (empty_data), que le setter typé non-nullable de l'entité refuse
        // → TypeError levée DANS submit(), AVANT toute validation (500 opaque). On la
        // rattrape en erreur de champ PROPRE (422), pour TOUS les formulaires à la fois.
        try {
            $form->submit($submittedData, false); // Le paramètre `false` est maintenant sûr.
        } catch (\Symfony\Component\PropertyAccess\Exception\InvalidTypeException $e) {
            $champ = $this->extraireChampChemin($e->propertyPath);
            $message = $e->actualType === 'null'
                ? 'Ce champ est obligatoire.'
                : 'La valeur fournie pour ce champ est invalide.';

            return $this->json([
                'message' => 'Veuillez corriger les erreurs ci-dessous.',
                'errors' => [$champ => [$message]],
                'labels' => [$champ => $this->champsObligatoiresInspector->libelleChamp($entityClass, $champ)],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // GARDE-FOU OBLIGATION (générique, source unique partagée avec l'assistant IA) :
        // la validation HTML5 native étant neutralisée dans les modales, un champ
        // obligatoire (colonne non-nullable, sans défaut) laissé vide échouerait au
        // flush Doctrine (500). On le rattrape ici en erreur de champ PROPRE.
        //
        // Périmètre du contrôle, choisi pour n'introduire AUCUN faux positif :
        //  - CRÉATION : tous les champs du formulaire (un obligatoire absent = manquant) ;
        //  - ÉDITION  : uniquement les champs RÉELLEMENT SOUMIS (que l'utilisateur a
        //    touchés). On ne bloque donc jamais l'édition d'un autre champ à cause d'une
        //    valeur préexistante vide (donnée héritée), tout en rattrapant le cas où
        //    l'utilisateur vide lui-même un champ obligatoire.
        // Dans les deux cas, restreint aux champs EXPOSÉS par le formulaire : un champ
        // obligatoire renseigné ailleurs (beforePersist, auto-scoping entreprise/invité)
        // n'est jamais signalé à tort.
        $champsFormulaire = array_keys($form->all());
        $champsPilotables = $isCreationMode
            ? $champsFormulaire
            : array_values(array_intersect($champsFormulaire, array_keys($data)));
        $champsManquants = $this->champsObligatoiresInspector->champsManquants($entity, $champsPilotables);

        if ($form->isSubmitted() && $form->isValid() && empty($champsManquants)) {
            // NOUVEAU : Logique pour associer l'entreprise et/ou l'invité si les IDs sont fournis
            // NOUVEAU : Exécuter une logique personnalisée avant la persistance (ex: définir l'auteur)

            // NOUVEAU : Vérification anti-doublon générique avant la persistance.
            // Si la méthode de vérification existe sur le contrôleur, on l'appelle.
            if (method_exists($this, 'checkForDuplicates')) {
                $duplicateError = $this->checkForDuplicates($entity);
                if ($duplicateError) {
                    return $this->json([
                        'message' => $duplicateError['message'],
                        'errors' => ['' => [$duplicateError['message']]] // Erreur globale
                    ], Response::HTTP_UNPROCESSABLE_ENTITY); // 422
                }
            }


            if ($beforePersist) {
                $beforePersist($entity);
            }

            // CORRECTION & ROBUSTESSE : Remplacement de la méthode 'associateParent' ambiguë.
            // Cette nouvelle logique garantit l'association correcte pour les entités polymorphes
            // comme Document ou Tache, en lisant directement les données brutes de la requête.
            // Elle est appelée après la validation du formulaire mais avant la persistance,
            // ce qui est le moment idéal pour établir la liaison parente.
            $parentMap = $this->buildParentAssociationMapFromEntity($entityClass);
            foreach ($parentMap as $parentField => $parentClass) {
                // On vérifie si un champ correspondant à une relation parente (ex: 'cotation', 'piste')
                // est présent et non vide dans les données soumises.
                if (isset($data[$parentField]) && !empty($data[$parentField])) {
                    $parentEntity = $this->em->getRepository($parentClass)->find($data[$parentField]);
                    if ($parentEntity) {
                        $setter = 'set' . ucfirst($parentField);
                        if (method_exists($entity, $setter)) {
                            // On appelle le setter (ex: setCotation()) sur l'entité enfant.
                            $entity->$setter($parentEntity);
                        }
                    }
                }
            }

            // VALIDATION SEULE — le dialogue veut savoir si la saisie TIENDRAIT.
            //
            // Une collection dont le parent n'existe pas encore garde ses éléments en
            // mémoire du navigateur jusqu'à l'enregistrement de l'ancêtre. Elle a pourtant
            // besoin de dire TOUT DE SUITE si la saisie est recevable, sinon l'utilisateur
            // découvrirait ses erreurs bien plus tard, sur un formulaire qu'il a fermé.
            //
            // On s'arrête donc ICI, après que le formulaire a passé TOUS les contrôles —
            // Symfony, champs obligatoires, périmètre workspace — et juste avant d'écrire.
            // Aucun régime de validation parallèle n'est créé : c'est le même chemin, une
            // ligne plus tôt. Et comme rien n'est écrit, rien n'est facturé : un solde de
            // tokens épuisé n'empêche plus de SAISIR, seulement d'enregistrer.
            if ($request->request->getBoolean('dry_run')) {
                return $this->json([
                    'valide' => true,
                    // LA LIGNE EST RENDUE ICI, PAR LE MÊME GABARIT QUE LES AUTRES.
                    //
                    // Sans cela, le navigateur devrait reconstruire un tableau de son côté :
                    // il RESSEMBLERAIT aux lignes enregistrées sans jamais leur être
                    // identique, et divergerait au premier changement du gabarit. Deux
                    // habillages pour une même chose, selon qu'on crée ou qu'on modifie.
                    //
                    // L'entité est complète — elle vient de passer toute la validation — mais
                    // n'a pas d'id : le tampon posera le sien sur la ligne.
                    'ligne' => $this->rendreLigneEnAttente($entity, $entityClass, $data),
                ]);
            }

            // MÉTRAGE TOKENS (écriture) + PERSISTANCE : bloquant, via le point de
            // passage unique partagé avec l'assistant IA (WorkspaceMutationService).
            // On débite le propriétaire AVANT la persistance ; solde épuisé => 402,
            // rien n'est enregistré.
            try {
                $this->workspaceMutationService->commitWrite($entity, $currentEntreprise, $this->getUser());
            } catch (InsufficientTokensException $e) {
                return $this->tokensBlockedJson($e);
            }

            // NOTIFICATION PÉRIMÈTRE : si un rôle (RolesEn*) vient d'être enregistré, on
            // informe l'invité concerné de son nouveau périmètre d'action. Ne se déclenche
            // pas pour une simple édition de la fiche invité (routée par InviteController).
            $this->notifyPerimetreIfRoleEntity($this->roleTargetInvite($entity));

            // NOUVEAU : Charger les valeurs calculées après la persistance
            // pour que la réponse JSON contienne une entité complète, y compris
            // les champs qui ne sont pas directement en base de données.
            $this->loadCalculatedValues(null, $entity);

            $jsonEntity = $this->serializer->serialize($entity, 'json', [
                'groups' => 'list:read',
                'circular_reference_handler' => fn($object) => method_exists($object, 'getId') ? $object->getId() : null,
            ]);
            return $this->json(['message' => 'Enregistrée avec succès!', 'entity' => json_decode($jsonEntity)]);
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[$error->getOrigin()->getName()][] = $error->getMessage();
        }
        // Champs obligatoires vides non déjà signalés par une contrainte du formulaire.
        foreach ($champsManquants as $champ => $messages) {
            if (!isset($errors[$champ])) {
                $errors[$champ] = $messages;
            }
        }

        // Libellés LISIBLES (tels qu'affichés dans le formulaire) pour que le dialogue
        // nomme chaque champ fautif côté client — plutôt que son code technique.
        $labels = [];
        foreach (array_keys($errors) as $champ) {
            if ($champ === '') {
                continue; // erreur globale : pas de champ associé.
            }
            $labels[$champ] = $this->champsObligatoiresInspector->libelleChamp($entityClass, $champ);
        }

        return $this->json([
            'message' => 'Veuillez corriger les erreurs ci-dessous.',
            'errors' => $errors,
            'labels' => $labels,
        ], 422);
    }

    /**
     * Handles the API deletion of any given entity.
     *
     * @param object $entity The entity instance to delete.
     * @return JsonResponse
     */
    private function handleDeleteApi(object $entity): JsonResponse
    {
        // CONTRÔLE D'ACCÈS (suppression) : exige le droit de Suppression sur l'entité
        // (ou la gestion des invités pour Invite / RolesEn*).
        if (!$this->mayAccessEntity($entity, Invite::ACCESS_SUPPRESSION)) {
            return $this->accessDeniedJson();
        }

        // On mémorise l'invité cible AVANT suppression (pour l'e-mail de périmètre si
        // c'est un rôle RolesEn* — après remove(), la relation n'est plus lisible).
        $roleInvite = $this->roleTargetInvite($entity);

        try {
            $entityName = $this->getEntityName($entity);
            // Suppression via le point de passage unique partagé (DRY).
            $this->workspaceMutationService->commitDelete($entity);

            // Périmètre réduit : on notifie l'invité concerné le cas échéant.
            $this->notifyPerimetreIfRoleEntity($roleInvite);

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
    protected function handleCollectionApiRequest(int $id, string $collectionName, string $parentEntityClass, ?string $usage = "generic", ?Request $request = null): Response
    {
        $collectionMap = $this->getCollectionMap();
        if (!isset($collectionMap[$collectionName])) {
            throw new NotFoundHttpException("La collection '$collectionName' n'existe pas ou n'est pas autorisée.");
        }
        // CONTRÔLE D'ACCÈS (lecture) sur l'entité de la collection (ex. les rôles
        // RolesEn* d'un invité exigent la gestion des invités).
        if (!$this->mayAccessEntity($collectionMap[$collectionName], Invite::ACCESS_LECTURE)) {
            throw $this->createAccessDeniedException("Cette collection n'est pas dans votre périmètre d'accès.");
        }
        $page = ($request !== null) ? max(1, $request->query->getInt('page', 1)) : 1;
        $parentEntity = $this->findParentOrNew($parentEntityClass, $id);

        // Preload batch des relations avant le calcul des indicateurs (évite N lazy-loads).
        $this->canvasBuilder->batchPreloadForCollection([$parentEntity]);
        // Hydratation systématique pour maintenir l'état du bouton d'ajout.
        $this->loadCalculatedValues(null, $parentEntity);

        // NOUVEAU : Déterminer si la collection est totalisable en inspectant le canvas du parent.
        $parentFormCanvas = $this->canvasBuilder->getEntityFormCanvas($parentEntity, $this->getEntreprise()->getId());
        $collectionOptions = $this->getCollectionOptionsFromCanvas($parentFormCanvas, $collectionName);
        $totalizableField = $collectionOptions['totalizableField'] ?? null;
        $secondaryField = $collectionOptions['secondaryField'] ?? null;
        $secondaryLabel = $collectionOptions['secondaryLabel'] ?? null;
        // Personnalisation des actions de ligne (ex. portefeuille : « Retirer » au lieu de
        // « Supprimer », et pas de bouton d'édition). Valeurs par défaut = comportement
        // historique (édition + suppression standard).
        $listActionOptions = [
            'hideEditAction'    => (bool) ($collectionOptions['hideEditAction'] ?? false),
            'deleteActionLabel' => $collectionOptions['deleteActionLabel'] ?? null,
            'deleteActionIcon'  => $collectionOptions['deleteActionIcon'] ?? null,
        ];

        // --- NOUVELLE LOGIQUE DE RÉPONSE JSON ---
        if ($usage === 'dialog') {
            $totalValue = null;
            $totalUnit = '';
            $data = $parentEntity->{'get' . ucfirst($collectionName)}();
            $entityClass = $collectionMap[$collectionName];

            // PARENT PAS ENCORE EN BASE, MAIS SÉLECTION DÉJÀ FAITE.
            //
            // Une collection de RATTACHEMENT (sélecteur) désigne des entités qui existent
            // déjà : en création, le navigateur en garde les identifiants en mémoire et nous
            // les passe ici. On rend alors la liste EXACTEMENT comme si elle était rattachée
            // — même gabarit, même pastille, mêmes actions.
            //
            // C'est ce qui évite un second habillage, reconstruit côté navigateur, qui
            // divergerait du premier dès que le gabarit changerait.
            $idsChoisis = array_filter(array_map('intval', explode(',', (string) $request?->query->get('ids', ''))));
            if ($id === 0 && $idsChoisis !== []) {
                $data = new \Doctrine\Common\Collections\ArrayCollection(
                    $this->em->getRepository($entityClass)->findBy([
                        'id' => $idsChoisis,
                        // Scoping d'entreprise : on ne rend visible que ce qui appartient à
                        // l'espace de travail, même quand les identifiants viennent du client.
                        'entreprise' => $this->getEntreprise(),
                    ]),
                );
            }

            // Preload inconditionnel — couvre totalizableField ET les collections sans champ totalisable.
            $this->canvasBuilder->batchPreloadForCollection($data->toArray());

            if ($totalizableField) {
                $total = 0;
                $fieldName = $totalizableField;
                $camelCaseField = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName))));
                $valueGetter = 'get' . ucfirst($camelCaseField);

                foreach ($data as $item) {
                    $this->canvasBuilder->loadAllCalculatedValues($item);
                    $value = property_exists($item, $fieldName) ? ($item->{$fieldName} ?? 0) : 0;
                    $total += $value;
                }
                $totalValue = $total;

                $entityCanvas = $this->canvasBuilder->getEntityCanvas($entityClass);
                foreach ($entityCanvas['liste'] as $fieldDef) {
                    if ($fieldDef['code'] === $totalizableField && isset($fieldDef['unite'])) {
                        $totalUnit = $fieldDef['unite'];
                        break;
                    }
                }
            }

            $html = $this->renderCollectionOrList('dialog', $entityClass, $parentEntity, $id, $data, $collectionName, $totalizableField, $secondaryField, $secondaryLabel, 1, PHP_INT_MAX, $listActionOptions)->getContent();

            return new JsonResponse([
                'html' => $html,
                'totalValue' => $totalValue,
                'totalUnit' => $totalUnit,
                'itemCount' => count($data),
                'isDisabled' => $collectionOptions['disabled'] ?? false
            ]);
        }

        $getter = 'get' . ucfirst($collectionName);
        if (!method_exists($parentEntity, $getter)) {
            throw new \BadMethodCallException(sprintf('La méthode "%s" n\'existe pas sur l\'entité "%s".', $getter, get_class($parentEntity)));
        }
        $data = $parentEntity->$getter();
        $entityClass = $collectionMap[$collectionName];
        // Preload des relations avant renderCollectionOrList (évite N×M lazy-loads).
        $this->canvasBuilder->batchPreloadForCollection($data->toArray());
        return $this->renderCollectionOrList($usage, $entityClass, $parentEntity, $id, $data, $collectionName, $totalizableField, $secondaryField, $secondaryLabel, $page, 20, $listActionOptions);
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
            if ($mapping['type'] === ClassMetadata::ONE_TO_MANY || $mapping['type'] === ClassMetadata::MANY_TO_MANY) {
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
     * Libellé de présentation d'une entité pour les puces de contexte de l'entête
     * de formulaire (best-effort : nom / libellé / titre / référence / code, sinon
     * description tronquée, sinon #id). Présentation uniquement — aucune logique métier.
     */
    private function describeEntityForContext(object $entity): ?string
    {
        $base = null;
        foreach (['getNom', 'getLibelle', 'getTitre', 'getReference', 'getCode', 'getDescription'] as $getter) {
            if (method_exists($entity, $getter)) {
                $value = $entity->$getter();
                if (is_string($value) && trim(strip_tags($value)) !== '') {
                    $base = trim(strip_tags($value));
                    break;
                }
            }
        }
        $id = method_exists($entity, 'getId') ? $entity->getId() : null;
        if ($base === null && $id === null) {
            return null;
        }
        if ($base !== null && mb_strlen($base) > 60) {
            $base = mb_substr($base, 0, 57) . '…';
        }
        if ($base === null) {
            return sprintf('#%d', $id);
        }

        return $id !== null ? sprintf('%s (#%d)', $base, $id) : $base;
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
            '_tableau_de_bord_component.html.twig' => 'App\Controller\Admin\EntrepriseDashbordController::loadWorkspaceComponent',
            // FINANCES
            '_view_manager.html.twig' => [
                Monnaie::class => 'App\Controller\Admin\MonnaieController::index',
                CompteBancaire::class => 'App\Controller\Admin\CompteBancaireController::index',
                AutoriteFiscale::class => 'App\Controller\Admin\AutoriteFiscaleController::index',
                Taxe::class => 'App\Controller\Admin\TaxeController::index',
                TypeRevenu::class => 'App\Controller\Admin\TypeRevenuController::index',
                Tranche::class => 'App\Controller\Admin\TrancheController::index',
                Chargement::class => 'App\Controller\Admin\ChargementController::index',
                ChargementPourPrime::class => 'App\Controller\Admin\ChargementPourPrimeController::index',
                Note::class => 'App\Controller\Admin\NoteController::index',
                Paiement::class => 'App\Controller\Admin\PaiementController::index',
                Bordereau::class => 'App\Controller\Admin\BordereauController::index',
                RevenuPourCourtier::class => 'App\Controller\Admin\RevenuPourCourtierController::index',
                ChargeCourtier::class => 'App\Controller\Admin\ChargeCourtierController::index',
                DepenseCourtier::class => 'App\Controller\Admin\DepenseCourtierController::index',
                Fournisseur::class => 'App\Controller\Admin\FournisseurController::index',
            ],
            // MARKETING
            '_view_manager_marketing.html.twig' => [
                Piste::class => 'App\Controller\Admin\PisteController::index',
                Tache::class => 'App\Controller\Admin\TacheController::index',
                Feedback::class => 'App\Controller\Admin\FeedbackController::index',
            ],
            // PRODUCTION
            '_view_manager_production.html.twig' => [
                Groupe::class => 'App\Controller\Admin\GroupeController::index',
                Portefeuille::class => 'App\Controller\Admin\PortefeuilleController::index',
                Client::class => 'App\Controller\Admin\ClientController::index',
                Assureur::class => 'App\Controller\Admin\AssureurController::index',
                Contact::class => 'App\Controller\Admin\ContactController::index',
                Risque::class => 'App\Controller\Admin\RisqueController::index',
                Avenant::class => 'App\Controller\Admin\AvenantController::index',
                ConditionPartage::class => 'App\Controller\Admin\ConditionPartageController::index',
                Partenaire::class => 'App\Controller\Admin\PartenaireController::index',
                Cotation::class => 'App\Controller\Admin\CotationController::index',
                // Rétros agents : rubrique de consultation. L'oublier
                // ici laisse la rubrique dans le menu et l'onglet vide (404 silencieux).
                ReversementRetroAgent::class => 'App\Controller\Admin\ReversementRetroAgentController::index',
            ],
            // SINISTRE
            '_view_manager_sinistre.html.twig' => [
                ModelePieceSinistre::class => 'App\Controller\Admin\ModelePieceSinistreController::index',
                PieceSinistre::class => 'App\Controller\Admin\PieceSinistreController::index',
                NotificationSinistre::class => 'App\Controller\Admin\NotificationSinistreController::index',
                OffreIndemnisationSinistre::class => 'App\Controller\Admin\OffreIndemnisationSinistreController::index',
            ],
            // ADMINISTRATION
            '_view_manager_administration.html.twig' => [
                Document::class => 'App\Controller\Admin\DocumentController::index',
                Classeur::class => 'App\Controller\Admin\ClasseurController::index',
                Invite::class => 'App\Controller\Admin\InviteController::index',
            ],
            //PARAMETRES
            '_mon_compte_component.html.twig' => 'App\Controller\RegistrationController::register',
            '_licence_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index', // TODO: A remplacer par le bon contrôleur
            // SUPPORT (self-service courtier → file CrmTicket de la console)
            '_support_component.html.twig' => 'App\Controller\Admin\SupportController::loadWorkspaceComponent',
            // DOCUMENTS COMPTABLES (états OHADA du courtier, générés à la volée)
            '_document_comptable_component.html.twig' => 'App\Controller\Admin\DocumentComptableWorkspaceController::loadWorkspaceComponent',
            // GROUPE IA : assistant conversationnel + paramètres du personnage
            '_assistant_ia_component.html.twig' => 'App\Controller\Admin\AssistantIaController::loadWorkspaceComponent',
            '_assistant_ia_parametres_component.html.twig' => 'App\Controller\Admin\AssistantIaController::loadParametresComponent',
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

    /**
     * Charge les valeurs calculées pour une entité ou pour toutes les entités d'un canvas.
     *
     * @param array|null $entityCanvas Le canvas de l'entité si disponible.
     * @param object $entity L'entité pour laquelle charger les valeurs calculées.
     */
    public function loadCalculatedValues($entityCanvas, $entity)
    {
        // La logique est maintenant entièrement déléguée au CanvasBuilder.
        // Le paramètre $entityCanvas est conservé pour la compatibilité avec les appels existants,
        // mais la nouvelle méthode du builder n'en a plus besoin car elle le récupère elle-même.
        if (is_object($entity) && method_exists($this->canvasBuilder, 'loadAllCalculatedValues')) {
            $this->canvasBuilder->loadAllCalculatedValues($entity);
        }
    }

    /**
     * Récupère une collection d'entités d'un type donné après validation.
     *
     * @param string $entityType Le nom court de l'entité (ex: 'Client').
     * @return array La collection d'entités trouvées.
     * @throws AccessDeniedException Si le type d'entité n'est pas autorisé.
     */
    /**
     * Critères de recherche actifs par défaut au premier chargement d'une liste.
     *
     * Pour les rubriques soumises au « périmètre portefeuille » (cf. PortefeuilleScope :
     * Client, Piste, Cotation, Avenant, Sinistres, Tâche, Feedback…), on applique par défaut
     * le critère synthétique « Mon portefeuille » = l'invité connecté. L'invité ne voit alors
     * que les éléments rattachés — directement ou indirectement — à un portefeuille qu'il
     * gère. Le critère reste retirable (badge de la barre ou champ du dialogue avancé) ; le
     * libellé décrit le périmètre réel (nom du portefeuille, nombre, ou « aucun portefeuille »).
     *
     * @return array<string, mixed>
     */
    protected function getInitialSearchCriteria(string $entityClass, int $idInvite, \App\Entity\Entreprise $entreprise): array
    {
        $shortName = (new \ReflectionClass($entityClass))->getShortName();
        $criteria = [];

        // Tranches : au premier chargement, la rubrique montre ce que les CLIENTS doivent
        // encore (prime impayée), trié par urgence (retard décroissant puis échéances
        // proches). Un seul axe est posé : les dettes de commission et de rétro ont leur
        // propre groupe de chips, à un clic. Critère retirable comme les autres (badge de la
        // barre ou dialogue avancé) → retour à « Toutes ».
        // Se COMBINE avec le périmètre portefeuille ci-dessous (Tranche y est soumise
        // au même titre que Cotation/Avenant : les deux badges coexistent).
        if ($shortName === 'Tranche') {
            $criteria += \App\Services\Search\TranchePaiementScope::critereRecherche(
                'Tranche',
                [\App\Services\Search\TranchePaiementScope::AXE_PRIME => \App\Services\Search\TranchePaiementScope::IMPAYEE],
            );
        }

        // Cotations (propositions) : au premier chargement, on met en avant le travail
        // commercial restant → les propositions « En attente » (non transformées en police).
        // Critère retirable comme les autres (badge de la barre ou dialogue avancé) → « Toutes ».
        // Se COMBINE avec le périmètre portefeuille ci-dessous (les deux badges coexistent).
        if ($shortName === 'Cotation') {
            $criteria[\App\Services\Search\CotationSouscriptionScope::CRITERION_KEY] = [
                'operator' => '=',
                'value' => \App\Services\Search\CotationSouscriptionScope::STATUT_EN_ATTENTE,
                'label' => \App\Services\Search\CotationSouscriptionScope::libelle(\App\Services\Search\CotationSouscriptionScope::STATUT_EN_ATTENTE),
            ];
        }

        // Pistes (opportunités) : au premier chargement, on met en avant le travail commercial
        // restant → les pistes « En cours » (aucune cotation encore transformée en police).
        // Même logique que Cotation, un cran plus haut. Retirable (badge/dialogue) → « Toutes »,
        // et se COMBINE avec le périmètre portefeuille ci-dessous.
        if ($shortName === 'Piste') {
            $criteria[\App\Services\Search\PisteTransformationScope::CRITERION_KEY] = [
                'operator' => '=',
                'value' => \App\Services\Search\PisteTransformationScope::STATUT_EN_COURS,
                'label' => \App\Services\Search\PisteTransformationScope::libelle(\App\Services\Search\PisteTransformationScope::STATUT_EN_COURS),
            ];
        }

        // Périmètre portefeuille : critère produit par la fabrique partagée avec les outils
        // de l'assistant IA (source unique — cf. PortefeuilleCritereFactory), pour que la
        // rubrique et Ket ne puissent plus diverger.
        $criteria += $this->portefeuilleCritereFactory->pourInviteId($shortName, $idInvite);

        // Avenants : au premier chargement, on met en avant l'urgence réelle. Les avenants
        // DÉJÀ EXPIRÉS sont les plus urgents à traiter → si le périmètre de l'invité en
        // contient au moins un, le chip « Échus » est actif par défaut ; sinon « Sous 30
        // jours ». La sonde réutilise le moteur de recherche (même interception SQL + même
        // scope, dont le portefeuille déjà posé ci-dessus) : zéro logique de scope dupliquée.
        // Critère retirable comme les autres (badge/dialogue avancé), et il se COMBINE au
        // périmètre portefeuille (les deux badges coexistent, comme pour Tranche).
        if ($shortName === 'Avenant') {
            $echeanceScope = \App\Services\Search\AvenantEcheanceScope::class;
            $sonde = $criteria;
            $sonde[$echeanceScope::CRITERION_KEY] = [
                'operator' => '=', 'value' => $echeanceScope::STATUT_ECHUS,
            ];
            $aDesExpires = ($this->searchService->search($entityClass, $sonde, $entreprise, null, 1, 1)['totalItems'] ?? 0) > 0;

            $statutParDefaut = $aDesExpires ? $echeanceScope::STATUT_ECHUS : $echeanceScope::STATUT_30J;
            $criteria[$echeanceScope::CRITERION_KEY] = [
                'operator' => '=',
                'value' => $statutParDefaut,
                'label' => $echeanceScope::libelle($statutParDefaut),
            ];
        }

        return $criteria;
    }

    /**
     * Applique en mémoire le « périmètre portefeuille » à une collection déjà chargée
     * (onglets de collection : les éléments viennent du getter du parent, pas du moteur
     * de recherche). Même sémantique que JSBDynamicSearchService : l'élément est retenu
     * si AU MOINS un des chemins de PortefeuilleScope mène à un portefeuille dont le
     * gestionnaire est l'invité connecté.
     *
     * @param array<int, object> $items
     * @return array<int, object>
     */
    protected function filterByPortefeuilleScope(array $items, string $entityClass, int $idInvite): array
    {
        $shortName = (new \ReflectionClass($entityClass))->getShortName();
        $paths = \App\Services\Search\PortefeuilleScope::pathsFor($shortName);
        if ($paths === []) {
            return $items;
        }

        return array_values(array_filter($items, function (object $item) use ($paths, $idInvite): bool {
            foreach ($paths as $path) {
                $value = $item;
                foreach (explode('.', $path) as $segment) {
                    if (!is_object($value)) {
                        $value = null;
                        break;
                    }
                    $getter = 'get' . ucfirst($segment);
                    $value = method_exists($value, $getter) ? $value->$getter() : null;
                    if ($value === null) {
                        break;
                    }
                }
                if ($value instanceof \App\Entity\Invite && $value->getId() === $idInvite) {
                    return true;
                }
            }
            return false;
        }));
    }

    protected function getEntitiesForType(string $entityType): array
    {
        // Sécurité : Vérifier si l'entité est dans la liste autorisée du service de recherche.
        // Note: Il serait préférable que cette liste soit un paramètre de service plutôt qu'une propriété statique.
        if (!in_array($entityType, JSBDynamicSearchService::$allowedEntities)) {
            throw $this->createAccessDeniedException("Cette entité n'est pas accessible.");
        }

        $entityClass = 'App\\Entity\\' . $entityType;

        // CONTRÔLE D'ACCÈS (lecture) : hors périmètre → accès refusé.
        if (!$this->mayAccessEntity($entityClass, Invite::ACCESS_LECTURE)) {
            throw $this->createAccessDeniedException("Cette entité n'est pas dans votre périmètre d'accès.");
        }

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

        // CONTRÔLE D'ACCÈS (lecture) : ouverture du détail (colonne de visualisation).
        if (!$this->mayAccessEntity($entityClass, Invite::ACCESS_LECTURE)) {
            throw $this->createAccessDeniedException("Cette entité n'est pas dans votre périmètre d'accès.");
        }

        $entity = $this->em->getRepository($entityClass)->find($id);

        if (!$entity) {
            throw new NotFoundHttpException("L'entité '$entityType' avec l'ID '$id' n'a pas été trouvée.");
        }

        // Hydratation avant la génération du canevas pour les calculs de solde.
        $this->loadCalculatedValues(null, $entity);

        $entityCanvas = $this->canvasBuilder->getEntityCanvas($entityClass);
        // Cet appel unique charge maintenant TOUTES les valeurs calculées,
        // y compris les indicateurs spécifiques.
        $this->loadCalculatedValues($entityCanvas, $entity);

        // On retourne le tableau de données prêt à être sérialisé. L'entité passe par
        // l'hydrateur : il la normalise (mêmes groupes list:read qu'avant) ET y ajoute
        // les relations que le canevas déclare mais que la sérialisation ne publie pas
        // — sans quoi l'accordéon affiche « N/A » sur des données bien présentes.
        return [
            'entity' => $this->canvasRelationHydrator->payload($entity, $entityCanvas),
            'entityType' => $entityType,
            'entityCanvas' => $entityCanvas,
        ];
    }

    /**
     * Traduction d'une cellule Excel vers un champ système.
     *
     * Délègue à BordereauLigneNormaliseur, SOURCE UNIQUE partagée avec le rattrapage en
     * ligne de commande : une seconde implémentation ferait diverger les montants persistés
     * de ceux affichés à l'écran, pour un même fichier.
     */
    protected function parseExcelValue($value, string $systemField)
    {
        return \App\Services\Bordereau\BordereauLigneNormaliseur::normaliserValeur($value, $systemField);
    }

    /**
     * Reconstruit les données système à partir d'une ligne Excel brute et des colonnes mappées.
     * Gère l'agrégation (somme) si plusieurs colonnes sont mappées à un même champ système.
     */
    protected function reconstructRawLineData(array $row, array $mappedColumns): array
    {
        return \App\Services\Bordereau\BordereauLigneNormaliseur::normaliserLigne($row, $mappedColumns);
    }

    /**
     * Formate une valeur pour l'affichage en fonction de sa clé (nom de champ).
     * Cette méthode est maintenant protégée pour être accessible par les contrôleurs utilisant le trait.
     *
     * @param mixed $value La valeur à formater (peut être string, int, float, DateTimeImmutable).
     * @param string $key La clé du champ (ex: 'date_operation', 'prime_ttc', 'taux_commission').
     * @return string La valeur formatée sous forme de chaîne.
     */
    protected function formatValueForDisplay(mixed $value, string $key): string
    {
        if ($value === null || $value === '') {
            return 'N/A';
        }

        // CORRECTION : On vérifie d'abord si la valeur est un objet date, INDÉPENDAMMENT de la clé.
        // C'est la correction la plus importante pour éviter l'erreur "Object of class DateTime could not be converted to string".
        // Cette vérification doit avoir lieu avant toute autre, car un objet DateTime n'est pas "numeric" et ne contient pas forcément "date" dans sa clé.
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y');
        }

        // NOUVEAU : Tentative de conversion des chaînes en date si la clé suggère une date.
        // Cela corrige l'erreur "Object of class DateTime could not be converted to string"
        // lorsque l'on compare une chaîne de date (Excel) avec un objet DateTime (DB).
        if (str_contains(strtolower($key), 'date') && is_string($value)) {
            try {
                $date = new \DateTimeImmutable($value);
                return $date->format('d/m/Y');
            } catch (\Exception $e) { /* La conversion a échoué, on continue... */
            }
        }

        // Pourcentages ou montants monétaires (y compris les chargements)
        if ((str_contains(strtolower($key), 'taux') || str_starts_with(strtolower($key), 'chargement') ||
            in_array(strtolower($key), ['prime_ttc', 'revenu 1', 'commission_ht_payable_now', 'taxe_commission_payable_now'])) && is_numeric($value)) {
            return number_format($value, 2, ',', ' ') . (str_contains(strtolower($key), 'taux') ? ' %' : '');
        }

        return (string) $value;
    }
}
