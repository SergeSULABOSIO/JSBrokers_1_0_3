<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Form\InviteType;
use App\Entity\UtilisateurJSB;
use Symfony\Component\Mime\Email;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\UtilisateurJSBRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/{idUtilisateur}/invite", name: 'admin.invite.')]
#[IsGranted('ROLE_USER')]
class InviteController extends AbstractController
{

    public function __construct(
        private MailerInterface $mailer,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private UtilisateurJSBRepository $utilisateurJSBRepository,
    ) {}

    #[Route(name: 'index')]
    public function index($idUtilisateur, InviteRepository $inviteRepository)
    {
        // $this->denyAccessUnlessGranted("ROLE_USER");
        $invites = $this->inviteRepository->findAll();
        // dd($invites[0]->getEntreprises());


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

            //Envoie de l'email de notification
            $this->envoyerEmail($invite, $utilisateur);

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
        // dd($invite);
        $utilisateur = $this->utilisateurJSBRepository->find($idUtilisateur);
        $form = $this->createForm(InviteType::class, $invite);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($invite); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $invite->getEmail() . " a été modifié avec succès.");
            return $this->redirectToRoute("admin.invite.index", [
                'idUtilisateur' => $utilisateur->getId(),
            ]);
        }
        return $this->render('admin/invite/edit.html.twig', [
            'pageName' => "Edition",
            'invite' => $invite,
            'entreprises' => $this->entrepriseRepository->findAll(),
            'invites' => $this->inviteRepository->findAll(),
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

    private function envoyerEmail(Invite $invite, UtilisateurJSB $utilisateur)
    {
        # C'est ici qu'on va gérer l'envoie de l'email de l'utilisateur
        $email = (new TemplatedEmail())
            ->to($invite->getEmail())
            ->from("info@jsbrokers.com")
            //->cc('cc@example.com')
            //->bcc('bcc@example.com')
            //->replyTo('fabien@example.com')
            ->priority(Email::PRIORITY_HIGH)
            ->subject('Invitation JS Brokers venant de ' . $utilisateur->getNom())
            // ->text($data->message)
            // ->html('<p>' . $data->message . '</p>');
            ->htmlTemplate("home/mail/message_invitation.html.twig")
            ->context([
                "invite" => $invite,
                "utilisateur" => $utilisateur,
            ]);
        $this->mailer->send($email);
    }
}
