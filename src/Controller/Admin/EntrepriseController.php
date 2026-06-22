<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Event\AgentNotificationEvent;
use App\Form\EntrepriseType;
use App\Services\ServiceGeographie;
use App\Services\ServiceInitialisationEntreprise;
use App\Services\ServiceSuppressionEntreprise;
use App\Repository\InviteRepository;
use App\Message\EntreprisePDFMessage;
use App\Repository\EntrepriseRepository;
use App\Token\InsufficientTokensException;
use App\Token\ParametresTokenService;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/entreprise", name: 'admin.entreprise.')]
#[IsGranted('ROLE_USER')]
class EntrepriseController extends AbstractController
{
    public function __construct(
        private TranslatorInterface $translator,
        private MailerInterface $mailer,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ServiceInitialisationEntreprise $serviceInitialisation,
        private ServiceSuppressionEntreprise $serviceSuppression,
        private UserPasswordHasherInterface $passwordHasher,
        private TokenAccountService $tokenAccountService,
        private ParametresTokenService $parametres,
        private \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher,
    ) {}

    /**
     * Garde-fou d'autorisation : seul le propriétaire d'une entreprise peut la gérer
     * (éditer, supprimer, générer son PDF).
     *
     * La liste (`paginateUtilisateur`) ne retourne que les entreprises possédées par
     * l'utilisateur ; sans ce contrôle, n'importe quel utilisateur authentifié pouvait
     * agir sur l'entreprise d'autrui en devinant son id (IDOR / contrôle d'accès rompu).
     */
    private function denyUnlessOwner(Entreprise $entreprise): void
    {
        if ($entreprise->getUtilisateur() !== $this->getUser()) {
            throw $this->createAccessDeniedException("Vous n'êtes pas autorisé à gérer cette entreprise.");
        }
    }

    #[Route(name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $this->applyLangPreference($request, $user, $localeSwitcher);

        $page = $request->query->getInt("page", 1);

        // NB : la vérification de l'e-mail est désormais imposée globalement par
        // App\EventSubscriber\EmailVerificationSubscriber. Un utilisateur non vérifié
        // n'atteint jamais cette action — inutile de re-tester isVerified() ici.
        return $this->render('admin/entreprise/index.html.twig', [
            'pageName' => $this->translator->trans("entreprise_page_name_list"),
            'utilisateur' => $user,
            'tokenBalance' => $this->tokenAccountService->getBalance($user),
            'entreprises' => $this->entrepriseRepository->paginateUtilisateur($user->getId(), $page),
            'page' => $request->query->getInt("page", 1),
            // NOUVEAU : On passe l'invité courant pour faciliter l'accès à ses informations
            'invite' => $this->inviteRepository->findOneBy(['utilisateur' => $user]),
            'nbEntreprises' => $this->entrepriseRepository->getNBEntreprises(),
            // 'nbInvites' => $this->inviteRepository->getNBInvites(),
        ]);
    }


    #[Route('/create', name: 'create')]
    public function create(Request $request, LocaleSwitcher $localeSwitcher)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $this->applyLangPreference($request, $user, $localeSwitcher);

        /** @var Entreprise $entreprise */
        $entreprise = new Entreprise();
        // dd("Ici");
        $form = $this->createForm(EntrepriseType::class, $entreprise);
        $form->handleRequest($request); 
        if ($form->isSubmitted() && $form->isValid()) {
            // MÉTRAGE TOKENS : créer une entreprise coûte 200 tokens, débités au
            // créateur (qui en devient le propriétaire). On vérifie la solvabilité
            // AVANT toute persistance pour ne pas laisser d'entreprise à moitié créée.
            if (!$this->tokenAccountService->canAfford($user, $this->parametres->weightFor(Entreprise::class))) {
                $this->addFlash("error", $this->translator->trans("token_blocked_flash"));

                return $this->redirectToRoute("admin.token.index");
            }

            // L'AuditableTrait va maintenant lier l'entreprise au créateur (Invite)
            // Mais pour le tout premier 'Invite' (le propriétaire), nous devons le créer manuellement.

            // On persiste l'entreprise d'abord pour qu'elle ait un ID.
            $this->manager->persist($entreprise);
            $this->manager->flush(); // Flush pour obtenir l'ID de l'entreprise

            // Lier l'utilisateur créateur
            $entreprise->setUtilisateur($user);

            // Débit effectif + journalisation (la solvabilité a été vérifiée ci-dessus).
            $this->tokenAccountService->meterWrite($entreprise, $entreprise, $user);

            //On cree aussi l'invité proprietaire de l'entreprise
            /** @var Invite $proprietaire */
            $proprietaire = new Invite();
            $proprietaire->setNom("Administrateur");
            $proprietaire->setUtilisateur($user); // On lie directement l'objet Utilisateur
            $proprietaire->setEntreprise($entreprise);
            $proprietaire->setProprietaire(true);

            $this->manager->persist($proprietaire);

            // L'AuditableTrait sur Entreprise a besoin de l'invité créateur.
            // $entreprise->setInvite($proprietaire); // Plus nécessaire

            // Initialisation des paramètres par défaut (monnaies, taxes, chargements,
            // types de revenu, risques) pour que l'espace de travail soit exploitable
            // dès la création. Aucun flush interne : un seul flush ci-dessous.
            $this->serviceInitialisation->initialiser($entreprise, $proprietaire);

            $this->manager->flush();

            $this->addFlash("success", $this->translator->trans("entreprise_created_ok", [
                ':company' => $entreprise->getNom(),
            ]));

            $this->dispatcher->dispatch(new AgentNotificationEvent(
                AgentNotificationEvent::ACTION_CREATE,
                AgentNotificationEvent::TYPE_ENTREPRISE,
                $entreprise->getNom(),
                ['Entreprise' => $entreprise->getNom(), 'Propriétaire' => $user->getNom() ?: (string) $user->getEmail()],
            ));

            return $this->redirectToRoute("admin.entreprise.index");
        } elseif ($form->isSubmitted()) {
            $this->addFlash("error", $this->translator->trans("entreprise_form_error"));
        }
        return $this->render('admin/entreprise/create.html.twig', [
            'pageName' => $this->translator->trans("entreprise_page_name_new"),
            'utilisateur' => $user,
            'nbEntreprises' => $this->entrepriseRepository->getNBEntreprises(),
            'nbInvites' => $this->inviteRepository->getNBInvites(),
            'form' => $form,
        ]);
    }


    /**
     * Renvoie, pour un code pays (ISO 3166-1 numérique), la liste de ses grandes
     * villes et son code monnaie. Consommé par le contrôleur Stimulus
     * `pays-dependances` pour remplir dynamiquement le champ « Ville » et
     * mettre à jour la devise affichée du « Capital social ».
     */
    #[Route('/api/villes/{codePays}', name: 'api.villes', requirements: ['codePays' => Requirement::DIGITS], methods: ['GET'])]
    public function apiVilles(int $codePays, ServiceGeographie $serviceGeographie): JsonResponse
    {
        return $this->json([
            'villes' => $serviceGeographie->getVilles($codePays),
            'monnaie' => $serviceGeographie->getMonnaie($codePays),
        ]);
    }

    #[Route('/{id}', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Entreprise $entreprise, Request $request, LocaleSwitcher $localeSwitcher)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $this->applyLangPreference($request, $user, $localeSwitcher);
        $this->denyUnlessOwner($entreprise);

        $form = $this->createForm(EntrepriseType::class, $entreprise);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // MÉTRAGE TOKENS (écriture) : éditer l'entreprise coûte aussi 200 tokens.
            try {
                $this->tokenAccountService->meterWrite($entreprise, $entreprise, $user);
            } catch (InsufficientTokensException) {
                $this->addFlash("error", $this->translator->trans("token_blocked_flash"));

                return $this->redirectToRoute("admin.token.index");
            }
            $this->manager->persist($entreprise); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("entreprise_edited_ok", [
                ':company' => $entreprise->getNom(),
            ]));

            $this->dispatcher->dispatch(new AgentNotificationEvent(
                AgentNotificationEvent::ACTION_UPDATE,
                AgentNotificationEvent::TYPE_ENTREPRISE,
                $entreprise->getNom(),
                ['Entreprise' => $entreprise->getNom(), 'Propriétaire' => $user->getNom() ?: (string) $user->getEmail()],
            ));
            // return $this->redirectToRoute("admin.entreprise.index");
            //Après modification, il faut revenir sur la page d'edition
        } elseif ($form->isSubmitted()) {
            $this->addFlash("error", $this->translator->trans("entreprise_form_error"));
        }
        return $this->render('admin/entreprise/edit.html.twig', [
            'pageName' => $this->translator->trans("entreprise_page_name_edition", [
                ':company' => $entreprise->getNom(),
            ]),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'nbEntreprises' => $this->entrepriseRepository->getNBEntreprises(),
            'nbInvites' => $this->inviteRepository->getNBInvites(),
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'remove', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove(Entreprise $entreprise, Request $request)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // Protection CSRF : le formulaire de suppression fournit un jeton dédié à cet id.
        if (!$this->isCsrfTokenValid('delete-entreprise-' . $entreprise->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->denyUnlessOwner($entreprise);

        // Confirmation forte : la suppression étant TOTALE et irréversible (toutes les
        // données opérationnelles + les fichiers du serveur sont détruits), on exige le
        // mot de passe du propriétaire saisi dans la boîte de dialogue de confirmation.
        $motDePasse = (string) $request->request->get('password', '');
        if ($motDePasse === '' || !$this->passwordHasher->isPasswordValid($user, $motDePasse)) {
            $this->addFlash("error", $this->translator->trans("entreprise_delete_bad_password"));

            return $this->redirectToRoute("admin.entreprise.index");
        }

        // Le nom est lu avant la purge : l'entité ne sera plus exploitable ensuite.
        $nom = $entreprise->getNom();

        // Destruction inconditionnelle : données scopées, fichiers uploadés, entreprise.
        $this->serviceSuppression->supprimer($entreprise);

        $this->dispatcher->dispatch(new AgentNotificationEvent(
            AgentNotificationEvent::ACTION_DELETE,
            AgentNotificationEvent::TYPE_ENTREPRISE,
            $nom,
            ['Entreprise' => $nom, 'Supprimée par' => $user->getNom() ?: (string) $user->getEmail()],
        ));

        $this->addFlash("success", $this->translator->trans("entreprise_deleted_ok", [
            ':company' => $nom,
        ]));

        return $this->redirectToRoute("admin.entreprise.index");
    }

    #[Route('/pdf/{id}', name: 'pdf', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function pdf(Entreprise $entreprise, MessageBusInterface $messageBus)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $this->denyUnlessOwner($entreprise);

        $messageBus->dispatch(new EntreprisePDFMessage($entreprise->getId()));

        // La génération est asynchrone (file d'attente Messenger) : on annonce une mise en
        // file, pas une création déjà aboutie, pour ne pas tromper l'utilisateur.
        $this->addFlash("info", $this->translator->trans("entreprise_pdf_queued_ok", [
            ':user' => $user->getNom(),
            ':company' => $entreprise->getNom(),
        ]));
        
        // return $this->redirectToRoute("admin.entreprise.index");
        return $this->render('admin/entreprise/pdf/index.html.twig', [
            'pageName' => $this->translator->trans("entreprise_page_name_pdf"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
        ]);
    }

    #[Route('/geticon/{inaction}/{nom}/{taille}', name: 'geticon', requirements: ['inaction' => Requirement::CATCH_ALL, 'nom' => Requirement::CATCH_ALL, 'taille' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function getIcone(bool $inaction, $nom, $taille)
    {
        // dd($inaction, $nom, $taille);
        $lien = "";
        if ($inaction == 1) {
            $lien = "segments/icones/actions/" . $nom . ".html.twig";
        }else{
            $lien = "segments/icones/" . $nom . ".html.twig";
        }
        return $this->render($lien, [
            'size' => $taille . 'px',
        ]);
    }

    /**
     * Bascule de langue persistante pour l'espace authentifié : ?lang= met à
     * jour la préférence de l'utilisateur (le UserLocaleListener s'appuie
     * ensuite sur getLocale()) et applique la locale au rendu courant.
     * Même logique que App\Controller\Admin\TokenController::applyLangPreference.
     */
    private function applyLangPreference(Request $request, Utilisateur $user, LocaleSwitcher $localeSwitcher): void
    {
        $lang = $request->query->get('lang');
        if (in_array($lang, ['fr', 'en'], true) && $lang !== $user->getLocale()) {
            $user->setLocale($lang);
            $this->manager->flush();
            $localeSwitcher->setLocale($lang);
        }
    }
}
