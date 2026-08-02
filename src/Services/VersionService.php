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

    /** Séparateur d'enregistrement (0x1E) entre commits : même raisonnement que SEP. */
    private const REC = "\x1e";

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
     * Mises à jour de la plateforme sur une fenêtre glissante, du plus récent au
     * plus ancien : de quoi alimenter la page « Nouveautés » qui explique à
     * l'utilisateur ce qui a changé et pourquoi.
     *
     * On ne retient qu'UN paragraphe de corps : dans ce dépôt le premier expose
     * systématiquement le besoin et le bénéfice, alors que les suivants basculent
     * dans la technique (noms de classes, ratios de contraste, comptes de tests)
     * — hors sujet pour un courtier.
     *
     * Chaque entrée porte LA VERSION qu'elle a produite : le numéro n'étant qu'un
     * décompte de commits, le plus récent vaut la version courante et chaque
     * entrée plus ancienne en retire un. C'est la même règle que getVersion(),
     * appliquée au même endroit — l'utilisateur peut ainsi rattacher une
     * amélioration au numéro affiché dans son menu.
     *
     * Liste vide si git est indisponible (production sans binaire) : l'appelant
     * affiche alors l'état dégradé, comme getLastCommit() masque son infobulle.
     *
     * @return list<array{version:string, ref:string, date:\DateTimeImmutable, subject:string, paragraphs:list<string>}>
     */
    public function getRecentCommits(int $jours = 30, int $max = 400): array
    {
        $raw = $this->gitLogRaw($jours, $max);
        if ($raw === null) {
            return [];
        }

        $commits = [];
        foreach (explode(self::REC, $raw) as $enregistrement) {
            if (trim($enregistrement) === '') {
                continue;
            }
            $commit = self::parseCommit($enregistrement, maxParagraphs: 1, maxLen: 600);
            if ($commit !== null) {
                $commits[] = $commit;
            }
        }

        // `git log` remonte l'historique depuis HEAD : la première entrée est donc
        // le commit courant, celui que le fichier VERSION vient d'estampiller.
        $compteur = $this->values()['count'];
        foreach ($commits as $rang => $commit) {
            $commits[$rang]['version'] = self::format($compteur - $rang);
        }

        return $commits;
    }

    /**
     * Analyse la sortie de `git log` pour UN commit (champs `ref SEP date-ISO SEP sujet SEP corps`).
     * Fonction pure : testable sans dépôt git.
     *
     * Le bornage du corps est paramétrable car les deux usages n'ont pas le même
     * besoin : l'infobulle du menu résume en 2 courts paragraphes, tandis que la
     * page des nouveautés veut le premier paragraphe entier (l'explication de
     * l'amélioration telle qu'elle a été rédigée).
     *
     * @return array{ref:string, date:\DateTimeImmutable, subject:string, paragraphs:list<string>}|null
     */
    public static function parseCommit(string $raw, int $maxParagraphs = 2, int $maxLen = 320): ?array
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
            'paragraphs' => self::summarizeBody($body, $maxParagraphs, $maxLen),
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

    /**
     * Variables d'environnement minimales à transmettre au processus git.
     *
     * GOTCHA — sous SAPI web, Dotenv peuple $_ENV avec les SEULES variables de
     * l'application ; Symfony\Process en fait alors l'environnement du processus
     * fils, qui se retrouve sans PATH : git n'est plus localisable (« 'git' n'est
     * pas reconnu… ») alors que la même commande passe en CLI. getenv() lit, lui,
     * le véritable environnement du processus courant : on y repêche de quoi
     * retrouver le binaire. Sous Windows, PATHEXT/ComSpec/SystemRoot sont
     * indispensables en plus de PATH.
     *
     * @return array<string, string>
     */
    private static function environnementGit(): array
    {
        $env = [];

        foreach (['PATH', 'Path', 'PATHEXT', 'SystemRoot', 'ComSpec', 'HOME', 'USERPROFILE'] as $nom) {
            $valeur = getenv($nom);
            if (is_string($valeur) && $valeur !== '') {
                $env[$nom] = $valeur;
            }
        }

        return $env;
    }

    /** Sortie brute de `git log -1` (ref, date ISO, sujet) ou null si git indisponible. */
    private function gitLastCommitRaw(): ?string
    {
        try {
            $format = '%h' . self::SEP . '%cI' . self::SEP . '%s' . self::SEP . '%b';
            $process = new Process(['git', 'log', '-1', '--format=' . $format], $this->projectDir, self::environnementGit());
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
     * Sortie brute de `git log` sur les $jours derniers jours (enregistrements
     * séparés par REC, champs par SEP), ou null si git est indisponible.
     * Le timeout est plus généreux que pour un commit seul : on lit ici quelques
     * centaines d'entrées avec leur corps.
     */
    private function gitLogRaw(int $jours, int $max): ?string
    {
        try {
            $format = self::REC . '%h' . self::SEP . '%cI' . self::SEP . '%s' . self::SEP . '%b';
            $process = new Process([
                'git', 'log',
                '--since=' . $jours . ' days ago',
                '--max-count=' . $max,
                '--format=' . $format,
            ], $this->projectDir, self::environnementGit());
            $process->setTimeout(5);
            $process->run();

            if ($process->isSuccessful()) {
                $out = trim($process->getOutput());
                return $out !== '' ? $out : null;
            }
        } catch (\Throwable) {
            // git indisponible : la page des nouveautés affiche l'état dégradé.
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
            $process = new Process(['git', 'rev-list', '--count', 'HEAD'], $this->projectDir, self::environnementGit());
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
