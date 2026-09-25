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

    /**
     * @param array<string, mixed> $complement
     *
     * @return array<string, mixed>
     */
    private function messageAvecComplement(array $complement, string $messageId = 'm1'): array
    {
        return $this->message('reponse', 2, 60000, [], $messageId) + ['complement' => $complement];
    }

    /** @return array<string, mixed> */
    private function comprehension(string $origine, string $motif = '', string $detail = '', int $tokens = 0): array
    {
        return [
            'evenement'     => 'comprehension',
            'horodatage'    => '2026-09-25T10:00:00+01:00',
            'messageId'     => 'm1',
            'modele'        => 'gemini-3.1-flash-lite',
            'clarte'        => 'claire',
            'origine'       => $origine,
            'motif'         => $motif,
            'detail'        => $detail,
            'tokens'        => $tokens,
            'millisecondes' => 4000,
        ];
    }

    /**
     * L'AIGUILLAGE EST JUGÉ SUR PIÈCES, PAS SUR L'INTENTION DU CODE.
     *
     * Armer la trousse d'écriture coûte dix-neuf déclarations d'outils de plus et
     * vingt-sept kilo-octets de protocoles. Le journal portait de quoi dire si on le
     * paie à bon escient depuis le 2026-09-23 ; rien ne le lisait. Ce test verrouille
     * la lecture : par déclencheur, combien de messages, et combien ont VRAIMENT écrit.
     */
    public function testLAiguillageEstCompteParDeclencheurEtParEcritureReelle(): void
    {
        $rapport = new RapportTokens([
            $this->messageAvecComplement(['trousse' => 'ecriture', 'declencheur' => 'verbe-action', 'ecriture_effective' => false], 'a'),
            $this->messageAvecComplement(['trousse' => 'ecriture', 'declencheur' => 'verbe-action', 'ecriture_effective' => true], 'b'),
            $this->messageAvecComplement(['trousse' => 'lecture', 'declencheur' => 'aucun', 'ecriture_effective' => false], 'c'),
        ]);

        $aiguillages = $rapport->aiguillages();

        self::assertSame(['n' => 2, 'ecrit' => 1], $aiguillages['ecriture / verbe-action']);
        self::assertSame(['n' => 1, 'ecrit' => 0], $aiguillages['lecture / aucun']);
    }

    /**
     * LES MESSAGES D'AVANT L'INSTRUMENTATION NE SONT PAS COMPTÉS COMME « AUCUN ».
     *
     * Ils n'ont pas de `complement` : les ranger sous un déclencheur qu'ils n'ont
     * jamais porté fabriquerait une statistique fausse, et c'est précisément ce
     * genre de chiffre qui fait resserrer la mauvaise règle.
     */
    public function testUnMessageSansComplementEstIgnoreEtNonSupposeAucun(): void
    {
        $rapport = new RapportTokens([
            $this->message('reponse', 2, 60000, [], 'ancien'),
            $this->messageAvecComplement(['trousse' => 'lecture', 'declencheur' => 'aucun', 'ecriture_effective' => false], 'neuf'),
        ]);

        self::assertSame(['lecture / aucun' => ['n' => 1, 'ecrit' => 0]], $rapport->aiguillages());
    }

    /**
     * LE MOT QUI ARME, ET NON LE SEUL NOM DU SIGNAL.
     *
     * `verbe-action` dit qu'une liste de cinquante alternatives a mordu ; il ne dit
     * pas laquelle. Sans ce compte, retirer une alternative revient à tirer au sort.
     */
    public function testLesMotsQuiArmentSontComptesAvecLeurTauxDEcriture(): void
    {
        $rapport = new RapportTokens([
            $this->messageAvecComplement(['trousse' => 'ecriture', 'declencheur' => 'verbe-action', 'mot_armeur' => 'mission', 'ecriture_effective' => false], 'a'),
            $this->messageAvecComplement(['trousse' => 'ecriture', 'declencheur' => 'verbe-action', 'mot_armeur' => 'mission', 'ecriture_effective' => false], 'b'),
            $this->messageAvecComplement(['trousse' => 'ecriture', 'declencheur' => 'verbe-action', 'mot_armeur' => 'enregistr', 'ecriture_effective' => true], 'c'),
            // Aucun mot : ce message ne doit pas peupler le tableau.
            $this->messageAvecComplement(['trousse' => 'lecture', 'declencheur' => 'aucun', 'mot_armeur' => '', 'ecriture_effective' => false], 'd'),
        ]);

        $mots = $rapport->motsQuiArment();

        self::assertSame(['mission', 'enregistr'], array_keys($mots), 'le plus fréquent en tête');
        self::assertSame(['n' => 2, 'ecrit' => 0], $mots['mission']);
        self::assertSame(['n' => 1, 'ecrit' => 1], $mots['enregistr']);
    }

    /**
     * UN REPLI DÉJÀ PAYÉ N'EST PAS UN REPLI GRATUIT.
     *
     * « origine = repli » mettait dans le même sac une panne d'appel (aucun jeton
     * dépensé de notre côté) et une sortie rejetée après coup (l'appel a abouti, et
     * il a été facturé). Mesuré au 2026-09-25 : 33 % des replis étaient du second
     * type, à 16 554 jetons en moyenne — dépensés puis jetés.
     */
    public function testLesMotifsDeRepliSontComptesAvecLesJetonsDejaDepenses(): void
    {
        $rapport = new RapportTokens([
            $this->comprehension('repli', 'appel-echoue', 'http-503'),
            $this->comprehension('repli', 'appel-echoue', 'http-503'),
            $this->comprehension('repli', 'sortie-illisible', '', 16000),
            // Une compréhension RÉUSSIE ne porte aucun motif : elle n'entre pas ici.
            $this->comprehension('modele', '', '', 14000),
        ]);

        $motifs = $rapport->motifsDeRepli();

        self::assertSame(['n' => 2, 'tokens' => 0], $motifs['appel-echoue (http-503)']);
        self::assertSame(['n' => 1, 'tokens' => 16000], $motifs['sortie-illisible']);
        self::assertArrayNotHasKey('', $motifs, 'une compréhension réussie ne fabrique pas un motif vide');
    }

    /**
     * LA CONCLUSION DU RAPPORT SE DÉDUIT DES CHIFFRES.
     *
     * Elle était écrite EN DUR sous le tableau de projection — deux phrases fixes,
     * sans un seul `if`, imprimées à l'identique quels que soient les nombres.
     * Mesuré au 2026-09-25, le texte figé annonçait −20 % là où la réponse était
     * −10 %. Les trois verdicts possibles sont verrouillés ici.
     */
    public function testLaConclusionDeLaProjectionSeDeduitDesChiffres(): void
    {
        $reductions = [0.10, 0.20, 0.30, 0.40];

        // Rien ne dépasse : aucun allègement n'est requis.
        $calme = new RapportTokens([$this->tour('2026-09-25T10:00:00+01:00', 10000)], 250000);
        self::assertSame(0.0, $calme->reductionSuffisante($reductions));

        // Ça dépasse, et un allègement modeste suffit : c'est LUI qu'on doit nommer,
        // pas le premier de la liste ni le plus fort.
        $charge = new RapportTokens([
            $this->tour('2026-09-25T10:00:00+01:00', 130000, 50000, 70000, 7000, 'a'),
            $this->tour('2026-09-25T10:00:10+01:00', 130000, 50000, 70000, 7000, 'b'),
        ], 250000);
        $suffisante = $charge->reductionSuffisante($reductions);
        self::assertNotNull($suffisante);
        self::assertGreaterThan(0.0, $suffisante);

        // Le plafond est si bas qu'aucun allègement du contexte n'y suffit : c'est la
        // simultanéité qui sature, et le rapport doit le dire — pas promettre un gain.
        $sature = new RapportTokens([
            $this->tour('2026-09-25T10:00:00+01:00', 200000, 50000, 70000, 7000, 'a'),
            $this->tour('2026-09-25T10:00:10+01:00', 200000, 50000, 70000, 7000, 'b'),
            $this->tour('2026-09-25T10:00:20+01:00', 200000, 50000, 70000, 7000, 'c'),
        ], 100000);
        self::assertNull($sature->reductionSuffisante($reductions));
    }

    /**
     * LES BASCULES DE SECOURS SONT COMPTÉES, ET GROUPÉES PAR COUPLE.
     *
     * Un 503 ou un 429 fait changer de modèle EN COURS de message. Jusqu’ici cela ne
     * laissait qu’un avertissement dans le journal général, que ce rapport ne lit pas :
     * on savait qu’une fenêtre mélangeait plusieurs modèles, jamais quand ni pourquoi.
     *
     * Le groupement par « quitté → pris (motif) » est ce qui rend le compte utile : dix
     * bascules vers le même secours disent une panne durable du modèle principal, dix
     * bascules éparpillées disent tout autre chose.
     */
    public function testLesBasculesDeSecoursSontCompteesParCouple(): void
    {
        $rapport = new RapportTokens([
            ['evenement' => 'repli', 'abandonne' => 'flash', 'pris' => 'flash-lite', 'motif' => 'indisponible'],
            ['evenement' => 'repli', 'abandonne' => 'flash', 'pris' => 'flash-lite', 'motif' => 'indisponible'],
            ['evenement' => 'repli', 'abandonne' => 'flash', 'pris' => 'pro', 'motif' => 'debit'],
            ['evenement' => 'tour', 'tour' => 1],
        ]);

        self::assertSame([
            'flash → flash-lite (indisponible)' => 2,
            'flash → pro (debit)'          => 1,
        ], $rapport->replis());
    }

    /** Sans bascule, rien à signaler : la section du rapport reste muette. */
    public function testSansBasculeLeCompteEstVide(): void
    {
        self::assertSame([], (new RapportTokens([['evenement' => 'tour', 'tour' => 1]]))->replis());
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
