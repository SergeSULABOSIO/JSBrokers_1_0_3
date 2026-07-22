<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\SuiviImpayesTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Tranche;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Search\TranchePaiementScope;
use App\Services\Tranche\TranchePaiementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Outil « suivi_impayes » : fail-closed sur le droit de lecture Tranche, statut par
 * défaut « impayees », validation du rattachement lieA, projection compacte des
 * lignes (soldes prime/commission, retard, urgence) et totaux. Tests purs.
 */
class SuiviImpayesToolTest extends TestCase
{
    private function makeTool(bool $canReadTranche, ?TranchePaiementService $tranchePaiement = null): SuiviImpayesTool
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(['Tranche' => 'Tranches']);
        $resolver->method('canRead')->willReturnCallback(
            static fn (Invite $invite, string $shortName) => $shortName === 'Tranche' && $canReadTranche,
        );

        if ($tranchePaiement === null) {
            $tranchePaiement = $this->createMock(TranchePaiementService::class);
            $tranchePaiement->method('lister')->willReturn([
                'items' => [],
                'totaux' => ['nb' => 0, 'totalPrime' => 0.0, 'totalSoldePrime' => 0.0, 'totalSoldeCommission' => 0.0],
                'totalItems' => 0,
                'currentPage' => 1,
                'totalPages' => 1,
            ]);
        }

        // Fabrique réelle sur un EntityManager muet : l'invité de ces tests purs n'a pas
        // d'identifiant, la fabrique retourne donc un critère vide sans jamais interroger la
        // base — le périmètre portefeuille est neutre ici, ce qui est bien le but.
        $portefeuilleCritere = new PortefeuilleCritereFactory($this->createMock(EntityManagerInterface::class));

        return new SuiviImpayesTool($resolver, $tranchePaiement, $portefeuilleCritere);
    }

    private function makeScope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    public function testFailClosedSansLectureTranche(): void
    {
        $tranchePaiement = $this->createMock(TranchePaiementService::class);
        $tranchePaiement->expects($this->never())->method('lister');

        $tool = $this->makeTool(false, $tranchePaiement);
        $result = $tool->execute([], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
    }

    public function testStatutParDefautImpayees(): void
    {
        $tranchePaiement = $this->createMock(TranchePaiementService::class);
        $tranchePaiement->expects($this->once())
            ->method('lister')
            ->with($this->anything(), TranchePaiementScope::STATUT_IMPAYEES, null, null, 1, $this->anything())
            ->willReturn([
                'items' => [],
                'totaux' => ['nb' => 0, 'totalPrime' => 0.0, 'totalSoldePrime' => 0.0, 'totalSoldeCommission' => 0.0],
                'totalItems' => 0,
                'currentPage' => 1,
                'totalPages' => 1,
            ]);

        $result = $this->makeTool(true, $tranchePaiement)->execute([], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('Impayées', $result->data['statut']);
        $this->assertArrayNotHasKey('tronque', $result->data);
    }

    public function testStatutInconnuIntrouvable(): void
    {
        $result = $this->makeTool(true)->execute(['statut' => 'inconnu'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    public function testLieAInvalideIntrouvable(): void
    {
        $tool = $this->makeTool(true);

        $result = $tool->execute(['lieA' => ['entite' => 'Piste', 'id' => 4]], $this->makeScope());
        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);

        $result = $tool->execute(['lieA' => ['entite' => 'Client', 'id' => 0]], $this->makeScope());
        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    public function testLieAClientTransmisAuService(): void
    {
        $tranchePaiement = $this->createMock(TranchePaiementService::class);
        $tranchePaiement->expects($this->once())
            ->method('lister')
            ->with($this->anything(), TranchePaiementScope::STATUT_ECHUES, 'Client', 12, 2, $this->anything())
            ->willReturn([
                'items' => [],
                'totaux' => ['nb' => 0, 'totalPrime' => 0.0, 'totalSoldePrime' => 0.0, 'totalSoldeCommission' => 0.0],
                'totalItems' => 0,
                'currentPage' => 2,
                'totalPages' => 2,
            ]);

        $this->makeTool(true, $tranchePaiement)->execute(
            ['statut' => 'echues', 'lieA' => ['entite' => 'Client', 'id' => 12], 'page' => 2],
            $this->makeScope(),
        );
    }

    public function testProjectionCompacteEtTotaux(): void
    {
        $tranche = (new Tranche())
            ->setNom('Tranche 2/4')
            ->setPayableAt(new \DateTimeImmutable('-60 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-15 days'));
        $tranche->clientNom = 'Client Alpha';
        $tranche->cotationNom = 'Cotation X';
        $tranche->statutPaiement = 'Partiellement payée';
        $tranche->urgenceRecouvrement = 'Critique · retard 15 j';
        $tranche->primeTranche = 1000.0;
        $tranche->primeSoldeDue = 400.0;
        $tranche->solde_restant_du = -5.0; // trop-perçu commission : restitué à 0

        $tranchePaiement = $this->createMock(TranchePaiementService::class);
        $tranchePaiement->method('lister')->willReturn([
            'items' => [$tranche],
            'totaux' => ['nb' => 3, 'totalPrime' => 3000.0, 'totalSoldePrime' => 1200.0, 'totalSoldeCommission' => 90.0],
            'totalItems' => 3,
            'currentPage' => 1,
            'totalPages' => 1,
        ]);

        $result = $this->makeTool(true, $tranchePaiement)->execute([], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $ligne = $result->data['lignes'][0];
        $this->assertSame('Tranche 2/4', $ligne['tranche']);
        $this->assertSame('Client Alpha', $ligne['client']);
        $this->assertSame(15, $ligne['joursRetard']);
        $this->assertSame(400.0, $ligne['soldePrime']);
        $this->assertSame(0.0, $ligne['soldeCommission']);
        $this->assertSame('Critique · retard 15 j', $ligne['urgence']);
        $this->assertSame(1200.0, $result->data['totaux']['totalSoldePrime']);
        $this->assertSame(3, $result->data['total']);
        $this->assertTrue($result->data['tronque']);
        $this->assertNull($result->uiAction);
    }

    public function testMatchDeclencheursEtNonCollisions(): void
    {
        $tool = $this->makeTool(true);
        $scope = $this->makeScope();

        $this->assertSame(
            ['statut' => TranchePaiementScope::STATUT_IMPAYEES],
            $tool->match('Montre-moi les impayés', $scope),
        );
        $this->assertSame(
            ['statut' => TranchePaiementScope::STATUT_ECHUES],
            $tool->match('Quelles primes en retard dois-je relancer ?', $scope),
        );
        $this->assertSame(
            ['statut' => TranchePaiementScope::STATUT_IMPAYEES],
            $tool->match('Quelles relances dois-je préparer ?', $scope),
        );

        // Commissions devenues collectables (prime payée par l'assuré).
        $this->assertSame(
            ['statut' => TranchePaiementScope::STATUT_COMMISSION_EXIGIBLE],
            $tool->match('Quelles commissions sont exigibles ?', $scope),
        );
        $this->assertSame(
            ['statut' => TranchePaiementScope::STATUT_COMMISSION_EXIGIBLE],
            $tool->match('Quelles commissions puis-je collecter auprès de l\'assureur ?', $scope),
        );

        // Flux inverse : rétrocommissions à verser aux partenaires.
        $this->assertSame(
            ['statut' => TranchePaiementScope::STATUT_RETRO_A_PAYER],
            $tool->match('Quelles rétrocommissions dois-je payer ?', $scope),
        );
        $this->assertSame(
            ['statut' => TranchePaiementScope::STATUT_RETRO_A_PAYER],
            $tool->match('Quels soldes dus aux partenaires ?', $scope),
        );

        // Domaine d'autres outils : liste => rechercher_entites, brief => vigie.
        $this->assertNull($tool->match('Liste les tranches', $scope));
        $this->assertNull($tool->match('Donne-moi le brief du jour', $scope));
        $this->assertNull($tool->match('Combien de clients avons-nous ?', $scope));
    }

    public function testProjectionSignaleRetroAPayer(): void
    {
        $tranche = (new Tranche())
            ->setNom('Tranche soldée')
            ->setPayableAt(new \DateTimeImmutable('-90 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-30 days'));
        $tranche->statutPaiement = 'Payée';
        $tranche->urgenceRecouvrement = 'Réglée';
        $tranche->primeSoldeDue = 0.0;
        $tranche->solde_restant_du = 0.0;
        $tranche->retroCommissionExigible = 75.5;

        $tranchePaiement = $this->createMock(TranchePaiementService::class);
        $tranchePaiement->method('lister')->willReturn([
            'items' => [$tranche],
            'totaux' => ['nb' => 1, 'totalPrime' => 500.0, 'totalSoldePrime' => 0.0, 'totalSoldeCommission' => 0.0, 'totalRetroExigible' => 75.5],
            'totalItems' => 1,
            'currentPage' => 1,
            'totalPages' => 1,
        ]);

        $result = $this->makeTool(true, $tranchePaiement)
            ->execute(['statut' => 'retro_a_payer'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('Rétro partenaire à payer', $result->data['statut']);
        $this->assertSame(75.5, $result->data['lignes'][0]['retroAPayer']);
        $this->assertSame(75.5, $result->data['totaux']['totalRetroExigible']);
    }
}
