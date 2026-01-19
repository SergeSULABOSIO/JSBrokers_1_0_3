<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Entity\ModelePieceSinistre;
use App\Form\ModelePieceSinistreType;
use App\Services\Canvas\CalculationProvider;
use App\Services\CanvasBuilder;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use App\Repository\ModelePieceSinistreRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/modelepiecesinistre", name: 'admin.modelepiecesinistre.')]
#[IsGranted('ROLE_USER')]
class ModelePieceSinistreController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ModelePieceSinistreRepository $modelePieceSinistreRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private CanvasBuilder $canvasBuilder,
        private CalculationProvider $calculationProvider
    ) {}

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(ModelePieceSinistre::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(ModelePieceSinistre::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(ModelePieceSinistre::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?ModelePieceSinistre $modele, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            ModelePieceSinistre::class,
            ModelePieceSinistreType::class,
            $modele,
            function (ModelePieceSinistre $modele, Invite $invite) {
                $modele->setEntreprise($invite->getEntreprise());
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            ModelePieceSinistre::class,
            ModelePieceSinistreType::class
        );
        $data = $request->request->all();
        $files = $request->files->all();
        $submittedData = array_merge($data, $files);

        $idEntreprise = $data['idEntreprise'] ?? null;
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        if (!$entreprise) {
            return $this->json(['message' => 'Contexte de l\'entreprise non trouvé.'], Response::HTTP_BAD_REQUEST);
        }

        $entity = isset($data['id']) && $data['id']
            ? $this->modelePieceSinistreRepository->find($data['id'])
            : new ModelePieceSinistre();

        // Association de l'entreprise AVANT la création du formulaire pour les nouvelles entités
        if (!$entity->getId()) {
            $entity->setEntreprise($entreprise);
        }

        $form = $this->createForm(ModelePieceSinistreType::class, $entity);
        $form->submit($submittedData, false);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($entity);
            $this->em->flush();

            $jsonEntity = $this->serializer->serialize($entity, 'json', ['groups' => 'list:read']);
            return $this->json(['message' => 'Enregistrée avec succès!', 'entity' => json_decode($jsonEntity)]);
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[$error->getOrigin()->getName()][] = $error->getMessage();
        }

        return $this->json(['message' => 'Veuillez corriger les erreurs ci-dessous.', 'errors' => $errors], 422);
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(ModelePieceSinistre $modele): Response
    {
        return $this->handleDeleteApi($modele);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(ModelePieceSinistre::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, ModelePieceSinistre::class, $usage);
    }
}
