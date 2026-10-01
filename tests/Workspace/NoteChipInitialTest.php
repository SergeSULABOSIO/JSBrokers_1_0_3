<?php

namespace App\Tests\Workspace;

use App\Controller\Admin\NoteController;
use App\Entity\Entreprise;
use App\Services\Canvas\Provider\List\NoteListCanvasProvider;
use App\Services\Search\NoteReglementScope;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA RUBRIQUE NOTES S'OUVRE SUR CE QU'IL RESTE À ENCAISSER.
 *
 * ── CE QUE CE BANC TIENT ────────────────────────────────────────────────────────────
 * Trois choses que rien d'autre ne vérifie, et qui se cassent en silence :
 *
 *   1. le chip POSÉ D'OFFICE au premier chargement — c'est la première question qu'on se
 *      pose en ouvrant ses notes, et un défaut qui disparaîtrait ne lèverait rien ;
 *   2. le fait que les chips et le badge de la barre de recherche manipulent la MÊME clé.
 *      Deux clés différentes donneraient un écran où cliquer un chip n'éteint pas le badge,
 *      et où retirer le badge ne désactive pas le chip ;
 *   3. l'option « Toutes », qui est le seul moyen de RETIRER un filtre posé d'office. Sans
 *      elle, la rubrique s'ouvrirait sur un sous-ensemble dont on ne pourrait pas sortir.
 *
 * `getInitialSearchCriteria` est protégée : on l'atteint par réflexion, exactement comme le
 * font déjà les bancs de Piste, Cotation et Avenant.
 */
class NoteChipInitialTest extends KernelTestCase
{
    public function testLaRubriqueSOuvreSurLesImpayees(): void
    {
        self::bootKernel();

        $methode = new \ReflectionMethod(NoteController::class, 'getInitialSearchCriteria');
        $methode->setAccessible(true);

        $controleur = static::getContainer()->get(NoteController::class);
        $criteres = $methode->invoke($controleur, \App\Entity\Note::class, 0, new Entreprise());

        self::assertArrayHasKey(NoteReglementScope::CRITERION_KEY, $criteres,
            'La rubrique doit s\'ouvrir filtrée : c\'est ce qui met le travail restant sous les yeux.',
        );
        self::assertSame(
            NoteReglementScope::IMPAYEE,
            $criteres[NoteReglementScope::CRITERION_KEY]['value'],
        );
        self::assertSame('Note impayée', $criteres[NoteReglementScope::CRITERION_KEY]['label'],
            'Le libellé du badge vient du scope : écrit à la main, il dériverait du chip.',
        );
    }

    /**
     * ⚠ UNE SEULE CLÉ POUR LE CHIP ET POUR LE BADGE.
     *
     * Le canevas de LISTE porte les chips, celui de RECHERCHE porte le badge retirable et le
     * champ du dialogue avancé. S'ils nommaient le critère différemment, les deux surfaces
     * cesseraient de se parler — sans erreur, sans trace.
     */
    public function testLeChipEtLeBadgePartagentLaMemeCle(): void
    {
        self::bootKernel();

        /** @var NoteListCanvasProvider $provider */
        $provider = static::getContainer()->get(NoteListCanvasProvider::class);
        $groupes = $provider->getCanvas()['filtres_predefinis'] ?? [];

        self::assertCount(1, $groupes,
            'Une note n\'a qu\'UNE dette : un seul groupe. Les quatre axes de Tranche '
            . 'répondent à quatre dettes de débiteurs différents.',
        );
        self::assertSame(NoteReglementScope::CRITERION_KEY, $groupes[0]['critere']);

        $recherche = static::getContainer()
            ->get(\App\Services\Canvas\SearchCanvasProvider::class)
            ->getCanvas(\App\Entity\Note::class);

        $cles = array_column($recherche, 'Nom');
        self::assertContains(NoteReglementScope::CRITERION_KEY, $cles,
            'Sans ce critère au canevas de recherche, le filtre posé d\'office ne serait pas '
            . 'retirable : un badge sans croix.',
        );
    }

    /**
     * LES QUATRE ÉTATS SONT PROPOSÉS, PLUS « TOUTES ».
     *
     * L'option vide est celle qui retire le critère. Elle ne déclare aucune condition de
     * visibilité : elle doit rester indestructible, sinon un filtre posé d'office
     * enfermerait l'utilisateur dans un sous-ensemble.
     */
    public function testLesQuatreEtatsEtLOptionDeRetraitSontProposes(): void
    {
        self::bootKernel();

        /** @var NoteListCanvasProvider $provider */
        $provider = static::getContainer()->get(NoteListCanvasProvider::class);
        $options = $provider->getCanvas()['filtres_predefinis'][0]['options'];

        $valeurs = array_column($options, 'value');
        self::assertSame(
            [...array_keys(NoteReglementScope::ETATS), ''],
            $valeurs,
            'Les quatre états dans l\'ordre du scope, puis l\'option de retrait.',
        );

        foreach ($options as $option) {
            self::assertNotSame('', $option['titre_complet'] ?? '', sprintf(
                'L\'option « %s » doit porter un titre complet : il alimente aria-label, title '
                . 'ET le libellé du badge de recherche. Sans lui, le badge dirait « Partielles », '
                . 'qui isolé ne dit pas de quoi il s\'agit.',
                $option['label'],
            ));
        }
    }
}
