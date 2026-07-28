<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\DetailDepensesTool;
use App\Entity\ChargeCourtier;
use App\Entity\DepenseCourtier;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\DepenseCourtierRepository;
use App\Service\Workspace\WorkspaceAccessResolver;
use PHPUnit\Framework\TestCase;

/**
 * detail_depenses : descend au niveau de chaque dépense/achat du cabinet et livre
 * par ligne la TVA DÉDUCTIBLE (récupérable) = TTC − HT, avec ventilation par compte
 * OHADA et totaux — là où document_comptable ne donne que l'agrégat mensuel.
 * FAIL-CLOSED : sans droit de lecture sur les Dépenses, rien n'existe.
 */
class DetailDepensesToolTest extends TestCase
{
    /** @param DepenseCourtier[] $depenses */
    private function makeTool(array $depenses, bool $canRead = true, ?array &$captured = null): DetailDepensesTool
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('canRead')->willReturn($canRead);
        $resolver->method('libellesEntites')->willReturn(['DepenseCourtier' => 'Dépenses']);

        $repo = $this->createMock(DepenseCourtierRepository::class);
        $repo->method('findPourDetail')->willReturnCallback(
            function (int $eid, $du, $au, bool $tvaSeulement) use ($depenses, &$captured): array {
                $captured = ['du' => $du, 'au' => $au, 'tvaSeulement' => $tvaSeulement];

                return $depenses;
            },
        );

        return new DetailDepensesTool($resolver, $repo);
    }

    private function makeCharge(string $compteOhada, string $libelle): ChargeCourtier
    {
        return (new ChargeCourtier())->setCode($compteOhada)->setLibelle($libelle)->setCompteOhada($compteOhada);
    }

    private function makeDepense(ChargeCourtier $charge, string $montantTtc, string $tauxTva, string $date, string $tiers): DepenseCourtier
    {
        return (new DepenseCourtier())
            ->setCharge($charge)
            ->setMontant($montantTtc)
            ->setTauxTva($tauxTva)
            ->setDateDepense(new \DateTimeImmutable($date))
            ->setBeneficiaire($tiers);
    }

    private function scope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    public function testDetailLignesTvaDeductibleEtTotaux(): void
    {
        $achats = $this->makeCharge('60', 'Fournitures');
        $services = $this->makeCharge('61', 'Services extérieurs');
        $depenses = [
            $this->makeDepense($achats, '116.00', '16.00', '2026-07-05', 'Papeterie'),   // HT 100, TVA 16, TTC 116
            $this->makeDepense($services, '200.00', '0.00', '2026-07-10', 'Bailleur'),    // HT 200, TVA 0,  TTC 200
        ];

        $result = $this->makeTool($depenses)->execute([], $this->scope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);

        // Totaux sur toute la période : HT 300, TVA déductible 16, TTC 316, 2 lignes.
        $this->assertSame(300.0, $result->data['totaux']['ht']);
        $this->assertSame(16.0, $result->data['totaux']['tvaDeductible']);
        $this->assertSame(316.0, $result->data['totaux']['ttc']);
        $this->assertSame(2, $result->data['totaux']['nb']);

        // La ligne à TVA déductible expose bien 16.
        $ligneTva = array_values(array_filter($result->data['lignes'], static fn ($l) => ($l['tvaDeductible'] ?? 0) > 0));
        $this->assertCount(1, $ligneTva);
        $this->assertSame(16.0, $ligneTva[0]['tvaDeductible']);
        $this->assertSame(100.0, $ligneTva[0]['ht']);

        // Ventilation par compte OHADA : deux comptes.
        $this->assertCount(2, $result->data['ventilationParCompte']);
    }

    public function testTvaDeductibleSeulementEstTransmisAuDepot(): void
    {
        $captured = null;
        $tool = $this->makeTool([], true, $captured);

        $tool->execute(['tvaDeductibleSeulement' => true, 'du' => '2026-07-01', 'au' => '2026-07-31'], $this->scope());

        $this->assertTrue($captured['tvaSeulement']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $captured['du']);
        $this->assertSame('2026-07-01', $captured['du']->format('Y-m-d'));
        // Borne de fin inclusive (fin de journée).
        $this->assertSame('2026-07-31 23:59:59', $captured['au']->format('Y-m-d H:i:s'));
    }

    public function testFailClosedSansDroitLecture(): void
    {
        $result = $this->makeTool([], false)->execute([], $this->scope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
    }

    public function testMatchDeclencheSurDepensesEtTvaDeductible(): void
    {
        $tool = $this->makeTool([]);

        $this->assertNotNull($tool->match('Liste moi le détail de mes dépenses et achats', $this->scope()));
        $this->assertSame(true, $tool->match('donne le détail de la TVA déductible par facture', $this->scope())['tvaDeductibleSeulement'] ?? null);
        // Une question de CA n'appartient pas à cet outil.
        $this->assertNull($tool->match('quel est mon chiffre d\'affaires ?', $this->scope()));
    }
}
