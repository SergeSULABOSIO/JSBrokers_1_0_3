<?php

namespace App\Controller\Admin;

use App\Entity\Note;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Form\RisqueType;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Constantes\PanierNotes;
use App\DTO\NotePageADTO;
use App\Form\NotePageAType;
use App\Form\NoteType;
use App\Repository\NoteRepository;
use App\Repository\InviteRepository;
use App\Repository\RisqueRepository;
use App\Repository\EntrepriseRepository;
use App\Services\ServiceDates;
use App\Services\ServiceMonnaies;
use App\Services\ServiceTaxes;
use Doctrine\Common\Collections\ArrayCollection;
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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Serializer\Serializer as SerializerSerializer;
use Symfony\Component\Serializer\SerializerInterface;

#[Route("/admin/note", name: 'admin.note.')]
#[IsGranted('ROLE_USER')]
class NoteController extends AbstractController
{
    public MenuActivator $activator;
    public int $pageMax = 2;
    public string $pageName = "";
    private bool $validateBeforeSaving = false;

    public function __construct(
        private MailerInterface $mailer,
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private NoteRepository $noteRepository,
        private Constante $constante,
        private RequestStack $requestStack,
        private ServiceMonnaies $serviceMonnaies,
        private ServiceTaxes $serviceTaxes,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_FINANCE);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        /** @var Panier $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);


        /** @var Note $note */
        $note = null;
        if ($panier) {
            $note = $this->noteRepository->find($panier->getIdNote());
        }


        return $this->render('admin/note/index.html.twig', [
            'pageName' => $this->translator->trans("note_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'notes' => $this->noteRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'activator' => $this->activator,
            "panier" => $panier,
            "note" => $note,
        ]);
    }


    #[Route('/viderpanier/{idEntreprise}/{currentURL}', name: 'viderpanier', requirements: [
        'idEntreprise' => Requirement::DIGITS,
        'currentURL' => '.+'
    ])]
    public function viderpanier($currentURL, int $idEntreprise, Request $request)
    {
        /** @var PanierNotes $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);

        //Puis on vide le panier
        if ($panier != null) {
            $panier->viderpanier();
            $request->getSession()->remove(PanierNotes::NOM);
        }
        // dd("Ici", $currentURL);
        return $this->redirect($currentURL);
    }

    #[Route('/mettredanslepanier/{idNote}/{idEntreprise}/{currentURL}', name: 'mettredanslepanier', requirements: [
        'idNote' => Requirement::DIGITS,
        'idEntreprise' => Requirement::DIGITS,
        'currentURL' => '.+'
    ])]
    public function mettredanslepanier($currentURL, int $idNote, $idEntreprise, Request $request)
    {
        /** @var PanierNotes $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);
        //Si le panier n'existe pas encore il faut le créer vite
        if ($panier == null) {
            $this->openSession($request);
        }

        /** @var Note $note */
        $note = $this->noteRepository->find($idNote);
        if ($note != null) {
            $panier->setNote($note);
            $this->addFlash("success", "La note '" . $note->getNom() . "' a été insérée dans le panier.");
        } else {
            $this->addFlash("danger", "Cher utilisateur, cette note est introuvable dans la base de données.");
        }
        return $this->redirect($currentURL);
    }


    #[Route('/retirerdupanier/{idNote}/{idEntreprise}/{currentURL}', name: 'retirerdupanier', requirements: [
        'idNote' => Requirement::DIGITS,
        'idEntreprise' => Requirement::DIGITS,
        'currentURL' => '.+'
    ])]
    public function retirerdupanier($currentURL, int $idNote, $idEntreprise, Request $request)
    {
        /** @var PanierNotes $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);

        if ($panier->getIdNote() == $idNote) {
            $panier->viderpanier();
            $this->addFlash("success", "La note a été rétirée du panier.");
        } else {
            $this->addFlash("danger", "Cher utilisateur, cette note n'est pas dans le panier.");
        }
        return $this->redirect($currentURL);
    }

    #[Route('/create/{idEntreprise}/{idNote}/{page}', name: 'create', requirements: [
        'idEntreprise' => Requirement::DIGITS,
        'idNote' => Requirement::CATCH_ALL,
        'page' => Requirement::DIGITS,
    ])]
    public function create(int $idEntreprise, int $idNote, int $page, Request $request, SerializerInterface $serializer)
    {

        $this->openSession($request);

        
        
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        
        /** @var Utilisateur $user */
        $user = $this->getUser();
        
        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());
        
        /** @var Note $note */
        $note = $this->loadNote($idNote, $invite, $request);
        
        /** @var Form $form */
        $form = $this->buildForm($note, $page);
        
        /** @var PanierNotes $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);
        // dd("panier ", $panier);
        // dd("Ici...", $panier);


        // dd($note);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            //On rentre sur la page Index si le client a supprimé la note
            if ($this->deleteNoteIfNeeded($form, $note, $idEntreprise) == true) {
                return $this->redirectToRoute("admin.note.index", [
                    'idEntreprise' => $idEntreprise,
                ]);
            }
            //Si non, on continue l'édition de la note
            $page = $this->movePage($page, $form);
            if ($page > $this->pageMax) {
                $this->validateBeforeSaving = true;
                $this->saveNote($note, $request, $serializer);
                $this->addFlash("success", "Cher utilisateur, veuillez séléctionner les tranches à ajouter dans la note.");
                return $this->redirectToRoute("admin.tranche.index", [ //admin.note.index
                    'idEntreprise' => $idEntreprise,
                ]);
            } else {
                $this->saveNote($note, $request, $serializer);
                /** @var Form $form */
                $form = $this->buildForm($note, $page);
            }
        }
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
            "panier" => $panier,
            "constante" => $this->constante,
            "serviceMonnaie" => $this->serviceMonnaies,
        ]);
    }

    private function openSession(Request $request)
    {
        if ($request->getSession()->get(PanierNotes::NOM) == null) {
            $request->getSession()->set(PanierNotes::NOM, new PanierNotes());
            // dd("session recréée!");
        }
    }

    private function buildForm(?Note $note, $page): Form
    {
        $form = $this->createForm(NoteType::class, $note, [
            "idNote" => $note->getId() == null ? -1 : $note->getId(),
            "page" => $page,
            "pageMax" => $this->pageMax,
            "type" => $note->getType(),
            "addressedTo" => $note->getAddressedTo(),
            "note" => $note,
        ]);
        if ($note != null) {
            $form->get('montantDue')->setData($this->constante->Note_getMontant_payable($note));
            $form->get('montantPaye')->setData($this->constante->Note_getMontant_paye($note));
            $form->get('montantSolde')->setData($this->constante->Note_getMontant_solde($note));
        }
        return $form;
    }

    private function saveNote(Note $note, Request $request): void
    {
        if ($this->validateBeforeSaving == true) {
            $note->setValidated(true);
        }
        //Enregistrement dans la session
        /** @var PanierNotes $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);
        $panier->setNote($note);
        //save
        $this->manager->persist($note);
        $this->manager->flush();
        if ($note->getId() == null) {
            $this->addFlash("success", $this->translator->trans("note_creation_ok", [
                ":note" => $note->getNom(),
            ]));
        } else {
            $this->addFlash("success", $this->translator->trans("note_edition_ok", [
                ":note" => $note->getNom(),
            ]));
        }
    }

    private function loadNote(int $idNote, ?Invite $invite, Request $request): ?Note
    {
        /** @var PanierNotes $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);

        /** @var Note */
        $note = new Note();
        //si la note a un identifiant = la note existe dans la base de données
        if ($idNote != -1 && $idNote != null) {
            $note = $this->noteRepository->find($idNote);
            if ($note != null) {
                $panier->setNote($note);
            }
        } else {
            //Si non, si le panier a un ID de la note en mémoire cache (dans la session), on réintérroge la base de données
            if ($panier->getIdNote() != null) {
                $note = $this->noteRepository->find($panier->getIdNote());
                if ($note != null) {
                    $panier->setNote($note);
                } else {
                    $panier->viderpanier();
                }
            } else {
                $note->setSignature(time());
                $note->setReference("N" . (time()));
                $note->setType(Note::TYPE_NULL);
                $note->setInvite($invite);
                $note->setAddressedTo(Note::TO_NULL);
                $note->setValidated(false);
            }
        }
        $this->pageName = $this->translator->trans("note_page_name_update", [
            ":note" => $note->getNom(),
        ]);
        // dd($note);
        return $note;
    }

    private function deleteNoteIfNeeded(Form $form, ?Note $note, $idEntreprise): bool
    {
        /** @var SubmitButton $btDelete */
        $btDelete = $form->has("delete") != null ? $form->get("delete") : null;
        if ($btDelete != null) {
            if ($btDelete->isClicked() == true) {
                // dd("Je dois supprimer ", $note);
                // $this->remove($idEntreprise, $note->getId(), new Request());
                // $message = $this->translator->trans("note_deletion_ok", [
                //     ":note" => $note->getNom(),
                // ]);;

                $this->manager->remove($note);
                $this->manager->flush();

                // dd("Deleted!");
                // $this->addFlash("success", $message);
                return true;
            }
        }
        return false;
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
    public function edit(int $idEntreprise, int $idNote, int $page, Request $request, SerializerInterface $serializer)
    {
        return $this->create($idEntreprise, $idNote, $page, $request, $serializer);
    }



    #[Route('/remove/{idEntreprise}/{idNote}', name: 'remove', requirements: ['idNote' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idNote, Request $request)
    {
        /** @var Note $note */
        $note = $this->noteRepository->find($idNote);

        $message = $this->translator->trans("note_deletion_ok", [
            ":note" => $note->getNom(),
        ]);

        /** @var PanierNotes $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);
        if ($panier->getIdNote() == $idNote) {
            $panier->viderpanier();
        }


        $this->manager->remove($note);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.note.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
