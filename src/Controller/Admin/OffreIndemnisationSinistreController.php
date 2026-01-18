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
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private OffreIndemnisationSinistreRepository $repository,
        private Constante $constante,
        private ServiceMonnaies $serviceMonnaies,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
    ) {}

    protected function getCollectionMap(): array
    {
        // Utilise la méthode du trait pour construire dynamiquement la carte.
        return $this->buildCollectionMapFromEntity(OffreIndemnisationSinistre::class);
    }


    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(OffreIndemnisationSinistre::class);
    }


    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(OffreIndemnisationSinistre::class, $request);
    }


    /**
     * Fournit le formulaire HTML pour une pièce.
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?OffreIndemnisationSinistre $offre, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            OffreIndemnisationSinistre::class,
            OffreIndemnisationSinistreType::class,
            $offre
            // No specific initializer needed for a new OffreIndemnisationSinistre
        );
    }

    /**
     * Traite la soumission du formulaire.
     */
    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            OffreIndemnisationSinistre::class,
            OffreIndemnisationSinistreType::class
        );
    }

    /**
     * Supprime une pièce.
     */
    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(OffreIndemnisationSinistre $offreIndemnisationSinistre): Response
    {
        return $this->handleDeleteApi($offreIndemnisationSinistre);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request)
    {
        return $this->renderViewOrListComponent(OffreIndemnisationSinistre::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, OffreIndemnisationSinistre::class, $usage);
    }
}
