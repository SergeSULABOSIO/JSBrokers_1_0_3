<?php

namespace App\Controller\Admin;

use App\Constantes\Constante;
use App\Entity\DemandeConge;
use App\Entity\TypeAbsence;
use App\Entity\Invite;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Form\DemandeCongeType;
use App\Repository\DemandeCongeRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Service\Conge\CalculateurJoursOuvrables;
use App\Service\Conge\CalculateurSolde;
use App\Service\Conge\PeriodeParDefaut;
use App\Service\Conge\CalendrierEquipe;
use App\Service\Conge\CongeTransitionException;
use App\Service\Conge\DemandeCongePolicy;
use App\Service\Conge\DemandeCongeWorkflow;
use App\Services\Canvas\CalculationProvider;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\CongeVisibiliteScope;
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
 * CRUD workspace des demandes de congé (module Administration → Congés).
 *
 * ── DEUX GARDES, PAS UNE ────────────────────────────────────────────────────────────
 * 1. La LISTE est restreinte en SQL par CongeVisibiliteScope, réinjecté à chaque requête
 *    via `$extraCriteria` : le critère est fusionné APRÈS la charge utile du navigateur,
 *    il n'est donc pas retirable par celui qu'il vise.
 * 2. Les accès DIRECTS (fiche, soumission, suppression, collections) sont gardés objet
 *    par objet par DemandeCongePolicy. Masquer une ligne de liste ne protège rien si la
 *    fiche du collègue reste ouverte à qui devine son identifiant.
 *
 * ── LES TRANSITIONS NE SONT PAS DES ÉDITIONS ────────────────────────────────────────
 * Soumettre, approuver, refuser, annuler ne passent pas par le formulaire : ce sont des
 * gestes, avec leurs propres règles, et ils appellent DemandeCongeWorkflow — le même
 * service que l'assistant. C'est ce qui rend les deux canaux rigoureusement équivalents.
 */
#[Route("/admin/demandeconge", name: 'admin.demandeconge.')]
#[IsGranted('ROLE_USER')]
class DemandeCongeController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private DemandeCongeRepository $demandeCongeRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private CalculationProvider $calculationProvider,
        private DemandeCongePolicy $policy,
        private DemandeCongeWorkflow $workflow,
        private PeriodeParDefaut $periodeParDefaut,
        private CalculateurJoursOuvrables $calculateurJours,
        private \App\Repository\TypeAbsenceRepository $typeAbsenceRepository,
        private CalculateurSolde $calculateurSolde,
        private CongeVisibiliteScope $visibilite,
        private CalendrierEquipe $calendrier,
        CanvasBuilder $canvasBuilder,
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(DemandeConge::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(DemandeConge::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(DemandeConge::class, $request);
    }

    /**
     * Rafraîchissement de la liste (recherche, chips, pagination).
     *
     * LE PÉRIMÈTRE EST REPOSÉ ICI, à chaque appel. `renderViewOrListComponent` fusionne
     * `$extraCriteria` après les critères venus du navigateur : un collaborateur qui
     * efface le badge « Mes demandes » ne l'efface donc que pour l'affichage, jamais pour
     * la requête.
     */
    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(
            DemandeConge::class,
            $request,
            true,
            $this->visibilite->critereFor($this->getInvite()),
        );
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?DemandeConge $demandeConge, Request $request): Response
    {
        if ($demandeConge !== null && !$this->policy->peutVoir($this->getInvite(), $demandeConge)) {
            return $this->accessDeniedJson();
        }

        return $this->renderFormCanvas(
            $request,
            DemandeConge::class,
            DemandeCongeType::class,
            $demandeConge,
            function (DemandeConge $demande, Invite $invite) {
                $demande->setEntreprise($invite->getEntreprise());
                // L'agent par défaut, c'est SOI. Un collaborateur pose ses propres congés
                // neuf fois sur dix ; un valideur qui saisit pour autrui change le champ.
                $demande->setAgent($invite);
                $demande->setStatut(DemandeConge::STATUT_BROUILLON);
                $demande->setOrigine(DemandeConge::ORIGINE_UI);
                // LA PÉRIODE PROPOSÉE DOIT POUVOIR ÊTRE ACCEPTÉE TELLE QUELLE.
                //
                // Elle s'ouvrait sur « aujourd'hui à aujourd'hui » : une date que le
                // contrôle de préavis refuse, et une durée d'un jour que presque personne
                // ne demande. Chaque saisie commençait donc par corriger les deux champs
                // que l'écran venait de remplir.
                $debut = $this->periodeParDefaut->debut($invite);
                $demande->setDateDebut($debut);
                $demande->setDateFin($this->periodeParDefaut->fin($invite, $debut));

                // LE TYPE PAR DÉFAUT EST LE CONGÉ ANNUEL. C'est celui de la quasi-totalité
                // des demandes ; laisser « Choisir un type… » obligeait à ouvrir une liste
                // pour y désigner l'évidence, à chaque fois. Les autres types — maladie,
                // événement familial — se choisissent, eux, en connaissance de cause.
                $demande->setTypeAbsence(
                    $this->typeAbsenceRepository->parCode($invite->getEntreprise(), TypeAbsence::CODE_CONGE_ANNUEL),
                );
            }
        );
    }

    /**
     * ── LE DÉCOMPTE EST RECALCULÉ À CHAQUE ÉCRITURE, tant que rien n'est décidé ──────
     *
     * Une demande posée du 2 au 2 septembre coûte un jour. Corrigée du 2 au 3, elle en
     * coûte deux — mais la liste continuait d'annoncer « 1 j » à côté de la nouvelle
     * période : le décompte n'était figé qu'à la SOUMISSION, et changer les dates ensuite
     * le laissait tel quel. Deux chiffres qui se contredisent sur la même ligne, et un
     * contrôle de solde qui se prononce sur le mauvais.
     *
     * Le crochet `beforePersist` est le bon moment : le formulaire est validé, l'entité
     * porte ses nouvelles dates, et rien n'est encore parti en base. Le chiffre est donc
     * juste DÈS la réponse — pas au prochain rafraîchissement.
     *
     * Les écritures qui ne passent pas par ici — l'assistant, qui écrit par le moteur
     * générique de mutation — sont rattrapées en fin de requête par
     * {@see \App\Service\Conge\DemandeCongeWorkflow::completerLaTrace}. La règle, elle,
     * n'est écrite qu'une fois.
     */
    /**
     * LA DATE DE FIN SUIT LA DATE DE DÉBUT, sans quitter le formulaire.
     *
     * ── CE QUE CELA ÉVITE ───────────────────────────────────────────────────────────
     * Déplacer son départ d'une semaine obligeait à recalculer soi-même la date de retour,
     * en tenant compte des week-ends, des jours fériés du cabinet et de son propre régime
     * de travail. Personne ne fait ce calcul de tête : on posait une date approximative,
     * et l'on découvrait le décompte réel à l'enregistrement.
     *
     * ── LA DURÉE EST CONSERVÉE, PAS REMISE À DIX ────────────────────────────────────
     * Quelqu'un qui a ramené sa demande à trois jours puis décale son départ veut toujours
     * trois jours. On mesure donc la longueur de la période TELLE QU'ELLE ÉTAIT avant le
     * geste, et on la reporte sur la nouvelle date.
     *
     * ── ET LE CALCUL RESTE ICI ──────────────────────────────────────────────────────
     * Le serveur seul connaît les jours fériés du cabinet et le régime de l'intéressé. Le
     * refaire dans le navigateur donnerait une seconde réponse à « ce jour compte-t-il ? »,
     * et l'écran finirait par contredire le décompte annoncé à l'enregistrement.
     */
    #[Route('/api/periode-fin', name: 'api.periode_fin', methods: ['POST'])]
    public function periodeFin(Request $request): JsonResponse
    {
        $args = json_decode($request->getContent() ?: '{}', true);
        $args = is_array($args) ? $args : [];

        $nouveauDebut = $this->dateOuNull($args['debut'] ?? null);
        if ($nouveauDebut === null) {
            return $this->json(['message' => 'Date de début illisible.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // L'AGENT EST RÉSOLU DANS LE PÉRIMÈTRE, jamais pris tel quel : son régime de
        // travail décide de ce qui compte, et un identifiant venu du navigateur ne doit
        // pas traverser les cabinets. À défaut, l'invité connecté.
        $agent = $this->agentDuCabinet($args['agent'] ?? null) ?? $this->getInvite();

        // La durée à conserver : celle de la période d'avant. Illisible ou incohérente
        // (fin avant début), on retombe sur la durée usuelle plutôt que de ne rien rendre.
        $ancienDebut = $this->dateOuNull($args['ancienDebut'] ?? null);
        $ancienneFin = $this->dateOuNull($args['ancienneFin'] ?? null);

        $duree = ($ancienDebut !== null && $ancienneFin !== null && $ancienneFin >= $ancienDebut)
            ? $this->calculateurJours->calculer($agent, $ancienDebut, $ancienneFin)
            : 0.0;

        if ($duree <= 0.0) {
            $duree = (float) PeriodeParDefaut::DUREE_JOURS_OUVRABLES;
        }

        $fin = $this->periodeParDefaut->finPourDuree($agent, $nouveauDebut, $duree);

        return $this->json([
            'fin' => $fin->format('Y-m-d'),
            'jours' => $this->calculateurJours->calculer($agent, $nouveauDebut, $fin),
        ]);
    }

    /** Une date ISO du navigateur, ou null si elle est absente ou illisible. */
    private function dateOuNull(mixed $brut): ?\DateTimeImmutable
    {
        if (!is_string($brut) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $brut)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($brut);
        } catch (\Throwable) {
            return null;
        }
    }

    /** L'agent désigné, s'il appartient bien à l'espace de travail courant. */
    private function agentDuCabinet(mixed $id): ?Invite
    {
        if (!is_numeric($id)) {
            return null;
        }

        $agent = $this->inviteRepository->find((int) $id);

        return $agent !== null && $agent->getEntreprise()?->getId() === $this->getEntreprise()->getId()
            ? $agent
            : null;
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            DemandeConge::class,
            DemandeCongeType::class,
            fn (DemandeConge $demande) => $this->workflow->rafraichirLeDecompteSiNonDecide($demande),
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(DemandeConge $demandeConge): Response
    {
        if (!$this->policy->peutVoir($this->getInvite(), $demandeConge)) {
            return $this->accessDeniedJson();
        }

        return $this->handleDeleteApi($demandeConge);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = 'generic'): Response
    {
        $demande = $this->demandeCongeRepository->find($id);
        if ($demande !== null && !$this->policy->peutVoir($this->getInvite(), $demande)) {
            return $this->accessDeniedJson();
        }

        return $this->handleCollectionApiRequest($id, $collectionName, DemandeConge::class, $usage);
    }

    // ─────────────────────────── LES GESTES DU CIRCUIT ─────────────────────────────

    /**
     * Boîte de décision, ouverte depuis la barre d'outils ou le clic droit.
     *
     * Le geste voyage dans l'URL (`?geste=soumettre|approuver|refuser|annuler`) : le
     * picker n'a rien à deviner. L'aperçu vient du SERVEUR — décompte, solde avant et
     * après —, du même calcul que celui de l'écran et des mails.
     */
    #[Route('/api/decision-picker/{id}', name: 'api.decision_picker', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function decisionPicker(DemandeConge $demandeConge, Request $request): Response
    {
        $contexte = $this->contexteDuGeste($demandeConge, $request);
        if ($contexte instanceof JsonResponse) {
            return $contexte;
        }

        return $this->render('components/conge/_decision_picker.html.twig', [
            'geste' => $contexte,
            'demande' => $demandeConge,
            'apercu' => $this->apercuDuGeste($demandeConge, $contexte),
        ]);
    }

    /**
     * ENREGISTRE le geste. Toutes les règles — nul ne valide sa propre demande, motif
     * obligatoire après le début, écriture du mouvement de compteur, ligne d'historique —
     * vivent dans DemandeCongeWorkflow, partagé avec l'assistant.
     */
    #[Route('/api/decision/{id}', name: 'api.decision_executer', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function decisionExecuter(DemandeConge $demandeConge, Request $request): JsonResponse
    {
        $contexte = $this->contexteDuGeste($demandeConge, $request);
        if ($contexte instanceof JsonResponse) {
            return $contexte;
        }
        $geste = $contexte;

        $args = json_decode($request->getContent() ?: '{}', true);
        $commentaire = is_array($args) ? trim((string) ($args['commentaire'] ?? '')) : '';
        $acteur = $this->getInvite();

        try {
            match ($geste) {
                'soumettre' => $this->workflow->soumettre($demandeConge, $acteur),
                'approuver' => $this->workflow->decider($demandeConge, $acteur, DemandeCongeWorkflow::DECISION_APPROUVER, $commentaire ?: null),
                'refuser' => $this->workflow->decider($demandeConge, $acteur, DemandeCongeWorkflow::DECISION_REFUSER, $commentaire ?: null),
                'annuler' => $this->workflow->annuler($demandeConge, $acteur, $commentaire ?: null),
            };
        } catch (CongeTransitionException $e) {
            return $this->json(
                ['success' => false, 'message' => implode(' ', $e->violations), 'errors' => $e->violations],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Un seul flush pour la demande, sa ligne d'historique et, le cas échéant, son
        // mouvement de compteur : les trois vivent ou meurent ensemble.
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => $this->messageDuGeste($geste, $demandeConge),
        ]);
    }

    // ─────────────────────────── LE CALENDRIER D'ÉQUIPE ────────────────────────────

    /**
     * La grille mensuelle des absences, ouverte depuis la barre d'outils de la rubrique.
     *
     * ── POURQUOI PAS UNE RUBRIQUE À PART ────────────────────────────────────────────
     * Le calendrier illustre les demandes ; l'en séparer aurait allongé le menu du groupe
     * Administration pour éloigner deux écrans qui se lisent ensemble. Il s'ouvre donc là
     * où l'on regarde déjà les congés.
     *
     * ── CE QU'ON VOIT DÉPEND DE QUI REGARDE ─────────────────────────────────────────
     * Un valideur voit le cabinet ; un collaborateur ordinaire, seulement son équipe. Les
     * absences des autres sont des données personnelles, et la grille n'est pas une porte
     * dérobée vers elles.
     */
    #[Route('/api/calendrier', name: 'api.calendrier', methods: ['GET'])]
    public function calendrier(Request $request): Response
    {
        if (!$this->mayAccessEntity(DemandeConge::class, Invite::ACCESS_LECTURE)) {
            return $this->accessDeniedJson();
        }

        $acteur = $this->getInvite();
        $limiterALEquipe = !$this->policy->estValideur($acteur);

        $aujourdhui = new \DateTimeImmutable('today');
        $annee = (int) $request->query->get('annee', $aujourdhui->format('Y'));
        $mois = (int) $request->query->get('mois', $aujourdhui->format('n'));

        // Bornes défensives : un mois 13 ou une année 0 venus de l'URL produiraient une
        // date invalide, donc une erreur là où l'utilisateur attend un calendrier.
        if ($mois < 1 || $mois > 12) {
            $mois = (int) $aujourdhui->format('n');
        }
        if ($annee < 2000 || $annee > 2100) {
            $annee = (int) $aujourdhui->format('Y');
        }

        $grille = $this->calendrier->grille(
            $this->getEntreprise(),
            $annee,
            $mois,
            $acteur,
            $limiterALEquipe,
        );

        $contexte = ['grille' => $grille, 'perimetre' => $limiterALEquipe ? 'equipe' : 'cabinet'];

        // Navigation : on ne renvoie QUE la grille. Recharger la boîte entière ferait
        // perdre le focus et rejouerait l'animation d'ouverture à chaque clic de flèche.
        if ($request->query->has('annee') || $request->query->has('mois')) {
            return $this->json([
                'libelle' => $grille['libelle'],
                'html' => $this->renderView('components/conge/_calendrier_grille.html.twig', $contexte),
            ]);
        }

        return $this->render('components/conge/_calendrier.html.twig', $contexte);
    }

    /**
     * Le geste demandé, une fois vérifié qu'il est possible ici et maintenant.
     *
     * Les quatre gestes n'ont pas les mêmes conditions ; on les demande au workflow, qui
     * les détient, plutôt que de les réécrire ici en plus petit.
     */
    private function contexteDuGeste(DemandeConge $demande, Request $request): string|JsonResponse
    {
        $acteur = $this->getInvite();

        if ($demande->getEntreprise()?->getId() !== $this->getEntreprise()->getId()) {
            return $this->json(['message' => 'Demande introuvable dans cet espace de travail.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->policy->peutVoir($acteur, $demande)) {
            return $this->accessDeniedJson();
        }

        $geste = (string) $request->query->get('geste', 'approuver');
        if (!in_array($geste, ['soumettre', 'approuver', 'refuser', 'annuler'], true)) {
            return $this->json(['message' => 'Geste inconnu.'], Response::HTTP_NOT_FOUND);
        }

        // Le picker s'OUVRE même si le geste est momentanément impossible : il affiche
        // alors la raison, ce qui vaut mieux qu'un refus brut sans explication. C'est
        // l'exécution, elle, qui refuse — via le workflow, source unique des règles.
        return $geste;
    }

    /**
     * Ce que le geste changera : décompte, solde avant et après, et ce qui l'empêche
     * encore. N'écrit rien.
     *
     * @return array{pret: bool, violations: string[], jours: float, solde: ?\App\Service\Conge\SoldeConge, commentaireRequis: bool}
     */
    private function apercuDuGeste(DemandeConge $demande, string $geste): array
    {
        $acteur = $this->getInvite();
        $agent = $demande->getAgent();

        $violations = match ($geste) {
            'soumettre' => $this->workflow->verifierSoumission($demande, $acteur),
            'approuver' => $this->workflow->verifierDecision($demande, $acteur, DemandeCongeWorkflow::DECISION_APPROUVER),
            'refuser' => $this->workflow->verifierDecision($demande, $acteur, DemandeCongeWorkflow::DECISION_REFUSER),
            'annuler' => $this->workflow->verifierAnnulation($demande, $acteur, 'aperçu'),
            default => ['Geste inconnu.'],
        };

        return [
            'pret' => $violations === [],
            'violations' => $violations,
            'jours' => $demande->nbJoursFloat(),
            'solde' => $agent !== null ? $this->calculateurSolde->pour($agent, $demande->getExercice()) : null,
            // Une absence déjà commencée ne s'annule pas sans explication.
            'commentaireRequis' => $geste === 'annuler' && $demande->aCommence(new \DateTimeImmutable('today')),
        ];
    }

    private function messageDuGeste(string $geste, DemandeConge $demande): string
    {
        $agent = $demande->getAgent()?->getNom() ?? 'Le collaborateur';

        return match ($geste) {
            'soumettre' => $demande->getStatut() === DemandeConge::STATUT_APPROUVEE
                // Le demandeur était le seul valideur : le dire, plutôt que de laisser
                // croire à une validation par un tiers.
                ? 'Demande enregistrée et auto-approuvée : vous êtes le seul valideur du cabinet.'
                : 'Demande soumise. Les valideurs en ont été informés.',
            'approuver' => sprintf('Congé approuvé. %s en a été informé.', $agent),
            'refuser' => sprintf('Congé refusé. %s en a été informé.', $agent),
            'annuler' => 'Congé annulé. Le solde a été recrédité.',
            default => 'Décision enregistrée.',
        };
    }
}
