<?php

namespace App\Controller\Admin;

use App\Entity\Piste;
use App\Entity\Tache;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\Avenant;
use App\Form\PisteType;
use App\Form\TacheType;
use App\Form\GroupeType;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Repository\PisteRepository;
use App\Repository\TacheRepository;
use App\Repository\GroupeRepository;
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


#[Route("/admin/groupe", name: 'admin.groupe.')]
#[IsGranted('ROLE_USER')]
class GroupeController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private GroupeRepository $groupeRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_MARKETING);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/groupe/index.html.twig', [
            'pageName' => $this->translator->trans("groupe_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'groupes' => $this->groupeRepository->paginateForEntreprise($idEntreprise, $page),
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

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Groupe $groupe */
        $groupe = new Groupe();
        //Paramètres par défaut
        $groupe->setEntreprise($entreprise);

        $form = $this->createForm(GroupeType::class, $groupe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($groupe);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("groupe_creation_ok", [
                ":groupe" => $groupe->getNom(),
            ]));
            return $this->redirectToRoute("admin.groupe.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/groupe/create.html.twig', [
            'pageName' => $this->translator->trans("groupe_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idGroupe}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idGroupe, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Groupe $groupe */
        $groupe = $this->groupeRepository->find($idGroupe);

        $form = $this->createForm(GroupeType::class, $groupe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($groupe);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("groupe_edition_ok", [
                ":groupe" => $groupe->getNom(),
            ]));

            //On doit rester sur la page d'édition
            // return $this->redirectToRoute("admin.piste.index", [
            //     'idEntreprise' => $idEntreprise,
            // ]);
        }
        return $this->render('admin/groupe/edit.html.twig', [
            'pageName' => $this->translator->trans("groupe_page_name_update", [
                ":groupe" => $groupe->getNom(),
            ]),
            'utilisateur' => $user,
            'groupe' => $groupe,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idGroupe}', name: 'remove', requirements: ['idGroupe' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idGroupe, Request $request)
    {
        /** @var Groupe $groupe */
        $groupe = $this->groupeRepository->find($idGroupe);

        $message = $this->translator->trans("groupe_deletion_ok", [
            ":groupe" => $groupe->getNom(),
        ]);;
        
        $this->manager->remove($groupe);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.groupe.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
