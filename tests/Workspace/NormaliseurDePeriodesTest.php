<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\NormaliseurDeDates;
use App\Service\Conge\NormaliseurDePeriodes;
use PHPUnit\Framework\TestCase;

/**
 * « LA SEMAINE PROCHAINE » DOIT DONNER LES MÊMES DATES À CHAQUE FOIS.
 *
 * C'est tout l'intérêt de résoudre les périodes côté serveur plutôt que de laisser le
 * modèle calculer : un congé créé sur une date mal comprise coûte plus cher que le tour
 * de dialogue supplémentaire qui l'aurait évité.
 *
 * L'horloge est injectée : sans cela, ce test passerait ou échouerait selon le jour où
 * on le lance, ce qui est la pire forme d'échec.
 */
class NormaliseurDePeriodesTest extends TestCase
{
    /** Mercredi 9 septembre 2026. Toutes les attentes sont calculées depuis ce jour. */
    private const AUJOURDHUI = '2026-09-09';

    private NormaliseurDePeriodes $normaliseur;

    protected function setUp(): void
    {
        $this->normaliseur = new NormaliseurDePeriodes(new NormaliseurDeDates());
    }

    /**
     * @dataProvider periodesComprises
     */
    public function testUnePeriodeDicteeDevientDeuxDates(string $dicte, string $debut, string $fin): void
    {
        $periode = $this->normaliseur->resoudre($dicte, new \DateTimeImmutable(self::AUJOURDHUI));

        self::assertNotNull($periode, sprintf('« %s » aurait dû être compris.', $dicte));
        self::assertSame($debut, $periode->debut->format('Y-m-d'), 'date de début');
        self::assertSame($fin, $periode->fin->format('Y-m-d'), 'date de fin');
        self::assertNotSame('', $periode->interpretation, "L'interprétation est ce qu'on montre à l'utilisateur : elle ne peut pas être vide.");
    }

    public static function periodesComprises(): iterable
    {
        // Du lundi au vendredi : ce que les gens veulent dire en posant « une semaine ».
        yield 'la semaine prochaine' => ['la semaine prochaine', '2026-09-14', '2026-09-18'];
        yield 'cette semaine' => ['cette semaine', '2026-09-07', '2026-09-11'];

        // Dates complètes : déléguées à la grammaire de dates du projet.
        yield 'du … au … en dates complètes' => ['du 12/10/2026 au 20/10/2026', '2026-10-12', '2026-10-20'];
        yield 'du … au … avec mois en clair' => ['du 3 au 7 octobre 2026', '2026-10-03', '2026-10-07'];

        // Quantièmes nus : le mois courant s'ils sont encore à venir.
        yield 'du 15 au 20 (mois courant)' => ['du 15 au 20', '2026-09-15', '2026-09-20'];
        // Déjà passés ce mois-ci → le mois suivant. On ne propose jamais du rétroactif.
        yield 'du 2 au 4 (bascule au mois suivant)' => ['du 2 au 4', '2026-10-02', '2026-10-04'];

        yield 'demain' => ['demain', '2026-09-10', '2026-09-10'];
        yield 'après-demain' => ['après-demain', '2026-09-11', '2026-09-11'];
        yield "aujourd'hui" => ["aujourd'hui", '2026-09-09', '2026-09-09'];

        yield 'vendredi prochain' => ['vendredi prochain', '2026-09-11', '2026-09-11'];

        yield 'le mois prochain' => ['le mois prochain', '2026-10-01', '2026-10-31'];

        // « les 3 prochains jours » commence DEMAIN : aujourd'hui n'est plus à venir.
        yield 'les 3 prochains jours' => ['les 3 prochains jours', '2026-09-10', '2026-09-12'];
        yield 'pendant 5 jours' => ['pendant 5 jours', '2026-09-10', '2026-09-14'];
    }

    /**
     * CE QUI N'EST PAS COMPRIS EST REFUSÉ, jamais deviné.
     *
     * L'assistant demande alors les dates — ce qui est toujours acceptable. Inventer une
     * période, non : la demande partirait sur des jours que personne n'a choisis.
     *
     * @dataProvider periodesRefusees
     */
    public function testCeQuiNEstPasComprisEstRefuse(string $dicte): void
    {
        self::assertNull(
            $this->normaliseur->resoudre($dicte, new \DateTimeImmutable(self::AUJOURDHUI)),
            sprintf('« %s » aurait dû être refusé plutôt que deviné.', $dicte),
        );
    }

    public static function periodesRefusees(): iterable
    {
        yield 'vide' => [''];
        yield 'du blabla' => ['quand ça m\'arrange'];
        yield 'une expression floue' => ['plus tard dans l\'année'];
        yield 'bornes inversées' => ['du 20/10/2026 au 12/10/2026'];
        yield 'quantième impossible' => ['du 32 au 35'];
    }

    /** Une période d'un seul jour reste une période : début et fin confondus. */
    public function testUnJourUniqueEstUnePeriodeDUnJour(): void
    {
        $periode = $this->normaliseur->resoudre('demain', new \DateTimeImmutable(self::AUJOURDHUI));

        self::assertNotNull($periode);
        self::assertEquals($periode->debut, $periode->fin);
    }

    /** L'interprétation part dans le récapitulatif de confirmation : elle doit être lisible. */
    public function testLInterpretationNommeLesDatesRetenues(): void
    {
        $periode = $this->normaliseur->resoudre('la semaine prochaine', new \DateTimeImmutable(self::AUJOURDHUI));

        self::assertNotNull($periode);
        self::assertStringContainsString('14/09/2026', $periode->interpretation);
        self::assertStringContainsString('18/09/2026', $periode->interpretation);
    }
}
