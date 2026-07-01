<?php

namespace App\Tests\Console;

use App\Comptabilite\EcritureComptableService;
use App\Comptabilite\SuiviFiscalService;
use App\Entity\Charge;
use App\Entity\Depense;
use App\Entity\ReglementTaxe;
use App\Entity\TaxeVente;
use App\Entity\TokenPurchase;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Suivi fiscal de la TVA : montant dû (collectée − déductible), reversé et solde,
 * + intégration comptable du reversement (écriture D 443 / C trésorerie).
 */
class SuiviFiscalTest extends WebTestCase
{
    private const ADMIN = 'phpunit-fisc-admin@test.local';
    private const ANNEE = 2031;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        $admin = (new Utilisateur())->setEmail(self::ADMIN)->setNom('Fisc Admin')->setVerified(true);
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'Test1234!'));
        $em->persist($admin);

        // Taxe active 16 % → assiette de la TVA collectée.
        $em->persist((new TaxeVente())->setCode('FISCTVA')->setLibelle('TVA')->setAutoriteNom('DGI')
            ->setAutoriteAbreviation('DGI')->setTaux('16')->setActif(true));

        // Vente encaissée 1160 TTC → HT 1000, TVA collectée 160.
        $em->persist((new TokenPurchase())->setUtilisateur($admin)->setPack('professionnel')->setTokens(1000)
            ->setMontantUsd(1160.0)->setReference('TOK-FISC-1')->setStatus(TokenPurchase::STATUS_PAID)
            ->setProvider('simulated')->setCreatedAt(new \DateTimeImmutable(self::ANNEE . '-06-15 10:00:00')));

        // Dépense 116 TTC à 16 % → TVA déductible 16.
        $charge = (new Charge())->setCode('FISC-CHG')->setLibelle('Infra')->setCompteOhada('62')
            ->setDestination(Charge::DEST_COUT_DIRECT)->setComportement(Charge::COMPORTEMENT_VARIABLE)
            ->setPeriodicite(Charge::PERIODICITE_MENSUELLE)->setActif(true);
        $em->persist($charge);
        $em->persist((new Depense())->setCharge($charge)->setMontant('116.00')->setTauxTva('16')->setDevise('USD')
            ->setDateDepense(new \DateTimeImmutable(self::ANNEE . '-06-20'))->setStatut(Depense::STATUT_PAYEE)
            ->setMoyenPaiement(Depense::MOYEN_BANQUE)->setReference('DEP-FISC'));

        // Reversement partiel de 100 → solde dû attendu = (160 − 16) − 100 = 44.
        // Photos de TVA figées (comme à la saisie) : collectée 160, déductible 16.
        $em->persist((new ReglementTaxe())->setAutorite('DGI')->setAnnee(self::ANNEE)->setMois(6)
            ->setMontant('100.00')->setMoyenPaiement(ReglementTaxe::MOYEN_BANQUE)
            ->setTvaCollectee('160.00')->setTvaDeductible('16.00')
            ->setDatePaiement(new \DateTimeImmutable(self::ANNEE . '-07-05')));

        $em->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('DELETE FROM reglement_taxe WHERE annee = :a', ['a' => self::ANNEE]);
        $conn->executeStatement("DELETE FROM token_purchase WHERE reference = 'TOK-FISC-1'");
        $conn->executeStatement("DELETE FROM depense WHERE reference = 'DEP-FISC'");
        $conn->executeStatement("DELETE FROM charge WHERE code = 'FISC-CHG'");
        $conn->executeStatement("DELETE FROM taxe_vente WHERE code = 'FISCTVA'");
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::ADMIN]);
    }

    public function testSuiviComputesDueReversedAndBalance(): void
    {
        $suivi = static::getContainer()->get(SuiviFiscalService::class)->suivi(self::ANNEE);
        $t = $suivi['totaux'];

        $this->assertEqualsWithDelta(160.0, $t['collectee'], 0.01);
        $this->assertEqualsWithDelta(16.0, $t['deductible'], 0.01);
        $this->assertEqualsWithDelta(144.0, $t['netDu'], 0.01, 'Net dû = collectée − déductible.');
        $this->assertEqualsWithDelta(100.0, $t['reverse'], 0.01);
        $this->assertEqualsWithDelta(44.0, $t['solde'], 0.01, 'Solde dû = net dû − reversé.');

        // Le mois de juin porte les montants ; les autres mois sont à zéro.
        $juin = $suivi['lignes'][5];
        $this->assertSame(6, $juin['mois']);
        $this->assertEqualsWithDelta(44.0, $juin['solde'], 0.01);
    }

    public function testReversementGeneratesDetailedAccountingEntry(): void
    {
        $ecritures = static::getContainer()->get(EcritureComptableService::class)->ecritures();
        $reglements = array_values(array_filter($ecritures, static fn (array $e) => $e['type'] === 'reglement_taxe'));

        $this->assertCount(1, $reglements);
        $lignes = $reglements[0]['lignes'];
        $par = static fn (string $compte) => array_values(array_filter($lignes, static fn ($l) => $l['compte'] === $compte))[0] ?? null;

        // Écriture détaillée : D 443 collectée 160 / C 445 déductible 16 / C 521 payé 100 / C 4441 solde 44.
        $this->assertEqualsWithDelta(160.0, $par('443')['debit'], 0.01);
        $this->assertEqualsWithDelta(16.0, $par('445')['credit'], 0.01);
        $this->assertEqualsWithDelta(100.0, $par('521')['credit'], 0.01);
        $this->assertEqualsWithDelta(44.0, $par('4441')['credit'], 0.01, 'Solde déclaré non payé → dette TVA (4441).');

        // L'écriture est équilibrée (Σ débit = Σ crédit).
        $debit = array_sum(array_map(static fn ($l) => $l['debit'], $lignes));
        $credit = array_sum(array_map(static fn ($l) => $l['credit'], $lignes));
        $this->assertEqualsWithDelta($debit, $credit, 0.01);
    }
}
