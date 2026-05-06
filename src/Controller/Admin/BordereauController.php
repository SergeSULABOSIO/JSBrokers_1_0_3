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
        $chargementsData = array_map(function(Chargement $chargement) {
            return [
                'id' => $chargement->getId(),
                'nom' => $chargement->getNom(),
            ];
        }, $chargements);
        $viewData['chargements'] = $chargementsData;

        // NOUVEAU : Récupérer tous les types de revenu de l'entreprise
        $typeRevenus = $typeRevenuRepository->findBy(['entreprise' => $entreprise]);

        // On ne garde que les champs nécessaires pour le frontend (id, nom)
        $typeRevenusData = array_map(function(TypeRevenu $typeRevenu) {
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
            'error' => $error,
        ]);
    }

    // NOUVEAU : Route pour soumettre l'analyse du bordereau
    #[Route('/api/submit-analysis/{id}', name: 'api.submit_analysis', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function submitAnalysisApi(Bordereau $bordereau, Request $request): JsonResponse
    {
        $entreprise = $this->getEntreprise();
        dump('--- Début submitAnalysisApi ---');
        $payload = json_decode($request->getContent(), true);
        dump('Payload reçu:', $payload);

        $sheetName = $payload['sheetName'] ?? null;
        $mappedColumns = $payload['mappedColumns'] ?? [];
        $sheetsData = $payload['sheetsData'] ?? [];
        dump('Extracted from payload:', ['sheetName' => $sheetName, 'mappedColumns' => $mappedColumns, 'sheetsDataKeys' => array_keys($sheetsData)]);

        if (!$sheetName || !isset($sheetsData[$sheetName])) {
            return $this->json(['error' => 'Nom de feuille ou données de feuille manquantes.'], Response::HTTP_BAD_REQUEST);
        }

        $selectedSheetData = $sheetsData[$sheetName] ?? []; // CORRECTION: Accéder directement aux données de la feuille
        $analysisResults = [];
        dump('Selected Sheet Data (first 5 rows):', array_slice($selectedSheetData, 0, 5));
        dump('Total rows in selectedSheetData:', count($selectedSheetData));

        foreach ($selectedSheetData as $rowIndex => $rowData) {
            // NOUVEAU : Vérifier si la ligne est entièrement vide (toutes les cellules sont null ou chaînes vides)
            $isRowEffectivelyEmpty = true;
            foreach ($rowData as $cellValue) {
                if ($cellValue !== null && $cellValue !== '') {
                    $isRowEffectivelyEmpty = false;
                    break;
                }
            }
            dump("Ligne " . ($rowIndex + 2) . " - Raw Data:", $rowData);
            dump("Ligne " . ($rowIndex + 2) . " - isRowEffectivelyEmpty:", $isRowEffectivelyEmpty);

            // Si la ligne est vide, on ajoute un résultat spécifique et on passe à la suivante
            if ($isRowEffectivelyEmpty) {
                $analysisResults[] = ['type' => 'empty_row', 'bordereau_line_info' => ['original_row_data' => $rowData], 'details' => "Ligne " . ($rowIndex + 2) . ": Cette ligne est vide.", 'actions' => []];
                continue;
            }

            $bordereauLineInfo = [];
            foreach ($mappedColumns as $systemField => $excelColumnLetter) {
                $value = $rowData[$excelColumnLetter] ?? null;
                $bordereauLineInfo[$systemField] = $this->parseExcelValue($value, $systemField);
                dump("Ligne " . ($rowIndex + 2) . " - Mapped Field '$systemField' (Excel Col '$excelColumnLetter'):", ['raw_value' => $value, 'parsed_value' => $bordereauLineInfo[$systemField]]);
            }
            dump("Ligne " . ($rowIndex + 2) . " - Bordereau Line Info:", $bordereauLineInfo);

            $referencePolice = $bordereauLineInfo['reference_police'] ?? null;
            dump("Ligne " . ($rowIndex + 2) . " - Reference Police:", $referencePolice);
            if (!$referencePolice) {
                // If no police reference, we can't process this line for comparison
                $analysisResults[] = [
                    'type' => 'error',
                    'bordereau_line_info' => $bordereauLineInfo,
                    'details' => "Ligne " . ($rowIndex + 2) . ": Référence de police manquante pour l'analyse.",
                    'actions' => []
                ];
                continue;
            }

            // Find existing Avenants for this police reference and entreprise
            $existingAvenants = $this->avenantRepository->findBy([
                'referencePolice' => $referencePolice,
                'entreprise' => $entreprise
            ]);
            dump("Ligne " . ($rowIndex + 2) . " - Existing Avenants found:", count($existingAvenants));

            if (empty($existingAvenants)) {
                $analysisResults[] = [
                    'type' => 'new',
                    'bordereau_line_info' => $bordereauLineInfo,
                    'details' => "Ligne " . ($rowIndex + 2) . ": Cet avenant n'existe pas dans la base de données de l'entreprise.",
                    'actions' => [ // NOUVEAU : Ajout des actions pour le type 'new'
                        // Ces actions sont des exemples, à adapter selon votre besoin
                        // Elles devraient déclencher un événement Stimulus pour ouvrir un formulaire de création pré-rempli
                        ['label' => 'Ajouter cet avenant', 'event' => 'bordereau:add-new-avenant', 'payload' => $bordereauLineInfo]
                    ]
                ];
            } else {
                // For simplicity, we'll compare against the first found avenant.
                // In a real scenario, you might need more sophisticated matching (e.g., by date, type).
                $matchedAvenant = $existingAvenants[0];
                dump("Ligne " . ($rowIndex + 2) . " - Matched Avenant (ID):", $matchedAvenant->getId());
                $discrepancyFound = false;

                // NOUVEAU : Hydratation complète de l'avenant trouvé en base
                $this->loadCalculatedValues(null, $matchedAvenant);
                $details = [];

                // Calculate values from bordereau line
                $bordereauPrimeTTC = $bordereauLineInfo['prime_ttc'] ?? 0;
                $bordereauCommissionHT = $bordereauLineInfo['commission_ht_assureur'] ?? 0;
                $bordereauTaxeCommission = $bordereauLineInfo['taxe_commission_assureur'] ?? 0;
                $bordereauCommissionTTC = $bordereauCommissionHT + $bordereauTaxeCommission;
                // Le taux de commission du bordereau est souvent un pourcentage (ex: 0.15 pour 15%)
                $bordereauTauxCommission = ($bordereauLineInfo['taux_commission'] ?? 0);
                dump("Ligne " . ($rowIndex + 2) . " - Bordereau Values:", ['prime_ttc' => $bordereauPrimeTTC, 'commission_ht' => $bordereauCommissionHT, 'taxe_commission' => $bordereauTaxeCommission, 'commission_ttc' => $bordereauCommissionTTC, 'taux_commission' => $bordereauTauxCommission]);

                // Get values from existing Avenant using Constante service
                // Les valeurs sont maintenant directement sur l'objet Avenant hydraté.
                $databasePrimeTTC = $matchedAvenant->montantTTC ?? 0;
                $databaseCommissionTTC = $this->constante->Avenant_getCommissionTTC($matchedAvenant); // Gardons le helper pour la commission TTC
                // CORRECTION : Le taux de commission est sur la cotation liée à l'avenant.
                // Assurez-vous que le taux est bien un float (ex: 0.15 pour 15%)
                $databaseTauxCommission = $matchedAvenant->getCotation() ? ((float)($matchedAvenant->getCotation()->tauxCommission ?? 0)) : 0;
                dump("Ligne " . ($rowIndex + 2) . " - Database Values:", ['prime_ttc' => $databasePrimeTTC, 'commission_ttc' => $databaseCommissionTTC, 'taux_commission' => $databaseTauxCommission]);

                // Compare Prime TTC
                dump("Ligne " . ($rowIndex + 2) . " - Comparing Prime TTC:", ['bordereau' => $bordereauPrimeTTC, 'database' => $databasePrimeTTC, 'diff' => abs($bordereauPrimeTTC - $databasePrimeTTC)]);
                if (abs($bordereauPrimeTTC - $databasePrimeTTC) > 0.01) { // Use a small tolerance for float comparison
                    $discrepancyFound = true;
                    $details[] = "Prime TTC: Base=" . number_format($databasePrimeTTC, 2) . ", Bordereau=" . number_format($bordereauPrimeTTC, 2);
                }

                // Compare Commission TTC
                if (abs($bordereauCommissionTTC - $databaseCommissionTTC) > 0.01) {
                    dump("Ligne " . ($rowIndex + 2) . " - Comparing Commission TTC:", ['bordereau' => $bordereauCommissionTTC, 'database' => $databaseCommissionTTC, 'diff' => abs($bordereauCommissionTTC - $databaseCommissionTTC)]);
                    $discrepancyFound = true;
                    $details[] = "Commission TTC: Base=" . number_format($databaseCommissionTTC, 2) . ", Bordereau=" . number_format($bordereauCommissionTTC, 2);
                }

                // Compare Taux de commission
                if (abs($bordereauTauxCommission - $databaseTauxCommission) > 0.0001) { // Small tolerance for percentage
                    dump("Ligne " . ($rowIndex + 2) . " - Comparing Taux Commission:", ['bordereau' => $bordereauTauxCommission, 'database' => $databaseTauxCommission, 'diff' => abs($bordereauTauxCommission - $databaseTauxCommission)]);
                    $discrepancyFound = true;
                    $details[] = "Taux Commission: Base=" . number_format($databaseTauxCommission * 100, 2) . "%, Bordereau=" . number_format($bordereauTauxCommission * 100, 2) . "%";
                }

                if ($discrepancyFound) {
                    $analysisResults[] = [
                        'type' => 'discrepancy',
                        'bordereau_line_info' => $bordereauLineInfo,
                        'database_info' => [
                            'prime_ttc' => $databasePrimeTTC,
                            'commission_ttc' => $databaseCommissionTTC,
                            'taux_commission' => $databaseTauxCommission,
                        ],
                        'bordereau_values' => [
                            'prime_ttc' => $bordereauPrimeTTC,
                            'commission_ttc' => $bordereauCommissionTTC,
                            'taux_commission' => $bordereauTauxCommission,
                        ],
                        'details' => "Ligne " . ($rowIndex + 2) . ": Discrépance détectée. " . implode(", ", $details),
                        'actions' => [
                            ['label' => 'Contester', 'event' => 'bordereau:dispute-avenant', 'payload' => ['avenantId' => $matchedAvenant->getId(), 'bordereauLine' => $bordereauLineInfo]],
                            ['label' => 'Modifier la base', 'event' => 'bordereau:update-database-avenant', 'payload' => ['avenantId' => $matchedAvenant->getId(), 'bordereauLine' => $bordereauLineInfo]]
                        ]
                    ];
                    dump("Ligne " . ($rowIndex + 2) . " - Result: Discrepancy", $analysisResults[count($analysisResults) - 1]);
                } else {
                    $analysisResults[] = [
                        'type' => 'match',
                        'bordereau_line_info' => $bordereauLineInfo,
                        'details' => "Ligne " . ($rowIndex + 2) . ": Cet avenant correspond aux données en base.",
                        'actions' => []
                    ];
                    dump("Ligne " . ($rowIndex + 2) . " - Result: Match", $analysisResults[count($analysisResults) - 1]);
                }
            }
        }
        dump('Final analysisResults (count):', count($analysisResults));
        dump('--- Fin submitAnalysisApi ---');

        return $this->json(['analysisResults' => $analysisResults]);
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
