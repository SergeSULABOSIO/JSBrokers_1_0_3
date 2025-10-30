<?php

/**
 * @file Ce fichier contient le contrôleur PaiementController.
 * @description Ce contrôleur est un CRUD complet pour l'entité `Paiement`.
 * Il est responsable de :
 * 1. `index()`: Afficher la vue principale de la liste des paiements (page non-générique).
 * 2. Fournir des points de terminaison API pour :
 *    - `getFormApi()`: Obtenir le formulaire de création/édition.
 *    - `submitApi()`: Traiter la soumission du formulaire, en gérant l'association à des entités parentes (ex: OffreIndemnisationSinistre) grâce au `HandleChildAssociationTrait`.
 *    - `deleteApi()`: Supprimer un paiement.
 *    - `getPreuvesListApi()`: Charger la liste des documents (preuves) liés à un paiement.
 */

namespace App\Controller\Admin;

use DateTimeImmutable;
use App\Entity\Document;
use App\Entity\Paiement;
use App\Form\PaiementType;
use App\Constantes\Constante;
use App\Services\ServiceMonnaies;
use App\Repository\NoteRepository;
use App\Repository\InviteRepository;
use App\Repository\ClasseurRepository;
use App\Repository\PaiementRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use App\Entity\OffreIndemnisationSinistre;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/paiement", name: 'admin.paiement.')]
#[IsGranted('ROLE_USER')]
class PaiementController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private NoteRepository $noteRepository,
        private InviteRepository $inviteRepository,
        private PaiementRepository $paiementRepository,
        private ClasseurRepository $classeurRepository,
        private ServiceMonnaies $serviceMonnaies,
        private Constante $constante,
        private JSBDynamicSearchService $searchService, // Ajoutez cette ligne
        private SerializerInterface $serializer,
    ) {}

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Paiement::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Paiement::class);
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
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(Paiement::class, $request);
    }

    /**
     * Fournit le formulaire HTML pour une pièce.
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Paiement $paiement, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Paiement::class,
            PaiementType::class,
            $paiement,
            function (Paiement $paiement) use ($request) {
                // Custom initializer for a new Paiement
                $defaultMontant = $request->query->get('default_montant');
                if ($defaultMontant !== null && $defaultMontant !== '') {
                    $paiement->setMontant((float)$defaultMontant);
                }
                $paiement->setPaidAt(new DateTimeImmutable("now"));
                $paiement->setDescription("Descript. à générer automatiquement ici.");
            }
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
            Paiement::class,
            PaiementType::class,
            function (Paiement $paiement) {
                if (!$paiement->getId()) {
                    // Le trait TimestampableTrait s'en occupe déjà
                    // $paiement->setCreatedAt(new DateTimeImmutable("now"));
                }
                // Le trait TimestampableTrait s'en occupe déjà
                // $paiement->setUpdatedAt(new DateTimeImmutable("now"));
            }
        );
    }

    /**
     * Supprime une pièce.
     */
    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Paiement $paiement): Response
    {
        return $this->handleDeleteApi($paiement);
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
    public function query(Request $request)
    {
        return $this->renderViewOrListComponent(Paiement::class, $request, true);
    }


    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Paiement::class, $usage);
    }
}
