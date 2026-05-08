<?php

namespace App\Controller\Admin;

use App\Constantes\Constante;
use App\Controller\Admin\ControllerUtilsTrait;
use App\Entity\TypeRevenu;
use App\Entity\Chargement;
use App\Entity\Bordereau;
use App\Entity\Invite;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Form\BordereauType;
use App\Repository\BordereauRepository;
use App\Repository\TypeRevenuRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Repository\ChargementRepository;
use App\Services\Canvas\CalculationProvider;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use App\Repository\AvenantRepository; // NOUVEAU
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private ChargementRepository $chargementRepository,
        private BordereauRepository $bordereauRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private CalculationProvider $calculationProvider,
        CanvasBuilder $canvasBuilder, // Inject CanvasBuilder without property promotion
        private AvenantRepository $avenantRepository // NOUVEAU : Ajout du repository Avenant
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
    public function showAnalysis(Bordereau $bordereau, ParameterBagInterface $params, ChargementRepository $chargementRepository, TypeRevenuRepository $typeRevenuRepository): Response
    {
        $entreprise = $this->getEntreprise(); // Récupère l'entreprise courante
        $invite = $this->getInvite(); // NOUVEAU : On récupère l'invité courant.
        $viewData = [
            'sheets' => [],
            'mapping_options' => [
                'reference_police' => 'N° de Police', // Obligatoire
                'date_effet_avenant' => 'Date d\'effet', // Obligatoire
                'date_expiration_avenant' => 'Date d\'expiration', // Obligatoire
                'date_operation' => 'Date d\'opération', // Obligatoire
                'risque' => 'Risque', // Obligatoire
                'prime_ttc' => 'Prime TTC', // NOUVEAU : Ajout de la prime TTC
                'nom_client' => 'Assuré', // Obligatoire
                'commission_ht_assureur' => 'Commission HT Payable', // Obligatoire
                'taxe_commission_assureur' => 'Taxe sur commission payable', // Obligatoire
                'taux_commission' => 'Taux de commission', // Obligatoire
            ],
            'chargements' => [], // Initialisation
            'typeRevenus' => [], // NOUVEAU : Initialisation pour les types de revenu
            // NOUVEAU : Données de l'analyse du bordereau
            'selectedSheetName' => $bordereau->getSelectedSheetName(),
            'mappedColumns' => $bordereau->getMappedColumns(),
            'analysisResults' => $bordereau->getAnalysisResults(), // NOUVEAU : Pour la restauration de l'état
            'currentAnalysisStep' => $bordereau->getCurrentAnalysisStep(), // NOUVEAU : Pour la restauration de l'état
        ];
        $error = null;
        $excelDocument = null;

        // Étape 1: Trouver le premier document de type Excel parmi les documents attachés.
        $allowedExtensions = ['xlsx', 'xls', 'ods'];
        foreach ($bordereau->getDocuments() as $doc) {
            if ($doc->getNomFichierStocke()) {
                $extension = pathinfo($doc->getNomFichierStocke(), PATHINFO_EXTENSION);
                if (in_array(strtolower($extension), $allowedExtensions)) {
                    $excelDocument = $doc;
                    break;
                }
            }
        }

        if (!$excelDocument) {
            $error = "Aucun fichier Excel (.xlsx, .xls, .ods) n'est attaché à ce bordereau. Veuillez retourner à l'édition pour y attacher un fichier valide.";
        } else {
            $filePath = $params->get('kernel.project_dir') . '/public/uploads/documents/' . $excelDocument->getNomFichierStocke();

            try {
                $spreadsheet = IOFactory::load($filePath);
                $sheetNames = $spreadsheet->getSheetNames();

                if (empty($sheetNames)) {
                    $error = "Le fichier Excel ne contient aucune feuille de calcul. L'analyse est impossible.";
                }

                foreach ($sheetNames as $sheetName) {
                    $worksheet = $spreadsheet->getSheetByName($sheetName);
                    if ($worksheet) {
                        /** @var Worksheet $worksheet */
                        $highestRow = $worksheet->getHighestRow();
                        if ($highestRow < 1) {
                            // Si la feuille est vide, on ne l'ajoute pas à l'analyse.
                            continue;
                        }

                        $highestColumn = $worksheet->getHighestColumn(1); // On se base sur la première ligne pour les en-têtes
                        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

                        // NOUVEAU: Lire toutes les données de la feuille
                        $sheetData = $worksheet->toArray(null, true, false, true); // Changed formatData to false
                        $headers = array_shift($sheetData) ?: []; // La première ligne est l'en-tête

                        $columns = [];
                        for ($col = 1; $col <= $highestColumnIndex; ++$col) {
                            $colLetter = Coordinate::stringFromColumnIndex($col);
                            $columns[$colLetter] = $headers[$colLetter] ?? null;
                        }

                        $viewData['sheets'][] = [
                            'sheetName' => $sheetName,
                            'columns' => $columns,
                            'headers' => $headers, // NOUVEAU: On passe les en-têtes séparément
                            'data' => $sheetData,      // NOUVEAU: On passe les données des lignes
                        ];
                    }
                }

                if (empty($viewData['sheets']) && !$error) {
                    $error = "Aucune feuille de calcul avec des données n'a été trouvée dans le fichier.";
                }
            } catch (ReaderException $e) {
                $error = "Une erreur est survenue lors de la lecture du fichier Excel : " . $e->getMessage();
            }
        }

        // NOUVEAU : Récupérer tous les chargements de l'entreprise
        $chargements = $chargementRepository->findBy(['entreprise' => $entreprise]);

        // On ne garde que les champs nécessaires pour le frontend (id, nom)
        $chargementsData = array_map(function (Chargement $chargement) {
            return [
                'id' => $chargement->getId(),
                'nom' => $chargement->getNom(),
            ];
        }, $chargements);
        $viewData['chargements'] = $chargementsData;

        // NOUVEAU : Récupérer tous les types de revenu de l'entreprise
        $typeRevenus = $typeRevenuRepository->findBy(['entreprise' => $entreprise]);

        // On ne garde que les champs nécessaires pour le frontend (id, nom)
        $typeRevenusData = array_map(function (TypeRevenu $typeRevenu) {
            return [
                'id' => $typeRevenu->getId(),
                'nom' => $typeRevenu->getNom(),
            ];
        }, $typeRevenus);
        $viewData['typeRevenus'] = $typeRevenusData;

        return $this->render('admin/bordereau/bordereau_analysis.html.twig', [
            'bordereau' => $bordereau,
            'entreprise' => $entreprise,
            'invite' => $invite, // NOUVEAU : On passe l'invité au template.
            'viewData' => $viewData, // Contient maintenant les chargements
            // NOUVEAU : On passe les données de restauration directement au template
            'selectedSheetName' => $bordereau->getSelectedSheetName(),
            'mappedColumns' => $bordereau->getMappedColumns(),
            'analysisResults' => $bordereau->getAnalysisResults(),
            'currentAnalysisStep' => $bordereau->getCurrentAnalysisStep(),
            'error' => $error,
        ]);
    }

    // NOUVEAU : Route pour soumettre l'analyse du bordereau
    #[Route('/api/submit-analysis/{id}', name: 'api.submit_analysis', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function submitAnalysisApi(Bordereau $bordereau, Request $request): JsonResponse
    {
        $entreprise = $this->getEntreprise();
        // dump('--- Début submitAnalysisApi ---');
        $payload = json_decode($request->getContent(), true);
        // dump('Payload reçu:', $payload);

        $sheetName = $payload['sheetName'] ?? null;
        $mappedColumns = $payload['mappedColumns'] ?? [];
        $sheetsData = $payload['sheetsData'] ?? [];
        // dump('Extracted from payload:', ['sheetName' => $sheetName, 'mappedColumns' => $mappedColumns, 'sheetsDataKeys' => array_keys($sheetsData)]);

        if (!$sheetName || !isset($sheetsData[$sheetName])) {
            return $this->json(['error' => 'Nom de feuille ou données de feuille manquantes.'], Response::HTTP_BAD_REQUEST);
        }

        $selectedSheetData = $sheetsData[$sheetName] ?? []; // CORRECTION: Accéder directement aux données de la feuille
        $analysisResults = [];
        // dump('Selected Sheet Data (first 5 rows):', array_slice($selectedSheetData, 0, 5));
        // dump('Total rows in selectedSheetData:', count($selectedSheetData));
        
        // TODO: Implémenter la logique métier réelle ici pour comparer les données Excel avec la base de données.
        // Cette section retourne actuellement des résultats d'analyse codés en dur.
        // La logique devrait :
        // 1. Parcourir chaque ligne de `$selectedSheetData`.
        // 2. Pour chaque ligne, extraire les valeurs en utilisant le mappage `$mappedColumns`.
        // 3. Utiliser la référence de police extraite pour rechercher un avenant correspondant en base de données.
        //    (L'optimisation consistant à tout charger en une fois est une bonne approche).
        // 4. Comparer les autres champs (dates, montants) entre la ligne Excel et l'avenant trouvé.
        // 5. En fonction de la comparaison, déterminer le type de résultat ('new', 'discrepancy', 'match').
        // 6. Construire un tableau `$analysisResults` avec les données dynamiques.
        //
        // Pour l'instant, le code en dur est conservé à titre d'exemple mais doit être remplacé.
        $analysisResults[] = [
            'type' => 'new', // Exemple
            'bordereau_line_info' => [ 'reference_police' => 'POLICE_EXEMPLE_001' /* ... autres données de la ligne ... */ ],
            'details' => "Ligne n°1: Cet avenant ne correspond à aucun enregistrement. Il faut donc l'ajouter.",
            'actions' => [ /* ... actions possibles ... */ ],
        ];

        return $this->json(['analysisResults' => $analysisResults]);
    }

    // NOUVEAU : Route pour enregistrer l'état de l'analyse du bordereau
    #[Route('/api/save-analysis-state/{id}', name: 'api.save_analysis_state', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function saveAnalysisState(Bordereau $bordereau, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        dump('saveAnalysisState: Payload reçu du frontend:', $payload);

        $selectedSheetName = $payload['selectedSheetName'] ?? null;
        $mappedColumns = $payload['mappedColumns'] ?? null;
        $currentAnalysisStep = $payload['currentAnalysisStep'] ?? null;
        $analysisResults = $payload['analysisResults'] ?? null; // NOUVEAU : Pour la restauration de l'état

        $bordereau->setSelectedSheetName($selectedSheetName);
        $bordereau->setMappedColumns($mappedColumns);
        $bordereau->setCurrentAnalysisStep($currentAnalysisStep);
        $bordereau->setAnalysisResults($analysisResults); // NOUVEAU : Pour la restauration de l'état
        dump('saveAnalysisState: Bordereau mis à jour avant persistance:', [
            'selectedSheetName' => $bordereau->getSelectedSheetName(),
            'mappedColumns' => $bordereau->getMappedColumns(), 'analysisResults' => $bordereau->getAnalysisResults(), 'currentAnalysisStep' => $bordereau->getCurrentAnalysisStep()
        ]);

        $this->em->persist($bordereau);
        $this->em->flush();

        return $this->json(['message' => 'État de l\'analyse enregistré avec succès.']);
    }
    /**
     * Helper to parse and convert Excel values based on expected system field type.
     * @param mixed $value
     * @param string $systemField
     * @return mixed
     */
    private function parseExcelValue($value, string $systemField)
    {
        if ($value === null || $value === '') {
            return null;
        }

        switch ($systemField) {
            case 'date_effet_avenant':
            case 'date_expiration_avenant':
            case 'date_operation':
                // Attempt to parse date. PhpSpreadsheet often converts dates to numbers.
                if (is_numeric($value)) {
                    try {
                        // Excel date format (days since 1900-01-01)
                        return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
                    } catch (\Exception $e) {
                        // Fallback if conversion fails
                        return null;
                    }
                } elseif (is_string($value)) {
                    try {
                        // Try common date formats
                        return new DateTimeImmutable($value);
                    } catch (\Exception $e) {
                        return null;
                    }
                }
                return null;
            case 'prime_ttc':
            case 'commission_ht_assureur':
            case 'taxe_commission_assureur':
            case 'taux_commission':
                // Convert to float, handling common string representations (e.g., "1 234,56", "1.234.567,89")
                if (is_string($value)) {
                    // Remove spaces, replace comma with dot for float conversion
                    $cleanedValue = str_replace([' ', "\u{00A0}"], '', $value); // Also remove non-breaking spaces
                    $cleanedValue = str_replace(',', '.', $cleanedValue);
                    // Handle cases like "1.234.567,89" -> "1234567.89"
                    if (substr_count($cleanedValue, '.') > 1) {
                        $lastDotPos = strrpos($cleanedValue, '.');
                        if ($lastDotPos !== false) {
                            $cleanedValue = str_replace('.', '', substr($cleanedValue, 0, $lastDotPos)) . substr($cleanedValue, $lastDotPos);
                        }
                    }
                    return (float) $cleanedValue;
                }
                return (float) $value;
            default:
                return $value;
        }
    }
}
