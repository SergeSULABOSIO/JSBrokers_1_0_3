<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\Avenant;
use App\Entity\Feedback;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Tache;
use App\Form\FeedbackType;
use App\Form\PisteType;
use App\Form\TacheType;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\FeedbackRepository;
use App\Repository\PisteRepository;
use App\Repository\TacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

#[Route("/admin/feedback", name: 'admin.feedback.')]
#[IsGranted('ROLE_USER')]
class FeedbackController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private FeedbackRepository $feedbackRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_MARKETING);
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
            'activator' => $this->activator,
        ]);
    }


    #[Route('/create/{idEntreprise}', name: 'create')]
    public function create($idEntreprise, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Feedback $feedback */
        $feedback = new Feedback();
        //Paramètres par défaut

        $form = $this->createForm(FeedbackType::class, $feedback);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($feedback);
            $this->manager->flush();
            return new Response("Ok");
        }
        return $this->render('admin/feedback/create.html.twig', [
            'pageName' => $this->translator->trans("feedback_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idFeedback}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idFeedback, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Feedback $feedback */
        $feedback = $this->feedbackRepository->find($idFeedback);

        $form = $this->createForm(FeedbackType::class, $feedback);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($feedback); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            return new Response("Ok");
        }
        return $this->render('admin/feedback/edit.html.twig', [
            'pageName' => $this->translator->trans("feedback_page_name_update", [
                ":feedback" => $feedback->getDescription(),
            ]),
            'utilisateur' => $user,
            'feedback' => $feedback,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idFeedback}', name: 'remove', requirements: ['idFeedback' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idFeedback, Request $request)
    {
        /** @var Feedback $feedback */
        $feedback = $this->feedbackRepository->find($idFeedback);

        $message = $this->translator->trans("feedback_deletion_ok", [
            ":feedback" => $feedback->getDescription(),
        ]);;
        
        $this->manager->remove($feedback);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.feedback.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
