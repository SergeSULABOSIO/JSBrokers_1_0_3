<?php

namespace App\Tests\Ai;

use App\Ai\Proposition\RevenuCourtierPrescrit;
use App\Ai\Scope\AiScope;
use App\Entity\Chargement;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use PHPUnit\Framework\TestCase;

/**
 * La rémunération du courtier ajoutée d'office à une proposition.
 *
 * CE QUE CE TEST PROTÈGE. Sur les données réelles, aucune proposition dictée à Ket ne
 * repartait avec un revenu : l'outil ne le dérivait que si l'entreprise n'avait qu'UN
 * type de revenu, alors que la plateforme en installe QUATRE à la création de chaque
 * entreprise. Le référentiel monté ici est donc le VRAI (celui de
 * ServiceInitialisationEntreprise, vérifié en base sur l'entreprise 1) — un test sur un
 * référentiel inventé à un seul type aurait laissé passer la panne pendant des mois.
 *
 * Deux promesses tenues ensemble : la commission due par l'ASSUREUR est ajoutée sans
 * qu'on la demande, et aucun taux n'est jamais inventé — un risque sans taux prescrit
 * devient une question, pas une ligne à zéro.
 */
class RevenuCourtierPrescritTest extends TestCase
{
    use ResolveurDeTest;

    /** Chargements de l'entreprise, par identifiant. */
    private const PRIME_NETTE = 1;
    private const FRONTING = 2;

    /** Types de revenu, par identifiant (mêmes rôles que le référentiel réel). */
    private const COMMISSION_ORDINAIRE = 1;
    private const COMMISSION_FRONTING = 2;
    private const CONSULTANCE = 3;
    private const HONORAIRE = 4;
    private const AJUSTEMENT_SYSTEME = 5;

    private const ETAPE = 'Le revenu du courtier (commission)';

    /**
     * @param array<int, TypeRevenu> $types
     */
    private function service(
        ?array $types = null,
        ?Piste $piste = null,
        bool $peutLire = true,
    ): RevenuCourtierPrescrit {
        $types ??= $this->referentielReel();

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            static function (string $fqcn) use ($types, $piste) {
                $data = match ($fqcn) {
                    TypeRevenu::class => $types,
                    Piste::class      => $piste !== null ? [$piste] : [],
                    default           => [],
                };

                return ['status' => ['code' => 200], 'data' => $data];
            }
        );

        $acces = $this->createMock(WorkspaceAccessResolver::class);
        $acces->method('can')->willReturn($peutLire);
        $acces->method('canRead')->willReturn($peutLire);
        $acces->method('libellesEntites')->willReturn([]);

        return new RevenuCourtierPrescrit($search, $acces, $this->resolveurAvec([], $acces));
    }

    private function scope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    /**
     * Le référentiel RÉEL installé par ServiceInitialisationEntreprise : deux
     * commissions dues par l'assureur, deux frais dus par le client, un type système.
     *
     * @return array<int, TypeRevenu>
     */
    private function referentielReel(): array
    {
        $primeNette = $this->avecId(new Chargement(), self::PRIME_NETTE);
        $primeNette->setNom('Prime nette');
        $fronting = $this->avecId(new Chargement(), self::FRONTING);
        $fronting->setNom('Fronting');

        return [
            // Le taux n'est PAS porté par le type : il est prescrit par le risque.
            $this->typeRevenu(self::COMMISSION_ORDINAIRE, 'Commission Ordinaire', $primeNette, TypeRevenu::REDEVABLE_ASSUREUR)
                ->setAppliquerPourcentageDuRisque(true),
            // Taux en POINTS (30 = 30 %), cf. TypeRevenu::getFraction.
            $this->typeRevenu(self::COMMISSION_FRONTING, 'Commission sur Fronting', $fronting, TypeRevenu::REDEVABLE_ASSUREUR)
                ->setPourcentage(30),
            $this->typeRevenu(self::CONSULTANCE, 'Frais de consultance', $primeNette, TypeRevenu::REDEVABLE_CLIENT)
                ->setPourcentage(5),
            $this->typeRevenu(self::HONORAIRE, 'Honoraire de gestion', $primeNette, TypeRevenu::REDEVABLE_CLIENT)
                ->setPourcentage(2),
            // Le nom RÉELLEMENT trouvé en base (entreprise 1) : « écart » en minuscule, là
            // où la constante déclare « Écart ». Une comparaison au caractère près laissait
            // passer ce type — dû par l'assureur, assis sur la prime nette et sans taux —,
            // qui réclamait alors un taux de commission pour un artefact interne.
            $this->typeRevenu(self::AJUSTEMENT_SYSTEME, '[Ajustement système - écart commission]', $primeNette, TypeRevenu::REDEVABLE_ASSUREUR),
        ];
    }

    private function typeRevenu(int $id, string $nom, Chargement $chargement, int $redevable): TypeRevenu
    {
        $type = $this->avecId(new TypeRevenu(), $id);

        return $type->setNom($nom)->setTypeChargement($chargement)->setRedevable($redevable)
            ->setShared(false)->setMultipayments(true);
    }

    /** Une piste dont le risque prescrit (ou non) un taux de commission. */
    private function piste(?float $tauxRisque, string $nomRisque = 'Incendie et Risques Annexes'): Piste
    {
        $risque = (new Risque())->setNomComplet($nomRisque)->setCode('FAP')->setImposable(true)
            ->setPourcentageCommissionSpecifiqueHT($tauxRisque);

        return (new Piste())->setRisque($risque);
    }

    /** @template T of object @param T $entity @return T */
    private function avecId(object $entity, int $id): object
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setValue($entity, $id);

        return $entity;
    }

    /**
     * Le cas courant, et celui qui était cassé : une prime nette dictée suffit à faire
     * naître la commission du courtier, au taux que le risque prescrit.
     */
    public function testUnePrimeNetteSuffitAFaireNaitreLaCommission(): void
    {
        $resultat = $this->service(piste: $this->piste(15.0))
            ->deriver(41, [self::PRIME_NETTE => 1000.0], self::PRIME_NETTE, null, self::ETAPE, $this->scope());

        $this->assertSame([], $resultat['aDemander']);
        $this->assertCount(1, $resultat['elements']);

        $revenu = $resultat['elements'][0];
        $this->assertSame('create', $revenu['op']);
        $this->assertSame(self::ETAPE, $revenu['etape']);
        $this->assertSame(self::COMMISSION_ORDINAIRE, $revenu['champs']['typeRevenu']);
        $this->assertSame('Commission Ordinaire', $revenu['champs']['nom']);
        // RIEN n'est recopié : le taux du risque s'applique à la LECTURE, et suivra le
        // risque s'il change. Un taux figé ici serait un taux périmé demain.
        $this->assertArrayNotHasKey('tauxExceptionel', $revenu['champs']);
    }

    /** Un défaut appliqué qui ne se dit pas est un défaut subi : le taux, sa source, le montant. */
    public function testLeDefautAnnonceLeTauxSaSourceEtLeMontant(): void
    {
        $resultat = $this->service(piste: $this->piste(15.0))
            ->deriver(41, [self::PRIME_NETTE => 1000.0], self::PRIME_NETTE, null, self::ETAPE, $this->scope());

        $annonce = implode(' ', $resultat['defauts']);
        $this->assertStringContainsString('Commission Ordinaire', $annonce);
        $this->assertStringContainsString('15,00 %', $annonce);
        $this->assertStringContainsString('Incendie et Risques Annexes', $annonce);
        $this->assertStringContainsString('150,00', $annonce, 'Le montant attendu doit être annoncé.');
    }

    /**
     * « Commission sur Fronting » n'a de sens que si une ligne de fronting a été dictée :
     * sans assiette, elle vaudrait 0. Avec, elle s'ajoute d'elle-même.
     */
    public function testLaCommissionSurFrontingSuitLaPresenceDeSonAssiette(): void
    {
        $service = $this->service(piste: $this->piste(15.0));
        $scope = $this->scope();

        $sansFronting = $service->deriver(41, [self::PRIME_NETTE => 1000.0], self::PRIME_NETTE, null, self::ETAPE, $scope);
        $this->assertSame(
            [self::COMMISSION_ORDINAIRE],
            array_column(array_column($sansFronting['elements'], 'champs'), 'typeRevenu'),
        );

        $avecFronting = $service->deriver(
            41,
            [self::PRIME_NETTE => 1000.0, self::FRONTING => 300.0],
            self::PRIME_NETTE,
            null,
            self::ETAPE,
            $scope,
        );
        $this->assertSame(
            [self::COMMISSION_ORDINAIRE, self::COMMISSION_FRONTING],
            array_column(array_column($avecFronting['elements'], 'champs'), 'typeRevenu'),
        );
        // 30 % des 300 de fronting : le taux du TYPE, faute de taux du risque sur ce type.
        $this->assertStringContainsString('90,00', implode(' ', $avecFronting['defauts']));
    }

    /**
     * Les frais dus par le CLIENT se négocient : les présumer facturerait l'assuré à son
     * insu. Le type système d'ajustement, lui, n'a rien à faire sur une proposition neuve.
     */
    public function testNiLesFraisDusParLeClientNiLeTypeSystemeNeSontAjoutes(): void
    {
        $resultat = $this->service(piste: $this->piste(15.0))
            ->deriver(41, [self::PRIME_NETTE => 1000.0], self::PRIME_NETTE, null, self::ETAPE, $this->scope());

        $types = array_column(array_column($resultat['elements'], 'champs'), 'typeRevenu');
        $this->assertNotContains(self::CONSULTANCE, $types);
        $this->assertNotContains(self::HONORAIRE, $types);
        $this->assertNotContains(self::AJUSTEMENT_SYSTEME, $types);
    }

    /**
     * LA RÈGLE CARDINALE : on ne devine pas un taux. Un risque sans « % commission
     * spécifique HT » (cas réel « TRANSPORT DES FONDS ») produit une QUESTION nommant le
     * risque — pas une commission écrite à zéro, qui se lirait plus tard comme une
     * affaire sans rémunération.
     */
    public function testUnRisqueSansTauxPrescritDemandeAuLieuDEcrireZero(): void
    {
        $resultat = $this->service(piste: $this->piste(null, 'TRANSPORT DES FONDS'))
            ->deriver(41, [self::PRIME_NETTE => 1000.0], self::PRIME_NETTE, null, self::ETAPE, $this->scope());

        $this->assertSame([], $resultat['elements'], 'Aucune ligne ne doit être écrite sans taux.');
        $this->assertCount(1, $resultat['aDemander']);
        $this->assertSame('tauxCommissionPercent', $resultat['aDemander'][0]['champ']);
        $this->assertStringContainsString('TRANSPORT DES FONDS', $resultat['aDemander'][0]['question']);
        $this->assertStringContainsString('Commission Ordinaire', $resultat['aDemander'][0]['question']);
    }

    /** Même exigence quand c'est l'opportunité qui n'a pas de risque : on nomme le manque. */
    public function testUneOpportuniteSansRisqueDemandeLeTaux(): void
    {
        $resultat = $this->service(piste: new Piste())
            ->deriver(41, [self::PRIME_NETTE => 1000.0], self::PRIME_NETTE, null, self::ETAPE, $this->scope());

        $this->assertSame([], $resultat['elements']);
        $this->assertStringContainsString('risque', $resultat['aDemander'][0]['question']);
    }

    /**
     * Un taux dicté DÉROGE : il se pose sur la commission principale (celle assise sur la
     * prime nette), pas sur les accessoires — étendre la dérogation au fronting
     * prononcerait un choix que l'utilisateur n'a pas fait.
     */
    public function testUnTauxDicteNeSePoseQueSurLaCommissionPrincipale(): void
    {
        $resultat = $this->service(piste: $this->piste(15.0))->deriver(
            41,
            [self::PRIME_NETTE => 1000.0, self::FRONTING => 300.0],
            self::PRIME_NETTE,
            20.0,
            self::ETAPE,
            $this->scope(),
        );

        $parType = [];
        foreach ($resultat['elements'] as $element) {
            $parType[$element['champs']['typeRevenu']] = $element['champs'];
        }

        // Convention unique de la plateforme : les taux sont des POINTS (20 = 20 %).
        $this->assertSame(20.0, $parType[self::COMMISSION_ORDINAIRE]['tauxExceptionel']);
        $this->assertArrayNotHasKey('tauxExceptionel', $parType[self::COMMISSION_FRONTING]);
        $this->assertStringContainsString('20,00 %', implode(' ', $resultat['defauts']));
    }

    /**
     * Un taux dicté sur une entreprise à un seul revenu applicable n'a pas besoin qu'on
     * identifie la prime nette : il n'y a qu'un destinataire possible.
     */
    public function testUnTauxDicteTrouveSonUniqueDestinataireSansPrimeNetteIdentifiee(): void
    {
        $resultat = $this->service(piste: $this->piste(null))
            ->deriver(41, [self::PRIME_NETTE => 1000.0], null, 12.5, self::ETAPE, $this->scope());

        $this->assertSame([], $resultat['aDemander'], 'Le taux dicté remplace le taux manquant du risque.');
        $this->assertSame(12.5, $resultat['elements'][0]['champs']['tauxExceptionel']);
    }

    /** FAIL-CLOSED, mais jamais silencieux : une omission qui ne se dit pas se lit « rien à facturer ». */
    public function testSansDroitDeLectureLOmissionEstDite(): void
    {
        $resultat = $this->service(piste: $this->piste(15.0), peutLire: false)
            ->deriver(41, [self::PRIME_NETTE => 1000.0], self::PRIME_NETTE, null, self::ETAPE, $this->scope());

        $this->assertSame([], $resultat['elements']);
        $this->assertSame([], $resultat['aDemander']);
        $this->assertNotEmpty($resultat['avertissements']);
    }

    /**
     * Aucun revenu ne s'adosse aux composantes dictées : on ne se rabat pas sur un type
     * payé par le client, mais on NOMME ce qui existe pour que l'utilisateur puisse trancher.
     */
    public function testAucunTypeAdossableEstDitEtNommeLesTypesExistants(): void
    {
        $resultat = $this->service(piste: $this->piste(15.0))
            // Une composition faite d'une seule taxe : aucune assiette de commission.
            ->deriver(41, [99 => 160.0], null, null, self::ETAPE, $this->scope());

        $this->assertSame([], $resultat['elements']);
        $avertissement = implode(' ', $resultat['avertissements']);
        $this->assertStringContainsString('Commission Ordinaire', $avertissement);
        $this->assertStringContainsString('Frais de consultance', $avertissement);
        $this->assertStringNotContainsString(
            'Ajustement',
            $avertissement,
            'Le type système est un artefact interne : il n’a pas à être proposé, quelle que soit sa casse.',
        );
    }
}
