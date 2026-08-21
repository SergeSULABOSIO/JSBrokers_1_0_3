<?php

namespace App\Tests\Workspace;

use PHPUnit\Framework\TestCase;

/**
 * LE REPLI DE LA COLONNE 2 — le contrat entre le gabarit et son contrôleur.
 *
 * Repliée, la colonne des rubriques quitte le flux et devient un panneau flottant : le
 * survol d'un groupe y montre sa description, le clic y épingle ses rubriques. Rien de
 * tout cela n'est vérifiable par un test fonctionnel — c'est de la géométrie et du
 * survol. Ce qui l'est, et qui casse en silence, c'est le CÂBLAGE :
 *
 *  - une méthode citée dans un `data-action` mais absente du contrôleur ne produit
 *    aucune erreur : le clic ne fait simplement rien (même famille de défaut que
 *    ContratDesEntreesDeMenuTest, côté canevas) ;
 *  - le script inline de restauration lit DEUX préférences, et l'ordre des lectures est
 *    toute la subtilité : les `return` anticipés ne gouvernent que le plein écran. Placer
 *    la lecture du repli après eux la rendrait muette pour quiconque n'est pas en plein
 *    écran — sans erreur, sans trace, juste un F5 qui « oublie » le choix ;
 *  - les deux `<a>` de la colonne 1 s'activent NATIVEMENT à Entrée. Leur ajouter un
 *    filtre `keydown.enter` provoquerait une double activation.
 *
 * On lit les fichiers, pas une page rendue : ce contrat ne dépend d'aucune donnée.
 */
class ColonneDeuxRepliableTest extends TestCase
{
    private const GABARIT = __DIR__ . '/../../templates/components/_interactive_menu.html.twig';
    private const CONTROLEUR = __DIR__ . '/../../assets/controllers/workspace-manager_controller.js';
    private const LOGIQUE_PURE = __DIR__ . '/../../assets/controllers/workspace-col2.js';
    private const CSS = __DIR__ . '/../../assets/styles/interactive-menu.css';
    private const CHAT = __DIR__ . '/../../templates/components/_assistant_ia_chat.html.twig';

    private function lire(string $chemin): string
    {
        self::assertFileExists($chemin);

        return (string) file_get_contents($chemin);
    }

    /**
     * Le cœur du dispositif anti-clignotement : la classe de repli doit être posée
     * pendant l'analyse du document, et donc avant TOUT `return` du script en ligne.
     *
     * Le garde-fou visait à l'origine un `return` nommément lié au plein écran. Le
     * viser par son libellé rendait le test complice d'une forme : la sortie a changé
     * de motif — elle ne dépend plus du plein écran mais de la présence d'un panneau
     * de colonne 4 — et le test s'est cassé alors que le contrat, lui, tenait toujours.
     * On vérifie donc ce qui compte réellement : qu'AUCUNE sortie anticipée, quelle
     * qu'elle soit, ne précède la lecture de la préférence de repli.
     */
    public function testLaPreferenceDeRepliEstLuePendantLAnalyseDuDocument(): void
    {
        $gabarit = $this->lire(self::GABARIT);

        $repli = strpos($gabarit, 'menuCol2Collapsed_');
        self::assertNotFalse($repli, 'Le script inline ne lit plus la préférence de repli de la colonne 2.');

        // On ne regarde que le CODE du script, pas les commentaires qui le précèdent :
        // le mot « return » y apparaît pour l'expliquer.
        $debutScript = strpos($gabarit, '(function (racine) {');
        self::assertNotFalse($debutScript, 'Le script en ligne de restauration a disparu.');

        if (preg_match('/\breturn\b/', $gabarit, $m, PREG_OFFSET_CAPTURE, $debutScript)) {
            self::assertLessThan(
                $m[0][1],
                $repli,
                "La préférence de repli doit être lue AVANT toute sortie anticipée du script "
                . "en ligne : sinon elle n'est restaurée que pour une partie des utilisateurs."
            );
        }

        self::assertStringContainsString(
            "racine.classList.add('col2-collapsed')",
            $gabarit,
            'La classe de repli doit être posée par le script inline, pas seulement par Stimulus.'
        );
    }

    /** La poignée est le seul moyen de récupérer la colonne : elle doit être complète. */
    public function testLaPoigneeEstBrancheeEtAnnoncee(): void
    {
        $gabarit = $this->lire(self::GABARIT);

        self::assertStringContainsString('ws-col2-handle', $gabarit);
        self::assertStringContainsString('click->workspace-manager#toggleCol2', $gabarit);
        self::assertStringContainsString('data-workspace-manager-target="col2Handle"', $gabarit);
        self::assertStringContainsString('aria-controls="ws-col2"', $gabarit);
        self::assertMatchesRegularExpression(
            '/class="[^"]*ws-col2-handle[^"]*"[^>]*aria-expanded=/s',
            $gabarit,
            "La poignée doit annoncer l'état de la colonne (aria-expanded)."
        );

        self::assertStringContainsString(
            'id="ws-col2"',
            $gabarit,
            "La colonne 2 doit porter l'id visé par les `aria-controls`."
        );
    }

    /**
     * La poignée de la colonne 4 est un RACCOURCI de plein écran, pas un
     * remplacement : la bascule doit rester offerte par le menu ⋮ du chat et par la
     * barre d'outils d'une fiche, et les trois commandes doivent partager le même
     * état — d'où la classe `vis-fullscreen`, sur laquelle `_syncFullscreenButtons`
     * s'appuie pour toutes les synchroniser d'un coup.
     */
    public function testLaPoigneeDeLaColonne4EstUnRaccourciSynchronise(): void
    {
        $gabarit = $this->lire(self::GABARIT);
        $chat = $this->lire(self::CHAT);
        $controleur = $this->lire(self::CONTROLEUR);

        self::assertMatchesRegularExpression(
            '/class="[^"]*ws-col4-handle[^"]*vis-fullscreen[^"]*"/s',
            $gabarit,
            'La poignée de la colonne 4 doit porter `vis-fullscreen` pour être synchronisée.'
        );
        self::assertMatchesRegularExpression(
            '/class="[^"]*ws-col4-handle[^"]*"[^>]*click->workspace-manager#toggleChatFullscreen/s',
            $gabarit,
            'La poignée doit appeler la bascule EXISTANTE, sans logique parallèle.'
        );

        // Non-régression : les deux commandes historiques survivent.
        self::assertStringContainsString(
            'class="icon-button vis-fullscreen"',
            $gabarit,
            "Le bouton plein écran de la barre d'outils d'une fiche doit rester en place."
        );
        self::assertStringContainsString(
            'aic-fullscreen',
            $chat,
            "L'entrée « plein écran » du menu ⋮ du chat doit rester en place."
        );

        // Le synchroniseur doit continuer de balayer les deux familles de boutons.
        self::assertStringContainsString(
            "querySelectorAll('.aic-fullscreen, .vis-fullscreen')",
            $controleur,
            'Toutes les commandes de plein écran doivent rester synchronisées ensemble.'
        );
    }

    /**
     * Les deux poignées portent un SVG INLINE, et non `twig:UX:Icon`.
     *
     * Ce n'est pas un détail de style : le jeu lucide n'est vendu localement qu'à
     * neuf icônes, et aucun chevron n'en fait partie. Une `twig:UX:Icon` irait donc
     * chercher le dessin chez Iconify à la volée — la poignée deviendrait invisible
     * hors ligne, c'est-à-dire exactement au moment où l'utilisateur ne pourrait plus
     * rétablir sa colonne.
     */
    public function testLesPoigneesNeDependentPasDuReseauPourLeurDessin(): void
    {
        $gabarit = $this->lire(self::GABARIT);

        preg_match_all('/<button[^>]*ws-edge-handle.*?<\/button>/s', $gabarit, $poignees);
        self::assertCount(2, $poignees[0], 'Il doit y avoir exactement deux poignées de bord.');

        foreach ($poignees[0] as $poignee) {
            self::assertStringContainsString('<svg', $poignee, 'Chaque poignée doit porter un SVG inline.');
            self::assertStringNotContainsString(
                'twig:UX:Icon',
                $poignee,
                "Aucun chevron n'est vendu localement : une UX:Icon dépendrait du réseau."
            );
        }
    }

    /**
     * Les `.nav-item` et `.rubrique-item` sont des `div role="button"` : ils ne
     * s'activent PAS au clavier sans filtre explicite. Sans eux, le panneau flottant
     * est inatteignable autrement qu'à la souris (WCAG 2.1.1).
     */
    public function testLaNavigationEstActivableAuClavier(): void
    {
        $gabarit = $this->lire(self::GABARIT);

        self::assertStringContainsString('keydown.enter->workspace-manager#showGroupRubriques', $gabarit);
        self::assertStringContainsString('keydown.space->workspace-manager#showGroupRubriques', $gabarit);
        self::assertStringContainsString('keydown.enter->workspace-manager#loadComponent', $gabarit);
        self::assertStringContainsString('keydown.space->workspace-manager#loadComponent', $gabarit);
        self::assertStringContainsString('keydown.esc->workspace-manager#fermerFlyout', $gabarit);
    }

    /**
     * Non-régression : « Paramètres » et « Fermer » sont de vrais `<a>`. Un filtre
     * clavier y déclencherait l'action DEUX fois (activation native + filtre).
     */
    public function testLesLiensNePortentPasDeFiltreClavier(): void
    {
        $gabarit = $this->lire(self::GABARIT);

        preg_match_all('/<a\b[^>]*>/s', $gabarit, $liens);
        self::assertNotEmpty($liens[0], 'Aucun lien lu : la forme du gabarit a changé.');

        foreach ($liens[0] as $lien) {
            self::assertStringNotContainsString(
                'keydown.enter',
                $lien,
                "Un `<a>` s'active nativement à Entrée : un filtre y provoquerait une double activation."
            );
        }
    }

    /**
     * Toute méthode citée dans un `data-action` du gabarit doit exister dans le
     * contrôleur. Un câblage orphelin est parfaitement silencieux.
     */
    public function testChaqueActionDuGabaritExisteDansLeControleur(): void
    {
        $gabarit = $this->lire(self::GABARIT);
        $controleur = $this->lire(self::CONTROLEUR);

        preg_match_all('/workspace-manager#([A-Za-z_][A-Za-z0-9_]*)/', $gabarit, $appels);
        $methodes = array_unique($appels[1]);
        self::assertNotEmpty($methodes, 'Aucune action lue : la forme des `data-action` a changé.');

        $orphelines = [];
        foreach ($methodes as $methode) {
            if (!preg_match('/^\s*(?:async\s+)?' . preg_quote($methode, '/') . '\s*\(/m', $controleur)) {
                $orphelines[] = $methode;
            }
        }

        self::assertSame(
            [],
            $orphelines,
            "Actions câblées dans le gabarit mais absentes du contrôleur : " . implode(', ', $orphelines)
        );
    }

    /**
     * La géométrie du panneau repose sur trois choix indissociables. Les perdre ne
     * casse aucun test fonctionnel, mais rend la fonctionnalité inutilisable :
     * `display:none` rendrait la hauteur immesurable (donc l'ancrage faux), et sans
     * z-index le panneau passerait SOUS le contenu de la colonne 4.
     */
    public function testLeStyleDuPanneauFlottantResteAncrable(): void
    {
        $css = $this->lire(self::CSS);

        self::assertMatchesRegularExpression(
            '/\.col2-collapsed \.menu-col-2\s*\{[^}]*position:\s*fixed/s',
            $css,
            'Repliée, la colonne doit quitter le flux pour que la colonne 3 récupère la place.'
        );
        self::assertMatchesRegularExpression(
            '/\.col2-collapsed \.menu-col-2\s*\{[^}]*visibility:\s*hidden/s',
            $css,
            "L'état fermé doit rester `visibility:hidden` : `display:none` mettrait offsetHeight à 0."
        );
        self::assertMatchesRegularExpression(
            '/\.col2-collapsed \.menu-col-2\s*\{[^}]*z-index:\s*var\(--ws-flyout-z\)/s',
            $css,
            'Sans z-index explicite, le panneau passe sous le contenu absolu de la colonne 4.'
        );
    }

    /**
     * NON-RÉGRESSION — la rubrique ouverte reste signalée dans le menu.
     *
     * Le repérage visuel repose sur trois indices cumulés, dont deux ne sont PAS
     * colorimétriques (graisse et accent latéral) : c'est ce qui le rend lisible en
     * vision déficiente. Les perdre ne casserait aucun parcours, mais l'utilisateur
     * ne saurait plus, depuis le menu, quelle rubrique il a sous les yeux.
     */
    public function testLaRubriqueOuverteResteSignaleeDansLeMenu(): void
    {
        $css = $this->lire(self::CSS);

        self::assertMatchesRegularExpression(
            '/\.rubrique-item\.active\s*\{[^}]*font-weight:\s*700/s',
            $css,
            'La rubrique active doit rester en gras.'
        );
        self::assertMatchesRegularExpression(
            '/\.rubrique-item\.active\s*\{[^}]*background-color:\s*var\(--bg-cobalt-subtle\)/s',
            $css,
            'La rubrique active doit garder son fond cobalt léger.'
        );
        self::assertMatchesRegularExpression(
            '/\.rubrique-item\.active\s*\{[^}]*color:\s*var\(--cobalt\)/s',
            $css,
            'La rubrique active doit garder son texte cobalt.'
        );
        self::assertMatchesRegularExpression(
            '/\.rubrique-item\.active::before\s*\{[^}]*background-color:\s*var\(--cobalt\)/s',
            $css,
            "L'accent latéral cobalt est l'indice NON colorimétrique de la sélection."
        );

        // Le repli ne doit pas neutraliser ces règles dans le panneau flottant.
        self::assertStringNotContainsString(
            '.col2-collapsed .rubrique-item',
            $css,
            'Le mode replié ne doit pas redéfinir le style des rubriques : la mise en valeur '
            . 'de la rubrique active doit être strictement la même dans les deux modes.'
        );
    }

    /**
     * NON-RÉGRESSION — passer d'un onglet du workspace à l'autre (Propositions →
     * Avenants → Clients) doit reposer la marque sur la bonne rubrique.
     *
     * Le mécanisme est fragile et mérite d'être verrouillé : `showGroupRubriques`
     * RÉINJECTE le HTML des rubriques, ce qui efface la classe `active`. Elle n'est
     * reposée qu'ensuite, dans le requestAnimationFrame qui suit. Retirer ce rappel
     * laisserait le menu muet sur la rubrique ouverte.
     */
    public function testLeChangementDOngletRepositionneLaRubriqueActive(): void
    {
        $controleur = $this->lire(self::CONTROLEUR);

        self::assertTrue(
            (bool) preg_match(
                '/_syncMenuWithTab\(tabData\)\s*\{.*?requestAnimationFrame\(.*?rubriquesContainerTarget\.querySelector.*?updateActiveState\(rubriqueEl\)/s',
                $controleur
            ),
            'Après réinjection des rubriques, _syncMenuWithTab doit reposer `active` sur la '
            . "rubrique de l'onglet activé."
        );

        // La branche colonne 2 de updateActiveState est ce qui POSE la classe.
        self::assertTrue(
            (bool) preg_match(
                "/closest\('\.menu-col-2'\).*?classList\.add\('active'\)/s",
                $controleur
            ),
            "updateActiveState doit continuer de poser `active` sur la rubrique cliquée."
        );

        // Toute réinjection de la liste doit reposer la marque : le HTML vient d'un
        // `<template>` inerte, il arrive vierge de toute classe `active`.
        self::assertTrue(
            (bool) preg_match(
                '/rubriquesContainerTarget\.innerHTML = templateContent\.outerHTML;\s*\n\s*this\._marquerRubriqueOuverte\(groupName\);/s',
                $controleur
            ),
            'displayRubriquesForGroup doit reposer la marque juste après avoir réinjecté la liste.'
        );

        // …en lisant l'ONGLET ACTIF, seule source de vérité vivante.
        self::assertStringContainsString(
            'this.workspaceTabs.find((t) => t.id === this.activeWorkspaceTabId)',
            $controleur,
            "La rubrique ouverte se lit sur l'onglet actif — `activeRubriqueState` est mort "
            . '(écrit uniquement par restoreLastState, qui n\'est plus appelée).'
        );
    }

    /**
     * NON-RÉGRESSION — le rappel de la rubrique ouverte ne doit pas retomber sur
     * `activeRubriqueState`.
     *
     * Ce champ n'est écrit que par `restoreLastState()`, méthode morte depuis le
     * passage aux onglets : il vaut TOUJOURS null. S'en servir revient à ne rien
     * marquer du tout — la panne était invisible en relecture, et c'est exactement
     * ce qui faisait disparaître la sélection dès qu'on rouvrait la liste d'un groupe.
     */
    public function testLeRappelNeRetombePasSurLEtatMort(): void
    {
        $controleur = $this->lire(self::CONTROLEUR);

        self::assertTrue(
            (bool) preg_match('/_marquerRubriqueOuverte\(groupName\)\s*\{.*?\n    \}/s', $controleur, $corps),
            'La méthode de marquage doit rester lisible d\'un bloc.'
        );
        self::assertStringNotContainsString(
            'activeRubriqueState',
            $corps[0],
            'Le marquage ne doit pas dépendre de `activeRubriqueState`, qui vaut toujours null.'
        );

        // Le garde-fou du repli ne doit JAMAIS s'appliquer colonne dépliée : sinon il
        // toucherait un parcours qui n'a rien demandé.
        self::assertStringContainsString(
            'if (this._replie() && estOuvert(this.etatFlyout)',
            $controleur,
            'Le rangement du panneau doit rester conditionné au mode replié.'
        );
    }

    /**
     * La logique pure ne doit pas se mettre à toucher au navigateur : c'est ce qui la
     * garde testable sous Node, sans DOM ni bundler.
     */
    public function testLaLogiquePureLeReste(): void
    {
        // On dépouille les commentaires : ils NOMMENT ces objets pour dire d'où
        // viennent les nombres qu'on reçoit (« hauteurViewport = window.innerHeight »),
        // ce qui est exactement la documentation qu'on veut garder.
        $code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $this->lire(self::LOGIQUE_PURE));
        self::assertNotNull($code, 'Le dépouillement des commentaires a échoué.');

        foreach (['document.', 'window.', 'localStorage', 'fetch('] as $interdit) {
            self::assertStringNotContainsString(
                $interdit,
                $code,
                "workspace-col2.js doit rester pur (aucun accès à « {$interdit} ») pour tourner sous Node."
            );
        }
    }
}
