<?php

/**
 * @file Ce fichier contient le contrôleur FeedbackController.
 * @description Ce contrôleur est un CRUD complet pour l'entité `Feedback`.
 * Il est responsable de :
 * 1. `index()`: Afficher la vue principale de la liste des feedbacks (page non-générique).
 * 2. Fournir des points de terminaison API pour :
 *    - `getFormApi()`: Obtenir le formulaire de création/édition.
 *    - `submitApi()`: Traiter la soumission du formulaire, en gérant l'association à une `Tache` parente.
 *    - `deleteApi()`: Supprimer un feedback.
 *    - `getDocumentsListApi()`: Charger la liste des documents liés à un feedback.
 */

namespace App\Controller\Admin;

use App\Entity\Tache;
use App\Entity\Feedback;
use App\Form\FeedbackType;
use App\Constantes\Constante;
use App\Repository\InviteRepository;
use App\Repository\FeedbackRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use App\Controller\Admin\ControllerUtilsTrait;
use App\Entity\Document;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/feedback", name: 'admin.feedback.')]
#[IsGranted('ROLE_USER')]
class FeedbackController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private FeedbackRepository $feedbackRepository,
        private Constante $constante,
    ) {}

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Feedback::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Feedback::class);
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
        // Utilisation de la fonction réutilisable du trait
        return $this->renderViewOrListComponent(Feedback::class, $request);
    }

    /**
     * Fournit le formulaire HTML pour un Feedback.
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Feedback $feedback, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Feedback::class,
            FeedbackType::class,
            $feedback
            // No specific initializer needed for a new Feedback
        );
    }

    /**
     * Traite la soumission du formulaire de Feedback.
     */
    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): Response
    {
        return $this->handleFormSubmission(
            $request,
            Feedback::class,
            FeedbackType::class,
            $em,
            $serializer
        );
    }



    /**
     * Supprime un Feedback.
     */
    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Feedback $feedback, EntityManagerInterface $em): Response
    {
        return $this->handleDeleteApi($feedback, $em);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Feedback::class, $usage);
    }
}
