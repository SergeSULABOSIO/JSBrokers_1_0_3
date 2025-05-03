<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\Chargement;
use App\Form\ChargementType;
use App\Repository\ChargementRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/chargement", name: 'admin.chargement.')]
#[IsGranted('ROLE_USER')]
class ChargementController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ChargementRepository $chargementRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_FINANCE);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/chargement/index.html.twig', [
            'pageName' => $this->translator->trans("chargement_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'chargements' => $this->chargementRepository->paginateForEntreprise($idEntreprise, $page),
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

        /** @var Chargement $chargement */
        $chargement = new Chargement();
        //Paramètres par défaut
        $chargement->setNom("CHGM" . (rand(0, 100)));
        $chargement->setEntreprise($entreprise);

        $form = $this->createForm(ChargementType::class, $chargement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($chargement);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("chargement_creation_ok", [
                ":chargement" => $chargement->getNom(),
            ]));
            return $this->redirectToRoute("admin.chargement.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/chargement/create.html.twig', [
            'pageName' => $this->translator->trans("chargement_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idChargement}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idChargement, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Chargement $chargement */
        $chargement = $this->chargementRepository->find($idChargement);

        $form = $this->createForm(ChargementType::class, $chargement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($chargement); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("chargement_edition_ok", [
                ":chargement" => $chargement->getNom(),
            ]));
            return $this->redirectToRoute("admin.chargement.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/chargement/edit.html.twig', [
            'pageName' => $this->translator->trans("chargement_page_name_update", [
                ":chargement" => $chargement->getNom(),
            ]),
            'utilisateur' => $user,
            'chargement' => $chargement,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idChargement}', name: 'remove', requirements: ['idChargement' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idChargement, Request $request)
    {
        /** @var Chargement $chargement */
        $chargement = $this->chargementRepository->find($idChargement);

        $message = $this->translator->trans("chargement_deletion_ok", [
            ":chargement" => $chargement->getNom(),
        ]);;
        
        $this->manager->remove($chargement);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.chargement.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
