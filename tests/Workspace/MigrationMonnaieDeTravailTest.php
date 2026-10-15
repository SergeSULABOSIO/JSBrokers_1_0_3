<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261015090000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once dirname(__DIR__, 2) . '/migrations/Version20261015090000.php';

/**
 * LA MIGRATION DU 2026-10-15 GARDE L'ÉTIQUETTE QUE LES MONTANTS PORTAIENT À LEUR SAISIE.
 *
 * Elle fait de la monnaie d'affichage la monnaie de travail du cabinet, sans convertir un
 * seul montant. Elle doit donc retenir EXACTEMENT celle que l'écran affichait : la première
 * (par id) de fonction « saisie et affichage » OU « affichage uniquement », sans préférence
 * — sinon des montants saisis sous « EUR » se liraient en « USD ».
 */
class MigrationMonnaieDeTravailTest extends KernelTestCase
{
    private const EMAIL = 'phpunit-migration-monnaie@test.local';
    private const PREFIXE = 'PHPUnit Migration Monnaie ';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->cleanUp();
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
        $conn->executeStatement('DELETE m FROM monnaie m JOIN entreprise e ON e.id = m.entreprise_id WHERE e.nom LIKE ?', [self::PREFIXE . '%']);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom LIKE ?', [self::PREFIXE . '%']);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
        $this->em()->clear();
    }

    /**
     * Un cabinet et ses monnaies, dans l'ordre des id.
     *
     * @param list<array{0: string, 1: int}> $monnaies code, fonction
     */
    private function cabinet(string $nom, Utilisateur $owner, array $monnaies): int
    {
        $em = $this->em();
        $cabinet = (new Entreprise())->setNom(self::PREFIXE . $nom)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000064')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $em->flush();
        foreach ($monnaies as [$code, $fonction]) {
            $em->getConnection()->executeStatement(
                'INSERT INTO monnaie (entreprise_id, nom, code, tauxusd, fonction, locale, created_at) VALUES (?, ?, ?, 1, ?, 0, NOW())',
                [$cabinet->getId(), $code, $code, $fonction],
            );
        }

        return (int) $cabinet->getId();
    }

    /** @return array<string, int> code => fonction après migration */
    private function fonctions(int $cabinet): array
    {
        return array_map('intval', $this->em()->getConnection()->fetchAllKeyValue(
            'SELECT code, fonction FROM monnaie WHERE entreprise_id = ? ORDER BY id',
            [$cabinet],
        ));
    }

    private function migrer(): void
    {
        $connexion = $this->em()->getConnection();
        $migration = new Version20261015090000($connexion, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $requete) {
            $connexion->executeStatement($requete->getStatement(), $requete->getParameters(), $requete->getTypes());
        }
    }

    public function testLaMonnaieRetenueEstCelleQueLEcranAffichait(): void
    {
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Migration')->setVerified(true)->setPassword('x');
        $this->em()->persist($owner);
        $this->em()->flush();

        // « Affichage uniquement » AVANT « saisie et affichage » : l'écran affichait EUR.
        $affichageDAbord = $this->cabinet('EUR affiche', $owner, [['EUR', 2], ['USD', 0]]);
        // Le cas courant : USD de travail, monnaie du pays en saisie seule.
        $courant = $this->cabinet('Courant', $owner, [['USD', 0], ['CDF', 1]]);
        // Deux monnaies de travail (une course) : la première reste, l'autre passe en référence.
        $doublon = $this->cabinet('Doublon', $owner, [['USD', 0], ['EUR', 0]]);
        // Aucune monnaie affichée, mais un USD : il devient la monnaie de travail.
        $sansAffichage = $this->cabinet('Sans affichage', $owner, [['CDF', 1], ['USD', 1]]);
        // Rien d'affiché, pas d'USD : la première monnaie de saisie devient celle de travail.
        $saisieSeule = $this->cabinet('Saisie seule', $owner, [['CDF', 1]]);

        $this->migrer();

        self::assertSame(['EUR' => 0, 'USD' => -1], $this->fonctions($affichageDAbord), 'Ce que l’écran affichait — EUR — reste l’étiquette.');
        self::assertSame(['USD' => 0, 'CDF' => -1], $this->fonctions($courant));
        self::assertSame(['USD' => 0, 'EUR' => -1], $this->fonctions($doublon));
        self::assertSame(['CDF' => -1, 'USD' => 0], $this->fonctions($sansAffichage));
        self::assertSame(['CDF' => 0], $this->fonctions($saisieSeule));

        // Rejouée, elle ne change plus rien.
        $avant = [$this->fonctions($affichageDAbord), $this->fonctions($courant), $this->fonctions($doublon)];
        $this->migrer();
        self::assertSame($avant, [$this->fonctions($affichageDAbord), $this->fonctions($courant), $this->fonctions($doublon)]);
    }
}
