<?php

namespace App\Tests\Services;

use App\Exception\RefusMetierException;
use App\Service\Erreur\MessageDErreur;
use App\Service\Workspace\MutationException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * LE TEXTE D'UNE ERREUR TECHNIQUE NE SORT PAS ; CELUI DU MÉTIER, SI.
 */
class MessageDErreurTest extends TestCase
{
    /** @var list<array{niveau: string, contexte: array}> */
    private array $journal = [];

    private function service(): MessageDErreur
    {
        $journal = &$this->journal;

        return new MessageDErreur(new class($journal) extends AbstractLogger {
            public function __construct(private array &$journal)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->journal[] = ['niveau' => (string) $level, 'contexte' => $context];
            }
        });
    }

    public function testUnRefusDuMetierEstRelayeMotPourMot(): void
    {
        $service = $this->service();

        self::assertSame('Aucune donnée à exporter.', $service->pourLEcran(new RefusMetierException('Aucune donnée à exporter.'), 'repli'));
        self::assertSame('Note intangible.', $service->pourLEcran(new \DomainException('Note intangible.'), 'repli'));
        self::assertSame([], $this->journal, 'Un refus voulu n\'est pas une panne : rien au journal.');
    }

    public function testUneMutationRefuseeEstUnMessageDuMetier(): void
    {
        self::assertTrue(MessageDErreur::estPourLUtilisateur(MutationException::introuvable('Ce client est introuvable.')));
    }

    public function testUnePanneEstMasqueeEtJournalisee(): void
    {
        $panne = new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1451 … `bdm`.`note`');

        $texte = $this->service()->pourLEcran($panne, 'L\'import s\'est arrêté. L\'équipe Joseara est prévenue.');

        self::assertSame('L\'import s\'est arrêté. L\'équipe Joseara est prévenue.', $texte);
        self::assertCount(1, $this->journal);
        self::assertSame('error', $this->journal[0]['niveau'], 'ERROR : le niveau que la supervision recueille.');
        self::assertSame($panne, $this->journal[0]['contexte']['exception']);
    }
}
