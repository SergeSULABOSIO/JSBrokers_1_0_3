<?php

namespace App\Tests\Ai;

use App\Ai\Boussole\BoussoleService;
use App\Comptabilite\SuiviFiscalService;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Saturation\SaturationService;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Tranche\TranchePaiementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * BoussoleService : instantané compact de la chaîne de valeur, FAIL-CLOSED (un axe
 * n'apparaît qu'avec le droit de lecture) et FAIL-SAFE (une exception d'axe est
 * avalée — le chat ne doit jamais casser). La priorité = l'axe actionnable le plus
 * urgent. Tests purs.
 */
class BoussoleServiceTest extends TestCase
{
    /**
     * @param array<string,bool> $droits          canRead par entité
     * @param array{sousSatures?:int}  $saturation config de couverturePortefeuille
     * @param float                    $soldeFiscal solde TVA dû
     * @param bool                     $fiscalThrow SuiviFiscalService::suivi lève une exception
     */
    private function makeService(
        array $droits,
        int $sousSatures = 0,
        float $soldeFiscal = 0.0,
        bool $fiscalThrow = false,
    ): BoussoleService {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('canRead')->willReturnCallback(
            static fn (Invite $invite, string $shortName): bool => $droits[$shortName] ?? false,
        );

        $saturation = $this->createMock(SaturationService::class);
        $saturation->method('couverturePortefeuille')->willReturn([
            'perimetre'            => 'Portefeuille Test',
            'nbClients'            => 10,
            'nbCatalogue'          => 5,
            'tauxMoyen'            => 55.0,
            'nbClientsSatures'     => 10 - $sousSatures,
            'nbClientsSousSatures' => $sousSatures,
            'topOpportunites'      => [['risque' => 'Santé', 'nbClients' => 4]],
        ]);

        $tranche = $this->createMock(TranchePaiementService::class);
        $tranche->method('lister')->willReturn([
            'items' => [], 'totaux' => ['totalSoldePrime' => 0.0, 'totalSoldeCommission' => 0.0, 'totalRetroExigible' => 0.0],
            'totalItems' => 0, 'currentPage' => 1, 'totalPages' => 1,
        ]);

        $fiscal = $this->createMock(SuiviFiscalService::class);
        if ($fiscalThrow) {
            $fiscal->method('suivi')->willThrowException(new \RuntimeException('boom'));
        } else {
            $fiscal->method('suivi')->willReturn(['lignes' => [], 'totaux' => ['solde' => $soldeFiscal]]);
        }

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn(['status' => ['code' => 200], 'data' => [], 'totalItems' => 0]);

        return new BoussoleService(
            $resolver,
            $saturation,
            $tranche,
            $fiscal,
            $search,
            new PortefeuilleCritereFactory($this->createMock(EntityManagerInterface::class)),
        );
    }

    private function axes(array $etat): array
    {
        return array_map(static fn (array $i): string => $i['axe'], $etat['items']);
    }

    public function testFailClosedAucunDroitAucunAxe(): void
    {
        $etat = $this->makeService([])->etat(new Entreprise(), new Invite());

        $this->assertSame([], $etat['items']);
        $this->assertNull($etat['prioritaire']);
    }

    public function testAxeSaturationSeulSiSeulDroitClient(): void
    {
        $etat = $this->makeService(['Client' => true], sousSatures: 4)->etat(new Entreprise(), new Invite());

        $this->assertSame(['saturation'], $this->axes($etat));
        $this->assertNotNull($etat['prioritaire']);
        $this->assertSame('saturation', $etat['prioritaire']['axe']);
    }

    public function testFiscalEstPrioritaireSurSaturation(): void
    {
        $etat = $this->makeService(
            ['Client' => true, 'DocumentComptable' => true],
            sousSatures: 3,
            soldeFiscal: 150.0,
        )->etat(new Entreprise(), new Invite());

        $this->assertContains('saturation', $this->axes($etat));
        $this->assertContains('fiscal', $this->axes($etat));
        // Fiscal (urgence 90) l'emporte sur saturation (50).
        $this->assertSame('fiscal', $etat['prioritaire']['axe']);
    }

    public function testFiscalAJourNestPasActionnable(): void
    {
        $etat = $this->makeService(['DocumentComptable' => true], soldeFiscal: 0.0)->etat(new Entreprise(), new Invite());

        $this->assertSame(['fiscal'], $this->axes($etat));
        $this->assertFalse($etat['items'][0]['actionnable']);
        $this->assertNull($etat['prioritaire']);
    }

    public function testFailSafeAxeQuiLeveEstIgnore(): void
    {
        // Le calcul fiscal lève, mais la saturation reste présente et le chat ne casse pas.
        $etat = $this->makeService(
            ['Client' => true, 'DocumentComptable' => true],
            sousSatures: 2,
            fiscalThrow: true,
        )->etat(new Entreprise(), new Invite());

        $this->assertSame(['saturation'], $this->axes($etat));
        $this->assertSame('saturation', $etat['prioritaire']['axe']);
    }
}
