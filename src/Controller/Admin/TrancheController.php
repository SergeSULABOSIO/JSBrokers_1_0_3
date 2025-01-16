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
use App\Repository\TaxeRepository;
use App\Repository\TacheRepository;
use App\Repository\InviteRepository;
use App\Repository\TrancheRepository;
use App\Repository\EntrepriseRepository;
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
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_FINANCE);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        // dd(
        //     time(),
        //     $request->getSession()->getMetadataBag()->getCreated(), 
        //     $request->getSession()->getMetadataBag()->getLastUsed(),
        //     $request->getSession()
        // );

        return $this->render('admin/tranche/index.html.twig', [
            'pageName' => $this->translator->trans("tache_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'tranches' => $this->trancheRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'activator' => $this->activator,
            "panier" => $request->getSession()->get(PanierNotes::NOM),
        ]);
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

        $form = $this->createForm(TrancheType::class, $tranche);
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
