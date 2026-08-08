<?php

namespace App\Tests\Ai;

use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Telemetrie\RapportTokens;
use PHPUnit\Framework\TestCase;

/**
 * Dépouillement de la campagne. Deux sorties portent la décision et doivent
 * donc être exactes : le PIC par minute glissante — la seule métrique qui
 * touche réellement le plafond du fournisseur, puisque le quota est partagé
 * entre tous les invités — et la PROJECTION, qui rejoue la chronologie
 * observée avec un bloc invariant allégé pour dire si un dégraissage suffirait.
 */
class RapportTokensTest extends TestCase
{
    /** @return array<string, mixed> */
    private function tour(string $horodatage, int $entree, int $systeme = 50000, int $outils = 70000, int $historique = 7000, string $messageId = 'm1'): array
    {
        return [
            'evenement'        => 'tour',
            'horodatage'       => $horodatage,
            'messageId'        => $messageId,
            'moteur'           => 'gemini',
            'modele'           => 'gemini-flash-lite-latest',
            'tokensEntree'     => $entree,
            'octetsSysteme'    => $systeme,
            'octetsOutils'     => $outils,
            'octetsHistorique' => $historique,
        ];
    }

    /** @return array<string, mixed> */
    private function message(string $issue, int $tours, int $cumulEntree, array $outils = [], string $messageId = 'm1'): array
    {
        return [
            'evenement'      => 'message',
            'horodatage'     => '2026-08-08T10:00:00+01:00',
            'messageId'      => $messageId,
            'moteur'         => 'gemini',
            'modele'         => 'gemini-flash-lite-latest',
            'issue'          => $issue,
            'tours'          => $tours,
            'cumulEntree'    => $cumulEntree,
            'sequenceOutils' => $outils,
        ];
    }

    public function testSepareLesToursDesMessages(): void
    {
        $rapport = new RapportTokens([
            $this->tour('2026-08-08T10:00:00+01:00', 36000),
            $this->message(JournalTokens::ISSUE_REPONSE, 1, 36000),
        ]);

        $this->assertCount(1, $rapport->tours());
        $this->assertCount(1, $rapport->messages());
    }

    /**
     * Le ratio n'est pas deviné : le fournisseur compte les tokens, nous
     * comptons les octets. C'est ce ratio qui convertit une réduction d'octets
     * en économie de tokens dans la projection.
     */
    public function testLeRatioOctetsParTokenEstDeduitDesMesures(): void
    {
        $rapport = new RapportTokens([
            $this->tour('2026-08-08T10:00:00+01:00', 10000, systeme: 20000, outils: 15000, historique: 2000),
        ]);

        $this->assertEqualsWithDelta(3.7, $rapport->ratioOctetsParToken(), 0.001);
    }

    public function testPartInvarianteExcluLHistorique(): void
    {
        $rapport = new RapportTokens([
            $this->tour('2026-08-08T10:00:00+01:00', 1000, systeme: 40000, outils: 50000, historique: 10000),
        ]);

        $this->assertEqualsWithDelta(0.9, $rapport->partInvariante(), 0.001);
    }

    /**
     * Le pic se calcule sur une fenêtre GLISSANTE de 60 s, pas par minute
     * calendaire : c'est ainsi que le fournisseur compte, et c'est pourquoi un
     * « essaie encore » lancé 30 s après un refus se heurte au même mur.
     */
    public function testLePicSuitUneFenetreGlissanteDeSoixanteSecondes(): void
    {
        $rapport = new RapportTokens([
            $this->tour('2026-08-08T10:00:00+01:00', 100000),
            $this->tour('2026-08-08T10:00:30+01:00', 100000),
            // 90 s après le premier : celui-ci est sorti de la fenêtre.
            $this->tour('2026-08-08T10:01:30+01:00', 100000),
        ]);

        $pic = $rapport->picParMinute();

        $this->assertSame(200000, $pic['pic'], 'Seuls les tours distants de moins de 60 s se cumulent.');
        $this->assertSame(0, $pic['depassements']);
    }

    public function testLeDepassementDuPlafondEstCompteEtRattacheAuMessage(): void
    {
        $rapport = new RapportTokens([
            $this->tour('2026-08-08T10:00:00+01:00', 120000, messageId: 'a'),
            $this->tour('2026-08-08T10:00:10+01:00', 120000, messageId: 'a'),
            $this->tour('2026-08-08T10:00:20+01:00', 120000, messageId: 'b'),
        ]);

        $pic = $rapport->picParMinute();

        $this->assertSame(360000, $pic['pic']);
        $this->assertSame(1, $pic['depassements'], 'Seul le 3e tour franchit les 250 000.');
        $this->assertSame(1, $pic['messagesEnDepassement']);
    }

    /**
     * Cœur de la décision : alléger le bloc invariant ne doit alléger QUE lui.
     * L'historique et les résultats d'outils, eux, ne bougeraient pas — une
     * projection qui les réduirait aussi surestimerait le gain et pousserait à
     * dégrader le prompt pour rien.
     */
    public function testLaProjectionNAllegeQueLeBlocInvariant(): void
    {
        // 100 000 o invariants + 11 000 o d'historique pour 30 000 tokens
        // ⇒ 3,7 o/token. Retirer 20 % de l'invariant (20 000 o) économise
        // 20 000 / 3,7 ≈ 5 405 tokens.
        $lignes = [
            $this->tour('2026-08-08T10:00:00+01:00', 30000, systeme: 40000, outils: 60000, historique: 11000),
        ];
        $rapport = new RapportTokens($lignes);

        $this->assertSame(30000, $rapport->picParMinute()['pic']);
        $this->assertSame(24595, $rapport->picParMinute(0.20)['pic']);
    }

    public function testLaProjectionFaitDisparaitreLesDepassementsQuandElleSuffit(): void
    {
        $lignes = [
            $this->tour('2026-08-08T10:00:00+01:00', 130000, systeme: 200000, outils: 281000, historique: 0),
            $this->tour('2026-08-08T10:00:10+01:00', 130000, systeme: 200000, outils: 281000, historique: 0),
        ];
        $rapport = new RapportTokens($lignes);

        $this->assertSame(1, $rapport->picParMinute()['depassements'], '260 000 dépasse le plafond.');
        $this->assertSame(0, $rapport->picParMinute(0.20)['depassements'], 'Allégé de 20 %, le pic repasse sous la barre.');
    }

    public function testClasseLesOutilsParToursInduits(): void
    {
        $rapport = new RapportTokens([
            $this->message(JournalTokens::ISSUE_REPONSE, 6, 200000, ['preparer_operations', 'rechercher_entites'], 'a'),
            $this->message(JournalTokens::ISSUE_REPONSE, 2, 70000, ['solde_tokens'], 'b'),
        ]);

        $outils = $rapport->outilsLesPlusCouteux();

        $this->assertSame('preparer_operations', $outils[0]['outil']);
        $this->assertEqualsWithDelta(6.0, $outils[0]['toursMoyens'], 0.001);
        $this->assertSame('solde_tokens', $outils[2]['outil']);
        $this->assertEqualsWithDelta(2.0, $outils[2]['toursMoyens'], 0.001);
    }

    public function testCompteLesIssuesEtDetecteUnMelangeDeMoteurs(): void
    {
        $anthropique = $this->message(JournalTokens::ISSUE_REPONSE, 1, 1000, [], 'c');
        $anthropique['moteur'] = 'anthropic';
        $anthropique['modele'] = 'claude-opus-4-8';

        $rapport = new RapportTokens([
            $this->message(JournalTokens::ISSUE_REPONSE, 1, 1000),
            $this->message(JournalTokens::ISSUE_QUOTA_FOURNISSEUR, 3, 110000, [], 'b'),
            $anthropique,
        ]);

        $this->assertSame(
            [JournalTokens::ISSUE_REPONSE => 2, JournalTokens::ISSUE_QUOTA_FOURNISSEUR => 1],
            $rapport->issues(),
        );
        // Deux moteurs dans la fenêtre : la campagne n'est pas homogène.
        $this->assertCount(2, $rapport->moteurs());
    }

    /**
     * Un horodatage inhabituel (microsecondes) ne doit PAS faire disparaître le
     * tour du calcul : le pic serait sous-estimé, et l'on conclurait à tort que
     * le plafond n'est pas atteint.
     */
    public function testUnHorodatageAvecMicrosecondesResteComptabilise(): void
    {
        $rapport = new RapportTokens([
            $this->tour('2026-08-08T10:00:00.123456+01:00', 130000),
            $this->tour('2026-08-08T10:00:10+01:00', 130000),
        ]);

        $this->assertSame(260000, $rapport->picParMinute()['pic']);
        $this->assertSame(1, $rapport->picParMinute()['depassements']);
    }

    public function testPercentile(): void
    {
        $this->assertSame(3.0, RapportTokens::percentile([1, 2, 3, 4, 5], 0.5));
        $this->assertNull(RapportTokens::percentile([], 0.5));
    }

    public function testJournalVideNeCassePas(): void
    {
        $rapport = new RapportTokens([]);

        $this->assertSame([], $rapport->tours());
        $this->assertNull($rapport->ratioOctetsParToken());
        $this->assertNull($rapport->partInvariante());
        $this->assertSame(0, $rapport->picParMinute()['pic']);
    }
}
