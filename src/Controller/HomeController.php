<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_index')]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'pageName' => 'Home',
        ]);
    }

    #[Route('/user_login', name: 'app_user_login')]
    public function userLogin(): Response
    {
        return $this->render('home/user_login.html.twig', [
            'pageName' => 'User Login',
        ]);
    }

    #[Route('/user_registration', name: 'app_user_registration')]
    public function userRegistration(): Response
    {
        return $this->render('home/user_registration.html.twig', [
            'pageName' => 'User Registration',
        ]);
    }

    #[Route('/user_dashbord/{idUtilisateur}', name: 'app_user_dashbord')]
    public function userDashbord(int $idUtilisateur): Response
    {
        return $this->render('home/user_dashbord.html.twig', [
            'pageName' => 'User Dashbord',
        ]);
    }

    #[Route('/broker_registration/{idUtilisateur}', name: 'app_broker_registration')]
    public function brokerRegistration(int $idUtilisateur): Response
    {
        return $this->render('home/broker_registration.html.twig', [
            'pageName' => 'Broker Registration',
        ]);
    }

    #[Route('/broker_dashbord/{idUtilisateur}/{idEntreprise}', name: 'app_broker_dashbord')]
    public function brokerDashbord(int $idUtilisateur, int $idEntreprise): Response
    {
        return $this->render('home/broker_dashbord.html.twig', [
            'pageName' => 'Broker Dashbord',
        ]);
    }
}
