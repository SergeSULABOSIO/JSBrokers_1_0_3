<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Crm\CrmPipelineService;
use App\Crm\CrmRecommendationService;
use App\Crm\CrmSyncService;
use App\Entity\Crm\CrmInteraction;
use App\Entity\Crm\CrmTache;
use App\Entity\Utilisateur;
use App\Repository\Crm\CrmHealthSnapshotRepository;
use App\Repository\Crm\CrmInteractionRepository;
use App\Repository\Crm\CrmTacheRepository;
use App\Repository\Crm\CrmTicketRepository;
use App\Repository\InviteRepository;
use App\Repository\TokenConsumptionRepository;
use App\Repository\TokenPurchaseRepository;
use App\Repository\UtilisateurRepository;
use App\Services\ServiceGeographie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Fiche client CRM (centrée sur l'utilisateur propriétaire = le client payant).
 * Liste + vue 360° à onglets. Toutes les données proviennent du SaaS et sont
 * synchronisées automatiquement (CrmSyncService) : le commercial ne ressaisit rien.
 */
#[Route('/console/crm/clients', name: 'console.crm.client.')]
#[IsGranted('ROLE_ADMIN')]
class CrmClientController extends AbstractConsoleController
{
    public function __construct(
        private UtilisateurRepository $utilisateurRepository,
        private CrmSyncService $crmSync,
        private TokenPurchaseRepository $purchaseRepository,
        private TokenConsumptionRepository $consumptionRepository,
        private InviteRepository $inviteRepository,
        private CrmInteractionRepository $interactionRepository,
        private CrmTacheRepository $tacheRepository,
        private CrmTicketRepository $ticketRepository,
        private CrmHealthSnapshotRepository $snapshotRepository,
        private CrmRecommendationService $recommendations,
        private CrmPipelineService $pipeline,
        private ServiceGeographie $geographie,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $q = trim((string) $request->query->get('q', ''));
        $pagination = $this->utilisateurRepository->paginateCrm($request->query->getInt('page', 1), $q ?: null);

        // Synchronise les profils de la page affichée (création + score + étape).
        $profils = $this->crmSync->refreshMany($pagination->getItems());

        return $this->render('console/crm/client/index.html.twig', [
            'pageName' => 'CRM — Clients',
            'pageIcon' => 'client',
            'clients'  => $pagination,
            'profils'  => $profils,
            'q'        => $q,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => Requirement::DIGITS])]
    public function show(Utilisateur $client, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $sync = $this->crmSync->refresh($client);
        $uid = (int) $client->getId();

        // Pays/ville/monnaie dérivés de l'entreprise principale (1ʳᵉ possédée).
        $entreprises = $client->getEntreprises();
        $principale = $entreprises->first() ?: null;
        $pays = $principale && $principale->getPays() ? $this->geographie->getNomPays($principale->getPays()) : null;

        return $this->render('console/crm/client/show.html.twig', [
            'pageName'      => $client->getNom() ?: $client->getEmail(),
            'pageIcon'      => 'client',
            'client'        => $client,
            'profil'        => $sync['profil'],
            'signals'       => $sync['signals'],
            'health'        => $sync['health'],
            'pays'          => $pays,
            'entreprises'   => $entreprises,
            'invites'       => $this->inviteRepository->findGuestsForOwner($uid),
            'achats'        => $this->purchaseRepository->findForUser($uid),
            'consommations' => $this->consumptionRepository->paginateForProprietaire($uid, 1, 15),
            'interactions'  => $this->interactionRepository->findForClient($client),
            'taches'        => $this->tacheRepository->findForClient($client),
            'tickets'       => $this->ticketRepository->findForClient($client),
            'snapshots'     => $this->snapshotRepository->trendForClient($client, 30),
            'reco'          => $this->recommendations->forClient($sync['profil'], $sync['signals']),
            'stages'        => $this->pipeline->orderedStages(),
        ]);
    }

    /** Enregistre une interaction commerciale (appel, e-mail, démo, réunion, note). */
    #[Route('/{id}/interaction', name: 'interaction', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function interaction(Utilisateur $client, Request $request): Response
    {
        $this->guard('crm-interaction-' . $client->getId(), $request);

        $type = (string) $request->request->get('type', CrmInteraction::TYPE_NOTE);
        $sujet = trim((string) $request->request->get('sujet', ''));

        if ($sujet !== '') {
            $interaction = (new CrmInteraction())
                ->setClient($client)
                ->setAgent($this->getUser() instanceof Utilisateur ? $this->getUser() : null)
                ->setType(isset(CrmInteraction::TYPES[$type]) ? $type : CrmInteraction::TYPE_NOTE)
                ->setSujet($sujet)
                ->setContenu(trim((string) $request->request->get('contenu', '')) ?: null)
                ->setDirection((string) $request->request->get('direction', 'out'));
            $this->em->persist($interaction);

            // Le contact met à jour la date de dernier contact du profil.
            $profil = $this->crmSync->getOrCreateProfil($client);
            $profil->setDernierContactAt($interaction->getOccurredAt() ?? new \DateTimeImmutable());

            $this->em->flush();
            $this->addFlash('success', 'Interaction enregistrée.');
        }

        return $this->back($client, 'activites');
    }

    /** Crée une tâche interne rattachée au client. */
    #[Route('/{id}/tache', name: 'tache', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function tache(Utilisateur $client, Request $request): Response
    {
        $this->guard('crm-tache-' . $client->getId(), $request);

        $titre = trim((string) $request->request->get('titre', ''));
        if ($titre !== '') {
            $dueRaw = (string) $request->request->get('dueAt', '');
            $tache = (new CrmTache())
                ->setClient($client)
                ->setAssigneA($this->getUser() instanceof Utilisateur ? $this->getUser() : null)
                ->setTitre($titre)
                ->setDescription(trim((string) $request->request->get('description', '')) ?: null)
                ->setPriorite((string) $request->request->get('priorite', CrmTache::PRIORITE_NORMALE))
                ->setDueAt($dueRaw !== '' ? new \DateTimeImmutable($dueRaw) : new \DateTimeImmutable('+3 days'));
            $this->em->persist($tache);
            $this->em->flush();
            $this->addFlash('success', 'Tâche créée.');
        }

        return $this->back($client, 'taches');
    }

    /** Force manuellement l'étape du pipeline (override commercial). */
    #[Route('/{id}/etape', name: 'etape', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function etape(Utilisateur $client, Request $request): Response
    {
        $this->guard('crm-etape-' . $client->getId(), $request);

        $stage = (string) $request->request->get('etape', '');
        if ($this->pipeline->isValidStage($stage)) {
            $profil = $this->crmSync->getOrCreateProfil($client);
            $this->pipeline->forceStage($profil, $stage);
            $this->em->flush();
            $this->addFlash('success', 'Étape du pipeline mise à jour.');
        }

        return $this->back($client, 'pipeline');
    }

    /** Met à jour les champs commerciaux libres du profil (notes, prochaine action). */
    #[Route('/{id}/profil', name: 'profil', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function profil(Utilisateur $client, Request $request): Response
    {
        $this->guard('crm-profil-' . $client->getId(), $request);

        $profil = $this->crmSync->getOrCreateProfil($client);
        $profil->setNotes(trim((string) $request->request->get('notes', '')) ?: null);

        $prochaine = (string) $request->request->get('prochaineActionAt', '');
        $profil->setProchaineActionAt($prochaine !== '' ? new \DateTimeImmutable($prochaine) : null);

        $this->em->flush();
        $this->addFlash('success', 'Profil mis à jour.');

        return $this->back($client, 'notes');
    }

    private function guard(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function back(Utilisateur $client, string $tab): Response
    {
        return $this->redirectToRoute('console.crm.client.show', ['id' => $client->getId(), '_fragment' => 'tab-' . $tab]);
    }
}
