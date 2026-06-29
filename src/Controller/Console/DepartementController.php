<?php

namespace App\Controller\Console;

use App\Entity\Utilisateur;
use App\Enum\Departement;
use App\Form\AffectationCollaborateurType;
use App\Repository\UtilisateurRepository;
use App\Services\Mail\CorporateMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Organisation des collaborateurs en départements et attribution des fonctions.
 * La consultation est ouverte aux agents (transparence) ; seule l'affectation est
 * réservée au super-admin. Chaque affectation notifie par e-mail le concerné.
 */
#[Route('/console/departements', name: 'console.departement.')]
#[IsGranted('ROLE_ADMIN')]
class DepartementController extends AbstractConsoleController
{
    public function __construct(
        private UtilisateurRepository $utilisateurRepository,
        private CorporateMailer $corporateMailer,
        private LoggerInterface $logger,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        // Regroupement des agents par département (ordre fixe des départements),
        // plus un bloc « non affectés ».
        $groupes = [];
        foreach (Departement::cases() as $dep) {
            $groupes[$dep->value] = ['dep' => $dep, 'agents' => []];
        }
        $nonAffectes = [];
        foreach ($this->utilisateurRepository->findAgents() as $agent) {
            $dep = $agent->getDepartement();
            if ($dep === null) {
                $nonAffectes[] = $agent;
            } else {
                $groupes[$dep->value]['agents'][] = $agent;
            }
        }

        return $this->render('console/departement/index.html.twig', [
            'pageName'    => 'Départements & rôles',
            'pageIcon'    => 'action:role',
            'groupes'     => $groupes,
            'nonAffectes' => $nonAffectes,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function edit(Utilisateur $collaborateur, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $form = $this->createForm(AffectationCollaborateurType::class, $collaborateur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            $this->notifierAffectation($collaborateur);
            $this->addFlash('success', sprintf('Affectation de « %s » enregistrée.', $collaborateur->getNom()));

            return $this->redirectToRoute('console.departement.index');
        }

        return $this->render('console/departement/form.html.twig', [
            'pageName'      => 'Affecter ' . $collaborateur->getNom(),
            'form'          => $form,
            'collaborateur' => $collaborateur,
            'backUrl'       => $this->generateUrl('console.departement.index'),
            'backLabel'     => 'Départements & rôles',
            'submitLabel'   => 'Enregistrer l\'affectation',
            'description'   => 'Rattachez ce collaborateur à un département et définissez sa fonction. '
                . 'Son périmètre d\'accès à la console s\'ajuste automatiquement, et il en est informé par e-mail.',
            'formIcon'      => 'action:role',
        ]);
    }

    /**
     * Notifie le collaborateur concerné de son affectation (département, fonction,
     * périmètre). Envoi direct via CorporateMailer (au seul concerné), tolérant aux
     * pannes : l'échec d'envoi ne doit pas annuler l'enregistrement déjà persisté.
     */
    private function notifierAffectation(Utilisateur $collaborateur): void
    {
        $email = $collaborateur->getEmail();
        if (!$email) {
            return;
        }

        $dep = $collaborateur->getDepartement();
        $fonction = $collaborateur->getFonction();

        $details = [
            'Département'           => $dep?->label() ?? 'Non affecté',
            'Fonction'             => $fonction?->label() ?? 'Non définie',
            'Niveau d\'accès'      => $fonction?->niveauLabel() ?? '—',
            'Rubriques accessibles' => implode(', ', $collaborateur->getPerimetreLabels()) ?: 'Accès complet (non restreint)',
        ];

        try {
            $this->corporateMailer->send(
                $email,
                $this->corporateMailer->buildSubject('Affectation & rôle', (string) $collaborateur->getNom()),
                'emails/agent_notification.html.twig',
                [
                    'titre'   => 'Votre rôle au sein de JS Brokers',
                    'intro'   => sprintf(
                        'Bonjour %s, votre rattachement au sein de l\'équipe JS Brokers vient d\'être défini. Voici les détails de votre affectation.',
                        $collaborateur->getNom()
                    ),
                    'icone'   => 'role',
                    'details' => $details,
                    'agent'   => $collaborateur->getNom(),
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Affectation : échec de notification e-mail de {nom} : {msg}', [
                'nom' => $collaborateur->getNom(),
                'msg' => $e->getMessage(),
            ]);
        }
    }
}
