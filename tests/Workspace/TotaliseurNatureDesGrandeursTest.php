<?php

namespace App\Tests\Workspace;

use App\Entity\Assureur;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\Monnaie;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\TypeRevenu;
use App\Services\Canvas\NumericCanvasProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CHAQUE GRANDEUR DU TOTALISEUR DIT CE QU'ELLE EST (chantier 4, commit 1 — décisions de Serge
 * du 2026-10-25).
 *
 * Le navigateur traitait toute valeur comme un MONTANT en centimes : il additionnait les taux
 * (deux taux de 15 % et 10 % devenaient « 0,25 $ »), affichait cinq transactions « 5,00 $ »,
 * et un montant fixe non converti en centimes sortait cent fois trop petit.
 *
 * Désormais chaque grandeur déclare sa NATURE :
 *   - `montant` : en centimes, entier ;
 *   - `nombre`  : un compte, tel quel, entier.
 * Une grandeur NON ADDITIVE (taux, indice, cours, moyenne) ne se déclare pas : une somme de
 * taux n'est pas un taux — elle sort du totaliseur (décision 1 de Serge).
 */
class TotaliseurNatureDesGrandeursTest extends KernelTestCase
{
    private const NATURES = ['montant', 'nombre'];

    protected function setUp(): void
    {
        static::bootKernel();
    }

    private function canevas(object $entite): array
    {
        return static::getContainer()->get(NumericCanvasProvider::class)->getAttributesAndValues($entite);
    }

    /** Toutes les entités qui ont une barre des totaux, construites vides. */
    public function testToutesLesGrandeursDeToutesLesRubriquesOntUneNature(): void
    {
        $vues = 0;
        $fautes = [];
        foreach (glob(\dirname(__DIR__, 2) . '/src/Entity/*.php') as $fichier) {
            $classe = 'App\\Entity\\' . basename($fichier, '.php');
            if (!class_exists($classe) || (new \ReflectionClass($classe))->isAbstract()) {
                continue;
            }
            try {
                $entite = new $classe();
            } catch (\Throwable) {
                continue;
            }
            $canevas = $this->canevas($entite);
            if ($canevas === []) {
                continue;
            }
            ++$vues;
            foreach ($canevas as $code => $grandeur) {
                $nature = $grandeur['nature'] ?? null;
                if (!\in_array($nature, self::NATURES, true)) {
                    $fautes[] = "$classe::$code — nature « " . var_export($nature, true) . ' »';
                } elseif (!\is_int($grandeur['value'])) {
                    $fautes[] = "$classe::$code — valeur non entière (" . get_debug_type($grandeur['value']) . ')';
                }
                if (isset($grandeur['unit']) || isset($grandeur['is_percentage'])) {
                    $fautes[] = "$classe::$code — reste de l'ancien format (unit / is_percentage)";
                }
            }
        }

        self::assertGreaterThanOrEqual(25, $vues, 'Les rubriques à barre des totaux sont bien parcourues.');
        self::assertSame([], $fautes);
    }

    public function testLesTauxSortentDuTotaliseur(): void
    {
        $risque = new Risque();
        $taxe = new Taxe();
        $type = new TypeRevenu();
        foreach ([$risque, $taxe, $type] as $entite) {
            $codes = array_keys($this->canevas($entite));
            foreach (['tauxCommission', 'tauxSP', 'indiceSolvabilite', 'pourcentageCommissionSpecifiqueHT', 'tauxIARD', 'tauxVIE', 'pourcentage'] as $taux) {
                self::assertNotContains($taux, $codes, $entite::class . " : « $taux » n'est pas additif.");
            }
        }
        self::assertSame([], $this->canevas(new Monnaie()), 'Un taux de change ne se totalise pas.');
    }

    public function testUnCompteEstUnNombreEtUneMoyenneNeSeTotalisePas(): void
    {
        $assureur = new Assureur();
        $assureur->nombrePolicesSouscrites = 5;
        $canevas = $this->canevas($assureur);
        self::assertSame(['description' => 'Nb. Polices', 'value' => 5, 'nature' => 'nombre'], $canevas['nombrePolicesSouscrites']);

        $client = new Client();
        $client->nombrePistes = 11;
        self::assertSame(11, $this->canevas($client)['nombrePistes']['value']);

        $compte = new CompteBancaire();
        $compte->nombreTransactions = 5;
        $compte->moyenneTransaction = 120.0;
        $compte->soldeActuel = 1234.56;
        $canevas = $this->canevas($compte);
        self::assertSame(['description' => 'Nb. Transactions', 'value' => 5, 'nature' => 'nombre'], $canevas['nombreTransactions']);
        self::assertArrayNotHasKey('moyenneTransaction', $canevas, 'Une somme de moyennes ne veut rien dire.');
        self::assertSame(['description' => 'Solde Actuel', 'value' => 123456, 'nature' => 'montant'], $canevas['soldeActuel']);
    }

    /** Le montant fixe d'un type de revenu n'était pas converti en centimes : cent fois trop petit. */
    public function testLeMontantFixeDUnTypeDeRevenuEstEnCentimes(): void
    {
        $type = (new TypeRevenu())->setMontantflat(12.5);
        self::assertSame(['description' => 'Montant Fixe', 'value' => 1250, 'nature' => 'montant'], $this->canevas($type)['montantflat']);
    }
}
