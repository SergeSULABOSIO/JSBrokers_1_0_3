<?php

namespace App\Tests\Workspace;

use App\Entity\ReversementRetroAgent;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Canvas\Provider\Form\ReversementRetroAgentFormCanvasProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA RUBRIQUE « REVERSEMENTS DE RÉTROCOMMISSION » — ce qu'elle ouvre, et ce qu'elle ferme.
 *
 * Elle n'existait pas : l'entité était gouvernée hors carte d'accès, donc sans liste, sans
 * fiche, et sans les deux actions documentaires que FormCanvasProvider injecte sur toute
 * entité portant une collection de documents. Conséquence concrète : un bordereau oublié au
 * moment du virement n'avait plus AUCUN endroit où être joint.
 *
 * Ce test tient les trois propriétés qui rendent cette ouverture sûre :
 *   1. la rubrique est déclarée, et sous le libellé qu'on lit à l'écran ;
 *   2. son droit reste CELUI DE L'AVENANT — aucun nouveau champ de rôle, donc aucune
 *      migration, et personne ne gagne un accès qu'il n'avait pas ;
 *   3. la CRÉATION y est refusée, avec son motif.
 */
class ReversementRubriqueTest extends KernelTestCase
{
    private function resolver(): WorkspaceAccessResolver
    {
        self::bootKernel();

        return static::getContainer()->get(WorkspaceAccessResolver::class);
    }

    /** La rubrique est dans la carte d'accès, sous son libellé d'écran. */
    public function testLaRubriqueEstDeclareeSousSonLibelle(): void
    {
        $libelles = $this->resolver()->libellesEntites();

        self::assertArrayHasKey('ReversementRetroAgent', $libelles);
        self::assertSame('Reversements de rétrocommission', $libelles['ReversementRetroAgent']);
    }

    /**
     * Le droit est celui de l'avenant, et ce n'est pas un détail : c'est ce qui a permis
     * d'ouvrir la rubrique SANS champ de rôle nouveau, donc sans migration ni reprise des
     * rôles existants. Le jour où il faudra les distinguer, ce sera un champ de plus —
     * pas un changement de logique.
     */
    public function testLeDroitResteCeluiDeLAvenant(): void
    {
        $carte = new \ReflectionClass(WorkspaceAccessResolver::class);
        $map = $carte->getConstant('MAP');

        self::assertArrayHasKey('ReversementRetroAgent', $map);
        [$module, $collectionGetter, $fieldGetter] = $map['ReversementRetroAgent'];

        self::assertSame($map['Avenant'][1], $collectionGetter, 'Même collection de rôles que l’avenant.');
        self::assertSame($map['Avenant'][2], $fieldGetter, 'Même champ d’accès que l’avenant.');

        // LE MODULE, LUI, N'A PAS À SUIVRE. Ce n'est qu'un libellé de regroupement —
        // celui sous lequel l'utilisateur cherche la rubrique. Un décaissement se cherche
        // dans les Finances, quand bien même son droit vient de la production.
        self::assertSame('Finances', $module);

        // Le getter doit EXISTER sur l'entité de rôles nommée : describePerimetre() l'appelle,
        // et un nom fantaisiste n'échouerait qu'à l'affichage du périmètre d'un invité.
        $rolesClass = 'App\\Entity\\' . str_replace('getRoles', 'Roles', $collectionGetter);
        self::assertTrue(method_exists($rolesClass, $fieldGetter), "{$rolesClass}::{$fieldGetter}() doit exister.");
    }

    /**
     * ELLE N'A PAS ÉTÉ LAISSÉE DANS LES DEUX RÉGIMES À LA FOIS.
     *
     * `can()` consulte GOUVERNANCE_PARENT AVANT la carte : y laisser l'entité aurait donné
     * deux déclarations d'un même droit — d'accord aujourd'hui, divergentes au premier
     * ajustement, et l'une des deux morte sans que rien ne le dise.
     */
    public function testElleNEstPlusUneSousEntiteGouverneeHorsCarte(): void
    {
        $carte = new \ReflectionClass(WorkspaceAccessResolver::class);

        self::assertArrayNotHasKey('ReversementRetroAgent', $carte->getConstant('GOUVERNANCE_PARENT'));
        self::assertArrayNotHasKey('ReversementRetroAgent', $carte->getConstant('SOUS_ENTITES_LIBELLES'));
    }

    /**
     * LA CRÉATION EST FERMÉE, ET LE MOTIF EST LE SIEN.
     *
     * Un reversement ne s'enregistre pas sans justificatif, or les pièces d'une fiche
     * s'attachent après sa création : exiger la preuve ici serait contradictoire. Le motif
     * doit venir du canevas — il était écrit en dur dans la barre d'outils, au nom des
     * conditions de partage, et cette rubrique aurait renvoyé l'utilisateur « à la fiche
     * d'un partenaire ».
     */
    public function testLaCreationEstRefuseeAvecSonMotif(): void
    {
        self::bootKernel();
        $provider = static::getContainer()->get(ReversementRetroAgentFormCanvasProvider::class);

        $parametres = $provider->getCanvas(new ReversementRetroAgent(), null)['parametres'];

        self::assertTrue($parametres['creation_interdite']);
        self::assertNotEmpty($parametres['creation_interdite_message']);
        self::assertStringContainsString('rapport de production', $parametres['creation_interdite_message']);
        self::assertStringNotContainsString(
            'condition de partage',
            $parametres['creation_interdite_message'],
            'Le motif doit parler DE CETTE rubrique.',
        );
    }

    /** La fiche porte bien sa collection de justificatifs — c'est elle qui ouvre les actions. */
    public function testLaFichePorteSaCollectionDeJustificatifs(): void
    {
        self::bootKernel();
        $canvas = static::getContainer()
            ->get(\App\Services\Canvas\Provider\Entity\ReversementRetroAgentEntityCanvasProvider::class)
            ->getCanvas();

        $codes = array_column($canvas['liste'], 'code');
        self::assertContains('documents', $codes);
        self::assertContains('lotReference', $codes, 'La référence de lot dit qu’un virement couvre plusieurs lignes.');
    }
}
