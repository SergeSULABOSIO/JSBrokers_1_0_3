<?php

namespace App\Controller\Admin;

use App\Entity\Note;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Form\RisqueType;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\DTO\NotePageADTO;
use App\Form\NotePageAType;
use App\Repository\NoteRepository;
use App\Repository\InviteRepository;
use App\Repository\RisqueRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/note", name: 'admin.note.')]
#[IsGranted('ROLE_USER')]
class NoteController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private NoteRepository $noteRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_FINANCE);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/note/index.html.twig', [
            'pageName' => $this->translator->trans("note_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'notes' => $this->noteRepository->paginateForEntreprise($idEntreprise, $page),
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

        /** @var Note $note */
        $note = new Note();
        //Paramètres par défaut
        $note->setInvite($invite);

        $form = $this->createForm(NotePageAType::class, $note);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($note);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("note_creation_ok", [
                ":note" => $note->getNom(),
            ]));
            return $this->redirectToRoute("admin.note.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/note/create.html.twig', [
            'pageName' => $this->translator->trans("note_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    // #[Route('/edit/{idEntreprise}/{idRisque}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    // public function edit($idEntreprise, $idRisque, Request $request)
    // {
    //     /** @var Entreprise $entreprise */
    //     $entreprise = $this->entrepriseRepository->find($idEntreprise);

    //     /** @var Utilisateur $user */
    //     $user = $this->getUser();

    //     /** @var Risque $risque */
    //     $risque = $this->risqueRepository->find($idRisque);

    //     $form = $this->createForm(RisqueType::class, $risque);
    //     $form->handleRequest($request);

    //     if ($form->isSubmitted() && $form->isValid()) {
    //         $this->manager->persist($risque); //On peut ignorer cette instruction car la fonction flush suffit.
    //         $this->manager->flush();
    //         $this->addFlash("success", $this->translator->trans("risque_edition_ok", [
    //             ":risque" => $risque->getNomComplet(),
    //         ]));
    //         return $this->redirectToRoute("admin.risque.index", [
    //             'idEntreprise' => $idEntreprise,
    //         ]);
    //     }
    //     return $this->render('admin/risque/edit.html.twig', [
    //         'pageName' => $this->translator->trans("risque_page_name_update", [
    //             ":risque" => $risque->getNomComplet(),
    //         ]),
    //         'utilisateur' => $user,
    //         'risque' => $risque,
    //         'entreprise' => $entreprise,
    //         'activator' => $this->activator,
    //         'form' => $form,
    //     ]);
    // }

    #[Route('/remove/{idEntreprise}/{idNote}', name: 'remove', requirements: ['idNote' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idNote, Request $request)
    {
        /** @var Note $note */
        $note = $this->noteRepository->find($idNote);

        $message = $this->translator->trans("note_deletion_ok", [
            ":note" => $note->getNom(),
        ]);;
        
        $this->manager->remove($note);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.note.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
