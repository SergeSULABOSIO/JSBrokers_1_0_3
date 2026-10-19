<?php

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * AUCUN CODE DANS UN ATTRIBUT HTML (chantier 9, politique de sécurité).
 *
 * La politique refuse tout `onclick="…"`, `onchange="…"`… : c'est exactement ce qu'une donnée
 * piégée glisserait dans une page. Les gestes s'écrivent en `data-action` (contrôleur Stimulus
 * `geste`, ou `assets/gestes-autonomes.js` sur les pages sans Stimulus).
 *
 * TOLÉRANCE PROVISOIRE (commit 4) : les appels aux fonctions du tableau de bord (`dbXxx(…)`,
 * assets/app.js) restent à convertir ; ce test les laisse passer et ne laisse passer qu'eux.
 */
class GestesSansCodeTest extends TestCase
{
    private const RACINE = __DIR__ . '/../..';

    /** @return iterable<string, string> chemin relatif => contenu, commentaires Twig blanchis (lignes gardées) */
    private static function gabarits(): iterable
    {
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RACINE . '/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $fichier) {
            $chemin = str_replace('\\', '/', $fichier->getPathname());
            // Les e-mails et les PDF ne passent pas par un navigateur.
            if (!str_ends_with($chemin, '.twig') || preg_match('#/templates/emails/|/pdf/|_pdf\.html#', $chemin)) {
                continue;
            }
            $contenu = (string) preg_replace_callback('/\{#.*?#\}/s', static fn (array $c): string => str_repeat("\n", substr_count($c[0], "\n")), (string) file_get_contents($chemin));

            yield substr($chemin, strpos($chemin, 'templates/')) => $contenu;
        }
    }

    public function testAucunGestionnaireOnDansLesGabaritsHorsTableauDeBord(): void
    {
        $restants = [];
        foreach (self::gabarits() as $chemin => $contenu) {
            preg_match_all('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\')/i', $contenu, $m, \PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$valeur, $position]) {
                if (!preg_match('/\bdb[A-Z]\w*\(/', $valeur)) {
                    $restants[] = $chemin . ':' . (substr_count(substr($contenu, 0, $position), "\n") + 1) . ' ' . substr($valeur, 0, 80);
                }
            }
        }

        self::assertSame([], $restants, 'Code dans un attribut on…= : le remplacer par un data-action (contrôleur geste).');
    }

    public function testChaqueActionGesteDesigneUneMethodeQuiExiste(): void
    {
        preg_match_all('/^    ([a-zA-Z]+)\(/m', (string) file_get_contents(self::RACINE . '/assets/controllers/geste_controller.js'), $m);
        $methodes = $m[1];
        self::assertContains('imprimer', $methodes);

        $inconnues = [];
        foreach (self::gabarits() as $chemin => $contenu) {
            preg_match_all('/->geste#([a-zA-Z]+)/', $contenu, $actions);
            foreach (array_unique($actions[1]) as $action) {
                if (!\in_array($action, $methodes, true)) {
                    $inconnues[] = "$chemin : geste#$action";
                }
            }
        }

        self::assertSame([], $inconnues, 'Action geste# sans méthode dans assets/controllers/geste_controller.js.');
    }

    /**
     * Une page autonome (son propre <html>, sans importmap() donc sans Stimulus) qui porte un
     * geste DOIT charger assets/gestes-autonomes.js — sinon le bouton est mort ; et ce module
     * ne connaît QUE « imprimer » et « fermer » : tout autre geste y serait mort aussi. Les
     * gabarits que la page inclut comptent (un partiel n'a pas de <html>). Une page à
     * Stimulus ne charge JAMAIS le module : le geste partirait deux fois.
     */
    public function testLesPagesAutonomesChargentLeModuleDesGestes(): void
    {
        $gabarits = iterator_to_array(self::gabarits());
        $ecarts = [];
        foreach ($gabarits as $chemin => $contenu) {
            $autonome = str_contains($contenu, '<html') && !str_contains($contenu, 'importmap(');
            $module = (bool) preg_match('/asset\(\s*[\'"]gestes-autonomes\.js[\'"]\s*\)/', $contenu);
            if ($module && !$autonome) {
                $ecarts[] = "$chemin : gestes-autonomes.js chargé hors page autonome (geste en double)";
            }
            if (!$autonome) {
                continue;
            }
            $gestes = self::gestesDe($chemin, $gabarits);
            if ([] !== $gestes && !$module) {
                $ecarts[] = "$chemin : page autonome à geste, sans gestes-autonomes.js";
            }
            foreach (array_diff($gestes, ['imprimer', 'fermer']) as $inconnu) {
                $ecarts[] = "$chemin : geste#$inconnu sur une page autonome (gestes-autonomes.js ne connaît que imprimer et fermer)";
            }
        }

        self::assertSame([], $ecarts);
    }

    /**
     * Les gestes d'un gabarit ET des gabarits qu'il inclut, récursivement.
     *
     * @param array<string, string> $gabarits
     * @param array<string, true>   $vus
     *
     * @return list<string>
     */
    private static function gestesDe(string $chemin, array $gabarits, array $vus = []): array
    {
        if (isset($vus[$chemin]) || !isset($gabarits[$chemin])) {
            return [];
        }
        $vus[$chemin] = true;
        preg_match_all('/->geste#([a-zA-Z]+)/', $gabarits[$chemin], $m);
        $gestes = $m[1];
        preg_match_all('/include\(?\s*[\'"]([^\'"]+\.twig)[\'"]/', $gabarits[$chemin], $inclus);
        foreach ($inclus[1] as $partiel) {
            $gestes = [...$gestes, ...self::gestesDe('templates/' . $partiel, $gabarits, $vus)];
        }

        return array_values(array_unique($gestes));
    }
}
