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

    /** Séparateur d'unité (0x1F) entre champs de `git log` : jamais présent dans un message. */
    private const SEP = "\x1f";

    /** Cache des valeurs résolues (fichier lu une seule fois par requête). */
    private ?array $cache = null;

    /** Cache du dernier commit (false = déjà cherché et indisponible). */
    private array|false|null $lastCommit = null;

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
     * Dernier commit effectué, pour communiquer sur la mise à jour au survol de
     * la version : réf courte, date, sujet (titre de ce qui a été fait) et
     * paragraphes du corps (explication détaillée, trailers retirés). Lu via git
     * (mémoïsé) ; null si git est indisponible (prod sans binaire) → l'appelant
     * masque simplement l'infobulle.
     *
     * @return array{ref:string, date:\DateTimeImmutable, subject:string, paragraphs:list<string>}|null
     */
    public function getLastCommit(): ?array
    {
        if ($this->lastCommit !== null) {
            return $this->lastCommit ?: null;
        }

        $raw = $this->gitLastCommitRaw();
        $this->lastCommit = ($raw !== null ? self::parseCommit($raw) : null) ?? false;

        return $this->lastCommit ?: null;
    }

    /**
     * Analyse la sortie de `git log -1` (champs `ref SEP date-ISO SEP sujet SEP corps`).
     * Fonction pure : testable sans dépôt git.
     *
     * @return array{ref:string, date:\DateTimeImmutable, subject:string, paragraphs:list<string>}|null
     */
    public static function parseCommit(string $raw): ?array
    {
        $parts = explode(self::SEP, trim($raw), 4);
        if (count($parts) < 3) {
            return null;
        }

        $ref = trim($parts[0]);
        $subject = trim($parts[2]);
        $body = $parts[3] ?? '';
        if ($ref === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable(trim($parts[1]));
        } catch (\Exception) {
            return null;
        }

        return [
            'ref' => $ref,
            'date' => $date,
            'subject' => $subject,
            'paragraphs' => self::summarizeBody($body),
        ];
    }

    /**
     * Transforme le corps brut d'un commit en 1 à 2 paragraphes lisibles :
     * retire les trailers (`Co-Authored-By:`, `Signed-off-by:`…), découpe sur les
     * lignes vides, réunit les retours de ligne internes en espaces (prose fluide)
     * et borne la longueur. Fonction pure : testable sans dépôt git.
     *
     * @return list<string>
     */
    public static function summarizeBody(string $body, int $maxParagraphs = 2, int $maxLen = 320): array
    {
        $lignes = preg_split('/\R/', str_replace("\r\n", "\n", $body)) ?: [];

        // Retire les lignes de trailer (métadonnées de fin de message, hors sujet).
        $lignes = array_filter($lignes, static fn (string $l): bool
            => !preg_match('/^\s*(co-authored-by|signed-off-by|acked-by|reviewed-by|tested-by|cc)\s*:/i', $l));

        $bruts = preg_split('/\n\s*\n/', trim(implode("\n", $lignes))) ?: [];

        $paragraphs = [];
        foreach ($bruts as $p) {
            $p = trim(preg_replace('/\s*\n\s*/', ' ', $p) ?? '');
            if ($p === '') {
                continue;
            }
            if (mb_strlen($p) > $maxLen) {
                $p = rtrim(mb_substr($p, 0, $maxLen - 1)) . '…';
            }
            $paragraphs[] = $p;
            if (count($paragraphs) >= $maxParagraphs) {
                break;
            }
        }

        return $paragraphs;
    }

    /** Sortie brute de `git log -1` (ref, date ISO, sujet) ou null si git indisponible. */
    private function gitLastCommitRaw(): ?string
    {
        try {
            $format = '%h' . self::SEP . '%cI' . self::SEP . '%s' . self::SEP . '%b';
            $process = new Process(['git', 'log', '-1', '--format=' . $format], $this->projectDir);
            $process->setTimeout(3);
            $process->run();

            if ($process->isSuccessful()) {
                $out = trim($process->getOutput());
                return $out !== '' ? $out : null;
            }
        } catch (\Throwable) {
            // git indisponible : l'infobulle est simplement masquée.
        }

        return null;
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
