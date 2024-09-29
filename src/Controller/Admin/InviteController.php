<?php

namespace App\Controller;

use App\Entity\Invite;
use App\Repository\InviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

#[Route("/admin/invite", name: 'admin.invite.')]
class InviteController extends AbstractController
{
    #[Route(name: 'index')]
    public function index(InviteRepository $inviteRepository)
    {
        return $this->render('admin/invite/index.html.twig', [
            'pageName' => 'Invités',
            'invites' => $inviteRepository->findAll(),
        ]);
    }


    #[Route('/create', name: 'create')]
    public function create(Request $request, EntityManagerInterface $manager)
    {
        /** @var Invite */
        $invite = new Invite();
        $form = $this->createForm(Invite::class, $invite);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->persist($invite);
            $manager->flush();
            $this->addFlash("success", $invite->getEmail() . " a été invité avec succès.");
            return $this->redirectToRoute("app_user_dashbord", [
                'idUtilisateur' => 32
            ]);
        }
        return $this->render('admin/invite/create.html.twig', [
            'pageName' => 'Nouvel Invité',
            'form' => $form,
        ]);
    }


    #[Route('/{id}', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit() {}


    #[Route('/{id}', name: 'remove', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove() {}
}
