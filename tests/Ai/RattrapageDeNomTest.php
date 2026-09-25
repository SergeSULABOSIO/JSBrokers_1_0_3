<?php

namespace App\Tests\Ai;

use App\Ai\Tool\RattrapageDeNom;
use PHPUnit\Framework\TestCase;

/**
 * UN NOM D'OUTIL ÉCORCHÉ NE DOIT PLUS COÛTER UN TOUR ENTIER.
 *
 * Mesuré sur les journaux au 2026-09-25 : 10 appels sur 188 (5,3 %) visaient un outil
 * inexistant, et tous étaient des quasi-homonymes du vrai. Chacun rendait
 * « introuvable » et forçait le modèle à repartir pour un tour de quarante mille
 * jetons d'entrée.
 *
 * Ces tests verrouillent surtout ce que le rattrapage NE DOIT PAS faire : il corrige
 * une faute de frappe, il ne devine jamais une intention. Une hésitation entre deux
 * outils, un outil volontairement absent du tour, un nom trop lointain : on rend
 * « introuvable », parce qu'exécuter la mauvaise chose est bien pire que ne rien faire.
 */
class RattrapageDeNomTest extends TestCase
{
    /** Les outils déclarés d'un tour de lecture ordinaire. */
    private const DECLARES = [
        'analyse_portefeuille',
        'compter_entites',
        'consulter_guide',
        'lire_fiche',
        'rechercher_entites',
        'statistiques',
    ];

    /**
     * LES TROIS ÉCORCHURES RELEVÉES EN PRODUCTION, et elles sont toutes à distance 1.
     *
     * @dataProvider ecorchuresReelles
     */
    public function testUneEcorchureEstRattrapee(string $demande, string $attendu): void
    {
        self::assertSame($attendu, RattrapageDeNom::leProche($demande, self::DECLARES));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function ecorchuresReelles(): iterable
    {
        // 4 appels le 2026-09-19 : le modèle conjugue le nom de l'outil.
        yield 'un verbe au lieu du nom' => ['analyser_portefeuille', 'analyse_portefeuille'];
        // Le pluriel oublié.
        yield 'un singulier de trop' => ['rechercher_entite', 'rechercher_entites'];
        // Le modèle a glissé en espagnol le temps d'un appel.
        yield 'une langue qui dérape' => ['consultar_guide', 'consulter_guide'];
    }

    /**
     * ⚠ UNE HÉSITATION N'EST PAS UNE FAUTE DE FRAPPE.
     *
     * `lister_entites` est à égale distance de deux outils bien réels, dont les
     * portées n'ont rien à voir — compter n'est pas rechercher. Exécuter l'un des
     * deux au hasard rendrait une réponse fausse avec l'assurance d'une vraie, là
     * où « introuvable » laisse le modèle se reprendre.
     */
    public function testUnNomAmbiguResteIntrouvable(): void
    {
        self::assertNull(RattrapageDeNom::leProche('lister_entites', ['compter_entites', 'filtrer_entites']));
    }

    /**
     * ⚠ UN OUTIL VOLONTAIREMENT ABSENT DU TOUR N'EST PAS RATTRAPÉ VERS UN VOISIN.
     *
     * Un outil coupé en console, ou réservé à la trousse d'écriture, ne figure pas
     * parmi les candidats — et c'est la seule garde qui compte : la frontière est
     * tenue par la LISTE qu'on remet, jamais par un jugement porté ici. Rien ne doit
     * pouvoir le faire réapparaître sous un autre nom.
     */
    public function testUnOutilHorsDuTourNOuvrePasUnVoisin(): void
    {
        // Le modèle demande un outil d'écriture depuis une trousse de lecture.
        self::assertNull(RattrapageDeNom::leProche('preparer_operations', self::DECLARES));
    }

    /**
     * Un nom EXACT n'est jamais « rattrapé » : s'il est déclaré, l'appelant l'a déjà
     * exécuté ; s'il ne l'est pas, c'est une décision qu'on ne contourne pas.
     */
    public function testUnNomExactNEstJamaisDetourne(): void
    {
        self::assertNull(RattrapageDeNom::leProche('lire_fiche', self::DECLARES));
    }

    /** Un nom trop lointain reste introuvable : on ne devine pas une intention. */
    public function testUnNomLointainResteIntrouvable(): void
    {
        self::assertNull(RattrapageDeNom::leProche('lecture_donnees', self::DECLARES));
        self::assertNull(RattrapageDeNom::leProche('', self::DECLARES));
        self::assertNull(RattrapageDeNom::leProche('lire_fich', []));
    }

    /**
     * La distance est bornée, et la borne est SÉVÈRE : deux caractères. Au-delà, des
     * outils qui ne se ressemblent que par leur suffixe entreraient dans le filet.
     */
    public function testLaDistanceEstBornee(): void
    {
        self::assertSame('lire_fiche', RattrapageDeNom::leProche('lire_fich', self::DECLARES), 'un caractère manquant');
        self::assertSame('lire_fiche', RattrapageDeNom::leProche('lir_fich', self::DECLARES), 'deux caractères manquants');
        self::assertNull(RattrapageDeNom::leProche('li_fich', self::DECLARES), 'trois : trop loin');
    }
}
