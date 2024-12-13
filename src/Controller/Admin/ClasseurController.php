<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\Chargement;
use App\Entity\Classeur;
use App\Form\ChargementType;
use App\Repository\ChargementRepository;
use App\Repository\ClasseurRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpParser\Builder\Class_;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/classeur", name: 'admin.classeur.')]
#[IsGranted('ROLE_USER')]
class ClasseurController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ClasseurRepository $classeurRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_ADMINISTRATION);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/classeur/index.html.twig', [
            'pageName' => $this->translator->trans("classeur_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'classeurs' => $this->classeurRepository->paginate($idEntreprise, $page),
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

        /** @var Classeur $classeur */
        $classeur = new Classeur();
        //Paramètres par défaut
        $classeur->setNom("CLSS" . (rand(0, 100)));
        $classeur->setNom("");
        $classeur->setEntreprise($entreprise);

        $form = $this->createForm(ChargementType::class, $classeur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($classeur);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("classeur_creation_ok", [
                ":classeur" => $classeur->getNom(),
            ]));
            return $this->redirectToRoute("admin.classeur.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/classeur/create.html.twig', [
            'pageName' => $this->translator->trans("classeur_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idClasseur}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idClasseur, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Classeur $classeur */
        $classeur = $this->classeurRepository->find($idClasseur);

        $form = $this->createForm(ChargementType::class, $classeur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($classeur); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("classeur_edition_ok", [
                ":classeur" => $classeur->getNom(),
            ]));
            return $this->redirectToRoute("admin.classeur.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/classeur/edit.html.twig', [
            'pageName' => $this->translator->trans("classeur_page_name_update", [
                ":classeur" => $classeur->getNom(),
            ]),
            'utilisateur' => $user,
            'classeur' => $classeur,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idClasseur}', name: 'remove', requirements: ['idClasseur' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idClasseur, Request $request)
    {
        /** @var Classeur $classeur */
        $classeur = $this->classeurRepository->find($idClasseur);

        $message = $this->translator->trans("classeur_deletion_ok", [
            ":classeur" => $classeur->getNom(),
        ]);;
        
        $this->manager->remove($classeur);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.classeur.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
