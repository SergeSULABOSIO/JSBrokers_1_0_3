<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Form\InviteType;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Repository\UtilisateurJSBRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

#[Route("/admin/{idUtilisateur}/invite", name: 'admin.invite.')]
class InviteController extends AbstractController
{

    public function __construct(
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private UtilisateurJSBRepository $utilisateurJSBRepository,
    ) {}

    #[Route(name: 'index')]
    public function index($idUtilisateur)
    {
        $utilisateur = $this->utilisateurJSBRepository->find($idUtilisateur);
        return $this->render('admin/invite/index.html.twig', [
            'pageName' => "Invités",
            'utilisateur' => $utilisateur,
            'invites' => $this->inviteRepository->findAll(),
            'entreprises' => $this->entrepriseRepository->findAll(),
        ]);
    }


    #[Route('/create', name: 'create')]
    public function create($idUtilisateur, Request $request)
    {
        // dd("Je suis ici: création de l'invité.");
        $utilisateur = $this->utilisateurJSBRepository->find($idUtilisateur);
        /** @var Invite */
        $invite = new Invite();
        $form = $this->createForm(InviteType::class, $invite);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($invite);
            $this->manager->flush();
            $this->addFlash("success", $invite->getEmail() . " a été invité avec succès.");
            return $this->redirectToRoute("admin.invite.index", [
                'idUtilisateur' => $utilisateur->getId()
            ]);
        }
        return $this->render('admin/invite/create.html.twig', [
            'pageName' => 'Nouveau',
            'idUtilisateur' => $utilisateur->getId(),
            'utilisateur' => $utilisateur,
            'invites' => $this->inviteRepository->findAll(),
            'entreprises' => $this->entrepriseRepository->findAll(),
            'form' => $form,
        ]);
    }


    #[Route('/{id}', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idUtilisateur, Invite $invite, Request $request)
    {
        $utilisateur = $this->utilisateurJSBRepository->find($idUtilisateur);
        $form = $this->createForm(Invite::class, $invite);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($invite);
            $this->manager->flush();
            $this->addFlash("success", $invite->getEmail() . " a été modifié avec succès.");
            return $this->redirectToRoute("admin.invite.index", [
                'idUtilisateur' => $utilisateur->getId(),
            ]);
        }
        return $this->render('admin/invite/edit.html.twig', [
            'pageName' => "Edition",
            'invite' => $invite,
            'idUtilisateur' => $utilisateur->getId(),
            'utilisateur' => $utilisateur,
            'form' => $form,
        ]);
    }


    #[Route('/{id}', name: 'remove', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idUtilisateur, Invite $invite)
    {
        $utilisateur = $this->utilisateurJSBRepository->find($idUtilisateur);
        $this->manager->remove($invite);
        $this->manager->flush();
        $this->addFlash("success", $invite->getEmail() . " a été supprimé avec succès.");
        return $this->redirectToRoute("admin.invite.index", [
            'idUtilisateur' => $utilisateur->getId()
        ]);
    }
}
