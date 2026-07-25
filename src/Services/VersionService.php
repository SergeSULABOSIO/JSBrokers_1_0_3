<?php

namespace App\Services;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * @file Version applicative dynamique de JS Brokers.
 * @description Source de vérité UNIQUE de la version affichée. Le numéro est
 * dérivé du nombre de commits git : le dépôt comptait BASELINE_COMMITS commits
 * le jour où l'on a figé la version 1.0 ; chaque commit ajoute +1, et il faut
 * COMMITS_PER_MAJOR commits pour passer de 1.x à 2.x.
 *
 * Au runtime on ne dépend PAS de git : le hook `.githooks/pre-commit` estampille
 * à chaque commit le fichier `VERSION` (nombre total de commits + date), committé
 * avec le code. On lit ce fichier ; en dernier recours seulement (fichier absent
 * en dev avant le 1er commit estampillé) on interroge git, puis on retombe sur la
 * base (⇒ 1.0). À ne pas confondre avec App\Legal\Cgu qui versionne les CGU à des
 * fins juridiques.
 */
class VersionService
{
    /** Nombre de commits correspondant à la version 1.0 (base figée). */
    private const BASELINE_COMMITS = 3881;

    /** Commits nécessaires pour incrémenter le numéro majeur (1.x → 2.x). */
    private const COMMITS_PER_MAJOR = 1000;

    /** Cache des valeurs résolues (fichier lu une seule fois par requête). */
    private ?array $cache = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * Traduit un nombre total de commits en numéro de version « majeur.mineur ».
     * Fonction pure et déterministe : c'est ici, et seulement ici, que vit la
     * règle de numérotation (donc facilement testable, sans filesystem).
     */
    public static function format(int $commitCount): string
    {
        $n = max(0, $commitCount - self::BASELINE_COMMITS);

        $majeur = 1 + intdiv($n, self::COMMITS_PER_MAJOR);
        $mineur = $n % self::COMMITS_PER_MAJOR;

        return $majeur . '.' . $mineur;
    }

    /** Version applicative affichable, ex. « 1.1 ». */
    public function getVersion(): string
    {
        return self::format($this->values()['count']);
    }

    /** Date de la dernière mise à jour (dernier commit estampillé). */
    public function getDate(): \DateTimeImmutable
    {
        return $this->values()['date'];
    }

    /**
     * @return array{count:int, date:\DateTimeImmutable}
     */
    private function values(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        [$count, $date] = $this->readVersionFile();

        if ($count === null) {
            // Repli dev : le fichier VERSION n'a pas encore été estampillé.
            $count = $this->gitCommitCount() ?? self::BASELINE_COMMITS;
        }

        return $this->cache = [
            'count' => $count,
            'date'  => $date ?? new \DateTimeImmutable('today'),
        ];
    }

    /**
     * Lit le fichier `VERSION` (ligne 1 = nb de commits, ligne 2 = date ISO).
     *
     * @return array{0: int|null, 1: \DateTimeImmutable|null}
     */
    private function readVersionFile(): array
    {
        $chemin = $this->projectDir . '/VERSION';
        if (!is_file($chemin) || !is_readable($chemin)) {
            return [null, null];
        }

        $lignes = preg_split('/\R/', trim((string) file_get_contents($chemin))) ?: [];

        $count = isset($lignes[0]) && ctype_digit(trim($lignes[0])) ? (int) trim($lignes[0]) : null;

        $date = null;
        if (isset($lignes[1]) && trim($lignes[1]) !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($lignes[1])) ?: null;
        }

        return [$count, $date];
    }

    /** Dernier recours (dev) : compte les commits via git, ou null si indisponible. */
    private function gitCommitCount(): ?int
    {
        try {
            $process = new Process(['git', 'rev-list', '--count', 'HEAD'], $this->projectDir);
            $process->setTimeout(3);
            $process->run();

            if ($process->isSuccessful()) {
                $out = trim($process->getOutput());
                if (ctype_digit($out)) {
                    return (int) $out;
                }
            }
        } catch (\Throwable) {
            // git indisponible (prod sans binaire) : on retombera sur la base.
        }

        return null;
    }
}
