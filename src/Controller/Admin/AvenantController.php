<?php

namespace App\Controller\Admin;

use DateTimeImmutable;
use App\Ai\Mouvement\MouvementAvenant;
use App\Ai\Mouvement\MouvementAvenantBuilder;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\MutationReferences;
use App\Ai\Scope\AiScope;
use App\Entity\Avenant;
use App\Entity\Cotation;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Form\AvenantType;
use App\Constantes\Constante;
use App\Service\Workspace\MutationException;
use App\Service\Workspace\WorkspaceMutationService;
use App\Token\InsufficientTokensException;
use App\Repository\InviteRepository;
use App\Repository\AvenantRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use App\Services\Avenant\MarquageNonRenouvelableService;
use App\Services\CanvasBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Service\Workspace\LiensProteges;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/avenant", name: 'admin.avenant.')]
#[IsGranted('ROLE_USER')]
class AvenantController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private AvenantRepository $avenantRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private MouvementAvenantBuilder $mouvementBuilder,
        private WorkspaceMutationService $mutationService,
        private MarquageNonRenouvelableService $marquageNonRenouvelable,
        CanvasBuilder $canvasBuilder // Inject CanvasBuilder without property promotion
    ) {
        // Assign the injected CanvasBuilder to the property declared in the trait
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Avenant::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Avenant::class);
    }

    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        return $this->renderViewOrListComponent(Avenant::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Avenant $avenant, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Avenant::class,
            AvenantType::class,
            $avenant,
            function (Avenant $avenant, Invite $invite) {
                $avenant->setStartingAt(new DateTimeImmutable("now"));
                $avenant->setEndingAt(new DateTimeImmutable("+1 year"));
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            Avenant::class,
            AvenantType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Avenant $avenant): Response
    {
        return $this->handleDeleteApi($avenant);
    }

    /**
     * Contexte « piste dérivée » d'un avenant, pour les actions spéciales de la
     * rubrique (miroir de InviteController::getPortefeuilleContext). Répond selon
     * l'état réel : mode 'edit' (piste dérivée existante) ou 'create' (canevas d'une
     * piste vierge). En création, le front rouvre le get-form de la Piste avec
     * ?idAvenant=%id% : PisteController préremplit le contexte (client/risque/
     * partenaires) et, au submit, lie la piste à l'avenant + reconduit le partage.
     */
    #[Route('/api/get-piste-derivee-context/{id}', name: 'api.get_piste_derivee_context', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getPisteDeriveeContext(Avenant $avenant, Request $request): JsonResponse
    {
        $piste = $avenant->getPisteDeRenouvellement();

        // Ouvrir le formulaire = mutation à venir : Modification si une piste dérivée
        // existe, Écriture s'il s'agit d'en créer une (fail-closed).
        $level = $piste ? Invite::ACCESS_MODIFICATION : Invite::ACCESS_ECRITURE;
        if (!$this->mayAccessEntity(Piste::class, $level)) {
            return $this->accessDeniedJson();
        }
        // Scoping : l'avenant doit appartenir à l'espace de travail courant.
        if ($avenant->getEntreprise()?->getId() !== $this->getEntreprise()->getId()) {
            return $this->json(['message' => "Avenant introuvable dans cet espace de travail."], Response::HTTP_NOT_FOUND);
        }

        $idEntreprise = (int) $request->query->get('idEntreprise', 0);

        return $this->json([
            'mode'       => $piste ? 'edit' : 'create',
            'avenantId'  => $avenant->getId(),
            'piste'      => $piste ? $this->serializer->normalize($piste, null, ['groups' => ['list:read']]) : null,
            'formCanvas' => $this->canvasBuilder->getEntityFormCanvas($piste ?? new Piste(), $idEntreprise),
        ]);
    }

    /**
     * Supprime la piste dérivée d'un avenant ({id} = id de l'AVENANT de base).
     * L'avenant de base est conservé (la relation pisteDeRenouvellement se dissocie).
     * Le gating Suppression + le métrage sont délégués à handleDeleteApi (trait).
     */
    #[Route('/api/delete-piste-derivee/{id}', name: 'api.delete_piste_derivee', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function deletePisteDerivee(Avenant $avenant): JsonResponse
    {
        // Scoping : l'avenant doit appartenir à l'espace de travail courant.
        if ($avenant->getEntreprise()?->getId() !== $this->getEntreprise()->getId()) {
            return $this->json(['message' => "Avenant introuvable dans cet espace de travail."], Response::HTTP_NOT_FOUND);
        }

        $piste = $avenant->getPisteDeRenouvellement();
        if ($piste === null) {
            return $this->json(['message' => "Cet avenant n'a aucune piste dérivée."], Response::HTTP_NOT_FOUND);
        }

        // Dissociation AVANT suppression : Piste::avenantDeBase est en
        // cascade:['remove'] — laissée telle quelle, la suppression de la piste
        // détruirait l'avenant de base. La règle (et les DEUX sens du lien, qui sont
        // indépendants) vit dans LiensProteges, partagée avec le moteur de mutation
        // de l'assistant : elle était écrite ici seule, et tout autre chemin de
        // suppression retombait dans le piège. Les nulls sont persistés par le flush
        // interne de handleDeleteApi (aucun flush ici si le gating échoue).
        LiensProteges::dissocier($piste);

        return $this->handleDeleteApi($piste);
    }

    // ─────────────────── Mouvements de police (renouvellement, prorogation, …) ───────────────────

    /** Durée proposée par défaut dans la boîte de prorogation (jours). */
    private const PROROGATION_JOURS_DEFAUT = 30;

    /**
     * Boîte de MOUVEMENT d'une police (renouvellement, prorogation, annulation,
     * résiliation) ouverte depuis la liste des avenants — barre d'outils ou clic
     * droit. Rend le fragment HTML du picker autonome, que le cerveau insère.
     *
     * PARITÉ AVEC L'ASSISTANT : l'écran et Ket appellent le MÊME
     * MouvementAvenantBuilder puis le MÊME WorkspaceMutationService. Ce qui change
     * n'est que la façon de recueillir la seule information variable (durée ou date
     * d'effet) : un formulaire ici, une phrase là-bas.
     */
    #[Route('/api/mouvement-picker/{mouvement}/{id}', name: 'api.mouvement_picker', requirements: ['id' => Requirement::DIGITS, 'mouvement' => '[a-z]+'], methods: ['GET'])]
    public function mouvementPicker(string $mouvement, Avenant $avenant): Response
    {
        $contexte = $this->contexteMouvement($mouvement, $avenant, Invite::ACCESS_ECRITURE);
        if ($contexte instanceof JsonResponse) {
            return $contexte;
        }
        [$type, $scope] = $contexte;

        // Valeurs proposées dans les champs. L'aperçu est calculé AVEC elles : sans
        // cela, la boîte s'ouvrait sur « renseignez l'information demandée » alors
        // que le champ était déjà rempli, puis se corrigeait toute seule.
        $defauts = match (true) {
            $type === MouvementAvenant::Prorogation => ['dureeJours' => self::PROROGATION_JOURS_DEFAUT],
            $type->exigeDate()                      => ['dateEffet' => (new DateTimeImmutable())->format('Y-m-d')],
            default                                 => [],
        };

        return $this->render('components/avenant/_mouvement_picker.html.twig', [
            'mouvement'   => $type->value,
            'libelle'     => $type->libelle(),
            'exigeDate'   => $type->exigeDate(),
            'avenant'     => $avenant,
            'dureeDefaut' => self::PROROGATION_JOURS_DEFAUT,
            'dateDefaut'  => $defauts['dateEffet'] ?? null,
            'apercu'      => $this->apercuMouvement($type, $avenant, $defauts, $scope),
        ]);
    }

    /**
     * APERÇU d'un mouvement : ce que le plan écrira, aux paramètres saisis. Rafraîchi
     * à chaque changement de durée ou de date dans le picker, pour que l'utilisateur
     * VOIE la période dérivée et la prime au prorata avant de valider (Nielsen 1).
     * N'écrit rien.
     */
    #[Route('/api/mouvement-apercu/{mouvement}/{id}', name: 'api.mouvement_apercu', requirements: ['id' => Requirement::DIGITS, 'mouvement' => '[a-z]+'], methods: ['GET'])]
    public function mouvementApercu(string $mouvement, Avenant $avenant, Request $request): JsonResponse
    {
        $contexte = $this->contexteMouvement($mouvement, $avenant, Invite::ACCESS_ECRITURE);
        if ($contexte instanceof JsonResponse) {
            return $contexte;
        }
        [$type, $scope] = $contexte;
        $apercu = $this->apercuMouvement($type, $avenant, $request->query->all(), $scope);

        // Le fragment est rendu par le SERVEUR (même partiel qu'à l'ouverture) : le
        // JS ne fabrique aucun libellé, il substitue le bloc. Un seul markup à maintenir.
        return $this->json([
            'pret' => $apercu['pret'] ?? false,
            'html' => $this->renderView('components/avenant/_mouvement_apercu.html.twig', ['apercu' => $apercu]),
        ]);
    }

    /**
     * EXÉCUTE le mouvement : même plan que celui de l'assistant, joué dans UNE
     * transaction (une étape en échec annule tout — jamais de police à moitié
     * renouvelée). Le métrage des tokens est celui du moteur (commitWrite).
     */
    #[Route('/api/mouvement/{mouvement}/{id}', name: 'api.mouvement_executer', requirements: ['id' => Requirement::DIGITS, 'mouvement' => '[a-z]+'], methods: ['POST'])]
    public function mouvementExecuter(string $mouvement, Avenant $avenant, Request $request): JsonResponse
    {
        $contexte = $this->contexteMouvement($mouvement, $avenant, Invite::ACCESS_ECRITURE);
        if ($contexte instanceof JsonResponse) {
            return $contexte;
        }
        [$type, $scope] = $contexte;

        $args = json_decode($request->getContent() ?: '{}', true);
        $decalque = $this->mouvementBuilder->construire($type, $avenant, is_array($args) ? $args : [], $scope);

        if (isset($decalque['bloquant'])) {
            return $this->json(['success' => false, 'message' => $decalque['bloquant']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (isset($decalque['aDemander'])) {
            return $this->json([
                'success' => false,
                'message' => $decalque['aDemander'][0]['question'] ?? 'Information manquante.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $acteur = $this->getUser();
        try {
            $this->em->wrapInTransaction(function () use ($decalque, $scope, $acteur): void {
                // Registre PARTAGÉ : la cotation renvoie à la piste créée juste avant
                // (« @mouvement »), l'avenant à la cotation — d'où l'ordre imposé.
                $refs = MutationReferences::live();
                foreach (MutationPlan::fromArray($decalque['operations'])->operationsOrdonnees() as $op) {
                    $this->mutationService->executer($op, $scope, $acteur, $refs);
                }
            });
        } catch (InsufficientTokensException) {
            return $this->json([
                'success' => false,
                'message' => 'Solde de tokens épuisé : aucune modification n’a été conservée.',
                'buyUrl'  => $this->generateUrl('admin.token.buy'),
            ], Response::HTTP_PAYMENT_REQUIRED);
        } catch (MutationException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'success' => true,
            'message' => sprintf('%s de la police « %s » enregistré%s.',
                $type->libelle(),
                (string) $avenant->getReferencePolice(),
                $type === MouvementAvenant::Resiliation || $type === MouvementAvenant::Annulation ? 'e' : '',
            ),
        ]);
    }

    /**
     * Gardes communes aux trois endpoints de mouvement : mouvement connu, droits
     * d'écriture (fail-closed), avenant de l'espace de travail courant, police pas
     * déjà mouvementée — l'idempotence exacte de l'outil de l'assistant.
     *
     * @return array{0: MouvementAvenant, 1: AiScope}|JsonResponse
     */
    private function contexteMouvement(string $mouvement, Avenant $avenant, int $niveau): array|JsonResponse
    {
        $type = MouvementAvenant::depuis($mouvement);
        if ($type === null) {
            return $this->json(['message' => 'Mouvement inconnu.'], Response::HTTP_NOT_FOUND);
        }
        // Un mouvement crée une piste, une cotation et un avenant : le droit d'écriture
        // est exigé sur les trois, comme le ferait chaque formulaire pris séparément.
        foreach ([Piste::class, Cotation::class, Avenant::class] as $classe) {
            if (!$this->mayAccessEntity($classe, $niveau)) {
                return $this->accessDeniedJson();
            }
        }
        if ($avenant->getEntreprise()?->getId() !== $this->getEntreprise()->getId()) {
            return $this->json(['message' => 'Avenant introuvable dans cet espace de travail.'], Response::HTTP_NOT_FOUND);
        }
        if ($avenant->getPisteDeRenouvellement() !== null) {
            return $this->json([
                'message' => 'Cette police porte déjà une opportunité dérivée : un mouvement y a déjà été enregistré.',
            ], Response::HTTP_CONFLICT);
        }

        return [$type, new AiScope($this->getEntreprise(), $this->getInvite())];
    }

    /**
     * Aperçu lisible du décalque : période, défauts appliqués, écarts, ce qui est
     * reconduit — exactement ce que l'assistant énonce dans sa réponse.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function apercuMouvement(MouvementAvenant $type, Avenant $avenant, array $args, AiScope $scope): array
    {
        $decalque = $this->mouvementBuilder->construire($type, $avenant, $args, $scope);

        if (isset($decalque['bloquant'])) {
            return ['pret' => false, 'bloquant' => $decalque['bloquant'], 'source' => []];
        }
        if (isset($decalque['aDemander'])) {
            // Le picker n'a pas encore de date : on annonce la question plutôt qu'un
            // aperçu vide, et le bouton de validation reste inactif. L'identité de la
            // police, elle, est déjà connue et accompagne la réponse.
            return [
                'pret'     => false,
                'question' => $decalque['aDemander'][0]['question'] ?? null,
                'source'   => $decalque['source'] ?? [],
            ];
        }

        $nouvelAvenant = [];
        foreach ($decalque['operations'] as $op) {
            if ($op['entite'] === 'Avenant' && $op['op'] === 'create') {
                $nouvelAvenant = $op['champs'];
            }
        }

        return [
            'pret'           => true,
            'debut'          => substr((string) ($nouvelAvenant['startingAt'] ?? ''), 0, 10),
            'fin'            => substr((string) ($nouvelAvenant['endingAt'] ?? ''), 0, 10),
            'numero'         => $nouvelAvenant['numero'] ?? null,
            'defauts'        => $decalque['defauts'],
            'ecarts'         => $decalque['ecarts'],
            'reconduit'      => $decalque['reconduit'],
            'avertissements' => $decalque['avertissements'],
            'source'         => $decalque['source'],
        ];
    }

    // ─────────────────── Décision : « cette police n'est pas à renouveler » ───────────────────

    /** Les trois gestes du marquage. Le motif n'est exigé que pour les deux premiers. */
    private const MODES_NON_RENOUVELABLE = ['marquer', 'motif', 'lever'];

    /**
     * Boîte « signaler / corriger / rétablir », ouverte depuis la barre d'outils, le clic
     * droit ou la fiche d'un avenant — et depuis le clic droit du widget Renouvellements.
     *
     * AUCUNE CONDITION DE DATE, ici ni ailleurs : l'information arrive quand elle arrive
     * (le client annonce en mars ce qui se produira en décembre), et c'est en pleine
     * couverture que la note a le plus de valeur pour celui qui rouvrira le dossier.
     */
    #[Route('/api/non-renouvelable-picker/{id}', name: 'api.non_renouvelable_picker', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function nonRenouvelablePicker(Avenant $avenant, Request $request): Response
    {
        $contexte = $this->contexteNonRenouvelable($avenant, $request);
        if ($contexte instanceof JsonResponse) {
            return $contexte;
        }
        $mode = $contexte;

        return $this->render('components/avenant/_non_renouvelable_picker.html.twig', [
            'mode'    => $mode,
            'avenant' => $avenant,
            'apercu'  => $this->apercuNonRenouvelable($avenant, $mode, $avenant->getNonRenouvelableMotif()),
        ]);
    }

    /**
     * APERÇU : ce que la validation écrira, et surtout CE QUI RESTE À RECOUVRER malgré la
     * décision. Rafraîchi à la saisie du motif. N'écrit rien.
     */
    #[Route('/api/non-renouvelable-apercu/{id}', name: 'api.non_renouvelable_apercu', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function nonRenouvelableApercu(Avenant $avenant, Request $request): JsonResponse
    {
        $contexte = $this->contexteNonRenouvelable($avenant, $request);
        if ($contexte instanceof JsonResponse) {
            return $contexte;
        }
        $apercu = $this->apercuNonRenouvelable($avenant, $contexte, (string) $request->query->get('motif', ''));

        // Fragment rendu par le SERVEUR, comme pour les mouvements : le JS ne fabrique aucun
        // libellé, il substitue le bloc. Écran et assistante annoncent donc le même texte.
        return $this->json([
            'pret' => $apercu['pret'],
            'html' => $this->renderView('components/avenant/_non_renouvelable_apercu.html.twig', ['apercu' => $apercu]),
        ]);
    }

    /**
     * ENREGISTRE la décision. Trois gestes, un seul endpoint : marquer, corriger le motif,
     * lever. Toutes les règles (motif obligatoire, horodatage, conservation de la trace)
     * vivent dans MarquageNonRenouvelableService, partagé avec l'outil de l'assistante.
     */
    #[Route('/api/non-renouvelable/{id}', name: 'api.non_renouvelable_executer', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function nonRenouvelableExecuter(Avenant $avenant, Request $request): JsonResponse
    {
        $contexte = $this->contexteNonRenouvelable($avenant, $request);
        if ($contexte instanceof JsonResponse) {
            return $contexte;
        }
        $mode = $contexte;

        $args  = json_decode($request->getContent() ?: '{}', true);
        $motif = is_array($args) ? (string) ($args['motif'] ?? '') : '';

        try {
            match ($mode) {
                'marquer' => $this->marquageNonRenouvelable->marquer($avenant, $motif, $this->getInvite()),
                'motif'   => $this->marquageNonRenouvelable->modifierMotif($avenant, $motif),
                'lever'   => $this->marquageNonRenouvelable->lever($avenant),
            };
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->flush();

        $reference = (string) $avenant->getReferencePolice();

        return $this->json([
            'success' => true,
            'message' => match ($mode) {
                'marquer' => sprintf('La police « %s » est signalée comme non renouvelable.', $reference),
                'motif'   => sprintf('Le motif de non-renouvellement de la police « %s » a été mis à jour.', $reference),
                'lever'   => sprintf('La police « %s » est de nouveau suivie pour renouvellement.', $reference),
            },
        ]);
    }

    /**
     * Gardes communes aux trois endpoints du marquage : mode connu, droit de MODIFICATION
     * sur l'avenant (fail-closed), avenant de l'espace de travail courant, et cohérence du
     * geste avec l'état réel de la police.
     *
     * Volontairement PLUS ÉTROIT que contexteMouvement() : aucun objet n'est créé ici, donc
     * le droit d'écriture sur Piste et Cotation n'a pas lieu d'être exigé. Et pas de garde
     * sur endingAt : marquer une police en cours est le cas nominal.
     *
     * @return string|JsonResponse le mode validé
     */
    private function contexteNonRenouvelable(Avenant $avenant, Request $request): string|JsonResponse
    {
        if (!$this->mayAccessEntity(Avenant::class, Invite::ACCESS_MODIFICATION)) {
            return $this->accessDeniedJson();
        }
        if ($avenant->getEntreprise()?->getId() !== $this->getEntreprise()->getId()) {
            return $this->json(['message' => 'Avenant introuvable dans cet espace de travail.'], Response::HTTP_NOT_FOUND);
        }

        $mode = (string) $request->query->get('mode', 'marquer');
        if (!in_array($mode, self::MODES_NON_RENOUVELABLE, true)) {
            return $this->json(['message' => 'Geste inconnu.'], Response::HTTP_NOT_FOUND);
        }

        // L'état de la police décide du geste possible : corriger ou lever suppose un
        // marquage, marquer suppose son absence. Sans ce contrôle, deux utilisateurs
        // agissant en même temps pourraient se contredire en silence.
        $marquee = $avenant->isNonRenouvelable();
        if ($mode === 'marquer' && $marquee) {
            return $this->json(['message' => 'Cette police est déjà signalée comme non renouvelable.'], Response::HTTP_CONFLICT);
        }
        if ($mode !== 'marquer' && !$marquee) {
            return $this->json(['message' => "Cette police n'est pas signalée comme non renouvelable."], Response::HTTP_CONFLICT);
        }

        return $mode;
    }

    /**
     * Aperçu lisible du geste : identité de la police, ce que la validation changera, et
     * CE QUI RESTE DÛ.
     *
     * Les avertissements de recouvrement sont le cœur de cet aperçu : sortir une police du
     * pipeline de renouvellement ne la sort d'AUCUN suivi d'encaissement, et laisser croire
     * l'inverse ferait perdre de l'argent déjà gagné.
     *
     * @return array<string, mixed>
     */
    private function apercuNonRenouvelable(Avenant $avenant, string $mode, ?string $motif): array
    {
        $piste = $avenant->getCotation()?->getPiste();
        $fin   = $avenant->getEndingAt();

        return [
            'pret'   => $mode === 'lever' || trim((string) $motif) !== '',
            'mode'   => $mode,
            'motif'  => trim((string) $motif),
            'source' => [
                'client'   => $piste?->getClient()?->getNom(),
                'risque'   => $piste?->getRisque()?->getNomComplet(),
                'assureur' => $avenant->getCotation()?->getAssureur()?->getNom(),
                'periode'  => $avenant->getStartingAt() !== null && $fin !== null
                    ? $avenant->getStartingAt()->format('d/m/Y') . ' → ' . $fin->format('d/m/Y')
                    : null,
            ],
            // La couverture court-elle encore ? C'est ce qui distingue ce marquage d'une
            // résiliation, et l'utilisateur doit le lire noir sur blanc avant de valider.
            'couvertureEnCours' => $fin !== null && $fin >= new DateTimeImmutable('today'),
            'finCouverture'     => $fin?->format('d/m/Y'),
            'decideeLe'         => $avenant->getNonRenouvelableLe()?->format('d/m/Y'),
            'decideePar'        => $avenant->getNonRenouvelablePar()?->getNom(),
            'motifActuel'       => $avenant->getNonRenouvelableMotif(),
            'avertissements'    => $this->marquageNonRenouvelable->avertissements($avenant),
        ];
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request)
    {
        return $this->renderViewOrListComponent(Avenant::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Avenant::class, $usage);
    }
}
