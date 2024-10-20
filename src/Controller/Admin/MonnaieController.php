<?php

namespace App\Controller\Admin;

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

    #[Route('/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/monnaie/index.html.twig', [
            'pageName' => "Monnaies",
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'monnaies' => $this->monnaieRepository->paginateMonnaie($idEntreprise, $page),
            'page' => $page = $request->query->getInt("page", 1),
        ]);
    }


    #[Route('/{idEntreprise}', name: 'create')]
    public function create($idEntreprise, Request $request)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Monnaie */
        $monnaie = new Monnaie();
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
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'form' => $form,
        ]);
    }


    #[Route('/{idEntreprise}/{idMonnaie}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idMonnaie, Request $request)
    {
        // dd($invite);
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Monnaie */
        $monnaie = $this->monnaieRepository->find($idMonnaie);

        $form = $this->createForm(InviteType::class, $monnaie);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($monnaie); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $monnaie->getCode() . " a été modifiée avec succès.");
            return $this->redirectToRoute("admin.monnaie.index");
        }
        return $this->render('admin/monnaie/edit.html.twig', [
            'pageName' => "Edition",
            'utilisateur' => $user,
            'monnaie' => $monnaie,
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'form' => $form,
        ]);
    }


    #[Route('/{idEntreprise}/{idMonnaie}', name: 'remove', requirements: ['idMonnaie' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idMonnaie, Request $request)
    {
        /** @var Monnaie */
        $monnaie = $this->monnaieRepository->find($idMonnaie);
        $message = $monnaie->getNom() . " a été supprimé avec succès.";
        $this->manager->remove($monnaie);
        $this->manager->flush();

        if ($request->getPreferredFormat() == TurboBundle::STREAM_FORMAT) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
            return $this->render("admin/invite/delete.html.twig", [
                'monnaieId' => $monnaie,
                'messages' => $message,
                'type' => "success",
            ]);
        }
        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.invite.index");
    }
}
