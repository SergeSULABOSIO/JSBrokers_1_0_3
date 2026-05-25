<?php

namespace App\Controller\Admin;

use App\Constantes\Constante;
use App\Controller\Admin\ControllerUtilsTrait;
use App\Entity\Bordereau;
use App\Entity\Chargement;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Entity\TypeRevenu;
use App\Form\BordereauType;
use App\Repository\AvenantRepository; // NOUVEAU
use App\Repository\BordereauRepository;
use App\Repository\ChargementRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Repository\TypeRevenuRepository;
use App\Services\AvenantActionService;
use App\Services\Canvas\CalculationProvider;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use App\Services\ServiceMonnaies;
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
        private ServiceMonnaies $serviceMonnaies, // NOUVEAU : Pour la monnaie d'affichage
        private AvenantActionService $avenantActionService // NOUVEAU : Service pour traiter les actions
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

        // Étape 2: Préparer les données Excel pour la reconstruction
        $excelDataByRowIndex = [];
        if (!empty($rawAnalysisResults) && $bordereau->getSelectedSheetName()) {
            foreach ($viewData['sheets'] as $sheet) {
                if ($sheet['sheetName'] === $bordereau->getSelectedSheetName()) {
                    foreach ($sheet['data'] as $rowIndex => $row) {
                        $excelDataByRowIndex[$rowIndex] = $row;
                    }
                    break;
                }
            }
        }

        // Étape 3: Préparer les avenants pour la reconstruction
        $avenantIdsToLoad = array_filter(
            array_column($rawAnalysisResults, 'avenant_id')
        );
        $avenantsForRestoration = [];
        if (!empty($avenantIdsToLoad)) {
            $loadedAvenants = $this->avenantRepository->findBy(['id' => $avenantIdsToLoad]);
            foreach ($loadedAvenants as $av) {
                $avenantsForRestoration[$av->getId()] = $av;
            }
        }

        // Étape 4: Reconstruire le HTML pour chaque résultat
        $analysisResultsHtmlForTemplate = [];
        $mappedColumns = $bordereau->getMappedColumns() ?: [];
        $allReconstructedLineData = []; // Pour le calcul des stats

        foreach ($rawAnalysisResults as $index => $storedResult) {
            $rowIndex    = $storedResult['row_index'] ?? null;
            $avenantId   = $storedResult['avenant_id'] ?? null;
            $type        = $storedResult['type'] ?? 'match';

            // Reconstruire rawLineData depuis la ligne Excel
            $rawLineData = ($rowIndex !== null && isset($excelDataByRowIndex[$rowIndex])) 
                ? $this->reconstructRawLineData($excelDataByRowIndex[$rowIndex], $mappedColumns)
                : [];
            $allReconstructedLineData[] = $rawLineData;

            // --- VÉRIFICATION D'INTÉGRITÉ ---
            // On s'assure que la ligne retrouvée via row_index correspond bien
            // à la même référence de police que celle stockée dans le résumé.
            // Si ce n'est pas le cas, le fichier Excel a changé depuis l'analyse.

            $policeFromExcel = $rawLineData['reference_police'] ?? null;
            $policeFromStore = $storedResult['reference_police'] ?? null;

            $fileHasChanged = false;

            // Cas 1 : La ligne Excel est introuvable (fichier supprimé ou row_index invalide)
            if ($rowIndex === null || !isset($excelDataByRowIndex[$rowIndex])) {
                $fileHasChanged = true;
            }

            // Cas 2 : La ligne existe mais ne correspond plus à la même police
            if (!$fileHasChanged && $policeFromExcel !== $policeFromStore) {
                $fileHasChanged = true;
            }

            // Si le fichier a changé, on court-circuite le switch et on affiche
            // un avertissement à la place des données potentiellement incorrectes
            if ($fileHasChanged) {
                $resultForRendering = [
                    'type'                => 'warning',
                    'row_index'           => $rowIndex,
                    'bordereau_line_info' => [],
                    'details'             => "⚠️ Police n°" . $policeFromStore
                        . " (ligne n°" . ($rowIndex !== null ? $rowIndex + 2 : '?') . ")"
                        . ": Les données ont changé depuis la dernière analyse."
                        . " Veuillez relancer l'analyse pour obtenir des résultats à jour.",
                    'actions'             => [],
                ];

                $analysisResultsHtmlForTemplate[] = $this->renderView(
                    'components/_analysis_result_item.html.twig',
                    [
                        'result'       => $resultForRendering,
                        'bordereau_id' => $bordereau->getId(),
                        'loop'         => ['index' => $index]
                    ]
                );

                // On passe immédiatement à l'élément suivant sans exécuter le switch
                continue;
            }
            // --- FIN VÉRIFICATION D'INTÉGRITÉ ---

            // Reconstruire details et actions selon le type
            $financialGaps = []; // Initialiser ici pour être sûr qu'elle existe toujours
            switch ($type) {
                case 'new':
                    $details = "Ligne n°" . ($rowIndex + 2) . ": Nouvel avenant détecté.";
                    $actions = [
                        [
                            'label'   => 'Créer l\'avenant',
                            'event'   => 'bordereau:create-avenant', // Renommé pour plus de clarté
                            'payload' => [
                                'excel_data' => $rawLineData,
                                'row_index'  => $rowIndex
                            ]
                        ]
                    ];
                    break;


                case 'discrepancy':
                    // 1. D'abord récupérer l'avenant depuis la map de restauration
                    $avenant = $avenantId ? ($avenantsForRestoration[$avenantId] ?? null) : null;

                    // 2. Construire les actions (dépendent de $avenant)
                    $details = "Ligne n°" . ($rowIndex + 2) . ": Anomalie(s) détectée(s).";
                    $actions = $avenant ? [
                        [
                            'label'   => 'Mettre à jour',
                            'event'   => 'bordereau:update-avenant', // Renommé pour plus de clarté
                            'payload' => [
                                'avenant_id' => $avenant->getId(), // L'ID de l'avenant existant
                                'row_index'  => $rowIndex, // L'index de la ligne dans le fichier Excel
                                'excel_data' => $rawLineData
                            ]
                        ]
                    ] : [];

                    // 3. Calculer les écarts financiers (dépendent de $avenant)
                    if ($avenant) {
                        // Hydratation forcée pour garantir l'accès aux attributs publics calculés
                        $this->loadCalculatedValues(null, $avenant);

                        // Calcul des sommes Excel
                        $sumChargementsExcel = 0.0;
                        $sumRevenusExcel = 0.0;
                        foreach ($rawLineData as $key => $val) {
                            if (str_starts_with($key, 'chargement_')) $sumChargementsExcel += (float)$val;
                            if (str_starts_with($key, 'revenu_')) $sumRevenusExcel += (float)$val;
                        }

                        // Récupération des ajustements système en base pour une comparaison équitable
                        $dbAdjPrime = 0.0;
                        foreach ($avenant->getCotation()->getChargements() as $cpp) {
                            if ($cpp->getNom() === \App\Entity\Chargement::SYSTEM_ADJUSTMENT_CHARGEMENT_NAME) {
                                $dbAdjPrime = (float)($cpp->getMontantFlatExceptionel() ?? 0.0);
                            }
                        }
                        $dbAdjRevenu = 0.0;
                        foreach ($avenant->getCotation()->getRevenus() as $rpc) {
                            if ($rpc->getNom() === \App\Entity\TypeRevenu::SYSTEM_ADJUSTMENT_REVENU_NAME) {
                                $dbAdjRevenu = (float)($rpc->getMontantFlatExceptionel() ?? 0.0);
                            }
                        }

                        $financialFieldsConfig = [
                            'prime_totale'  => [
                                'label' => 'Prime TTC Totale',
                                'excel' => round($sumChargementsExcel + $dbAdjPrime, 2),
                                'db'    => round((float)($avenant->primeTotale ?? 0), 2)
                            ],
                            'commission_ht' => [
                                'label' => 'Commission HT Totale',
                                'excel' => round($sumRevenusExcel + $dbAdjRevenu, 2),
                                'db'    => round((float)($avenant->montantHT ?? 0), 2)
                            ],
                        ];

                        foreach ($financialFieldsConfig as $field => $config) {
                            $gap = round($config['excel'] - $config['db'], 2);
                            if ($gap !== 0.0) {
                                $financialGaps[$field] = [
                                    'label'       => $config['label'],
                                    'excel_value' => $config['excel'],
                                    'db_value'    => $config['db'],
                                    'gap'         => $gap,
                                    'gap_class'   => $gap > 0 ? 'text-success' : ($gap < 0 ? 'text-danger' : 'text-muted'),
                                    'gap_sign'    => $gap > 0 ? '+' : '',
                                ];
                            }
                        }
                    }
                    break;

                case 'match':
                default:
                    $details = "Ligne n°" . ($rowIndex + 2)
                        . ": Correspondance parfaite avec les données existantes.";
                    $actions = [];
                    break;
            }

            // Construire le résultat complet pour le rendu
            $resultForRendering = [
                'type'                => $type,
                'row_index'           => $rowIndex,
                'bordereau_line_info' => $rawLineData,
                'details'             => $details,
                'actions'             => $actions,
                'financial_gaps'      => $financialGaps ?? [], // Assurer que la clé existe toujours
            ];

            $analysisResultsHtmlForTemplate[] = $this->renderView(
                'components/_analysis_result_item.html.twig',
                [
                    'result'       => $resultForRendering,
                    'bordereau_id' => $bordereau->getId(),
                    'loop'         => ['index' => $index]
                ]
            );
        }

        // Calcul des statistiques pour la restauration
        $stats = [
            'total'       => count($rawAnalysisResults),
            'match'       => count(array_filter($rawAnalysisResults, fn($r) => $r['type'] === 'match')),
            'discrepancy' => count(array_filter($rawAnalysisResults, fn($r) => $r['type'] === 'discrepancy')),
            'new'         => count(array_filter($rawAnalysisResults, fn($r) => $r['type'] === 'new')),
            'total_prime_ttc'      => 0.0,
            'total_commission_ht'  => 0.0,
            'total_taxe'           => 0.0,
            'total_commission_ttc' => 0.0,
        ];

        // Les totaux financiers sont reconstruits depuis les rawLineData
        // (disponibles dans la boucle de reconstruction précédente)
        // Additionner directement depuis $reconstructedLineData accumulés
        foreach ($allReconstructedLineData as $lineData) {
            $linePrime = 0.0;
            $lineComHT = 0.0;
            $lineTaxe  = 0.0;
            foreach ($lineData as $key => $val) {
                if (str_starts_with($key, 'chargement_')) $linePrime += (float)$val;
                if (str_starts_with($key, 'revenu_')) $lineComHT += (float)$val;
                if ($key === 'taxe_commission_assureur') $lineTaxe += (float)$val;
            }
            $stats['total_prime_ttc']     += $linePrime;
            $stats['total_commission_ht'] += $lineComHT;
            $stats['total_taxe']          += $lineTaxe;
        }
        $stats['total_commission_ttc'] = round(
            $stats['total_commission_ht'] + $stats['total_taxe'],
            2
        );

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
            'analysisStats' => $stats,
            'currentAnalysisStep' => $bordereau->getCurrentAnalysisStep(),
            'error' => $error,
        ]);
    }

    // NOUVEAU : Route pour soumettre l'analyse du bordereau
    #[Route('/api/submit-analysis/{id}', name: 'api.submit_analysis', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function submitAnalysisApi(Bordereau $bordereau, Request $request, ParameterBagInterface $params): JsonResponse
    {
        $mode = $request->query->get('mode', 'init');

        return match ($mode) {
            'init'    => $this->_handleAnalysisInit($bordereau, $request, $params),
            'process' => $this->_handleAnalysisProcess($bordereau, $request, $params),
            default   => $this->json(['error' => 'Mode inconnu.'], 400),
        };
    }

    /**
     * Initialise l'analyse : lit le fichier Excel, prépare les données,
     * et retourne le nombre total de lignes à traiter.
     */
    private function _handleAnalysisInit(Bordereau $bordereau, Request $request, ParameterBagInterface $params): JsonResponse
    {
        $sheetName     = $bordereau->getSelectedSheetName();
        $mappedColumns = $bordereau->getMappedColumns() ?: [];
        $refPoliceColumn = $mappedColumns['reference_police'] ?? null;

        if (empty($sheetName) || empty($refPoliceColumn)) {
            return $this->json(['error' => 'Le nom de la feuille ou le mappage "N° de Police" est manquant.'], 400);
        }

        $selectedSheetData = $this->_loadSheetData($bordereau, $sheetName, $params);
        if ($selectedSheetData instanceof JsonResponse) {
            return $selectedSheetData;
        }

        // Sécurisation de la colonne de référence (on prend la première si tableau)
        $refCol = is_array($refPoliceColumn) ? ($refPoliceColumn[0] ?? null) : $refPoliceColumn;

        $policeReferences = array_filter(array_unique(
            array_map(fn($row) => $row[$refCol] ?? null, $selectedSheetData)
        ));
        $avenantsFromDb = $this->avenantRepository->findBy(['referencePolice' => $policeReferences]);
        $avenantsMap = [];
        foreach ($avenantsFromDb as $avenant) {
            $this->loadCalculatedValues(null, $avenant);
            $avenantsMap[$avenant->getReferencePolice()] = $avenant->getId();
        }

        $sessionKey = 'bordereau_analysis_' . $bordereau->getId();
        $request->getSession()->set($sessionKey, [
            'rows'          => array_values($selectedSheetData),
            'avenantsMap'   => $avenantsMap,
            'mappedColumns' => $mappedColumns,
            'totalRows'     => count($selectedSheetData),
        ]);

        return $this->json([
            'totalRows' => count($selectedSheetData),
            'chunkSize' => 10,
        ]);
    }

    /**
     * Traite un lot de 10 lignes et retourne les résultats partiels.
     */
    private function _handleAnalysisProcess(Bordereau $bordereau, Request $request, ParameterBagInterface $params): JsonResponse
    {
        $body   = json_decode($request->getContent(), true);
        $offset = (int)($body['offset'] ?? 0);
        $chunkSize = 10;

        $sessionKey  = 'bordereau_analysis_' . $bordereau->getId();
        $sessionData = $request->getSession()->get($sessionKey);

        if (!$sessionData) {
            return $this->json(['error' => 'Session d\'analyse expirée ou introuvable. Veuillez relancer l\'analyse.'], 400);
        }

        $allRows       = $sessionData['rows'];
        $mappedColumns = $sessionData['mappedColumns'];
        $totalRows     = $sessionData['totalRows'];
        // Initialiser ou récupérer les totaux financiers cumulés
        $financialTotals = $sessionData['financialTotals'] ?? ['prime_ttc' => 0.0, 'commission_ht' => 0.0, 'taxe' => 0.0];

        // Ensure 'reference_police' is mapped and available
        if (!isset($mappedColumns['reference_police'])) {
            return $this->json(['error' => 'Le mappage pour "N° de Police" est manquant dans la session.'], 400);
        }
        
        // Sécurisation de la colonne de référence pour l'indexation
        $refPoliceColumn = $mappedColumns['reference_police'];
        $refColForMatch = is_array($refPoliceColumn) ? ($refPoliceColumn[0] ?? null) : $refPoliceColumn;

        $chunk = array_slice($allRows, $offset, $chunkSize);

        $chunkPoliceRefs = array_filter(array_unique(
            array_map(fn($row) => $row[$refColForMatch] ?? null, $chunk)
        ));
        $chunkAvenants = $this->avenantRepository->findBy(['referencePolice' => $chunkPoliceRefs]);
        $chunkAvenantsMap = [];
        foreach ($chunkAvenants as $avenant) {
            $this->loadCalculatedValues(null, $avenant);
            $chunkAvenantsMap[$avenant->getReferencePolice()] = $avenant;
        }

        $chunkResultsToStore  = [];
        $chunkResultsForDisplay = [];

        foreach ($chunk as $chunkIndex => $row) {
            $rowIndex  = $offset + $chunkIndex;
            $refPolice = $row[$refColForMatch] ?? null;
            if (!$refPolice) continue;

            $avenant     = $chunkAvenantsMap[$refPolice] ?? null;
            $rawLineData = $this->reconstructRawLineData($row, $mappedColumns);
            $discrepancies = [];

            if (!$avenant) {
                $chunkResultsToStore[] = [
                    'type' => 'new',
                    'row_index' => $rowIndex,
                    'reference_police' => $refPolice,
                    'avenant_id' => null,
                ];
                $chunkResultsForDisplay[] = [
                    'type' => 'new',
                    'row_index' => $rowIndex,
                    'bordereau_line_info' => $rawLineData,
                    'details' => "Ligne n°" . ($rowIndex + 2) . ": Nouvel avenant détecté.",
                    'actions' => [[
                        'label' => 'Créer l\'avenant',
                        'event' => 'bordereau:create-avenant',
                        'payload' => [
                            'excel_data' => $rawLineData,
                            'row_index'  => $rowIndex
                        ]
                    ]],
                ];
                continue;
            }

            // ÉVALUATION DES ÉCARTS STRUCTURELS
            // On ne compare plus les montants partiels (encaissables) mais les indicateurs de totaux.
            
            $sumChargements = 0.0;
            $sumRevenus = 0.0;
            foreach ($rawLineData as $key => $value) {
                if (str_starts_with($key, 'chargement_')) $sumChargements += (float)$value;
                if (str_starts_with($key, 'revenu_')) $sumRevenus += (float)$value;
            }

            // Récupération des montants d'ajustement système existants en base
            $dbAdjPrime = 0.0;
            foreach ($avenant->getCotation()->getChargements() as $cpp) {
                if ($cpp->getNom() === \App\Entity\Chargement::SYSTEM_ADJUSTMENT_CHARGEMENT_NAME) {
                    $dbAdjPrime = (float)($cpp->getMontantFlatExceptionel() ?? 0.0);
                }
            }
            $dbAdjRevenu = 0.0;
            foreach ($avenant->getCotation()->getRevenus() as $rpc) {
                if ($rpc->getNom() === \App\Entity\TypeRevenu::SYSTEM_ADJUSTMENT_REVENU_NAME) {
                    $dbAdjRevenu = (float)($rpc->getMontantFlatExceptionel() ?? 0.0);
                }
            }

            // On met à jour les totaux de session pour les stats finales
            $financialTotals['prime_ttc']    += $sumChargements;
            $financialTotals['commission_ht'] += $sumRevenus;
            $financialTotals['taxe']          += (float)($rawLineData['taxe_commission_assureur'] ?? 0);

            $comparisons = [
                'prime_totale' => [
                    'excel_total' => round($sumChargements + $dbAdjPrime, 2),
                    'db_total'    => round((float)($avenant->primeTotale ?? 0), 2),
                    'label'       => 'Prime TTC Totale',
                ],
                'commission_ht' => [
                    'excel_total' => round($sumRevenus + $dbAdjRevenu, 2),
                    'db_total'    => round((float)($avenant->montantHT ?? 0), 2),
                    'label'       => 'Commission HT Totale',
                ],
            ];

            foreach ($comparisons as $field => $comp) {
                if ($comp['excel_total'] !== $comp['db_total']) {
                    $discrepancies[] = sprintf(
                        "%s (Excel: %s, DB: %s)",
                        $comp['label'],
                        number_format($comp['excel_total'], 2, ',', ' '),
                        number_format($comp['db_total'], 2, ',', ' ')
                    );
                }
            }

            if (!empty($discrepancies)) {
                // Préparation des écarts financiers pour l'affichage Twig
                $financialGaps = [];
                
                // On réutilise la structure de comparisons pour peupler financialGaps
                foreach ($comparisons as $field => $config) {
                    $excelValue = $config['excel_total'];
                    $dbValue    = $config['db_total'];
                    $gap        = round($excelValue - $dbValue, 2);

                    $financialGaps[$field] = [
                        'label'       => $config['label'],
                        'excel_value' => $excelValue,
                        'db_value'    => $dbValue,
                        'gap'         => $gap,
                        'gap_class'   => $gap > 0 ? 'text-success' : ($gap < 0 ? 'text-danger' : 'text-muted'),
                        'gap_sign'    => $gap > 0 ? '+' : '',
                    ];
                }

                // On garde quand même le taux de commission pour info visuelle s'il y a un écart
                if (isset($rawLineData['taux_commission'])) {
                    $excelValue = round((float)$rawLineData['taux_commission'], 2);
                    $dbValue    = round((float)($avenant->tauxCommission ?? 0) * 100, 2);
                    $gap        = round($excelValue - $dbValue, 2);

                    $financialGaps['taux_commission'] = [
                        'label'       => 'Taux commission (%)',
                        'excel_value' => $excelValue,
                        'db_value'    => $dbValue,
                        'gap'         => $gap,
                        'gap_class'   => $gap > 0 ? 'text-success' : ($gap < 0 ? 'text-danger' : 'text-muted'),
                        'gap_sign'    => $gap > 0 ? '+' : '',
                    ];
                }

                $chunkResultsToStore[] = [
                    'type' => 'discrepancy',
                    'row_index' => $rowIndex,
                    'reference_police' => $refPolice,
                    'avenant_id' => $avenant->getId(),
                ];
                $chunkResultsForDisplay[] = [
                    'type' => 'discrepancy',
                    'row_index' => $rowIndex,
                    'bordereau_line_info' => $rawLineData,
                    'details' => "Ligne n°" . ($rowIndex + 2) . ": Anomalie(s) - " . implode(', ', $discrepancies),
                    'financial_gaps' => $financialGaps,
                    'actions' => [[
                        'label' => 'Mettre à jour',
                        'event' => 'bordereau:update-avenant',
                        'payload' => [
                            'avenant_id' => $avenant->getId(),
                            'excel_data' => $rawLineData,
                            'row_index'  => $rowIndex
                        ]
                    ]],
                ];
            } else {
                $chunkResultsToStore[] = [
                    'type' => 'match',
                    'row_index' => $rowIndex,
                    'reference_police' => $refPolice,
                    'avenant_id' => $avenant->getId(),
                ];
                $chunkResultsForDisplay[] = [
                    'type' => 'match',
                    'row_index' => $rowIndex,
                    'bordereau_line_info' => $rawLineData,
                    'details' => "Ligne n°" . ($rowIndex + 2) . ": Correspondance parfaite.",
                    'actions' => [],
                ];
            }
        }

        $chunkHtml = [];
        foreach ($chunkResultsForDisplay as $i => $result) {
            $chunkHtml[] = $this->renderView('components/_analysis_result_item.html.twig', [
                'result' => $result,
                'bordereau_id' => $bordereau->getId(),
                'loop' => ['index' => $offset + $i],
                'viewData' => [
                    'display_currency_code' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    'system_field_formats' => [
                        'prime_ttc' => 'number',
                        'taux_commission' => 'number',
                        'commission_ht_assureur' => 'number',
                        'taxe_commission_assureur' => 'number',
                    ]
                ]
            ]);
        }

        $isLastChunk = ($offset + $chunkSize) >= $totalRows;
        $accumulated = $sessionData['accumulatedResults'] ?? [];
        $accumulated = array_merge($accumulated, $chunkResultsToStore);
        $sessionData['financialTotals'] = $financialTotals;
        $sessionData['accumulatedResults'] = $accumulated; // Update accumulated results in session data
        $stats = null;

        if ($isLastChunk) {
            // Calcul des statistiques de synthèse finales
            $stats = [
                'total'       => count($accumulated),
                'match'       => count(array_filter($accumulated, fn($r) => $r['type'] === 'match')),
                'discrepancy' => count(array_filter($accumulated, fn($r) => $r['type'] === 'discrepancy')),
                'new'         => count(array_filter($accumulated, fn($r) => $r['type'] === 'new')),
                'total_prime_ttc'      => round($financialTotals['prime_ttc'], 2),
                'total_commission_ht'  => round($financialTotals['commission_ht'], 2),
                'total_taxe'           => round($financialTotals['taxe'], 2),
            ];
            $stats['total_commission_ttc'] = round($stats['total_commission_ht'] + $stats['total_taxe'], 2);

            $bordereau->setAnalysisResults($accumulated);
            $bordereau->setCurrentAnalysisStep(3);
            $bordereau->setUpdatedAt(new \DateTimeImmutable());
            $this->em->persist($bordereau);
            $this->em->flush();
        }

        // Always update session data and return stats
        $request->getSession()->set($sessionKey, $sessionData);
        return $this->json([
            'chunkResultsHtml' => $chunkHtml,
            'chunkResultsStore' => $chunkResultsToStore,
            'processedCount' => $offset + count($chunk),
            'totalRows' => $totalRows,
            'isLastChunk' => $isLastChunk,
            'stats' => $stats
        ]);
    }

    /**
     * Lit le fichier Excel d'un bordereau et retourne les données de la feuille.
     */
    private function _loadSheetData(Bordereau $bordereau, string $sheetName, ParameterBagInterface $params): array|JsonResponse
    {
        $allowedExtensions = ['xlsx', 'xls', 'ods'];
        $excelDocument = null;

        foreach ($bordereau->getDocuments() as $doc) {
            if ($doc->getNomFichierStocke()) {
                $ext = pathinfo($doc->getNomFichierStocke(), PATHINFO_EXTENSION);
                if (in_array(strtolower($ext), $allowedExtensions)) {
                    $excelDocument = $doc;
                    break;
                }
            }
        }

        if (!$excelDocument) return $this->json(['error' => "Aucun fichier Excel valide n'est attaché."], 400);

        $filePath = $params->get('kernel.project_dir') . '/public/uploads/documents/' . $excelDocument->getNomFichierStocke();

        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet   = $spreadsheet->getSheetByName($sheetName);
            if (!$worksheet) return $this->json(['error' => "Feuille '$sheetName' introuvable."], 400);
            $data = $worksheet->toArray(null, true, false, true);
            array_shift($data);
            return $data;
        } catch (ReaderException $e) {
            return $this->json(['error' => "Erreur lecture Excel : " . $e->getMessage()], 500);
        }
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
            // CORRECTION 5 : Protection contre l'écrasement par un tableau vide
            $newMappedColumns = $payload['mappedColumns'];
            if (!empty($newMappedColumns) || empty($bordereau->getMappedColumns())) {
                $bordereau->setMappedColumns($newMappedColumns);
            }
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
            'mappedColumns' => $bordereau->getMappedColumns(),
            'analysisResults' => $bordereau->getAnalysisResults(),
            'currentAnalysisStep' => $bordereau->getCurrentAnalysisStep()
        ]);

        $this->em->persist($bordereau);
        $this->em->flush();

        return $this->json(['message' => 'État de l\'analyse enregistré avec succès.']);
    }

    /**
     * SIMULATION : Simule le traitement d'une ligne d'analyse (new ou discrepancy).
     * Cette route sera remplacée par la vraie logique métier ultérieurement.
     *
     * TODO: Remplacer cette simulation par la vraie logique métier :
     *   - Pour type "new"         : créer réellement l'avenant en base
     *   - Pour type "discrepancy" : mettre à jour réellement l'avenant en base
     */
    #[Route('/api/simulate-action/{bordereauId}', name: 'api.simulate_action', methods: ['POST'])]
    public function simulateAnalysisAction(
        int $bordereauId,
        Request $request
    ): JsonResponse {
        // Récupérer le bordereau pour vérifier qu'il existe
        $bordereau = $this->bordereauRepository->find($bordereauId);
        if (!$bordereau) {
            return $this->json(['success' => false, 'message' => 'Bordereau introuvable.'], 404);
        }

        $raw  = json_decode($request->getContent(), true);
        $data = $raw['payload'] ?? $raw;

        try {
            $result = $this->doProcessAnalysisAction($bordereau, $data);
            if (!$result['success']) {
                return $this->json($result, 400);
            }

            return $this->json($result);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * SIMULATION : Traite en lot plusieurs lignes d'analyse en une seule requête.
     * Reçoit un tableau d'actions à simuler et retourne le résultat pour chacune.
     *
     * TODO: Remplacer cette simulation par la vraie logique métier :
     *   - Pour chaque item de type 'new'         : créer réellement l'avenant en base
     *   - Pour chaque item de type 'discrepancy' : mettre à jour réellement l'avenant
     *
     * @Route("/admin/bordereau/api/simulate-batch-action/{bordereauId}", methods=["POST"])
     */
    #[Route(
        '/api/simulate-batch-action/{bordereauId}',
        name: 'admin_bordereau_api_simulate_batch_action',
        methods: ['POST']
    )]
    public function simulateBatchAnalysisAction(int $bordereauId, Request $request): JsonResponse
    {
        $bordereau = $this->bordereauRepository->find($bordereauId);
        if (!$bordereau) {
            return $this->json([
                'success' => false,
                'message' => 'Bordereau introuvable.'
            ], 404);
        }
        $raw   = json_decode($request->getContent(), true);
        $body  = $raw['payload'] ?? $raw;
        $items = $body['items'] ?? ($raw['items'] ?? []);

        if (empty($items)) return $this->json(['success' => false, 'message' => 'Aucun élément à traiter.'], 400);

        $results = [];
        foreach ($items as $item) {
            try {
                $res = $this->doProcessAnalysisAction($bordereau, $item);
                $results[] = [
                    'success'          => $res['success'],
                    'row_index'        => $res['resolved_row_index'] ?? null,
                    'message'          => $res['message']
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'success'   => false,
                    'row_index' => $item['row_index'] ?? null,
                    'message'   => $e->getMessage(),
                ];
            }
        }

        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $failCount    = count($results) - $successCount;

        return $this->json([
            'success' => $failCount === 0,
            'results' => $results,
            'message' => sprintf('%d élément(s) traité(s) avec succès%s.', $successCount, $failCount > 0 ? ", $failCount en échec" : ''),
        ]);
    }

    /**
     * Méthode centralisée (DRY) pour traiter une action d'analyse.
     * Sécurise l'assignation de l'entreprise pour éviter les violations d'intégrité.
     */
    private function doProcessAnalysisAction(Bordereau $bordereau, array $data): array
    {
        $actionType = $data['action_type'] ?? null;
        $excelData  = $data['excel_data'] ?? [];
        $rowIndex   = isset($data['row_index']) ? (int)$data['row_index'] : null;
        
        if ($rowIndex === null) throw new \Exception("Index de ligne manquant.");
        
        // On sécurise la récupération de l'entreprise et de l'invité en utilisant le bordereau comme source de vérité.
        $entreprise = $this->getEntreprise() ?? $bordereau->getEntreprise();
        $invite     = $this->getInvite()     ?? $bordereau->getInvite();

        if ($actionType === 'new') {
            $avenant = $this->avenantActionService->createFromBordereauLine($excelData, $bordereau, $invite);

            // Propagation exhaustive et récursive de l'entreprise et de l'invité sur tout le graph d'objets (Fix SQL 1048)
            $this->propagateAuditInfo($avenant, $entreprise, $invite);
            $this->em->flush();

            $this->_markAnalysisResultAsMatch($bordereau, $rowIndex, $avenant->getId(), $avenant->getReferencePolice());

            return [
                'success' => true,
                'message' => sprintf('Ligne n°%s : Avenant créé avec succès.', $rowIndex + 2),
                'resolved_row_index' => $rowIndex
            ];
        } 
        
        if ($actionType === 'discrepancy') {
            $avenant = $this->avenantRepository->find($data['avenant_id'] ?? null);
            if (!$avenant) throw new \Exception("Avenant introuvable.");
            
            $this->avenantActionService->updateFromBordereauLine($avenant, $excelData, $bordereau);
            
            // NOUVEAU : On sécurise aussi les éventuelles nouvelles entités créées lors d'une mise à jour (ex: Note, Paiement)
            $this->propagateAuditInfo($avenant, $entreprise, $invite);
            
            $this->_markAnalysisResultAsMatch($bordereau, $rowIndex, $avenant->getId(), $avenant->getReferencePolice());
            
            return [
                'success' => true,
                'message' => sprintf('Ligne n°%s : Avenant mis à jour avec succès.', $rowIndex + 2),
                'resolved_row_index' => $rowIndex
            ];
        }

        throw new \Exception("Action d'analyse inconnue.");
    }

    /**
     * Sécurise toute la cascade d'objets en injectant l'entreprise et l'invité.
     * Utilise le UnitOfWork de Doctrine pour intercepter TOUTES les entités découvertes via cascade.
     */
    private function propagateAuditInfo(?object $entity, Entreprise $entreprise, Invite $invite): void
    {
        if (!$entity || !$entreprise) return;

        // 1. On demande à Doctrine de persister l'entité racine (déclenche la cascade persist)
        $this->em->persist($entity);

        $uow = $this->em->getUnitOfWork();

        // 2. CRUCIAL : On force le calcul des ChangeSets.
        // Cette instruction déclenche la découverte récursive de toutes les entités 
        // liées via 'cascade: persist'. Sans cela, getScheduledEntityInsertions() 
        // ne contient souvent que l'entité racine au moment de l'appel.
        $uow->computeChangeSets();

        // 3. On récupère maintenant la liste exhaustive des entités prêtes à être insérées
        $insertions = $uow->getScheduledEntityInsertions();
        $updates = $uow->getScheduledEntityUpdates();
        $allScheduled = array_merge($insertions, $updates);

        // DIAGNOSTIC : On dump les informations pour voir ce que Doctrine a découvert
        if (function_exists('dump')) {
            dump("--- DEBUT PROPAGATION AUDIT ---");
            dump("Entité racine : " . get_class($entity));
            dump("Total entités détectées (insert/update) : " . count($allScheduled));
        }

        foreach ($allScheduled as $entityToAudit) {
            $className = get_class($entityToAudit);
            
            // Vérification pour l'Entreprise
            if (method_exists($entityToAudit, 'setEntreprise')) {
                $currentE = method_exists($entityToAudit, 'getEntreprise') ? $entityToAudit->getEntreprise() : null;
                
                if (null === $currentE) {
                    if (function_exists('dump')) dump("-> Injection Entreprise dans : " . $className);
                    $entityToAudit->setEntreprise($entreprise);
                }
            } else {
                // Si l'entité n'a pas de setEntreprise mais qu'elle est dans le graph, 
                // c'est peut-être elle qui cause l'erreur SQL si son champ est NOT NULL en base.
                if (function_exists('dump')) dump("!! ATTENTION : Aucune méthode setEntreprise sur " . $className);
            }

            // Vérification pour l'Invité (Correction du bug de typo setInvite -> getInvite)
            if (method_exists($entityToAudit, 'setInvite')) {
                $currentI = method_exists($entityToAudit, 'getInvite') ? $entityToAudit->getInvite() : null;
                
                if (null === $currentI) {
                    if (function_exists('dump')) dump("-> Injection Invite dans : " . $className);
                    $entityToAudit->setInvite($invite);
                }
            }
        }
        if (function_exists('dump')) dump("--- FIN PROPAGATION AUDIT ---");
    }

    /**
     * Valide le bordereau après que tous les résultats ont été traités.
     * Change le statut vers STATUT_VALIDE.
     */
    #[Route('/api/validate/{bordereauId}', name: 'api.validate', methods: ['POST'])]
    public function validateBordereau(
        int $bordereauId
    ): JsonResponse {
        $bordereau = $this->bordereauRepository->find($bordereauId);
        if (!$bordereau) {
            return $this->json(['success' => false, 'message' => 'Bordereau introuvable.'], 404);
        }

        // Changer le statut vers VALIDE
        $bordereau->setCurrentAnalysisStep(Bordereau::STATUT_ANALYSE_TERMINEE);
        $bordereau->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        // TODO: Ici seront ajoutées ultérieurement :
        //   - La notification au comptable (MailerInterface déjà injecté)
        //   - La création d'un enregistrement d'historique de validation
        //   - Le déclenchement du workflow de paiement des commissions

        return $this->json([
            'success' => true,
            'message' => 'Le bordereau a été validé avec succès.',
        ]);
    }

    /**
     * Génère et télécharge le rapport PDF de l'analyse du bordereau.
     * N'inclut que les résultats discrepancy et new (les match ne sont pas actionnables).
     */
    #[Route('/api/export-analysis-pdf/{id}', name: 'api.export_analysis_pdf', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function exportAnalysisPdf(
        Bordereau $bordereau,
        ParameterBagInterface $params
    ): Response {
        $analysisResults = $bordereau->getAnalysisResults() ?? [];

        if (empty($analysisResults)) {
            return $this->json([
                'error' => 'Aucun résultat d\'analyse disponible. Lancez l\'analyse avant d\'exporter.'
            ], 400);
        }

        // Filtrer : exclure les match et warning du rapport
        $reportableResults = array_filter(
            $analysisResults,
            fn($r) => in_array($r['type'] ?? '', ['discrepancy', 'new'])
        );

        // Statistiques pour le récapitulatif
        $stats = [
            'total'       => count($analysisResults),
            'match'       => count(array_filter($analysisResults, fn($r) => ($r['type'] ?? '') === 'match')),
            'discrepancy' => count(array_filter($analysisResults, fn($r) => ($r['type'] ?? '') === 'discrepancy')),
            'new'         => count(array_filter($analysisResults, fn($r) => ($r['type'] ?? '') === 'new')),
        ];
        $stats['conformity_rate'] = $stats['total'] > 0
            ? round(($stats['match'] / $stats['total']) * 100, 1)
            : 0;

        // Reconstruire les données des lignes pour le PDF depuis le fichier Excel
        $mappedColumns   = $bordereau->getMappedColumns() ?: [];
        $selectedSheet   = $bordereau->getSelectedSheetName();
        $enrichedResults = [];

        if (!empty($reportableResults) && !empty($mappedColumns) && !empty($selectedSheet)) {
            $sheetData = $this->_loadSheetData($bordereau, $selectedSheet, $params);

            if (is_array($sheetData)) {
                // Indexer par row_index pour accès rapide
                $rowsByIndex = array_values($sheetData);

                foreach ($reportableResults as $stored) {
                    $rowIndex  = $stored['row_index'] ?? null;
                    $lineData = ($rowIndex !== null && isset($rowsByIndex[$rowIndex]))
                        ? $this->reconstructRawLineData($rowsByIndex[$rowIndex], $mappedColumns)
                        : [];

                    $enrichedResults[] = array_merge($stored, ['line_data' => $lineData]);
                }
            }
        } else {
            foreach ($reportableResults as $stored) {
                $enrichedResults[] = array_merge($stored, ['line_data' => []]);
            }
        }

        // Rendre le template HTML du rapport
        $html = $this->renderView('admin/bordereau/pdf/analysis_report.html.twig', [
            'bordereau'       => $bordereau,
            'entreprise'      => $this->getEntreprise(),
            'results'         => $enrichedResults,
            'stats'           => $stats,
            'generatedAt'     => new \DateTimeImmutable(),
            'mappingOptions'  => [
                'reference_police'          => 'N° de Police',
                'date_effet_avenant'        => 'Date d\'effet',
                'date_expiration_avenant'   => 'Date d\'expiration',
                'date_operation'            => 'Date d\'opération',
                'prime_ttc'                 => 'Prime TTC',
                'nom_client'                => 'Assuré',
                'commission_ht_assureur'    => 'Commission HT',
                'taxe_commission_assureur'  => 'Taxe commission',
                'taux_commission'           => 'Taux commission (%)',
            ],
        ]);

        // Générer le PDF avec Dompdf
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->getOptions()->setChroot(
            $params->get('kernel.project_dir') . '/public'
        );
        $dompdf->getOptions()->setIsRemoteEnabled(false);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape'); // Paysage pour les tableaux larges
        $dompdf->render();

        // Nom du fichier
        $filename = sprintf(
            'rapport-analyse-%s-%s.pdf',
            $bordereau->getReference(),
            (new \DateTimeImmutable())->format('Y-m-d')
        );

        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    /**
     * Met à jour une entrée dans analysisResults du bordereau pour la marquer comme 'match'.
     * Appelé après qu'un avenant a été créé ou mis à jour depuis l'étape 3.
     */
    private function _markAnalysisResultAsMatch(
        Bordereau $bordereau,
        int $rowIndex,
        int $avenantId,
        string $referencePolice
    ): void {
        $results = $bordereau->getAnalysisResults() ?? [];
        foreach ($results as &$result) {
            if (($result['row_index'] ?? null) === $rowIndex) {
                $result['type']              = 'match';
                $result['avenant_id']        = $avenantId;
                $result['reference_police']  = $referencePolice;
                break;
            }
        }
        unset($result);
        $bordereau->setAnalysisResults($results);
        $bordereau->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();
    }
}
