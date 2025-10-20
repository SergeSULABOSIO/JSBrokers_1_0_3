<?php

/**
 * @file Ce fichier contient le contrôleur OffreIndemnisationSinistreController.
 * @description Ce contrôleur est un CRUD complet pour l'entité `OffreIndemnisationSinistre`.
 * Il est responsable de :
 * 1. `index()`: Afficher la vue principale de la liste des offres d'indemnisation.
 * 2. Fournir des points de terminaison API pour :
 *    - `getFormApi()`: Obtenir le formulaire de création/édition.
 *    - `submitApi()`: Traiter la soumission du formulaire.
 *    - `deleteApi()`: Supprimer une offre.
 *    - `getPaiementsListApi()`, `getDocumentsListApi()`, etc. : Charger les listes des collections liées à une offre.
 */

namespace App\Controller\Admin;

use App\Entity\Tache;
use App\Entity\Document;
use App\Entity\Paiement;
use App\Constantes\Constante;
use App\Services\ServiceMonnaies;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use App\Entity\OffreIndemnisationSinistre;
use App\Form\OffreIndemnisationSinistreType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Repository\OffreIndemnisationSinistreRepository;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/offreindemnisationsinistre", name: 'admin.offreindemnisationsinistre.')]
#[IsGranted('ROLE_USER')]
class OffreIndemnisationSinistreController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private OffreIndemnisationSinistreRepository $repository,
        private Constante $constante,
        private ServiceMonnaies $serviceMonnaies,
        private JSBDynamicSearchService $searchService, // Ajoutez cette ligne
    ) {}

    protected function getParentAssociationMap(): array
    {
        return [
            'notificationSinistre' => NotificationSinistre::class,
        ];
    }


    #[Route(
        '/index/{idInvite}/{idEntreprise}',
        name: 'index',
        requirements: [
            'idEntreprise' => Requirement::DIGITS,
            'idInvite' => Requirement::DIGITS
        ],
        methods: ['GET', 'POST']
    )]
    public function index(int $idInvite, int $idEntreprise)
    {
        $data = $this->repository->findAll();
        $entityCanvas = $this->constante->getEntityCanvas(OffreIndemnisationSinistre::class);
        $this->constante->loadCalculatedValue($entityCanvas, $data);

        return $this->render('components/_view_manager.html.twig', [
            'data' => $data,
            'entite_nom' => $this->getEntityName($this),
            'serverRootName' => $this->getServerRootName($this),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(OffreIndemnisationSinistre::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new OffreIndemnisationSinistre(), $idEntreprise),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
            'idInvite' => $idInvite,
            'idEntreprise' => $idEntreprise,
        ]);
    }


    /**
     * Fournit le formulaire HTML pour une pièce.
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?OffreIndemnisationSinistre $offre, Request $request): Response
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->validateWorkspaceAccess($request);
        $idEntreprise = $entreprise->getId();
        $idInvite = $invite->getId();

        if (!$offre) {
            $offre = new OffreIndemnisationSinistre();
        }

        $form = $this->createForm(OffreIndemnisationSinistreType::class, $offre);

        $entityCanvas = $this->constante->getEntityCanvas(OffreIndemnisationSinistre::class);
        $this->constante->loadCalculatedValue($entityCanvas, [$offre]);
        $entityFormCanvas = $this->constante->getEntityFormCanvas($offre, $entreprise->getId()); // On utilise l'ID de l'entreprise validée

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $entityFormCanvas,
            'entityCanvas' => $entityCanvas,
            'idEntreprise' => $idEntreprise,
            'idInvite' => $idInvite,
        ]);
    }

    /**
     * Traite la soumission du formulaire.
     */
    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): Response
    {
        $data = $request->request->all();
        $files = $request->files->all();
        $submittedData = array_merge($data, $files);

        /** @var OffreIndemnisationSinistre $offre */
        $offre = isset($data['id']) ? $em->getRepository(OffreIndemnisationSinistre::class)->find($data['id']) : new OffreIndemnisationSinistre();

        $form = $this->createForm(OffreIndemnisationSinistreType::class, $offre);
        $form->submit($submittedData, false);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->associateParent($offre, $data, $em);
            $em->persist($offre);
            $em->flush();
            // On sérialise l'entité complète (avec son nouvel ID) pour la renvoyer
            $jsonEntity = $serializer->serialize($offre, 'json', ['groups' => 'list:read']);
            return $this->json([
                'message' => 'Enregistrée avec succès!',
                'entity' => json_decode($jsonEntity) // On renvoie l'objet JSON
            ]);
        }
        $errors = [];
        // On parcourt toutes les erreurs du formulaire (y compris celles des champs enfants)
        foreach ($form->getErrors(true) as $error) {
            $errors[$error->getOrigin()->getName()][] = $error->getMessage();
        }
        return $this->json([
            'success' => false,
            'message' => 'Veuillez corriger les erreurs ci-dessous.',
            'errors'  => $errors // On envoie le tableau détaillé des erreurs au client
        ], 422); // 422 = Unprocessable Entity
    }

    /**
     * Supprime une pièce.
     */
    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(OffreIndemnisationSinistre $offreIndemnisationSinistre, EntityManagerInterface $em): Response
    {
        try {
            $em->remove($offreIndemnisationSinistre);
            $em->flush();
            return $this->json(['message' => 'Offre supprimée avec succès.']);
        } catch (\Exception $e) {
            return $this->json(['message' => 'Erreur lors de la suppression.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(
        '/api/dynamic-query/{idInvite}/{idEntreprise}',
        name: 'app_dynamic_query',
        requirements: [
            'idEntreprise' => Requirement::DIGITS,
            'idInvite' => Requirement::DIGITS
        ],
        methods: ['POST']
    )]
    public function query(int $idInvite, int $idEntreprise, Request $request)
    {
        $requestData = json_decode($request->getContent(), true) ?? [];
        $reponseData = $this->searchService->search($requestData);
        $entityCanvas = $this->constante->getEntityCanvas(OffreIndemnisationSinistre::class);
       $this->constante->loadCalculatedValue($entityCanvas, $reponseData["data"]);

        // 6. Rendre le template Twig avec les données filtrées et les informations de statut/pagination
        return $this->render('components/_list_content.html.twig', [
            'status' => $reponseData["status"], // Contient l'erreur ou les infos de pagination
            'totalItems' => $reponseData["totalItems"],  // Le nombre total d'éléments (pour la pagination)
            'data' => $reponseData["data"], // Les entités NotificationSinistre trouvées
            'entite_nom' => $this->getEntityName($this),
            'serverRootName' => $this->getServerRootName($this),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(OffreIndemnisationSinistre::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new OffreIndemnisationSinistre(), $idEntreprise),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($reponseData["data"]),
            'idEntreprise' => $idEntreprise,
            'idInvite' => $idInvite,
        ]);
    }


    #[Route('/api/{id}/paiements/{usage}', name: 'api.get_paiements', methods: ['GET'])]
    public function getPaiementsListApi(int $id, ?string $usage = "generic"): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var OffreIndemnisationSinistre $offreIndemnisationSinistre */
            $offreIndemnisationSinistre = $this->repository->find($id);
            if (!$offreIndemnisationSinistre) {
                throw $this->createNotFoundException("L'offre sinistre avec l'ID $id n'a pas été trouvée.");
            }
            $data = $offreIndemnisationSinistre->getPaiements();
        }
        $entityCanvas = $this->constante->getEntityCanvas(Paiement::class);
        $this->constante->loadCalculatedValue($entityCanvas, $data);

        return $this->render("components/_" . $usage . "_list_component.html.twig", [
            'data' => $data,
            'entite_nom' => $this->getEntityName(Paiement::class),
            'serverRootName' => $this->getServerRootName(Paiement::class),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(Paiement::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Paiement(), $this->getEntreprise()->getId()),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
            'idInvite' => $this->getInvite()->getId(),
            'idEntreprise' => $this->getEntreprise()->getId(),
            'customAddAction' => "click->collection#addItem", //Custom Action pour Ajouter à la collection
            // 'customEditAction' => "click->collection#editItem", //Custom Action pour Editer un élement de la collection
            // 'customDeleteAction' => "click->collection#deleteItem", //Custom Action pour Supprimer un élément de la collection
        ]);
    }

    #[Route('/api/{id}/documents/{usage}', name: 'api.get_documents', methods: ['GET'])]
    public function getDocumentsListApi(int $id, ?string $usage = "generic"): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var OffreIndemnisationSinistre $offreIndemnisationSinistre */
            $offreIndemnisationSinistre = $this->repository->find($id);
            if (!$offreIndemnisationSinistre) {
                throw $this->createNotFoundException("L'offre avec l'ID $id n'a pas été trouvée.");
            }
            $data = $offreIndemnisationSinistre->getDocuments();
        }
        $entityCanvas = $this->constante->getEntityCanvas(Paiement::class);
        $this->constante->loadCalculatedValue($entityCanvas, $data);

        return $this->render("components/_" . $usage . "_list_component.html.twig", [
            'data' => $data,
            'entite_nom' => $this->getEntityName(Document::class),
            'serverRootName' => $this->getServerRootName(Document::class),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(Document::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Document(), $this->getEntreprise()->getId()),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
            'idInvite' => $this->getInvite()->getId(),
            'idEntreprise' => $this->getEntreprise()->getId(),
            'customAddAction' => "click->collection#addItem", //Custom Action pour Ajouter à la collection
            // 'customEditAction' => "click->collection#editItem", //Custom Action pour Editer un élement de la collection
            // 'customDeleteAction' => "click->collection#deleteItem", //Custom Action pour Supprimer un élément de la collection
        ]);
    }

    #[Route('/api/{id}/taches/{usage}', name: 'api.get_taches', methods: ['GET'])]
    public function getTachesListApi(int $id, ?string $usage = "generic"): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var OffreIndemnisationSinistre $offreIndemnisationSinistre */
            $offreIndemnisationSinistre = $this->repository->find($id);
            if (!$offreIndemnisationSinistre) {
                throw $this->createNotFoundException("L'offre avec l'ID $id n'a pas été trouvée.");
            }
            $data = $offreIndemnisationSinistre->getTaches();
        }
        $entityCanvas = $this->constante->getEntityCanvas(Tache::class);
        $this->constante->loadCalculatedValue($entityCanvas, $data);

        return $this->render("components/_" . $usage . "_list_component.html.twig", [
            'data' => $data,
            'entite_nom' => $this->getEntityName(Tache::class),
            'serverRootName' => $this->getServerRootName(Tache::class),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(Tache::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Tache(), $this->getEntreprise()->getId()),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
            'idInvite' => $this->getInvite()->getId(),
            'idEntreprise' => $this->getEntreprise()->getId(),
            'customAddAction' => "click->collection#addItem", //Custom Action pour Ajouter à la collection
            // 'customEditAction' => "click->collection#editItem", //Custom Action pour Editer un élement de la collection
            // 'customDeleteAction' => "click->collection#deleteItem", //Custom Action pour Supprimer un élément de la collection
        ]);
    }
}
