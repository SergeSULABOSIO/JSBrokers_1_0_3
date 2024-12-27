<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\Assureur;
use App\Entity\Partenaire;
use App\Form\AssureurType;
use App\Form\PartenaireType;
use App\Repository\AssureurRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\PartenaireRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/partenaire", name: 'admin.partenaire.')]
#[IsGranted('ROLE_USER')]
class PartenaireController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private PartenaireRepository $partenaireRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_PRODUCTION);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/partenaire/index.html.twig', [
            'pageName' => $this->translator->trans("partenaire_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'partenaires' => $this->partenaireRepository->paginateForEntreprise($idEntreprise, $page),
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

        /** @var Partenaire $partenaire */
        $partenaire = new Partenaire();
        //Paramètres par défaut
        $partenaire->setPart(0.10);
        $partenaire->setEntreprise($entreprise);

        $form = $this->createForm(PartenaireType::class, $partenaire);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($partenaire);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("partenaire_creation_ok", [
                ":partenaire" => $partenaire->getNom(),
            ]));
            return $this->redirectToRoute("admin.partenaire.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/partenaire/create.html.twig', [
            'pageName' => $this->translator->trans("partenaire_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idPartenaire}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idPartenaire, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Partenaire $partenaire */
        $partenaire = $this->partenaireRepository->find($idPartenaire);

        $form = $this->createForm(PartenaireType::class, $partenaire);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($partenaire); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("partenaire_edition_ok", [
                ":partenaire" => $partenaire->getNom(),
            ]));
            return $this->redirectToRoute("admin.partenaire.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/partenaire/edit.html.twig', [
            'pageName' => $this->translator->trans("partenaire_page_name_update", [
                ":partenaire" => $partenaire->getNom(),
            ]),
            'utilisateur' => $user,
            'partenaire' => $partenaire,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idPartenaire}', name: 'remove', requirements: ['idPartenaire' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idPartenaire, Request $request)
    {
        /** @var Partenaire $partenaire */
        $partenaire = $this->partenaireRepository->find($idPartenaire);

        $message = $this->translator->trans("partenaire_deletion_ok", [
            ":partenaire" => $partenaire->getNom(),
        ]);;
        
        $this->manager->remove($partenaire);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.partenaire.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
