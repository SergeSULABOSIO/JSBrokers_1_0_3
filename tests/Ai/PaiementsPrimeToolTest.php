<?php

namespace App\Tests\Ai;

use App\Ai\FicheNormaliseur;
use App\Ai\Presentation\Colonnes;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\CompterEntitesTool;
use App\Ai\Tool\EntiteLexique;
use App\Ai\Tool\EntiteLibelle;
use App\Ai\Tool\LireFicheTool;
use App\Ai\Tool\PaiementsPrimeTool;
use App\Ai\Tool\RechercherEntitesTool;
use App\Ai\Tool\SignalerPaiementPrimeTool;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\PaiementPrime;
use App\Entity\Piste;
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
            // Le préchargement du contexte n'est sollicité qu'avec des tranches
            // PERSISTÉES (il travaille par identifiants) : sur ces entités en mémoire,
            // il rend la main sans émettre une requête. Le test reste pur.
            $this->createMock(EntityManagerInterface::class),
        );
    }

    /**
     * Un signalement replacé dans son graphe réel : tranche → cotation → assureur, et
     * cotation → piste → client, plus l'avenant qui porte la référence de police.
     */
    private function signalementContextualise(): PaiementPrime
    {
        $client = (new Client())->setNom('Fracht Trading Mauritius');
        $assureur = (new Assureur())->setNom('SFA Assurances');
        $piste = (new Piste())->setClient($client);
        $avenant = (new Avenant())->setReferencePolice('POL-2026-0042');

        $cotation = (new Cotation())->setAssureur($assureur)->setPiste($piste);
        $cotation->addAvenant($avenant);

        $tranche = (new Tranche())->setNom('Tranche unique - import bordereau');
        $tranche->setCotation($cotation);

        return $this->signalement('PRIME-05082026-160239', 3195.16, '2026-08-05')->setTranche($tranche);
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
        // Les clés vides sont élaguées (économie de tokens) : pas d'id sur une entité non
        // persistée, et aucun contexte client/assureur en mode ciblé — l'entête le donne
        // déjà. L'ORDRE des clés suit celui de la présentation déclarée.
        $this->assertSame(
            ['date' => '2026-07-10', 'reference' => 'PP-002', 'montant' => 200.0, 'description' => 'Avis de règlement transmis par l\'assureur'],
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

    /**
     * L'INCIDENT DU 2026-08-10, verrouillé.
     *
     * Un courtier demande la liste des primes signalées. La projection ne rendait NI le
     * client NI l'assureur : Ket a produit un tableau dont la colonne « Client » était
     * découpée dans le préfixe des références (« MIC-RC » n'est pas un client, c'est le
     * début de « MIC-RC0012454/2028 ») et dont la colonne « Assureur » portait le
     * libellé générique « Assureur Partenaire ». Relancée, elle a répondu — à juste
     * titre — que ses outils ne lui donnaient pas le nom de la compagnie.
     *
     * LA RÈGLE : une colonne PRÉSENTABLE est une colonne RENVOYÉE. Tant qu'une
     * information manque au résultat, le modèle n'a que deux issues, la taire ou
     * l'inventer.
     */
    public function testLeModeTransversalRendLeVraiClientEtLeVraiAssureur(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn($this->reponse([$this->signalementContextualise()]));

        $result = $this->makeTool(true, $search)->execute([], $this->makeScope($this->inviteAvecId(9)));
        $ligne = $result->data['items'][0];

        $this->assertSame('Fracht Trading Mauritius', $ligne['client']);
        $this->assertSame('SFA Assurances', $ligne['assureur']);
        $this->assertSame('POL-2026-0042', $ligne['police']);
        // Le rattachement est APLATI : une valeur structurée n'a pas de rendu de cellule
        // honnête, et le tableau de repli écarte les sous-tableaux.
        $this->assertSame('Tranche unique - import bordereau', $ligne['tranche']);
        $this->assertSame('PRIME-05082026-160239', $ligne['reference']);
        $this->assertSame(3195.16, $ligne['montant']);
    }

    /**
     * LA PRÉSENTATION DÉCLARÉE : l'ordre des colonnes, leurs rôles et la colonne à
     * totaliser. C'est ce qui fixe l'alignement des montants, le format des dates et la
     * ligne de totaux — pour le modèle comme pour le repli PHP.
     */
    public function testLeModeTransversalDeclareSesColonnesEtLeurRole(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn($this->reponse([$this->signalementContextualise()]));

        $presentation = $this->makeTool(true, $search)
            ->execute([], $this->makeScope($this->inviteAvecId(9)))
            ->data['presentation'];

        $this->assertSame(
            ['date', 'client', 'assureur', 'police', 'reference', 'montant'],
            array_keys($presentation['colonnes']),
        );
        $this->assertSame(Colonnes::DATE, $presentation['colonnes']['date']);
        $this->assertSame(Colonnes::MONTANT, $presentation['colonnes']['montant']);
        $this->assertSame(['montant'], $presentation['totaliser']);
    }

    /**
     * Une cotation SANS avenant n'est qu'une proposition : elle n'a pas de police. On
     * rend alors la clé absente, jamais un libellé de remplissage — c'est exactement ce
     * qu'on reproche au modèle.
     */
    public function testUneCotationSansAvenantNeFabriquePasDeReferenceDePolice(): void
    {
        $tranche = (new Tranche())->setNom('Tranche unique');
        $tranche->setCotation((new Cotation())->setAssureur((new Assureur())->setNom('SFA Assurances')));

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn($this->reponse([
            $this->signalement('PP-020', 100.0, '2026-07-01')->setTranche($tranche),
        ]));

        $ligne = $this->makeTool(true, $search)
            ->execute([], $this->makeScope($this->inviteAvecId(9)))
            ->data['items'][0];

        $this->assertArrayNotHasKey('police', $ligne);
        $this->assertArrayNotHasKey('client', $ligne);
        $this->assertSame('SFA Assurances', $ligne['assureur']);
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

        $rechercher = new RechercherEntitesTool(
            $resolver,
            $search,
            $lexique,
            $libelleur,
            $em,
            $this->fabriquePortefeuille(),
            $this->createMock(\App\Services\Canvas\Indicator\IndicatorCalculationHelper::class),
            // Classe finale (source unique volontairement non doublable) : instance
            // réelle sur des dépôts doublés — ce test ne vérifie que le routage des
            // intentions, jamais la résolution du renouvellement.
            new \App\Services\AvenantRenouvellementResolver(
                $this->createMock(\App\Repository\PisteRepository::class),
                $this->createMock(\App\Repository\AvenantRepository::class),
            ),
            new \App\Ai\Resolution\ResolveurDeReferences($search, $resolver, $libelleur),
            new \App\Ai\Resolution\CheminsDeRelation($em),
        );
        $compter = new CompterEntitesTool($resolver, $search, $lexique, $this->fabriquePortefeuille());
        $lireFiche = new LireFicheTool(
            $resolver,
            $search,
            $lexique,
            $libelleur,
            new FicheNormaliseur(
                $this->createMock(NormalizerInterface::class),
                $this->createMock(\App\Services\Canvas\CalculationProvider::class),
            ),
            $this->createMock(\App\Service\Workspace\FormTreeInspector::class),
            $this->createMock(\App\Token\TokenAccountService::class),
            $this->createMock(\App\Service\Workspace\ChampsObligatoiresInspector::class),
        );

        $this->assertNull($rechercher->match('Liste les paiements de prime de la tranche 12', $scope));
        $this->assertNull($compter->match('Combien de paiements de prime ont été signalés ?', $scope));
        $this->assertNull($lireFiche->match('Donne-moi les informations du paiement de prime de la tranche 12', $scope));

        // Non-régression : les questions sur la vraie rubrique Paiements passent toujours.
        $this->assertSame(['entite' => 'Paiement'], $rechercher->match('Liste les paiements', $scope));
    }

    /** Les deux outils dédiés se répartissent la question sans se marcher dessus. */
    public function testLectureEtActionNeSeRecouvrentPas(): void
    {
        // match() n'utilise aucune dépendance injectée (juste AiText/PaiementPrimeIntent) :
        // on évite d'avoir à câbler le délégué PreparerOperationsTool (classe finale).
        $signaler = (new \ReflectionClass(SignalerPaiementPrimeTool::class))->newInstanceWithoutConstructor();
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
