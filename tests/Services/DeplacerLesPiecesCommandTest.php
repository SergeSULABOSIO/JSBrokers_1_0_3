<?php

namespace App\Tests\Services;

use App\Command\DeplacerLesPiecesCommand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * LE DÉPLACEMENT DES FICHIERS DÉPOSÉS (chantier 18) : copie vérifiée par SHA-256, rapport,
 * et un seul échec = AUCUN original supprimé. Idempotent.
 */
final class DeplacerLesPiecesCommandTest extends KernelTestCase
{
    private const ENT = 'PHPUNIT Déplacement des pièces';
    private const EMAIL = 'phpunit-deplacement@test.local';

    private string $racine;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->racine = sys_get_temp_dir() . '/deplacer-' . bin2hex(random_bytes(4));
        foreach (['anciennes', 'pieces', 'anciensLogos', 'logos'] as $d) {
            mkdir($this->racine . '/' . $d, 0777, true);
        }
        $this->nettoyerLaBase();
    }

    protected function tearDown(): void
    {
        $this->nettoyerLaBase();
        foreach (glob($this->racine . '/*/*') ?: [] as $f) {
            unlink($f);
        }
        foreach (glob($this->racine . '/*') ?: [] as $d) {
            rmdir($d);
        }
        rmdir($this->racine);
        parent::tearDown();
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function nettoyerLaBase(): void
    {
        $c = $this->conn();
        $c->executeStatement('DELETE d FROM document d JOIN entreprise e ON d.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $c->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $c->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::EMAIL]);
    }

    /** Une entreprise (logo `$logo`) et une pièce par nom donné, référencés en base. */
    private function referencer(array $pieces, ?string $logo): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new \App\Entity\Utilisateur())->setEmail(self::EMAIL)->setNom('PHPUnit')->setVerified(true)->setPassword('x');
        $em->persist($u);
        $e = (new \App\Entity\Entreprise())->setNom(self::ENT)->setLicence('L')->setAdresse('A')->setTelephone('+243')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($u);
        $e->setThumbnail($logo);
        $em->persist($e);
        foreach ($pieces as $nom) {
            $d = (new \App\Entity\Document())->setNom('PHPUnit');
            $d->setNomFichierStocke($nom);
            $d->setEntreprise($e);
            $em->persist($d);
        }
        $em->flush();
        $em->clear();
    }

    private function deposer(string $dossier, string $nom, string $contenu): string
    {
        file_put_contents($chemin = $this->racine . '/' . $dossier . '/' . $nom, $contenu);

        return $chemin;
    }

    private function lancer(bool $executer): CommandTester
    {
        $commande = new DeplacerLesPiecesCommand(
            static::getContainer()->get(EntityManagerInterface::class),
            $this->racine . '/anciennes',
            $this->racine . '/pieces',
            $this->racine . '/anciensLogos',
            $this->racine . '/logos',
        );
        $application = new Application(self::$kernel);
        $application->add($commande);
        $testeur = new CommandTester($application->find('app:documents:deplacer'));
        $testeur->execute($executer ? ['--executer' => true] : []);

        return $testeur;
    }

    public function testLEssaiABlancNEcritRien(): void
    {
        $nom = 'phpunit-a-' . bin2hex(random_bytes(4)) . '.pdf';
        $this->referencer([$nom], null);
        $source = $this->deposer('anciennes', $nom, 'contenu A');

        $testeur = $this->lancer(false);

        self::assertSame(Command::SUCCESS, $testeur->getStatusCode());
        self::assertFileExists($source);
        self::assertFileDoesNotExist($this->racine . '/pieces/' . $nom);
        self::assertStringContainsString('Essai à blanc', $testeur->getDisplay());
    }

    public function testLesFichiersSontCopiesVerifiesPuisLesOriginauxSupprimes(): void
    {
        $piece = 'phpunit-b-' . bin2hex(random_bytes(4)) . '.xlsx';
        $logo = 'phpunit-logo-' . bin2hex(random_bytes(4)) . '.png';
        $this->referencer([$piece], $logo);
        $this->deposer('anciennes', $piece, 'contenu B');
        $this->deposer('anciensLogos', $logo, 'image');

        $testeur = $this->lancer(true);

        self::assertSame(Command::SUCCESS, $testeur->getStatusCode(), $testeur->getDisplay());
        self::assertSame('contenu B', file_get_contents($this->racine . '/pieces/' . $piece));
        self::assertSame('image', file_get_contents($this->racine . '/logos/' . $logo));
        self::assertFileDoesNotExist($this->racine . '/anciennes/' . $piece);
        self::assertFileDoesNotExist($this->racine . '/anciensLogos/' . $logo);
        self::assertStringContainsString('déplacés : 2', $testeur->getDisplay());

        // Relancée : rien à refaire, rien en échec.
        $relance = $this->lancer(true);
        self::assertSame(Command::SUCCESS, $relance->getStatusCode());
        self::assertStringContainsString('déplacés : 0', $relance->getDisplay());
    }

    public function testUnFichierDuDepotEstCopieJamaisSupprime(): void
    {
        $this->referencer([], 'logofav.png');
        $source = $this->deposer('anciensLogos', 'logofav.png', 'marque');

        self::assertSame(Command::SUCCESS, $this->lancer(true)->getStatusCode());
        self::assertFileExists($source, 'le prochain déploiement le recréerait : on n’y touche pas');
        self::assertSame('marque', file_get_contents($this->racine . '/logos/logofav.png'));
    }

    /** Un fichier que la base ne désigne pas reste en place, mais le rapport le compte et le nomme. */
    public function testLesFichiersNonReferencesSontComptesEtLaissesEnPlace(): void
    {
        $this->referencer([], null);
        $orphelin = $this->deposer('anciennes', 'phpunit-orphelin.txt', 'rien ne me désigne');

        $testeur = $this->lancer(true);

        self::assertSame(Command::SUCCESS, $testeur->getStatusCode());
        self::assertFileExists($orphelin, 'laissé en place : son sort est une décision');
        self::assertStringContainsString('non référencés par la base, laissés en place : 1', $testeur->getDisplay());
        self::assertStringContainsString('phpunit-orphelin.txt', $testeur->getDisplay());
    }

    public function testUnSeulEchecEtAucunOriginalNEstSupprime(): void
    {
        $bon = 'phpunit-c-' . bin2hex(random_bytes(4)) . '.pdf';
        $conflit = 'phpunit-d-' . bin2hex(random_bytes(4)) . '.pdf';
        $this->referencer([$bon, $conflit], null);
        $sourceBonne = $this->deposer('anciennes', $bon, 'bon');
        $sourceConflit = $this->deposer('anciennes', $conflit, 'original');
        $this->deposer('pieces', $conflit, 'AUTRE contenu déjà à destination');

        $testeur = $this->lancer(true);

        self::assertSame(Command::FAILURE, $testeur->getStatusCode());
        self::assertFileExists($sourceBonne, 'même la pièce bien copiée garde son original');
        self::assertFileExists($sourceConflit);
        self::assertSame('AUTRE contenu déjà à destination', file_get_contents($this->racine . '/pieces/' . $conflit), 'rien n’est écrasé');
        self::assertStringContainsString('AUCUN original', $testeur->getDisplay());
    }
}
