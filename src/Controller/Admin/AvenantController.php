<?php

namespace App\Controller\Admin;

use DateTimeImmutable;
use App\Entity\Avenant;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Form\AvenantType;
use App\Constantes\Constante;
use App\Repository\InviteRepository;
use App\Repository\AvenantRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use App\Services\CanvasBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
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

        // Dissociation AVANT suppression : les deux relations Avenant↔Piste sont
        // indépendantes et unidirectionnelles. Piste::avenantDeBase est en
        // cascade:['remove'] — laissée telle quelle, la suppression de la piste
        // détruirait l'avenant de base. On coupe les deux liens pour ne supprimer
        // QUE la piste dérivée (l'avenant de base est conservé). Les nulls sont
        // persistés par le flush interne de handleDeleteApi (aucun flush ici si le
        // gating de suppression échoue).
        $avenant->setPisteDeRenouvellement(null);
        $piste->setAvenantDeBase(null);

        return $this->handleDeleteApi($piste);
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
