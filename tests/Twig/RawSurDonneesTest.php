<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * AUCUN `|raw` SANS MOTIF ÉCRIT.
 *
 * `|raw` coupe l'échappement de Twig. Sur une donnée saisie, c'est une injection : le
 * sujet d'un ticket rendu en `|raw` s'exécutait dans la console (2026-10-16), un nom de
 * visiteur concaténé au balisage d'un e-mail partait en HTML à l'adresse de son choix.
 *
 * Ce test inventorie chaque `{{ … |raw }}` des gabarits. Un site est admis s'il est :
 *  - une traduction littérale sans paramètre (`'cle'|trans|raw`) ;
 *  - une traduction dont CHAQUE paramètre est échappé (`trans({':x': v|e})|raw`) ;
 *  - inscrit ci-dessous, avec son motif.
 * Tout autre `|raw` fait échouer le test : on l'échappe, ou l'on écrit pourquoi il est sûr.
 */
class RawSurDonneesTest extends TestCase
{
    /**
     * Sites admis : gabarit => [expression (regex) => motif].
     *
     * @var array<string, array<string, string>>
     */
    private const ADMIS = [
        'admin/assistant_ia/export/message_pdf.html.twig' => [
            '/^corpsHtml\|raw$/' => 'Fragment rendu SOUS AUTOÉCHAPPEMENT par _message_corps.html.twig (MessageExporter::corpsHtml), à partir du texte brut du parseur.',
        ],
        'admin/assistant_ia/export/message_word.html.twig' => [
            '/^corpsHtml\|raw$/' => 'Même fragment que l\'export PDF (MessageExporter::corpsHtml).',
        ],
        'components/_list_row.html.twig' => [
            '/^g\|raw$/' => "Le guillemet décoratif, un LITTÉRAL ('\"' ou '') posé juste au-dessus.",
        ],
        'components/_list_row_secondary_items.html.twig' => [
            '/textes_secondaires_separateurs/' => 'Le séparateur des textes secondaires, un littéral des canevas de liste (« • »).',
        ],
        'components/_onboarding_rappel.html.twig' => [
            '/^_params\|raw$/' => 'Bloc {% set %} littéral ; sa seule partie dynamique passe par |e(\'html_attr\').',
            '/^_ouvrir\|raw$/' => 'Bloc {% set %} littéral d\'attributs.',
        ],
        'components/_workspace_params_toast.html.twig' => [
            '/^message\|raw$/' => 'Traduction balisée ; l\'appelant qui y place un nom l\'échappe (admin/entreprise/edit : entreprise.nom|e).',
        ],
        'emails/_macros.html.twig' => [
            '/^html\|raw$/' => 'paragraph()/note() : le balisage vient des appelants, qui échappent chaque donnée (EmailEchappementTest).',
        ],
        'emails/conge_notification.html.twig' => [
            '/^valeur\|raw$/' => 'ligne() : « &nbsp;j » et le report en <span> ; les données sont échappées à l\'appel (EmailEchappementTest).',
            '/^lignes\|raw$/' => 'tableau() : des lignes déjà rendues par ligne().',
        ],
        'segments/pricing_plan.html.twig' => [
            '/allowance.*hours|hours.*allowance/s' => 'Traduction balisée dont les paramètres sont des NOMBRES formatés (PlanTarifaireExtension).',
        ],
    ];

    /** @return list<array{fichier: string, expression: string}> */
    private function sitesRaw(): array
    {
        $racine = dirname(__DIR__, 2) . '/templates';
        $sites = [];
        foreach ((new Finder())->files()->in($racine)->name('*.twig') as $fichier) {
            $source = (string) preg_replace('/\{#.*?#\}/s', '', $fichier->getContents());
            preg_match_all('/\{\{(.*?)\}\}/s', $source, $blocs);
            foreach ($blocs[1] as $bloc) {
                if (preg_match('/\|\s*raw\b/', $bloc)) {
                    $sites[] = [
                        'fichier' => str_replace('\\', '/', $fichier->getRelativePathname()),
                        'expression' => trim((string) preg_replace('/\s+/', ' ', $bloc)),
                    ];
                }
            }
        }

        return $sites;
    }

    private function traductionSure(string $expression): bool
    {
        // 'cle'|trans|raw — sans paramètre.
        if (preg_match('/^([\'"])[^\'"]+\1\s*\|\s*trans\s*\|\s*raw$/', $expression)) {
            return true;
        }
        // 'cle'|trans({...})|raw — chaque valeur passe par |e.
        if (preg_match('/^([\'"])[^\'"]+\1\s*\|\s*trans\s*\(\s*\{(.*)\}\s*\)\s*\|\s*raw$/s', $expression, $m)) {
            preg_match_all('/:\s*([^,]+?)\s*(?:,|$)/', trim($m[2]), $valeurs);

            return $valeurs[1] !== [] && array_reduce($valeurs[1], fn (bool $ok, string $v) => $ok && (bool) preg_match('/\|\s*e\b/', $v), true);
        }

        return false;
    }

    public function testChaqueRawEstMotiveOuEchappe(): void
    {
        $refuses = [];
        foreach ($this->sitesRaw() as $site) {
            if ($this->traductionSure($site['expression'])) {
                continue;
            }
            $admis = false;
            foreach (self::ADMIS[$site['fichier']] ?? [] as $motif => $_) {
                if (preg_match($motif, $site['expression'])) {
                    $admis = true;
                    break;
                }
            }
            if (!$admis) {
                $refuses[] = $site['fichier'] . ' : {{ ' . $site['expression'] . ' }}';
            }
        }

        self::assertSame([], $refuses, "Ces |raw ne sont ni échappés ni motivés :\n" . implode("\n", $refuses));
    }

    public function testAucuneEntreeAdmiseNeDecritUnSiteDisparu(): void
    {
        $sites = $this->sitesRaw();
        $mortes = [];
        foreach (self::ADMIS as $fichier => $motifs) {
            foreach (array_keys($motifs) as $motif) {
                $vivant = array_filter($sites, fn (array $s) => $s['fichier'] === $fichier && preg_match($motif, $s['expression']));
                if ($vivant === []) {
                    $mortes[] = $fichier . ' ' . $motif;
                }
            }
        }

        self::assertSame([], $mortes, 'Entrées admises qui ne décrivent plus aucun |raw : à retirer.');
    }
}
