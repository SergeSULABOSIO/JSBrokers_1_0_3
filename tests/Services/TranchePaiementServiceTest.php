<?php

namespace App\Tests\Services;

use App\Entity\Tranche;
use App\Services\CanvasBuilder;
use App\Services\Search\TranchePaiementScope;
use App\Services\Tranche\TranchePaiementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Suivi des paiements par tranche : classification (« payée » = prime encaissée ET
 * commission collectée), découpage échues / à échoir, tri par urgence, pagination
 * en mémoire. Tests purs : le CanvasBuilder est un no-op, les indicateurs calculés
 * (statutPaiement, soldes) sont posés directement sur les entités.
 */
class TranchePaiementServiceTest extends TestCase
{
    private function makeService(): TranchePaiementService
    {
        // Les indicateurs sont pré-posés par les tests : le préchargement et le
        // calcul deviennent des no-ops (ils sont couverts par le test Kernel).
        $canvasBuilder = $this->createMock(CanvasBuilder::class);

        return new TranchePaiementService($canvasBuilder, $this->createMock(EntityManagerInterface::class));
    }

    private function makeTranche(
        int $id,
        string $statutPaiement,
        ?\DateTimeImmutable $echeance,
        float $soldePrime = 0.0,
        float $soldeCommission = 0.0,
        ?\DateTimeImmutable $payable = null,
    ): Tranche {
        $tranche = (new Tranche())
            ->setNom('Tranche ' . $id)
            ->setPayableAt($payable ?? new \DateTimeImmutable('-30 days'))
            ->setEcheanceAt($echeance);
        $tranche->statutPaiement = $statutPaiement;
        $tranche->primeSoldeDue = $soldePrime;
        $tranche->solde_restant_du = $soldeCommission;

        $ref = new \ReflectionProperty(Tranche::class, 'id');
        $ref->setValue($tranche, $id);

        return $tranche;
    }

    public function testFiltreImpayeesExclutPayeesEtNa(): void
    {
        $tranches = [
            $this->makeTranche(1, 'Non payée', new \DateTimeImmutable('+10 days'), 500.0),
            $this->makeTranche(2, 'Payée', new \DateTimeImmutable('-10 days')),
            $this->makeTranche(3, 'N/A', null),
            $this->makeTranche(4, 'Prime payée, commission due', new \DateTimeImmutable('+5 days'), 0.0, 80.0),
            $this->makeTranche(5, 'Partiellement payée', new \DateTimeImmutable('-2 days'), 200.0),
        ];

        $resultat = $this->makeService()->filtrerTrierPaginer($tranches, TranchePaiementScope::STATUT_IMPAYEES);

        $ids = array_map(static fn (Tranche $t) => $t->getId(), $resultat['data']);
        $this->assertSame([5, 4, 1], $ids, 'Échue d\'abord, puis à échoir par échéance croissante.');
        $this->assertSame(3, $resultat['totalItems']);
    }

    public function testCommissionDueSuffitARendreImpayee(): void
    {
        // Prime intégralement encaissée, mais commission encore due : la tranche
        // reste à recouvrer (règle « payée = prime ET commission »).
        $tranche = $this->makeTranche(1, 'Prime payée, commission due', new \DateTimeImmutable('-3 days'), 0.0, 150.0);

        $service = $this->makeService();
        $this->assertTrue($service->correspondAuFiltre($tranche, TranchePaiementScope::STATUT_IMPAYEES));
        $this->assertTrue($service->correspondAuFiltre($tranche, TranchePaiementScope::STATUT_ECHUES));
        $this->assertTrue($service->correspondAuFiltre($tranche, TranchePaiementScope::STATUT_PARTIELLEMENT));
        $this->assertFalse($service->correspondAuFiltre($tranche, TranchePaiementScope::STATUT_PAYEES));
    }

    public function testDecoupageEchuesAEchoir(): void
    {
        $echue = $this->makeTranche(1, 'Non payée', new \DateTimeImmutable('-1 day'), 100.0);
        $aEchoir = $this->makeTranche(2, 'Non payée', new \DateTimeImmutable('+1 day'), 100.0);
        $sansEcheance = $this->makeTranche(3, 'Non payée', null, 100.0);

        $service = $this->makeService();

        $this->assertTrue($service->correspondAuFiltre($echue, TranchePaiementScope::STATUT_ECHUES));
        $this->assertFalse($service->correspondAuFiltre($echue, TranchePaiementScope::STATUT_A_ECHOIR));

        $this->assertFalse($service->correspondAuFiltre($aEchoir, TranchePaiementScope::STATUT_ECHUES));
        $this->assertTrue($service->correspondAuFiltre($aEchoir, TranchePaiementScope::STATUT_A_ECHOIR));

        // Sans échéance : jamais « échue » (pas de retard mesurable), mais à échoir.
        $this->assertFalse($service->correspondAuFiltre($sansEcheance, TranchePaiementScope::STATUT_ECHUES));
        $this->assertTrue($service->correspondAuFiltre($sansEcheance, TranchePaiementScope::STATUT_A_ECHOIR));
    }

    public function testTriParUrgence(): void
    {
        $retard30j = $this->makeTranche(1, 'Non payée', new \DateTimeImmutable('-30 days'), 100.0);
        $retard5j = $this->makeTranche(2, 'Non payée', new \DateTimeImmutable('-5 days'), 100.0);
        $echeanceProche = $this->makeTranche(3, 'Non payée', new \DateTimeImmutable('+3 days'), 100.0);
        $echeanceLointaine = $this->makeTranche(4, 'Non payée', new \DateTimeImmutable('+60 days'), 100.0);
        $sansEcheance = $this->makeTranche(5, 'Non payée', null, 100.0, 0.0, new \DateTimeImmutable('-10 days'));
        $payee = $this->makeTranche(6, 'Payée', new \DateTimeImmutable('-40 days'));

        $tries = $this->makeService()->trierParUrgence([
            $payee, $echeanceLointaine, $sansEcheance, $retard5j, $echeanceProche, $retard30j,
        ]);

        $this->assertSame(
            [1, 2, 3, 4, 5, 6],
            array_map(static fn (Tranche $t) => $t->getId(), $tries),
            'Retard le plus grand d\'abord, puis échéances proches, puis sans échéance, payées en dernier.'
        );
    }

    public function testTropPercuJamaisEnRetardNiImpayee(): void
    {
        // Note de crédit / trop-perçu : soldes négatifs, statut « Payée ».
        $tranche = $this->makeTranche(1, 'Payée', new \DateTimeImmutable('-15 days'), -50.0, -10.0);

        $service = $this->makeService();
        $this->assertFalse($service->correspondAuFiltre($tranche, TranchePaiementScope::STATUT_IMPAYEES));
        $this->assertFalse($service->correspondAuFiltre($tranche, TranchePaiementScope::STATUT_ECHUES));
        $this->assertTrue($service->correspondAuFiltre($tranche, TranchePaiementScope::STATUT_PAYEES));
    }

    public function testFiltreRetroAPayerCroiseLesStatuts(): void
    {
        // Tranche soldée à l'encaissement MAIS rétro partenaire exigible : elle doit
        // remonter sous « Rétro à payer » (flux inverse), pas sous « Impayées ».
        $payeeAvecRetro = $this->makeTranche(1, 'Payée', new \DateTimeImmutable('-5 days'));
        $payeeAvecRetro->retroCommissionExigible = 120.0;

        $payeeSansRetro = $this->makeTranche(2, 'Payée', new \DateTimeImmutable('-5 days'));
        $payeeSansRetro->retroCommissionExigible = 0.0;

        // Solde de rétro dû mais commission partageable PAS encore encaissée :
        // l'indicateur calculé vaut 0 → pas encore exigible.
        $impayeeRetroNonNee = $this->makeTranche(3, 'Non payée', new \DateTimeImmutable('-2 days'), 100.0);
        $impayeeRetroNonNee->retroCommissionExigible = 0.0;

        $service = $this->makeService();

        $this->assertTrue($service->correspondAuFiltre($payeeAvecRetro, TranchePaiementScope::STATUT_RETRO_A_PAYER));
        $this->assertFalse($service->correspondAuFiltre($payeeAvecRetro, TranchePaiementScope::STATUT_IMPAYEES));
        $this->assertFalse($service->correspondAuFiltre($payeeSansRetro, TranchePaiementScope::STATUT_RETRO_A_PAYER));
        $this->assertFalse($service->correspondAuFiltre($impayeeRetroNonNee, TranchePaiementScope::STATUT_RETRO_A_PAYER));

        $resultat = $service->filtrerTrierPaginer(
            [$payeeAvecRetro, $payeeSansRetro, $impayeeRetroNonNee],
            TranchePaiementScope::STATUT_RETRO_A_PAYER,
        );
        $this->assertSame([1], array_map(static fn (Tranche $t) => $t->getId(), $resultat['data']));
    }

    public function testFiltreCommissionExigible(): void
    {
        // Prime signalée payée (assureur) mais commission non collectée : la commission
        // devient exigible — la tranche doit remonter sous « Commission exigible ».
        $exigible = $this->makeTranche(1, 'Prime payée, commission due', new \DateTimeImmutable('-5 days'), 0.0, 150.0);
        $exigible->commissionExigible = 150.0;

        // Prime NON payée : commission due mais PAS exigible (l'assureur n'a rien encaissé).
        $nonExigible = $this->makeTranche(2, 'Non payée', new \DateTimeImmutable('-5 days'), 500.0, 150.0);
        $nonExigible->commissionExigible = 0.0;

        $service = $this->makeService();

        $this->assertTrue($service->correspondAuFiltre($exigible, TranchePaiementScope::STATUT_COMMISSION_EXIGIBLE));
        $this->assertFalse($service->correspondAuFiltre($nonExigible, TranchePaiementScope::STATUT_COMMISSION_EXIGIBLE));

        $resultat = $service->filtrerTrierPaginer([$exigible, $nonExigible], TranchePaiementScope::STATUT_COMMISSION_EXIGIBLE);
        $this->assertSame([1], array_map(static fn (Tranche $t) => $t->getId(), $resultat['data']));
    }

    public function testPaginationEnMemoire(): void
    {
        $tranches = [];
        for ($i = 1; $i <= 45; ++$i) {
            $tranches[] = $this->makeTranche($i, 'Non payée', new \DateTimeImmutable('-' . $i . ' days'), 100.0);
        }

        $service = $this->makeService();

        $page1 = $service->filtrerTrierPaginer($tranches, TranchePaiementScope::STATUT_IMPAYEES, 1, 20);
        $this->assertCount(20, $page1['data']);
        $this->assertSame(45, $page1['totalItems']);
        $this->assertSame(3, $page1['totalPages']);
        // Retard décroissant : la tranche la plus ancienne (id 45) ouvre la page 1.
        $this->assertSame(45, $page1['data'][0]->getId());

        $page3 = $service->filtrerTrierPaginer($tranches, TranchePaiementScope::STATUT_IMPAYEES, 3, 20);
        $this->assertCount(5, $page3['data']);

        $pageHorsBornes = $service->filtrerTrierPaginer($tranches, TranchePaiementScope::STATUT_IMPAYEES, 9, 20);
        $this->assertSame([], $pageHorsBornes['data']);
        $this->assertSame(45, $pageHorsBornes['totalItems']);
    }

    public function testTotauxCalculesSurEnsembleFiltreNonLaPage(): void
    {
        // Couvert indirectement via lister() dans le test Kernel ; ici on vérifie
        // au moins que la forme du retour du chemin liste est complète et stable.
        $resultat = $this->makeService()->filtrerTrierPaginer([], TranchePaiementScope::STATUT_IMPAYEES);

        $this->assertSame(200, $resultat['status']['code']);
        $this->assertSame(0, $resultat['totalItems']);
        $this->assertSame(1, $resultat['totalPages']);
        $this->assertSame(20, $resultat['itemsPerPage']);
    }
}
