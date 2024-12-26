<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\CompteBancaire;
use App\Entity\Revenu;
use App\Form\CompteBancaireType;
use App\Form\RevenuType;
use App\Repository\CompteBancaireRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\RevenuRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/revenu", name: 'admin.revenu.')]
#[IsGranted('ROLE_USER')]
class RevenuController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private RevenuRepository $revenuRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_FINANCE);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/revenu/index.html.twig', [
            'pageName' => $this->translator->trans("revenu_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'revenus' => $this->revenuRepository->paginateForEntreprise($idEntreprise, $page),
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

        /** @var Revenu $revenu */
        $revenu = new Revenu();
        //Paramètres par défaut
        $revenu->setNom("REVENUE" . (rand(2000, 3000)));
        $revenu->setEntreprise($entreprise);
        $revenu->setFormule(Revenu::FORMULE_POURCENTAGE_PRIME_NETTE);
        $revenu->setPourcentage(0.1);
        $revenu->setAppliquerPourcentageDuRisque(true);
        $revenu->setMontantflat(0);
        $revenu->setMultipayments(true);
        $revenu->setRedevable(Revenu::REDEVABLE_ASSUREUR);
        $revenu->setShared(false);
        

        $form = $this->createForm(RevenuType::class, $revenu);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($revenu);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("revenu_creation_ok", [
                ":revenu" => $revenu->getNom(),
            ]));
            return $this->redirectToRoute("admin.revenu.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/revenu/create.html.twig', [
            'pageName' => $this->translator->trans("revenu_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idRevenu}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idRevenu, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Revenu $revenu */
        $revenu = $this->revenuRepository->find($idRevenu);

        $form = $this->createForm(RevenuType::class, $revenu);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($revenu); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("revenu_edition_ok", [
                ":revenu" => $revenu->getNom(),
            ]));
            return $this->redirectToRoute("admin.revenu.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/revenu/edit.html.twig', [
            'pageName' => $this->translator->trans("revenu_page_name_update", [
                ":revenu" => $revenu->getNom(),
            ]),
            'utilisateur' => $user,
            'revenu' => $revenu,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idRevenu}', name: 'remove', requirements: ['idRevenu' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idRevenu, Request $request)
    {
        /** @var Revenu $revenu */
        $revenu = $this->revenuRepository->find($idRevenu);

        $message = $this->translator->trans("revenu_deletion_ok", [
            ":revenu" => $revenu->getNom(),
        ]);;
        
        $this->manager->remove($revenu);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.revenu.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
