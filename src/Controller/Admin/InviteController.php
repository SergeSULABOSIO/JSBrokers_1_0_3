<?php
namespace App\Controller\Admin;

use App\Entity\Invite;
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
    #[Route(name: 'index')]
    public function index($idUtilisateur, UtilisateurJSBRepository $utilisateurJSBRepository, InviteRepository $inviteRepository)
    {
        $utilisateur = $utilisateurJSBRepository->find($idUtilisateur);
        return $this->render('admin/invite/index.html.twig', [
            'pageName' => "Liste d'invités",
            'idUtilisateur' => $utilisateur->getId(),
            'utilisateur' => $utilisateur,
            'invites' => $inviteRepository->findAll(),
        ]);
    }


    #[Route('/create', name: 'create')]
    public function create($idUtilisateur, UtilisateurJSBRepository $utilisateurJSBRepository, Request $request, EntityManagerInterface $manager)
    {
        $utilisateur = $utilisateurJSBRepository->find($idUtilisateur);
        /** @var Invite */
        $invite = new Invite();
        $form = $this->createForm(Invite::class, $invite);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->persist($invite);
            $manager->flush();
            $this->addFlash("success", $invite->getEmail() . " a été invité avec succès.");
            return $this->redirectToRoute("admin.invite.index", [
                'idUtilisateur' => $utilisateur->getId()
            ]);
        }
        return $this->render('admin/invite/create.html.twig', [
            'pageName' => 'Nouvel Invité',
            'idUtilisateur' => $utilisateur->getId(),
            'utilisateur' => $utilisateur,
            'form' => $form,
        ]);
    }


    #[Route('/{id}', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idUtilisateur, UtilisateurJSBRepository $utilisateurJSBRepository, Invite $invite, Request $request, EntityManagerInterface $manager)
    {
        $utilisateur = $utilisateurJSBRepository->find($idUtilisateur);
        $form = $this->createForm(Invite::class, $invite);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->persist($invite);
            $manager->flush();
            $this->addFlash("success", $invite->getEmail() . " a été modifié avec succès.");
            return $this->redirectToRoute("admin.invite.index", [
                'idUtilisateur' => $utilisateur->getId(),
            ]);
        }
        return $this->render('admin/invite/edit.html.twig', [
            'pageName' => "Edition de l'invité",
            'invite' => $invite,
            'idUtilisateur' => $utilisateur->getId(),
            'utilisateur' => $utilisateur,
            'form' => $form,
        ]);
    }


    #[Route('/{id}', name: 'remove', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idUtilisateur, UtilisateurJSBRepository $utilisateurJSBRepository, Invite $invite, EntityManagerInterface $manager)
    {
        $utilisateur = $utilisateurJSBRepository->find($idUtilisateur);
        $manager->remove($invite);
        $manager->flush();
        $this->addFlash("success", $invite->getEmail() . " a été supprimé avec succès.");
        return $this->redirectToRoute("admin.invite.index", [
            'idUtilisateur' => $utilisateur->getId()
        ]);
    }
}
