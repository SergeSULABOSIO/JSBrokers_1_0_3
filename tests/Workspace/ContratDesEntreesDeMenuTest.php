<?php

namespace App\Tests\Workspace;

use PHPUnit\Framework\TestCase;

/**
 * LE CONTRAT ENTRE LES CANEVAS ET LE CERVEAU — une action déclarée doit être branchée.
 *
 * Les entrées spécifiques de la barre d'outils et du menu contextuel sont déclarées côté
 * serveur (`parametres.attribute_actions`) et acheminées vers le navigateur, qui les
 * route par un `switch` sur leur `event`. Rien ne garantissait que les deux bouts se
 * parlent : une action déclarée avec un `event` sans `case` correspondant s'affiche
 * normalement, se laisse cliquer… et ne produit RIEN. Aucune erreur, aucune trace —
 * l'utilisateur clique deux fois, puis renonce.
 *
 * C'est exactement la famille de défaut que ContratDesActionsTest traque du côté de
 * l'assistant, et le trou était resté ouvert de ce côté-ci. Il s'est refermé le jour où
 * l'on a injecté « Attacher des pièces » et « Voir les documents » pour toutes les
 * rubriques : deux actions transverses de plus, donc deux occasions de plus de se
 * tromper, sur trente-six rubriques à la fois.
 *
 * ⚠️ ON LIT LES PROVIDERS, PAS UN CATALOGUE. Les actions vivent dans les
 * FormCanvasProviders et dans l'injection centrale ; il n'existe aucune liste unique à
 * confronter. On extrait donc les `event` du CODE lui-même — c'est plus grossier qu'une
 * énumération, mais c'est la seule lecture qui ne puisse pas mentir.
 */
class ContratDesEntreesDeMenuTest extends TestCase
{
    private const CERVEAU = __DIR__ . '/../../assets/controllers/cerveau_controller.js';
    private const PROVIDERS = __DIR__ . '/../../src/Services/Canvas/Provider/Form';
    private const INJECTION_CENTRALE = __DIR__ . '/../../src/Services/Canvas/FormCanvasProvider.php';

    /**
     * Tout `event` déclaré dans une action de canevas doit avoir son `case` dans le
     * cerveau — sinon le clic est un trou noir.
     */
    public function testChaqueActionDeclareeEstBrancheeDansLeCerveau(): void
    {
        $traites = $this->eventsTraitesParLeCerveau();
        self::assertNotSame([], $traites, 'Aucun `case` lu : la lecture du cerveau a changé de forme.');

        $orphelines = [];
        foreach ($this->eventsDeclaresParLesCanevas() as $event => $fichiers) {
            if (!in_array($event, $traites, true)) {
                $orphelines[] = sprintf('%s (déclaré dans %s)', $event, implode(', ', $fichiers));
            }
        }

        self::assertSame([], $orphelines, "Ces actions ne produiraient RIEN au clic :\n" . implode("\n", $orphelines));
    }

    /**
     * Les `event` traités par le cerveau, lus dans son `switch`.
     *
     * @return list<string>
     */
    private function eventsTraitesParLeCerveau(): array
    {
        $module = (string) file_get_contents(self::CERVEAU);
        preg_match_all("/case\s+'([a-z0-9:._-]+)'\s*:/i", $module, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * Les `event` déclarés par les canevas — providers d'entité ET injection centrale.
     *
     * @return array<string, list<string>> event => fichiers qui le déclarent
     */
    private function eventsDeclaresParLesCanevas(): array
    {
        $declares = [];
        $fichiers = array_merge(glob(self::PROVIDERS . '/*.php') ?: [], [self::INJECTION_CENTRALE]);

        foreach ($fichiers as $chemin) {
            $source = (string) file_get_contents($chemin);
            // On ne retient que les `event` d'actions : la clé est écrite « 'event' => »
            // ou « "event" => » dans les tableaux d'attribute_actions.
            preg_match_all('/["\']event["\']\s*=>\s*["\']([a-z0-9:._-]+)["\']/i', $source, $m);
            foreach ($m[1] ?? [] as $event) {
                $declares[$event][] = basename($chemin);
            }
        }

        foreach ($declares as $event => $liste) {
            $declares[$event] = array_values(array_unique($liste));
        }

        return $declares;
    }

}
