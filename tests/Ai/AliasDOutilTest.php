<?php

namespace App\Tests\Ai;

use App\Ai\Tool\AliasDOutils;
use App\Ai\Tool\RattrapageDeNom;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UN ANCIEN NOM D'OUTIL RESTE COMPRIS — MAIS N'OUVRE RIEN DE PLUS.
 *
 * ── POURQUOI DES ALIAS, ET PAS SEULEMENT LE RATTRAPAGE ──────────────────────────
 *
 * `RattrapageDeNom` corrige une faute de frappe à deux caractères près. Un renommage
 * n'en est pas une : `compter_entites` fusionné dans `rechercher_entites` est à
 * distance 8, `lecture_donnees` à distance 9 du plus proche outil. Le filet ne les
 * aurait jamais rattrapés, et faire reposer une transition d'architecture sur le hasard
 * des lettres n'aurait tenu que par chance.
 *
 * ── CE QUE CES TESTS VERROUILLENT ────────────────────────────────────────────────
 *
 * Un alias RENOMME, il n'autorise pas. Trois propriétés, et chacune protège d'une
 * façon différente de transformer une commodité en faille :
 *
 *  1. sa cible EXISTE — sinon l'alias promet un outil disparu, et l'appel échoue
 *     exactement comme s'il n'y avait pas d'alias, mais silencieusement ;
 *  2. il ne FRANCHIT PAS la frontière lecture/écriture — un ancien nom de lecture ne
 *     doit jamais mener à une écriture, quel qu'ait été son sens autrefois ;
 *  3. il n'ÉCRASE PAS un outil vivant — un nom que porte un outil aujourd'hui désigne
 *     cet outil-là, quoi qu'il ait désigné hier.
 *
 * La quatrième propriété — un outil coupé en console reste introuvable par son alias —
 * est éprouvée dans {@see RattrapageJournaliseTest}, où vit déjà le harnais de coupure.
 */
class AliasDOutilTest extends KernelTestCase
{
    public function testChaqueAliasViseUnOutilQuiExiste(): void
    {
        $noms = $this->nomsDuCatalogue();

        foreach (AliasDOutils::ALIAS as $ancien => $cible) {
            self::assertContains(
                $cible,
                $noms,
                sprintf(
                    'L\'alias « %s » vise « %s », qui n\'existe pas. Un alias sans cible échoue '
                    . 'comme s\'il n\'existait pas — mais en donnant l\'illusion qu\'on a géré la transition.',
                    $ancien,
                    $cible,
                ),
            );
        }
    }

    public function testAucunAliasNePorteLeNomDUnOutilVivant(): void
    {
        $noms = $this->nomsDuCatalogue();

        foreach (array_keys(AliasDOutils::ALIAS) as $ancien) {
            self::assertNotContains(
                $ancien,
                $noms,
                sprintf(
                    '« %s » est à la fois un alias et un outil réel. La garde de resoudre() fait '
                    . 'gagner l\'outil, donc l\'alias est mort — mais sa présence laisse croire le contraire.',
                    $ancien,
                ),
            );
        }
    }

    /**
     * ⚠ LA PROPRIÉTÉ DE SÉCURITÉ. Un alias ne doit jamais faire passer de la lecture à
     * l'écriture : ce serait donner, par un nom d'hier, un accès que le nom d'aujourd'hui
     * n'accorde pas.
     */
    public function testAucunAliasNeFranchitLaFrontiereVersLEcriture(): void
    {
        self::bootKernel();
        $catalogue = static::getContainer()->get(TrousseCatalogue::class);

        foreach (AliasDOutils::ALIAS as $ancien => $cible) {
            if (!$catalogue->estOutilDEcriture($cible)) {
                continue;
            }
            // Une cible d'écriture n'est admissible que si l'ancien nom en était un
            // aussi. Aucun de nos alias n'est dans ce cas aujourd'hui ; le jour où l'un
            // le sera, ce test obligera à l'écrire explicitement ici.
            self::fail(sprintf(
                'L\'alias « %s » mène à « %s », un outil d\'ÉCRITURE. Un ancien nom ne doit pas '
                . 'ouvrir une capacité que son remplaçant réserve à l\'autre trousse.',
                $ancien,
                $cible,
            ));
        }

        self::assertTrue(true, 'Aucun alias ne mène à un outil d\'écriture.');
    }

    /**
     * UN NOM QUI EXISTE N'EST JAMAIS UN ALIAS — la même garde que dans le rattrapage,
     * et pour la même raison : l'outil du jour l'emporte toujours sur l'histoire du mot.
     */
    public function testUnNomExistantNEstJamaisResoluCommeAlias(): void
    {
        self::assertNull(AliasDOutils::resoudre('rechercher_entites', ['rechercher_entites']));
        // Et s'il n'existe plus, l'alias reprend la main.
        self::assertSame(
            'rechercher_entites',
            AliasDOutils::resoudre('compter_entites', ['rechercher_entites']),
        );
    }

    /**
     * LA CONTRAINTE QUE LE RATTRAPAGE IMPOSE AU NOMMAGE, vérifiée sur la liste réelle.
     *
     * `RattrapageDeNom::leProche()` refuse de trancher quand deux outils déclarés au même
     * tour sont à égale distance du nom écorché. Deux noms trop proches DANS UNE MÊME
     * TROUSSE désarment donc le filet pour toute leur famille — en silence, et c'est le
     * pire : rien ne le signale, les écorchures redeviennent simplement introuvables.
     *
     * Ce test ne dit pas « c'est interdit » : il IMPRIME les paires en cause, pour que le
     * prochain nom choisi le soit en connaissance. Une seule paire subsiste aujourd'hui —
     * `echange_exporter` / `echange_importer` —, et leur renommage est planifié.
     */
    public function testLesNomsTropProchesSontConnusEtDenombres(): void
    {
        self::bootKernel();
        $catalogue = static::getContainer()->get(TrousseCatalogue::class);
        $noms = array_values(array_map(static fn ($o): string => $o->name(), $catalogue->tous()));

        $paires = [];
        for ($i = 0; $i < \count($noms); ++$i) {
            for ($j = $i + 1; $j < \count($noms); ++$j) {
                if (levenshtein($noms[$i], $noms[$j]) <= RattrapageDeNom::DISTANCE_MAX) {
                    $paires[] = $noms[$i] . ' / ' . $noms[$j];
                }
            }
        }

        // LE SEUIL EST UN CONSTAT, PAS UN IDÉAL. Il vaut 1 parce qu'une paire reste, et
        // il doit DESCENDRE à mesure qu'on renomme — jamais monter. Un test qu'on relâche
        // pour faire passer un ajout ne protège plus rien.
        self::assertLessThanOrEqual(
            1,
            \count($paires),
            'Des noms d\'outils sont trop proches pour que le rattrapage ose trancher entre eux : '
            . implode(', ', $paires) . '. Sur ces familles, une écorchure reste introuvable.',
        );
    }

    /** @return list<string> */
    private function nomsDuCatalogue(): array
    {
        self::bootKernel();

        return array_values(array_map(
            static fn ($o): string => $o->name(),
            static::getContainer()->get(TrousseCatalogue::class)->tous(),
        ));
    }
}
