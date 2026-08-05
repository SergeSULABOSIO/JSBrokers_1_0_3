<?php

namespace App\Tests\Services;

use App\Entity\Tranche;
use App\Services\CanvasBuilder;
use App\Services\Search\TranchePaiementScope;
use App\Services\Tranche\TranchePaiementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Suivi des paiements par tranche : filtrage par AXES (une dette par débiteur, plus
 * l'échéance), cumul en ET, tri par urgence, pagination en mémoire. Tests purs : le
 * CanvasBuilder est un no-op, les indicateurs calculés (soldes, statut) sont posés
 * directement sur les entités.
 *
 * L'invariant central du fichier est la COMPLÉMENTARITÉ : chaque axe partitionne les
 * tranches suivies en deux ensembles disjoints (plus, pour la rétro et l'échéance, un
 * hors-champ explicite). C'est ce qui rend l'ancien « impayées = prime ET/OU commission »
 * inexprimable — l'ambiguïté qui faisait annoncer 5 lignes pour 1 seule prime due.
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

    /** @return array<string, string> */
    private function axes(string ...$paires): array
    {
        $axes = [];
        foreach (array_chunk($paires, 2) as [$cle, $valeur]) {
            $axes[$cle] = $valeur;
        }

        return $axes;
    }

    public function testAxePrimeIsoleLaDetteDeLAssure(): void
    {
        $tranches = [
            $this->makeTranche(1, 'Non payée', new \DateTimeImmutable('+10 days'), 500.0),
            $this->makeTranche(2, 'Payée', new \DateTimeImmutable('-10 days')),
            $this->makeTranche(3, 'N/A', null),
            $this->makeTranche(4, 'Prime payée, commission due', new \DateTimeImmutable('+5 days'), 0.0, 80.0),
            $this->makeTranche(5, 'Partiellement payée', new \DateTimeImmutable('-2 days'), 200.0),
        ];

        $resultat = $this->makeService()->filtrerTrierPaginer(
            $tranches,
            $this->axes(TranchePaiementScope::AXE_PRIME, TranchePaiementScope::IMPAYEE),
        );

        $ids = array_map(static fn (Tranche $t) => $t->getId(), $resultat['data']);
        // La tranche 4 (prime soldée, commission due) est ABSENTE : c'est tout l'objet
        // de l'axe. L'ancien filtre « impayées » l'incluait avec un solde de prime à 0.
        $this->assertSame([5, 1], $ids, 'Échue d\'abord, puis à échoir par échéance croissante.');
        $this->assertSame(2, $resultat['totalItems']);
    }

    public function testUneDetteDeCommissionNEstPasUnePrimeDue(): void
    {
        // Prime intégralement encaissée, commission encore due : deux débiteurs, deux
        // réponses opposées selon l'axe. C'est exactement la ligne qui, lue sous un
        // filtre unique, faisait dire tour à tour « impayée » et « prime soldée ».
        $tranche = $this->makeTranche(1, 'Prime payée, commission due', new \DateTimeImmutable('-3 days'), 0.0, 150.0);

        $service = $this->makeService();
        $this->assertFalse($service->correspondAuFiltre($tranche, $this->axes(TranchePaiementScope::AXE_PRIME, TranchePaiementScope::IMPAYEE)));
        $this->assertTrue($service->correspondAuFiltre($tranche, $this->axes(TranchePaiementScope::AXE_PRIME, TranchePaiementScope::PAYEE)));
        $this->assertTrue($service->correspondAuFiltre($tranche, $this->axes(TranchePaiementScope::AXE_COMMISSION, TranchePaiementScope::IMPAYEE)));
        $this->assertTrue($service->correspondAuFiltre($tranche, $this->axes(TranchePaiementScope::AXE_ECHEANCE, TranchePaiementScope::ECHUE)));
    }

    public function testLesAxesSeCumulentEnEt(): void
    {
        // Commission EXIGIBLE = prime PAYÉE + commission IMPAYÉE : sa définition même,
        // désormais exprimée par une combinaison au lieu d'un statut composite opaque.
        $exigible = $this->makeTranche(1, 'Prime payée, commission due', new \DateTimeImmutable('-5 days'), 0.0, 150.0);
        // Prime NON payée : la commission est due mais PAS exigible (l'assureur n'a rien encaissé).
        $nonExigible = $this->makeTranche(2, 'Non payée', new \DateTimeImmutable('-5 days'), 500.0, 150.0);

        $combinaison = $this->axes(
            TranchePaiementScope::AXE_PRIME, TranchePaiementScope::PAYEE,
            TranchePaiementScope::AXE_COMMISSION, TranchePaiementScope::IMPAYEE,
        );

        $service = $this->makeService();
        $this->assertTrue($service->correspondAuFiltre($exigible, $combinaison));
        $this->assertFalse($service->correspondAuFiltre($nonExigible, $combinaison));

        $resultat = $service->filtrerTrierPaginer([$exigible, $nonExigible], $combinaison);
        $this->assertSame([1], array_map(static fn (Tranche $t) => $t->getId(), $resultat['data']));
    }

    public function testChaqueAxeEstComplementaire(): void
    {
        // Un jeu couvrant les quatre combinaisons de dettes, avec et sans rétro.
        $tranches = [
            $this->makeTranche(1, 'Non payée', new \DateTimeImmutable('-1 day'), 500.0, 100.0),
            $this->makeTranche(2, 'Prime payée, commission due', new \DateTimeImmutable('+1 day'), 0.0, 100.0),
            $this->makeTranche(3, 'Payée', new \DateTimeImmutable('-2 days')),
            $this->makeTranche(4, 'Partiellement payée', new \DateTimeImmutable('+2 days'), 300.0),
        ];
        // Rétro : seules 1 et 3 en portent une (1 encore due, 3 déjà reversée).
        $tranches[0]->retroCommission = 50.0;
        $tranches[0]->retroCommissionSolde = 50.0;
        $tranches[2]->retroCommission = 40.0;
        $tranches[2]->retroCommissionSolde = 0.0;

        $service = $this->makeService();
        $ids = static fn (array $r): array => array_map(static fn (Tranche $t) => $t->getId(), $r['data']);

        // Seules PAYEE et IMPAYEE partitionnent l'axe ; PARTIELLE est un sous-ensemble
        // d'IMPAYEE, testé séparément (cf. testPartielleEstUnSousEnsembleDImpayee).
        foreach ([
            TranchePaiementScope::AXE_PRIME => [TranchePaiementScope::PAYEE, TranchePaiementScope::IMPAYEE, 4],
            TranchePaiementScope::AXE_COMMISSION => [TranchePaiementScope::PAYEE, TranchePaiementScope::IMPAYEE, 4],
            // Rétro : les tranches 2 et 4 n'en portent aucune → hors de TOUTES les valeurs.
            TranchePaiementScope::AXE_RETRO => [TranchePaiementScope::PAYEE, TranchePaiementScope::IMPAYEE, 2],
            TranchePaiementScope::AXE_ECHEANCE => [TranchePaiementScope::A_ECHOIR, TranchePaiementScope::ECHUE, 4],
        ] as $axe => [$valeurA, $valeurB, $couverture]) {
            $a = $ids($service->filtrerTrierPaginer($tranches, $this->axes($axe, $valeurA)));
            $b = $ids($service->filtrerTrierPaginer($tranches, $this->axes($axe, $valeurB)));

            $this->assertSame([], array_intersect($a, $b), "Axe {$axe} : les deux valeurs doivent être DISJOINTES.");
            $this->assertCount($couverture, array_merge($a, $b), "Axe {$axe} : couverture attendue.");
        }
    }

    /**
     * PARTIELLE = « il reste dû, ET de l'argent est déjà rentré ». C'est un SOUS-ENSEMBLE
     * strict d'IMPAYEE, pas une troisième catégorie : le cas d'un bordereau de production
     * encaissé à 31 %, où la commission n'est ni soldée, ni restée sans le moindre
     * versement. Sans cette valeur, ces tranches n'étaient visibles nulle part — le chip
     * « Commission payée » les excluait (à juste titre) et rien ne les distinguait de
     * celles où pas un centime n'était rentré.
     */
    public function testPartielleEstUnSousEnsembleDImpayee(): void
    {
        $rienRecu = $this->makeTranche(1, 'Non payée', new \DateTimeImmutable('-1 day'), 500.0, 200.0);
        $rienRecu->primePayee = 0.0;
        $rienRecu->montant_paye = 0.0;

        $entame = $this->makeTranche(2, 'Partiellement payée', new \DateTimeImmutable('-1 day'), 350.0, 138.0);
        $entame->primePayee = 150.0;
        $entame->montant_paye = 62.0; // bordereau encaissé à 31 %

        $soldee = $this->makeTranche(3, 'Payée', new \DateTimeImmutable('-1 day'), 0.0, 0.0);
        $soldee->primePayee = 500.0;
        $soldee->montant_paye = 200.0;

        $service = $this->makeService();
        $ids = fn (array $axes): array => array_map(
            static fn (Tranche $t) => $t->getId(),
            $service->filtrerTrierPaginer([$rienRecu, $entame, $soldee], $axes)['data'],
        );

        foreach ([TranchePaiementScope::AXE_PRIME, TranchePaiementScope::AXE_COMMISSION] as $axe) {
            $impayees = $ids([$axe => TranchePaiementScope::IMPAYEE]);
            $partielles = $ids([$axe => TranchePaiementScope::PARTIELLE]);

            $this->assertEqualsCanonicalizing([1, 2], $impayees, "Axe {$axe} : « impayée » = tout ce qui reste dû.");
            $this->assertSame([2], $partielles, "Axe {$axe} : seule la dette ENTAMÉE est partielle.");
            $this->assertSame(
                [],
                array_diff($partielles, $impayees),
                "Axe {$axe} : « partielle » est INCLUSE dans « impayée », jamais à côté."
            );
        }

        // Se cumule avec les autres axes comme n'importe quelle valeur.
        $this->assertSame([2], $ids([
            TranchePaiementScope::AXE_COMMISSION => TranchePaiementScope::PARTIELLE,
            TranchePaiementScope::AXE_ECHEANCE => TranchePaiementScope::ECHUE,
        ]));
    }

    public function testAxeRetroPartielleExigeUnVersementDejaFait(): void
    {
        $entamee = $this->makeTranche(1, 'Payée', new \DateTimeImmutable('-5 days'));
        $entamee->retroCommission = 120.0;
        $entamee->retroCommissionReversee = 40.0;
        $entamee->retroCommissionSolde = 80.0;

        $intacte = $this->makeTranche(2, 'Payée', new \DateTimeImmutable('-5 days'));
        $intacte->retroCommission = 90.0;
        $intacte->retroCommissionReversee = 0.0;
        $intacte->retroCommissionSolde = 90.0;

        $service = $this->makeService();
        $resultat = $service->filtrerTrierPaginer(
            [$entamee, $intacte],
            $this->axes(TranchePaiementScope::AXE_RETRO, TranchePaiementScope::PARTIELLE),
        );

        $this->assertSame([1], array_map(static fn (Tranche $t) => $t->getId(), $resultat['data']));
        // Les deux restent dues : « partielle » n'en retire aucune de « à payer ».
        $this->assertCount(2, $service->filtrerTrierPaginer(
            [$entamee, $intacte],
            $this->axes(TranchePaiementScope::AXE_RETRO, TranchePaiementScope::IMPAYEE),
        )['data']);
    }

    public function testAxeEcheanceExclutLesTranchesSansDate(): void
    {
        $echue = $this->makeTranche(1, 'Non payée', new \DateTimeImmutable('-1 day'), 100.0);
        $aEchoir = $this->makeTranche(2, 'Non payée', new \DateTimeImmutable('+1 day'), 100.0);
        $sansEcheance = $this->makeTranche(3, 'Non payée', null, 100.0);

        $service = $this->makeService();
        $echues = $this->axes(TranchePaiementScope::AXE_ECHEANCE, TranchePaiementScope::ECHUE);
        $aVenir = $this->axes(TranchePaiementScope::AXE_ECHEANCE, TranchePaiementScope::A_ECHOIR);

        $this->assertTrue($service->correspondAuFiltre($echue, $echues));
        $this->assertFalse($service->correspondAuFiltre($echue, $aVenir));

        $this->assertFalse($service->correspondAuFiltre($aEchoir, $echues));
        $this->assertTrue($service->correspondAuFiltre($aEchoir, $aVenir));

        // Sans date d'échéance, la tranche n'est ni en retard ni à échoir : l'information
        // n'existe pas, on ne la range pas d'office dans « à échoir ». Elle reste visible
        // sans cet axe, et le tri par urgence la place après les tranches datées.
        $this->assertFalse($service->correspondAuFiltre($sansEcheance, $echues));
        $this->assertFalse($service->correspondAuFiltre($sansEcheance, $aVenir));
        $this->assertTrue($service->correspondAuFiltre($sansEcheance, $this->axes(TranchePaiementScope::AXE_PRIME, TranchePaiementScope::IMPAYEE)));
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

    public function testTropPercuCompteCommeSolde(): void
    {
        // Note de crédit / trop-perçu : soldes négatifs. Rien à recouvrer, donc « payée »
        // sur les deux axes de dette — un solde négatif n'est pas une dette.
        $tranche = $this->makeTranche(1, 'Payée', new \DateTimeImmutable('-15 days'), -50.0, -10.0);

        $service = $this->makeService();
        $this->assertFalse($service->correspondAuFiltre($tranche, $this->axes(TranchePaiementScope::AXE_PRIME, TranchePaiementScope::IMPAYEE)));
        $this->assertTrue($service->correspondAuFiltre($tranche, $this->axes(TranchePaiementScope::AXE_PRIME, TranchePaiementScope::PAYEE)));
        $this->assertTrue($service->correspondAuFiltre($tranche, $this->axes(TranchePaiementScope::AXE_COMMISSION, TranchePaiementScope::PAYEE)));
    }

    public function testAxeRetroIgnoreLesTranchesSansRetro(): void
    {
        // Tranche soldée à l'encaissement MAIS rétro partenaire encore due : elle remonte
        // sous « rétro impayée » (flux inverse : le courtier est ici le débiteur).
        $payeeAvecRetro = $this->makeTranche(1, 'Payée', new \DateTimeImmutable('-5 days'));
        $payeeAvecRetro->retroCommission = 120.0;
        $payeeAvecRetro->retroCommissionSolde = 120.0;

        // Aucune rétro sur cette affaire : la dette n'existe pas, la tranche sort des DEUX
        // valeurs de l'axe (elle n'est pas « rétro payée » par défaut).
        $sansRetro = $this->makeTranche(2, 'Payée', new \DateTimeImmutable('-5 days'));
        $sansRetro->retroCommission = 0.0;
        $sansRetro->retroCommissionSolde = 0.0;

        $service = $this->makeService();
        $aPayer = $this->axes(TranchePaiementScope::AXE_RETRO, TranchePaiementScope::IMPAYEE);
        $reversee = $this->axes(TranchePaiementScope::AXE_RETRO, TranchePaiementScope::PAYEE);

        $this->assertTrue($service->correspondAuFiltre($payeeAvecRetro, $aPayer));
        $this->assertFalse($service->correspondAuFiltre($payeeAvecRetro, $reversee));
        $this->assertFalse($service->correspondAuFiltre($sansRetro, $aPayer));
        $this->assertFalse($service->correspondAuFiltre($sansRetro, $reversee));

        // « À verser MAINTENANT » = la dette existe ET la commission est encaissée.
        $resultat = $service->filtrerTrierPaginer([$payeeAvecRetro, $sansRetro], $this->axes(
            TranchePaiementScope::AXE_RETRO, TranchePaiementScope::IMPAYEE,
            TranchePaiementScope::AXE_COMMISSION, TranchePaiementScope::PAYEE,
        ));
        $this->assertSame([1], array_map(static fn (Tranche $t) => $t->getId(), $resultat['data']));
    }

    public function testPaginationEnMemoire(): void
    {
        $tranches = [];
        for ($i = 1; $i <= 45; ++$i) {
            $tranches[] = $this->makeTranche($i, 'Non payée', new \DateTimeImmutable('-' . $i . ' days'), 100.0);
        }

        $service = $this->makeService();
        $axes = $this->axes(TranchePaiementScope::AXE_PRIME, TranchePaiementScope::IMPAYEE);

        $page1 = $service->filtrerTrierPaginer($tranches, $axes, 1, 20);
        $this->assertCount(20, $page1['data']);
        $this->assertSame(45, $page1['totalItems']);
        $this->assertSame(3, $page1['totalPages']);
        // Retard décroissant : la tranche la plus ancienne (id 45) ouvre la page 1.
        $this->assertSame(45, $page1['data'][0]->getId());

        $page3 = $service->filtrerTrierPaginer($tranches, $axes, 3, 20);
        $this->assertCount(5, $page3['data']);

        $pageHorsBornes = $service->filtrerTrierPaginer($tranches, $axes, 9, 20);
        $this->assertSame([], $pageHorsBornes['data']);
        $this->assertSame(45, $pageHorsBornes['totalItems']);
    }

    public function testSansAxeToutesLesTranchesSuiviesRemontent(): void
    {
        // Aucun axe = aucun filtre de dette. Seul « N/A » (projet non validé) reste exclu.
        $tranches = [
            $this->makeTranche(1, 'Non payée', new \DateTimeImmutable('-1 day'), 500.0),
            $this->makeTranche(2, 'Payée', new \DateTimeImmutable('-2 days')),
            $this->makeTranche(3, 'N/A', null),
        ];

        $resultat = $this->makeService()->filtrerTrierPaginer($tranches, []);

        $this->assertSame([1, 2, 3], array_map(static fn (Tranche $t) => $t->getId(), $resultat['data']));
        $this->assertSame(3, $resultat['totalItems']);
    }

    public function testFormeDuRetourStable(): void
    {
        $resultat = $this->makeService()->filtrerTrierPaginer([], $this->axes(
            TranchePaiementScope::AXE_PRIME, TranchePaiementScope::IMPAYEE,
        ));

        $this->assertSame(200, $resultat['status']['code']);
        $this->assertSame(0, $resultat['totalItems']);
        $this->assertSame(1, $resultat['totalPages']);
        $this->assertSame(20, $resultat['itemsPerPage']);
    }
}
