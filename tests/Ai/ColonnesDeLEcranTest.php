<?php

namespace App\Tests\Ai;

use App\Ai\Presentation\Colonnes;
use App\Ai\Presentation\ColonnesDeLEcran;
use App\Services\CanvasBuilder;
use App\Services\ServiceMonnaies;
use PHPUnit\Framework\TestCase;

/**
 * LES COLONNES DE LA RUBRIQUE, servies à Ket pour chaque ligne d'une liste.
 *
 * L'INCIDENT DU 2026-08-14. `rechercher_entites` ne rendait que `id` + `libelle`.
 * Prié d'ajouter « une colonne pour le taux », le modèle a affiché 0 % pour les dix
 * partenaires — dont les parts valaient 30, 45 et 50 % — puis, sommé de vérifier, a
 * fabriqué une explication (« une valeur par défaut non renseignée dans la table
 * générale »). La donnée était à l'écran ; elle n'arrivait simplement jamais au chat.
 */
class ColonnesDeLEcranTest extends TestCase
{
    /** Le canevas RÉEL de Partenaire, réduit à ce qui compte ici. */
    private const CANVAS_PARTENAIRE = [
        'colonne_principale' => [
            'texte_principal'    => ['attribut_code' => 'nom'],
            'textes_secondaires' => [
                ['attribut_code' => 'telephone'],
                ['attribut_code' => 'email'],
            ],
        ],
        'colonnes_numeriques' => [
            ['titre_colonne' => 'Part (%)', 'attribut_unité' => '%', 'attribut_code' => 'partPourcentage'],
            ['titre_colonne' => 'Assiette', 'attribut_unité' => 'USD', 'attribut_code' => 'montantPur'],
            ['titre_colonne' => 'Rétro-comm.', 'attribut_unité' => 'USD', 'attribut_code' => 'retroCommission'],
        ],
    ];

    private function service(array $canvas): ColonnesDeLEcran
    {
        $canvasBuilder = $this->createMock(CanvasBuilder::class);
        $canvasBuilder->method('getListeCanvas')->willReturn($canvas);

        $monnaies = $this->createMock(ServiceMonnaies::class);
        $monnaies->method('getCodeMonnaieAffichage')->willReturn('USD');

        return new ColonnesDeLEcran($canvasBuilder, $monnaies);
    }

    /** Un partenaire tel que la rubrique le voit, indicateurs déjà posés. */
    private function partenaire(int $id, string $nom, float $part): object
    {
        return new class($id, $nom, $part) {
            public function __construct(
                private int $id,
                public string $nom,
                public float $partPourcentage,
                public string $telephone = '+243000',
                public float $montantPur = 0.0,
                public float $retroCommission = 0.0,
            ) {
            }

            public function getId(): int
            {
                return $this->id;
            }
        };
    }

    public function testChaqueLignePorteLesColonnesDeLaRubrique(): void
    {
        $resultat = $this->service(self::CANVAS_PARTENAIRE)->projeter([
            $this->partenaire(10, 'LOCKTON', 30.0),
            $this->partenaire(9, 'MONT BLANC', 50.0),
        ], 'App\\Entity\\Partenaire');

        // LE point de l'incident : le taux est là, et c'est le VRAI.
        self::assertSame(30.0, $resultat['valeurs'][10]['partPourcentage']);
        self::assertSame(50.0, $resultat['valeurs'][9]['partPourcentage']);
        // Les textes secondaires de la rubrique voyagent aussi (donnée, pas colonne).
        self::assertSame('+243000', $resultat['valeurs'][10]['telephone']);
    }

    /**
     * L'UNITÉ DU CANEVAS DIT LE RÔLE. Sans cette déclaration, `partPourcentage: 30`
     * s'afficherait « 30 » : `Colonnes::roleDeduit()` refuse volontairement de deviner
     * un pourcentage ou un montant, pour ne jamais coller la mauvaise unité.
     */
    public function testLUniteDuCanevasDonneLeRoleDeColonne(): void
    {
        $presentation = $this->service(self::CANVAS_PARTENAIRE)->projeter(
            [$this->partenaire(10, 'LOCKTON', 30.0)],
            'App\\Entity\\Partenaire',
        )['presentation'];

        self::assertSame(Colonnes::POURCENTAGE, $presentation['colonnes']['partPourcentage']);
        self::assertSame(Colonnes::MONTANT, $presentation['colonnes']['montantPur']);
        // Un taux ne s'additionne JAMAIS ; les montants, si.
        self::assertNotContains('partPourcentage', $presentation['totaliser']);
        self::assertContains('montantPur', $presentation['totaliser']);
        // Les textes secondaires ne sont PAS des colonnes de tableau.
        self::assertArrayNotHasKey('telephone', $presentation['colonnes']);
    }

    /** Une entité sans canevas de liste ne casse rien : elle garde id + libellé. */
    public function testUneEntiteSansCanevasResteCompacte(): void
    {
        $resultat = $this->service([])->projeter(
            [$this->partenaire(10, 'LOCKTON', 30.0)],
            'App\\Entity\\Inconnue',
        );

        self::assertSame([], $resultat['valeurs']);
        self::assertSame([], $resultat['presentation']);
    }

    /**
     * Une colonne d'écran qui vise un indicateur que l'entité ne porte pas est
     * IGNORÉE, jamais rendue vide : le contrat de présentation interdit à Ket
     * d'afficher une colonne absente des résultats, et une clé à null la lui
     * proposerait quand même.
     */
    public function testUneColonneIllisibleEstOmiseEtNeCassePas(): void
    {
        $canvas = self::CANVAS_PARTENAIRE;
        $canvas['colonnes_numeriques'][] = [
            'titre_colonne' => 'Inexistant', 'attribut_unité' => 'USD', 'attribut_code' => 'nExistePas',
        ];

        $valeurs = $this->service($canvas)->projeter(
            [$this->partenaire(10, 'LOCKTON', 30.0)],
            'App\\Entity\\Partenaire',
        )['valeurs'];

        self::assertArrayNotHasKey('nExistePas', $valeurs[10]);
        self::assertSame(30.0, $valeurs[10]['partPourcentage']);
    }
}
