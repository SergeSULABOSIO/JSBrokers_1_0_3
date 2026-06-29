<?php

namespace App\Controller\Console;

use App\Entity\Evaluation;
use App\Entity\Objectif;
use App\Entity\Utilisateur;
use App\Enum\Departement;
use App\Enum\FonctionCollaborateur;
use App\Form\EvaluationType;
use App\Form\ObjectifType;
use App\Repository\EvaluationRepository;
use App\Repository\UtilisateurRepository;
use App\Service\Console\FicheEvaluationBuilder;
use App\Services\Mail\CorporateMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Évaluations RH : objectifs (SMART) et fiches d'évaluation des collaborateurs.
 * La consultation est ouverte aux agents ; la définition d'objectifs, la saisie de
 * l'appréciation et la clôture sont réservées à ceux qui peuvent gérer les
 * évaluations — le super-administrateur (Direction Générale) et le Directeur des
 * Ressources Humaines — qui notifient le collaborateur concerné par e-mail.
 */
#[Route('/console/evaluations', name: 'console.evaluation.')]
#[IsGranted('ROLE_ADMIN')]
class EvaluationController extends AbstractConsoleController
{
    public function __construct(
        private UtilisateurRepository $utilisateurRepository,
        private EvaluationRepository $evaluationRepository,
        private FicheEvaluationBuilder $ficheBuilder,
        private CorporateMailer $corporateMailer,
        private LoggerInterface $logger,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);
        [$annee, $trimestre] = $this->periode($request);

        $lignes = [];
        foreach ($this->utilisateurRepository->findAgents() as $agent) {
            $score = $this->ficheBuilder->score($agent, $annee, $trimestre);
            $lignes[] = [
                'agent'        => $agent,
                'score'        => $score,
                'mention'      => $this->ficheBuilder->mention($score),
                'mentionClass' => $this->ficheBuilder->mentionClass($score),
            ];
        }

        return $this->render('console/evaluation/index.html.twig', [
            'pageName'  => 'Évaluations',
            'pageIcon'  => 'action:analyser',
            'lignes'    => $lignes,
            'annee'     => $annee,
            'trimestre' => $trimestre,
            'annees'    => $this->anneesProposees($annee),
            'canManage' => $this->canManageEvaluations(),
        ]);
    }

    #[Route('/collaborateur/{id}', name: 'show', requirements: ['id' => Requirement::DIGITS])]
    public function show(Utilisateur $collaborateur, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);
        [$annee, $trimestre] = $this->periode($request);

        return $this->render('console/evaluation/show.html.twig', [
            'pageName'      => 'Évaluation · ' . $collaborateur->getNom(),
            'pageIcon'      => 'action:analyser',
            'collaborateur' => $collaborateur,
            'fiche'         => $this->ficheBuilder->build($collaborateur, $annee, $trimestre),
            'evaluation'    => $this->evaluationRepository->findOnePeriode($collaborateur, $annee, $trimestre),
            'annee'         => $annee,
            'trimestre'     => $trimestre,
            'annees'        => $this->anneesProposees($annee),
            'canManage'     => $this->canManageEvaluations(),
        ]);
    }

    #[Route('/collaborateur/{id}/objectif/new', name: 'objectif_new', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function objectifNew(Utilisateur $collaborateur, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->denyUnlessCanManage();
        $this->applyLangPreference($request, $localeSwitcher);
        [$annee, $trimestre] = $this->periode($request);

        $objectif = (new Objectif())
            ->setCollaborateur($collaborateur)
            ->setAnnee($annee)
            ->setTrimestre($trimestre);

        return $this->traiterObjectif($objectif, $request, true);
    }

    #[Route('/objectif/{id}/edit', name: 'objectif_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function objectifEdit(Objectif $objectif, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->denyUnlessCanManage();
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->traiterObjectif($objectif, $request, false);
    }

    #[Route('/objectif/{id}', name: 'objectif_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function objectifDelete(Objectif $objectif, Request $request): Response
    {
        $this->denyUnlessCanManage();
        if (!$this->isCsrfTokenValid('delete-objectif-' . $objectif->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $collaborateur = $objectif->getCollaborateur();
        $annee = $objectif->getAnnee();
        $trimestre = $objectif->getTrimestre();

        $this->em->remove($objectif);
        $this->em->flush();

        $this->addFlash('success', 'Objectif supprimé.');

        return $this->redirectToShow($collaborateur, $annee, $trimestre);
    }

    #[Route('/collaborateur/{id}/evaluer', name: 'evaluer', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function evaluer(Utilisateur $collaborateur, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->denyUnlessCanManage();
        $this->applyLangPreference($request, $localeSwitcher);
        [$annee, $trimestre] = $this->periode($request);

        $evaluation = $this->evaluationRepository->findOnePeriode($collaborateur, $annee, $trimestre)
            ?? (new Evaluation())->setCollaborateur($collaborateur)->setAnnee($annee)->setTrimestre($trimestre);

        $form = $this->createForm(EvaluationType::class, $evaluation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // À la clôture, on fige le score global de la période pour l'historique.
            if ($evaluation->isCloturee()) {
                $evaluation->setScoreFige((float) $this->ficheBuilder->score($collaborateur, $annee, $trimestre));
            } else {
                $evaluation->setScoreFige(null);
            }

            if ($evaluation->getId() === null) {
                $this->em->persist($evaluation);
            }
            $this->em->flush();

            if ($evaluation->isCloturee()) {
                $this->notifierCloture($collaborateur, $evaluation);
            }
            $this->addFlash('success', sprintf('Évaluation de « %s » enregistrée.', $collaborateur->getNom()));

            return $this->redirectToShow($collaborateur, $annee, $trimestre);
        }

        return $this->render('console/evaluation/form.html.twig', [
            'pageName'      => 'Évaluer ' . $collaborateur->getNom(),
            'form'          => $form,
            'backUrl'       => $this->urlShow($collaborateur, $annee, $trimestre),
            'backLabel'     => 'Fiche d\'évaluation',
            'submitLabel'   => 'Enregistrer l\'évaluation',
            'description'   => sprintf('Appréciation et clôture pour la période %s. La clôture fige le score global de la période.', $this->periodeLabel($annee, $trimestre)),
            'formIcon'      => 'action:analyser',
        ]);
    }

    /** Création/édition d'un objectif, factorisée (DRY). */
    private function traiterObjectif(Objectif $objectif, Request $request, bool $isNew): Response
    {
        $form = $this->createForm(ObjectifType::class, $objectif);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->em->persist($objectif);
            }
            $this->em->flush();

            $this->notifierObjectif($objectif, $isNew);
            $this->addFlash('success', sprintf('Objectif « %s » enregistré.', $objectif->getTitre()));

            return $this->redirectToShow($objectif->getCollaborateur(), $objectif->getAnnee(), $objectif->getTrimestre());
        }

        $collaborateur = $objectif->getCollaborateur();

        return $this->render('console/evaluation/form.html.twig', [
            'pageName'    => ($isNew ? 'Nouvel objectif · ' : 'Éditer l\'objectif · ') . $collaborateur->getNom(),
            'form'        => $form,
            'backUrl'     => $this->urlShow($collaborateur, $objectif->getAnnee(), $objectif->getTrimestre()),
            'backLabel'   => 'Fiche d\'évaluation',
            'submitLabel' => $isNew ? 'Créer l\'objectif' : 'Enregistrer',
            'description' => 'Définissez une cible mesurable et son poids dans le score. '
                . 'En mode automatique, l\'atteinte est recalculée depuis les données de la plateforme.',
            'formIcon'    => 'tache',
        ]);
    }

    private function notifierObjectif(Objectif $objectif, bool $isNew): void
    {
        $collaborateur = $objectif->getCollaborateur();
        $this->notifier(
            $collaborateur,
            $isNew ? 'Nouvel objectif' : 'Objectif mis à jour',
            sprintf('Un objectif vient d\'être %s pour la période %s.', $isNew ? 'défini' : 'mis à jour', $objectif->periodeLabel()),
            'tache',
            [
                'Objectif' => (string) $objectif->getTitre(),
                'Période'  => $objectif->periodeLabel(),
                'Cible'    => rtrim(rtrim(number_format($objectif->getCible(), 2, '.', ' '), '0'), '.') . ' ' . $objectif->getUnite(),
                'Poids'    => $objectif->getPoids() . ' %',
                'Suivi'    => $objectif->getMode()->label(),
            ],
        );
    }

    private function notifierCloture(Utilisateur $collaborateur, Evaluation $evaluation): void
    {
        $this->notifier(
            $collaborateur,
            'Évaluation clôturée',
            sprintf('Votre évaluation pour la période %s a été clôturée.', $evaluation->periodeLabel()),
            'action:analyser',
            [
                'Période'    => $evaluation->periodeLabel(),
                'Score'      => (int) round((float) $evaluation->getScoreFige()) . ' / 100',
                'Mention'    => $this->ficheBuilder->mention((int) round((float) $evaluation->getScoreFige())),
                'Appréciation' => $evaluation->getAppreciation() ?: '—',
            ],
        );
    }

    /**
     * Envoi e-mail au seul collaborateur concerné, tolérant aux pannes.
     *
     * @param array<string, string> $details
     */
    private function notifier(Utilisateur $collaborateur, string $objet, string $intro, string $icone, array $details): void
    {
        $email = $collaborateur->getEmail();
        if (!$email) {
            return;
        }

        try {
            $this->corporateMailer->send(
                $email,
                $this->corporateMailer->buildSubject($objet, (string) $collaborateur->getNom()),
                'emails/agent_notification.html.twig',
                [
                    'titre'   => $objet,
                    'intro'   => sprintf('Bonjour %s, %s', $collaborateur->getNom(), lcfirst($intro)),
                    'icone'   => $icone,
                    'details' => $details,
                    'agent'   => $collaborateur->getNom(),
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Évaluation : échec de notification e-mail de {nom} : {msg}', [
                'nom' => $collaborateur->getNom(),
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Peut gérer les évaluations (créer/éditer objectifs, évaluer, clôturer) :
     * le super-administrateur (Direction Générale) et le Directeur des Ressources
     * Humaines. Les autres collaborateurs ont un accès en lecture seule.
     */
    private function canManageEvaluations(): bool
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }

        $user = $this->getUser();

        return $user instanceof Utilisateur
            && $user->getDepartement() === Departement::RH
            && $user->getFonction() === FonctionCollaborateur::DIRECTEUR;
    }

    private function denyUnlessCanManage(): void
    {
        if (!$this->canManageEvaluations()) {
            throw $this->createAccessDeniedException(
                'Réservé au super-administrateur ou au Directeur des Ressources Humaines.'
            );
        }
    }

    /** Lecture de la période depuis la requête (année + trimestre). */
    private function periode(Request $request): array
    {
        $annee = (int) $request->query->get('annee', (int) date('Y'));
        $trimestre = max(0, min(4, (int) $request->query->get('trimestre', 0)));

        return [$annee, $trimestre];
    }

    private function periodeLabel(int $annee, int $trimestre): string
    {
        return $annee . ' · ' . ($trimestre === 0 ? 'Annuel' : 'T' . $trimestre);
    }

    /** @return int[] */
    private function anneesProposees(int $annee): array
    {
        $courante = (int) date('Y');
        $base = max($courante, $annee);

        return range($base + 1, $base - 4);
    }

    private function urlShow(?Utilisateur $collaborateur, int $annee, int $trimestre): string
    {
        return $this->generateUrl('console.evaluation.show', [
            'id'        => $collaborateur?->getId(),
            'annee'     => $annee,
            'trimestre' => $trimestre,
        ]);
    }

    private function redirectToShow(?Utilisateur $collaborateur, int $annee, int $trimestre): Response
    {
        return $this->redirect($this->urlShow($collaborateur, $annee, $trimestre));
    }
}
