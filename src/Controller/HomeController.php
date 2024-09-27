<?php

namespace App\Controller;

use App\DTO\ConnexionDTO;
use DateTimeImmutable;
use App\DTO\ContactDTO;
use App\Form\ContactType;
use App\Entity\UtilisateurJSB;
use App\Form\ConnexionType;
use App\Form\UtilisateurJSBType;
use Symfony\Component\Mime\Email;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\UtilisateurJSBRepository;
use SebastianBergmann\CodeCoverage\Report\Xml\Report;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use function PHPUnit\Framework\isEmpty;

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
    public function userLogin(Request $request, UtilisateurJSBRepository $repository): Response
    {
        /** @var ConnexionDTO */
        $data = new ConnexionDTO();
        $form = $this->createForm(ConnexionType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $tabResultats = $repository->findBy([
                'email' => $data->email,
                'motDePasse' => $data->motdepasse
            ]);

            // dd($data, $tabResultats);

            if (isset($tabResultats) && count($tabResultats) != 0) {
                /** @var UtilisateurJSB */
                $user = $tabResultats[0];
                if ($user->getEmail() == $data->email && $user->getMotDePasse() == $data->motdepasse) {
                    $this->addFlash("success", "Bienvenue " . $user->getNom());
                    return $this->redirectToRoute('app_user_dashbord', [
                        'idUtilisateur' => $user->getId()
                    ]);
                }
            }
        }
        return $this->render('home/user_login.html.twig', [
            'pageName' => 'Connexion',
            'form' => $form
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
    public function userDashbord($idUtilisateur, UtilisateurJSBRepository $repository): Response
    {
        $utilisateurJSB = $repository->find($idUtilisateur);
        return $this->render('home/user_dashbord.html.twig', [
            'pageName' => 'Dashbord Utilisateur',
            'utilisateur' => $utilisateurJSB,
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
