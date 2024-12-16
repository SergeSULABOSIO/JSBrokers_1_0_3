<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\ModelePieceSinistre;
use App\Form\ModelePieceSinistreType;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\ModelePieceSinistreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/modelepiecesinistre", name: 'admin.modelepiecesinistre.')]
#[IsGranted('ROLE_USER')]
class ModelePieceSinistreController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ModelePieceSinistreRepository $modelePieceSinistreRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_CLAIMS);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/modelepiecesinistre/index.html.twig', [
            'pageName' => $this->translator->trans("modelepiecesinistre_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'modelepiecesinistres' => $this->modelePieceSinistreRepository->paginate($idEntreprise, $page),
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

        /** @var ModelePieceSinistre $modele */
        $modele = new ModelePieceSinistre();
        //Paramètres par défaut
        $modele->setEntreprise($entreprise);

        $form = $this->createForm(ModelePieceSinistreType::class, $modele);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($modele);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("modelepiecesinistre_creation_ok", [
                ":modelepiecesinistre" => $modele->getNom(),
            ]));
            return $this->redirectToRoute("admin.modelepiecesinistre.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/modelepiecesinistre/create.html.twig', [
            'pageName' => $this->translator->trans("modelepiecesinistre_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idModelepiecesinistre}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idModelepiecesinistre, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var ModelePieceSinistre $modele */
        $modele = $this->modelePieceSinistreRepository->find($idModelepiecesinistre);

        $form = $this->createForm(ModelePieceSinistreType::class, $modele);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($modele); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("modelepiecesinistre_edition_ok", [
                ":modelepiecesinistre" => $modele->getNom(),
            ]));
            return $this->redirectToRoute("admin.modelepiecesinistre.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/modelepiecesinistre/edit.html.twig', [
            'pageName' => $this->translator->trans("modelepiecesinistre_page_name_update", [
                ":modelepiecesinistre" => $modele->getNom(),
            ]),
            'utilisateur' => $user,
            'modelepiecesinistre' => $modele,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idModelepiecesinistre}', name: 'remove', requirements: ['idModelepiecesinistre' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idModelepiecesinistre, Request $request)
    {
        /** @var ModelePieceSinistre $modele */
        $modele = $this->modelePieceSinistreRepository->find($modele);

        $message = $this->translator->trans("modelepiecesinistre_deletion_ok", [
            ":modelepiecesinistre" => $modele->getNom(),
        ]);;
        
        $this->manager->remove($modele);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.modelepiecesinistre.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}