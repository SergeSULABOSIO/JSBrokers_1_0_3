<?php

namespace App\Tests\Echange;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * LA PURGE QUOTIDIENNE EFFACE AUSSI LES FICHIERS DE PASSAGE OUBLIÉS (chantier 19 bis, 2026-10-23).
 *
 * Un export préparé jamais téléchargé, une copie que Vich n'a pas emportée parce que PHP a été
 * tué net (ni destructeur ni remise à zéro ne passent alors) : ces fichiers portent des données
 * du cabinet et ne dormaient nulle part ailleurs qu'ici, sans limite de temps. Après une heure
 * de grâce, `app:echange:purger` les efface ; avant, il n'y touche pas (un téléchargement ou un
 * enregistrement peut être en cours).
 */
class PurgeDesFichiersDePassageTest extends KernelTestCase
{
    private const CABINET_FICTIF = 990000123;

    /** @var list<string> */
    private array $dossiers = [];

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dossiers);
        parent::tearDown();
    }

    public function testLesFichiersDePassageVieuxDUneHeureSontEffacesLesRecentsGardes(): void
    {
        self::bootKernel();
        $conteneur = static::getContainer();
        $exports = $conteneur->getParameter('app.dossier_de_l_echange') . '/exports/' . self::CABINET_FICTIF;
        $passage = $conteneur->getParameter('app.dossier_des_temporaires') . '/' . self::CABINET_FICTIF;
        $this->dossiers = [$exports, $passage];

        $vieilExport = $this->fichier($exports . '/' . str_repeat('a', 32) . '.xlsx', 2);
        $exportRecent = $this->fichier($exports . '/' . str_repeat('b', 32) . '.xlsx', 0);
        $vieilleCopie = $this->fichier($passage . '/' . str_repeat('c', 32) . '.pdf', 2);
        $copieRecente = $this->fichier($passage . '/' . str_repeat('d', 32) . '.docx', 0);

        // En simulation, rien ne bouge.
        $this->purger(['--simuler' => true]);
        self::assertFileExists($vieilExport);
        self::assertFileExists($vieilleCopie);

        $this->purger([]);

        self::assertFileDoesNotExist($vieilExport, 'Un export préparé et jamais téléchargé ne dort pas indéfiniment.');
        self::assertFileDoesNotExist($vieilleCopie, 'Un fichier de passage oublié ne dort pas indéfiniment.');
        self::assertFileExists($exportRecent, 'Une heure de grâce : le téléchargement peut être en cours.');
        self::assertFileExists($copieRecente, 'Une heure de grâce : l\'enregistrement peut être en cours.');
    }

    private function fichier(string $chemin, int $heuresDAge): string
    {
        (new Filesystem())->dumpFile($chemin, 'données du cabinet');
        $quand = time() - $heuresDAge * 3600;
        touch($chemin, $quand, $quand);

        return $chemin;
    }

    private function purger(array $options): void
    {
        $application = new Application(self::$kernel);
        $testeur = new CommandTester($application->find('app:echange:purger'));
        $testeur->execute($options);
        self::assertSame(0, $testeur->getStatusCode(), $testeur->getDisplay());
    }
}
