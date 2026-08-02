<?php

namespace App\Tests\Services;

use App\Services\VersionService;
use PHPUnit\Framework\TestCase;

/**
 * Règle de numérotation de la version applicative : la base (3881 commits) vaut
 * 1.0, chaque commit ajoute +1, et 1000 commits font passer de 1.x à 2.x.
 * Vérifie aussi la lecture du fichier VERSION et les replis.
 */
class VersionServiceTest extends TestCase
{
    public function testFormatBornesNumerotation(): void
    {
        $base = 3881;

        $this->assertSame('1.0', VersionService::format($base), 'La base = 1.0');
        $this->assertSame('1.1', VersionService::format($base + 1), 'Premier commit = 1.1');
        $this->assertSame('1.42', VersionService::format($base + 42));
        $this->assertSame('1.999', VersionService::format($base + 999), 'Dernier mineur avant bascule');
        $this->assertSame('2.0', VersionService::format($base + 1000), '1000 commits → 2.0');
        $this->assertSame('2.1', VersionService::format($base + 1001));
        $this->assertSame('3.0', VersionService::format($base + 2000));
    }

    public function testFormatClampeSousLaBase(): void
    {
        // Un décompte inférieur à la base (dépôt tronqué) ne descend jamais sous 1.0.
        $this->assertSame('1.0', VersionService::format(0));
        $this->assertSame('1.0', VersionService::format(100));
    }

    public function testLitLeFichierVersion(): void
    {
        $dir = $this->projetTemporaire("3882\n2026-07-25\n");

        $service = new VersionService($dir);

        $this->assertSame('1.1', $service->getVersion());
        $this->assertSame('2026-07-25', $service->getDate()->format('Y-m-d'));
    }

    public function testFichierAbsentRetombeSurLaBase(): void
    {
        // Dossier sans fichier VERSION ni dépôt git : repli déterministe sur 1.0.
        $dir = sys_get_temp_dir() . '/jsb_version_' . uniqid('', true);
        mkdir($dir);

        $service = new VersionService($dir);

        $this->assertSame('1.0', $service->getVersion());

        rmdir($dir);
    }

    public function testParseCommitDecoupeLesTroisChamps(): void
    {
        $sep = "\x1f";
        $raw = "b8d40a8a{$sep}2026-07-25T15:18:40+01:00{$sep}Version applicative dynamique";

        $commit = VersionService::parseCommit($raw);

        $this->assertNotNull($commit);
        $this->assertSame('b8d40a8a', $commit['ref']);
        $this->assertSame('2026-07-25', $commit['date']->format('Y-m-d'));
        $this->assertSame('Version applicative dynamique', $commit['subject']);
    }

    public function testParseCommitSujetAvecSeparateurDeChampNaturel(): void
    {
        // Le sujet peut contenir un tiret « — » : seul le séparateur 0x1F découpe.
        $sep = "\x1f";
        $raw = "abc1234{$sep}2026-01-02T08:00:00+00:00{$sep}Ket : garde-fou — robustesse";

        $commit = VersionService::parseCommit($raw);

        $this->assertSame('Ket : garde-fou — robustesse', $commit['subject']);
    }

    public function testParseCommitCaptureLesParagraphesDuCorps(): void
    {
        $sep = "\x1f";
        $corps = "Première explication de ce qui a été fait.\n\nDeuxième paragraphe sur le problème résolu.\n\nCo-Authored-By: Claude <x@y.z>";
        $raw = "abc1234{$sep}2026-01-02T08:00:00+00:00{$sep}Sujet du commit{$sep}{$corps}";

        $commit = VersionService::parseCommit($raw);

        $this->assertSame('Sujet du commit', $commit['subject']);
        $this->assertSame(
            ['Première explication de ce qui a été fait.', 'Deuxième paragraphe sur le problème résolu.'],
            $commit['paragraphs'],
            'Deux paragraphes, trailer Co-Authored-By exclu',
        );
    }

    public function testSummarizeBodyRetireTrailersEtBorneADeuxParagraphes(): void
    {
        $body = "Para un.\n\nPara deux.\n\nPara trois (ignoré).\n\nSigned-off-by: Qui <a@b.c>";

        $this->assertSame(['Para un.', 'Para deux.'], VersionService::summarizeBody($body));
    }

    public function testSummarizeBodyReunitLesLignesEtTronque(): void
    {
        // Les retours de ligne internes deviennent des espaces ; longueur bornée.
        $long = str_repeat('mot ', 200); // ~800 caractères
        $body = "ligne une\nligne deux\n\n{$long}";

        $paras = VersionService::summarizeBody($body, 2, 50);

        $this->assertSame('ligne une ligne deux', $paras[0]);
        $this->assertStringEndsWith('…', $paras[1]);
        $this->assertLessThanOrEqual(50, mb_strlen($paras[1]));
    }

    public function testSummarizeBodyVideDonneListeVide(): void
    {
        $this->assertSame([], VersionService::summarizeBody(''));
        $this->assertSame([], VersionService::summarizeBody("Co-Authored-By: X <a@b.c>"));
    }

    public function testParseCommitBornageDuCorpsParametrable(): void
    {
        // La page des nouveautés ne veut QUE le premier paragraphe (le « pourquoi »),
        // là où l'infobulle du menu en résume deux, plus court.
        $sep = "\x1f";
        $corps = "Le besoin et le bénéfice, en clair.\n\nDétail technique hors sujet pour l'utilisateur.";
        $raw = "abc1234{$sep}2026-01-02T08:00:00+00:00{$sep}Sujet{$sep}{$corps}";

        $commit = VersionService::parseCommit($raw, maxParagraphs: 1, maxLen: 600);

        $this->assertSame(['Le besoin et le bénéfice, en clair.'], $commit['paragraphs']);
    }

    public function testParseCommitRejetteUneSortieMalformee(): void
    {
        $this->assertNull(VersionService::parseCommit(''));
        $this->assertNull(VersionService::parseCommit('pas-de-separateur'));
        $this->assertNull(VersionService::parseCommit("\x1f2026-01-02T08:00:00+00:00\x1fsujet"), 'ref vide → null');
    }

    /**
     * Lecture de l'historique sur le dépôt réel : c'est la matière de la page des
     * nouveautés. Ignoré si git est indisponible (le service, lui, retombe sur []).
     */
    public function testGetRecentCommitsLitLHistoriqueDuDepot(): void
    {
        $projet = \dirname(__DIR__, 2);
        if (!is_dir($projet . '/.git')) {
            $this->markTestSkipped('Dépôt git absent : lecture de l’historique non vérifiable.');
        }

        $commits = (new VersionService($projet))->getRecentCommits(30);

        if ($commits === []) {
            $this->markTestSkipped('Binaire git indisponible : le service retombe sur une liste vide.');
        }

        $premier = $commits[0];
        $this->assertArrayHasKey('ref', $premier);
        $this->assertArrayHasKey('subject', $premier);
        $this->assertInstanceOf(\DateTimeImmutable::class, $premier['date']);
        $this->assertNotSame('', $premier['ref']);
        $this->assertNotSame('', $premier['subject']);

        $limite = new \DateTimeImmutable('-31 days');
        $precedente = null;
        foreach ($commits as $commit) {
            $this->assertGreaterThan($limite, $commit['date'], 'La fenêtre demandée est respectée.');
            $this->assertLessThanOrEqual(1, count($commit['paragraphs']), 'Un seul paragraphe de description.');
            if ($precedente !== null) {
                $this->assertLessThanOrEqual($precedente, $commit['date'], 'Ordre antéchronologique.');
            }
            $precedente = $commit['date'];
        }
    }

    public function testGetRecentCommitsSansDepotDonneListeVide(): void
    {
        // Hors dépôt (production sans binaire git ou sans .git) : repli silencieux.
        $dir = $this->projetTemporaire("3882\n2026-07-25\n");

        $this->assertSame([], (new VersionService($dir))->getRecentCommits(30));
    }

    private function projetTemporaire(string $contenuVersion): string
    {
        $dir = sys_get_temp_dir() . '/jsb_version_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/VERSION', $contenuVersion);

        // Nettoyage en fin de test.
        register_shutdown_function(static function () use ($dir): void {
            @unlink($dir . '/VERSION');
            @rmdir($dir);
        });

        return $dir;
    }
}
