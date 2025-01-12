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
use App\Form\NoteType;
use App\Repository\NoteRepository;
use App\Repository\InviteRepository;
use App\Repository\RisqueRepository;
use App\Repository\EntrepriseRepository;
use App\Services\ServiceDates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\Form\Test\FormInterface;

#[Route("/admin/note", name: 'admin.note.')]
#[IsGranted('ROLE_USER')]
class NoteController extends AbstractController
{
    public MenuActivator $activator;
    public int $pageMax = 2;
    public string $pageName = "";

    public function __construct(
        private MailerInterface $mailer,
        private ServiceDates $serviceDates,
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


    #[Route('/create/{idEntreprise}/{idNote}/{page}', name: 'create', requirements: [
        'idEntreprise' => Requirement::DIGITS,
        'idNote' => Requirement::CATCH_ALL,
        'page' => Requirement::DIGITS,
    ])]
    public function create(int $idEntreprise, int $idNote, int $page, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Note $note */
        $note = $this->loadNote($idNote, $invite);

        /** @var Form $form */
        $form = $this->buildForm($note, $page);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->saveNote($note, true);
            // dd($page, $form);
            if ($page == $this->pageMax) {
                $note->setValidated(true);
                $this->saveNote($note, true);
                return $this->redirectToRoute("admin.note.index", [
                    'idEntreprise' => $idEntreprise,
                ]);
            } else {
                $page = $this->movePage($page, $form);
                // $form = $this->createForm(NoteType::class, $note, [
                //     "page" => $page,
                //     "pageMax" => $this->pageMax,
                //     "type" => $note->getType(),
                //     "addressedTo" => $note->getAddressedTo(),
                // ]);
                /** @var Form $form */
                $form = $this->buildForm($note, $page);
            }
        }
        // dd($page, $this->pageMax, $form);

        return $this->render('admin/note/create.html.twig', [
            'pageName' => $this->pageName,
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'note' => $note,
            'form' => $form,
            "page" => $page,
            "idNote" => $note->getId() == null ? -1 : $note->getId(),
            "pageMax" => $this->pageMax,
        ]);
    }

    private function buildForm(?Note $note, $page): Form
    {
        $form = $this->createForm(NoteType::class, $note, [
            "page" => $page,
            "pageMax" => $this->pageMax,
            "type" => $note->getType(),
            "addressedTo" => $note->getAddressedTo(),
        ]);
        return $form;
    }

    private function saveNote(Note $note, bool $creation): void
    {
        //save
        $this->manager->persist($note);
        $this->manager->flush();
        if ($creation == true) {
            $this->addFlash("success", $this->translator->trans("note_creation_ok", [
                ":note" => $note->getNom(),
            ]));
        } else {
            $this->addFlash("success", $this->translator->trans("note_edition_ok", [
                ":note" => $note->getNom(),
            ]));
        }
    }

    private function loadNote(int $idNote, ?Invite $invite): Note
    {
        $note = new Note();
        if ($idNote != -1 && $idNote != null) {
            $note = $this->noteRepository->find($idNote);
            $this->pageName = $this->translator->trans("note_page_name_new");
        } else {
            $note->setReference("N" . ($this->serviceDates->aujourdhui()->getTimestamp()));
            $note->setType(Note::TYPE_NULL);
            $note->setInvite($invite);
            $note->setAddressedTo(Note::TO_NULL);
            $note->setValidated(false);
            $this->pageName = $this->translator->trans("note_page_name_update", [
                ":note" => $note->getNom(),
            ]);
        }
        return $note;
    }

    private function movePage(int $page, Form $form): int
    {
        /** @var SubmitButton $btSuivant */
        $btSuivant = $form->has("suivant") != null ? $form->get("suivant") : null;

        /** @var SubmitButton $btPrecedent */
        $btPrecedent = $form->has("precedent") != null ? $form->get("precedent") : null;

        // dd($form->get("suivant"));

        if ($btSuivant != null) {
            if ($btSuivant->isClicked() == true) { // && $page < $this->pageMax
                $page++;
            }
        }
        if ($btPrecedent != null) {
            if ($btPrecedent->isClicked() == true) { // && $page > 1
                $page--;
            }
        }
        // dd($page);
        return $page;
    }


    #[Route('/edit/{idEntreprise}/{idNote}/{page}', name: 'edit', methods: ['GET', 'POST'], requirements: [
        'idEntreprise' => Requirement::DIGITS,
        'idNote' => Requirement::CATCH_ALL,
        'page' => Requirement::DIGITS,
    ])]
    public function edit(int $idEntreprise, int $idNote, int $page, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Note $note */
        $note = $this->loadNote($idNote, $invite);

        $form = $this->createForm(NoteType::class, $note, [
            "page" => $page,
            "type" => $note->getType(),
            "addressedTo" => $note->getAddressedTo(),
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->saveNote($note, false);
            if ($page == $this->pageMax) {
                $note->setValidated(true);
                $this->saveNote($note, false);
                return $this->redirectToRoute("admin.note.index", [
                    'idEntreprise' => $idEntreprise,
                ]);
            } else {
                $page = $this->movePage($page, $form);
                $form = $this->createForm(NoteType::class, $note, [
                    "page" => $page,
                    "pageMax" => $this->pageMax,
                    "type" => $note->getType(),
                    "addressedTo" => $note->getAddressedTo(),
                ]);
            }
        }
        // dd($page, $form);
        return $this->render('admin/note/edit.html.twig', [
            'pageName' => $this->pageName,
            'utilisateur' => $user,
            'note' => $note,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
            "page" => $page,
            "idNote" => $note->getId() == null ? -1 : $note->getId(),
            "pageMax" => $this->pageMax,
        ]);
    }

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
