<?php

namespace App\Tests\Ai;

use App\Ai\Boussole\BoussoleService;
use App\Ai\Boussole\PlanDuJourService;
use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Tache;
use App\Entity\Tranche;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Note\NoteRecouvrementService;
use App\Services\Search\ChargeInviteCritereFactory;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Tranche\TranchePaiementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * PlanDuJourService : le programme du jour affiché à l'ouverture d'une conversation
 * vide. Mêmes garanties que la boussole — FAIL-CLOSED (une section n'apparaît qu'avec
 * le droit de lecture de son entité) et FAIL-SAFE (une source qui lève fait
 * disparaître la section, jamais échouer l'ouverture du chat) — plus la règle propre
 * au plan : assignation ∪ portefeuille, dédoublonnées, triées par urgence. Tests purs.
 */
class PlanDuJourServiceTest extends TestCase
{
    private function id(object $entity, int $id): object
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);

        return $entity;
    }

    private function invite(?int $id = null): Invite
    {
        $invite = new Invite();

        return $id === null ? $invite : $this->id($invite, $id);
    }

    /** Tâche ouverte échéant à $jours du jour (négatif = en retard). */
    private function tache(int $id, int $jours, string $description = 'Relancer'): Tache
    {
        $tache = (new Tache())
            ->setDescription($description)
            ->setToBeEndedAt(new \DateTimeImmutable(sprintf('%+d days', $jours)))
            ->setClosed(false);

        return $this->id($tache, $id);
    }

    /**
     * @param array<string,bool>                        $droits    canRead par entité
     * @param callable(string, array):array|null        $recherche réponse du moteur par entité
     */
    private function makeService(
        array $droits,
        ?callable $recherche = null,
        ?TranchePaiementService $tranchePaiement = null,
        ?NoteRecouvrementService $notes = null,
        bool $rechercheLeve = false,
    ): PlanDuJourService {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('canRead')->willReturnCallback(
            static fn (Invite $invite, string $shortName): bool => $droits[$shortName] ?? false,
        );

        $search = $this->createMock(JSBDynamicSearchService::class);
        if ($rechercheLeve) {
            $search->method('search')->willThrowException(new \RuntimeException('boom'));
        } else {
            $search->method('search')->willReturnCallback(
                static function (string $entityClass, array $criteres) use ($recherche): array {
                    $data = $recherche !== null ? $recherche($entityClass, $criteres) : [];

                    return ['status' => ['code' => 200], 'data' => $data, 'totalItems' => count($data)];
                }
            );
        }

        if ($tranchePaiement === null) {
            $tranchePaiement = $this->createMock(TranchePaiementService::class);
            $tranchePaiement->method('lister')->willReturn([
                'items' => [],
                'totaux' => ['nb' => 0, 'totalPrime' => 0.0, 'totalSoldePrime' => 0.0, 'totalSoldeCommission' => 0.0, 'totalRetroExigible' => 0.0],
                'totalItems' => 0, 'currentPage' => 1, 'totalPages' => 1,
            ]);
        }

        if ($notes === null) {
            $notes = $this->createMock(NoteRecouvrementService::class);
            $notes->method('lister')->willReturn([
                'items' => [], 'totaux' => ['nb' => 0, 'totalFacture' => 0.0, 'totalEncaisse' => 0.0, 'totalSolde' => 0.0],
                'totalItems' => 0, 'currentPage' => 1, 'totalPages' => 1,
            ]);
        }

        return new PlanDuJourService(
            $resolver,
            $search,
            new ChargeInviteCritereFactory(
                new PortefeuilleCritereFactory($this->createMock(EntityManagerInterface::class))
            ),
            $tranchePaiement,
            $notes,
        );
    }

    /** @return string[] */
    private function cles(array $plan): array
    {
        return array_map(static fn (array $s): string => $s['cle'], $plan['sections']);
    }

    // ─────────────────────────────────────────────────────────── Fail-closed

    public function testFailClosedAucunDroitAucuneSection(): void
    {
        $plan = $this->makeService([])->plan(new Entreprise(), $this->invite());

        $this->assertSame([], $plan['sections']);
        $this->assertNull($plan['priorite']);
        $this->assertTrue($plan['toutAuVert']);
    }

    public function testSectionTachesAbsenteSansLeDroitTache(): void
    {
        $plan = $this->makeService(
            ['Feedback' => true],
            fn (string $classe): array => $classe === Tache::class ? [$this->tache(1, -2)] : [],
        )->plan(new Entreprise(), $this->invite());

        $this->assertNotContains('taches_assignees', $this->cles($plan));
    }

    // ───────────────────────────────────────────────────────────── Fail-safe

    public function testFailSafeUneSectionQuiLeveNeCassePasLePlan(): void
    {
        // Le moteur de recherche lève pour toute entité : les sections qui en
        // dépendent disparaissent, celles adossées aux autres services subsistent.
        $tranches = $this->createMock(TranchePaiementService::class);
        $tranches->method('lister')->willReturn([
            'items' => [$this->id(new Tranche(), 7)],
            'totaux' => ['totalSoldePrime' => 900.0, 'totalSoldeCommission' => 0.0],
            'totalItems' => 1, 'currentPage' => 1, 'totalPages' => 1,
        ]);

        $plan = $this->makeService(
            ['Tache' => true, 'Tranche' => true],
            null,
            $tranches,
            null,
            rechercheLeve: true,
        )->plan(new Entreprise(), $this->invite());

        $this->assertNotContains('taches_assignees', $this->cles($plan));
        $this->assertContains('primes_dues', $this->cles($plan));
    }

    // ────────────────────────────────────────────── Assignation ∪ portefeuille

    public function testSansPortefeuilleSeuleLaSectionAssigneeExiste(): void
    {
        // Invité sans id : la fabrique rend un critère de périmètre VIDE. Interroger
        // quand même reviendrait à lister les tâches de toute l'entreprise.
        $plan = $this->makeService(
            ['Tache' => true],
            fn (string $classe): array => $classe === Tache::class ? [$this->tache(1, -1)] : [],
        )->plan(new Entreprise(), $this->invite());

        $this->assertSame(['taches_assignees'], $this->cles($plan));
    }

    public function testUneTacheDesDeuxCotesNapparaitQueDansMesTaches(): void
    {
        $partagee = $this->tache(10, -1, 'Tâche à moi ET sur mon client');
        $autre = $this->tache(11, 2, 'Tâche de mon client, assignée à un collègue');

        // Le critère d'assignation porte 'executor', celui du portefeuille non :
        // c'est ce qui distingue les deux requêtes ici.
        $recherche = static function (string $classe, array $criteres) use ($partagee, $autre): array {
            if ($classe !== Tache::class) {
                return [];
            }

            return isset($criteres['executor']) ? [$partagee] : [$partagee, $autre];
        };

        $plan = $this->makeService(['Tache' => true], $recherche)->plan(new Entreprise(), $this->invite(42));

        $this->assertSame(['taches_assignees', 'taches_portefeuille'], $this->cles($plan));

        $assignees = $plan['sections'][0];
        $portefeuille = $plan['sections'][1];

        $this->assertSame([10], array_column($assignees['lignes'], 'id'));
        // La tâche partagée a été retirée : elle est déjà annoncée comme « à moi ».
        $this->assertSame([11], array_column($portefeuille['lignes'], 'id'));
    }

    public function testFeedbacksDedoublonnesEntreAuteurEtPortefeuille(): void
    {
        $feedback = $this->id(
            (new Feedback())
                ->setDescription('Appel client')
                ->setHasNextAction(true)
                ->setNextAction('Envoyer la proposition')
                ->setNextActionAt(new \DateTimeImmutable('-1 day')),
            5
        );

        // Le même feedback remonte par les deux requêtes (auteur ET portefeuille).
        $plan = $this->makeService(
            ['Feedback' => true],
            fn (string $classe): array => $classe === Feedback::class ? [$feedback] : [],
        )->plan(new Entreprise(), $this->invite(42));

        $section = $plan['sections'][0];
        $this->assertSame('feedbacks_actions', $section['cle']);
        $this->assertSame(1, $section['compte'], 'Le feedback ne doit être compté qu’une fois.');
        $this->assertSame('Envoyer la proposition', $section['lignes'][0]['libelle']);
    }

    // ──────────────────────────────────────────────────── Tri et bornage

    public function testSectionsTrieesParUrgenceDecroissante(): void
    {
        $tranches = $this->createMock(TranchePaiementService::class);
        $tranches->method('lister')->willReturn([
            'items' => [$this->id(new Tranche(), 1)],
            'totaux' => ['totalSoldePrime' => 500.0, 'totalSoldeCommission' => 300.0],
            'totalItems' => 1, 'currentPage' => 1, 'totalPages' => 1,
        ]);

        $plan = $this->makeService(
            ['Tache' => true, 'Tranche' => true],
            fn (string $classe): array => $classe === Tache::class ? [$this->tache(1, 0)] : [],
            $tranches,
        )->plan(new Entreprise(), $this->invite());

        // commissions (70) > primes (65) > tâches (40) — barème de la boussole.
        $this->assertSame(
            ['commissions_a_facturer', 'primes_dues', 'taches_assignees'],
            $this->cles($plan),
        );
        $this->assertSame('commissions_a_facturer', $plan['priorite']['cle']);
    }

    public function testLignesBorneesEtDrapeauTronque(): void
    {
        $taches = [];
        for ($i = 1; $i <= PlanDuJourService::MAX_LIGNES_PAR_SECTION + 3; ++$i) {
            $taches[] = $this->tache($i, $i - 5);
        }

        $plan = $this->makeService(
            ['Tache' => true],
            fn (string $classe): array => $classe === Tache::class ? $taches : [],
        )->plan(new Entreprise(), $this->invite());

        $section = $plan['sections'][0];
        $this->assertCount(PlanDuJourService::MAX_LIGNES_PAR_SECTION, $section['lignes']);
        $this->assertTrue($section['tronque']);
    }

    public function testLignesTrieesParEcheanceLaPlusUrgenteDabord(): void
    {
        // Fournies dans le désordre : le moteur trie par id décroissant, le tri par
        // échéance relève donc du service.
        $taches = [$this->tache(1, 3), $this->tache(2, -4), $this->tache(3, 0)];

        $plan = $this->makeService(
            ['Tache' => true],
            fn (string $classe): array => $classe === Tache::class ? $taches : [],
        )->plan(new Entreprise(), $this->invite());

        $lignes = $plan['sections'][0]['lignes'];
        $this->assertSame([2, 3, 1], array_column($lignes, 'id'));
        $this->assertSame(
            [PlanDuJourService::EN_RETARD, PlanDuJourService::AUJOURDHUI, PlanDuJourService::A_VENIR],
            array_column($lignes, 'statutTemporel'),
        );
        $this->assertSame(1, $plan['sections'][0]['enRetard']);
    }

    // ────────────────────────────────────────────────────────── Renouvellements

    public function testRenouvellementsEchusPortentLUrgenceHaute(): void
    {
        $avenant = $this->id(
            (new Avenant())->setReferencePolice('POL-2026-001')->setEndingAt(new \DateTimeImmutable('-3 days')),
            9
        );

        $plan = $this->makeService(
            ['Avenant' => true],
            fn (string $classe): array => $classe === Avenant::class ? [$avenant] : [],
        )->plan(new Entreprise(), $this->invite());

        $section = $plan['sections'][0];
        $this->assertSame('renouvellements', $section['cle']);
        $this->assertSame(BoussoleService::URGENCE['renouvellements'], $section['urgence']);
        $this->assertSame('POL-2026-001', $section['lignes'][0]['libelle']);
        $this->assertSame(PlanDuJourService::EN_RETARD, $section['lignes'][0]['statutTemporel']);
    }

    // ──────────────────────────────────────────────────────────── Recouvrement

    public function testCommissionsARecouvrerAnnoncentLePerimetreCabinet(): void
    {
        $note = $this->id((new Note())->setReference('ND-2026-014'), 3);
        $note->solde = 2150.0;

        $notes = $this->createMock(NoteRecouvrementService::class);
        $notes->method('lister')->willReturn([
            'items' => [$note],
            'totaux' => ['nb' => 1, 'totalFacture' => 3000.0, 'totalEncaisse' => 850.0, 'totalSolde' => 2150.0],
            'totalItems' => 1, 'currentPage' => 1, 'totalPages' => 1,
        ]);
        $notes->method('joursAnciennete')->willReturn(45);

        $plan = $this->makeService(['Note' => true], null, null, $notes)->plan(new Entreprise(), $this->invite());

        $section = $plan['sections'][0];
        $this->assertSame('commissions_a_recouvrer', $section['cle']);
        $this->assertStringContainsString('cabinet', $section['perimetre']);
        $this->assertSame(2150.0, $section['montant']);
        $this->assertSame(45, $section['lignes'][0]['joursAnciennete']);
    }
}
