<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Entity\Crm\CrmTicket;
use App\Entity\Utilisateur;
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

        return $this->redirectToRoute('console.crm.ticket.index');
    }
}
