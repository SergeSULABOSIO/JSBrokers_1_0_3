<?php

namespace App\Tests\Ai;

use App\Ai\Reglage\ManifesteDesRegles;
use PHPUnit\Framework\TestCase;

/**
 * LE MANIFESTE DOIT DÉCRIRE LE CODE RÉEL, PAS CELUI D'HIER.
 *
 * ── CE QUE CE FICHIER PROTÈGE ───────────────────────────────────────────────
 * Une carte des règles est utile tant qu'elle est juste. Sans garde-fou, elle se
 * périme en quelques mois — un service renommé, une méthode déplacée — et devient
 * pire qu'absente : on cite une source, on va voir, il n'y a rien, et l'on doute
 * alors de la règle elle-même.
 *
 * Le manifeste a été établi en corrigeant SEPT sources d'un relevé antérieur, dont
 * deux franchement fausses (l'une pointait des variables de boucle, l'autre
 * affirmait qu'une règle n'était portée que par le prompt alors qu'un filtre SQL
 * l'applique). C'est exactement ce que ce test empêche de recommencer.
 *
 * Aucune base de données ici : on lit les fichiers du dépôt.
 */
final class ManifesteDesReglesTest extends TestCase
{
    private static function racine(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** Chaque règle du code pointe un fichier qui existe, et y contient son ancre. */
    public function testChaqueRegleDuCodePointeUneSourceReelle(): void
    {
        $cassees = [];

        foreach (ManifesteDesRegles::CODE as $id => $regle) {
            $chemin = self::racine() . '/' . $regle['source'];

            if (!is_file($chemin)) {
                $cassees[] = sprintf('%s : « %s » n’existe pas', $id, $regle['source']);
                continue;
            }
            if (!str_contains((string) file_get_contents($chemin), $regle['ancre'])) {
                $cassees[] = sprintf('%s : « %s » ne contient plus « %s »', $id, $regle['source'], $regle['ancre']);
            }
        }

        self::assertSame(
            [],
            $cassees,
            'Ces règles citent une source qui a bougé. Corrigez le manifeste — ou, si la règle '
            . 'a disparu du code, c’est un problème bien plus grave que ce test.'
        );
    }

    /** Chaque règle de restitution est réellement présente dans le prompt système. */
    public function testChaqueRegleDeRestitutionEstDansLePrompt(): void
    {
        $cassees = [];

        foreach (ManifesteDesRegles::RESTITUTION as $id => $regle) {
            $chemin = self::racine() . '/' . $regle['source'];

            if (!is_file($chemin)) {
                $cassees[] = sprintf('%s : « %s » n’existe pas', $id, $regle['source']);
                continue;
            }
            if (!str_contains((string) file_get_contents($chemin), $regle['ancre'])) {
                $cassees[] = sprintf('%s : « %s » ne se trouve plus dans le prompt', $id, $regle['ancre']);
            }
        }

        self::assertSame([], $cassees, 'Ces règles de restitution ne sont plus dans le prompt.');
    }

    /**
     * LES IDENTIFIANTS SONT STABLES ET SANS TROU. Un numéro qui saute laisse croire
     * qu'une règle a été retirée en silence ; un numéro réutilisé fait pointer une
     * discussion ancienne sur une règle nouvelle.
     */
    public function testLesIdentifiantsSontContinusEtSansDoublon(): void
    {
        foreach ([
            'R' => array_keys(ManifesteDesRegles::CODE),
            'K' => array_keys(ManifesteDesRegles::RESTITUTION),
            'B' => array_keys(ManifesteDesRegles::BOUSSOLE),
        ] as $prefixe => $ids) {
            self::assertSame(array_unique($ids), $ids, sprintf('Doublon dans la famille %s.', $prefixe));

            $attendus = [];
            for ($i = 1; $i <= \count($ids); ++$i) {
                $attendus[] = $prefixe . $i;
            }

            self::assertSame(
                $attendus,
                $ids,
                sprintf('La famille %s doit être numérotée de 1 à %d, sans trou.', $prefixe, \count($ids)),
            );
        }
    }

    /**
     * R13 ET K1 SONT JUMELLES, PAS DOUBLONS — et le manifeste doit le dire, sans quoi
     * quelqu'un finira par en supprimer une : R13 impose la règle aux CHIFFRES par un
     * filtre SQL, K1 l'impose au DISCOURS par le prompt.
     */
    public function testLaJumelleDeR13EstSignalee(): void
    {
        self::assertStringContainsString(
            'R13',
            ManifesteDesRegles::RESTITUTION['K1']['titre'],
            'K1 doit renvoyer explicitement à R13 : sans cela, elles passent pour un doublon.'
        );
        self::assertStringContainsString('projet', ManifesteDesRegles::CODE['R13']['titre']);
    }

    /** Aucune entrée muette : un intitulé vide ne cite rien et n'apprend rien. */
    public function testAucuneEntreeNEstMuette(): void
    {
        foreach (ManifesteDesRegles::familles() as $cle => $famille) {
            foreach ($famille['entrees'] as $id => $entree) {
                self::assertNotSame('', trim($entree['titre']), sprintf('%s (%s) n’a pas d’intitulé.', $id, $cle));
                self::assertNotSame('', trim($entree['source']), sprintf('%s (%s) n’a pas de source.', $id, $cle));
            }
        }
    }
}
