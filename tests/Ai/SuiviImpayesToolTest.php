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
 * Outil « suivi_impayes » : fail-closed sur le droit de lecture Tranche, filtrage par
 * AXES (une dette par débiteur), validation du rattachement lieA, projection compacte
 * des lignes (soldes prime/commission, dette nommée, retard, urgence) et répartition.
 * Tests purs.
 */
class SuiviImpayesToolTest extends TestCase
{
    /** @param array<string, float|int> $totauxSup */
    private function resultatVide(array $totauxSup = [], int $page = 1): array
    {
        return [
            'items' => [],
            'totaux' => $totauxSup + [
                'nb' => 0, 'totalPrime' => 0.0, 'totalSoldePrime' => 0.0,
                'totalSoldeCommission' => 0.0, 'totalRetroExigible' => 0.0,
                'nbPrimeImpayee' => 0, 'nbCommissionImpayee' => 0,
            ],
            'totalItems' => 0,
            'currentPage' => $page,
            'totalPages' => 1,
        ];
    }

    private function makeTool(bool $canReadTranche, ?TranchePaiementService $tranchePaiement = null): SuiviImpayesTool
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(['Tranche' => 'Tranches']);
        $resolver->method('canRead')->willReturnCallback(
            static fn (Invite $invite, string $shortName) => $shortName === 'Tranche' && $canReadTranche,
        );

        if ($tranchePaiement === null) {
            $tranchePaiement = $this->createMock(TranchePaiementService::class);
            $tranchePaiement->method('lister')->willReturn($this->resultatVide());
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

    public function testSansAxeAucunFiltreDeDette(): void
    {
        // Il n'existe plus de statut « impayées » par défaut : ce mot désignait deux dettes
        // à la fois. Sans axe, l'outil ne filtre pas et livre la répartition.
        $tranchePaiement = $this->createMock(TranchePaiementService::class);
        $tranchePaiement->expects($this->once())
            ->method('lister')
            ->with($this->anything(), [], null, null, 1, $this->anything())
            ->willReturn($this->resultatVide());

        $result = $this->makeTool(true, $tranchePaiement)->execute([], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('Toutes les tranches suivies', $result->data['filtre']);
        $this->assertArrayNotHasKey('tronque', $result->data);
    }

    public function testAxesTransmisAuServiceEtLibellesDansLaSortie(): void
    {
        $attendus = [
            TranchePaiementScope::AXE_PRIME => TranchePaiementScope::IMPAYEE,
            TranchePaiementScope::AXE_ECHEANCE => TranchePaiementScope::ECHUE,
        ];

        $tranchePaiement = $this->createMock(TranchePaiementService::class);
        $tranchePaiement->expects($this->once())
            ->method('lister')
            ->with($this->anything(), $attendus, null, null, 1, $this->anything())
            ->willReturn($this->resultatVide());

        $result = $this->makeTool(true, $tranchePaiement)->execute(
            ['axes' => ['prime' => 'impayee', 'echeance' => 'echue']],
            $this->makeScope(),
        );

        // Le filtre appliqué est NOMMÉ axe par axe : le modèle doit pouvoir dire
        // exactement ce qu'il a demandé, sinon un compte redevient ambigu.
        $this->assertSame('Prime impayée · Échues (en retard)', $result->data['filtre']);
    }

    public function testAxeInconnuIgnoreSansCasser(): void
    {
        // Une valeur d'axe hors énumération ne doit pas produire un filtre fantôme :
        // elle est simplement écartée (le schéma la contraint déjà côté modèle).
        $tranchePaiement = $this->createMock(TranchePaiementService::class);
        $tranchePaiement->expects($this->once())
            ->method('lister')
            ->with($this->anything(), [], null, null, 1, $this->anything())
            ->willReturn($this->resultatVide());

        $result = $this->makeTool(true, $tranchePaiement)->execute(
            ['axes' => ['prime' => 'inconnu', 'fantome' => 'impayee']],
            $this->makeScope(),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
    }

    /**
     * NOTE DE CONDUITE. Cet outil de lecture livre toute la matière d'un plan
     * d'écriture (id de tranche, échéance, solde) sans en être un : sans note, Ket a
     * présenté en prose un plan recopié du tour précédent, qu'aucun bouton ne pouvait
     * accompagner (incident du 2026-08-05, série de « le suivant »). La note dit que
     * c'est une lecture, NOMME l'écriture qui la prolonge, et depuis le même incident
     * rappelle qu'un solde de prime nul n'est pas « rien à faire ».
     */
    public function testPorteUneNoteDeConduiteVersLEcriture(): void
    {
        $note = $this->makeTool(true)->execute([], $this->makeScope())->data['note'] ?? '';

        $this->assertStringContainsString('LECTURE SEULE', $note);
        $this->assertStringContainsString('aucun bouton de validation', $note);
        $this->assertStringContainsString('signaler_paiement_prime', $note);
        // Le piège des demandes en série est nommé dans la note elle-même.
        $this->assertStringContainsString('le suivant', $note);
        // Et celui du solde de prime nul lu comme une absence de dette.
        $this->assertStringContainsString('PRIME SOLDÉE', $note);
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
            ->with(
                $this->anything(),
                [TranchePaiementScope::AXE_ECHEANCE => TranchePaiementScope::ECHUE],
                'Client',
                12,
                2,
                $this->anything(),
            )
            ->willReturn($this->resultatVide([], 2));

        $this->makeTool(true, $tranchePaiement)->execute(
            ['axes' => ['echeance' => 'echue'], 'lieA' => ['entite' => 'Client', 'id' => 12], 'page' => 2],
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
            'totaux' => [
                'nb' => 3, 'totalPrime' => 3000.0, 'totalSoldePrime' => 1200.0,
                'totalSoldeCommission' => 90.0, 'nbPrimeImpayee' => 2, 'nbCommissionImpayee' => 1,
            ],
            'totalItems' => 3,
            'currentPage' => 1,
            'totalPages' => 1,
        ]);

        $result = $this->makeTool(true, $tranchePaiement)->execute(
            ['axes' => ['prime' => 'impayee']],
            $this->makeScope(),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $ligne = $result->data['lignes'][0];
        $this->assertSame('Tranche 2/4', $ligne['tranche']);
        $this->assertSame('Client Alpha', $ligne['client']);
        $this->assertSame(15, $ligne['joursRetard']);
        $this->assertSame(400.0, $ligne['soldePrime']);
        $this->assertSame(0.0, $ligne['soldeCommission']);
        $this->assertSame('prime', $ligne['dette']);
        $this->assertSame('Critique · retard 15 j', $ligne['urgence']);
        $this->assertSame(1200.0, $result->data['totaux']['totalSoldePrime']);
        $this->assertSame(3, $result->data['total']);
        $this->assertTrue($result->data['tronque']);
        $this->assertNull($result->uiAction);
        // Un axe de dette est posé : la répartition serait redondante et trompeuse.
        $this->assertArrayNotHasKey('repartition', $result->data);
    }

    /**
     * LE CHAMP « dette » NOMME LE DÉBITEUR RESTANT. Sans lui, un soldePrime à 0 se lit
     * « rien à faire » alors que l'assureur doit encore sa commission — c'est très
     * exactement la lecture qui a fait dire « une seule est réellement impayée » d'un
     * jeu de cinq lignes toutes exigibles (incident du 2026-08-05).
     */
    public function testChampDetteNommeLaDetteRestante(): void
    {
        $cas = [
            'prime' => [400.0, 0.0, 0.0],
            'commission' => [0.0, 150.0, 0.0],
            'prime+commission' => [400.0, 150.0, 0.0],
            'retro' => [0.0, 0.0, 75.0],
            'soldee' => [0.0, 0.0, 0.0],
        ];

        foreach ($cas as $attendu => [$soldePrime, $soldeCommission, $soldeRetro]) {
            $tranche = (new Tranche())->setNom('T')->setPayableAt(new \DateTimeImmutable('-1 day'));
            $tranche->statutPaiement = 'Non payée';
            $tranche->primeSoldeDue = $soldePrime;
            $tranche->solde_restant_du = $soldeCommission;
            $tranche->retroCommissionSolde = $soldeRetro;

            $tranchePaiement = $this->createMock(TranchePaiementService::class);
            $tranchePaiement->method('lister')->willReturn(
                ['items' => [$tranche]] + $this->resultatVide() + [],
            );

            $result = $this->makeTool(true, $tranchePaiement)->execute(
                ['axes' => ['prime' => 'impayee']],
                $this->makeScope(),
            );

            $this->assertSame($attendu, $result->data['lignes'][0]['dette'], "Dette attendue : {$attendu}.");
        }
    }

    /**
     * RÉPARTITION. Même remède que la partition echues/aVenir de vigie_echeances : sans
     * axe de dette, un total unique ne dit pas qui doit quoi. Deux comptes nommés, avec
     * leur débiteur et l'avertissement qu'ils se chevauchent, rendent le contresens
     * impossible.
     */
    public function testRepartitionEmiseSeulementSansAxeDeDette(): void
    {
        $totaux = [
            'nb' => 5, 'totalPrime' => 5000.0, 'totalSoldePrime' => 1381.48,
            'totalSoldeCommission' => 620.0, 'totalRetroExigible' => 0.0,
            'nbPrimeImpayee' => 1, 'nbCommissionImpayee' => 4,
        ];

        $tranchePaiement = $this->createMock(TranchePaiementService::class);
        $tranchePaiement->method('lister')->willReturn([
            'items' => [], 'totaux' => $totaux, 'totalItems' => 5, 'currentPage' => 1, 'totalPages' => 1,
        ]);
        $tool = $this->makeTool(true, $tranchePaiement);

        $sansAxe = $tool->execute([], $this->makeScope())->data['repartition'] ?? null;
        $this->assertNotNull($sansAxe);
        $this->assertSame(1, $sansAxe['primeImpayee']['nb']);
        $this->assertSame(1381.48, $sansAxe['primeImpayee']['montant']);
        $this->assertSame('l\'assuré', $sansAxe['primeImpayee']['debiteur']);
        $this->assertSame(4, $sansAxe['commissionImpayee']['nb']);
        $this->assertSame('l\'assureur', $sansAxe['commissionImpayee']['debiteur']);
        $this->assertStringContainsString('ne s\'additionnent pas', $sansAxe['rappel']);

        // L'axe échéance seul ne tranche aucune dette : la répartition reste utile.
        $avecEcheance = $tool->execute(['axes' => ['echeance' => 'echue']], $this->makeScope());
        $this->assertArrayHasKey('repartition', $avecEcheance->data);

        // Dès qu'une dette est ciblée, la répartition disparaît (elle ferait doublon).
        $avecPrime = $tool->execute(['axes' => ['prime' => 'impayee']], $this->makeScope());
        $this->assertArrayNotHasKey('repartition', $avecPrime->data);

        $avecCommission = $tool->execute(['axes' => ['commission' => 'impayee']], $this->makeScope());
        $this->assertArrayNotHasKey('repartition', $avecCommission->data);
    }

    public function testMatchDeclencheursEtNonCollisions(): void
    {
        $tool = $this->makeTool(true);
        $scope = $this->makeScope();

        // « impayés » sans dette nommée : on retient celle du CLIENT (la plus courante en
        // relance) et la sortie porte la répartition pour pouvoir nommer l'autre.
        $this->assertSame(
            ['axes' => ['prime' => 'impayee']],
            $tool->match('Montre-moi les impayés', $scope),
        );
        $this->assertSame(
            ['axes' => ['prime' => 'impayee', 'echeance' => 'echue']],
            $tool->match('Quelles primes en retard dois-je relancer ?', $scope),
        );
        $this->assertSame(
            ['axes' => ['prime' => 'impayee']],
            $tool->match('Quelles primes sont dues ?', $scope),
        );

        // Commissions devenues collectables = prime PAYÉE + commission impayée.
        $this->assertSame(
            ['axes' => ['prime' => 'payee', 'commission' => 'impayee']],
            $tool->match('Quelles commissions sont exigibles ?', $scope),
        );
        $this->assertSame(
            ['axes' => ['prime' => 'payee', 'commission' => 'impayee']],
            $tool->match('Quelles commissions puis-je collecter auprès de l\'assureur ?', $scope),
        );

        // Flux inverse : rétrocommissions à verser aux partenaires, dette née.
        $this->assertSame(
            ['axes' => ['commission' => 'payee', 'retro' => 'impayee']],
            $tool->match('Quelles rétrocommissions dois-je payer ?', $scope),
        );
        $this->assertSame(
            ['axes' => ['commission' => 'payee', 'retro' => 'impayee']],
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
        $tranche->retroCommission = 75.5;
        $tranche->retroCommissionSolde = 75.5;
        $tranche->retroCommissionExigible = 75.5;

        $tranchePaiement = $this->createMock(TranchePaiementService::class);
        $tranchePaiement->method('lister')->willReturn([
            'items' => [$tranche],
            'totaux' => [
                'nb' => 1, 'totalPrime' => 500.0, 'totalSoldePrime' => 0.0,
                'totalSoldeCommission' => 0.0, 'totalRetroExigible' => 75.5,
                'nbPrimeImpayee' => 0, 'nbCommissionImpayee' => 0,
            ],
            'totalItems' => 1,
            'currentPage' => 1,
            'totalPages' => 1,
        ]);

        $result = $this->makeTool(true, $tranchePaiement)->execute(
            ['axes' => ['retro' => 'impayee', 'commission' => 'payee']],
            $this->makeScope(),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('Commission payée · Rétro à payer', $result->data['filtre']);
        $this->assertSame(75.5, $result->data['lignes'][0]['retroAPayer']);
        $this->assertSame('retro', $result->data['lignes'][0]['dette']);
        $this->assertSame(75.5, $result->data['totaux']['totalRetroExigible']);
    }
}
