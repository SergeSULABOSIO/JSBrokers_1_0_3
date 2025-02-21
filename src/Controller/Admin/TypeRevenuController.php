<?php

namespace App\Controller\Admin;

use App\Entity\Revenu;
use App\Form\RevenuType;
use App\Entity\Entreprise;
use App\Entity\TypeRevenu;
use App\Form\TypeRevenuType;
use App\Constantes\Constante;
use App\Entity\CompteBancaire;
use App\Services\ServiceTaxes;
use App\Form\CompteBancaireType;
use App\Constantes\MenuActivator;
use App\Services\ServiceMonnaies;
use App\Repository\InviteRepository;
use App\Repository\RevenuRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\TypeRevenuRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\CompteBancaireRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/typerevenu", name: 'admin.typerevenu.')]
#[IsGranted('ROLE_USER')]
class TypeRevenuController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private TypeRevenuRepository $typerevenuRepository,
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

        return $this->render('admin/typerevenu/index.html.twig', [
            'pageName' => $this->translator->trans("typerevenu_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'typerevenus' => $this->typerevenuRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'activator' => $this->activator,
            'serviceMonnaie' => $this->serviceMonnaies,
            'serviceTaxe' => $this->serviceTaxes,
        ]);
    }


    #[Route('/create/{idEntreprise}', name: 'create')]
    public function create($idEntreprise, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var TypeRevenu $typerevenu */
        $typerevenu = new TypeRevenu();
        //Paramètres par défaut
        $typerevenu->setNom("REVENUE" . (rand(2000, 3000)));
        $typerevenu->setEntreprise($entreprise);
        // $typerevenu->setFormule(TypeRevenu::FORMULE_POURCENTAGE_PRIME_NETTE);
        $typerevenu->setPourcentage(0.1);
        $typerevenu->setAppliquerPourcentageDuRisque(true);
        $typerevenu->setMontantflat(0);
        $typerevenu->setMultipayments(true);
        $typerevenu->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typerevenu->setShared(false);
        

        $form = $this->createForm(TypeRevenuType::class, $typerevenu);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($typerevenu);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("typerevenu_creation_ok", [
                ":typerevenu" => $typerevenu->getNom(),
            ]));
            return $this->redirectToRoute("admin.typerevenu.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/typerevenu/create.html.twig', [
            'pageName' => $this->translator->trans("typerevenu_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idTypeRevenu}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idTypeRevenu, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var TypeRevenu $typerevenu */
        $typerevenu = $this->typerevenuRepository->find($idTypeRevenu);

        $form = $this->createForm(TypeRevenuType::class, $typerevenu);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($typerevenu); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("typerevenu_edition_ok", [
                ":typerevenu" => $typerevenu->getNom(),
            ]));
            return $this->redirectToRoute("admin.typerevenu.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/typerevenu/edit.html.twig', [
            'pageName' => $this->translator->trans("typerevenu_page_name_update", [
                ":typerevenu" => $typerevenu->getNom(),
            ]),
            'utilisateur' => $user,
            'typerevenu' => $typerevenu,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idTypeRevenu}', name: 'remove', requirements: ['idTypeRevenu' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idTypeRevenu, Request $request)
    {
        /** @var TypeRevenu $typerevenu */
        $typerevenu = $this->typerevenuRepository->find($idTypeRevenu);

        $message = $this->translator->trans("typerevenu_deletion_ok", [
            ":typerevenu" => $typerevenu->getNom(),
        ]);;
        
        $this->manager->remove($typerevenu);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.typerevenu.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
