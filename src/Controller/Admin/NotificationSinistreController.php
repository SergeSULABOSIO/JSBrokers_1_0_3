<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\Assureur;
use App\Entity\Entreprise;
use App\Entity\Partenaire;
use App\Form\AssureurType;
use App\Form\PartenaireType;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\NotificationSinistre;
use App\Entity\Utilisateur;
use App\Form\NotificationSinistreType;
use App\Repository\InviteRepository;
use App\Repository\AssureurRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\PartenaireRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\NotificationSinistreRepository;
use DateTimeImmutable;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/notificationsinistre", name: 'admin.notificationsinistre.')]
#[IsGranted('ROLE_USER')]
class NotificationSinistreController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private NotificationSinistreRepository $notificationSinistreRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_CLAIMS);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        return $this->render('admin/notificationsinistre/index.html.twig', [
            'pageName' => $this->translator->trans("notificationsinistre_page_name_new"),
            'utilisateur' => $utilisateur,
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'notificationsinistres' => $this->notificationSinistreRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'activator' => $this->activator,
        ]);
    }


    #[Route('/create/{idEntreprise}', name: 'create')]
    public function create($idEntreprise, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();
        
        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var NotificationSinistre $notificationsinistre */
        $notificationsinistre = new NotificationSinistre();
        //Paramètres par défaut
        $notificationsinistre->setOccuredAt(new DateTimeImmutable("now"));
        $notificationsinistre->setInvite($invite);

        $form = $this->createForm(NotificationSinistreType::class, $notificationsinistre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($notificationsinistre);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("notificationsinistre_creation_ok", [
                ":notificationsinistre" => $notificationsinistre->getDescriptionDeFait(),
            ]));
            return $this->redirectToRoute("admin.notificationsinistre.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/notificationsinistre/create.html.twig', [
            'pageName' => $this->translator->trans("notificationsinistre_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idNotificationsinistre}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idNotificationsinistre, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var NotificationSinistre $notificationsinistre */
        $notificationsinistre = $this->notificationSinistreRepository->find($idNotificationsinistre);

        $form = $this->createForm(NotificationSinistreType::class, $notificationsinistre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($notificationsinistre); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("notificationsinistre_edition_ok", [
                ":notificationsinistre" => $notificationsinistre->getDescriptionDeFait(),
            ]));
            return $this->redirectToRoute("admin.notificationsinistre.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/notificationsinistre/edit.html.twig', [
            'pageName' => $this->translator->trans("notificationsinistre_page_name_update", [
                ":notificationsinistre" => $notificationsinistre->getDescriptionDeFait(),
            ]),
            'utilisateur' => $user,
            'notificationsinistre' => $notificationsinistre,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idNotificationsinistre}', name: 'remove', requirements: ['idNotificationsinistre' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idNotificationsinistre, Request $request)
    {
        /** @var NotificationSinistre $notificationsinistre */
        $notificationsinistre = $this->notificationSinistreRepository->find($idNotificationsinistre);

        $message = $this->translator->trans("notificationsinistre_deletion_ok", [
            ":notificationsinistre" => $notificationsinistre->getDescriptionDeFait(),
        ]);
        
        $this->manager->remove($notificationsinistre);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.notificationsinistre.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
