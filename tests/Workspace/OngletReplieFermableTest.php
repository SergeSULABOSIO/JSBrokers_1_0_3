<?php

namespace App\Tests\Workspace;

use PHPUnit\Framework\TestCase;

/**
 * UN ONGLET REPLIÉ DOIT POUVOIR ÊTRE FERMÉ.
 *
 * Le panneau « + N » listait les onglets qui ne tiennent plus dans la barre, et permettait
 * d'y aller — mais pas de les fermer. Le seul moyen d'en refermer un était donc de le
 * ramener d'abord dans la barre : élargir la fenêtre, ou fermer un autre onglet. Un
 * utilisateur qui en a douze n'a aucune raison de deviner cela.
 *
 * ── POURQUOI CE TEST LIT DES FICHIERS ───────────────────────────────────────────────
 * Le geste vit dans le DOM, et la suite JavaScript du projet n'a pas de DOM (aucun jsdom
 * en dépendance, délibérément). On vérifie donc le CÂBLAGE : que le socle sache poser une
 * croix, qu'il ne la pose que si on lui donne de quoi fermer, et que la barre du workspace
 * lui donne effectivement ce geste. C'est précisément ce qui manquait — pas un détail de
 * rendu.
 */
class OngletReplieFermableTest extends TestCase
{
    private const SOCLE = __DIR__ . '/../../assets/controllers/onglets-debordement.js';
    private const WORKSPACE = __DIR__ . '/../../assets/controllers/workspace-manager_controller.js';
    private const CSS = __DIR__ . '/../../assets/styles/app.css';

    private function lire(string $chemin): string
    {
        self::assertFileExists($chemin);

        return (string) file_get_contents($chemin);
    }

    /** Le socle construit une rangée, et la croix y vit HORS du bouton d'activation. */
    public function testLeSoclePoseUneCroixHorsDuBoutonDActivation(): void
    {
        $socle = $this->lire(self::SOCLE);

        self::assertStringContainsString('list-tabs-overflow-row', $socle);
        self::assertStringContainsString('list-tabs-overflow-close', $socle);

        // Le rôle de liste appartient à la RANGÉE, plus au bouton : sinon l'entrée et sa
        // croix compteraient pour deux éléments de liste.
        self::assertStringContainsString("rangee.setAttribute('role', 'listitem')", $socle);

        // Un bouton dans un bouton n'est pas du HTML valide : la croix est ajoutée à la
        // rangée, jamais à l'entrée.
        self::assertStringContainsString('rangee.appendChild(this._croix(onglet))', $socle);
    }

    /**
     * La croix n'apparaît QUE si l'hôte fournit le geste de fermeture.
     *
     * Les deux barres du workspace partagent ce socle : celle des rubriques ouvertes et
     * celle des onglets d'une rubrique. Poser la croix d'office en aurait mis une là où
     * rien ne sait fermer, et le clic n'aurait rien fait.
     */
    public function testLaCroixEstConditionnelleAuGesteDeFermeture(): void
    {
        $socle = $this->lire(self::SOCLE);

        self::assertStringContainsString("typeof this.cfg.fermer === 'function'", $socle);
    }

    /** La barre du workspace, elle, fournit ce geste — et par un appel, pas un clic simulé. */
    public function testLaBarreDuWorkspaceFournitLaFermeture(): void
    {
        $workspace = $this->lire(self::WORKSPACE);

        self::assertStringContainsString('fermer: (onglet) => this._closeWorkspaceTabById(', $workspace);

        // La fermeture par identifiant est EXTRAITE, pas recopiée : la croix de la barre et
        // celle du panneau doivent emprunter le même chemin, sans quoi l'une des deux
        // oublierait un jour de réactiver l'onglet voisin ou de sauvegarder l'état.
        self::assertStringContainsString('_closeWorkspaceTabById(tabId)', $workspace);
        self::assertStringContainsString(
            'this._closeWorkspaceTabById(tabEl.dataset.tabId)',
            $workspace,
            'La croix de la barre doit passer par la même méthode.',
        );
    }

    /**
     * Après une fermeture, le panneau se remet à jour — ou se referme s'il ne reste rien.
     *
     * Une liste vide restée ouverte sous le curseur donnerait à croire que le geste n'a pas
     * abouti, et une liste périmée proposerait d'aller sur un onglet qui n'existe plus.
     */
    public function testLePanneauSeRemetAJourApresUneFermeture(): void
    {
        $socle = $this->lire(self::SOCLE);

        $position = strpos($socle, 'this.cfg.fermer(onglet)');
        self::assertNotFalse($position);

        $suite = substr($socle, $position, 400);
        self::assertStringContainsString('this.recalculer()', $suite);
        self::assertStringContainsString('this.fermer()', $suite);
        self::assertStringContainsString('this._peupler()', $suite);
    }

    /**
     * La croix ne se montre qu'au survol de sa rangée — mais reste atteignable au clavier.
     *
     * Visible en permanence, elle transformerait un panneau de navigation en liste
     * d'actions destructrices ; cachée au focus, elle serait inaccessible (WCAG 2.4.7).
     */
    public function testLaCroixEstDiscreteMaisAtteignableAuClavier(): void
    {
        $css = $this->lire(self::CSS);

        self::assertStringContainsString('.list-tabs-overflow-close {', $css);
        self::assertStringContainsString(
            ".list-tabs-overflow-row:hover .list-tabs-overflow-close,\n.list-tabs-overflow-close:focus-visible {",
            str_replace("\r\n", "\n", $css),
            'Le survol ET le focus doivent révéler la croix.',
        );
    }
}
