<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Tache;
use App\Form\PisteType;
use App\Form\TacheType;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\PisteRepository;
use App\Repository\TacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/piste", name: 'admin.piste.')]
#[IsGranted('ROLE_USER')]
class PisteController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private PisteRepository $pisteRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_MARKETING);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/piste/index.html.twig', [
            'pageName' => $this->translator->trans("piste_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'pistes' => $this->pisteRepository->paginate($idEntreprise, $page),
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

        /** @var Piste $piste */
        $piste = new Piste();
        //Paramètres par défaut
        $piste->setInvite($invite);

        $form = $this->createForm(PisteType::class, $piste);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($piste);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("piste_creation_ok", [
                ":piste" => $piste->getNom(),
            ]));
            return $this->redirectToRoute("admin.piste.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/piste/create.html.twig', [
            'pageName' => $this->translator->trans("piste_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idPiste}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idPiste, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Piste $piste */
        $piste = $this->pisteRepository->find($idPiste);

        $form = $this->createForm(PisteType::class, $piste);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($piste); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("piste_edition_ok", [
                ":piste" => $piste->getNom(),
            ]));
            return $this->redirectToRoute("admin.piste.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/piste/edit.html.twig', [
            'pageName' => $this->translator->trans("piste_page_name_update", [
                ":piste" => $piste->getNom(),
            ]),
            'utilisateur' => $user,
            'piste' => $piste,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idPiste}', name: 'remove', requirements: ['idPiste' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idPiste, Request $request)
    {
        /** @var Piste $piste */
        $piste = $this->pisteRepository->find($idPiste);

        $message = $this->translator->trans("piste_deletion_ok", [
            ":piste" => $piste->getNom(),
        ]);;
        
        $this->manager->remove($piste);
        $this->manager->flush();
        
        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.piste.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
