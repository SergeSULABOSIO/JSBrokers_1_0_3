<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Entity\Crm\CrmTicket;
use App\Entity\Utilisateur;
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

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm-ticket-new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $client = $this->utilisateurRepository->find((int) $request->request->get('client'));
            $sujet = trim((string) $request->request->get('sujet', ''));

            if ($client instanceof Utilisateur && $sujet !== '') {
                $ticket = (new CrmTicket())
                    ->setClient($client)
                    ->setAgent($this->getUser() instanceof Utilisateur ? $this->getUser() : null)
                    ->setSujet($sujet)
                    ->setDescription(trim((string) $request->request->get('description', '')) ?: null)
                    ->setCanal((string) $request->request->get('canal', 'email'))
                    ->setPriorite((string) $request->request->get('priorite', CrmTicket::PRIORITE_NORMALE));
                $this->em->persist($ticket);
                $this->em->flush();
                $this->addFlash('success', 'Ticket créé (' . $ticket->getReference() . ').');

                return $this->redirectToRoute('console.crm.client.show', ['id' => $client->getId(), '_fragment' => 'tab-support']);
            }

            $this->addFlash('warning', 'Client et sujet sont requis.');
        }

        $preClient = $request->query->getInt('client') ?: null;

        return $this->render('console/crm/ticket/new.html.twig', [
            'pageName'  => 'Nouveau ticket',
            'pageIcon'  => 'feedback',
            'clients'   => $this->utilisateurRepository->findAllCrm(500),
            'preClient' => $preClient,
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
