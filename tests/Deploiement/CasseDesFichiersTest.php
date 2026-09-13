<?php

namespace App\Tests\Deploiement;

use PHPUnit\Framework\TestCase;

/**
 * Le dépôt doit pouvoir être cloné sur un système de fichiers SENSIBLE À LA CASSE.
 *
 * ── L'INCIDENT DU 2026-09-13 ────────────────────────────────────────────────
 * La toute première mise en production de Joseara a échoué là-dessus, et le
 * message d'erreur ne parlait ni de casse ni de Windows :
 *
 *   Expected to find class "App\Form\OffreindemnisationSinistreType" in file
 *   "…/src/Form/OffreindemnisationSinistreType.php", but it was not found!
 *
 * Git avait enregistré « Offre*i*ndemnisation… », le disque du développeur
 * portait « Offre*I*ndemnisation… », et la classe déclarait le I majuscule.
 * Windows ne distingue pas les deux : tout fonctionnait depuis des mois. Au
 * clone sur Linux, c'est le nom de GIT qui est écrit — la classe devient
 * introuvable, le conteneur ne se construit plus, et l'application entière
 * s'arrête. Pas une page : toutes.
 *
 * ── POURQUOI CE TEST INTERROGE GIT, ET PAS LE DISQUE ────────────────────────
 * Sur Windows, scandir() rend le nom réel du disque — qui était correct. Le
 * défaut n'existait QUE dans l'index de git. Lire le disque n'aurait donc rien
 * vu, et c'est précisément pour cela que rien ne l'a vu pendant des mois.
 */
final class CasseDesFichiersTest extends TestCase
{
    private static function racine(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** @return list<string>|null  Les chemins suivis par git, ou null s'il est indisponible. */
    private static function fichiersSuivis(): ?array
    {
        $sortie = [];
        $code = 1;
        // 2>&1 pour ne pas laisser un message d'erreur de git salir la sortie
        // de PHPUnit quand le dossier n'est pas un dépôt (archive, image Docker).
        @exec(sprintf('git -C %s ls-files 2>&1', escapeshellarg(self::racine())), $sortie, $code);

        return 0 === $code ? $sortie : null;
    }

    /**
     * Le nom enregistré par git doit correspondre, CASSE COMPRISE, au nom réel.
     */
    public function testLeNomEnregistreParGitEstCeluiDuDisque(): void
    {
        $suivis = self::fichiersSuivis();

        if (null === $suivis) {
            self::markTestSkipped('git indisponible : rien à comparer.');
        }

        $divergences = [];
        $contenus = [];

        foreach ($suivis as $chemin) {
            $dossier = self::racine() . '/' . \dirname($chemin);
            $nom = basename($chemin);

            $contenus[$dossier] ??= is_dir($dossier) ? (scandir($dossier) ?: []) : [];
            if ([] === $contenus[$dossier]) {
                continue;
            }

            // Comparaison STRICTE : in_array avec $strict, car « == » ne
            // distinguerait pas davantage les casses que le système de fichiers.
            if (\in_array($nom, $contenus[$dossier], true)) {
                continue;
            }

            foreach ($contenus[$dossier] as $reel) {
                if (0 === strcasecmp($reel, $nom)) {
                    $divergences[] = sprintf('%s  (git) ≠ %s  (disque)', $chemin, $reel);
                    break;
                }
            }
        }

        self::assertSame(
            [],
            $divergences,
            "Ces fichiers portent un nom différent dans git et sur le disque.\n"
            . "Sur un système sensible à la casse — tout serveur Linux — c'est le nom de GIT\n"
            . "qui est écrit, et la classe devient introuvable.\n"
            . "Corriger par un renommage en deux temps :\n"
            . "  git mv <ancien> <temporaire> && git mv <temporaire> <nouveau>\n\n"
            . implode("\n", $divergences)
        );
    }

    /**
     * Et, pour les classes de src/, le nom de fichier doit être celui de la
     * classe : c'est la règle PSR-4, et ce que Composer exige pour la trouver.
     * On lit le nom tel que GIT l'enregistre — c'est lui qui atterrira sur le
     * serveur.
     */
    public function testChaqueClasseDeSrcVitDansUnFichierPortantSonNom(): void
    {
        $suivis = self::fichiersSuivis();

        if (null === $suivis) {
            self::markTestSkipped('git indisponible : rien à comparer.');
        }

        $fautes = [];

        foreach ($suivis as $chemin) {
            if (!str_starts_with($chemin, 'src/') || !str_ends_with($chemin, '.php')) {
                continue;
            }

            $source = @file_get_contents(self::racine() . '/' . $chemin);
            if (false === $source) {
                continue;
            }

            if (!preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $trouve)) {
                continue; // fichier sans type nommé : rien à vérifier
            }

            $attendu = $trouve[1] . '.php';
            if (basename($chemin) !== $attendu) {
                $fautes[] = sprintf('%s  déclare  %s  → attendu : %s', $chemin, $trouve[1], $attendu);
            }
        }

        self::assertSame(
            [],
            $fautes,
            "PSR-4 : le fichier doit porter le nom de la classe, casse comprise.\n"
            . "Composer ignore silencieusement les classes non conformes — et l'erreur\n"
            . "n'apparaît qu'au premier serveur sensible à la casse.\n\n"
            . implode("\n", $fautes)
        );
    }
}
