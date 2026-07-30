<?php

namespace App\Tests\Twig;

use App\Services\Canvas\Provider\Icon\IconCanvasProvider;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou de production : TOUT alias de IconCanvasProvider doit pointer vers
 * un SVG réellement vendorisé dans assets/icons/{set}/{nom}.svg.
 *
 * Pourquoi ce test existe : le projet n'a pas de config/packages/ux_icons.yaml,
 * donc ux_icon() sur une icône absente du disque déclenche un téléchargement à
 * la demande via l'API Iconify AU MOMENT DU RENDU — lent en dev et 500 en prod
 * sans réseau sortant (cf. correctif du commit 456208af sur le partial du chat).
 * Un alias ajouté sans `php bin/console ux:icons:import` est donc une panne
 * différée : ce test la transforme en échec immédiat.
 */
class IconAliasVendorisationTest extends TestCase
{
    /** Racine des icônes vendorisées (défaut du bundle ux-icons). */
    private const ICON_DIR = __DIR__ . '/../../assets/icons';

    /**
     * @return array<string, string> alias => nom d'icône résolu
     */
    private function aliasMap(): array
    {
        $constante = (new \ReflectionClass(IconCanvasProvider::class))->getReflectionConstant('ICON_ALIAS_MAP');
        self::assertNotFalse($constante, 'La constante ICON_ALIAS_MAP a disparu de IconCanvasProvider.');

        return $constante->getValue();
    }

    /**
     * Chemin disque attendu pour un nom d'icône « set:nom ». Les noms de
     * l'écosystème Iconify n'ont qu'un seul « : » et jamais de séparateur de
     * dossier — tout autre forme est un alias mal saisi.
     */
    private function cheminAttendu(string $nomIcone): ?string
    {
        if (!preg_match('/^([a-z0-9-]+):([a-z0-9-]+)$/', $nomIcone, $m)) {
            return null;
        }

        return self::ICON_DIR . '/' . $m[1] . '/' . $m[2] . '.svg';
    }

    public function testChaqueAliasEstVendorise(): void
    {
        $manquants = [];
        foreach ($this->aliasMap() as $alias => $nomIcone) {
            $chemin = $this->cheminAttendu($nomIcone);
            if ($chemin === null || !is_file($chemin)) {
                $manquants[] = sprintf('%s => %s', $alias, $nomIcone);
            }
        }

        self::assertSame([], $manquants, sprintf(
            "%d alias pointent vers un SVG absent de assets/icons (appel Iconify au rendu → 500 en prod) :\n  %s\n"
            . "Corriger avec : php bin/console ux:icons:import <nom> …",
            count($manquants),
            implode("\n  ", $manquants)
        ));
    }

    /**
     * Les icônes des actions du menu de bulle (chat Ket) : elles sont rendues
     * dans un partial injecté en onglet, où un appel réseau au rendu est
     * particulièrement pénalisant. Test explicite pour que leur suppression
     * accidentelle nomme la fonctionnalité cassée.
     */
    public function testAliasDuMenuDeBulleExistentEtSontVendorises(): void
    {
        $map = $this->aliasMap();
        foreach (['action:options', 'action:reply', 'action:pdf', 'action:word', 'action:markdown', 'action:image', 'action:send-email'] as $alias) {
            self::assertArrayHasKey($alias, $map, sprintf('Alias « %s » attendu par le menu de bulle du chat Ket.', $alias));
            $chemin = $this->cheminAttendu($map[$alias]);
            self::assertNotNull($chemin, sprintf('Nom d\'icône mal formé pour « %s » : %s', $alias, $map[$alias]));
            self::assertFileExists($chemin, sprintf('Icône « %s » (%s) non vendorisée.', $alias, $map[$alias]));
        }
    }
}
