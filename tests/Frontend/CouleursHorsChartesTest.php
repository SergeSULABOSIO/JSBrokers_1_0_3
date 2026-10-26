<?php

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * DES COULEURS HORS CHARTE QUI NE REVIENNENT PAS (chantier 5, décisions de Serge du 2026-10-26).
 *
 *  - `#0d2440` (un bleu nuit qu'aucun jeton ne porte, 47 fois dans 12 fichiers) → cobalt foncé :
 *    `var(--cobalt-dark)` à l'écran, `#003380` partout où app.css n'est pas chargée ;
 *  - titres de section de l'infobulle : gris clair `#adb5bd` (le bleu Bootstrap `#0d6efd` n'y
 *    faisait que 3,6:1 sur le fond sombre) ;
 *  - badge « élevée » : texte foncé sur l'orange (le blanc n'y faisait que 2,4:1).
 */
class CouleursHorsChartesTest extends TestCase
{
    private const RACINE = __DIR__ . '/../../';

    /**
     * Les gabarits rendus SANS app.css, où un jeton `var(--…)` de la charte ne vaut rien :
     * e-mails, export PDF/Word des messages de Ket (DomPDF), et le picker des documents du
     * SOA, servi aussi par la page publique du client (PublicSoaController). Ils écrivent la
     * VALEUR de la couleur.
     */
    private const SANS_APP_CSS = [
        'templates/emails/',
        'templates/admin/assistant_ia/export/',
        'templates/components/soa/_documents_picker.html.twig',
    ];

    public function testLeBleuNuitHorsCharteNeRevientPas(): void
    {
        $fautifs = [];
        foreach ($this->fichiers(['templates', 'assets', 'src'], '/\.(twig|css|js|php|html)$/') as $chemin => $contenu) {
            // En hexadécimal comme en RGB (« rgba(13, 36, 64, .55) » en était une variante).
            if (preg_match('/#0d2440\b|rgba?\(\s*13\s*,\s*36\s*,\s*64\b/i', $contenu) === 1) {
                $fautifs[] = $chemin;
            }
        }

        self::assertSame([], $fautifs, '#0d2440 est hors charte : var(--cobalt-dark), ou #003380 hors app.css.');
    }

    public function testUnGabaritRenduSansAppCssNEmploieAucunJetonDeLaCharte(): void
    {
        $fautifs = [];
        foreach ($this->fichiers(['templates'], '/\.twig$/') as $chemin => $contenu) {
            foreach (self::SANS_APP_CSS as $prefixe) {
                if (str_starts_with($chemin, $prefixe) && preg_match('/var\(--(cobalt|text-|blue-|warning-)/', $contenu) === 1) {
                    $fautifs[] = $chemin;
                }
            }
        }

        self::assertSame([], $fautifs, 'Rendu sans app.css : le jeton ne vaut rien, écrire la valeur (#003380…).');
    }

    public function testLesTitresDeLInfobulleSontEnGrisClair(): void
    {
        $css = (string) file_get_contents(self::RACINE . 'assets/styles/infobulle.css');
        self::assertMatchesRegularExpression('/\.jsb-ctx-tip\s+\.tip-section\s*\{[^}]*color:\s*#adb5bd\b/i', $css);
    }

    public function testLeBadgeEleveeEstEnTexteFonce(): void
    {
        $css = (string) file_get_contents(self::RACINE . 'assets/styles/app.css');
        self::assertMatchesRegularExpression('/\.jsb-state-badge--elevee\s*\{[^}]*color:\s*var\(--text-dark\)/', $css);
    }

    /** @return iterable<string, string> chemin relatif => contenu */
    private function fichiers(array $dossiers, string $extensions): iterable
    {
        foreach ($dossiers as $dossier) {
            $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RACINE . $dossier, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterateur as $fichier) {
                $chemin = str_replace('\\', '/', substr($fichier->getPathname(), strlen(self::RACINE)));
                if (preg_match($extensions, $fichier->getFilename()) !== 1 || str_contains($chemin, '/vendor/')) {
                    continue;
                }
                yield $chemin => (string) file_get_contents($fichier->getPathname());
            }
        }
    }
}
