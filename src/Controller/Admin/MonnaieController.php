<?php

namespace App\Controller\Admin;

use App\Constantes\Constantes;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Form\InviteType;
use App\Entity\Utilisateur;
use App\Event\InvitationEvent;
use App\Form\MonnaieType;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\MonnaieRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\Turbo\TurboBundle;

#[Route("/admin/monnaie", name: 'admin.monnaie.')]
#[IsGranted('ROLE_USER')]
class MonnaieController extends AbstractController
{
    public function __construct(
        private MailerInterface $mailer,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private MonnaieRepository $monnaieRepository,
    ) {}

    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/monnaie/index.html.twig', [
            'pageName' => "Monnaies",
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'monnaies' => $this->monnaieRepository->paginateMonnaie($idEntreprise, $page),
            // 'nbMonnaies' => count($monaies->getItems()),
            'page' => $page,
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
        $monnaie->setFonction(Constantes::TAB_MONNAIE_FONCTIONS[Constantes::FONCTION_AUCUNE]);

        $form = $this->createForm(MonnaieType::class, $monnaie);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($monnaie);
            $this->manager->flush();
            return $this->redirectToRoute("admin.monnaie.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/monnaie/create.html.twig', [
            'pageName' => 'Nouveau',
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idMonnaie}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idMonnaie, Request $request)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Monnaie */
        $monnaie = $this->monnaieRepository->find($idMonnaie);

        $form = $this->createForm(MonnaieType::class, $monnaie);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($monnaie); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $monnaie->getNom() . " a été modifiée avec succès.");
            return $this->redirectToRoute("admin.monnaie.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/monnaie/edit.html.twig', [
            'pageName' => "Edition",
            'utilisateur' => $user,
            'monnaie' => $monnaie,
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'form' => $form,
        ]);
    }


    #[Route('/remove/{idEntreprise}/{idMonnaie}', name: 'remove', requirements: ['idMonnaie' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idMonnaie, Request $request)
    {
        /** @var Monnaie $monnaie */
        $monnaie = $this->monnaieRepository->find($idMonnaie);
        $monnaieId = $monnaie->getId();

        $message = $monnaie->getNom() . " a été supprimée avec succès.";
        
        $this->manager->remove($monnaie);
        $this->manager->flush();

        if ($request->getPreferredFormat() == TurboBundle::STREAM_FORMAT) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
            return $this->render("admin/monnaie/delete.html.twig", [
                'monnaieId' => $monnaieId,
                'messages' => $message,
                'type' => "success",
            ]);
        }
        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.monnaie.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
