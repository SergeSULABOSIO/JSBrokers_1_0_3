<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Form\PaiementType;
use App\Form\PartenaireType;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\PaiementRepository;
use App\Repository\PartenaireRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/paiement", name: 'admin.paiement.')]
#[IsGranted('ROLE_USER')]
class PaiementController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private PaiementRepository $paiementRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_FINANCE);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/paiement/index.html.twig', [
            'pageName' => $this->translator->trans("paiement_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'paiements' => $this->paiementRepository->paginateForEntreprise($idEntreprise, $page),
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

        /** @var Paiement $paiement */
        $paiement = new Paiement();
        //Paramètres par défaut

        $form = $this->createForm(PaiementType::class, $paiement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($paiement);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("paiement_creation_ok", [
                ":paiement" => $paiement->getDescription(),
            ]));
            return $this->redirectToRoute("admin.paiement.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/paiement/create.html.twig', [
            'pageName' => $this->translator->trans("paiement_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idPaiement}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idPaiement, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Paiement $paiement */
        $paiement = $this->paiementRepository->find($idPaiement);

        $form = $this->createForm(PaiementType::class, $paiement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($paiement); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("paiement_edition_ok", [
                ":paiement" => $paiement->getDescription(),
            ]));
            return $this->redirectToRoute("admin.paiement.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/paiement/edit.html.twig', [
            'pageName' => $this->translator->trans("paiement_page_name_update", [
                ":paiement" => $paiement->getDescription(),
            ]),
            'utilisateur' => $user,
            'paiement' => $paiement,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idPaiement}', name: 'remove', requirements: ['idPaiement' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idPaiement, Request $request)
    {
        /** @var Paiement $paiement */
        $paiement = $this->paiementRepository->find($idPaiement);

        $message = $this->translator->trans("paiement_deletion_ok", [
            ":paiement" => $paiement->getDescription(),
        ]);;
        
        $this->manager->remove($paiement);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.paiement.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
