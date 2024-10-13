<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Form\InviteType;
use Symfony\Component\Mime\Email;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/invite", name: 'admin.invite.')]
#[IsGranted('ROLE_USER')]
class InviteController extends AbstractController
{
    public function __construct(
        private MailerInterface $mailer,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
    ) {}

    #[Route(name: 'index')]
    public function index(Request $request)
    {
        $page = $request->query->getInt("page", 1);
        // $invites = $this->inviteRepository->findAll();
        $invites = $this->inviteRepository->paginateInvites($page);
        $entreprises = $this->entrepriseRepository->findAll();

        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render('admin/invite/index.html.twig', [
            'pageName' => "Invités",
            'utilisateur' => $user,
            'invites' => $invites,
            'entreprises' => $entreprises,
            'page' => $page,
        ]);
    }


    #[Route('/create', name: 'create')]
    public function create(Request $request)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite */
        $invite = new Invite();
        $form = $this->createForm(InviteType::class, $invite);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($invite);
            $this->manager->flush();

            //Envoie de l'email de notification
            $this->envoyerEmail($invite, $user);

            $this->addFlash("success", $invite->getEmail() . " a été invité avec succès.");
            return $this->redirectToRoute("admin.invite.index", [
                'idUtilisateur' => $user->getId()
            ]);
        }
        return $this->render('admin/invite/create.html.twig', [
            'pageName' => 'Nouveau',
            'idUtilisateur' => $user->getId(),
            'utilisateur' => $user,
            'invites' => $this->inviteRepository->findAll(),
            'entreprises' => $this->entrepriseRepository->findAll(),
            'form' => $form,
        ]);
    }


    #[Route('/{id}', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Invite $invite, Request $request)
    {
        // dd($invite);
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $form = $this->createForm(InviteType::class, $invite);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($invite); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $invite->getEmail() . " a été modifié avec succès.");
            return $this->redirectToRoute("admin.invite.index");
        }
        return $this->render('admin/invite/edit.html.twig', [
            'pageName' => "Edition",
            'invite' => $invite,
            'entreprises' => $this->entrepriseRepository->findAll(),
            'invites' => $this->inviteRepository->findAll(),
            'idUtilisateur' => $user->getId(),
            'utilisateur' => $user,
            'form' => $form,
        ]);
    }


    #[Route('/{id}', name: 'remove', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove(Invite $invite)
    {
        $this->manager->remove($invite);
        $this->manager->flush();
        $this->addFlash("success", $invite->getEmail() . " a été supprimé avec succès.");
        return $this->redirectToRoute("admin.invite.index");
    }

    private function envoyerEmail(Invite $invite, Utilisateur $utilisateur)
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
