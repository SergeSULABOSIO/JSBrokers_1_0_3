<?php

namespace App\Tests\Workspace;

use App\Entity\ReversementRetroAgent;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Canvas\Provider\Form\ReversementRetroAgentFormCanvasProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA RUBRIQUE « RÉTROS INTERMÉDIAIRES » — ce qu'elle ouvre, et ce qu'elle ferme.
 *
 * Elle n'existait pas : l'entité était gouvernée hors carte d'accès, donc sans liste, sans
 * fiche, et sans les deux actions documentaires que FormCanvasProvider injecte sur toute
 * entité portant une collection de documents. Conséquence concrète : un bordereau oublié au
 * moment du virement n'avait plus AUCUN endroit où être joint.
 *
 * Ce test tient les trois propriétés qui rendent cette ouverture sûre :
 *   1. la rubrique est déclarée, et sous le libellé qu'on lit à l'écran ;
 *   2. son droit lui est PROPRE — il empruntait celui de l'avenant, ce qui rendait la
 *      rubrique inréglable depuis le gestionnaire des rôles ;
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
        self::assertSame('Rétros intermédiaires', $libelles['ReversementRetroAgent']);
    }

    /**
     * LE DROIT EST PROPRE À LA RUBRIQUE — il ne l'est plus à l'avenant.
     *
     * Ce test affirmait l'inverse jusqu'au 2026-08-24, et il avait alors raison : emprunter
     * le droit de l'avenant avait permis d'ouvrir la rubrique sans migration. Mais le
     * cabinet ne pouvait ni l'ouvrir ni la fermer sans toucher aux contrats, et le réglage
     * n'apparaissait NULLE PART dans le gestionnaire des rôles — alors que ce que cette
     * rubrique montre, c'est ce que chaque collaborateur a touché.
     */
    public function testLeDroitEstPropreALaRubrique(): void
    {
        $carte = new \ReflectionClass(WorkspaceAccessResolver::class);
        $map = $carte->getConstant('MAP');

        self::assertArrayHasKey('ReversementRetroAgent', $map);
        [$module, $collectionGetter, $fieldGetter] = $map['ReversementRetroAgent'];

        self::assertNotSame(
            $map['Avenant'][2],
            $fieldGetter,
            'Le droit ne doit plus être celui de l’avenant : lire un contrat ne dit rien de ce '
            . 'qu’un collègue a touché.',
        );
        self::assertSame('getAccessReversementRetroAgent', $fieldGetter);

        // Le droit se règle là où l'utilisateur cherche la rubrique : en Finances, module ET
        // collection de rôles. Les deux coïncident enfin, ce qui n'était pas le cas.
        self::assertSame('Finances', $module);
        self::assertSame('getRolesEnFinance', $collectionGetter);

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
