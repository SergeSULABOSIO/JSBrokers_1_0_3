<?php

namespace App\Controller\Admin;

use App\Crm\CrmNotifier;
use App\Entity\Crm\CrmNotification;
use App\Entity\Crm\CrmTicket;
use App\Entity\Utilisateur;
use App\Form\SupportDemandeType;
use App\Repository\Crm\CrmTicketRepository;
use App\Repository\UtilisateurRepository;
use App\Services\Mail\CorporateMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @file Support self-service côté courtier (espace de travail).
 * @description Permet à un courtier d'ouvrir une demande de support depuis son
 * espace. La demande crée un CrmTicket (canal « portail ») qui alimente
 * directement la file support de la console — aucune entité parallèle. L'équipe
 * est notifiée (in-app via CrmNotifier + e-mail via CorporateMailer) et le
 * courtier suit le statut de ses demandes. Rendu comme composant du workspace
 * (cf. getComponentMap + menu.yaml « Assistance ») ; l'interactivité (barre de
 * progression + toast + rafraîchissement) est pilotée par le contrôleur Stimulus
 * `support`, d'où la réponse JSON de la soumission.
 */
#[Route('/admin/support', name: 'admin.support.')]
#[IsGranted('ROLE_USER')]
class SupportController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CrmTicketRepository $ticketRepository,
        private UtilisateurRepository $utilisateurRepository,
        private CrmNotifier $notifier,
        private CorporateMailer $corporateMailer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Rend le composant Support dans l'espace de travail. Atteint par le « Cerveau »
     * via forwardToComponent ; l'accès au workspace (entreprise/invité) est déjà
     * validé en amont par EspaceDeTravailComponentController::loadComponent.
     */
    #[Route('/workspace/{idEntreprise}', name: 'workspace', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function loadWorkspaceComponent(): Response
    {
        return $this->render(
            'components/_support_component.html.twig',
            $this->componentParams($this->createForm(SupportDemandeType::class, new CrmTicket())),
        );
    }

    /**
     * Crée le ticket à partir de la demande du courtier, puis notifie l'équipe.
     * Répond en JSON { success, message, html } : le contrôleur Stimulus affiche le
     * toast (succès/échec) et remplace le composant par sa version rafraîchie.
     */
    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        $ticket = new CrmTicket();
        $form = $this->createForm(SupportDemandeType::class, $ticket);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->json([
                'success' => false,
                'message' => "Votre demande n'a pas pu être envoyée. Veuillez vérifier les champs indiqués.",
                'html'    => $this->renderView('components/_support_component.html.twig', $this->componentParams($form)),
            ]);
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();
        $ticket->setClient($user)
            ->setAgent(null)
            ->setCanal(CrmTicket::CANAL_PORTAIL)
            // Entreprise depuis laquelle la demande est émise = espace de travail
            // actif du courtier (connectedTo, synchronisé à l'entrée du workspace).
            ->setEntreprise($user->getConnectedTo());

        $this->em->persist($ticket);
        $this->em->flush();

        $this->notifierEquipe($ticket, $user);

        return $this->json([
            'success' => true,
            'message' => sprintf('Votre demande %s a bien été transmise au support. Notre équipe vous recontactera.', $ticket->getReference()),
            'html'    => $this->renderView(
                'components/_support_component.html.twig',
                $this->componentParams($this->createForm(SupportDemandeType::class, new CrmTicket())),
            ),
        ]);
    }

    /** @return array{form: \Symfony\Component\Form\FormView, tickets: CrmTicket[]} */
    private function componentParams(FormInterface $form): array
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return [
            'form'    => $form->createView(),
            'tickets' => $this->ticketRepository->findForClient($user),
        ];
    }

    /**
     * Notifie l'équipe JS Brokers d'un nouveau ticket : notification in-app à tous
     * les agents (CrmNotifier) + e-mail corporate (réutilise le template agent).
     */
    private function notifierEquipe(CrmTicket $ticket, Utilisateur $client): void
    {
        $nom = $client->getNom() ?: $client->getEmail();
        $entreprise = $ticket->getEntreprise();
        $origine = $entreprise ? sprintf('%s (%s)', $nom, $entreprise->getNom()) : $nom;

        $this->notifier->broadcast(
            'Nouveau ticket support',
            sprintf('%s a ouvert le ticket %s : « %s ».', $origine, $ticket->getReference(), $ticket->getSujet()),
            CrmNotification::NIVEAU_INFO,
            $this->generateUrl('console.crm.ticket.index'),
            true,
        );

        $details = [
            'Référence'  => $ticket->getReference(),
            'Sujet'      => $ticket->getSujet(),
            'Priorité'   => $ticket->getPriorite(),
            'Client'     => sprintf('%s (%s)', $nom, $client->getEmail()),
        ];
        if ($entreprise) {
            $details['Entreprise'] = $entreprise->getNom();
        }
        if ($ticket->getDescription()) {
            $details['Message'] = $ticket->getDescription();
        }

        // L'e-mail ne doit jamais compromettre la création du ticket (déjà
        // persisté) : un souci de transport est journalisé, pas propagé.
        try {
            foreach ($this->utilisateurRepository->findAgents() as $agent) {
                $email = $agent->getEmail();
                if (!$email) {
                    continue;
                }

                $this->corporateMailer->send(
                    $email,
                    $this->corporateMailer->buildSubject('Nouveau ticket support', $nom),
                    'emails/agent_notification.html.twig',
                    [
                        'titre'   => 'Nouveau ticket support',
                        'intro'   => sprintf('%s a ouvert une demande de support depuis son espace de travail.', $nom),
                        'icone'   => 'feedback',
                        'details' => $details,
                        'agent'   => $agent->getNom(),
                    ],
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Support: échec de notification e-mail des agents pour le ticket {ref}: {msg}', [
                'ref' => $ticket->getReference(),
                'msg' => $e->getMessage(),
            ]);
        }
    }
}
