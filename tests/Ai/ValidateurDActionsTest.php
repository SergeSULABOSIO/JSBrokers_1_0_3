<?php

namespace App\Tests\Ai;

use App\Ai\Action\TypeAction;
use App\Ai\Action\ValidateurDActions;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Le filtre qui empêche une action muette de partir vers le navigateur.
 *
 * FAIL-SAFE ET VISIBLE, dans cet ordre. Une action que le navigateur ne saurait pas
 * exécuter ne sert personne : on l'écarte. Mais on la JOURNALISE — sans quoi le défaut
 * resterait invisible, et c'est précisément ce qui a permis à
 * « signaler-paiement-prime » de survivre après la disparition de son émetteur.
 */
class ValidateurDActionsTest extends TestCase
{
    /** @var array<int, array{message: string, context: array}> */
    private array $journal = [];

    private function validateur(): ValidateurDActions
    {
        $espion = new class($this->journal) extends AbstractLogger {
            public function __construct(private array &$lignes)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->lignes[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        return new ValidateurDActions($espion);
    }

    public function testUneActionCompleteEstConservee(): void
    {
        $actions = [['type' => TypeAction::OUVRIR_RUBRIQUE->value, 'entite' => 'Client']];

        self::assertSame($actions, $this->validateur()->filtrer($actions));
        self::assertSame([], $this->journal, 'Rien à signaler sur une action valide.');
    }

    public function testUnTypeInconnuEstEcarteEtJournalise(): void
    {
        $retenues = $this->validateur()->filtrer([['type' => 'open-sesame']]);

        self::assertSame([], $retenues);
        self::assertCount(1, $this->journal);
        self::assertStringContainsString('type inconnu', $this->journal[0]['message']);
        self::assertSame('open-sesame', $this->journal[0]['context']['type']);
    }

    /**
     * Le navigateur sort par une garde silencieuse quand un champ lui manque :
     * l'action partirait pour ne rien produire.
     */
    public function testUneActionIncompleteEstEcarteeEtLeChampManquantEstNomme(): void
    {
        $retenues = $this->validateur()->filtrer([['type' => TypeAction::OUVRIR_URL->value]]);

        self::assertSame([], $retenues);
        self::assertCount(1, $this->journal);
        self::assertStringContainsString('incomplète', $this->journal[0]['message']);
        self::assertSame(['url'], $this->journal[0]['context']['manquants']);
    }

    /** Une action valide ne doit pas être emportée par la voisine qui ne l'est pas. */
    public function testUneActionFautiveNEmportePasLesAutres(): void
    {
        $valide = ['type' => TypeAction::QUITTER_WORKSPACE->value];

        $retenues = $this->validateur()->filtrer([
            ['type' => 'inconnu'],
            $valide,
            ['type' => TypeAction::OUVRIR_ENVOI_SOA->value], // clientId manquant
        ]);

        self::assertSame([$valide], $retenues);
        self::assertCount(2, $this->journal);
    }

    /**
     * Le plan porte sa propre vérification (PlanEnAttente::planStockable) : le
     * validateur ne doit pas s'en mêler, sous peine de créer une seconde vérité.
     */
    public function testLeValidateurNeJugePasLeContenuDUnPlan(): void
    {
        $plan = ['type' => TypeAction::PLAN_A_VALIDER->value];

        self::assertSame([$plan], $this->validateur()->filtrer([$plan]));
    }
}
