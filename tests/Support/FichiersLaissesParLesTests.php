<?php

namespace App\Tests\Support;

use PHPUnit\Runner\AfterLastTestHook;
use PHPUnit\Runner\AfterTestHook;
use PHPUnit\Runner\BeforeTestHook;

/**
 * QUI LAISSE DES FICHIERS SUR LE POSTE ? (Serge, 2026-10-22) — extension de suite.
 *
 * Avant chaque test, la liste des fichiers des dossiers de stockage ; après (tearDown passé),
 * ce qui est APPARU et resté. Un test qui crée un cabinet, une pièce ou une conversation de Ket
 * doit effacer ses fichiers comme ses lignes : sans quoi le poste accumule des dizaines de
 * milliers de fichiers orphelins (37 178 nettoyés le 2026-10-22).
 *
 * Le relevé ne fait pas échouer un test : il s'écrit dans var/fichiers-laisses-par-les-tests.log
 * et se résume en fin de suite. Les dossiers sont ceux des paramètres `app.dossier_*`
 * (config/services.yaml) — relus ici en chemins, l'extension tournant hors du conteneur.
 */
final class FichiersLaissesParLesTests implements BeforeTestHook, AfterTestHook, AfterLastTestHook
{
    private const DOSSIERS = [
        'var/uploads/documents',
        'var/uploads/assistant',
        'var/uploads/assistant-documents',
        'var/echange',
        'var/echange/exports',
        'var/ket-voix',
        'public/uploads/logos',
        'public/uploads/documents',
    ];

    /** @var array<string, true> */
    private array $avant = [];

    /** @var array<string, list<string>> test => fichiers laissés */
    private array $laisses = [];

    private static function racine(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** @return array<string, true> chemin relatif => présent */
    private static function inventaire(): array
    {
        $liste = [];
        foreach (self::DOSSIERS as $dossier) {
            $chemin = self::racine() . '/' . $dossier;
            foreach (is_dir($chemin) ? (scandir($chemin) ?: []) : [] as $entree) {
                if ($entree !== '.' && $entree !== '..' && $entree !== '.htaccess' && $entree !== '.gitkeep'
                    && !($dossier === 'var/echange' && $entree === 'exports')
                    && !($dossier === 'var/ket-voix' && $entree === 'epuisement')) {
                    $liste[$dossier . '/' . $entree] = true;
                }
            }
        }

        return $liste;
    }

    public function executeBeforeTest(string $test): void
    {
        $this->avant = self::inventaire();
    }

    public function executeAfterTest(string $test, float $time): void
    {
        $nouveaux = array_keys(array_diff_key(self::inventaire(), $this->avant));
        if ($nouveaux !== []) {
            $this->laisses[$test] = $nouveaux;
        }
    }

    public function executeAfterLastTest(): void
    {
        if ($this->laisses === []) {
            return;
        }
        $lignes = [sprintf('== %s : %d test(s) ont laissé des fichiers', date('Y-m-d H:i:s'), \count($this->laisses))];
        foreach ($this->laisses as $test => $fichiers) {
            $lignes[] = sprintf('%s : %d — %s', $test, \count($fichiers), implode(', ', \array_slice($fichiers, 0, 5)));
        }
        file_put_contents(self::racine() . '/var/fichiers-laisses-par-les-tests.log', implode(PHP_EOL, $lignes) . PHP_EOL, FILE_APPEND);
        fwrite(STDERR, PHP_EOL . sprintf('⚠ %d test(s) ont laissé des fichiers sur le disque — voir var/fichiers-laisses-par-les-tests.log', \count($this->laisses)) . PHP_EOL);
    }
}
