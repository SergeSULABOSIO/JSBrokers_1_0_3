<?php

namespace App\Services;

use App\Entity\Bordereau;
use App\Entity\Chargement;
use App\Repository\ChargementRepository;
use DateTimeImmutable;
use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Environment;

class BordereauAnalysisPdfService
{
    public function __construct(
        private ParameterBagInterface $params,
        private ChargementRepository $chargementRepository,
        private Environment $twig,
    ) {}

    /**
     * Génère le PDF d'analyse du bordereau en mémoire et retourne les bytes.
     * Retourne null si aucun résultat d'analyse n'est disponible.
     *
     * @param bool $matchOnly Si true, n'inclut que les résultats de type 'match' (conformes).
     */
    public function generatePdfString(Bordereau $bordereau, bool $matchOnly = false): ?string
    {
        $analysisResults = $bordereau->getAnalysisResults() ?? [];

        if (empty($analysisResults)) {
            return null;
        }

        $reportableResults = $matchOnly
            ? array_values(array_filter($analysisResults, fn($r) => ($r['type'] ?? '') === 'match'))
            : $analysisResults;

        if ($matchOnly && empty($reportableResults)) {
            return null;
        }

        $stats = [
            'total'       => count($analysisResults),
            'match'       => count(array_filter($analysisResults, fn($r) => ($r['type'] ?? '') === 'match')),
            'discrepancy' => count(array_filter($analysisResults, fn($r) => ($r['type'] ?? '') === 'discrepancy')),
            'new'         => count(array_filter($analysisResults, fn($r) => ($r['type'] ?? '') === 'new')),
        ];
        $stats['conformity_rate'] = $stats['total'] > 0
            ? round(($stats['match'] / $stats['total']) * 100, 1)
            : 0;

        $mappedColumns = $bordereau->getMappedColumns() ?: [];
        $selectedSheet = $bordereau->getSelectedSheetName();
        $enrichedResults = [];

        if (!empty($reportableResults) && !empty($mappedColumns) && !empty($selectedSheet)) {
            $sheetData = $this->loadSheetData($bordereau, $selectedSheet);

            if (is_array($sheetData)) {
                $rowsByIndex = array_values($sheetData);

                foreach ($reportableResults as $stored) {
                    $rowIndex = $stored['row_index'] ?? null;
                    $lineData = ($rowIndex !== null && isset($rowsByIndex[$rowIndex]))
                        ? $this->normalizeLineDataForPdf(
                            $this->reconstructRawLineData($rowsByIndex[$rowIndex], $mappedColumns)
                        )
                        : [];

                    $enrichedResults[] = array_merge($stored, ['line_data' => $lineData]);
                }
            }
        } else {
            foreach ($reportableResults as $stored) {
                $enrichedResults[] = array_merge($stored, ['line_data' => []]);
            }
        }

        $html = $this->twig->render('admin/bordereau/pdf/analysis_report.html.twig', [
            'bordereau'      => $bordereau,
            'entreprise'     => null,
            'results'        => $enrichedResults,
            'stats'          => $stats,
            'generatedAt'    => new DateTimeImmutable(),
            'matchOnly'      => $matchOnly,
            'mappingOptions' => [
                'num_avenant'                  => 'N° Avenant',
                'reference_police'             => 'N° de Police',
                'date_effet_avenant'           => 'Date d\'effet',
                'date_expiration_avenant'      => 'Date d\'expiration',
                'date_operation'               => 'Date d\'opération',
                'prime_ttc'                    => 'Prime TTC',
                'nom_client'                   => 'Assuré',
                'commission_ht_payable_now'    => 'Commission ht payable now',
                'taxe_commission_payable_now'  => 'Taxe / Commission ht payable now',
                'taux_commission'              => 'Taux commission (%)',
            ],
        ]);

        $dompdf = new Dompdf();
        $dompdf->getOptions()->setChroot($this->params->get('kernel.project_dir') . '/public');
        $dompdf->getOptions()->setIsRemoteEnabled(false);
        $dompdf->getOptions()->setIsPhpEnabled(true);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    private function loadSheetData(Bordereau $bordereau, string $sheetName): array
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

        if (!$excelDocument) {
            return [];
        }

        $filePath = $this->params->get('kernel.project_dir') . '/public/uploads/documents/' . $excelDocument->getNomFichierStocke();

        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet   = $spreadsheet->getSheetByName($sheetName);
            if (!$worksheet) {
                return [];
            }
            $allRows = $worksheet->toArray(null, true, false, true);
            array_shift($allRows);

            $dataRows = array_filter($allRows, function ($row) {
                return !empty(array_filter($row, fn($cell) => $cell !== null && $cell !== ''));
            });

            return array_values($dataRows);
        } catch (ReaderException) {
            return [];
        }
    }

    private function normalizeLineDataForPdf(array $lineData): array
    {
        $primeNette = 0.0;
        foreach ($lineData as $key => $val) {
            if (!str_starts_with($key, 'chargement_')) {
                continue;
            }
            $parts  = explode('_', $key, 3);
            $typeId = isset($parts[1]) ? (int) $parts[1] : 0;
            if ($typeId === 0) {
                continue;
            }
            $chargement = $this->chargementRepository->find($typeId);
            if ($chargement && $chargement->getFonction() === Chargement::FONCTION_PRIME_NETTE) {
                $primeNette += (float) $val;
            }
        }

        $commissionHtAssureur = 0.0;
        foreach ($lineData as $key => $val) {
            if (str_starts_with($key, 'revenu_')) {
                $commissionHtAssureur += (float) $val;
            }
        }

        return array_merge($lineData, [
            'prime_nette'            => $primeNette !== 0.0 ? $primeNette : null,
            'commission_ht_assureur' => $commissionHtAssureur !== 0.0 ? $commissionHtAssureur : null,
        ]);
    }

    private function reconstructRawLineData(array $row, array $mappedColumns): array
    {
        $rawLineData = [];
        foreach ($mappedColumns as $systemField => $excelColumns) {
            if (is_array($excelColumns)) {
                $isNumericField = str_starts_with($systemField, 'chargement_') ||
                    str_starts_with($systemField, 'revenu_') ||
                    in_array($systemField, ['prime_ttc', 'commission_ht_payable_now', 'taxe_commission_payable_now', 'taux_commission']);
                $sum       = 0.0;
                $textValue = null;
                foreach ($excelColumns as $col) {
                    $val = $this->parseExcelValue($row[$col] ?? null, $systemField);
                    if ($isNumericField && is_numeric($val)) {
                        $sum += (float) $val;
                    } elseif ($val !== null && $textValue === null) {
                        $textValue = $val;
                    }
                }
                $rawLineData[$systemField] = $isNumericField ? $sum : $textValue;
            } else {
                $rawLineData[$systemField] = $this->parseExcelValue($row[$excelColumns] ?? null, $systemField);
            }
        }
        return $rawLineData;
    }

    private function parseExcelValue(mixed $value, string $systemField): mixed
    {
        if ($value === null || $value === '') {
            if ($systemField === 'num_avenant') {
                return '0';
            }
            return null;
        }

        $isNumericField = str_starts_with($systemField, 'chargement_') ||
            str_starts_with($systemField, 'revenu_') ||
            in_array($systemField, ['prime_ttc', 'commission_ht_payable_now', 'taxe_commission_payable_now', 'taux_commission']);

        if ($isNumericField) {
            if (is_string($value)) {
                $cleaned = str_replace([' ', "\u{00A0}"], '', $value);
                $cleaned = str_replace(',', '.', $cleaned);
                if (substr_count($cleaned, '.') > 1) {
                    $lastDot = strrpos($cleaned, '.');
                    if ($lastDot !== false) {
                        $cleaned = str_replace('.', '', substr($cleaned, 0, $lastDot)) . substr($cleaned, $lastDot);
                    }
                }
                return (float) $cleaned;
            }
            return (float) $value;
        }

        switch ($systemField) {
            case 'date_effet_avenant':
            case 'date_expiration_avenant':
            case 'date_operation':
                if (is_numeric($value)) {
                    try {
                        $dateObj = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
                        return $dateObj instanceof \DateTimeInterface ? $dateObj->format('Y-m-d') : null;
                    } catch (\Exception) {
                        return null;
                    }
                } elseif (is_string($value)) {
                    try {
                        return (new \DateTimeImmutable($value))->format('Y-m-d');
                    } catch (\Exception) {
                        return null;
                    }
                }
                return null;
            case 'num_avenant':
                if (is_float($value) && floor($value) == $value) {
                    return (string) (int) $value;
                }
                return (string) $value;
            default:
                return $value;
        }
    }
}
