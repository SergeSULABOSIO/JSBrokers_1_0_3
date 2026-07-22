<?php

namespace App\Tests\Ai;

use App\Ai\FicheNormaliseur;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\CompterEntitesTool;
use App\Ai\Tool\EntiteLexique;
use App\Ai\Tool\EntiteLibelle;
use App\Ai\Tool\LireFicheTool;
use App\Ai\Tool\PaiementsPrimeTool;
use App\Ai\Tool\RechercherEntitesTool;
use App\Ai\Tool\SignalerPaiementPrimeTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\PaiementPrime;
use App\Entity\Tranche;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Search\PortefeuilleScope;
use App\Services\Tranche\TranchePaiementService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Outil « paiements_prime » : lecture des signalements de paiement de prime (déclaratifs,
 * encaissés par l'ASSUREUR — jamais la trésorerie du cabinet). Fail-closed sur la LECTURE
 * Tranche (sous-entité gouvernée par sa tranche), scoping entreprise, mode ciblé sans
 * filtre portefeuille (comme lire_fiche) et mode transversal avec périmètre par défaut.
 * Vérifie aussi que les outils génériques ne captent plus la question. Tests purs.
 */
class PaiementsPrimeToolTest extends TestCase
{
    /** Invité porteur d'un id : sans lui, la fabrique de périmètre reste neutre. */
    private function inviteAvecId(int $id): Invite
    {
        $invite = new Invite();
        $reflection = new \ReflectionProperty(Invite::class, 'id');
        $reflection->setValue($invite, $id);

        return $invite;
    }

    /** Fabrique réelle (source unique du critère) sur un EntityManager muet. */
    private function fabriquePortefeuille(): PortefeuilleCritereFactory
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return new PortefeuilleCritereFactory($em);
    }

    private function makeTool(
        bool $canReadTranche,
        ?JSBDynamicSearchService $search = null,
        ?TranchePaiementService $tranchePaiement = null,
    ): PaiementsPrimeTool {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(['Tranche' => 'Tranches', 'Client' => 'Clients']);
        $resolver->method('canRead')->willReturnCallback(
            static fn (Invite $invite, string $shortName) => \in_array($shortName, ['Tranche', 'Client'], true) && $canReadTranche,
        );

        return new PaiementsPrimeTool(
            $resolver,
            $search ?? $this->createMock(JSBDynamicSearchService::class),
            $tranchePaiement ?? $this->createMock(TranchePaiementService::class),
            $this->fabriquePortefeuille(),
        );
    }

    private function makeScope(?Invite $invite = null): AiScope
    {
        return new AiScope(new Entreprise(), $invite ?? new Invite());
    }

    /** @param object[] $data */
    private function reponse(array $data, int $totalItems = null): array
    {
        return [
            'status' => ['error' => null, 'code' => 200, 'message' => 'OK'],
            'data' => $data,
            'totalItems' => $totalItems ?? count($data),
            'currentPage' => 1,
            'totalPages' => 1,
            'itemsPerPage' => 20,
        ];
    }

    private function signalement(string $reference, float $montant, string $date): PaiementPrime
    {
        return (new PaiementPrime())
            ->setReference($reference)
            ->setMontant($montant)
            ->setPaidAt(new \DateTimeImmutable($date))
            ->setDescription('Avis de règlement transmis par l\'assureur');
    }

    public function testFailClosedSansLectureTranche(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->expects($this->never())->method('search');

        $result = $this->makeTool(false, $search)->execute(['trancheId' => 71], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
        $this->assertSame('Tranches', $result->data['libelle']);
    }

    public function testTrancheHorsEntrepriseIntrouvable(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn($this->reponse([]));

        $result = $this->makeTool(true, $search)->execute(['trancheId' => 71], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    public function testModeCibleRestitueSignalementsEtContexteDeReglement(): void
    {
        $tranche = (new Tranche())->setNom('Tranche unique');
        $tranche->primeTranche = 1000.0;
        $tranche->primePayee = 1000.0;
        $tranche->primeDeclareePayee = 600.0;
        $tranche->primeSoldeDue = 0.0;
        $tranche->statutPaiement = 'Prime payée, commission due';
        $tranche->commissionExigible = 150.0;

        $signalements = [
            $this->signalement('PP-002', 200.0, '2026-07-10'),
            $this->signalement('PP-001', 400.0, '2026-06-05'),
        ];

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            fn (string $class) => $class === Tranche::class
                ? $this->reponse([$tranche])
                : $this->reponse($signalements),
        );

        $tranchePaiement = $this->createMock(TranchePaiementService::class);
        $tranchePaiement->expects($this->once())->method('chargerIndicateurs')->with([$tranche]);

        $result = $this->makeTool(true, $search, $tranchePaiement)->execute(['trancheId' => 71], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('Tranche unique', $result->data['tranche']['nom']);
        $this->assertSame('Prime payée, commission due', $result->data['tranche']['statutPaiement']);
        $this->assertSame(150.0, $result->data['commissionExigible']);

        // La part SIGNALÉE est la somme des PaiementPrime ; « payee » l'englobe et peut la
        // dépasser (notes client encaissées, bordereau réconcilié) — la note l'explique.
        $this->assertSame(600.0, $result->data['prime']['signalee']);
        $this->assertSame(1000.0, $result->data['prime']['payee']);
        $this->assertStringContainsString('DÉCLARATIF', $result->data['note']);
        $this->assertStringContainsString('trésorerie', $result->data['note']);

        $this->assertCount(2, $result->data['signalements']);
        // Les clés vides sont élaguées (économie de tokens) : pas d'id sur une entité non persistée.
        $this->assertSame(
            ['date' => '2026-07-10', 'montant' => 200.0, 'reference' => 'PP-002', 'description' => 'Avis de règlement transmis par l\'assureur'],
            $result->data['signalements'][0],
        );
        $this->assertSame(2, $result->data['total']);
    }

    public function testModeCibleNAppliquePasLeFiltrePortefeuille(): void
    {
        $tranche = (new Tranche())->setNom('Tranche hors portefeuille');
        $criteresVus = [];

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            function (string $class, array $criteria) use (&$criteresVus, $tranche) {
                $criteresVus[] = $criteria;

                return $class === Tranche::class ? $this->reponse([$tranche]) : $this->reponse([]);
            },
        );

        $result = $this->makeTool(true, $search)
            ->execute(['trancheId' => 71], $this->makeScope($this->inviteAvecId(9)));

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        foreach ($criteresVus as $criteria) {
            $this->assertArrayNotHasKey(
                PortefeuilleScope::CRITERION_KEY,
                $criteria,
                'Une consultation ciblée par id ne doit pas être restreinte au portefeuille (cf. lire_fiche).'
            );
        }
    }

    public function testModeTransversalAppliqueLePerimetrePortefeuilleParDefaut(): void
    {
        $criteres = null;
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            function (string $class, array $criteria) use (&$criteres) {
                $criteres = $criteria;

                return $this->reponse([$this->signalement('PP-010', 500.0, '2026-07-01')]);
            },
        );

        $result = $this->makeTool(true, $search)->execute([], $this->makeScope($this->inviteAvecId(9)));

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertArrayHasKey(PortefeuilleScope::CRITERION_KEY, $criteres);
        $this->assertSame('aucun portefeuille', $result->data['perimetre']);
        $this->assertSame(500.0, $result->data['montantPage']);
    }

    public function testModeTransversalElargiALEntreprise(): void
    {
        $criteres = null;
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            function (string $class, array $criteria) use (&$criteres) {
                $criteres = $criteria;

                return $this->reponse([]);
            },
        );

        $result = $this->makeTool(true, $search)->execute(
            ['perimetre' => PortefeuilleScope::PERIMETRE_ENTREPRISE, 'du' => '2026-07-01', 'au' => '2026-07-31'],
            $this->makeScope($this->inviteAvecId(9)),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertArrayNotHasKey(PortefeuilleScope::CRITERION_KEY, $criteres);
        $this->assertSame(['from' => '2026-07-01', 'to' => '2026-07-31'], $criteres['paidAt']);
        $this->assertSame(PortefeuilleScope::LIBELLE_ENTREPRISE, $result->data['perimetre']);
    }

    public function testRattachementClientTraduitEnCheminDeRelations(): void
    {
        $criteres = null;
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            function (string $class, array $criteria) use (&$criteres) {
                $criteres = $criteria;

                return $this->reponse([]);
            },
        );

        $result = $this->makeTool(true, $search)->execute(
            ['lieA' => ['entite' => 'Client', 'id' => 82], 'perimetre' => PortefeuilleScope::PERIMETRE_ENTREPRISE],
            $this->makeScope(),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(
            ['operator' => '=', 'value' => 82],
            $criteres['tranche.cotation.piste.client'],
        );
        $this->assertSame(['entite' => 'Client', 'id' => 82], $result->data['lien']);
    }

    public function testMatchDistingueLaLectureDeLAction(): void
    {
        $tool = $this->makeTool(true);
        $scope = $this->makeScope();

        $this->assertSame(
            ['trancheId' => 12],
            $tool->match('Quels paiements de prime ont été signalés sur la tranche 12 ?', $scope),
        );
        $this->assertSame(
            ['trancheId' => 5],
            $tool->match('La prime de la tranche n°5 a-t-elle été payée ?', $scope),
        );
        $this->assertSame([], $tool->match('Montre-moi les paiements de prime signalés', $scope));
        $this->assertSame(
            ['perimetre' => PortefeuilleScope::PERIMETRE_ENTREPRISE],
            $tool->match('Liste les paiements de prime de toute l\'entreprise', $scope),
        );

        // L'ACTION reste à signaler_paiement_prime, et les autres domaines ne déclenchent pas.
        $this->assertNull($tool->match('Signale le paiement de la prime de la tranche 71', $scope));
        $this->assertNull($tool->match('Liste les paiements', $scope));
        $this->assertNull($tool->match('Liste les tranches impayées', $scope));
    }

    /**
     * Le mot « paiements » du lexique désigne la rubrique Paiements (TRÉSORERIE du
     * courtier) : sans la garde partagée, les outils génériques captaient la question et
     * répondaient sur le mauvais circuit métier. Ils doivent désormais passer la main.
     */
    public function testLesOutilsGeneriquesNeCaptentPlusLesPaiementsDePrime(): void
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn([
            'Paiement' => 'Paiements',
            'Tranche'  => 'Tranches',
        ]);
        $search = $this->createMock(JSBDynamicSearchService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $lexique = new EntiteLexique($resolver);
        $libelleur = new EntiteLibelle($em);
        $scope = $this->makeScope();

        $rechercher = new RechercherEntitesTool($resolver, $search, $lexique, $libelleur, $em, $this->fabriquePortefeuille());
        $compter = new CompterEntitesTool($resolver, $search, $lexique, $this->fabriquePortefeuille());
        $lireFiche = new LireFicheTool($resolver, $search, $lexique, $libelleur, new FicheNormaliseur($this->createMock(NormalizerInterface::class)));

        $this->assertNull($rechercher->match('Liste les paiements de prime de la tranche 12', $scope));
        $this->assertNull($compter->match('Combien de paiements de prime ont été signalés ?', $scope));
        $this->assertNull($lireFiche->match('Donne-moi les informations du paiement de prime de la tranche 12', $scope));

        // Non-régression : les questions sur la vraie rubrique Paiements passent toujours.
        $this->assertSame(['entite' => 'Paiement'], $rechercher->match('Liste les paiements', $scope));
    }

    /** Les deux outils dédiés se répartissent la question sans se marcher dessus. */
    public function testLectureEtActionNeSeRecouvrentPas(): void
    {
        $signaler = new SignalerPaiementPrimeTool(
            $this->createMock(WorkspaceAccessResolver::class),
            $this->createMock(JSBDynamicSearchService::class),
        );
        $lecture = $this->makeTool(true);
        $scope = $this->makeScope();

        $question = 'Quels paiements de prime ont été signalés sur la tranche 12 ?';
        $this->assertNull($signaler->match($question, $scope));
        $this->assertSame(['trancheId' => 12], $lecture->match($question, $scope));

        $action = 'Signale le paiement de la prime de la tranche 12';
        $this->assertSame(['trancheId' => 12], $signaler->match($action, $scope));
        $this->assertNull($lecture->match($action, $scope));
    }
}
