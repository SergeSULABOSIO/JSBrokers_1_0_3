<?php

namespace App\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/invite", name: 'admin.invite.')]
class InviteController extends AbstractController
{
    #[Route(name: 'index')]
    public function index()
    {
        return $this->render('admin/invite/index.html.twig', [
            'pageName' => 'Home',
        ]);
    }


    #[Route('/create', name: 'create')]
    public function create() {}


    #[Route('/{id}', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit() {}


    #[Route('/{id}', name: 'remove', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove() {}
}
