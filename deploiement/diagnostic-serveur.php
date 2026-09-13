<?php

/**
 * @file JOSEARA — DIAGNOSTIC D'HÉBERGEMENT. FICHIER JETABLE.
 *
 * ── À QUOI IL SERT ───────────────────────────────────────────────────────────
 * Toute la procédure de mise en production bifurque sur quatre inconnues qu'on
 * ne peut pas deviner depuis le poste de développement : la version de PHP que
 * sert réellement l'hébergeur, les extensions qu'il a activées, ce que son
 * `open_basedir` autorise, et si le serveur peut SORTIR sur Internet. Ce script
 * répond aux quatre en une page, et à une trentaine d'autres questions qu'on
 * aurait posées une par une — chacune au prix d'un aller-retour.
 *
 * ── MODE D'EMPLOI ────────────────────────────────────────────────────────────
 *   1. Changer le JETON ci-dessous (n'importe quelle suite longue et unique).
 *   2. Téléverser ce fichier dans public_html SOUS UN NOM IMPRÉVISIBLE, par
 *      exemple `_d-9f3a71c4e8.php` — pas `diagnostic.php`, que n'importe quel
 *      robot essaie.
 *   3. Ouvrir https://www.joseara.com/_d-9f3a71c4e8.php?jeton=VOTRE-JETON
 *   4. Copier toute la page, puis SUPPRIMER LE FICHIER.
 *
 * ── CE QU'IL NE FAIT PAS ─────────────────────────────────────────────────────
 * Il ne modifie aucun réglage, ne crée aucune table, n'envoie aucun e-mail. Il
 * n'écrit qu'un fichier temporaire de quelques octets — pour vérifier qu'il le
 * peut — et l'efface aussitôt. La seule écriture en base est un CREATE/DROP sur
 * une table jetable, car c'est le SEUL moyen honnête de savoir si les 76
 * migrations pourront s'appliquer : `SHOW GRANTS` ment quand l'hébergeur pose
 * des restrictions ailleurs.
 *
 * ── POURQUOI UN JETON, ET POURQUOI LE SUPPRIMER ──────────────────────────────
 * La page révèle la version de PHP, les fonctions désactivées, l'arborescence
 * du compte et l'existence de la base. C'est une carte du terrain. Le jeton
 * évite qu'elle soit lue en passant ; la suppression évite qu'elle soit lue
 * tout court. Un 404 est renvoyé — et non un 403 — pour ne pas confirmer à un
 * visiteur non authentifié que le fichier existe.
 */

// ⚠ À CHANGER AVANT DE TÉLÉVERSER. Sans cela, le fichier est public.
const JETON = 'CHANGEZ-MOI-AVANT-DE-TELEVERSER';

// ── BASE DE DONNÉES ──────────────────────────────────────────────────────────
// À renseigner APRÈS avoir créé la base dans cPanel → MySQL Databases.
// Laisser tel quel au premier passage : la section « base » dira simplement
// qu'elle n'a pas été renseignée, et tout le reste du diagnostic fonctionne.
const DB_HOTE = '127.0.0.1';
const DB_PORT = 3306;
const DB_BASE = 'À-RENSEIGNER';       // ex. « joseara_prod » (cPanel préfixe !)
const DB_USER = 'À-RENSEIGNER';       // ex. « joseara_app »
const DB_PASS = 'À-RENSEIGNER';

// Domaine de production : sert à vérifier la cohérence de l'hôte servi.
const DOMAINE = 'www.joseara.com';

// ─────────────────────────────────────────────────────────────────────────────

if (!hash_equals(JETON, (string) ($_GET['jeton'] ?? ''))) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

/** Nombre de points en échec : sert au verdict final. */
$echecs = [];

/** Marge de gauche : « OK » quand c'est bon, une flèche voyante sinon. */
function etat(bool $bon, string $sujet = ''): string
{
    global $echecs;
    if (!$bon && $sujet !== '') {
        $echecs[] = $sujet;
    }

    return $bon ? 'OK    ' : '>>>>  ';
}

function titre(string $texte): void
{
    echo "\n\n" . str_repeat('═', 78) . "\n  " . mb_strtoupper($texte) . "\n" . str_repeat('═', 78) . "\n";
}

function ligne(string $texte = ''): void
{
    echo $texte . "\n";
}

/** Rend un octet-compte lisible (les quotas d'hébergeur se lisent en Go). */
function taille(float $octets): string
{
    foreach (['o', 'Ko', 'Mo', 'Go', 'To'] as $unite) {
        if ($octets < 1024) {
            return round($octets, 1) . ' ' . $unite;
        }
        $octets /= 1024;
    }

    return round($octets, 1) . ' Po';
}

/** Convertit « 512M » en octets — les directives PHP mélangent les notations. */
function enOctets(string $valeur): int
{
    $valeur = trim($valeur);
    if ($valeur === '' || $valeur === '-1') {
        return -1;
    }
    $unite = strtolower(substr($valeur, -1));
    $nombre = (int) $valeur;

    return match ($unite) {
        'g' => $nombre * 1024 * 1024 * 1024,
        'm' => $nombre * 1024 * 1024,
        'k' => $nombre * 1024,
        default => $nombre,
    };
}

ligne('JOSEARA — DIAGNOSTIC DE L\'HÉBERGEMENT');
ligne('Généré le ' . date('Y-m-d H:i:s') . ' (heure du serveur)');
ligne(str_repeat('─', 78));

// ═════════════════════════════════════════════════════════════════════════════
titre('1. Identité du serveur');

printf("%sPHP %s — %d bits — SAPI « %s »\n", etat(PHP_VERSION_ID >= 80200, 'PHP >= 8.2'), PHP_VERSION, PHP_INT_SIZE * 8, PHP_SAPI);
if (PHP_VERSION_ID < 80200) {
    ligne('      Joseara exige PHP 8.2 au minimum (composer.json).');
    ligne('      → cPanel → MultiPHP Manager → joseara.com → ea-php82 ou ea-php83.');
}

$utilisateur = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
    : (getenv('USER') ?: getenv('USERNAME') ?: '?');
ligne('      Compte système  : ' . $utilisateur);
ligne('      Binaire PHP     : ' . (PHP_BINARY ?: '?'));
ligne('      Fichier php.ini : ' . (php_ini_loaded_file() ?: '(aucun)'));
ligne('      DOCUMENT_ROOT   : ' . ($_SERVER['DOCUMENT_ROOT'] ?? '?'));
ligne('      Ce fichier      : ' . __DIR__);
ligne('      HTTP_HOST       : ' . ($_SERVER['HTTP_HOST'] ?? '?'));
ligne('      Serveur web     : ' . ($_SERVER['SERVER_SOFTWARE'] ?? '?'));

// Derrière un mandataire inverse, REMOTE_ADDR est celle du mandataire et non
// celle du visiteur : c'est ce qui décide de TRUSTED_PROXIES.
$adresseVue = $_SERVER['REMOTE_ADDR'] ?? '?';
$estMandate = isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_X_FORWARDED_PROTO']);
ligne('      Votre IP vue par PHP : ' . $adresseVue);
if ($estMandate) {
    ligne('      ⚠ En-têtes X-Forwarded-* PRÉSENTES → un mandataire inverse est en place.');
    ligne('        X-Forwarded-For   : ' . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '—'));
    ligne('        X-Forwarded-Proto : ' . ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '—'));
    ligne('        ⇒ Poser TRUSTED_PROXIES=private_ranges dans .env.local, sinon Symfony');
    ligne('          fabriquera des liens en http:// et journalisera l\'IP du mandataire.');
} else {
    ligne('      Aucune en-tête X-Forwarded-* → pas de mandataire détecté sur CETTE requête.');
    ligne('      ⇒ TRUSTED_PROXIES peut rester vide.');
}

// ═════════════════════════════════════════════════════════════════════════════
titre('2. Horloge');

// L'application FIXE son fuseau au démarrage (App\Kernel::boot), donc le
// date.timezone du serveur n'affecte PAS l'affichage. Mais les CRONS, eux,
// s'exécutent à l'heure du serveur : c'est cet écart-là qu'il faut connaître
// pour poser « 06:30 heure de Kinshasa » dans cPanel.
$kinshasa = new DateTime('now', new DateTimeZone('Africa/Kinshasa'));
$serveur  = new DateTime('now');
$ecart    = ($kinshasa->getOffset() - $serveur->getOffset()) / 3600;

ligne('      Fuseau PHP du serveur : ' . date_default_timezone_get() . ' (ini: ' . (ini_get('date.timezone') ?: 'non défini') . ')');
ligne('      Heure serveur         : ' . $serveur->format('Y-m-d H:i:s'));
ligne('      Heure Kinshasa        : ' . $kinshasa->format('Y-m-d H:i:s'));
printf("%sÉcart Kinshasa − serveur : %+d h\n", etat(true), $ecart);
if ((int) $ecart !== 0) {
    ligne('      ⚠ LES HEURES DES CRONS cPanel SONT CELLES DU SERVEUR.');
    ligne(sprintf('        Pour un cron « 06:30 à Kinshasa », écrire %02d:30 dans cPanel.', (6 - (int) $ecart + 24) % 24));
}

// ═════════════════════════════════════════════════════════════════════════════
titre('3. Extensions PHP');

// Cette liste est celle de composer.json APRÈS correction, c'est-à-dire les
// extensions réellement exigées par Doctrine, PhpSpreadsheet, PhpWord, DomPDF,
// TCPDF, VichUploader, symfony/intl et symfony/http-client.
$requises = [
    'ctype'      => 'composer.json (polyfill neutralisé par « replace »)',
    'iconv'      => 'composer.json + smalot/pdfparser',
    'mbstring'   => 'DomPDF, PhpSpreadsheet — polyfill NEUTRALISÉ, extension obligatoire',
    'intl'       => 'symfony/intl, twig/intl-extra — montants et dates FR/EN',
    'gd'         => 'PhpSpreadsheet, PhpWord, endroid/qr-code, validation d\'images IA',
    'zip'        => 'PhpSpreadsheet, PhpWord — XLSX et DOCX sont des archives ZIP',
    'dom'        => 'DomPDF, PhpSpreadsheet, PhpWord',
    'libxml'     => 'PhpSpreadsheet',
    'xml'        => 'PhpSpreadsheet, PhpWord',
    'simplexml'  => 'PhpSpreadsheet, VichUploader',
    'xmlreader'  => 'PhpSpreadsheet',
    'xmlwriter'  => 'PhpSpreadsheet',
    'fileinfo'   => 'VichUploader — détection du type des fichiers téléversés',
    'filter'     => 'PhpSpreadsheet',
    'json'       => 'PhpWord + toute l\'application',
    'curl'       => 'tecnickcom/tcpdf (exigence dure) + appels à l\'API Gemini',
    'openssl'    => 'SMTP en TLS/SSL, HTTPS sortant',
    'pdo'        => 'Doctrine',
    'pdo_mysql'  => 'Doctrine sur MariaDB',
    'tokenizer'  => 'phpdocumentor/reflection-docblock',
    'zlib'       => 'PhpSpreadsheet, pdfparser, fpdi',
    'session'    => 'framework.yaml : session activée',
    'hash'       => 'hachage des mots de passe, signatures d\'URL',
];

$manquantes = [];
foreach ($requises as $extension => $pourquoi) {
    $presente = extension_loaded($extension);
    if (!$presente) {
        $manquantes[] = $extension;
    }
    printf("%s%-12s %s\n", etat($presente, "extension $extension"), $extension, $presente ? '' : '← MANQUANTE : ' . $pourquoi);
}

ligne();
if ($manquantes !== []) {
    ligne('  ⚠ À ACTIVER : cPanel → Select PHP Version → onglet « Extensions »,');
    ligne('    cocher : ' . implode(', ', $manquantes));
    ligne('    puis RELANCER ce diagnostic.');
} else {
    ligne('  Toutes les extensions requises sont présentes.');
}

ligne();
$opcache = extension_loaded('Zend OPcache');
printf("%sOPcache : %s\n", etat($opcache), $opcache ? 'actif' : 'ABSENT (le site fonctionnera, mais nettement plus lentement)');
if ($opcache && function_exists('opcache_get_configuration')) {
    $directives = opcache_get_configuration()['directives'];
    $valide = (bool) ($directives['opcache.validate_timestamps'] ?? true);
    printf("%s  validate_timestamps = %s\n", etat($valide, 'opcache.validate_timestamps'), var_export($valide, true));
    if (!$valide) {
        ligne('      ⚠⚠ CRITIQUE POUR LE DÉPLOIEMENT : à « false », PHP garde en mémoire');
        ligne('         l\'ANCIEN code. Une mise à jour n\'aurait AUCUN effet visible tant');
        ligne('         que PHP-FPM n\'est pas redémarré — ce qu\'un compte mutualisé ne');
        ligne('         peut pas faire. → MultiPHP INI Editor : opcache.validate_timestamps=1');
    }
    $memoire = (int) ($directives['opcache.memory_consumption'] ?? 0);
    printf("      memory_consumption    = %s (viser >= 192 Mo)\n", taille($memoire));
    printf("      max_accelerated_files = %s (viser >= 20000 : Symfony a beaucoup de fichiers)\n", $directives['opcache.max_accelerated_files'] ?? '?');
}

if (defined('INTL_ICU_VERSION')) {
    ligne('      ICU (intl) : ' . INTL_ICU_VERSION);
}
if (function_exists('gd_info')) {
    $gd = array_keys(array_filter(gd_info(), static fn ($v) => $v === true));
    ligne('      GD         : ' . implode(', ', $gd));
}

// ═════════════════════════════════════════════════════════════════════════════
titre('4. Réglages PHP');

// Chaque cible est justifiée : ce ne sont pas des valeurs de confort.
$cibles = [
    'memory_limit'        => ['512M', 'les exports Excel et les imports par paliers tiennent la mémoire'],
    'max_execution_time'  => ['120',  'un import ou un gros PDF dépasse les 30 s par défaut'],
    'upload_max_filesize' => ['32M',  'bordereaux et pièces jointes du chat'],
    'post_max_size'       => ['36M',  'doit dépasser upload_max_filesize'],
    'max_input_vars'      => ['5000', 'LE PLUS SOUS-ESTIMÉ — voir ci-dessous'],
    'max_input_time'      => ['120',  'téléversement de gros classeurs'],
];

foreach ($cibles as $directive => [$cible, $pourquoi]) {
    $actuel = (string) ini_get($directive);
    $suffisant = enOctets($actuel) === -1 || enOctets($actuel) >= enOctets($cible);
    printf("%s%-20s = %-8s (cible %-6s) %s\n", etat($suffisant, $directive), $directive, $actuel, $cible, $suffisant ? '' : '← ' . $pourquoi);
}

if (enOctets((string) ini_get('max_input_vars')) < 5000) {
    ligne();
    ligne('  ⚠ max_input_vars MÉRITE UNE EXPLICATION : les formulaires « canevas » de');
    ligne('    Joseara envoient beaucoup de champs. Au-delà de cette limite, PHP');
    ligne('    TRONQUE LE POST EN SILENCE — l\'enregistrement perd des données, sans la');
    ligne('    moindre erreur, ni à l\'écran ni dans les journaux. C\'est la panne la');
    ligne('    plus difficile à diagnostiquer de toute cette liste.');
    ligne('    → cPanel → MultiPHP INI Editor → Editor Mode → max_input_vars = 5000');
}

ligne();
ligne('      session.save_path      = ' . (ini_get('session.save_path') ?: '(défaut système)'));
ligne('      realpath_cache_size    = ' . ini_get('realpath_cache_size'));
ligne('      default_socket_timeout = ' . ini_get('default_socket_timeout') . ' s');

// ═════════════════════════════════════════════════════════════════════════════
titre('5. Ce qui peut tout bloquer');

// open_basedir décide de l'architecture entière : si le code ne peut pas vivre
// hors de public_html, la racine web ne peut pas être déplacée et il faut le
// point d'entrée déporté (« Plan C » du plan de déploiement).
$baseDir = (string) ini_get('open_basedir');
printf("%sopen_basedir = %s\n", etat($baseDir === '', 'open_basedir'), $baseDir === '' ? '(aucun — idéal)' : $baseDir);
if ($baseDir !== '') {
    ligne('      ⚠ DÉCIDE DE TOUTE L\'ARCHITECTURE. Le code ne pourra vivre QUE dans ces');
    ligne('        chemins. Si « /home/<compte> » y figure, tout va bien : le dépôt ira');
    ligne('        dans /home/<compte>/joseara, hors racine web, comme prévu.');
    ligne('        Si SEUL public_html est listé, il faut le point d\'entrée déporté');
    ligne('        (« Plan C ») — ou demander à l\'hébergeur d\'élargir la directive.');
}

$desactivees = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
ligne();
ligne('      disable_functions (' . count($desactivees) . ') : ' . ($desactivees !== [] ? implode(', ', $desactivees) : '(aucune)'));
ligne();

$fonctions = [
    'proc_open'      => 'transport mailer « sendmail:// » ET Symfony Process (page /nouveautes)',
    'popen'          => 'transport mailer « sendmail:// »',
    'symlink'        => 'assets:install --symlink (repli en copie : non bloquant)',
    'set_time_limit' => 'imports longs (repli sur max_execution_time)',
    'putenv'         => 'certaines bibliothèques tierces',
];
foreach ($fonctions as $fonction => $usage) {
    $dispo = function_exists($fonction) && !in_array($fonction, $desactivees, true);
    printf("%s%-16s %s\n", etat($dispo), $fonction, $dispo ? $usage : '← DÉSACTIVÉE : ' . $usage);
}

if (!function_exists('proc_open') || in_array('proc_open', $desactivees, true)) {
    ligne();
    ligne('  ⚠ proc_open DÉSACTIVÉE — deux conséquences, une seule gênante :');
    ligne('    1. MAILER_DSN=sendmail://default NE FONCTIONNERA PAS.');
    ligne('       → utiliser smtp://contact%40joseara.com:<mdp>@mail.joseara.com:465?encryption=ssl');
    ligne('    2. La page /nouveautes ne pourra pas lire le journal git. Sans gravité :');
    ligne('       le code retombe déjà proprement sur le fichier VERSION.');
}

// ═════════════════════════════════════════════════════════════════════════════
titre('6. Écriture disque et quota');

$temoin = __DIR__ . '/_diag_' . bin2hex(random_bytes(6)) . '.tmp';
$peutEcrire = @file_put_contents($temoin, 'test') !== false;
printf("%sÉcriture dans %s\n", etat($peutEcrire, 'écriture disque'), __DIR__);
if ($peutEcrire) {
    @unlink($temoin);
}

$tmp = sys_get_temp_dir();
printf("%ssys_get_temp_dir() = %s\n", etat(is_writable($tmp)), $tmp);

$libre = @disk_free_space(__DIR__);
$total = @disk_total_space(__DIR__);
if ($libre !== false) {
    // Budget : vendor ~185 Mo + var/cache ~100 Mo + assets + marge.
    printf("%sEspace libre : %s%s\n", etat($libre > 1073741824, 'espace disque'), taille((float) $libre), $total ? ' sur ' . taille((float) $total) : '');
    ligne('      Budget Joseara : vendor ≈ 185 Mo, var/cache ≈ 100 Mo, public/assets ≈ 20 Mo.');
}

ligne();
ligne('      ⚠ LE QUOTA D\'INODES (nombre de fichiers) sature souvent avant l\'espace.');
ligne('        Budget Joseara : vendor ≈ 28 000, var/cache/prod ≈ 8 000,');
ligne('        public/assets ≈ 1 500, application ≈ 6 000  →  environ 45 000 fichiers.');
ligne('        À lire dans cPanel, barre latérale de la page d\'accueil : « File Usage ».');
ligne('        Sous 200 000 c\'est confortable ; sous 50 000 il faut négocier.');

// ═════════════════════════════════════════════════════════════════════════════
titre('7. Réseau sortant — décide de la stratégie de déploiement');

$destinations = [
    'https://repo.packagist.org/packages.json'                  => 'composer install SUR le serveur',
    'https://api.github.com/'                                   => 'git clone / pull en HTTPS',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/package.json'  => 'importmap:install (évité : assets/vendor sera versionné)',
    'https://generativelanguage.googleapis.com/'                 => 'ASSISTANT IA GEMINI — sans cela, Ket ne peut pas répondre',
];

$curlDispo = function_exists('curl_init');
foreach ($destinations as $url => $usage) {
    $depart = microtime(true);
    $joignable = false;

    if ($curlDispo) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT      => 'joseara-diagnostic',
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_exec($ch);
        $joignable = curl_errno($ch) === 0;
        $erreur = curl_error($ch);
        curl_close($ch);
    } else {
        $contexte = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 10, 'user_agent' => 'joseara-diagnostic']]);
        $joignable = @file_get_contents($url, false, $contexte) !== false || !empty($http_response_header);
        $erreur = 'allow_url_fopen';
    }

    printf(
        "%s%-32s %-58s %d ms%s\n",
        etat($joignable, 'réseau ' . parse_url($url, PHP_URL_HOST)),
        (string) parse_url($url, PHP_URL_HOST),
        $usage,
        (int) ((microtime(true) - $depart) * 1000),
        $joignable ? '' : '  ← INJOIGNABLE (' . ($erreur ?? '?') . ')'
    );
}

ligne();
printf("      allow_url_fopen = %s | cURL = %s\n", ini_get('allow_url_fopen') ? '1' : '0', $curlDispo ? curl_version()['version'] : 'ABSENT');
ligne();
ligne('      Lecture : si packagist et github sont injoignables, « composer install »');
ligne('      et « git pull » ne peuvent PAS tourner sur le serveur → il faudra');
ligne('      téléverser vendor/ construit localement (le plan prévoit ce repli).');
ligne('      Si googleapis est injoignable, poser AI_ENGINE=simulated : Ket répondra');
ligne('      sans moteur réel plutôt que d\'échouer à chaque message.');

// ═════════════════════════════════════════════════════════════════════════════
titre('8. Base de données');

if (DB_BASE === 'À-RENSEIGNER') {
    ligne('  (non renseignée — créez la base dans cPanel → MySQL Databases, puis');
    ligne('   remplissez DB_BASE / DB_USER / DB_PASS en haut de ce fichier et rechargez)');
} else {
    $connecte = false;
    // cPanel expose tantôt TCP sur 127.0.0.1, tantôt seulement la socket Unix
    // (« localhost »). On essaie les deux : c'est ce qui ira dans DATABASE_URL.
    foreach ([[DB_HOTE, 'TCP ' . DB_HOTE], ['localhost', 'socket localhost']] as [$hote, $mode]) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $hote, DB_PORT, DB_BASE),
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 6]
            );

            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            printf("%sConnexion via %s\n", etat(true), $mode);
            ligne('      Serveur : ' . $version);
            ligne();
            ligne('      ⇒ À RECOPIER TEL QUEL dans DATABASE_URL de .env.local :');
            ligne('        serverVersion=' . $version);
            ligne('        (Doctrine choisit sa grammaire SQL dessus — une valeur fausse');
            ligne('         produit des migrations qui échouent sans raison apparente.)');
            ligne();

            foreach (['max_allowed_packet', 'wait_timeout', 'character_set_server', 'collation_server', 'innodb_buffer_pool_size'] as $variable) {
                $ligne = $pdo->query("SHOW VARIABLES LIKE '$variable'")->fetch(PDO::FETCH_NUM);
                ligne(sprintf('      %-26s = %s', $variable, $ligne[1] ?? '?'));
            }

            // LE test qui compte. « SHOW GRANTS » peut annoncer ALL PRIVILEGES
            // alors que l'hébergeur bride ailleurs : les 76 migrations ont
            // besoin de CREATE, ALTER, DROP, INDEX et FOREIGN KEY. On les
            // exerce pour de vrai, sur une table jetable.
            ligne();
            try {
                $pdo->exec('CREATE TABLE _diag_joseara (id INT NOT NULL AUTO_INCREMENT, PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                $pdo->exec('ALTER TABLE _diag_joseara ADD COLUMN libelle VARCHAR(32) DEFAULT NULL');
                $pdo->exec('CREATE INDEX idx_diag ON _diag_joseara (libelle)');
                $pdo->exec('CREATE TABLE _diag_joseara_fk (id INT NOT NULL AUTO_INCREMENT, parent_id INT DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT fk_diag FOREIGN KEY (parent_id) REFERENCES _diag_joseara (id)) ENGINE=InnoDB');
                $pdo->exec('DROP TABLE _diag_joseara_fk');
                $pdo->exec('DROP TABLE _diag_joseara');
                printf("%sDroits CREATE / ALTER / INDEX / FOREIGN KEY / DROP — les 76 migrations passeront\n", etat(true));
            } catch (Throwable $e) {
                printf("%sDroits INSUFFISANTS : %s\n", etat(false, 'droits DDL'), $e->getMessage());
                ligne('      → cPanel → MySQL Databases → « Add User To Database » → ALL PRIVILEGES');
                // Ménage au cas où l'échec est survenu en cours de route.
                foreach (['_diag_joseara_fk', '_diag_joseara'] as $reste) {
                    try {
                        $pdo->exec("DROP TABLE IF EXISTS $reste");
                    } catch (Throwable) {
                    }
                }
            }

            $tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
            ligne();
            printf("      Tables déjà présentes : %d %s\n", $tables, $tables === 0 ? '(base vierge, comme attendu)' : '← la base n\'est PAS vide');

            $connecte = true;
            break;
        } catch (Throwable $e) {
            printf("%sConnexion via %-18s : %s\n", etat(false), $mode, $e->getMessage());
        }
    }

    if (!$connecte) {
        $echecs[] = 'connexion base de données';
        ligne();
        ligne('      → Vérifier dans cPanel → MySQL Databases que la base ET l\'utilisateur');
        ligne('        existent, et que l\'utilisateur est RATTACHÉ à la base avec ALL');
        ligne('        PRIVILEGES. cPanel PRÉFIXE les noms du compte (« compte_joseara »)');
        ligne('        et les TRONQUE : recopier le nom exact affiché par cPanel.');
    }
}

// ═════════════════════════════════════════════════════════════════════════════
titre('9. Envoi d\'e-mails');

// Les e-mails partiront en mode SYNCHRONE (voir when@prod de messenger.yaml) :
// la voie la plus rapide est donc la meilleure, car elle est dans la requête.
$sendmail = (string) ini_get('sendmail_path');
printf("%ssendmail_path = %s\n", etat($sendmail !== ''), $sendmail ?: '(vide)');
printf("%s/usr/sbin/sendmail exécutable  → MAILER_DSN=sendmail://default\n", etat(is_executable('/usr/sbin/sendmail')));

foreach ([25 => 'SMTP local en clair', 465 => 'SMTPS (mail.joseara.com)', 587 => 'SMTP soumission'] as $port => $usage) {
    $flux = @fsockopen('127.0.0.1', $port, $errno, $errstr, 4);
    printf("%s127.0.0.1:%-4d %s\n", etat($flux !== false), $port, $usage);
    if ($flux !== false) {
        fclose($flux);
    }
}

$flux = @fsockopen('ssl://' . str_replace('www.', 'mail.', DOMAINE), 465, $errno, $errstr, 6);
printf("%smail.%s:465 en SSL (le transport retenu au plan)\n", etat($flux !== false), str_replace('www.', '', DOMAINE));
if ($flux !== false) {
    fclose($flux);
} else {
    ligne('      ← ' . ($errstr ?: 'injoignable') . ' — repli : sendmail://default ou smtp://127.0.0.1:25');
}

ligne();
ligne('      ⚠ NE PAS OUBLIER cPanel → Email Deliverability → « Repair » :');
ligne('        sans SPF et DKIM valides, chaque e-mail d\'inscription part en');
ligne('        indésirable, et l\'inscription paraît cassée alors qu\'elle marche.');

// ═════════════════════════════════════════════════════════════════════════════
titre('10. HTTPS');

$securise = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
printf("%sCette page est servie en %s\n", etat($securise, 'HTTPS'), $securise ? 'HTTPS' : 'HTTP SIMPLE');
if (!$securise) {
    ligne('      → cPanel → SSL/TLS Status → cocher joseara.com, www. et mail. → Run AutoSSL');
    ligne('      → puis cPanel → Domains → interrupteur « Force HTTPS Redirect »');
    ligne('      ⚠ Activer SOIT cet interrupteur, SOIT le bloc HTTPS du .htaccess.');
    ligne('        Les deux ensemble produisent une boucle de redirection.');
}

// ═════════════════════════════════════════════════════════════════════════════
titre('Verdict');

if ($echecs === []) {
    ligne('  ✔ AUCUN POINT BLOQUANT. Le serveur est prêt à recevoir Joseara.');
} else {
    ligne('  ' . count($echecs) . ' POINT(S) À TRAITER AVANT DE DÉPLOYER :');
    foreach ($echecs as $index => $echec) {
        ligne(sprintf('    %d. %s', $index + 1, $echec));
    }
}

ligne();
ligne(str_repeat('─', 78));
ligne('  ⚠ SUPPRIMEZ CE FICHIER MAINTENANT');
ligne('    cPanel → Gestionnaire de fichiers → public_html → ' . basename(__FILE__) . ' → Supprimer');
ligne(str_repeat('─', 78));
