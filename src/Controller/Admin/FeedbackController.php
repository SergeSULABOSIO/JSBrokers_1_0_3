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


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/feedback/index.html.twig', [
            'pageName' => $this->translator->trans("feedback_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'feedbacks' => $this->feedbackRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            // 'activator' => $this->activator,
        ]);
    }

    /**
     * Fournit le formulaire HTML pour un Feedback.
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Feedback $feedback, Request $request): Response
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->validateWorkspaceAccess($request);
        $idEntreprise = $entreprise->getId();
        $idInvite = $invite->getId();

        if (!$feedback) {
            $feedback = new Feedback();
        }

        $form = $this->createForm(FeedbackType::class, $feedback);

        $entityCanvas = $this->constante->getEntityCanvas(Feedback::class);
        $this->constante->loadCalculatedValue($entityCanvas, [$feedback]);

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $this->constante->getEntityFormCanvas($feedback, $entreprise->getId()),
            'entityCanvas' => $entityCanvas,
            'idEntreprise' => $idEntreprise,
            'idInvite' => $idInvite,
        ]);
    }

    /**
     * Traite la soumission du formulaire de Feedback.
     */
    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): Response
    {
        $data = $request->request->all();
        $files = $request->files->all();
        $submittedData = array_merge($data, $files);

        $feedback = isset($data['id']) ? $em->getRepository(Feedback::class)->find($data['id']) : new Feedback();

        $form = $this->createForm(FeedbackType::class, $feedback);
        $form->submit($submittedData, false);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->associateParent($feedback, $data, $em);
            $em->persist($feedback);
            $em->flush();

            $jsonEntity = $serializer->serialize($feedback, 'json', ['groups' => 'list:read']);
            return $this->json([
                'message' => 'Enregistrée avec succès!',
                'entity' => json_decode($jsonEntity) // On renvoie l'objet JSON
            ]);
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[$error->getOrigin()->getName()][] = $error->getMessage();
        }
        return $this->json([
            'message' => 'Veuillez corriger les erreurs ci-dessous.', 
            'errors' => $errors
        ], 422);
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

    #[Route('/api/{id}/documents', name: 'api.get_documents', methods: ['GET'])]
    public function getDocumentsListApi(int $id, FeedbackRepository $repository): Response
    {
        $feedback = ($id === 0) ? new Feedback() : $repository->find($id);
        if (!$feedback) { $feedback = new Feedback(); }

        return $this->render('components/_collection_list.html.twig', [
            'items' => $feedback->getDocuments(),
            'item_template' => 'components/collection_items/_document_item.html.twig'
        ]);
    }
}
