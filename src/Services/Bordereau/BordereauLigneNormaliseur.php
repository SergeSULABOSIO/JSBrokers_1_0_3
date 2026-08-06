<?php

namespace App\Services\Bordereau;

/**
 * Normalisation d'une ligne de bordereau Excel vers les champs système.
 *
 * SOURCE UNIQUE de cette traduction, partagée par l'analyse interactive (ControllerUtilsTrait,
 * qui délègue ici) et par le rattrapage en ligne de commande. Une seconde implémentation
 * ferait diverger les montants persistés de ceux affichés à l'écran, pour le même fichier.
 *
 * Fonctions PURES, donc statiques : aucune dépendance, aucun état — même parti que les
 * classes de périmètre (TranchePaiementScope, AvenantEcheanceScope).
 */
final class BordereauLigneNormaliseur
{
    /**
     * Champs dont la valeur est un MONTANT : nettoyés des séparateurs, additionnés quand
     * plusieurs colonnes Excel alimentent le même champ système.
     */
    private const CHAMPS_NUMERIQUES = [
        'prime_ttc',
        'commission_ht_payable_now',
        'taxe_commission_payable_now',
        'taux_commission',
    ];

    public static function estNumerique(string $systemField): bool
    {
        return str_starts_with($systemField, 'chargement_')
            || str_starts_with($systemField, 'revenu_')
            || in_array($systemField, self::CHAMPS_NUMERIQUES, true);
    }

    /**
     * Reconstruit les données système à partir d'une ligne Excel brute et des colonnes
     * mappées. Gère l'agrégation (somme) si plusieurs colonnes alimentent un même champ.
     *
     * @param array<string, mixed> $row
     * @param array<string, string|array<int, string>> $mappedColumns
     * @return array<string, mixed>
     */
    public static function normaliserLigne(array $row, array $mappedColumns): array
    {
        $rawLineData = [];
        foreach ($mappedColumns as $systemField => $excelColumns) {
            if (is_array($excelColumns)) {
                $isNumericField = self::estNumerique($systemField);
                $sum = 0.0;
                $textValue = null;
                foreach ($excelColumns as $col) {
                    $val = self::normaliserValeur($row[$col] ?? null, $systemField);
                    if ($isNumericField && is_numeric($val)) {
                        $sum += (float) $val;
                    } elseif ($val !== null && $textValue === null) {
                        $textValue = $val;
                    }
                }
                $rawLineData[$systemField] = $isNumericField ? $sum : $textValue;
            } else {
                // Comportement standard 1:1
                $rawLineData[$systemField] = self::normaliserValeur($row[$excelColumns] ?? null, $systemField);
            }
        }

        return $rawLineData;
    }

    /**
     * Convertit une cellule Excel selon le champ système visé : montants nettoyés de leurs
     * séparateurs, dates ramenées en Y-m-d, numéro d'avenant en chaîne (« 3.0 » → « 3 »).
     */
    public static function normaliserValeur(mixed $value, string $systemField): mixed
    {
        if ($value === null || $value === '') {
            return $systemField === 'num_avenant' ? '0' : null;
        }

        if (self::estNumerique($systemField)) {
            if (is_string($value)) {
                $cleanedValue = str_replace([' ', "\u{00A0}"], '', $value);
                $cleanedValue = str_replace(',', '.', $cleanedValue);
                // Séparateur de milliers en point : seul le DERNIER point est décimal.
                if (substr_count($cleanedValue, '.') > 1) {
                    $lastDotPos = strrpos($cleanedValue, '.');
                    if ($lastDotPos !== false) {
                        $cleanedValue = str_replace('.', '', substr($cleanedValue, 0, $lastDotPos))
                            . substr($cleanedValue, $lastDotPos);
                    }
                }

                return (float) $cleanedValue;
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
                }
                if (is_string($value)) {
                    try {
                        return (new \DateTimeImmutable($value))->format('Y-m-d');
                    } catch (\Exception) {
                        return null;
                    }
                }

                return null;
            case 'num_avenant':
                if (is_float($value) && floor($value) == $value) {
                    return (string) (int) $value; // 3.0 → "3"
                }

                return (string) $value;
            default:
                return $value;
        }
    }
}
