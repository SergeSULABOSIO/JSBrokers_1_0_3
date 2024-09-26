<?php

namespace App\Controller;

use App\Entity\UtilisateurJSB;
use App\Form\UtilisateurJSBType;
use App\Repository\UtilisateurJSBRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_index')]
    public function index(): Response
    {
        $this->addFlash("success", "Bienvenue chez JS Broker!");
        return $this->render('home/index.html.twig', [
            'pageName' => 'Home',
        ]);
    }

    #[Route('/user_login', name: 'app_user_login')]
    public function userLogin(): Response
    {
        return $this->render('home/user_login.html.twig', [
            'pageName' => 'Connexion',
        ]);
    }

    #[Route('/user_registration/{idUtilisateur}', name: 'app_user_registration')]
    public function userRegistration(int $idUtilisateur, UtilisateurJSBRepository $utilisateurJSBRepository, Request $request, EntityManagerInterface $manager): Response
    {
        /** @var UtilisateurJSB */
        $utilisateurJSB = new UtilisateurJSB();
        if ($idUtilisateur != -1) {
            $utilisateurJSB = $utilisateurJSBRepository->find($idUtilisateur);
        }
        $form = $this->createForm(UtilisateurJSBType::class, $utilisateurJSB);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($idUtilisateur == -1) {
                $utilisateurJSB->setCreatedAt(new DateTimeImmutable('now'));
                $utilisateurJSB->setUpdatedAt(new DateTimeImmutable('now'));
                $manager->persist($utilisateurJSB);
            } else {
                $utilisateurJSB->setUpdatedAt(new DateTimeImmutable('now'));
                $manager->refresh($utilisateurJSB);
            }
            $manager->flush();
            $this->addFlash("success", "" . $utilisateurJSB->getNom() . " enregistré avec succès.");
            return $this->redirectToRoute("app_user_login");
        }
        return $this->render('home/user_registration.html.twig', [
            'pageName' => 'Création du compte utilisateur',
            'form' => $form,
        ]);
    }

    #[Route('/user_dashbord/{idUtilisateur}', name: 'app_user_dashbord')]
    public function userDashbord(UtilisateurJSB $utilisateurJSB): Response
    {
        return $this->render('home/user_dashbord.html.twig', [
            'pageName' => 'Dashbord Utilisateur',
        ]);
    }

    #[Route('/broker_registration/{idUtilisateur}', name: 'app_broker_registration')]
    public function brokerRegistration(UtilisateurJSB $utilisateurJSB): Response
    {
        return $this->render('home/broker_registration.html.twig', [
            'pageName' => "Création de l'entreprise",
        ]);
    }

    #[Route('/broker_dashbord/{idUtilisateur}/{idEntreprise}', name: 'app_broker_dashbord')]
    public function brokerDashbord(int $idUtilisateur, int $idEntreprise): Response
    {
        return $this->render('home/broker_dashbord.html.twig', [
            'pageName' => 'Dashbord Entreprise',
        ]);
    }
}
