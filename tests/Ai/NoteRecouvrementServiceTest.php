<?php

namespace App\Tests\Ai;

use App\Entity\Entreprise;
use App\Entity\Note;
use App\Services\CanvasBuilder;
use App\Services\Note\NoteRecouvrementService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * NoteRecouvrementService : les commissions FACTURÉES aux assureurs et non encore
 * encaissées. Le solde d'une note n'étant jamais stocké (dérivé par
 * NoteIndicatorStrategy), le filtrage et le tri se font en mémoire — c'est cette
 * logique-là que ces tests verrouillent. Le CanvasBuilder est neutralisé et les
 * soldes posés à la main : on teste le service, pas le calcul d'indicateurs.
 */
class NoteRecouvrementServiceTest extends TestCase
{
    private function note(int $id, float $solde, ?string $envoyeeLe, float $total = 0.0, float $paye = 0.0): Note
    {
        $note = new Note();
        $ref = new \ReflectionProperty(Note::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($note, $id);

        if ($envoyeeLe !== null) {
            $note->setSentAt(new \DateTimeImmutable($envoyeeLe));
        }

        $note->solde = $solde;
        $note->montantTotal = $total;
        $note->montantPaye = $paye;

        return $note;
    }

    /** @param Note[] $notes Ce que la requête SQL bornée est censée remonter. */
    private function makeService(array $notes): NoteRecouvrementService
    {
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn($notes);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($qb);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        // Les indicateurs sont déjà posés sur les objets de test.
        $canvas = $this->createMock(CanvasBuilder::class);

        return new NoteRecouvrementService($canvas, $em);
    }

    public function testNoteSoldeeEstExclue(): void
    {
        $service = $this->makeService([
            $this->note(1, 0.0, '-30 days'),
            $this->note(2, 1200.0, '-10 days'),
        ]);

        $resultat = $service->lister(new Entreprise());

        $this->assertSame(1, $resultat['totalItems']);
        $this->assertSame(2, $resultat['items'][0]->getId());
    }

    public function testSoldeResiduelDArrondiEstIgnore(): void
    {
        // Sous le seuil : c'est un arrondi comptable, pas une créance à relancer.
        $resultat = $this->makeService([$this->note(1, 0.004, '-5 days')])->lister(new Entreprise());

        $this->assertSame(0, $resultat['totalItems']);
        $this->assertSame([], $resultat['items']);
    }

    public function testNotePartiellementPayeeEstIncluseAvecSonSolde(): void
    {
        $resultat = $this->makeService([
            $this->note(1, 2150.0, '-45 days', total: 3000.0, paye: 850.0),
        ])->lister(new Entreprise());

        $this->assertSame(1, $resultat['totalItems']);
        $this->assertSame(2150.0, $resultat['totaux']['totalSolde']);
        $this->assertSame(3000.0, $resultat['totaux']['totalFacture']);
        $this->assertSame(850.0, $resultat['totaux']['totalEncaisse']);
    }

    public function testTriParAncienneteLesPlusVieillesDabord(): void
    {
        $resultat = $this->makeService([
            $this->note(1, 100.0, '-5 days'),
            $this->note(2, 100.0, '-90 days'),
            $this->note(3, 100.0, '-30 days'),
        ])->lister(new Entreprise());

        $this->assertSame([2, 3, 1], array_map(
            static fn (Note $n): int => $n->getId(),
            $resultat['items'],
        ));
    }

    public function testTotauxPortentSurLEnsembleFiltrePasSurLaPage(): void
    {
        $notes = [];
        for ($i = 1; $i <= 5; ++$i) {
            $notes[] = $this->note($i, 100.0, sprintf('-%d days', $i * 10));
        }

        $resultat = $this->makeService($notes)->lister(new Entreprise(), 1, 2);

        $this->assertCount(2, $resultat['items'], 'La page est bornée…');
        $this->assertSame(5, $resultat['totalItems'], '…mais le compte porte sur tout le filtre.');
        $this->assertSame(500.0, $resultat['totaux']['totalSolde']);
        $this->assertSame(3, $resultat['totalPages']);
    }

    public function testAncienneteRepliSurLaDateDeCreationSansEnvoi(): void
    {
        $service = $this->makeService([]);
        $jour = new \DateTimeImmutable('2026-08-02');

        $envoyee = $this->note(1, 100.0, '2026-07-03');
        $this->assertSame(30, $service->joursAnciennete($envoyee, $jour));

        // Jamais envoyée : on retombe sur createdAt (posé par AuditableTrait).
        $brouillon = $this->note(2, 100.0, null);
        $brouillon->setCreatedAt(new \DateTimeImmutable('2026-07-23'));
        $this->assertSame(10, $service->joursAnciennete($brouillon, $jour));
    }

    public function testAncienneteNulleSansAucuneDate(): void
    {
        $this->assertNull(
            $this->makeService([])->joursAnciennete($this->note(1, 100.0, null), new \DateTimeImmutable('2026-08-02'))
        );
    }
}
