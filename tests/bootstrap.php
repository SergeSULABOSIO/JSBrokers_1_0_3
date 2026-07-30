<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

/*
 * Purge des sessions de test résiduelles.
 *
 * Chaque client de test crée un fichier dans var/cache/test/sessions, et rien ne
 * les efface : le dossier atteignait 11 000 fichiers. MockFileSessionStorage
 * termine chaque écriture par un rename() — sur Windows, avec autant de fichiers
 * dans le dossier (indexation, antivirus), ce rename échoue par intermittence
 * avec « Accès refusé », et le test en cours reçoit un 500. Le symptôme est
 * déroutant : la défaillance frappe un test DIFFÉRENT à chaque exécution, sans
 * rapport avec la modification en cours, et disparaît quand on le relance seul.
 *
 * Le nettoyage est fait ici, une fois par exécution, avant tout test.
 */
$sessions = dirname(__DIR__).'/var/cache/test/sessions';
if (is_dir($sessions)) {
    foreach (glob($sessions.'/*') ?: [] as $fichier) {
        if (is_file($fichier)) {
            @unlink($fichier);
        }
    }
}
