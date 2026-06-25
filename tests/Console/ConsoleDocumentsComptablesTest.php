<?php

namespace App\Tests\Console;

use App\Comptabilite\EcritureComptableService;
use App\Entity\Charge;
use App\Entity\Depense;
use App\Entity\TokenPurchase;
use App\Entity\Utilisateur;
use App\Services\ServiceTaxesVente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Console JS Brokers : rubrique « Documents comptables » (journal, grand livre,
 * balance, compte de résultat, TFR, bilan, TFT) générée à la volée depuis les
 * ventes et dépenses. Vérifie l'accès, l'affichage des onglets, la TVA déductible,
 * le capital social, l'export Excel et les invariants comptables (partie double).
 *
 * Les fixtures sont datées sur un exercice isolé (EXERCICE) pour des montants
 * déterministes, et préfixées PHPUNIT- pour un nettoyage sûr.
 */
class ConsoleDocumentsComptablesTest extends WebTestCase
{
    private const ADMIN = 'phpunit-doc-admin@test.local';
    private const SUPER = 'phpunit-doc-super@test.local';
    private const USER  = 'phpunit-doc-user@test.local';
    private const PASSWORD = 'Test1234!';
    private const EXERCICE = 2031; // exercice de test isolé (aucune autre donnée attendue)

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        foreach ([self::ADMIN => ['ROLE_ADMIN'], self::SUPER => ['ROLE_SUPER_ADMIN'], self::USER => []] as $email => $roles) {
            $u = new Utilisateur();
            $u->setEmail($email);
            $u->setNom('PHPUnit ' . $email);
            $u->setVerified(true);
            $u->setLocale('fr');
            $u->setRoles($roles);
            $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
            $em->persist($u);
        }
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
        $conn->executeStatement('DELETE d FROM depense d JOIN charge c ON d.charge_id = c.id WHERE c.code LIKE :c', ['c' => 'PHPUNIT-%']);
        $conn->executeStatement('DELETE FROM charge WHERE code LIKE :c', ['c' => 'PHPUNIT-%']);
        $conn->executeStatement('DELETE FROM token_purchase WHERE reference LIKE :r', ['r' => 'PHPUNIT-%']);
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => [self::ADMIN, self::SUPER, self::USER]],
            ['e' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        // Réinitialise le capital social (singleton partagé) pour ne pas polluer les autres tests.
        $conn->executeStatement('UPDATE plateforme_parametres SET capital_social = NULL, date_constitution = NULL');
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    /** Dépense de test : 116 TTC, TVA déductible 16 % → 100 HT + 16 de TVA récupérable. */
    private function createFixtures(): void
    {
        $em = $this->em();

        $charge = new Charge();
        $charge->setCode('PHPUNIT-DOC-CH');
        $charge->setLibelle('Hébergement infrastructure');
        $charge->setCompteOhada('62');
        $charge->setDestination(Charge::DEST_COUT_DIRECT);
        $charge->setComportement(Charge::COMPORTEMENT_VARIABLE);
        $charge->setPeriodicite(Charge::PERIODICITE_MENSUELLE);
        $charge->setActif(true);
        $em->persist($charge);

        $depense = new Depense();
        $depense->setCharge($charge);
        $depense->setDateDepense(new \DateTimeImmutable(self::EXERCICE . '-03-15'));
        $depense->setMontant('116.00');
        $depense->setTauxTva('16.00');
        $depense->setDevise('USD');
        $depense->setBeneficiaire('Hébergeur Cloud');
        $depense->setReference('PHPUNIT-DOC-DEP');
        $depense->setMoyenPaiement(Depense::MOYEN_BANQUE);
        $depense->setStatut(Depense::STATUT_PAYEE);
        $em->persist($depense);

        $vente = new TokenPurchase();
        $vente->setUtilisateur($this->user(self::ADMIN));
        $vente->setPack('professionnel');
        $vente->setTokens(50000);
        $vente->setMontantUsd(1000.0);
        $vente->setReference('PHPUNIT-DOC-V');
        $vente->setStatus(TokenPurchase::STATUS_PAID_SIMULATED);
        $vente->setCreatedAt(new \DateTimeImmutable(self::EXERCICE . '-02-10 10:00:00'));
        $em->persist($vente);

        $em->flush();
    }

    private function setCapitalSocial(string $montant, string $date): void
    {
        $params = static::getContainer()->get('App\Repository\PlateformeParametresRepository')->getSingleton();
        $params->setCapitalSocial($montant);
        $params->setDateConstitution(new \DateTimeImmutable($date));
        $this->em()->flush();
    }

    // ===================== Accès =====================

    public function testRegularUserIsForbidden(): void
    {
        $this->client->loginUser($this->user(self::USER));
        $this->client->request('GET', '/console/documents');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testParametresForbiddenForSimpleAdmin(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));
        $this->client->request('GET', '/console/documents/parametres');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testParametresAccessibleForSuperAdmin(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->client->request('GET', '/console/documents/parametres');
        $this->assertResponseIsSuccessful();
    }

    // ===================== Affichage =====================

    public function testIndexShowsAllSevenTabs(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));
        $this->client->request('GET', '/console/documents');
        $this->assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        foreach (['Journal', 'Grand livre', 'Balance générale', 'Compte de résultat', 'Formation du résultat', 'Bilan comparatif', 'Flux de trésorerie'] as $onglet) {
            $this->assertStringContainsString($onglet, $html, sprintf('L\'onglet « %s » doit être présent.', $onglet));
        }
        $this->assertStringContainsString('Exercice', $html, 'Le sélecteur d\'exercice doit être présent.');
    }

    public function testJournalReflectsDeductibleVat(): void
    {
        $this->createFixtures();
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/documents?doc=journal&exercice=' . self::EXERCICE);
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        // Dépense 116 TTC à 16 % : 100 HT en charge (62), 16 de TVA récupérable (445), 116 au crédit (521).
        $this->assertStringContainsString('445', $html, 'Le compte de TVA récupérable doit apparaître.');
        $this->assertStringContainsString('100.00', $html, 'La charge HT (100) doit apparaître.');
        $this->assertStringContainsString('16.00', $html, 'La TVA déductible (16) doit apparaître.');
        $this->assertStringContainsString('116.00', $html, 'Le décaissement TTC (116) doit apparaître.');
    }

    public function testBilanShowsCapitalSocial(): void
    {
        $this->createFixtures();
        $this->setCapitalSocial('5000.00', self::EXERCICE . '-01-05');
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/documents?doc=bilan&exercice=' . self::EXERCICE);
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('Capital social', $html);
        $this->assertStringContainsString('5 000.00', $html, 'Le capital social (5000) doit figurer au bilan.');
    }

    // ===================== Export =====================

    public function testExportSingleDocumentIsXlsx(): void
    {
        $this->createFixtures();
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/documents/export?doc=journal&exercice=' . self::EXERCICE);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
            'L\'export doit renvoyer un fichier XLSX.',
        );
    }

    public function testExportFullWorkbookIsXlsx(): void
    {
        $this->createFixtures();
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/documents/export?doc=all&exercice=' . self::EXERCICE);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
    }

    // ===================== Invariants comptables (partie double) =====================

    public function testDoubleEntryInvariants(): void
    {
        $this->createFixtures();
        $this->setCapitalSocial('5000.00', self::EXERCICE . '-01-05');

        /** @var EcritureComptableService $service */
        $service = static::getContainer()->get(EcritureComptableService::class);
        $documents = $service->documents(self::EXERCICE);

        // 1) Balance équilibrée : Σ mouvements débit = Σ mouvements crédit ; Σ soldes débit = Σ soldes crédit.
        $t = $documents['balance']['totaux'];
        $this->assertEqualsWithDelta($t['mvtD'], $t['mvtC'], 0.01, 'Les mouvements débit/crédit doivent s\'équilibrer.');
        $this->assertEqualsWithDelta($t['cloD'], $t['cloC'], 0.01, 'Les soldes débiteurs/créditeurs doivent s\'équilibrer.');

        // 2) Bilan équilibré (ouverture et clôture) : total actif = total passif.
        $actif = $documents['bilan']['actif'];
        $passif = $documents['bilan']['passif'];
        $totalActif = end($actif);
        $totalPassif = end($passif);
        $this->assertEqualsWithDelta($totalActif['ouverture'], $totalPassif['ouverture'], 0.01, 'Bilan d\'ouverture déséquilibré.');
        $this->assertEqualsWithDelta($totalActif['cloture'], $totalPassif['cloture'], 0.01, 'Bilan de clôture déséquilibré.');

        // 3) Résultat net cohérent : compte de résultat == dernière ligne du TFR == résultat du bilan.
        $resultatCompte = $documents['resultat']['resultat'];
        $tfr = $documents['tfr'];
        $resultatTfr = end($tfr)['montant'];
        $resultatBilan = null;
        foreach ($passif as $p) {
            if ($p['libelle'] === 'Résultat de l\'exercice') {
                $resultatBilan = $p['cloture'];
            }
        }
        $this->assertEqualsWithDelta($resultatCompte, $resultatTfr, 0.01, 'Le résultat net du TFR doit égaler celui du compte de résultat.');
        $this->assertEqualsWithDelta($resultatCompte, $resultatBilan, 0.01, 'Le résultat du bilan doit égaler celui du compte de résultat.');

        // 4) Produit = revenu HT des ventes (cohérence avec ServiceTaxesVente).
        /** @var ServiceTaxesVente $taxes */
        $taxes = static::getContainer()->get(ServiceTaxesVente::class);
        $this->assertEqualsWithDelta(
            $taxes->revenuHorsTaxe(1000.0),
            $documents['resultat']['totalProduits'],
            0.01,
            'Le produit doit correspondre au revenu HT calculé par ServiceTaxesVente.',
        );

        // 5) Charge = HT de la dépense (100), TVA récupérable à l'actif (16), capital au passif (5000).
        $this->assertEqualsWithDelta(100.0, $documents['resultat']['totalCharges'], 0.01, 'La charge doit être en HT (100).');

        $tvaRecup = null;
        foreach ($actif as $a) {
            if ($a['libelle'] === 'État, TVA récupérable') {
                $tvaRecup = $a['cloture'];
            }
        }
        $this->assertEqualsWithDelta(16.0, $tvaRecup, 0.01, 'La TVA récupérable (16) doit figurer à l\'actif.');

        $capital = null;
        foreach ($passif as $p) {
            if ($p['libelle'] === 'Capital social') {
                $capital = $p['cloture'];
            }
        }
        $this->assertEqualsWithDelta(5000.0, $capital, 0.01, 'Le capital social (5000) doit figurer au passif.');
    }
}
