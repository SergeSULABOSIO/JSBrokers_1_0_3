<?php

namespace App\Controller;

use App\DTO\ConnexionDTO;
use App\DTO\ContactDTO;
use App\Entity\Entreprise;
use App\Form\ContactType;
use App\Entity\UtilisateurJSB;
use App\Form\ConnexionType;
use App\Form\EntrepriseType;
use App\Form\UtilisateurJSBType;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use Symfony\Component\Mime\Email;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\UtilisateurJSBRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


class HomeController extends AbstractController
{

    public function __construct(
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
    )
    {
        
    }


    #[Route('/', name: 'app_index')]
    public function index(): Response
    {
        $this->addFlash("success", "Bienvenue chez JS Broker!");
        return $this->render('home/index.html.twig', [
            'pageName' => 'Home',
        ]);
    }




    #[Route('/user_login', name: 'app_user_login')]
    public function userLogin(Request $request, UtilisateurJSBRepository $repository): Response
    {
        /** @var ConnexionDTO */
        $data = new ConnexionDTO();
        $form = $this->createForm(ConnexionType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user = null;
            $tabResultats = $repository->findBy([
                'email' => $data->email,
                'motDePasse' => $data->motdepasse
            ]);
            if (isset($tabResultats) && count($tabResultats) != 0) {
                /** @var UtilisateurJSB */
                $user = $tabResultats[0];
                if ($user->getEmail() == $data->email && $user->getMotDePasse() == $data->motdepasse) {
                    $this->addFlash("success", "Bienvenue " . $user->getNom());
                    return $this->redirectToRoute('app_user_dashbord', [
                        'idUtilisateur' => $user->getId(),
                        'utilisateur' => $user,
                    ]);
                }
            }
            // dd($data, $tabResultats);
            // dd($failed);
            $this->addFlash("danger", "Identifiants incorrects.");
            return $this->redirectToRoute('app_user_login', [
                'pageName' => 'Connexion',
                'form' => $form
            ]);
        }
        return $this->render('home/user_login.html.twig', [
            'pageName' => 'Connexion',
            'form' => $form
        ]);
    }




    #[Route('/user_registration/{idUtilisateur}', name: 'app_user_registration')]
    public function userRegistration(int $idUtilisateur, UtilisateurJSBRepository $utilisateurJSBRepository, Request $request, EntityManagerInterface $manager): Response
    {
        $tittrePage = "Création du compte utilisateur";

        /** @var UtilisateurJSB */
        $utilisateurJSB = new UtilisateurJSB();
        if ($idUtilisateur != -1) {
            $utilisateurJSB = $utilisateurJSBRepository->find($idUtilisateur);
            $tittrePage = "Edition du compte " . $utilisateurJSB->getNom();
        }
        $form = $this->createForm(UtilisateurJSBType::class, $utilisateurJSB);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->persist($utilisateurJSB);
            $manager->flush();

            if($idUtilisateur != -1){
                $this->addFlash("success", "" . $utilisateurJSB->getNom() . ", votre profil est mis à jour avec succès.");
                return $this->redirectToRoute("app_user_dashbord", [
                    'idUtilisateur'=> $utilisateurJSB->getId()
                ]);
            }else{
                $this->addFlash("success", "" . $utilisateurJSB->getNom() . ", votre compte vient d'être créée.");
                return $this->redirectToRoute("app_user_login");
            }
        }
        return $this->render('home/user_registration.html.twig', [
            'pageName' => $tittrePage,
            'form' => $form,
        ]);
    }




    #[Route('/user_dashbord/{idUtilisateur}', name: 'app_user_dashbord')]
    public function userDashbord($idUtilisateur, UtilisateurJSBRepository $repositoryUtilisateur): Response
    {
        // dd($listeEntreprises);
        return $this->render('home/user_dashbord.html.twig', [
            'pageName' => "Liste d'entreprises",
            'utilisateur' => $repositoryUtilisateur->find($idUtilisateur),
            'entreprises' => $this->entrepriseRepository->findAll(),
            'invites' => $this->inviteRepository->findAll(),
        ]);
    }




    #[Route('/broker_registration/{idUtilisateur}/{idEntreprise}', name: 'app_broker_registration')]
    public function brokerRegistration(Request $request, $idUtilisateur, $idEntreprise, UtilisateurJSBRepository $repositoryUtilisateur, EntrepriseRepository $repositoryEntreprise, EntityManagerInterface $manager): Response
    {
        $tittrePage = "Création de l'entreprise";
        /** @var UtilisateurJSB */
        $user = $repositoryUtilisateur->find($idUtilisateur);

        /** @var Entreprise */
        $entreprise = new Entreprise();
        if ($idEntreprise != -1) {
            $entreprise = $repositoryEntreprise->find($idEntreprise);
            $tittrePage = "Modification de " . $entreprise->getNom();
        }
        // dd($entreprise);
        $form = $this->createForm(EntrepriseType::class, $entreprise);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manager->persist($entreprise);
            $manager->flush();
            if ($idEntreprise == -1) {
                $this->addFlash("success", "" . $entreprise->getNom() . " est ajoutée avec succès.");
            } else {
                $this->addFlash("success", "" . $entreprise->getNom() . " est mise à jour avec succès.");
            }
            return $this->redirectToRoute("app_user_dashbord", [
                "idUtilisateur" => $idUtilisateur,
            ]);
        }

        return $this->render('home/broker_registration.html.twig', [
            'pageName' => $tittrePage,
            'utilisateur' => $user,
            'form' => $form,
        ]);
    }




    #[Route('/broker_destruction/{idUtilisateur}/{idEntreprise}', name: 'app_broker_destruction')]
    public function brokerDestruction(Request $request, $idUtilisateur, $idEntreprise, UtilisateurJSBRepository $repositoryUtilisateur, EntrepriseRepository $repositoryEntreprise, EntityManagerInterface $manager): Response
    {
        /** @var UtilisateurJSB */
        $utilisateur = $repositoryUtilisateur->find($idUtilisateur);

        /** @var Entreprise */
        $entreprise = new Entreprise();
        if ($idEntreprise != -1) {
            $entreprise = $repositoryEntreprise->find($idEntreprise);
        }
        $manager->remove($entreprise);
        $manager->flush();

        $this->addFlash("success", "" . $utilisateur->getNom() . " vous venez de supprimer " . $entreprise->getNom());

        return $this->redirectToRoute("app_user_dashbord", [
            "idUtilisateur" => $idUtilisateur,
        ]);
    }




    #[Route('/broker_dashbord/{idUtilisateur}/{idEntreprise}', name: 'app_broker_dashbord')]
    public function brokerDashbord(int $idUtilisateur, int $idEntreprise): Response
    {
        return $this->render('home/broker_dashbord.html.twig', [
            'pageName' => 'Dashbord Entreprise',
        ]);
    }





    #[Route('/contact', name: 'app_contact')]
    public function userEmailContact(Request $request, MailerInterface $mailer): Response
    {
        /** @var ContactDTO */
        $data = new ContactDTO();
        $form = $this->createForm(ContactType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            # C'est ici qu'on va gérer l'envoie de l'email de l'utilisateur
            $email = (new TemplatedEmail())
                ->to('contact@demo.fr')
                ->from($data->email)
                //->cc('cc@example.com')
                //->bcc('bcc@example.com')
                //->replyTo('fabien@example.com')
                ->priority(Email::PRIORITY_HIGH)
                ->subject('Demande de contact')
                // ->text($data->message)
                // ->html('<p>' . $data->message . '</p>');
                ->htmlTemplate("home/mail/message_demande_de_contact.html.twig")
                ->context(["data" => $data]);
            $mailer->send($email);
            $this->addFlash("success", "L'email a bien été envoyé. Nous vous reviendrons au plus vite.");
            return $this->redirectToRoute('app_contact');
        }
        return $this->render('home/contact.html.twig', [
            'pageName' => 'Formulaire de contact',
            'form' => $form,
        ]);
    }
}
