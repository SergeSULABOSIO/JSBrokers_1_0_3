<?php

namespace App\Tests\Frontend;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Section « Fonctionnalités clés » de la vitrine publique.
 *
 * Verrouille la structure de la section après l'ajout des blocs reflétant les
 * nouveautés du Workspace courtier (collaboration/rôles des invités et
 * comptabilité OHADA) :
 *  - 6 blocs .feature-row, alternance cobalt / clair-inversé préservée ;
 *  - chaque bloc : un titre, une description, un surtitre de liste et une
 *    liste d'au moins 4 capacités ;
 *  - chaque visuel : un alt non vide (WCAG 1.1.1) et un chargement paresseux ;
 *  - les nouveaux contenus sont servis dans les deux langues (FR/EN).
 */
class HomeFeaturesSectionTest extends WebTestCase
{
    public function languages(): array
    {
        return [
            'français' => ['fr', 'Collaboration & accès sécurisés', 'Comptabilité & fiscalité (OHADA)', 'Recherche avancée multicritères'],
            'anglais'  => ['en', 'Collaboration & secure access', 'Accounting & tax (OHADA)', 'Advanced multi-criteria search'],
        ];
    }

    /**
     * @dataProvider languages
     */
    public function testFeaturesSectionStructureAndNewBlocks(
        string $lang,
        string $collabTitle,
        string $comptaTitle,
        string $searchItem
    ): void {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/?lang=' . $lang);
        $this->assertResponseIsSuccessful();

        $rows = $crawler->filter('#features .feature-row');
        $this->assertSame(7, $rows->count(),
            'La section Fonctionnalités doit compter 7 blocs (assistant IA en tête + 4 historiques + collaboration + comptabilité).');

        // Le bloc de TÊTE est l'assistant IA (variante dédiée, hors alternance :
        // c'est sa mise en valeur), sur fond cobalt.
        $this->assertStringContainsString('feature-row--ia', (string) $rows->eq(0)->attr('class'),
            'Le premier bloc doit être celui de l\'assistant IA.');
        $this->assertStringContainsString('feature-row--cobalt', (string) $rows->eq(0)->attr('class'));

        // Alternance visuelle des blocs suivants : cobalt puis clair inversé.
        $rows->each(function (Crawler $row, int $i) {
            if ($i === 0) {
                return; // bloc IA traité ci-dessus
            }
            $class = $row->attr('class');
            if ($i % 2 === 1) {
                $this->assertStringContainsString('feature-row--cobalt', $class,
                    "Le bloc n°" . ($i + 1) . " doit être sur fond cobalt.");
            } else {
                $this->assertStringContainsString('feature-row--light', $class,
                    "Le bloc n°" . ($i + 1) . " doit être sur fond clair.");
                $this->assertStringContainsString('feature-row--reverse', $class,
                    "Le bloc n°" . ($i + 1) . " doit avoir son image à gauche (inversé).");
            }
        });

        // Chaque bloc reste complet : titre(s), description, surtitre et liste fournie.
        $rows->each(function (Crawler $row, int $i) {
            $n = $i + 1;
            $this->assertGreaterThanOrEqual(1, $row->filter('.feature-row__title')->count(), "Titre absent du bloc n°$n.");
            $this->assertGreaterThanOrEqual(1, $row->filter('.feature-row__desc')->count(), "Description absente du bloc n°$n.");
            $this->assertGreaterThanOrEqual(1, $row->filter('.feature-row__intro')->count(), "Surtitre de liste absent du bloc n°$n.");
            $this->assertGreaterThanOrEqual(4, $row->filter('.feature-row__list li')->count(),
                "Le bloc n°$n doit lister au moins 4 capacités.");
        });

        // Visuels : alt non vide (WCAG 1.1.1) + chargement paresseux (performance).
        $crawler->filter('#features .feature-row__media img')->each(function (Crawler $img) {
            $this->assertNotSame('', trim((string) $img->attr('alt')),
                'Chaque visuel de la section doit porter un alt non vide.');
            $this->assertSame('lazy', $img->attr('loading'),
                'Chaque visuel de la section doit être chargé paresseusement.');
        });

        $sectionText = $crawler->filter('#features')->text();

        // Nouveaux blocs présents dans la langue demandée.
        $this->assertStringContainsString($collabTitle, $sectionText,
            "Le bloc Collaboration doit être rendu en $lang.");
        $this->assertStringContainsString($comptaTitle, $sectionText,
            "Le bloc Comptabilité OHADA doit être rendu en $lang.");
        // Nouvel item du bloc Production (recherche avancée).
        $this->assertStringContainsString($searchItem, $sectionText,
            "La recherche avancée doit apparaître dans le bloc Production en $lang.");

        // Visuels dédiés des nouveaux blocs, recadrés en carré (modificateur portrait).
        $portraits = $crawler->filter('#features .feature-row__media--portrait img');
        $this->assertSame(2, $portraits->count(),
            'Les deux nouveaux blocs doivent porter le modificateur portrait (recadrage carré).');
        $sources = $portraits->each(fn (Crawler $img) => $img->attr('src'));
        $this->assertStringContainsString('images/fitures/invite.png', $sources[0]);
        $this->assertStringContainsString('images/fitures/classeur.png', $sources[1]);

        // Aucune clé de traduction fuitée (libellé brut « security_fitures_… » affiché).
        $this->assertStringNotContainsString('security_fitures_', $sectionText,
            'Aucune clé de traduction ne doit fuiter dans la section rendue.');
    }
}
