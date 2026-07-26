<?php

namespace App\Tests\Ai;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Repository\RisqueRepository;
use App\Service\Saturation\SaturationService;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\PortefeuilleCritereFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * SaturationService : la couverture d'un client (taux + opportunités) est la
 * source UNIQUE du cross-selling (partagée avec le SOA). Tests purs — les entités
 * sont construites en mémoire, avec un identifiant posé par réflexion (le calcul
 * indexe les risques du catalogue par id).
 */
class SaturationServiceTest extends TestCase
{
    /** Pose un id sur une entité non persistée (le catalogue est indexé par id). */
    private static function withId(object $entity, int $id): object
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);

        return $entity;
    }

    /** @return Risque[] catalogue de 5 risques r1..r5 */
    private function catalogue(): array
    {
        $catalogue = [];
        for ($i = 1; $i <= 5; $i++) {
            /** @var Risque $r */
            $r = self::withId(new Risque(), $i);
            $r->setNomComplet('Risque ' . $i)->setCode('R' . $i);
            $catalogue[] = $r;
        }

        return $catalogue;
    }

    /** Attache au client une piste sur $risque, avec (éventuellement) un avenant de statut donné. */
    private function ajouterPiste(Client $client, int $pisteId, Risque $risque, bool $closed, ?int $renewalStatus): void
    {
        /** @var Piste $piste */
        $piste = self::withId(new Piste(), $pisteId);
        $piste->setRisque($risque)->setClient($client)->setClosed($closed);

        if ($renewalStatus !== null) {
            $cotation = new Cotation();
            $cotation->setPiste($piste);
            $avenant = (new Avenant())->setRenewalStatus($renewalStatus);
            $avenant->setCotation($cotation);
            $cotation->addAvenant($avenant);
            $piste->addCotation($cotation);
        }

        $client->addPiste($piste);
    }

    private function makeService(array $catalogue): SaturationService
    {
        $risqueRepository = $this->createMock(RisqueRepository::class);
        $risqueRepository->method('findCatalogueForEntreprise')->willReturn($catalogue);

        return new SaturationService(
            $risqueRepository,
            $this->createMock(JSBDynamicSearchService::class),
            new PortefeuilleCritereFactory($this->createMock(EntityManagerInterface::class)),
        );
    }

    public function testCouvertureDeuxSurCinq(): void
    {
        $catalogue = $this->catalogue();
        $service   = $this->makeService($catalogue);

        $client = new Client();
        // r1 et r2 couverts (police valide RUNNING) ; r3, r4, r5 jamais abordés.
        $this->ajouterPiste($client, 1, $catalogue[0], false, Avenant::RENEWAL_STATUS_RUNNING);
        $this->ajouterPiste($client, 2, $catalogue[1], false, Avenant::RENEWAL_STATUS_RUNNING);

        $couverture = $service->couvertureClient($client, new Entreprise());

        $this->assertSame(5, $couverture['nbCatalogue']);
        $this->assertSame(2, $couverture['nbCouverts']);
        $this->assertSame(40.0, $couverture['tauxCouverture']);
        $this->assertCount(3, $couverture['nouveaux']);   // r3, r4, r5
        $this->assertSame([], $couverture['aRelancer']);
    }

    public function testPolicePerdueEtPisteFermeeVontEnARelancer(): void
    {
        $catalogue = $this->catalogue();
        $service   = $this->makeService($catalogue);

        $client = new Client();
        $this->ajouterPiste($client, 1, $catalogue[0], false, Avenant::RENEWAL_STATUS_RUNNING);   // r1 couvert
        $this->ajouterPiste($client, 2, $catalogue[1], false, Avenant::RENEWAL_STATUS_RUNNING);   // r2 couvert
        $this->ajouterPiste($client, 3, $catalogue[2], true, null);                               // r3 piste fermée sans souscription
        $this->ajouterPiste($client, 4, $catalogue[3], true, Avenant::RENEWAL_STATUS_LOST);       // r4 police perdue
        // r5 : jamais abordé.

        $couverture = $service->couvertureClient($client, new Entreprise());

        $this->assertSame(2, $couverture['nbCouverts']);
        $this->assertSame(40.0, $couverture['tauxCouverture']);
        $this->assertCount(1, $couverture['nouveaux']); // r5 seulement
        $this->assertSame(5, $couverture['nouveaux'][0]->getId());

        $motifs = array_map(static fn (array $a): string => $a['motif'], $couverture['aRelancer']);
        sort($motifs);
        $this->assertSame(['Piste(s) fermée(s) sans souscription', 'Police perdue ou résiliée'], $motifs);
    }

    public function testCatalogueVideEstNeutre(): void
    {
        $service = $this->makeService([]);

        $couverture = $service->couvertureClient(new Client(), new Entreprise());

        $this->assertSame(0, $couverture['nbCatalogue']);
        $this->assertSame(0.0, $couverture['tauxCouverture']);
        $this->assertSame([], $couverture['nouveaux']);
        $this->assertSame([], $couverture['aRelancer']);
    }

    public function testOpportunitesIdentiquesAuSousEnsembleDeLaCouverture(): void
    {
        // Non-régression SOA : opportunites() = exactement le couple nouveaux/aRelancer.
        $catalogue = $this->catalogue();
        $service   = $this->makeService($catalogue);

        $client = new Client();
        $this->ajouterPiste($client, 1, $catalogue[0], false, Avenant::RENEWAL_STATUS_RUNNING);
        $this->ajouterPiste($client, 3, $catalogue[2], true, null);

        $entreprise = new Entreprise();
        $opportunites = $service->opportunites($client, $entreprise);
        $couverture   = $service->couvertureClient($client, $entreprise);

        $this->assertSame(
            array_map(static fn (Risque $r): int => $r->getId(), $opportunites['nouveaux']),
            array_map(static fn (Risque $r): int => $r->getId(), $couverture['nouveaux']),
        );
        $this->assertSame(\count($opportunites['aRelancer']), \count($couverture['aRelancer']));
    }
}
