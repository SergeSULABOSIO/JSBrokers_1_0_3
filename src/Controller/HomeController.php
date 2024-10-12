<?php

namespace App\Controller;

use App\DTO\ContactDTO;
use App\DTO\ConnexionDTO;
use App\Form\ContactType;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Form\ConnexionType;
use App\Form\EntrepriseType;
use App\Entity\UtilisateurJSB;
use App\Form\UtilisateurJSBType;
use Symfony\Component\Mime\Email;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\UtilisateurJSBRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class HomeController extends AbstractController
{

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        // private UtilisateurJSBRepository $utilisateurJSBRepository,
    ) {}


    #[Route('/', name: 'app_index')]
    public function index(EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        //Création d'un utilisateur juste pour test
        // $user = new Utilisateur();
        // $user->setEmail("ssula@aib-brokers.com")
        //     ->setNom("Serge SULA BOSIO de AIB")
        //     ->setPassword($hasher->hashPassword($user, "abcd"))
        //     ->setRoles([]);
        // $em->persist($user);
        // $em->flush();

        $this->addFlash("success", "Bienvenue chez JS Broker!");
        return $this->render('home/index.html.twig', [
            'pageName' => 'Home',
        ]);
    }




    // #[Route('/user_login', name: 'app_user_login')]
    // public function userLogin(Request $request): Response
    // {
    //     /** @var ConnexionDTO $data */
    //     $data = new ConnexionDTO();
    //     $form = $this->createForm(ConnexionType::class, $data);
    //     $form->handleRequest($request);
    //     if ($form->isSubmitted() && $form->isValid()) {
    //         $user = null;
    //         $tabResultats = $this->utilisateurJSBRepository->findBy([
    //             'email' => $data->email,
    //             'motDePasse' => $data->motdepasse
    //         ]);
    //         if (isset($tabResultats) && count($tabResultats) != 0) {
    //             /** @var UtilisateurJSB $user */
    //             $user = $tabResultats[0];
    //             if ($user->getEmail() == $data->email && $user->getMotDePasse() == $data->motdepasse) {
    //                 $this->addFlash("success", "Bienvenue " . $user->getNom());
    //                 return $this->redirectToRoute('app_user_dashbord', [
    //                     'idUtilisateur' => $user->getId(),
    //                     'utilisateur' => $user,
    //                 ]);
    //             }
    //         }
    //         // dd($data, $tabResultats);
    //         // dd($failed);
    //         $this->addFlash("danger", "Identifiants incorrects.");
    //         return $this->redirectToRoute('app_user_login', [
    //             'pageName' => 'Connexion',
    //             'form' => $form
    //         ]);
    //     }
    //     return $this->render('home/user_login.html.twig', [
    //         'pageName' => 'Connexion',
    //         'form' => $form
    //     ]);
    // }




    // #[Route('/user_registration/{idUtilisateur}', name: 'app_user_registration')]
    // public function userRegistration(int $idUtilisateur, Request $request): Response
    // {
    //     $tittrePage = "Création du compte utilisateur";

    //     /** @var UtilisateurJSB */
    //     $utilisateurJSB = new UtilisateurJSB();
    //     if ($idUtilisateur != -1) {
    //         $utilisateurJSB = $this->utilisateurJSBRepository->find($idUtilisateur);
    //         $tittrePage = "Edition du compte " . $utilisateurJSB->getNom();
    //     }
    //     $form = $this->createForm(UtilisateurJSBType::class, $utilisateurJSB);
    //     $form->handleRequest($request);
    //     if ($form->isSubmitted() && $form->isValid()) {
    //         $this->manager->persist($utilisateurJSB);
    //         $this->manager->flush();

    //         if ($idUtilisateur != -1) {
    //             $this->addFlash("success", "" . $utilisateurJSB->getNom() . ", votre profil est mis à jour avec succès.");
    //             return $this->redirectToRoute("app_user_dashbord", [
    //                 'idUtilisateur' => $utilisateurJSB->getId()
    //             ]);
    //         } else {
    //             $this->addFlash("success", "" . $utilisateurJSB->getNom() . ", votre compte vient d'être créée.");
    //             return $this->redirectToRoute("app_user_login");
    //         }
    //     }
    //     return $this->render('home/user_registration.html.twig', [
    //         'pageName' => $tittrePage,
    //         'form' => $form,
    //     ]);
    // }




    #[Route('/user_dashbord', name: 'app_user_dashbord')]
    public function userDashbord(): Response
    {
        $this->denyAccessUnlessGranted("ROLE_USER");

        /** @var Utilisateur $user */
        $user = $this->getUser();

        
        if ($user->isVerified()) {
            // dd($user);
            // dd($listeEntreprises);
            return $this->render('home/user_dashbord.html.twig', [
                'pageName' => "Entreprises",
                'utilisateur' => $user,
                'entreprises' => $this->entrepriseRepository->findAll(),
                'invites' => $this->inviteRepository->findAll(),
            ]);
        } else {
            $this->addFlash("warning", "" . $user->getNom() . ", votre adresse mail n'est pas encore vérifiée. Veuillez cliquer sur le lien de vérification qui vous a été envoyé par JS Brokers à votre adresse " . $user->getEmail().".");
            return new RedirectResponse($this->urlGenerator->generate("app_login"));
        }
    }




    #[Route('/broker_registration/{idEntreprise}', name: 'app_broker_registration')]
    public function brokerRegistration(Request $request, $idUtilisateur, $idEntreprise): Response
    {
        $this->denyAccessUnlessGranted("ROLE_USER");

        $tittrePage = "Nouveau";
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Entreprise $entreprise */
        $entreprise = new Entreprise();
        if ($idEntreprise != -1) {
            $entreprise = $this->entrepriseRepository->find($idEntreprise);
            $tittrePage = "Modification de " . $entreprise->getNom();
        }
        // dd($entreprise);
        $form = $this->createForm(EntrepriseType::class, $entreprise);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($entreprise);

            // if ($form->get('thumbnailFile')->getData()) {
            //     /** @var UploadedFile $photoProfile */
            //     $photoProfile = $form->get('thumbnailFile')->getData();
            //     $fileName = $entreprise->getId() . "." . $photoProfile->getClientOriginalExtension();
            //     $photoProfile->move($this->getParameter("kernel.project_dir") . "/public/profile_entreprise", $fileName);
            //     $entreprise->setThumbnail($fileName);
            // }

            $this->manager->flush();
            if ($idEntreprise == -1) {
                $this->addFlash("success", "" . $entreprise->getNom() . " est ajoutée avec succès.");
            } else {
                $this->addFlash("success", "" . $entreprise->getNom() . " est mise à jour avec succès.");
            }
            return $this->redirectToRoute("app_user_dashbord");
        }

        return $this->render('home/broker_registration.html.twig', [
            'pageName' => $tittrePage,
            'utilisateur' => $user,
            'invites' => $this->inviteRepository->findAll(),
            'entreprise' => $entreprise,
            'entreprises' => $this->entrepriseRepository->findAll(),
            'form' => $form,
        ]);
    }




    #[Route('/broker_destruction/{idEntreprise}', name: 'app_broker_destruction')]
    public function brokerDestruction($idUtilisateur, $idEntreprise): Response
    {
        $this->denyAccessUnlessGranted("ROLE_USER");

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        /** @var Entreprise $entreprise */
        $entreprise = new Entreprise();
        if ($idEntreprise != -1) {
            $entreprise = $this->entrepriseRepository->find($idEntreprise);
        }
        $this->manager->remove($entreprise);
        $this->manager->flush();

        $this->addFlash("success", "" . $utilisateur->getNom() . " vous venez de supprimer " . $entreprise->getNom());

        return $this->redirectToRoute("app_user_dashbord");
    }




    #[Route('/broker_dashbord/{idUtilisateur}/{idEntreprise}', name: 'app_broker_dashbord')]
    public function brokerDashbord(int $idUtilisateur, int $idEntreprise): Response
    {
        $this->denyAccessUnlessGranted("ROLE_USER");

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
