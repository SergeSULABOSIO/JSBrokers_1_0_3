<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Entity\Crm\CrmTicket;
use App\Entity\Crm\CrmTicketFeedback;
use App\Entity\Utilisateur;
use App\Form\CrmTicketFeedbackType;
use App\Form\CrmTicketType;
use App\Repository\Crm\CrmTicketRepository;
use App\Repository\UtilisateurRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Support : tickets clients (réception, statut, SLA). La création est possible
 * depuis cette rubrique ou depuis la fiche client (onglet Support).
 */
#[Route('/console/crm/tickets', name: 'console.crm.ticket.')]
#[IsGranted('ROLE_ADMIN')]
class CrmTicketController extends AbstractConsoleController
{
    public function __construct(
        private CrmTicketRepository $ticketRepository,
        private UtilisateurRepository $utilisateurRepository,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $statut = $request->query->get('statut') ?: null;

        return $this->render('console/crm/ticket/index.html.twig', [
            'pageName' => 'CRM — Support',
            'pageIcon' => 'feedback',
            'tickets'  => $this->ticketRepository->paginateFiltered($statut, $request->query->getInt('page', 1)),
            'statut'   => $statut,
            'compteurs' => $this->ticketRepository->countByStatut(),
        ]);
    }

    /** Création d'un ticket (client présélectionnable via ?client=). */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $ticket = new CrmTicket();
        // Préselection du client depuis la fiche (?client=ID).
        if ($preId = $request->query->getInt('client')) {
            $client = $this->utilisateurRepository->find($preId);
            if ($client instanceof Utilisateur) {
                $ticket->setClient($client);
            }
        }

        $form = $this->createForm(CrmTicketType::class, $ticket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ticket->setAgent($this->getUser() instanceof Utilisateur ? $this->getUser() : null);
            $this->em->persist($ticket);
            $this->em->flush();
            $this->addFlash('success', 'Ticket créé (' . $ticket->getReference() . ').');

            return $this->redirectToRoute('console.crm.client.show', ['id' => $ticket->getClient()->getId(), '_fragment' => 'tab-support']);
        }

        return $this->render('console/crm/ticket/form.html.twig', [
            'pageName'    => 'Nouveau ticket',
            'formIcon'    => 'feedback',
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.crm.ticket.index'),
            'backLabel'   => 'Support',
            'submitLabel' => 'Créer le ticket',
            'description' => 'Ouvrez une demande de support rattachée à un client. Le délai SLA est calculé selon la priorité.',
        ]);
    }

    /** Fiche d'un ticket : détails, statut et fil des feedbacks (notes internes). */
    #[Route('/{id}', name: 'show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(CrmTicket $ticket, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/crm/ticket/show.html.twig', [
            'pageName' => 'Ticket ' . $ticket->getReference(),
            'pageIcon' => 'feedback',
            'ticket'   => $ticket,
        ]);
    }

    /** Ajoute un feedback (note interne) à un ticket non clos. */
    #[Route('/{id}/feedbacks/new', name: 'feedback_new', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function feedbackNew(CrmTicket $ticket, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        if ($guard = $this->refuserSiClos($ticket)) {
            return $guard;
        }

        $feedback = (new CrmTicketFeedback())->setTicket($ticket);
        $feedback->setAuteur($this->getUser() instanceof Utilisateur ? $this->getUser() : null);

        return $this->traiterFeedback($feedback, $request, 'Nouveau feedback', 'Ajouter le feedback', true);
    }

    /** Édite un feedback existant (tant que le ticket n'est pas clos). */
    #[Route('/feedbacks/{id}/edit', name: 'feedback_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function feedbackEdit(CrmTicketFeedback $feedback, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        if ($guard = $this->refuserSiClos($feedback->getTicket())) {
            return $guard;
        }

        // L'auteur d'origine reste tracé : on ne le réécrit pas à l'édition.
        return $this->traiterFeedback($feedback, $request, 'Modifier le feedback', 'Enregistrer les modifications', false);
    }

    /**
     * Pattern Console partagé (façon Coupon::traiter) pour la création ET l'édition
     * d'un feedback : même type, même shell de formulaire, retour sur la fiche ticket.
     */
    private function traiterFeedback(CrmTicketFeedback $feedback, Request $request, string $pageName, string $submitLabel, bool $isNew): Response
    {
        $ticket = $feedback->getTicket();
        $form = $this->createForm(CrmTicketFeedbackType::class, $feedback);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->em->persist($feedback);
            }
            $this->em->flush();
            $this->addFlash('success', $isNew ? 'Feedback ajouté.' : 'Feedback mis à jour.');

            return $this->redirectToRoute('console.crm.ticket.show', ['id' => $ticket->getId(), '_fragment' => 'feedbacks']);
        }

        return $this->render('console/crm/ticket/feedback_form.html.twig', [
            'pageName'    => $pageName,
            'formIcon'    => 'feedback',
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.crm.ticket.show', ['id' => $ticket->getId(), '_fragment' => 'feedbacks']),
            'backLabel'   => 'Ticket ' . $ticket->getReference(),
            'submitLabel' => $submitLabel,
            'description' => 'Ticket ' . $ticket->getReference() . ' — « ' . $ticket->getSujet() . ' ».',
        ]);
    }

    /**
     * Refuse l'ajout/l'édition d'un feedback sur un ticket clos : redirige vers la
     * fiche avec un message d'erreur. Retourne null si l'action est autorisée.
     */
    private function refuserSiClos(CrmTicket $ticket): ?Response
    {
        if ($ticket->getStatut() === CrmTicket::STATUT_CLOS) {
            $this->addFlash('error', 'Ce ticket est clos : il n\'accepte plus de feedback.');

            return $this->redirectToRoute('console.crm.ticket.show', ['id' => $ticket->getId(), '_fragment' => 'feedbacks']);
        }

        return null;
    }

    /** Change le statut d'un ticket. */
    #[Route('/{id}/statut', name: 'statut', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function statut(CrmTicket $ticket, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('crm-ticket-statut-' . $ticket->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $statut = (string) $request->request->get('statut', '');
        if (isset(CrmTicket::STATUTS[$statut])) {
            $ticket->setStatut($statut);
            $this->em->flush();
            $this->addFlash('success', 'Statut du ticket mis à jour.');
        }

        // Retour contextuel selon l'origine de l'action :
        // - « fiche »  → onglet Support de la fiche client ;
        // - « show »   → fiche du ticket ;
        // - sinon      → liste globale des tickets.
        $retour = $request->request->get('_retour');
        if ($retour === 'fiche' && $ticket->getClient()) {
            return $this->redirectToRoute('console.crm.client.show', [
                'id'        => $ticket->getClient()->getId(),
                '_fragment' => 'tab-support',
            ]);
        }
        if ($retour === 'show') {
            return $this->redirectToRoute('console.crm.ticket.show', ['id' => $ticket->getId()]);
        }

        return $this->redirectToRoute('console.crm.ticket.index');
    }
}
