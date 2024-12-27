<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Form\InviteType;
use App\Entity\Utilisateur;
use App\Constantes\Constante;
use App\Event\InvitationEvent;
use App\Constantes\MenuActivator;
use App\Entity\Entreprise;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/invite", name: 'admin.invite.')]
#[IsGranted('ROLE_USER')]
class InviteController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_ADMINISTRATION);
    }

    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/invite/index.html.twig', [
            'pageName' => $this->translator->trans("invite_page_name_list"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'invites' => $this->inviteRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'activator' => $this->activator,
        ]);
    }


    #[Route('/create/{idEntreprise}', name: 'create')]
    public function create($idEntreprise, Request $request, EventDispatcherInterface $dispatcher)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite */
        $invite = new Invite();
        //Paramètres par défaut
        $invite->setEntreprise($entreprise);
        $invite->setProprietaire(false);

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
                    $this->addFlash("success", $this->translator->trans("invite_create_ok", [
                        ':email' => $invite->getEmail()
                    ]));
                } catch (\Throwable $th) {
                    //throw $th;
                    $this->addFlash("danger", $this->translator->trans("invite_email_sending_error"));
                }
                return $this->redirectToRoute("admin.invite.index");
            }
        } else {
            $this->addFlash("danger", $this->translator->trans("invite_sending_invite_not_granted", [
                ':user' => $user->getNom()
            ]));
            return $this->redirectToRoute("admin.invite.index");
        }
        return $this->render('admin/invite/create.html.twig', [
            'pageName' => $this->translator->trans("invite_page_name_new"),
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
            'pageName' => $this->translator->trans("invite_page_name_edit"),
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
        $message = $this->translator->trans("invite_deletion_ok", [
            ':email' => $invite->getEmail()
        ]);
        $this->manager->remove($invite);
        $this->manager->flush();

        // if ($request->getPreferredFormat() == TurboBundle::STREAM_FORMAT) {
        //     $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
        //     return $this->render("admin/invite/delete.html.twig", [
        //         'inviteId' => $inviteId,
        //         'messages' => $message,
        //         'type' => "success",
        //     ]);
        // }
        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.invite.index");
    }
}
