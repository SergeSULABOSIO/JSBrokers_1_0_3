<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Tache;
use App\Form\BordereauType;
use App\Form\PisteType;
use App\Form\TacheType;
use App\Repository\BordereauRepository;
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


#[Route("/admin/bordereau", name: 'admin.bordereau.')]
#[IsGranted('ROLE_USER')]
class BordereauController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private BordereauRepository $bordereauRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_FINANCE);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/bordereau/index.html.twig', [
            'pageName' => $this->translator->trans("bordereau_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'bordereaux' => $this->bordereauRepository->paginateForEntreprise($idEntreprise, $page),
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

        /** @var Bordereau $bordereau */
        $bordereau = new Bordereau();
        //Paramètres par défaut
        $bordereau->setType(Bordereau::TYPE_BOREDERAU_PRODUCTION);
        $bordereau->setInvite($invite);

        $form = $this->createForm(BordereauType::class, $bordereau);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($bordereau);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("bordereau_creation_ok", [
                ":bordereau" => $bordereau->getNom(),
            ]));
            return $this->redirectToRoute("admin.bordereau.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/bordereau/create.html.twig', [
            'pageName' => $this->translator->trans("bordereau_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idBordereau}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idBordereau, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Bordereau $bordereau */
        $bordereau = $this->bordereauRepository->find($idBordereau);

        $form = $this->createForm(BordereauType::class, $bordereau);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($bordereau); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("bordereau_edition_ok", [
                ":bordereau" => $bordereau->getNom(),
            ]));

            //On doit rester sur la page d'édition
            // return $this->redirectToRoute("admin.piste.index", [
            //     'idEntreprise' => $idEntreprise,
            // ]);
        }
        return $this->render('admin/bordereau/edit.html.twig', [
            'pageName' => $this->translator->trans("bordereau_page_name_update", [
                ":bordereau" => $bordereau->getNom(),
            ]),
            'utilisateur' => $user,
            'bordereau' => $bordereau,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idBordereau}', name: 'remove', requirements: ['idBordereau' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idBordereau, Request $request)
    {
        /** @var Bordereau $bordereau */
        $bordereau = $this->bordereauRepository->find($idBordereau);

        $message = $this->translator->trans("bordereau_deletion_ok", [
            ":bordereau" => $bordereau->getNom(),
        ]);;
        
        $this->manager->remove($bordereau);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.bordereau.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
