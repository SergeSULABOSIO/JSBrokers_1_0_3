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
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private FeedbackRepository $feedbackRepository,
        private Constante $constante,
    ) {}

    protected function getParentAssociationMap(): array
    {
        return [
            'tache' => Tache::class,
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
        // Utilisation de la fonction réutilisable du trait
        return $this->renderViewManager(Feedback::class, $idInvite, $idEntreprise);
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
        try {
            $em->remove($feedback);
            $em->flush();
            return $this->json(['message' => 'Feedback supprimé avec succès.']);
        } catch (\Exception $e) {
            return $this->json(['message' => 'Erreur lors de la suppression.'], 500);
        }
    }

    #[Route('/api/{id}/documents/{usage}', name: 'api.get_documents', methods: ['GET'])]
    public function getDocumentsListApi(int $id, ?string $usage = "generic"): Response
    {
        /** @var Feedback $feedback */
        $feedback = $this->findParentOrNew(Feedback::class, $id);
        $data = $feedback->getDocuments();
        return $this->renderCollectionOrList($usage, Document::class, $feedback, $id, $data, 'feedback');
    }
}
