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
use App\Services\ServiceMonnaies;
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
        private AvenantRepository $avenantRepository, // NOUVEAU : Ajout du repository Avenant
        private ServiceMonnaies $serviceMonnaies // NOUVEAU : Pour la monnaie d'affichage
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
        $viewData['display_currency_code'] = $this->serviceMonnaies->getCodeMonnaieAffichage();
        // NOUVEAU : Définition statique des formats pour chaque champ système.
        // C'est cette carte qui dictera le formatage dans Twig.
        $viewData['system_field_formats'] = [
            'reference_police' => 'text',
            'date_effet_avenant' => 'date',
            'date_expiration_avenant' => 'date',
            'date_operation' => 'date',
            'prime_ttc' => 'number',
            'taux_commission' => 'number', // Les taux sont aussi des nombres
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

        // NOUVEAU : Enrichir la carte des formats avec les chargements et revenus
        foreach ($chargementsData as $chargement) {
            // La clé doit correspondre à la valeur utilisée dans le <select> du mappage
            // NOUVEAU : Ajout du nom "slugifié" pour une meilleure lisibilité
            $slug = str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9\s]/', '', $chargement['nom']));
            $key = 'chargement_' . $chargement['id'] . '_' . $slug;
            $viewData['system_field_formats'][$key] = 'number';
        }
        foreach ($typeRevenusData as $revenu) {
            // NOUVEAU : Ajout du nom "slugifié"
            $slug = str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9\s]/', '', $revenu['nom']));
            $key = 'revenu_' . $revenu['id'] . '_' . $slug;
            $viewData['system_field_formats'][$key] = 'number';
        }


        // DUMP pour le débogage : Vérifier les valeurs avant de les envoyer au template.
        $rawAnalysisResults = $bordereau->getAnalysisResults() ?? [];

        // CORRECTION : Si les résultats sont des données structurées (objets/tableaux),
        // on les transforme en HTML en utilisant le même template que lors de l'analyse initiale.
        // Cela garantit que la restauration de l'état fonctionne correctement.
        $analysisResultsHtmlForTemplate = [];
        if (!empty($rawAnalysisResults) && (isset($rawAnalysisResults[0])) && (is_array($rawAnalysisResults[0]) || is_object($rawAnalysisResults[0]))) {
            foreach ($rawAnalysisResults as $index => $result) {
                $analysisResultsHtmlForTemplate[] = $this->renderView('components/_analysis_result_item.html.twig', [
                    'result' => (array) $result,
                    'bordereau_id' => $bordereau->getId(),
                    'loop' => ['index' => $index] // Simuler la variable loop de Twig
                ]);
            }
        }

        return $this->render('admin/bordereau/bordereau_analysis.html.twig', [
            'bordereau' => $bordereau,
            'entreprise' => $entreprise,
            'invite' => $invite, // NOUVEAU : On passe l'invité au template.
            'viewData' => $viewData, // Contient maintenant les chargements
            // CORRECTION : S'assurer que les types sont corrects (objet vide ou tableau vide au lieu de null)
            'selectedSheetName' => $bordereau->getSelectedSheetName(),
            'mappedColumns' => (object) ($bordereau->getMappedColumns() ?: []),
            'analysisResults' => $rawAnalysisResults, // On passe les données brutes pour la sauvegarde
            'analysisResultsHtml' => $analysisResultsHtmlForTemplate, // On passe le HTML pour l'affichage
            'currentAnalysisStep' => $bordereau->getCurrentAnalysisStep(),
            'error' => $error,
        ]);
    }

    // NOUVEAU : Route pour soumettre l'analyse du bordereau
    #[Route('/api/submit-analysis/{id}', name: 'api.submit_analysis', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function submitAnalysisApi(Bordereau $bordereau, Request $request, ParameterBagInterface $params): JsonResponse
    {
        $entreprise = $this->getEntreprise();

        // Récupérer les informations depuis l'entité Bordereau, qui a été sauvegardée
        $sheetName = $bordereau->getSelectedSheetName();
        $mappedColumns = $bordereau->getMappedColumns() ?: [];

        // DUMP pour le débogage, comme suggéré.
        dump([
            'sheetName from DB' => $sheetName,
            'mappedColumns from DB' => $mappedColumns
        ]);

        // CORRECTION : On vérifie si la CLÉ 'reference_police' existe, au lieu de chercher sa VALEUR.
        // Si elle existe, on récupère la lettre de la colonne associée.
        $refPoliceColumn = array_key_exists('reference_police', $mappedColumns) ? $mappedColumns['reference_police'] : null;

        if (empty($sheetName) || empty($refPoliceColumn)) {
            return $this->json(['error' => 'Le nom de la feuille ou le mappage de la colonne "N° de Police" est manquant. Veuillez retourner à l\'étape de mappage.'], Response::HTTP_BAD_REQUEST);
        }

        // Relire le fichier Excel pour obtenir les données de la feuille sélectionnée
        $excelDocument = null;
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
            return $this->json(['error' => "Aucun fichier Excel valide n'est attaché à ce bordereau."], Response::HTTP_BAD_REQUEST);
        }

        $filePath = $params->get('kernel.project_dir') . '/public/uploads/documents/' . $excelDocument->getNomFichierStocke();
        $selectedSheetData = [];

        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getSheetByName($sheetName);
            if ($worksheet) {
                $sheetDataWithHeader = $worksheet->toArray(null, true, false, true);
                array_shift($sheetDataWithHeader); // Retirer la ligne d'en-tête
                $selectedSheetData = $sheetDataWithHeader;
            } else {
                return $this->json(['error' => "La feuille '$sheetName' n'a pas été trouvée dans le fichier Excel."], Response::HTTP_BAD_REQUEST);
            }
        } catch (ReaderException $e) {
            return $this->json(['error' => "Erreur lors de la lecture du fichier Excel : " . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $analysisResults = [];

        // --- ÉTAPE 1: Extraire toutes les références de police du fichier Excel ---
        $policeReferences = array_map(fn ($row) => $row[$refPoliceColumn] ?? null, $selectedSheetData);
        $policeReferences = array_filter(array_unique($policeReferences)); // Garder les références uniques et non vides

        // --- ÉTAPE 2: Récupérer tous les avenants correspondants en une seule requête ---
        $avenantsFromDb = $this->avenantRepository->findBy(['referencePolice' => $policeReferences]);

        // --- ÉTAPE 3: Hydrater les avenants et les stocker dans une map pour un accès rapide ---
        $avenantsMap = [];
        foreach ($avenantsFromDb as $avenant) {
            $this->loadCalculatedValues(null, $avenant); // Hydratation des valeurs calculées
            $avenantsMap[$avenant->getReferencePolice()] = $avenant;
        }

        // --- ÉTAPE 4: Parcourir chaque ligne du bordereau et comparer ---
        foreach ($selectedSheetData as $rowIndex => $row) {
            $refPolice = $row[$refPoliceColumn] ?? null;
            if (!$refPolice) continue; // Ignorer les lignes sans référence de police

            $avenant = $avenantsMap[$refPolice] ?? null;
            $rawLineData = []; // Pour stocker les données brutes extraites de la ligne Excel (utilisées dans le payload)
            $discrepancies = [];

            // Extraire toutes les données mappées de la ligne Excel
            foreach ($mappedColumns as $systemField => $excelColumn) {
                $rawValue = $this->parseExcelValue($row[$excelColumn] ?? null, $systemField);
                $rawLineData[$systemField] = $rawValue;
                // SUPPRESSION : Le formatage se fait maintenant dans Twig.
                // $formattedLineData[$systemField] = $this->formatValueForDisplay($rawValue, $systemField);
            }

            if (!$avenant) {
                // CAS 1: Nouvel avenant, non trouvé en base de données
                $analysisResults[] = [
                    'type' => 'new',
                    'bordereau_line_info' => $rawLineData, // On envoie les données brutes
                    'details' => "Ligne n°" . ($rowIndex + 2) . ": Nouvel avenant détecté.",
                    'actions' => [
                        ['label' => 'Créer l\'avenant', 'event' => 'bordereau:create-entity', 'payload' => ['excel_data' => $rawLineData]] // Brut pour le payload
                    ],
                ];
                continue;
            }

            // CAS 2: Avenant trouvé, on compare les champs
            $comparisons = [
                'prime_ttc' => ['getter' => 'montantTTC', 'formatter' => fn ($v) => number_format($v, 2, '.', '')],
                'date_effet_avenant' => ['getter' => 'startingAt', 'formatter' => fn ($v) => $v ? $v->format('Y-m-d') : null],
                'date_expiration_avenant' => ['getter' => 'endingAt', 'formatter' => fn ($v) => $v ? $v->format('Y-m-d') : null],
                'taux_commission' => ['getter' => 'tauxCommission', 'formatter' => fn ($v) => number_format($v, 2, '.', '')],
            ];

            foreach ($comparisons as $field => $config) {
                if (isset($rawLineData[$field])) { // Comparaison sur les données brutes
                    $excelValue = $rawLineData[$field];
                    $dbValue = $avenant->{$config['getter']};

                    // Formater les deux valeurs pour une comparaison fiable
                    $formattedExcelValue = $config['formatter']($excelValue);
                    $formattedDbValue = $config['formatter']($dbValue);

                    if ($formattedExcelValue !== $formattedDbValue) {
                        $discrepancies[] = sprintf(
                            "%s (Excel: %s, DB: %s)",
                            $this->translator->trans($field, [], 'messages'), // ex: "Date d'effet"
                            $this->formatValueForDisplay($excelValue, $field), // ex: "15/01/2024"
                            $this->formatValueForDisplay($dbValue, $field)     // ex: "10/01/2024"
                        );
                    }
                }
            }

            if (!empty($discrepancies)) {
                // CAS 2a: Des anomalies ont été trouvées
                $analysisResults[] = [
                    'type' => 'discrepancy',
                    'bordereau_line_info' => $rawLineData, // On envoie les données brutes
                    'details' => "Ligne n°" . ($rowIndex + 2) . ": Anomalie(s) détectée(s) - " . implode(', ', $discrepancies),
                    'actions' => [
                        ['label' => 'Mettre à jour', 'event' => 'bordereau:update-entity', 'payload' => ['avenant_id' => $avenant->getId(), 'excel_data' => $rawLineData]] // Brut pour le payload
                    ],
                ];
            } else {
                // CAS 2b: Aucune anomalie, tout correspond
                $analysisResults[] = [
                    'type' => 'match',
                    'bordereau_line_info' => $rawLineData, // On envoie les données brutes
                    'details' => "Ligne n°" . ($rowIndex + 2) . ": Correspondance parfaite avec les données existantes.",
                    'actions' => [],
                ];
            }
        }

        // Sauvegarder les résultats d'analyse bruts dans l'entité Bordereau
        $bordereau->setAnalysisResults($analysisResults);
        $this->em->persist($bordereau);
        $this->em->flush();

        // NOUVEAU : Rendre chaque résultat en HTML via le composant Twig
        $analysisResultsHtml = [];
        foreach ($analysisResults as $index => $result) {
            // Créer une copie du résultat pour le rendu afin d'éviter de modifier l'original
            // $resultForRendering = $result;
            // Le 'bordereau_line_info' contient maintenant des données brutes.
            // Le formatage se fera dans le template Twig.
            $analysisResultsHtml[] = $this->renderView('components/_analysis_result_item.html.twig', [
                'result' => $result,
                'bordereau_id' => $bordereau->getId(),
                'loop' => ['index' => $index] // Simuler la variable loop de Twig
            ]);
        }

        // On retourne les résultats bruts (pour la sauvegarde) et le HTML pré-rendu (pour l'affichage)
        return $this->json([
            'analysisResults' => $analysisResults,
            'analysisResultsHtml' => $analysisResultsHtml
        ]);
    }

    // NOUVEAU : Route pour enregistrer l'état de l'analyse du bordereau
    #[Route('/api/save-analysis-state/{id}', name: 'api.save_analysis_state', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function saveAnalysisState(Bordereau $bordereau, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        dump('saveAnalysisState: Payload reçu du frontend:', $payload);

        // OPTIMISATION : On ne met à jour que les champs présents dans le payload.
        if (array_key_exists('selectedSheetName', $payload)) {
            $bordereau->setSelectedSheetName($payload['selectedSheetName']);
        }
        if (array_key_exists('mappedColumns', $payload)) {
            $bordereau->setMappedColumns($payload['mappedColumns']);
        }
        if (array_key_exists('currentAnalysisStep', $payload)) {
            $bordereau->setCurrentAnalysisStep($payload['currentAnalysisStep']);
        }
        if (array_key_exists('analysisResults', $payload)) {
            $analysisResults = $payload['analysisResults'];
            // Si on n'est pas à l'étape 3, on s'assure de vider les résultats pour éviter de restaurer un état incohérent.
            if (($payload['currentAnalysisStep'] ?? $bordereau->getCurrentAnalysisStep()) !== 3) {
                $analysisResults = null;
            }
            $bordereau->setAnalysisResults($analysisResults);
        }

        dump('saveAnalysisState: Bordereau mis à jour avant persistance:', [
            'selectedSheetName' => $bordereau->getSelectedSheetName(),
            'mappedColumns' => $bordereau->getMappedColumns(), 'analysisResults' => $bordereau->getAnalysisResults(), 'currentAnalysisStep' => $bordereau->getCurrentAnalysisStep()
        ]);

        $this->em->persist($bordereau);
        $this->em->flush();

        return $this->json(['message' => 'État de l\'analyse enregistré avec succès.']);
    }
}
