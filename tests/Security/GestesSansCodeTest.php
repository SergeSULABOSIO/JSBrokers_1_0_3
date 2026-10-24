<?php

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * AUCUN CODE DANS UN ATTRIBUT HTML (chantier 9, politique de sécurité).
 *
 * La politique refuse tout `onclick="…"`, `onchange="…"`… : c'est exactement ce qu'une donnée
 * piégée glisserait dans une page. Les gestes s'écrivent en `data-action` : contrôleur Stimulus
 * `geste` (gestes génériques), `tableau-de-bord` (fonctions `window.db…` d'assets/app.js, liste
 * fermée), ou `assets/gestes-autonomes.js` sur les pages sans Stimulus. Ni les gabarits, ni le
 * HTML que fabrique le JavaScript n'en contiennent plus un seul.
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

    public function testAucunGestionnaireOnDansLesGabarits(): void
    {
        $restants = [];
        foreach (self::gabarits() as $chemin => $contenu) {
            // Les instructions et expressions Twig ({% set onglets = … %}, {{ ongletActif == cle }}) ne
            // sont pas du HTML : blanchies, lignes gardées. Un onclick="{{ x }}" reste vu (ses guillemets restent).
            $contenu = (string) preg_replace_callback('/\{%.*?%\}|\{\{.*?\}\}/s', static fn (array $c): string => str_repeat("\n", substr_count($c[0], "\n")), $contenu);
            // Avec ou sans guillemets : `onclick="…"`, `onclick='…'`, `onclick=foo()`.
            preg_match_all('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>"\'{]+)/i', $contenu, $m, \PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$valeur, $position]) {
                $restants[] = $chemin . ':' . (substr_count(substr($contenu, 0, $position), "\n") + 1) . ' ' . substr($valeur, 0, 80);
            }
        }

        self::assertSame([], $restants, 'Code dans un attribut on…= : le remplacer par un data-action (contrôleurs geste ou tableau-de-bord).');
    }

    /** Le HTML que le JavaScript fabrique (chaînes de assets/, hors vendor) n'en porte pas non plus. */
    public function testAucunGestionnaireOnDansLeHtmlFabriqueParLeJavaScript(): void
    {
        $restants = [];
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RACINE . '/assets', \FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $fichier) {
            $chemin = str_replace('\\', '/', $fichier->getPathname());
            if (!str_ends_with($chemin, '.js') || str_contains($chemin, '/assets/vendor/')) {
                continue;
            }
            // Un attribut on…= ouvert dans une chaîne : `' onclick="` , `"<tr onclick='`, `` `<a onclick=${ `` …
            if (preg_match_all('/\son[a-z]+\s*=\s*(?:\\\\?["\']|\$\{)/i', (string) file_get_contents($chemin), $m)) {
                foreach ($m[0] as $trouve) {
                    $restants[] = substr($chemin, strpos($chemin, 'assets/')) . ' : ' . trim($trouve);
                }
            }
        }

        self::assertSame([], $restants, 'HTML fabriqué avec un on…= : poser un data-action (contrôleur tableau-de-bord ou geste).');
    }

    /**
     * Chaque fonction appelée par `tableau-de-bord#appeler` est dans la LISTE FERMÉE du contrôleur
     * et définie sur `window` par assets/app.js — sinon le geste meurt en silence (le contrôleur
     * refuse). Et la liste ne garde aucune fonction que plus rien n'appelle.
     */
    public function testLesFonctionsDuTableauDeBordSontAutoriseesEtDefinies(): void
    {
        $controleur = (string) file_get_contents(self::RACINE . '/assets/controllers/tableau-de-bord_controller.js');
        self::assertSame(1, preg_match('/FONCTIONS_AUTORISEES = new Set\(\[(.*?)\]\)/s', $controleur, $liste));
        preg_match_all("/'(db[A-Za-z]+)'/", $liste[1], $m);
        $autorisees = $m[1];

        $app = (string) file_get_contents(self::RACINE . '/assets/app.js');
        $sources = [...iterator_to_array(self::gabarits()), 'assets/app.js' => $app];

        $appelees = [];
        $ecarts = [];
        foreach ($sources as $chemin => $contenu) {
            // Toute fonction nommée (guillemets doubles ou simples, préfixe db ou non)…
            preg_match_all('/data-tableau-de-bord-fonction-param\s*=\s*["\']([^"\']+)["\']/', $contenu, $noms);
            // … doit l'être JUSTE après son action, dont on lit l'événement.
            preg_match_all('/data-action="(\w+)->tableau-de-bord#appeler(?::\w+)*"\s+data-tableau-de-bord-fonction-param="([^"]+)"/', $contenu, $paires, \PREG_SET_ORDER);
            if (\count($noms[1]) !== \count($paires)) {
                $ecarts[] = "$chemin : fonction-param sans action tableau-de-bord#appeler juste avant";
            }
            foreach ($paires as [, $evenement, $nom]) {
                $appelees[] = $nom;
                // Signature : (event, élément) pour un menu contextuel, (élément, …) sinon.
                if (str_ends_with($nom, 'CtxOpen') !== ('contextmenu' === $evenement)) {
                    $ecarts[] = "$chemin : $nom déclenchée par « $evenement » (…CtxOpen ⇔ contextmenu)";
                }
            }
        }
        $appelees = array_values(array_unique($appelees));
        sort($appelees);
        $triees = $autorisees;
        sort($triees);

        self::assertSame([], $ecarts);
        self::assertSame($triees, $appelees, 'La liste fermée du contrôleur doit être exactement celle des fonctions appelées.');
        foreach ($autorisees as $nom) {
            self::assertMatchesRegularExpression('/window\.' . $nom . '\s*=/', $app, "$nom n'est pas définie sur window par assets/app.js.");
        }
    }

    /**
     * LE REMBOURSEMENT D'UNE VENTE EST VERROUILLÉ (décision de Serge, 2026-10-20) : irréversible
     * (achat remboursé, tokens retirés du compte, avoir émis, client notifié), il ne part jamais
     * sans confirmation — pas même si le JavaScript ne démarre pas. Son formulaire n'a donc AUCUN
     * bouton d'envoi direct : seul `geste#envoyerApresConfirmation` l'envoie, après la question.
     */
    public function testLeRemboursementNePartJamaisSansConfirmation(): void
    {
        $gabarit = (string) file_get_contents(self::RACINE . '/templates/console/vente/index.html.twig');
        self::assertSame(1, preg_match('#<form[^>]*console\.vente\.refund.*?</form>#s', $gabarit, $m), 'Formulaire de remboursement introuvable.');
        $formulaire = $m[0];

        self::assertDoesNotMatchRegularExpression('/type="submit"|<input[^>]*type="(submit|image)"/i', $formulaire, 'Un bouton d\'envoi direct laisserait partir le remboursement sans question.');
        self::assertMatchesRegularExpression('/<button type="button"[^>]*click->geste#envoyerApresConfirmation[^>]*data-geste-message-param=/s', $formulaire);
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
            // Stimulus vient de l'entrée « app » ; une importmap réduite à l'infobulle
            // (importmap('infobulles-autonomes'), relevé de compte) laisse la page autonome.
            $autonome = str_contains($contenu, '<html') && !preg_match('/importmap\(\s*[\'"]app[\'"]/', $contenu);
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
