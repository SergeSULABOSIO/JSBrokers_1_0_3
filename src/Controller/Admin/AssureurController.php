<?php

namespace App\Controller\Admin;

use App\Entity\Assureur;
use App\Entity\Entreprise;
use App\Form\AssureurType;
use App\Constantes\Constante;
use App\Services\ServiceTaxes;
use App\Constantes\MenuActivator;
use App\Services\ServiceMonnaies;
use App\Repository\InviteRepository;
use App\Repository\AssureurRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/assureur", name: 'admin.assureur.')]
#[IsGranted('ROLE_USER')]
class AssureurController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private AssureurRepository $assureurRepository,
        private Constante $constante,
        private ServiceMonnaies $serviceMonnaies,
        private ServiceTaxes $serviceTaxes,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_PRODUCTION);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/assureur/index.html.twig', [
            'pageName' => $this->translator->trans("assureur_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'assureurs' => $this->assureurRepository->paginateForEntreprise($idEntreprise, $page),
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

        /** @var Assureur $assureur */
        $assureur = new Assureur();
        //Paramètres par défaut
        $assureur->setEntreprise($entreprise);

        $form = $this->createForm(AssureurType::class, $assureur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($assureur);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("assureur_creation_ok", [
                ":assureur" => $assureur->getNom(),
            ]));
            return $this->redirectToRoute("admin.assureur.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/assureur/create.html.twig', [
            'pageName' => $this->translator->trans("assureur_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idAssureur}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idAssureur, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Assureur $assureur */
        $assureur = $this->assureurRepository->find($idAssureur);

        $form = $this->createForm(AssureurType::class, $assureur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($assureur); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("assureur_edition_ok", [
                ":assureur" => $assureur->getNom(),
            ]));
            return $this->redirectToRoute("admin.assureur.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/assureur/edit.html.twig', [
            'pageName' => $this->translator->trans("assureur_page_name_update", [
                ":assureur" => $assureur->getNom(),
            ]),
            'utilisateur' => $user,
            'assureur' => $assureur,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idAssureur}', name: 'remove', requirements: ['idAssureur' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idAssureur, Request $request)
    {
        /** @var Assureur $assureur */
        $assureur = $this->assureurRepository->find($idAssureur);

        $message = $this->translator->trans("assureur_deletion_ok", [
            ":assureur" => $assureur->getNom(),
        ]);;
        
        $this->manager->remove($assureur);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.assureur.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
