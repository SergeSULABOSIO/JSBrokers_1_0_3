<?php

namespace App\Controller\Admin;

use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Service\Avenant\DefautsDeSouscription;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Piste;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Constantes\Constante;
use App\Form\PisteType;
use App\Repository\PisteRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Services\CanvasBuilder;
use App\Services\Piste\NomDePisteDerivee;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/piste", name: 'admin.piste.')]
#[IsGranted('ROLE_USER')]
class PisteController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private PisteRepository $pisteRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private IndicatorCalculationHelper $indicatorHelper,
        CanvasBuilder $canvasBuilder
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Piste::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Piste::class);
    }

    #[Route('/test', name: 'test')]
    public function edit(): Response
    {
        return $this->render('components/piste/editor.html.twig', []);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        return $this->renderViewOrListComponent(Piste::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Piste $piste, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Piste::class,
            PisteType::class,
            $piste,
            function (Piste $piste, Invite $invite) use ($request) {
                $piste->setRenewalCondition(Piste::RENEWAL_CONDITION_RENEWABLE);

                // Pré-remplissage cross-selling (menu contextuel du SOA) : ?idClient=&idRisque=
                $idClient = (int) $request->query->get('idClient', 0);
                $idRisque = (int) $request->query->get('idRisque', 0);
                if ($idClient || $idRisque) {
                    $entreprise = $invite->getEntreprise();

                    $client = $idClient ? $this->em->find(Client::class, $idClient) : null;
                    if ($client && $client->getEntreprise() === $entreprise) {
                        $piste->setClient($client);
                    }

                    $risque = $idRisque ? $this->em->find(Risque::class, $idRisque) : null;
                    if ($risque && $risque->getEntreprise() === $entreprise) {
                        $piste->setRisque($risque);
                        if ($risque->getDescription() !== null) {
                            $piste->setDescriptionDuRisque($risque->getDescription());
                        }
                    }

                    $piste->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION);
                    $piste->setExercice((int) date('Y'));
                    if ($piste->getRisque() && $piste->getClient()) {
                        $piste->setNom(mb_substr($piste->getRisque()->getNomComplet() . ' — ' . $piste->getClient()->getNom(), 0, 255));
                    } elseif ($piste->getClient()) {
                        // OUVERT DEPUIS LA RUBRIQUE CLIENTS : le risque n'est pas encore
                        // choisi — le client ne dit pas ce qu'on va lui vendre. Le nom
                        // resterait donc vide, alors qu'il est obligatoire.
                        //
                        // On reprend la forme « <préfixe> — <nom> » du reste du projet, et
                        // le préfixe est le LIBELLÉ DU TYPE, pris à sa source unique. Vertu
                        // gratuite : `piste-name-sync`, déjà posé sur ce formulaire, ne
                        // réécrit un préfixe que s'il reconnaît un libellé de type — le nom
                        // suivra donc tout seul si le courtier bascule sur « Prorogation ».
                        $piste->setNom(mb_substr(
                            PisteType::TYPE_AVENANT_LABELS[Piste::AVENANT_SOUSCRIPTION] . ' — ' . $piste->getClient()->getNom(),
                            0,
                            255,
                        ));
                    }
                    return;
                }

                $idAvenant = (int) $request->query->get('idAvenant', 0);
                if (!$idAvenant) {
                    // PISTE NON DÉRIVÉE : c'est une SOUSCRIPTION, et rien d'autre.
                    //
                    // Les cinq autres types — incorporation, prorogation, annulation,
                    // renouvellement, résiliation — présupposent tous une police qui
                    // existe déjà. Or c'est précisément ce qu'est une piste DÉRIVÉE : une
                    // piste née d'un avenant de base, ouverte avec `?idAvenant=`. Sans ce
                    // paramètre, il n'y a pas de police antérieure à faire évoluer.
                    //
                    // Ce n'est donc pas deviner à la place du courtier : c'est le seul
                    // type cohérent dans ce contexte, et le laisser vide l'obligeait à
                    // cocher chaque fois la même case.
                    //
                    // ⚠ LA RÈGLE S'ARRÊTE AU FORMULAIRE. L'inventaire annoncé à
                    // l'assistant continue de ne déclarer AUCUN défaut sur ce champ
                    // (« un discriminant ne se devine pas ») : il le lit sur une entité
                    // NEUVE, sans contexte de dérivation, et n'a donc pas de quoi
                    // trancher. Le formulaire, lui, sait d'où il est ouvert.
                    $piste->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION);

                    return;
                }

                $avenant = $this->em->find(Avenant::class, $idAvenant);
                if (!$avenant) return;
                // La police vient de l'URL, et ses chiffres sont recopiés dans le formulaire :
                // celle d'un autre cabinet ne s'y prête pas.
                $this->appartenance()->exigerLeCabinetOuvert($avenant, 'Cet avenant');

                $src = $avenant->getCotation()?->getPiste();
                if ($src) {
                    $piste->setClient($src->getClient());
                    $piste->setRisque($src->getRisque());
                    if ($src->getDescriptionDuRisque() !== null) {
                        $piste->setDescriptionDuRisque($src->getDescriptionDuRisque());
                    }
                    $cotation = $avenant->getCotation();
                    $piste->setPrimePotentielle($this->indicatorHelper->getCotationMontantPrimePayableParClient($cotation) ?: $src->getPrimePotentielle());
                    $piste->setCommissionPotentielle($this->indicatorHelper->getCotationMontantCommissionTtc($cotation, -1, false) ?: $src->getCommissionPotentielle());
                    $piste->setRenewalCondition($src->getRenewalCondition() ?? Piste::RENEWAL_CONDITION_RENEWABLE);
                    $piste->setPartenaire($src->getPartenaire());
                    // Un seul préfixe, quel que soit le nombre de mouvements déjà subis.
                    $piste->setNom(NomDePisteDerivee::nommer(PisteType::TYPE_AVENANT_LABELS[Piste::AVENANT_RENOUVELLEMENT], $src->getNom()));
                }

                $piste->setTypeAvenant(Piste::AVENANT_RENOUVELLEMENT);
                $piste->setExercice((int) date('Y'));
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        // On récupère l'invité connecté de manière sécurisée côté serveur
        $inviteConnecte = $this->getInvite();

        return $this->handleFormSubmission(
            $request,
            Piste::class,
            PisteType::class,
            function (Piste $piste) use ($inviteConnecte, $request) {
                if (!$piste->getId()) {
                    $piste->setInvite($inviteConnecte);

                    $idAvenant = (int) $request->request->get('idAvenant', 0);
                    if ($idAvenant) {
                        $avenant = $this->em->find(Avenant::class, $idAvenant);
                        // Le cabinet OUVERT, et non celui de la piste : ce dernier se
                        // forgeait en postant `entreprise=` avant que le trait ne le refuse.
                        if ($avenant && $this->appartenance()->estDuCabinetOuvert($avenant)) {
                            // On persiste la piste AVANT de la référencer depuis l'avenant
                            // managé : Avenant::pisteDeRenouvellement n'a pas de cascade
                            // persist, et un flush anticipé (métrage de tokens) rejetterait
                            // sinon une piste « nouvelle » atteinte via cette relation.
                            $this->em->persist($piste);
                            $avenant->setPisteDeRenouvellement($piste);
                            $piste->setAvenantDeBase($avenant);

                            // LA RECONDUCTION DU PARTAGE N'EST PLUS APPELÉE ICI.
                            //
                            // Elle se déclenche seule, pour toute piste écrite avec un avenant
                            // de base — quelle que soit la porte ({@see
                            // \App\EventListener\ReconductionPartageListener}). Deux
                            // déclencheurs pour une règle, c'était deux endroits où elle
                            // pouvait diverger : un plan d'écriture générique de l'assistant
                            // qui posait `avenantDeBase` autrement ne reconduisait rien, et le
                            // partage d'une police disparaissait au renouvellement sans un mot.
                        }
                    }
                }
            }
        );
    }

    /**
     * L'ÉTAPE D'UNE PISTE, maintenant — ce que la carte compare, une fois la fiche fermée,
     * à l'étape qu'elle avait avant l'action (« Piste passée en … » / « La piste reste … »).
     */
    #[Route('/api/etape/{id}', name: 'api.etape', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function etapeApi(Piste $piste): JsonResponse
    {
        $this->appartenance()->exigerLeCabinetOuvert($piste, 'Cette piste');
        if (!$this->mayAccessEntity($piste, Invite::ACCESS_LECTURE)) {
            return $this->accessDeniedJson();
        }
        $this->canvasBuilder->loadAllCalculatedValues($piste);

        return $this->json(['etape' => $piste->etapePipe, 'libelle' => $piste->etapePipeLibelle]);
    }

    /**
     * L'ACTION PRIMAIRE D'UNE CARTE : quelle fiche ouvrir, sur quel onglet — décidé ICI, sur
     * des données fraîches, et non par l'écran qui a pu vieillir.
     *
     *  - coter        → création d'une cotation sous la piste ;
     *  - saisir-prime → la cotation, onglet Chargements ; plusieurs cotations : la piste,
     *                   onglet Cotations ;
     *  - souscrire    → création d'un AVENANT sous la cotation chiffrée, prérempli par
     *                   DefautsDeSouscription (comme Ket) ; plusieurs : la piste, onglet Cotations.
     *
     * ⚠ 409 SEULEMENT SI L'ACTION EST DEVENUE IMPOSSIBLE — piste clôturée, cotation déjà
     * souscrite, droit absent. Une carte périmée dont l'action reste valable s'ouvre
     * normalement : le rafraîchissement la rangera dans sa colonne. Un autre cabinet : 404.
     */
    #[Route('/api/action-pipe/{id}/{action}', name: 'api.action_pipe', requirements: ['id' => Requirement::DIGITS, 'action' => 'coter|saisir-prime|souscrire'], methods: ['GET'])]
    public function actionPipeApi(Piste $piste, string $action, DefautsDeSouscription $defautsDeSouscription): JsonResponse
    {
        $this->appartenance()->exigerLeCabinetOuvert($piste, 'Cette piste');
        if ($piste->isClosed()) {
            return $this->refusDuPipe('Cette piste est clôturée : elle ne se travaille plus depuis le pipe.');
        }

        $cotations = $piste->getCotations()->toArray();
        $peutModifierLaPiste = $this->mayAccessEntity($piste, Invite::ACCESS_MODIFICATION);
        $versLaPiste = fn () => $peutModifierLaPiste
            ? $this->ouvertureDuPipe($piste, Piste::class, null, 'cotations')
            : $this->refusDuPipe("Vous n'avez pas le droit de modifier cette piste.");

        if ($action === 'coter') {
            return $this->mayAccessEntity(Cotation::class, Invite::ACCESS_ECRITURE)
                ? $this->ouvertureDuPipe(null, Cotation::class, ['id' => $piste->getId(), 'fieldName' => 'piste'], null)
                : $this->refusDuPipe("Vous n'avez pas le droit d'ajouter une cotation.");
        }

        if ($action === 'saisir-prime') {
            if (!$this->mayAccessEntity(ChargementPourPrime::class, Invite::ACCESS_ECRITURE)) {
                return $this->refusDuPipe("Vous n'avez pas le droit de saisir une prime.");
            }
            if (count($cotations) === 1) {
                return $this->mayAccessEntity(Cotation::class, Invite::ACCESS_MODIFICATION)
                    ? $this->ouvertureDuPipe($cotations[0], Cotation::class, null, 'chargements')
                    : $versLaPiste();
            }

            return $versLaPiste();
        }

        // souscrire — la règle de l'enregistrement, jamais une copie : pas encore souscrite,
        // et chiffrée si le type de la piste l'exige (DefautsDeSouscription).
        $aSouscrire = array_values(array_filter($cotations, fn (Cotation $c) => $defautsDeSouscription->peutEtreSouscrite($c)));

        // UN SEUL CHEMIN PAR TYPE. Renouvellement, prorogation, annulation, résiliation : le
        // MOUVEMENT de la police de base, qui adopte cette piste et sa proposition (dates,
        // statut de la base, échéances). La boîte du mouvement s'ouvre à la place du formulaire.
        $mouvement = DefautsDeSouscription::mouvementDe($piste->getTypeAvenant());
        if ($mouvement !== null && count($aSouscrire) === 1) {
            $base = $defautsDeSouscription->policeDeBase($piste);
            if ($base === null) {
                return $this->refusDuPipe((string) $defautsDeSouscription->orientationVersLeMouvement($aSouscrire[0]));
            }

            return $this->json(['pickerUrl' => $this->generateUrl('admin.avenant.api.mouvement_picker', [
                'mouvement' => $mouvement->value,
                'id'        => $base->getId(),
                'cotation'  => $aSouscrire[0]->getId(),
            ])]);
        }
        if (count($aSouscrire) === 1) {
            return $this->mayAccessEntity(\App\Entity\Avenant::class, Invite::ACCESS_ECRITURE)
                ? $this->ouvertureDuPipe(null, \App\Entity\Avenant::class, ['id' => $aSouscrire[0]->getId(), 'fieldName' => 'cotation'], null)
                : $this->refusDuPipe("Vous n'avez pas le droit de souscrire une cotation.");
        }
        if ($aSouscrire === []) {
            $enAttente = array_values(array_filter($cotations, fn (Cotation $c) => !$defautsDeSouscription->dejaSouscrite($c)));
            // Une seule offre, et une raison de la refuser : on la dit, au lieu d'ouvrir un
            // formulaire que l'enregistrement rejetterait.
            if (count($enAttente) === 1 && ($motif = $defautsDeSouscription->refusDAvenant($enAttente[0])) !== null) {
                return $this->refusDuPipe($motif);
            }
            if ($enAttente === [] && $cotations !== []) {
                return $this->refusDuPipe('La cotation de cette piste est déjà souscrite.');
            }
        }

        return $versLaPiste();
    }

    /**
     * La fiche à ouvrir, dans la forme qu'attend openDialogBox — le canevas filtré par les
     * droits, comme celui de la barre d'outils.
     */
    private function ouvertureDuPipe(?object $entite, string $classe, ?array $parent, ?string $onglet): JsonResponse
    {
        $idEntreprise = (int) $this->getEntreprise()->getId();
        $canevas = $this->applyPermissionToFormCanvas(
            $this->canvasBuilder->getEntityFormCanvas($entite ?? new $classe(), $idEntreprise),
            $classe,
        );

        return $this->json([
            'entity' => $entite === null ? new \stdClass() : ['id' => $entite->getId()],
            'formCanvas' => $canevas,
            'isCreationMode' => $entite === null,
            'parentContext' => $parent,
            'ongletInitial' => $onglet,
        ]);
    }

    private function refusDuPipe(string $message): JsonResponse
    {
        return $this->json(['message' => $message], Response::HTTP_CONFLICT);
    }

    #[Route('/api/close/{id}', name: 'api.close', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function closeApi(Piste $piste, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('db-piste-close', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }
        // Le jeton CSRF prouve d'où vient la requête, pas à qui est la piste ; et clôturer
        // est une MODIFICATION, qui obéit au même droit que le formulaire.
        $this->appartenance()->exigerLeCabinetOuvert($piste, 'Cette piste');
        if (!$this->mayAccessEntity($piste, Invite::ACCESS_MODIFICATION)) {
            return $this->accessDeniedJson();
        }
        $piste->setClosed(true);
        $this->em->flush();
        return $this->json(['success' => true]);
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Piste $piste): Response
    {
        return $this->handleDeleteApi($piste);
    }

    // SUPPRIMÉ — « /api/set-not-renewable ». Cette route écrivait
    // Piste::RENEWAL_CONDITION_ONCE_OFF_AND_EXTENDABLE (« assurance temporaire non
    // renouvelable ») pour signaler une DÉCISION commerciale : elle réécrivait donc la
    // nature du contrat, ne conservait aucun motif, n'avait ni jeton CSRF, ni contrôle de
    // droits, ni scoping entreprise — et ne faisait en réalité sortir la police d'AUCUNE
    // vue, ce statut n'étant pas un sort scellé.
    // Remplacée par admin.avenant.api.non_renouvelable_executer, qui porte la décision sur
    // l'avenant lui-même, exige un motif, trace son auteur et reste réversible.

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(Piste::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Piste::class, $usage);
    }
}