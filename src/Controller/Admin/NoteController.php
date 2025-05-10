<?php

namespace App\Controller\Admin;

use App\Entity\Note;
use App\Entity\Invite;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Constantes\PanierNotes;
use App\Form\NoteType;
use App\Repository\NoteRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Services\ServiceDates;
use App\Services\ServiceMonnaies;
use App\Services\ServiceTaxes;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Util\Json;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

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
        if ($panier != null) {
            if ($panier->getIdNote() != null) {
                $note = $this->noteRepository->find($panier->getIdNote());
                // dd("Ici...", $panier, $note);
            }
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



    #[Route('/getpanier/{idEntreprise}', name: 'getpanier', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function getPanier($idEntreprise, Request $request)
    {
        /** @var Panier $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);

        /** @var Note $note */
        $note = null;
        if ($panier != null) {
            if ($panier->getIdNote() != null) {
                $note = $this->noteRepository->find($panier->getIdNote());
            }
        }

        return $this->render('/segments/panier_pour_note.html.twig', [
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
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
        $this->detruirePanier($request);
        // dd("Ici", $currentURL);
        return $this->redirect($currentURL);
    }

    private function detruirePanier(Request $request)
    {
        /** @var PanierNotes $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);

        //Puis on vide le panier
        if ($panier != null) {
            $panier->viderpanier();
            $request->getSession()->remove(PanierNotes::NOM);
        }
    }

    private function loadPanierFromRequest(?Request $request): ?PanierNotes
    {
        return $request->getSession()->get(PanierNotes::NOM);
    }

    #[Route('/mettredanslepanier/{idNote}/{idEntreprise}/{currentURL}', name: 'mettredanslepanier', requirements: [
        'idNote' => Requirement::DIGITS,
        'idEntreprise' => Requirement::DIGITS,
        'currentURL' => '.+'
    ])]
    public function mettredanslepanier($currentURL, int $idNote, $idEntreprise, Request $request)
    {
        $panier = $this->loadPanierFromRequest($request);
        //Si le panier n'existe pas encore il faut le créer vite
        if ($panier == null) {
            $this->openSession($request);
            $panier = $this->loadPanierFromRequest($request);
        } else {
            // dd($panier);
            if ($panier->getIdNote() == null) {
                $this->openSession($request);
            }
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

    #[Route('/create/{idEntreprise}/{idNote}', name: 'create', requirements: [
        'idEntreprise' => Requirement::DIGITS,
        'idNote' => Requirement::CATCH_ALL,
    ])]
    public function create(int $idEntreprise, int $idNote, Request $request)
    {
        //S'il s'agit de la création d'une nouvelle note, il faut vider d'abord le panier
        if ($idNote == -1) {
            $this->detruirePanier($request);
        }

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
        $form = $this->buildForm($note);

        /** @var PanierNotes $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->saveNote($note, $request);

            $mntDue = $this->constante->Note_getMontant_payable($note);
            $mntPaye = $this->constante->Note_getMontant_paye($note);
            $mntSolde = $this->constante->Note_getMontant_solde($note);

            // return new JsonResponse($note);
            return new Response($mntDue . "___" . $mntPaye . "___" . $mntSolde, Response::HTTP_OK);

            // $this->addFlash("success", "Cher utilisateur, veuillez séléctionner les tranches à ajouter dans la note.");
            // return $this->redirectToRoute("admin.tranche.index", [ //admin.note.index
            //     'idEntreprise' => $idEntreprise,
            // ]);
        }
        return $this->render('admin/note/create.html.twig', [
            'pageName' => $this->pageName,
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'note' => $note,
            'form' => $form,
            "idNote" => $note->getId() == null ? -1 : $note->getId(),
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

    private function buildForm(?Note $note): Form
    {
        // dd("Note: ", $note);
        $form = $this->createForm(NoteType::class, $note, [
            "idNote" => $note->getId() == null ? -1 : $note->getId(),
            "note" => $note,
        ]);
        if ($note != null) {
            if ($note->getId() != null) {
                $form->get('montantDue')->setData($this->constante->Note_getMontant_payable($note));
                $form->get('montantPaye')->setData($this->constante->Note_getMontant_paye($note));
                $form->get('montantSolde')->setData($this->constante->Note_getMontant_solde($note));
            }
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
                $note->setType(Note::TYPE_NOTE_DE_DEBIT);
                $note->setInvite($invite);
                $note->setAddressedTo(Note::TO_ASSUREUR);
                $note->setValidated(false);
            }
        }
        $this->pageName = $this->translator->trans("note_page_name_update", [
            ":note" => $note->getNom(),
        ]);
        // dd($note);
        return $note;
    }


    #[Route('/edit/{idEntreprise}/{idNote}', name: 'edit', methods: ['GET', 'POST'], requirements: [
        'idEntreprise' => Requirement::DIGITS,
        'idNote' => Requirement::CATCH_ALL,
    ])]
    public function edit(int $idEntreprise, int $idNote, Request $request)
    {
        return $this->create($idEntreprise, $idNote, $request);
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
        if ($panier) {
            if ($panier->getIdNote() == $idNote) {
                $panier->viderpanier();
            }
        }

        $this->manager->remove($note);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.note.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
