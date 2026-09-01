<?php

namespace App\Controller\Admin;

use App\Constantes\Constante;
use App\Entity\DemandeConge;
use App\Entity\Invite;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Repository\MouvementCongeRepository;
use App\Service\Conge\CalculateurSolde;
use App\Service\Conge\CompteursExport;
use App\Service\Conge\CongeTransitionException;
use App\Service\Conge\DemandeCongePolicy;
use App\Service\Conge\GrilleDesCompteurs;
use App\Service\Conge\MouvementDuCompteur;
use App\Services\Canvas\CalculationProvider;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * LA GRILLE DES COMPTEURS, ET LES GESTES QUI S'Y RATTACHENT.
 *
 * ── UN CONTRÔLEUR À PART ────────────────────────────────────────────────────────────
 * Six routes de plus sur DemandeCongeController auraient noyé le CRUD des demandes sous
 * la gestion des compteurs — deux sujets voisins mais distincts : l'un traite des
 * dossiers, l'autre des droits.
 *
 * ── RÉSERVÉ AUX VALIDEURS ───────────────────────────────────────────────────────────
 * Toutes ces routes exigent le niveau MODIFICATION sur les congés. Un collaborateur
 * ordinaire n'a rien à faire ici : la grille expose les soldes de tout le cabinet, et
 * l'ajustement écrit sur le compteur d'autrui.
 *
 * ── AUCUNE RUBRIQUE ─────────────────────────────────────────────────────────────────
 * La grille s'ouvre depuis la barre d'outils des congés, comme le calendrier. Le groupe
 * Administration compte déjà sept entrées ; une huitième aurait éloigné du dossier un
 * écran qui ne parle que de lui.
 */
#[Route("/admin/compteurconge", name: 'admin.compteurconge.')]
#[IsGranted('ROLE_USER')]
class CompteurCongeController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private MouvementCongeRepository $mouvementRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private CalculationProvider $calculationProvider,
        private GrilleDesCompteurs $grille,
        private MouvementDuCompteur $mouvements,
        private DemandeCongePolicy $policy,
        private CalculateurSolde $calculateurSolde,
        private CompteursExport $export,
        CanvasBuilder $canvasBuilder,
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    /** Aucune collection : ce contrôleur ne sert pas de rubrique. */
    protected function getCollectionMap(): array
    {
        return [];
    }

    protected function getParentAssociationMap(): array
    {
        return [];
    }

    /**
     * La grille agents × exercice.
     *
     * Rendue entière à l'ouverture, en FRAGMENT lors d'un changement d'exercice :
     * recharger la boîte ferait perdre le focus et rejouerait l'animation à chaque clic.
     */
    #[Route('/api/grille', name: 'api.grille', methods: ['GET'])]
    public function grille(Request $request): Response
    {
        if ($refus = $this->refuserSiPasValideur()) {
            return $refus;
        }

        $exercice = $this->exerciceDemande($request);
        $grille = $this->grille->pour($this->getEntreprise(), $exercice);

        if ($request->query->getBoolean('fragment')) {
            return $this->json([
                'exercice' => $grille['exercice'],
                'html' => $this->renderView('components/conge/_compteurs_grille.html.twig', ['grille' => $grille]),
            ]);
        }

        return $this->render('components/conge/_compteurs.html.twig', ['grille' => $grille]);
    }

    /**
     * LE JOURNAL D'UN AGENT — d'où vient son solde, ligne par ligne.
     *
     * C'est ce qu'on ouvre quand un chiffre surprend. Un compteur qu'on ne peut pas
     * expliquer est un compteur qu'on cesse de croire.
     */
    #[Route('/api/journal/{idAgent}', name: 'api.journal', requirements: ['idAgent' => Requirement::DIGITS], methods: ['GET'])]
    public function journal(int $idAgent, Request $request): Response
    {
        if ($refus = $this->refuserSiPasValideur()) {
            return $refus;
        }

        $agent = $this->grille->agentDuCabinet($this->getEntreprise(), $idAgent);
        if ($agent === null) {
            return $this->json(['message' => 'Collaborateur introuvable dans cet espace de travail.'], Response::HTTP_NOT_FOUND);
        }

        $exercice = $this->exerciceDemande($request);

        return $this->json([
            'html' => $this->renderView('components/conge/_compteurs_journal.html.twig', [
                'agent' => $agent,
                'exercice' => $exercice,
                'solde' => $this->calculateurSolde->pour($agent, $exercice),
                'mouvements' => $this->mouvementRepository->journalDe($agent, $exercice),
            ]),
        ]);
    }

    /**
     * UN AJUSTEMENT MANUEL, MOTIVÉ.
     *
     * Le motif n'est pas une politesse : un chiffre qui apparaît dans un journal sans
     * explication fera douter de tout le reste, des mois plus tard.
     */
    #[Route('/api/ajustement/{idAgent}', name: 'api.ajustement', requirements: ['idAgent' => Requirement::DIGITS], methods: ['POST'])]
    public function ajustement(int $idAgent, Request $request): JsonResponse
    {
        if ($refus = $this->refuserSiPasValideur()) {
            return $refus;
        }

        $agent = $this->grille->agentDuCabinet($this->getEntreprise(), $idAgent);
        if ($agent === null) {
            return $this->json(['message' => 'Collaborateur introuvable dans cet espace de travail.'], Response::HTTP_NOT_FOUND);
        }

        $args = json_decode($request->getContent() ?: '{}', true);
        $quantite = is_array($args) ? (float) ($args['quantite'] ?? 0) : 0.0;
        $motif = is_array($args) ? trim((string) ($args['motif'] ?? '')) : '';
        $exercice = is_array($args) && isset($args['exercice'])
            ? (int) $args['exercice']
            : (int) (new \DateTimeImmutable('now'))->format('Y');

        try {
            $this->mouvements->ajuster($agent, $exercice, $quantite, $motif, $this->getInvite());
        } catch (CongeTransitionException $e) {
            return $this->json(
                ['success' => false, 'message' => implode(' ', $e->violations), 'errors' => $e->violations],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => sprintf(
                'Compteur de %s ajusté de %s jour(s). Le journal en garde la trace.',
                (string) $agent->getNom(),
                $this->formater($quantite),
            ),
        ]);
    }

    /**
     * LE DÉCOMPTE DE SORTIE — ce qui reste dû, ou ce qui a été pris en trop.
     *
     * En GET, il se CALCULE sans rien écrire : on regarde avant de solder. En POST, la
     * régularisation est écrite au journal, et le décompte imprimable est rendu.
     */
    #[Route('/api/sortie/{idAgent}', name: 'api.sortie_apercu', requirements: ['idAgent' => Requirement::DIGITS], methods: ['GET'])]
    public function apercuDeSortie(int $idAgent, Request $request): Response
    {
        if ($refus = $this->refuserSiPasValideur()) {
            return $refus;
        }

        $agent = $this->grille->agentDuCabinet($this->getEntreprise(), $idAgent);
        if ($agent === null) {
            return $this->json(['message' => 'Collaborateur introuvable dans cet espace de travail.'], Response::HTTP_NOT_FOUND);
        }

        $dateFin = $this->dateDeSortie($request->query->get('dateFin'));

        // `ecrire: false` : un aperçu qui écrirait ferait du simple fait de regarder un
        // acte de gestion.
        $decompte = $this->mouvements->regulariserLaSortie(
            $agent,
            $dateFin,
            $this->getInvite(),
            DemandeConge::ORIGINE_UI,
            ecrire: false,
        );

        return $this->json([
            'html' => $this->renderView('components/conge/_compteurs_sortie.html.twig', [
                'agent' => $agent,
                'dateFin' => $dateFin,
                'decompte' => $decompte,
                'definitif' => false,
            ]),
        ]);
    }

    #[Route('/api/sortie/{idAgent}', name: 'api.sortie_executer', requirements: ['idAgent' => Requirement::DIGITS], methods: ['POST'])]
    public function executerLaSortie(int $idAgent, Request $request): JsonResponse
    {
        if ($refus = $this->refuserSiPasValideur()) {
            return $refus;
        }

        $agent = $this->grille->agentDuCabinet($this->getEntreprise(), $idAgent);
        if ($agent === null) {
            return $this->json(['message' => 'Collaborateur introuvable dans cet espace de travail.'], Response::HTTP_NOT_FOUND);
        }

        $args = json_decode($request->getContent() ?: '{}', true);
        $dateFin = $this->dateDeSortie(is_array($args) ? ($args['dateFin'] ?? null) : null);

        $decompte = $this->mouvements->regulariserLaSortie($agent, $dateFin, $this->getInvite());
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => sprintf(
                'Décompte de sortie enregistré pour %s : solde final de %s jour(s).',
                (string) $agent->getNom(),
                $this->formater($decompte['soldeFinal']),
            ),
            'html' => $this->renderView('components/conge/_compteurs_sortie.html.twig', [
                'agent' => $agent,
                'dateFin' => $dateFin,
                'decompte' => $decompte,
                'definitif' => true,
            ]),
        ]);
    }

    /**
     * L'export du classeur.
     *
     * Il est métré comme une LECTURE — c'en est une, d'autant de lignes que la grille en
     * porte. Aucun barème propre n'a été inventé : le module se facture par le mécanisme
     * générique de l'application, pas par un second circuit à tenir à jour.
     */
    #[Route('/api/export', name: 'api.export', methods: ['GET'])]
    public function exporter(Request $request): Response
    {
        if ($refus = $this->refuserSiPasValideur()) {
            return $refus;
        }

        $entreprise = $this->getEntreprise();
        $grille = $this->grille->pour($entreprise, $this->exerciceDemande($request));

        try {
            $this->tokenAccountService->meterRead(
                \App\Entity\MouvementConge::class,
                count($grille['lignes']),
                $entreprise,
                $this->getUser(),
            );
        } catch (\App\Token\InsufficientTokensException $e) {
            return $this->tokensBlockedJson($e);
        }

        return $this->export->classeur($grille, (string) $entreprise->getNom());
    }

    // ─────────────────────────────── Gardes et lectures ────────────────────────────

    /**
     * TOUT ICI EXIGE D'ÊTRE VALIDEUR. La grille expose les soldes de tout le cabinet, et
     * l'ajustement écrit sur le compteur d'autrui : ni l'un ni l'autre ne regarde un
     * collaborateur ordinaire.
     */
    private function refuserSiPasValideur(): ?JsonResponse
    {
        return $this->policy->estValideur($this->getInvite()) ? null : $this->accessDeniedJson();
    }

    /** L'exercice demandé, borné : une année venue de l'URL ne doit pas produire d'erreur. */
    private function exerciceDemande(Request $request): int
    {
        $courant = (int) (new \DateTimeImmutable('now'))->format('Y');
        $exercice = (int) $request->query->get('exercice', (string) $courant);

        return ($exercice >= 2000 && $exercice <= 2100) ? $exercice : $courant;
    }

    /**
     * La date de fin de contrat, aujourd'hui par défaut.
     *
     * Une date illisible retombe sur aujourd'hui plutôt que de lever : sur un écran de
     * sortie, une erreur brute laisserait croire que le collaborateur est introuvable.
     */
    private function dateDeSortie(mixed $brut): \DateTimeImmutable
    {
        if (is_string($brut) && $brut !== '') {
            try {
                return new \DateTimeImmutable($brut);
            } catch (\Throwable) {
                // Retombe sur aujourd'hui.
            }
        }

        return new \DateTimeImmutable('today');
    }

    private function formater(float $jours): string
    {
        return rtrim(rtrim(number_format($jours, 1, ',', ' '), '0'), ',');
    }
}
