<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RATTRAPAGE du référentiel SEMÉ : les taux posés en dur à la création d'une
 * entreprise n'avaient pas suivi la conversion fraction → POINTS.
 *
 * Version20260725110000 a converti les DONNÉES en base (×100), mais pas les
 * valeurs écrites en dur dans le code de semis : `assets/data/risques_defaut.json`
 * (43 risques, commissions 0,05 à 0,25) et `ServiceInitialisationEntreprise`
 * (Commission sur Fronting 0,30 ; Frais de consultance 0,05 ; Honoraire de
 * gestion 0,02). Conséquence : toute entreprise créée DEPUIS le 25 juillet 2026
 * démarre avec un référentiel de commissions divisé par cent — 0,15 % au lieu de
 * 15 % — et toute commission calculée dessus est 100× trop faible, en silence.
 *
 * Le code est corrigé ; cette migration répare les entreprises déjà créées.
 *
 * PRÉCISION DU CIBLAGE (le point délicat) — on ne peut pas rejouer un « ×100 sur
 * tout ce qui est ≤ 1 » : un taux légitimement inférieur à 1 % y passerait aussi.
 * Deux garde-fous cumulés restreignent la reprise aux seules lignes SEMÉES et
 * NON MODIFIÉES :
 *   1. l'identité de la ligne doit être celle d'un élément du référentiel semé
 *      (code de risque connu, ou l'un des trois noms de type de revenu) ;
 *   2. la valeur doit être EXACTEMENT l'une des valeurs de semis fractionnaires
 *      (comparaison arrondie à 4 décimales, la colonne étant flottante).
 * Une ligne retouchée par l'utilisateur ne correspond plus et reste intacte.
 *
 * IDEMPOTENTE : après conversion les valeurs valent 5 à 30, aucune ne figure plus
 * dans la liste des valeurs fractionnaires — un second passage ne fait rien.
 *
 * Irréversible : au retour, un taux légitime et un taux rattrapé seraient
 * indiscernables (même raison que Version20260725110000).
 */
final class Version20260807140000 extends AbstractMigration
{
    /** Les 43 codes du référentiel semé (assets/data/risques_defaut.json). */
    private const CODES_RISQUES_SEMES = [
        'IARD-AC-GRP', 'IARD-AC-IND', 'IARD-AGRI', 'IARD-ASS', 'IARD-CAU',
        'IARD-CRED', 'IARD-DOM-AERO', 'IARD-DOM-AUTO', 'IARD-DOM-BIENS', 'IARD-DOM-FER',
        'IARD-DOM-MAR', 'IARD-ENG', 'IARD-INC-AUT', 'IARD-INC-MRH', 'IARD-INFO',
        'IARD-MAL-GRP', 'IARD-MAL-IND', 'IARD-MINE', 'IARD-PEC', 'IARD-PETRO',
        'IARD-PJ', 'IARD-POL', 'IARD-RC-AERO', 'IARD-RC-AUTO-FLO', 'IARD-RC-AUTO-IND',
        'IARD-RC-FER', 'IARD-RC-GEN', 'IARD-RC-MAR', 'IARD-TRANS', 'VIE-COL-AUT',
        'VIE-COL-CAP', 'VIE-COL-COM', 'VIE-COL-DEC', 'VIE-COL-EPA', 'VIE-COL-MIX',
        'VIE-COL-VIE', 'VIE-IND-AUT', 'VIE-IND-CAP', 'VIE-IND-COM', 'VIE-IND-DEC',
        'VIE-IND-EPA', 'VIE-IND-MIX', 'VIE-IND-VIE',
    ];

    /** Valeurs FRACTIONNAIRES semées (signature d'une ligne jamais retouchée). */
    private const COMMISSIONS_FRACTIONNAIRES = [0.05, 0.08, 0.1, 0.11, 0.125, 0.15, 0.16, 0.175, 0.2, 0.25];

    /** Types de revenu semés : nom => taux fractionnaire d'origine. */
    private const TYPES_REVENU_SEMES = [
        'Commission sur Fronting' => 0.30,
        'Frais de consultance'    => 0.05,
        'Honoraire de gestion'    => 0.02,
    ];

    public function getDescription(): string
    {
        return 'Référentiel semé (risques + types de revenu) : rattrapage fraction → points (×100)';
    }

    public function up(Schema $schema): void
    {
        $codes = "'" . implode("', '", self::CODES_RISQUES_SEMES) . "'";
        $valeurs = implode(', ', self::COMMISSIONS_FRACTIONNAIRES);

        $this->addSql(sprintf(
            'UPDATE risque SET pourcentage_commission_specifique_ht = pourcentage_commission_specifique_ht * 100 '
            . 'WHERE code IN (%s) AND pourcentage_commission_specifique_ht IS NOT NULL '
            . 'AND ROUND(pourcentage_commission_specifique_ht, 4) IN (%s)',
            $codes,
            $valeurs,
        ));

        foreach (self::TYPES_REVENU_SEMES as $nom => $fraction) {
            $this->addSql(
                'UPDATE type_revenu SET pourcentage = pourcentage * 100 '
                . 'WHERE nom = :nom AND pourcentage IS NOT NULL AND ROUND(pourcentage, 4) = :fraction',
                ['nom' => $nom, 'fraction' => $fraction],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Rattrapage de taux irréversible : un taux légitimement inférieur à 1 % ne se distingue '
            . 'pas d’un taux rattrapé.',
        );
    }
}
