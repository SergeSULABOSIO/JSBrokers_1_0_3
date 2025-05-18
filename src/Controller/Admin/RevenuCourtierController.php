<?php

namespace App\Controller\Admin;

use App\Entity\Piste;
use App\Entity\Tache;
use App\Entity\Invite;
use App\Entity\Avenant;
use App\Form\PisteType;
use App\Form\TacheType;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Services\ServiceTaxes;
use App\Constantes\MenuActivator;
use App\Services\ServiceMonnaies;
use App\Entity\RevenuPourCourtier;
use App\Repository\PisteRepository;
use App\Repository\TacheRepository;
use App\Form\RevenuPourCourtierType;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\RevenuPourCourtierRepository;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

#[Route("/admin/revenucourtier", name: 'admin.revenucourtier.')]
#[IsGranted('ROLE_USER')]
class RevenuCourtierController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private RevenuPourCourtierRepository $revenuCourtierRepository,
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

        return $this->render('admin/revenucourtier/index.html.twig', [
            'pageName' => $this->translator->trans("revenucourtier_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'revenucourtiers' => $this->revenuCourtierRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'serviceTaxe' => $this->serviceTaxes,
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

        /** @var RevenuPourCourtier $revenucourtier */
        $revenucourtier = new RevenuPourCourtier();
        //Paramètres par défaut

        $form = $this->createForm(RevenuPourCourtierType::class, $revenucourtier);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($revenucourtier);
            $this->manager->flush();
            // $this->addFlash("success", $this->translator->trans("revenucourtier_creation_ok", [
            //     ":revenucourtier" => $revenucourtier->getNom(),
            // ]));
            // return $this->redirectToRoute("admin.revenucourtier.index", [
            //     'idEntreprise' => $idEntreprise,
            // ]);
            return new Response("Ok");
        }
        return $this->render('admin/revenucourtier/create.html.twig', [
            'pageName' => $this->translator->trans("revenucourtier_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idRevenucourtier}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idRevenucourtier, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var RevenuPourCourtier $revenucourtier */
        $revenucourtier = $this->revenuCourtierRepository->find($idRevenucourtier);

        $form = $this->createForm(RevenuPourCourtierType::class, $revenucourtier);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($revenucourtier); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            return new Response("Ok");
        }
        return $this->render('admin/revenucourtier/edit.html.twig', [
            'pageName' => $this->translator->trans("revenucourtier_page_name_update", [
                ":revenucourtier" => $revenucourtier->getNom(),
            ]),
            'utilisateur' => $user,
            'revenucourtier' => $revenucourtier,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idRevenucourtier}', name: 'remove', requirements: ['idPiste' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idRevenucourtier, Request $request)
    {
        /** @var RevenuPourCourtier $revenucourtier */
        $revenucourtier = $this->revenuCourtierRepository->find($idRevenucourtier);

        $message = $this->translator->trans("revenucourtier_deletion_ok", [
            ":revenucourtier" => $revenucourtier->getNom(),
        ]);;
        
        $this->manager->remove($revenucourtier);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.revenucourtier.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
