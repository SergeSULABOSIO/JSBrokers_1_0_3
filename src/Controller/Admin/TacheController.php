<?php

namespace App\Controller\Admin;

use App\Entity\Taxe;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\Invite;
use App\Entity\Tache;
use App\Form\TacheType;
use App\Form\TaxeType;
use App\Repository\TaxeRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\TacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/tache", name: 'admin.tache.')]
#[IsGranted('ROLE_USER')]
class TacheController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private TacheRepository $tacheRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_MARKETING);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/tache/index.html.twig', [
            'pageName' => $this->translator->trans("tache_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'taches' => $this->tacheRepository->paginateForInvite($idEntreprise, $page),
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

        /** @var Tache */
        $tache = new Tache();
        //Paramètres par défaut
        $tache->setEntreprise($entreprise);
        $tache->setClosed(false);
        $tache->setInvite($invite);

        $form = $this->createForm(TacheType::class, $tache);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($tache);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("tache_creation_ok", [
                ":tache" => $tache->getDescription(),
            ]));
            return $this->redirectToRoute("admin.tache.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/tache/create.html.twig', [
            'pageName' => $this->translator->trans("tache_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idTache}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idTache, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Tache */
        $tache = $this->tacheRepository->find($idTache);

        $form = $this->createForm(TacheType::class, $tache);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($tache); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("tache_edition_ok", [
                ":tache" => $tache->getDescription(),
            ]));
            return $this->redirectToRoute("admin.tache.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/tache/edit.html.twig', [
            'pageName' => $this->translator->trans("tache_page_name_update", [
                ":tache" => $tache->getDescription(),
            ]),
            'utilisateur' => $user,
            'tache' => $tache,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idTache}', name: 'remove', requirements: ['idTache' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idTache, Request $request)
    {
        /** @var Tache $tache */
        $tache = $this->tacheRepository->find($idTache);

        $message = $this->translator->trans("tache_deletion_ok", [
            ":tache" => $tache->getDescription(),
        ]);;
        
        $this->manager->remove($tache);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.tache.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
