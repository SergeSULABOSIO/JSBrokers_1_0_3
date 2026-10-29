<?php

namespace App\Tests\Ai;

use App\Ai\Traitement\PoulsDuWorker;
use App\EventSubscriber\BattementDuWorkerSubscriber;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

/**
 * LE WORKER DE KET (Serge, 2026-10-29). Il s'arrêtait au bout d'une heure (`--time-limit=3600`
 * du worker de `symfony serve`) et personne ne le relançait : les questions à Ket attendaient
 * sans réponse ni explication. Trois garanties :
 *   — UN SEUL worker à la fois : `app:ket:worker`, lancé chaque minute, prend un verrou de
 *     fichier sans attendre ; tenu par un autre, il sort aussitôt ;
 *   — le worker BAT, et sa règle (vivant ≤ 120 s) est la source unique de « Ket disponible » ;
 *   — absent, le chat le DIT et la console de supervision le SIGNALE.
 */
class WorkerDeKetTest extends KernelTestCase
{
    private string $fichier;

    protected function setUp(): void
    {
        // Sous var/ du projet, jamais dans le dossier temporaire du système (chantier 19 bis).
        $this->fichier = __DIR__ . '/../../var/worker/pouls-essai-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->fichier);
        parent::tearDown();
    }

    // ── La règle pure du battement ──────────────────────────────────────────────────────────
    public function testLaRegleDuBattement(): void
    {
        $maintenant = 1_800_000_000;
        self::assertSame(PoulsDuWorker::ABSENT, PoulsDuWorker::etat(null, $maintenant), 'jamais battu');
        self::assertSame(PoulsDuWorker::VIVANT, PoulsDuWorker::etat($maintenant, $maintenant));
        self::assertSame(PoulsDuWorker::VIVANT, PoulsDuWorker::etat($maintenant - 120, $maintenant), '120 s : encore vivant');
        self::assertSame(PoulsDuWorker::ABSENT, PoulsDuWorker::etat($maintenant - 121, $maintenant), 'au-delà de 2 minutes');
        self::assertSame(PoulsDuWorker::ABSENT, PoulsDuWorker::etat($maintenant + 3600, $maintenant), 'horloge déréglée');
        self::assertSame(120, PoulsDuWorker::SEUIL_SECONDES);
    }

    public function testKetEstIndisponibleQuandSonWorkerNeBatPlus(): void
    {
        $horloge = new MockClock('2026-10-29 14:00:00');
        $pouls = new PoulsDuWorker($this->fichier, $horloge, true);
        self::assertFalse($pouls->ketDisponible(), 'jamais battu : indisponible');
        self::assertSame(['asynchrone' => true, 'etat' => 'absent', 'depuis' => null], $pouls->constat());

        $pouls->battre();
        $horloge->modify('+90 seconds');
        self::assertTrue($pouls->ketDisponible());
        self::assertSame(['asynchrone' => true, 'etat' => 'vivant', 'depuis' => 90], $pouls->constat());

        $horloge->modify('+40 seconds');
        self::assertFalse($pouls->ketDisponible(), '130 s sans battement');
    }

    /** Ket qui répond PENDANT la requête n'a pas de worker : il est toujours disponible. */
    public function testSansFileKetEstToujoursDisponible(): void
    {
        $pouls = new PoulsDuWorker($this->fichier, new MockClock(), false);
        self::assertTrue($pouls->ketDisponible());
        self::assertFalse($pouls->constat()['asynchrone']);
    }

    // ── Le battement, écrit par le worker ───────────────────────────────────────────────────
    public function testSeulLeWorkerDeLaFileDeKetBatEtAuPlusToutesLes30Secondes(): void
    {
        $horloge = new MockClock('2026-10-29 14:00:00');
        $pouls = new PoulsDuWorker($this->fichier, $horloge, true);
        $abonne = new BattementDuWorkerSubscriber($pouls, $horloge);
        $worker = fn (string $file) => new Worker([$file => $this->createMock(ReceiverInterface::class)], $this->createMock(MessageBusInterface::class));

        $abonne->auDemarrage(new WorkerStartedEvent($worker('failed')));
        self::assertNull($pouls->dernierBattement(), 'un autre consommateur ne dit rien de Ket');

        $kel = $worker('async');
        $abonne->auDemarrage(new WorkerStartedEvent($kel));
        $premier = $pouls->dernierBattement();
        self::assertSame($horloge->now()->getTimestamp(), $premier);

        $horloge->modify('+10 seconds');
        $abonne->enMarche(new WorkerRunningEvent($kel, true));
        self::assertSame($premier, $pouls->dernierBattement(), 'pas d’écriture à chaque tour');

        $horloge->modify('+25 seconds');
        $abonne->enMarche(new WorkerRunningEvent($kel, true));
        self::assertSame($horloge->now()->getTimestamp(), $pouls->dernierBattement(), '35 s : il bat de nouveau');
    }

    // ── Un seul worker : le verrou ──────────────────────────────────────────────────────────
    public function testUnSecondWorkerSortAussitotQuandLeVerrouEstTenu(): void
    {
        self::bootKernel();
        $fichierDuVerrou = static::getContainer()->getParameter('kernel.project_dir') . '/var/worker/worker-test.lock';
        @mkdir(\dirname($fichierDuVerrou), 0775, true);
        $tenu = fopen($fichierDuVerrou, 'c');
        self::assertTrue(flock($tenu, LOCK_EX | LOCK_NB), 'préalable : le verrou est libre');

        try {
            $testeur = new CommandTester((new Application(self::$kernel))->find('app:ket:worker'));
            $debut = microtime(true);
            $code = $testeur->execute(['--time-limit' => '30'], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
            self::assertSame(0, $code);
            self::assertStringContainsString('Un worker de Ket tourne déjà', $testeur->getDisplay());
            self::assertStringNotContainsString('Consuming messages', $testeur->getDisplay(), 'aucun second worker');
            self::assertLessThan(5, microtime(true) - $debut, 'il sort aussitôt, sans attendre le verrou');
        } finally {
            flock($tenu, LOCK_UN);
            fclose($tenu);
        }
    }

    /** Verrou libre : il consomme la file de Ket, et le worker bat dès son démarrage. */
    public function testVerrouLibreLeWorkerDemarreEtBat(): void
    {
        self::bootKernel();
        $pouls = static::getContainer()->get(PoulsDuWorker::class);
        $avant = $pouls->dernierBattement();

        $testeur = new CommandTester((new Application(self::$kernel))->find('app:ket:worker'));
        $code = $testeur->execute(['--time-limit' => '1']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Consuming messages from transport "async"', $testeur->getDisplay());
        self::assertNotNull($pouls->dernierBattement());
        self::assertGreaterThanOrEqual($avant ?? 0, $pouls->dernierBattement());
    }

    // ── Le signal : le chat, la console ─────────────────────────────────────────────────────
    public function testLaConsoleSignaleUnWorkerAbsent(): void
    {
        self::bootKernel();
        $twig = static::getContainer()->get('twig');
        $rendu = static fn (array $worker): string => $twig->render('console/dashboard/_block_supervision.html.twig', [
            'defauts' => [],
            'totaux' => ['ouverts' => 0, 'occurrences24h' => 0, 'occurrences' => 0],
            'worker' => $worker,
        ]);

        $absent = $rendu(['asynchrone' => true, 'etat' => 'absent', 'depuis' => 600]);
        self::assertStringContainsString('Worker de Ket : ABSENT', $absent);
        self::assertStringContainsString('aucun signe depuis 10 min', $absent);
        self::assertStringContainsString('aucun signe de vie', $rendu(['asynchrone' => true, 'etat' => 'absent', 'depuis' => null]));
        self::assertStringContainsString('Worker de Ket : actif', $rendu(['asynchrone' => true, 'etat' => 'vivant', 'depuis' => 12]));
        self::assertStringContainsString('aucun worker requis', $rendu(['asynchrone' => false, 'etat' => 'absent', 'depuis' => null]));
    }

    /** Une seule source : le point d'état du chat et la console lisent PoulsDuWorker. */
    public function testLeChatEtLaConsoleLisentLaMemeSource(): void
    {
        $racine = __DIR__ . '/../..';
        self::assertStringContainsString("'ketDisponible'     => \$pouls->ketDisponible(),", (string) file_get_contents($racine . '/src/Controller/Admin/AssistantIaController.php'));
        self::assertStringContainsString("'worker'  => \$pouls->constat(),", (string) file_get_contents($racine . '/src/Controller/Console/DashboardController.php'));
        self::assertStringContainsString('verbeEtape(cleDAttente(this._ketDisponible))', (string) file_get_contents($racine . '/assets/controllers/assistant-chat_controller.js'));
    }
}
