<?php

namespace App\Controller\Admin;

use App\Constantes\Constante;
use App\Entity\Bordereau;
use App\Entity\Invite;
use App\Form\BordereauType;
use App\Repository\BordereauRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use DateTimeImmutable;
use App\Services\Canvas\CalculationProvider;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

#[Route("/admin/bordereau", name: 'admin.bordereau.')]
#[IsGranted('ROLE_USER')]
class BordereauController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private BordereauRepository $bordereauRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private CalculationProvider $calculationProvider,
        CanvasBuilder $canvasBuilder // Inject CanvasBuilder without property promotion
    ) {
        // Assign the injected CanvasBuilder to the property declared in the trait
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Bordereau::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Bordereau::class);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        return $this->renderViewOrListComponent(Bordereau::class, $request);
    }


    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Bordereau $bordereau, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Bordereau::class,
            BordereauType::class,
            $bordereau,
            function (Bordereau $bordereau, Invite $invite) {
                $bordereau->setType(Bordereau::TYPE_BOREDERAU_PRODUCTION);
                $bordereau->setInvite($invite);
                $bordereau->setEntreprise($invite->getEntreprise()); // Définir l'entreprise explicitement
                $bordereau->setReference('BORD-' . (new DateTimeImmutable())->format('ymd') . '-' . substr(uniqid(), 0, 3)); // Générer une référence unique de 15 caractères pour le nouveau bordereau
                $bordereau->setReceivedAt(new DateTimeImmutable("now"));
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            Bordereau::class,
            BordereauType::class
        );
    }


    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Bordereau $bordereau): Response
    {
        return $this->handleDeleteApi($bordereau);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request)
    {
        return $this->renderViewOrListComponent(Bordereau::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Bordereau::class, $usage);
    }

    #[Route('/api/get-analysis-url/{id}', name: 'api.get_analysis_url', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getAnalysisUrlApi(Bordereau $bordereau, Request $request): JsonResponse
    {
        // On génère l'URL de la page d'analyse.
        $finalUrl = $this->generateUrl(
            'admin.bordereau.show_analysis',
            ['id' => $bordereau->getId()],
            \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
        );

        return $this->json(['analysisUrl' => $finalUrl]);
    }

    #[Route('/analyse/{id}', name: 'show_analysis', methods: ['GET'])]
    public function showAnalysis(Bordereau $bordereau, ParameterBagInterface $params): Response
    {
        $entreprise = $this->getEntreprise();
        $analysisData = [];
        $error = null;
        $excelDocument = null;

        // Étape 1: Trouver le premier document de type Excel parmi les documents attachés.
        $allowedExtensions = ['xlsx', 'xls', 'ods'];
        foreach ($bordereau->getDocuments() as $doc) {
            if ($doc->getFichier()) {
                $extension = pathinfo($doc->getFichier(), PATHINFO_EXTENSION);
                if (in_array(strtolower($extension), $allowedExtensions)) {
                    $excelDocument = $doc;
                    break; // On a trouvé notre fichier, on arrête la boucle.
                }
            }
        }

        if (!$excelDocument) {
            $error = "Aucun fichier Excel (.xlsx, .xls, .ods) n'est attaché à ce bordereau. Veuillez retourner à l'édition pour y attacher un fichier valide.";
        } else {
            // On construit le chemin complet vers le fichier uploadé
            $filePath = $params->get('kernel.project_dir') . '/public/uploads/documents/' . $excelDocument->getFichier();

            try {
                $spreadsheet = IOFactory::load($filePath);
                $sheetNames = $spreadsheet->getSheetNames();

                foreach ($sheetNames as $sheetName) {
                    $worksheet = $spreadsheet->getSheetByName($sheetName);
                    if ($worksheet) {
                        $highestColumn = $worksheet->getHighestColumn(); // ex: 'F'
                        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn); // ex: 6
                        
                        $columns = [];
                        // On lit la première ligne pour récupérer les en-têtes de colonnes
                        for ($col = 1; $col <= $highestColumnIndex; ++$col) {
                            $columns[] = $worksheet->getCellByColumnAndRow($col, 1)->getValue();
                        }

                        $analysisData[] = [
                            'sheetName' => $sheetName,
                            'columns' => $columns,
                        ];
                    }
                }
            } catch (ReaderException $e) {
                $error = "Une erreur est survenue lors de la lecture du fichier Excel : " . $e->getMessage();
            }
        }

        return $this->render('admin/bordereau/bordereau_analysis.html.twig', [
            'bordereau' => $bordereau,
            'entreprise' => $entreprise,
            'analysisData' => $analysisData,
            'error' => $error,
        ]);
    }
}
