<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Form\InviteType;
use App\Entity\Utilisateur;
use App\Event\InvitationEvent;
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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\Turbo\TurboBundle;

#[Route("/admin/entreprise", name: 'admin.entreprise.')]
#[IsGranted('ROLE_USER')]
class EntrepriseMonnaieController extends AbstractController
{
    public function __construct(
        private MailerInterface $mailer,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
    ) {}

    #[Route('/{id}', name: 'monnaie', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Entreprise $entreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render('admin/monnaie/index.html.twig', [
            'pageName' => "Monnaies",
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            // 'monnaies' => $this->entrepriseRepository->paginateEntreprises($page),
            'page' => $page = $request->query->getInt("page", 1),
        ]);
    }


    #[Route('/create', name: 'create')]
    public function create(Request $request, EventDispatcherInterface $dispatcher)
    {
        // dd($this->entrepriseRepository->getNBMyProperEntreprises());
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite */
        $invite = new Invite();
        $form = $this->createForm(InviteType::class, $invite);
        $form->handleRequest($request);

        if ($this->entrepriseRepository->getNBMyProperEntreprises() != 0) {
            if ($form->isSubmitted() && $form->isValid()) {
                $this->manager->persist($invite);
                $this->manager->flush();
                try {
                    //Envoie de l'email de notification
                    //Lancer un évènement
                    $dispatcher->dispatch(new InvitationEvent($invite));
                    $this->addFlash("success", $invite->getEmail() . " a été invité avec succès.");
                } catch (\Throwable $th) {
                    //throw $th;
                    $this->addFlash("danger", "Echec d'envoie de l'email d'invitation.");
                }
                return $this->redirectToRoute("admin.invite.index");
            }
        } else {
            $this->addFlash("danger", "Désolé " . $user->getNom() . ", vous n'avez pas le droit d'inviter des utilisateurs. Vous ne pouvez inviter d'utilisateurs que pour une ou des entreprises que vous avez créées vous-mêmes, non pas pour une ou des entreprises où vous êtes invités.");
            return $this->redirectToRoute("admin.invite.index");
        }
        return $this->render('admin/invite/create.html.twig', [
            'pageName' => 'Nouveau',
            'utilisateur' => $user,
            'nbEntreprises' => $this->entrepriseRepository->getNBEntreprises(),
            'nbInvites' => $this->inviteRepository->getNBInvites(),
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
            'utilisateur' => $user,
            'invite' => $invite,
            'nbEntreprises' => $this->entrepriseRepository->getNBEntreprises(),
            'nbInvites' => $this->inviteRepository->getNBInvites(),
            'form' => $form,
        ]);
    }


    #[Route('/{id}', name: 'remove', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove(Request $request, Invite $invite)
    {
        $inviteId = $invite->getId();
        $message = $invite->getEmail() . " a été supprimé avec succès.";
        $this->manager->remove($invite);
        $this->manager->flush();

        if($request->getPreferredFormat() == TurboBundle::STREAM_FORMAT){
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
            return $this->render("admin/invite/delete.html.twig", [
                'inviteId' => $inviteId,
                'messages' => $message,
                'type' => "success",
            ]);
        }
        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.invite.index");
    }
}