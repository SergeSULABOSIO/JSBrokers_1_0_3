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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


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
        if ($panier) {
            $note = $this->noteRepository->find($panier->getIdNote());
        }

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
        /** @var PanierNotes $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);

        /** @var Note $note */
        $note = $this->noteRepository->find($panier->getIdNote());

        if ($note != null) {
            /** @var Article $articleToDelete */
            $articleToDelete = null;

            foreach ($note->getArticles() as $article) {
                if ($article) {
                    if ($article->getTranche()->getId() == $idTranche) {
                        $articleToDelete = $article;
                        // dd("Article à supprimer:", $article);
                        break;
                    }
                }
            }
            if ($articleToDelete) {
                $note->removeArticle($articleToDelete);

                //on actualise la base de données
                $this->manager->refresh($note);
                $this->manager->flush();

                //On actualise le panier
                $panier->setNote($note);
                $this->addFlash("success", "La tranche a été retirée du panier.");
            }
        } else {
            $this->addFlash("danger", "Cher utilisateur, la note est introuvable dans le panier.");
        }
        return $this->redirect($currentURL);
    }


    #[Route('/mettredanslanote/{idTranche}/{idEntreprise}/{currentURL}', name: 'mettredanslanote', requirements: [
        'idTranche' => Requirement::DIGITS,
        'idEntreprise' => Requirement::DIGITS,
        'currentURL' => '.+'
    ])]
    public function mettredanslanote($currentURL, int $idTranche, $idEntreprise, Request $request)
    {
        /** @var PanierNotes $panier */
        $panier = $request->getSession()->get(PanierNotes::NOM);
        if ($panier && $panier->getIdNote()) {
            /** @var Note $note */
            $note = $this->noteRepository->find($panier->getIdNote());

            if ($note) {
                /** @var Tranche $tranche */
                $tranche = $this->trancheRepository->find($idTranche);
                if ($tranche) {
                    if ($panier->containsTranche($idTranche) == false) {

                        /** @var Article $article */
                        $article = new Article();

                        $article->setPourcentage(1);
                        $article->setTranche($tranche);
                        $article->setNom($this->getNomArticle($tranche));

                        //On actualise la base de données
                        $note->addArticle($article);
                        $this->manager->persist($note);
                        $this->manager->flush();
                        //On actualise le panier
                        $panier->setNote($note);

                        $this->addFlash("success", $article->getNom() . " vient d'être insérée dans la note.");
                    } else {
                        $this->addFlash("danger", "Cette tranche existe déjà dans cette note. Impossible de l'ajouter car le doublon n'est pas autorisé.");
                    }
                } else {
                    $this->addFlash("danger", "Cette tranche est introuvable dans la base de données.");
                }
            } else {
                $this->addFlash("danger", "La note n'existe pas. Impossible d'ajouter quoi que ce soit.");
            }
        } else {
            $this->addFlash("danger", "Le panier est vide. Merci d'y mettre d'abord la note.");
        }
        return $this->redirect($currentURL);
    }

    private function getNomArticle(?Tranche $tranche): string {
        $nomArticle = "";
        if ($tranche->getCotation()) {
            /** @var Cotation */
            $cotation = $tranche->getCotation();
            $nomArticle = $cotation->getNom();
            if ($cotation->getPiste()) {
                /** @var Piste */
                $piste = $cotation->getPiste();
                if ($piste->getRisque()) {
                    $nomArticle = $piste->getRisque()->getCode() . " / " . $nomArticle;
                }
                if ($piste->getClient()) {
                    $nomArticle = $piste->getClient()->getNom() . " / " . $nomArticle;
                }
            }
            $echeance = "";
            if ($tranche->getEcheanceAt()) {
                $echeance = " (début: " . $tranche->getPayableAt()->format('d-m-Y') . ")";
            }
            $nomArticle = $tranche->getNom() . $echeance . " / " . $nomArticle;
        }
        // dd($nomArticle);
        return $nomArticle;
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
