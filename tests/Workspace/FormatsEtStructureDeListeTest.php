<?php

namespace App\Tests\Workspace;

use App\Services\ServiceNombres;
use PHPUnit\Framework\TestCase;

/**
 * CE QUI DOIT RESTER VRAI DANS LES SOURCES DU RENDU DE LISTE.
 *
 * Le projet n'a pas de jsdom et ne rend pas le CSS en test : ce qui se vérifie sans
 * navigateur se vérifie ici, par lecture de source. Chaque assertion garde une décision
 * du lot « rendu des collections » — et surtout les deux qu'un nettoyage trop large
 * défferait sans s'en apercevoir.
 */
class FormatsEtStructureDeListeTest extends TestCase
{
    private const LIST_ROW = __DIR__ . '/../../templates/components/_list_row.html.twig';
    private const LIST_ROW_META = __DIR__ . '/../../templates/components/_list_row_secondary_items.html.twig';
    private const LIST_MANAGER = __DIR__ . '/../../templates/components/_list_manager.html.twig';
    // Le balisage du widget vit dans _collection_manager (le bloc de thème l'inclut, et
    // un onglet sans champ de formulaire aussi).
    private const WIDGET = __DIR__ . '/../../templates/themes/_collection_manager.html.twig';
    private const JS_COLLECTION = __DIR__ . '/../../assets/controllers/collection_controller.js';
    private const CSS = __DIR__ . '/../../assets/styles/app.css';
    private const TRAIT_CONTROLEUR = __DIR__ . '/../../src/Controller/Admin/ControllerUtilsTrait.php';
    private const ICON_EXTENSION = __DIR__ . '/../../src/Twig/Extension/IconExtension.php';

    private function lire(string $chemin): string
    {
        self::assertFileExists($chemin);

        return str_replace("\r\n", "\n", (string) file_get_contents($chemin));
    }

    /**
     * UN SEUL FORMATAGE DE NOMBRE, CELUI DU PROJET.
     *
     * Les gabarits de liste écrivaient `number_format(2, ',', ' ')` : un format FRANÇAIS
     * EN DUR, là où `ServiceNombres` est localisé. Trois écritures du même besoin vivaient
     * côte à côte, dont une côté navigateur.
     */
    public function testLesGabaritsDeListeNeFormatentPlusLesNombresAlaMain(): void
    {
        foreach ([self::LIST_ROW, self::LIST_ROW_META, self::LIST_MANAGER] as $gabarit) {
            $src = $this->lire($gabarit);
            self::assertStringNotContainsString(
                'number_format(',
                $src,
                sprintf('%s formate encore un nombre a la main.', basename($gabarit)),
            );
            self::assertStringContainsString('format_nombre(2)', $src, basename($gabarit));
        }
    }

    /**
     * LA CONSÉQUENCE DE CE CHOIX, FIGÉE ICI.
     *
     * En français, le service rend exactement ce que rendait le code en dur — aucune
     * différence. C'est en anglais que le changement se voit, et c'est précisément ce
     * qu'on voulait : une rubrique ne doit pas parler français à un utilisateur
     * anglophone, à côté d'une barre de totaux qui lui parle anglais.
     */
    public function testLeFormatageSuitLaLangue(): void
    {
        $service = new ServiceNombres(new \Symfony\Component\Translation\LocaleSwitcher('fr', []));

        self::assertSame('1 160,00', $service->format(1160, 2, 'fr'), 'En francais, rien ne change.');
        self::assertSame('1,160.00', $service->format(1160, 2, 'en'), 'En anglais, le nombre suit la langue.');
    }

    /** Un seul format de date dans les composants de liste. */
    public function testUnSeulFormatDeDate(): void
    {
        foreach ([self::LIST_ROW, self::LIST_ROW_META, self::LIST_MANAGER] as $gabarit) {
            self::assertStringNotContainsString("date('d-m-Y')", $this->lire($gabarit), basename($gabarit));
        }
    }

    /**
     * LE TOTAL A QUITTÉ L'ENTÊTE — des deux côtés.
     *
     * Retirer la cible sans retirer la méthode laisserait un appel mort ; l'inverse ferait
     * une erreur au premier rafraîchissement. Les quatre occurrences partent ensemble.
     */
    public function testLeTotalNeVitPlusDansLEnteteDuWidget(): void
    {
        $widget = $this->lire(self::WIDGET);
        self::assertStringNotContainsString('collection-total-value', $widget);
        self::assertStringNotContainsString('class="badge', $widget, '`.badge` est forcee en bleu plein ici.');
        self::assertStringContainsString('jsb-onglet-compte', $widget, 'Le compteur reutilise la pastille existante.');

        $js = $this->lire(self::JS_COLLECTION);
        self::assertStringNotContainsString('totalValueDisplay', $js);
        self::assertStringNotContainsString('updateTotal', $js);
    }

    /** Le pied vit dans le gabarit, et ne recalcule rien. */
    public function testLePiedDeTotalNeRecalculeRien(): void
    {
        $manager = $this->lire(self::LIST_MANAGER);

        self::assertStringContainsString('<tfoot>', $manager);
        self::assertStringContainsString('th scope="row"', $manager);
        self::assertStringContainsString('totalValue', $manager, 'Le pied affiche la valeur recue, il ne la somme pas.');
        self::assertStringNotContainsString('|sum', $manager, 'Aucune addition dans la vue.');

        // UNE SEULE CONDITION, ET ELLE VIENT DU CONTROLEUR. Le pied exigeait autrefois que
        // le parent soit ecrit ; le navigateur rend desormais au serveur les valeurs de son
        // tampon, si bien que le total vaut dans les deux modes. La vue ne compte plus de
        // lignes et n'interroge plus l'etat du parent : elle lit `totalValue`.
        self::assertStringContainsString(
            'estCollection and totalValue is defined and totalValue is not null',
            $manager,
            "Le controleur decide s'il y a un total ; la vue ne le rededuit pas.",
        );
        self::assertStringNotContainsString(
            'not parentEnAttente',
            $manager,
            "Le pied ne depend plus de l'etat du parent.",
        );
    }

    /**
     * LES DEUX PIEDS QUE LE NAVIGATEUR CLONE AU LIEU DE LES ECRIRE.
     *
     * Pendant le calcul du total, le nombre affiche est perime : un squelette prend sa
     * place. Son balisage vient d'ICI — un pied fabrique en JS ressemblerait au vrai sans
     * jamais lui etre identique, et s'en ecarterait au premier changement de ce gabarit.
     *
     * Ils dependent de `estTotalisable` (la CONFIGURATION) et non de `totalValue` (l'etat
     * du moment) : au tout premier ajout dans une collection vide, aucun pied n'est encore
     * rendu, et c'est precisement la qu'un squelette doit pouvoir paraitre.
     */
    public function testLesGabaritsDePiedSontRendusParLeServeur(): void
    {
        $manager = $this->lire(self::LIST_MANAGER);

        self::assertStringContainsString(
            '{% set estTotalisable = totalizableField is defined and totalizableField is not null %}',
            $manager,
            'La question « cette collection a-t-elle un total ? » se pose une seule fois.',
        );
        self::assertStringContainsString(
            '{% if estCollection|default(false) and estTotalisable|default(false) %}',
            $manager,
            'Les gabarits suivent la configuration, jamais `totalValue`.',
        );
        self::assertStringContainsString('<template data-collection-target="squelettePied">', $manager);
        self::assertStringContainsString('<template data-collection-target="piedIndisponible">', $manager);
        self::assertStringContainsString('skeleton-line skeleton-total', $manager, 'Le squelette reutilise la base existante.');

        // MEME STRUCTURE QUE LE VRAI PIED : trois cellules. Sans cela le tableau changerait
        // de geometrie pendant l'attente — l'inverse de ce qu'un squelette doit faire.
        foreach (['squelettePied', 'piedIndisponible'] as $cible) {
            $bloc = $this->blocDuGabarit($manager, $cible);
            self::assertSame(1, substr_count($bloc, '<th scope="row"'), $cible . ' : un entete de rangee.');
            self::assertSame(2, substr_count($bloc, '<td'), $cible . ' : deux cellules, comme le vrai pied.');
        }

        $js = $this->lire(self::JS_COLLECTION);
        self::assertStringContainsString('squelettePied', $js, 'Le controleur cible le gabarit.');
        // Nommer `<tfoot>` dans un commentaire est permis — l'ECRIRE ne l'est pas. On
        // retire donc les blocs de documentation avant de chercher le balisage.
        self::assertStringNotContainsString(
            '<tfoot',
            $this->sansCommentaires($js),
            "Le navigateur clone un pied, il n'en ecrit aucun.",
        );
    }

    /**
     * LE MOUVEMENT REDUIT NEUTRALISE LE SELECTEUR QUI PORTE VRAIMENT L'ANIMATION.
     *
     * Ecrite sur `.skeleton-line`, la regle n'aurait rien neutralise : le shimmer vit sur le
     * PSEUDO-ELEMENT. On COMPARE donc les deux selecteurs au lieu d'en figer un — deplacer
     * l'animation un jour fera echouer ce test, plutot que desactiver la protection en
     * silence (WCAG 2.3.3).
     */
    public function testLeMouvementReduitViseLeSelecteurAnime(): void
    {
        $css = $this->sansCommentaires($this->lire(self::CSS));

        $anime = $this->selecteurDuBlocContenant($css, 'animation: shimmer-advanced');

        $calme = null;
        $depuis = 0;
        while (($pos = strpos($css, 'animation: none', $depuis)) !== false) {
            $selecteur = $this->selecteurDuBlocA($css, $pos);
            if (str_contains($selecteur, 'skeleton')) {
                self::assertStringContainsString(
                    'prefers-reduced-motion',
                    substr($css, max(0, $pos - 400), min(400, $pos)),
                    'La neutralisation doit etre conditionnee au mouvement reduit.',
                );
                $calme = $selecteur;
                break;
            }
            $depuis = $pos + 1;
        }

        self::assertNotNull($calme, "Aucune regle ne neutralise l'animation des squelettes.");
        self::assertSame(
            $anime,
            $calme,
            'Le mouvement reduit doit viser EXACTEMENT le selecteur qui porte le shimmer.',
        );
    }

    /** Le corps d'un `<template>`, pour compter ses cellules sans relire tout le gabarit. */
    private function blocDuGabarit(string $source, string $cible): string
    {
        $debut = strpos($source, '<template data-collection-target="' . $cible . '">');
        self::assertNotFalse($debut, 'Gabarit « ' . $cible . ' » introuvable.');
        $fin = strpos($source, '</template>', $debut);
        self::assertNotFalse($fin);

        return substr($source, $debut, $fin - $debut);
    }

    /** Les commentaires portent des accolades : les laisser fausserait toute lecture de bloc. */
    private function sansCommentaires(string $css): string
    {
        while (($debut = strpos($css, '/*')) !== false) {
            $fin = strpos($css, '*/', $debut);
            if ($fin === false) {
                break;
            }
            $css = substr($css, 0, $debut) . ' ' . substr($css, $fin + 2);
        }

        return $css;
    }

    /** Le sélecteur du bloc qui contient cette déclaration, sans espaces. */
    private function selecteurDuBlocContenant(string $css, string $declaration): string
    {
        $pos = strpos($css, $declaration);
        self::assertNotFalse($pos, 'Declaration introuvable : ' . $declaration);

        return $this->selecteurDuBlocA($css, $pos);
    }

    /** Le sélecteur du bloc dans lequel tombe cette position, sans espaces. */
    private function selecteurDuBlocA(string $css, int $pos): string
    {
        $ouvrante = strrpos(substr($css, 0, $pos), '{');
        self::assertNotFalse($ouvrante, 'Declaration hors de tout bloc.');

        $avant = substr($css, 0, $ouvrante);
        // La borne gauche est la fin de la regle precedente — ou l'ouverture du @media qui
        // nous englobe, selon ce qui vient en dernier.
        $borne = max((int) strrpos($avant, '}'), (int) strrpos($avant, '{'));

        return str_replace(
            [' ', chr(9), chr(10), chr(13)],
            '',
            substr($css, $borne + 1, $ouvrante - $borne - 1),
        );
    }

    /** Les largeurs sont portées par le colgroup, jamais par un `style=` en ligne. */
    public function testLesLargeursPassentParLeColgroup(): void
    {
        $manager = $this->lire(self::LIST_MANAGER);
        self::assertStringContainsString('<col class="jsb-col-valeur">', $manager);
        self::assertStringContainsString('<col class="jsb-col-actions">', $manager);

        $css = $this->lire(self::CSS);
        self::assertStringContainsString('.table-enhanced:has(> colgroup)', $css);
        self::assertStringContainsString('table-layout: fixed', $css);
        self::assertMatchesRegularExpression(
            '/--jsb-col-valeur:\s*\d+ch/',
            $css,
            'La colonne de valeur se mesure en `ch` : elle doit tenir le plus grand montant reel, '
            . 'qui n\'est pas un montant en dollars.',
        );
    }

    /**
     * LA LIGNE DU TAMPON PORTE LES MÊMES CELLULES QUE CELLES DU SERVEUR.
     *
     * ⚠ Garde de SOURCE, plus faible qu'un test fonctionnel : le projet n'a pas de harnais
     * pour poster un formulaire en `dry_run`, et en écrire un ici aurait coûté plus que ce
     * qu'il aurait prouvé. Ce test tient l'essentiel — que les deux rendus passent par LA
     * MÊME résolution — sans compter les cellules pour de vrai.
     */
    public function testLaLigneEnAttentePasseParLaMemeResolution(): void
    {
        $trait = $this->lire(self::TRAIT_CONTROLEUR);

        self::assertSame(
            2,
            substr_count($trait, '$this->resoudreColonneValeur('),
            'Les deux rendus de dialogue — le tableau et la ligne du tampon — doivent appeler '
            . 'la meme resolution, sinon leurs comptes de cellules divergent en silence.',
        );
    }

    /** Le critère « ce code désigne-t-il un état ? » n'est écrit qu'une fois. */
    public function testLeCritereDEtatNEstEcritQuUneFois(): void
    {
        $extension = $this->lire(self::ICON_EXTENSION);

        self::assertStringContainsString("new TwigTest('etat'", $extension);
        self::assertSame(
            1,
            substr_count($extension, "str_contains(\$c, 'statut')"),
            'Deux ecritures du meme critere finiraient par diverger : la pastille sans son icone.',
        );
        self::assertStringContainsString('$this->estEtat($c)', $extension, 'secondaryIcon delegue au critere unique.');
    }

    /**
     * LE CODE MORT EST PARTI — ET `.text-secondary` EST RESTÉ.
     *
     * Elle vivait au milieu du bloc supprimé mais redéfinit globalement l'utilitaire
     * Bootstrap : la supprimer avec son voisinage aurait été la plus large régression du lot.
     */
    public function testLeCodeMortEstPartiSansEmporterCeQuiVit(): void
    {
        $css = $this->lire(self::CSS);

        foreach (['.collection-item {', '.item-icon {', '.item-actions {', '.contextual-row-actions {'] as $mort) {
            self::assertStringNotContainsString($mort, $css, sprintf('%s aurait du partir.', $mort));
        }
        self::assertStringContainsString(".text-secondary {\n", $css, '`.text-secondary` est VIVANTE.');

        foreach ([
            __DIR__ . '/../../templates/components/_collection.html.twig',
            __DIR__ . '/../../templates/components/collection_items',
            __DIR__ . '/../../templates/components/collection',
        ] as $chemin) {
            self::assertFileDoesNotExist($chemin, sprintf('%s n\'est plus inclus nulle part.', basename($chemin)));
        }
    }

    /**
     * LE DÉBORD DU BANDEAU SUIT LA GOUTTIÈRE DU CADRE, PAS CELLE DE LA COLONNE.
     *
     * Il débordait de 1.5rem — la gouttière de `.form-column` — alors qu'il vit dans le
     * `<form>`, dont la gouttière est de 1.25rem : 3 px de trop, et la bordure du cadre
     * paraissait coupée sur toute la hauteur du bandeau.
     *
     * Les deux SOURCES restent distinctes, et c'est voulu : le décalage vertical se cale
     * sur la colonne qui défile, le débord horizontal sur le cadre qui entoure. Toutes
     * deux valent 0 depuis que le formulaire suit la grammaire du workspace (aucune
     * gouttière) — le mécanisme, lui, demeure.
     */
    public function testLeBandeauBordeALinterieurDuCadre(): void
    {
        $css = $this->lire(self::CSS);

        self::assertMatchesRegularExpression(
            '/\.form-column:has\(\.form-intro\) form \{[^}]*--gouttiere-cadre:\s*0rem/s',
            $css,
            'Le cadre doit declarer SA gouttiere (nulle, comme au workspace).',
        );
        self::assertMatchesRegularExpression(
            '/\.form-column \.jsb-onglets-barre \{[^}]*margin-inline:\s*calc\(-1 \* var\(--gouttiere-cadre/s',
            $css,
            'Le debord horizontal suit la gouttiere du cadre, et ne la recopie pas.',
        );
        self::assertMatchesRegularExpression(
            '/\.form-column \.jsb-onglets-barre \{[^}]*top:\s*0;/s',
            $css,
            'Le decalage VERTICAL, lui, reste cale sur la colonne qui defile (sans gouttiere : 0).',
        );
    }

    /**
     * LE BANDEAU DOIT DOMINER L'ENTETE COLLANT DU TABLEAU.
     *
     * Le bandeau cree un contexte d'empilement : son panneau « + N » (z-index 1050) y est
     * ENFERME, et tout le groupe ne vaut, face au reste du formulaire, que le z-index du
     * bandeau lui-meme. A 3, l'entete collant du tableau d'une collection (100) passait
     * devant : la liste des onglets replies etait coupee en deux par la ligne
     * « MONTANT FINAL / ACTIONS ».
     *
     * Le test COMPARE les deux nombres au lieu d'en figer un : si quelqu'un releve
     * l'entete du tableau, c'est ici que ca doit se voir.
     */
    public function testLeBandeauDomineLEnteteDuTableau(): void
    {
        $css = $this->lire(self::CSS);

        self::assertSame(1, preg_match('/thead\.sticky-header \{[^}]*z-index:\s*(\d+)/s', $css, $entete));
        self::assertSame(1, preg_match('/\.form-column \.jsb-onglets-barre \{[^}]*z-index:\s*(\d+)/s', $css, $bandeau));

        self::assertGreaterThan(
            (int) $entete[1],
            (int) $bandeau[1],
            sprintf(
                'Le bandeau (%d) doit passer devant l\'entete collant du tableau (%d), sinon le '
                . 'panneau « + N » est coupe par la ligne d\'entetes.',
                (int) $bandeau[1],
                (int) $entete[1],
            ),
        );
    }
}
