<?php

namespace App\Controller\Admin;

use App\Entity\Taxe;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\CompteBancaire;
use App\Form\CompteBancaireType;
use App\Form\TaxeType;
use App\Repository\CompteBancaireRepository;
use App\Repository\TaxeRepository;
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


#[Route("/admin/comptebancaire", name: 'admin.comptebancaire.')]
#[IsGranted('ROLE_USER')]
class CompteBancaireController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private CompteBancaireRepository $compteBancaireRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_FINANCE);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/comptebancaire/index.html.twig', [
            'pageName' => $this->translator->trans("comptebancaire_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'comptebancaires' => $this->compteBancaireRepository->paginate($idEntreprise, $page),
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

        /** @var CompteBancaire $compteBancaire */
        $compteBancaire = new CompteBancaire();
        //Paramètres par défaut
        $compteBancaire->setEntreprise($entreprise);
        

        $form = $this->createForm(CompteBancaireType::class, $compteBancaire);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($compteBancaire);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("comptebancaire_creation_ok", [
                ":comptebancaire" => $compteBancaire->getIntitule(),
            ]));
            return $this->redirectToRoute("admin.comptebancaire.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/comptebancaire/create.html.twig', [
            'pageName' => $this->translator->trans("comptebancaire_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idCompteBancaire}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idCompteBancaire, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var CompteBancaire $compteBancaire */
        $compteBancaire = $this->compteBancaireRepository->find($idCompteBancaire);

        $form = $this->createForm(CompteBancaireType::class, $compteBancaire);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($compteBancaire); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("comptebancaire_edition_ok", [
                ":comptebancaire" => $compteBancaire->getIntitule(),
            ]));
            return $this->redirectToRoute("admin.comptebancaire.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/comptebancaire/edit.html.twig', [
            'pageName' => $this->translator->trans("compteBancaire_page_name_update", [
                ":comptebancaire" => $compteBancaire->getIntitule(),
            ]),
            'utilisateur' => $user,
            'comptebancaire' => $compteBancaire,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idCompteBancaire}', name: 'remove', requirements: ['idCompteBancaire' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idCompteBancaire, Request $request)
    {
        /** @var CompteBancaire $compteBancaire */
        $compteBancaire = $this->compteBancaireRepository->find($idCompteBancaire);

        $message = $this->translator->trans("comptebancaire_deletion_ok", [
            ":comptebancaire" => $compteBancaire->getIntitule(),
        ]);;
        
        $this->manager->remove($compteBancaire);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.comptebancaire.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
