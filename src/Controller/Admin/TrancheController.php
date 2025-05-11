<?php

namespace App\Controller\Admin;

use App\Entity\Note;
use App\Entity\Taxe;
use App\Entity\Tache;
use App\Entity\Invite;
use App\Form\TaxeType;
use App\Entity\Tranche;
use App\Form\TacheType;
use App\Form\TrancheType;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Constantes\PanierNotes;
use App\Entity\Article;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Repository\TaxeRepository;
use App\Repository\TacheRepository;
use App\Repository\InviteRepository;
use App\Repository\TrancheRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\NoteRepository;
use App\Services\ServiceMonnaies;
use App\Services\ServiceTaxes;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

#[Route("/admin/tranche", name: 'admin.tranche.')]
#[IsGranted('ROLE_USER')]
class TrancheController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private TrancheRepository $trancheRepository,
        private NoteRepository $noteRepository,
        private Constante $constante,
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
                // dd("Ici");
                $note = $this->noteRepository->find($panier->getIdNote());
            }
        }
        // dd("Panier ", $panier);

        return $this->render('admin/tranche/index.html.twig', [
            'pageName' => $this->translator->trans("tache_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'tranches' => $this->trancheRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'serviceTaxe' => $this->serviceTaxes,
            'activator' => $this->activator,
            "panier" => $panier,
            "note" => $note,
        ]);
    }


    #[Route('/retirerdelanote/{idTranche}/{idEntreprise}/{currentURL}', name: 'retirerdelanote', requirements: [
        'idTranche' => Requirement::DIGITS,
        'idEntreprise' => Requirement::DIGITS,
        'currentURL' => '.+'
    ])]
    public function retirerdelanote($currentURL, int $idTranche, $idEntreprise, Request $request)
    {
        $reponseServeur = "";
        /** @var PanierNotes $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);

        /** @var Note $note */
        $note = $this->noteRepository->find($panier->getIdNote());

        if ($note != null) {
            /** @var Article $articleToDelete */
            $articleToDelete = null;

            foreach ($note->getArticles() as $article) {
                if ($panier->isInvoiced($article->getTranche()->getId(), $article->getMontant(), $article->getNom())) {
                    $articleToDelete = $article;
                    break;
                }
            }
            if ($articleToDelete) {
                $note->removeArticle($articleToDelete);

                //on actualise la base de données
                $this->manager->refresh($note);
                $this->manager->flush();

                //On actualise le panier
                $panier->setNote($note);
                $reponseServeur = "La tranche a été retirée du panier.";
                // $this->addFlash("success", $reponseServeur);
            }
        } else {
            $reponseServeur = "Cher utilisateur, la note est introuvable dans le panier.";
            $this->addFlash("danger", $reponseServeur);
        }
        // return $this->redirect($currentURL);
        return new Response($reponseServeur);
    }


    // #[Route('/mettredanslanote/{poste}/{montantPayable}/{idNote}/{idPoste}/{idTranche}/{idEntreprise}/{currentURL}', name: 'mettredanslanote', requirements: [
    #[Route('/mettredanslanote/{poste}/{montantPayable}/{idNote}/{idPoste}/{idTranche}/{idEntreprise}', name: 'mettredanslanote', requirements: [
        'poste' => Requirement::CATCH_ALL,
        'montantPayable' => Requirement::CATCH_ALL,
        'idNote' => Requirement::DIGITS,
        'idPoste' => Requirement::DIGITS,
        'idTranche' => Requirement::DIGITS,
        'idEntreprise' => Requirement::DIGITS,
        // 'currentURL' => Requirement::CATCH_ALL
    ])]
    public function mettredanslanote(string $poste, int $idPoste, float $montantPayable, int $idNote, int $idTranche, Request $request)
    {
        $reponseServeur = "";
        /** @var PanierNotes $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);
        // dd("idNote:", $idNote, $panier->getIdNote());
        if ($panier && $panier->getIdNote() == $idNote) {
            /** @var Note $note */
            $note = $this->noteRepository->find($idNote);

            if ($note) {
                /** @var Tranche $tranche */
                $tranche = $this->trancheRepository->find($idTranche);
                if ($tranche) {
                    //On vérifie si cette article n'existe pas déjà dans le panier
                    if ($panier->isInvoiced($idTranche, $montantPayable, $poste) == false) {
                        /** @var Article $article */
                        $article = new Article();
                        $article->setNom($poste);
                        $article->setIdPoste($idPoste);
                        $article->setMontant($montantPayable);
                        $article->setTranche($tranche);
                        $article->setNote($note);
                        //On actualise la base de données
                        $note->addArticle($article);
                        $this->manager->persist($note);
                        $this->manager->flush();
                        //On actualise le panier
                        $panier->setNote($note);
                        // dd("Je suis ici !", $article);
                        $reponseServeur = "ok_1986_" . $article->getNom() . " vient d'être insérée dans la note.";
                        // $this->addFlash("success", $reponseServeur);
                    } else {
                        $reponseServeur = "erreur_1986_Cette tranche existe déjà dans cette note. Impossible de l'ajouter car le doublon n'est pas autorisé.";
                        // $this->addFlash("danger", $reponseServeur);
                    }
                } else {
                    $reponseServeur = "erreur_1986_Cette tranche est introuvable dans la base de données.";
                    // $this->addFlash("danger", $reponseServeur);
                }
            } else {
                $reponseServeur = "erreur_1986_La note n'existe pas. Impossible d'ajouter quoi que ce soit.";
                // $this->addFlash("danger", $reponseServeur);
            }
        } else {
            $reponseServeur = "erreur_1986_Désolé, vous ne pouvez pas l'insérer dans ce panier car il contient une autre note.";
            // $this->addFlash("danger", $reponseServeur);
        }
        // return $this->redirect($currentURL);
        return new Response($reponseServeur);
    }


    #[Route('/create/{idEntreprise}', name: 'create')]
    public function create($idEntreprise, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Tranche $tranche */
        $tranche = new Tranche();
        $tranche
            ->setNom("Tranche n°" . random_int(1986, 2070))
            ->setPourcentage(1)
            ->setPayableAt(new DateTimeImmutable("now"))
            ->setEcheanceAt(new DateTimeImmutable("+364 days"))
        ;
        //Paramètres par défaut

        $form = $this->createForm(TrancheType::class, $tranche);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($tranche);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("tranche_creation_ok", [
                ":tranche" => $tranche->getNom(),
            ]));
            return $this->redirectToRoute("admin.tranche.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/tranche/create.html.twig', [
            'pageName' => $this->translator->trans("tranche_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idTranche}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idTranche, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Tranche $tranche */
        $tranche = $this->trancheRepository->find($idTranche);

        $form = $this->createForm(TrancheType::class, $tranche, [
            "tranche" => $tranche
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($tranche); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("tranche_edition_ok", [
                ":tranche" => $tranche->getNom(),
            ]));
            return $this->redirectToRoute("admin.tranche.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/tranche/edit.html.twig', [
            'pageName' => $this->translator->trans("tranche_page_name_update", [
                ":tranche" => $tranche->getNom(),
            ]),
            'utilisateur' => $user,
            'tranche' => $tranche,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idTranche}', name: 'remove', requirements: ['idTranche' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idTranche, Request $request)
    {
        /** @var Tranche $tranche */
        $tranche = $this->trancheRepository->find($idTranche);

        $message = $this->translator->trans("tranche_deletion_ok", [
            ":tranche" => $tranche->getNom(),
        ]);;

        $this->manager->remove($tranche);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.tranche.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
