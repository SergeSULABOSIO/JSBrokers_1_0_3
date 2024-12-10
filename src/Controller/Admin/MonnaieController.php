<?php

namespace App\Controller\Admin;

use App\Constantes\Constante;
use App\Entity\Monnaie;
use App\Form\MonnaieType;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Constantes\MenuActivator;
use App\Repository\InviteRepository;
use App\Repository\MonnaieRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/monnaie", name: 'admin.monnaie.')]
#[IsGranted('ROLE_USER')]
class MonnaieController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private MonnaieRepository $monnaieRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_FINANCE);
    }

    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/monnaie/index.html.twig', [
            'pageName' => $this->translator->trans("currency_page_name_list"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'monnaies' => $this->monnaieRepository->paginateMonnaie($idEntreprise, $page),
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

        /** @var Monnaie */
        $monnaie = new Monnaie();
        //Paramètres par défaut
        $monnaie->setEntreprise($entreprise);
        $monnaie->setTauxusd(1);
        $monnaie->setLocale(false);
        $monnaie->setFonction(-1);

        $form = $this->createForm(MonnaieType::class, $monnaie);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($monnaie);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("currency_creation_ok", [
                ":currency" => $monnaie->getNom(),
            ]));
            return $this->redirectToRoute("admin.monnaie.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/monnaie/create.html.twig', [
            'pageName' => $this->translator->trans("currency_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idMonnaie}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idMonnaie, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Monnaie */
        $monnaie = $this->monnaieRepository->find($idMonnaie);

        $form = $this->createForm(MonnaieType::class, $monnaie);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($monnaie); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("currency_edition_ok", [
                ":currency" => $monnaie->getNom(),
            ]));
            return $this->redirectToRoute("admin.monnaie.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/monnaie/edit.html.twig', [
            'pageName' => $this->translator->trans("currency_page_name_update", [
                ":currency" => $monnaie->getNom(),
            ]),
            'utilisateur' => $user,
            'monnaie' => $monnaie,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/remove/{idEntreprise}/{idMonnaie}', name: 'remove', requirements: ['idMonnaie' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idMonnaie, Request $request)
    {
        /** @var Monnaie $monnaie */
        $monnaie = $this->monnaieRepository->find($idMonnaie);
        $monnaieId = $monnaie->getId();

        $message = $this->translator->trans("currency_deletion_ok", [
            ":currency" => $monnaie->getNom(),
        ]);;
        
        $this->manager->remove($monnaie);
        $this->manager->flush();

        // if ($request->getPreferredFormat() == TurboBundle::STREAM_FORMAT) {
        //     $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
        //     return $this->render("admin/monnaie/delete.html.twig", [
        //         'monnaieId' => $monnaieId,
        //         'messages' => $message,
        //         'type' => "success",
        //     ]);
        // }
        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.monnaie.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
