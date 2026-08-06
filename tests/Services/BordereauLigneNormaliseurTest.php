<?php

namespace App\Tests\Services;

use App\Services\Bordereau\BordereauLigneNormaliseur;
use PHPUnit\Framework\TestCase;

/**
 * Normalisation d'une ligne de bordereau Excel.
 *
 * Ce code était jusqu'ici enfoui dans le trait des contrôleurs et n'avait qu'un seul
 * appelant. Il en a désormais DEUX — l'analyse interactive et le rattrapage en ligne de
 * commande — et ils doivent produire les mêmes montants pour un même fichier : c'est ce que
 * ce test verrouille. Une divergence ferait diverger les montants persistés de ceux affichés
 * à l'écran, sans que rien ne le signale.
 */
class BordereauLigneNormaliseurTest extends TestCase
{
    /**
     * Les montants d'un bordereau arrivent tels que l'assureur les a tapés : espaces
     * insécables, virgule décimale, points de milliers. Une conversion naïve en float
     * tronquerait « 75 908,67 » à 75.
     */
    public function testLesMontantsSontNettoyesDeLeursSeparateurs(): void
    {
        $cas = [
            '75 908,67' => 75908.67,
            "1\u{00A0}234,50" => 1234.50,
            '1.234.567,89' => 1234567.89,
            '4151.67' => 4151.67,
            '0' => 0.0,
        ];

        foreach ($cas as $brut => $attendu) {
            $this->assertEqualsWithDelta(
                $attendu,
                BordereauLigneNormaliseur::normaliserValeur($brut, 'commission_ht_payable_now'),
                0.001,
                sprintf('« %s » doit valoir %s.', $brut, $attendu),
            );
        }
    }

    /**
     * Plusieurs colonnes Excel peuvent alimenter un même champ système (une commission
     * éclatée en plusieurs rubriques) : elles s'ADDITIONNENT. C'est ce qui fait que la
     * somme des lignes retombe sur montantPayableNow.
     */
    public function testPlusieursColonnesAlimentantUnMemeChampSAdditionnent(): void
    {
        $ligne = BordereauLigneNormaliseur::normaliserLigne(
            ['C' => '1 000,50', 'D' => '234,50', 'E' => 'POL-1'],
            [
                'commission_ht_payable_now' => ['C', 'D'],
                'reference_police' => 'E',
            ],
        );

        $this->assertEqualsWithDelta(1235.0, $ligne['commission_ht_payable_now'], 0.001);
        $this->assertSame('POL-1', $ligne['reference_police']);
    }

    /**
     * Un champ TEXTE mappé sur plusieurs colonnes retient la première valeur non vide,
     * jamais une somme — sans quoi une référence de police deviendrait un nombre.
     */
    public function testUnChampTexteMultiColonnesRetientLaPremiereValeur(): void
    {
        $ligne = BordereauLigneNormaliseur::normaliserLigne(
            ['A' => null, 'B' => 'POL-XYZ', 'C' => 'POL-AUTRE'],
            ['reference_police' => ['A', 'B', 'C']],
        );

        $this->assertSame('POL-XYZ', $ligne['reference_police']);
    }

    public function testNumeroDAvenantAbsentVautZeroEtNestJamaisUnFlottant(): void
    {
        // Une cellule vide vaut « 0 » : c'est la clé d'appariement avec l'avenant en base.
        $this->assertSame('0', BordereauLigneNormaliseur::normaliserValeur(null, 'num_avenant'));
        $this->assertSame('0', BordereauLigneNormaliseur::normaliserValeur('', 'num_avenant'));
        // Excel rend souvent les entiers en flottants : « 3.0 » doit redevenir « 3 ».
        $this->assertSame('3', BordereauLigneNormaliseur::normaliserValeur(3.0, 'num_avenant'));
    }

    public function testLesDatesExcelDeviennentDesChainesIso(): void
    {
        // 45292 = 1er janvier 2024 dans le calendrier série d'Excel.
        $this->assertSame('2024-01-01', BordereauLigneNormaliseur::normaliserValeur(45292, 'date_effet_avenant'));
        $this->assertSame('2026-03-15', BordereauLigneNormaliseur::normaliserValeur('2026-03-15', 'date_operation'));
        $this->assertNull(BordereauLigneNormaliseur::normaliserValeur('pas une date', 'date_operation'));
    }

    public function testLesChampsDynamiquesSontReconnusCommeNumeriques(): void
    {
        // Les chargements et revenus sont nommés dynamiquement d'après le référentiel.
        $this->assertTrue(BordereauLigneNormaliseur::estNumerique('chargement_prime_nette'));
        $this->assertTrue(BordereauLigneNormaliseur::estNumerique('revenu_commission'));
        $this->assertTrue(BordereauLigneNormaliseur::estNumerique('taxe_commission_payable_now'));
        $this->assertFalse(BordereauLigneNormaliseur::estNumerique('reference_police'));
        $this->assertFalse(BordereauLigneNormaliseur::estNumerique('date_operation'));
    }
}
