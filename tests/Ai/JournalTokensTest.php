<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\OutilsDePlan;
use App\Ai\AiRequest;
use App\Ai\Scope\AiScope;
use App\Ai\Telemetrie\JournalTokens;
use App\Entity\Entreprise;
use App\Entity\Invite;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Télémétrie des tokens de l'assistant. Ce que ces tests protègent, c'est la
 * capacité à DÉCIDER : sans corrélation entre les tours et leur message, sans
 * répartition des octets et sans issue nommée, la campagne produirait des
 * chiffres inexploitables — et l'arbitrage entre alléger le contexte et
 * relever le plafond resterait une intuition.
 */
class JournalTokensTest extends TestCase
{
    /** @var list<array{message: string, context: array}> */
    private array $enregistrements = [];

    private function journal(): JournalTokens
    {
        $espion = new class($this->enregistrements) extends AbstractLogger {
            public function __construct(private array &$enregistrements)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->enregistrements[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        return new JournalTokens($espion, new OutilsDePlan([]));
    }

    private function request(): AiRequest
    {
        return new AiRequest(
            systemContext: ['assistantNom' => 'Ket', 'entrepriseNom' => 'Courtage Test', 'perimetre' => [], 'date' => '2026-08-08'],
            messages: [['role' => 'user', 'content' => 'Combien de clients ?']],
            scope: new AiScope(new Entreprise(), new Invite()),
        );
    }

    public function testLaLigneDeTourPorteTokensEtRepartitionDesOctets(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->tour(
            $this->request(),
            'gemini',
            'gemini-flash-lite-latest',
            1,
            ['entree' => 36000, 'sortie' => 250, 'cache' => 12000],
            ['systeme' => 54649, 'outils' => 72407, 'historique' => 7326],
            ['rechercher_entites'],
        );

        $contexte = $this->enregistrements[0]['context'];

        $this->assertSame('tour', $contexte['evenement']);
        $this->assertSame('gemini', $contexte['moteur']);
        $this->assertSame('gemini-flash-lite-latest', $contexte['modele']);
        $this->assertSame(1, $contexte['tour']);
        $this->assertSame(36000, $contexte['tokensEntree']);
        $this->assertSame(250, $contexte['tokensSortie']);
        // Les tokens servis par le cache du fournisseur comptent MALGRÉ TOUT
        // dans le quota : la colonne mesure l'économie de facture, pas un
        // desserrement du plafond.
        $this->assertSame(12000, $contexte['tokensCache']);
        $this->assertSame(54649, $contexte['octetsSysteme']);
        $this->assertSame(72407, $contexte['octetsOutils']);
        $this->assertSame(7326, $contexte['octetsHistorique']);
        $this->assertSame(['rechercher_entites'], $contexte['outils']);
        $this->assertNotEmpty($contexte['horodatage']);
    }

    /**
     * Sans identifiant commun, deux utilisateurs écrivant en même temps
     * produiraient des tours entrelacés impossibles à rattacher à leur message.
     */
    public function testLesToursEtLeurMessagePartagentUnIdentifiant(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->tour($this->request(), 'gemini', 'm', 1, ['entree' => 100], []);
        $journal->message($this->request(), 'gemini', 'm', JournalTokens::ISSUE_REPONSE, 1, 100);

        $idTour = $this->enregistrements[0]['context']['messageId'];
        $idMessage = $this->enregistrements[1]['context']['messageId'];

        $this->assertNotNull($idTour);
        $this->assertSame($idTour, $idMessage);
    }

    public function testUnNouveauMessageChangeDIdentifiantEtRemetLesCompteursAZero(): void
    {
        $journal = $this->journal();

        $journal->nouveauMessage();
        $journal->tour($this->request(), 'gemini', 'm', 1, ['entree' => 40000, 'sortie' => 10], []);
        $premier = $this->enregistrements[0]['context']['messageId'];

        $journal->nouveauMessage();
        $journal->tour($this->request(), 'gemini', 'm', 1, ['entree' => 5000, 'sortie' => 3], []);
        $journal->messageInterrompu($this->request(), 'gemini', 'm', JournalTokens::ISSUE_QUOTA_FOURNISSEUR);

        $second = $this->enregistrements[1]['context']['messageId'];
        $bilan = $this->enregistrements[2]['context'];

        $this->assertNotSame($premier, $second);
        $this->assertSame(1, $bilan['tours'], 'Les tours du message précédent ne doivent pas fuiter.');
        $this->assertSame(5000, $bilan['cumulEntree']);
        $this->assertSame(3, $bilan['cumulSortie']);
    }

    /**
     * Un 429 remonte jusqu'au contrôleur, qui ignore combien de tours ont déjà
     * été payés. Ce sont pourtant les messages les plus coûteux : s'ils
     * manquaient à la campagne, elle sous-estimerait exactement le problème
     * qu'elle cherche à mesurer.
     */
    public function testLeMessageInterrompuRestitueLesToursDejaPayes(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->tour($this->request(), 'gemini', 'm', 1, ['entree' => 36000, 'sortie' => 100], []);
        $journal->tour($this->request(), 'gemini', 'm', 2, ['entree' => 38000, 'sortie' => 120], []);
        $journal->messageInterrompu(
            $this->request(),
            'gemini',
            'm',
            JournalTokens::ISSUE_QUOTA_FOURNISSEUR,
            ['quotaId' => 'GenerateContentInputTokensPerModelPerMinute-FreeTier', 'retryApres' => 47],
        );

        $bilan = $this->enregistrements[2]['context'];

        $this->assertSame('message', $bilan['evenement']);
        $this->assertSame(JournalTokens::ISSUE_QUOTA_FOURNISSEUR, $bilan['issue']);
        $this->assertSame(2, $bilan['tours']);
        $this->assertSame(74000, $bilan['cumulEntree']);
        $this->assertSame(220, $bilan['cumulSortie']);
        $this->assertSame(47, $bilan['complement']['retryApres']);
    }

    public function testLeComplementEstOmisQuandIlEstVide(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->message($this->request(), 'gemini', 'm', JournalTokens::ISSUE_REPONSE, 1, 100);

        $this->assertArrayNotHasKey('complement', $this->enregistrements[0]['context']);
    }
}
