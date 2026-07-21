<?php

namespace App\Tests\Services;

use App\Controller\Admin\AvenantController;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\AvenantIndicatorStrategy;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\AvenantEcheanceScope;
use App\Services\Search\PortefeuilleScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Moteur de recherche, critère synthétique « Échéance » (Avenant) : contrairement au statut
 * de paiement d'une tranche (dérivé, filtré en mémoire), l'échéance est une VRAIE colonne
 * (Avenant.endingAt) → filtrage et tri par urgence directement en SQL. On vérifie :
 * le filtrage par fenêtre, le tri du plus urgent au moins urgent (endingAt croissant), le
 * scoping entreprise (AuditableTrait) + périmètre portefeuille (« Mon portefeuille »), la
 * classification d'urgence pour le badge, et le chip actif par défaut dynamique (« Échus »
 * si le périmètre contient un avenant expiré, sinon « Sous 30 jours »).
 */
class JSBDynamicSearchServiceAvenantEcheanceTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-avecheance-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit AvEcheance SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit AvEcheance Sans Expire SARL';

    protected function setUp(): void
    {
        static::bootKernel();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function service(): JSBDynamicSearchService
    {
        return static::getContainer()->get(JSBDynamicSearchService::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_B_NOM];

        // Enfants avant parents (contraintes FK) : avenant → cotation → piste → client →
        // portefeuille → invite, puis l'entreprise et l'utilisateur propriétaire.
        foreach (['avenant', 'cotation', 'piste', 'client', 'portefeuille', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }
        $conn->executeStatement(
            "DELETE FROM entreprise WHERE nom IN (:noms)",
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $conn->executeStatement(
            "DELETE FROM utilisateur WHERE email = :email",
            ['email' => self::OWNER_EMAIL]
        );
    }

    private function makeEntreprise(string $nom, Utilisateur $owner): Entreprise
    {
        $entreprise = new Entreprise();
        $entreprise->setNom($nom)->setLicence('LIC-AV')->setAdresse('1 rue des Avenants')
            ->setTelephone('+243000000009')->setRccm('RCCM-AV')->setIdnat('IDNAT-AV')->setNumimpot('IMP-AV');
        $entreprise->setUtilisateur($owner);
        $this->em()->persist($entreprise);

        return $entreprise;
    }

    private function makeInvite(Entreprise $entreprise, Utilisateur $user, string $nom): Invite
    {
        $invite = new Invite();
        $invite->setNom($nom)->setUtilisateur($user)->setEntreprise($entreprise)->setProprietaire(true);
        $this->em()->persist($invite);

        return $invite;
    }

    /**
     * Une cotation rattachée à un client. Si $portefeuille est fourni, le client y est
     * rattaché (chemin du périmètre portefeuille : cotation.piste.client.portefeuille).
     */
    private function makeCotation(Entreprise $entreprise, Invite $invite, string $nom, ?Portefeuille $portefeuille): Cotation
    {
        $em = $this->em();

        $client = (new Client())->setNom('Client ' . $nom)->setExonere(false);
        $client->setEntreprise($entreprise);
        if ($portefeuille !== null) {
            $portefeuille->addClient($client); // côté propriétaire : synchronise client.portefeuille
        }
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Piste ' . $nom)->setTypeAvenant(0)->setDescriptionDuRisque('Risque échéance')
            ->setExercice(2026)->setClient($client)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom($nom)->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        return $cotation;
    }

    private function makeAvenant(Cotation $cotation, Entreprise $entreprise, Invite $invite, string $ref, \DateTimeImmutable $endingAt): Avenant
    {
        $avenant = new Avenant();
        $avenant->setCotation($cotation)->setReferencePolice($ref)->setNumero('0')
            ->setDescription('Avenant ' . $ref)
            ->setStartingAt($endingAt->modify('-365 days'))
            ->setEndingAt($endingAt);
        $avenant->setEntreprise($entreprise);
        $avenant->setInvite($invite);
        $this->em()->persist($avenant);

        return $avenant;
    }

    /**
     * Entreprise A (invité gestionnaire d'un portefeuille) : avenants échelonnés dans les
     * quatre fenêtres, DANS le portefeuille — plus un avenant expiré HORS portefeuille (même
     * entreprise) pour prouver le périmètre. Entreprise B : uniquement du futur (aucun expiré),
     * pour le défaut dynamique « Sous 30 jours ».
     *
     * @return array<string, mixed>
     */
    private function seed(): array
    {
        $em = $this->em();

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit AvEcheance')->setVerified(true)->setPassword('irrelevant');
        $em->persist($ownerUser);

        // --- Entreprise A ---
        $entrepriseA = $this->makeEntreprise(self::ENTREPRISE_NOM, $ownerUser);
        $inviteA = $this->makeInvite($entrepriseA, $ownerUser, 'Gestionnaire A');

        $portefeuille = (new Portefeuille())->setNom('Portefeuille A')->setGestionnaire($inviteA);
        $portefeuille->setEntreprise($entrepriseA);
        $em->persist($portefeuille);

        $cotationPf = $this->makeCotation($entrepriseA, $inviteA, 'Cotation Portefeuille', $portefeuille);
        $echu = $this->makeAvenant($cotationPf, $entrepriseA, $inviteA, 'POL-ECHU', new \DateTimeImmutable('-10 days'));
        $sous30Proche = $this->makeAvenant($cotationPf, $entrepriseA, $inviteA, 'POL-30A', new \DateTimeImmutable('+10 days'));
        $sous30Loin = $this->makeAvenant($cotationPf, $entrepriseA, $inviteA, 'POL-30B', new \DateTimeImmutable('+20 days'));
        $entre3160 = $this->makeAvenant($cotationPf, $entrepriseA, $inviteA, 'POL-45', new \DateTimeImmutable('+45 days'));
        $auDela = $this->makeAvenant($cotationPf, $entrepriseA, $inviteA, 'POL-90', new \DateTimeImmutable('+90 days'));

        // Avenant expiré HORS portefeuille (client sans portefeuille), même entreprise.
        $cotationHorsPf = $this->makeCotation($entrepriseA, $inviteA, 'Cotation Hors PF', null);
        $echuHorsPf = $this->makeAvenant($cotationHorsPf, $entrepriseA, $inviteA, 'POL-ECHU-HORS', new \DateTimeImmutable('-5 days'));

        // --- Entreprise B : aucun avenant expiré (futur seulement) ---
        $entrepriseB = $this->makeEntreprise(self::ENTREPRISE_B_NOM, $ownerUser);
        $inviteB = $this->makeInvite($entrepriseB, $ownerUser, 'Gestionnaire B');
        $portefeuilleB = (new Portefeuille())->setNom('Portefeuille B')->setGestionnaire($inviteB);
        $portefeuilleB->setEntreprise($entrepriseB);
        $em->persist($portefeuilleB);
        $cotationB = $this->makeCotation($entrepriseB, $inviteB, 'Cotation B', $portefeuilleB);
        $futurB = $this->makeAvenant($cotationB, $entrepriseB, $inviteB, 'POL-B-FUTUR', new \DateTimeImmutable('+15 days'));

        $em->flush();
        $ids = [
            'entrepriseA' => $entrepriseA->getId(),
            'inviteA' => $inviteA->getId(),
            'entrepriseB' => $entrepriseB->getId(),
            'inviteB' => $inviteB->getId(),
            'echu' => $echu->getId(),
            'sous30Proche' => $sous30Proche->getId(),
            'sous30Loin' => $sous30Loin->getId(),
            'entre3160' => $entre3160->getId(),
            'auDela' => $auDela->getId(),
            'echuHorsPf' => $echuHorsPf->getId(),
            'futurB' => $futurB->getId(),
        ];
        $em->clear();

        return $ids;
    }

    private function ids(array $resultat): array
    {
        return array_map(static fn (Avenant $a) => $a->getId(), $resultat['data']);
    }

    public function testChaqueChipNeRetourneQueSaFenetre(): void
    {
        $s = $this->seed();
        $entrepriseA = $this->em()->getRepository(Entreprise::class)->find($s['entrepriseA']);

        // « Échus » : les deux expirés de l'entreprise A (dans et hors portefeuille), jamais
        // le futur ; « étranger » de l'entreprise B jamais visible (scoping entreprise).
        $echus = $this->service()->search(Avenant::class, [AvenantEcheanceScope::CRITERION_KEY => AvenantEcheanceScope::STATUT_ECHUS], $entrepriseA);
        $this->assertNull($echus['status']['error']);
        $this->assertEqualsCanonicalizing([$s['echu'], $s['echuHorsPf']], $this->ids($echus));
        $this->assertNotContains($s['futurB'], $this->ids($echus));

        $sous30 = $this->service()->search(Avenant::class, [AvenantEcheanceScope::CRITERION_KEY => AvenantEcheanceScope::STATUT_30J], $entrepriseA);
        $this->assertEqualsCanonicalizing([$s['sous30Proche'], $s['sous30Loin']], $this->ids($sous30));

        $entre3160 = $this->service()->search(Avenant::class, [AvenantEcheanceScope::CRITERION_KEY => AvenantEcheanceScope::STATUT_31_60J], $entrepriseA);
        $this->assertSame([$s['entre3160']], $this->ids($entre3160));

        $auDela = $this->service()->search(Avenant::class, [AvenantEcheanceScope::CRITERION_KEY => AvenantEcheanceScope::STATUT_60_PLUS], $entrepriseA);
        $this->assertSame([$s['auDela']], $this->ids($auDela));

        // « Toutes » (valeur vide) : critère retiré, recherche standard scopée entreprise.
        $toutes = $this->service()->search(Avenant::class, [AvenantEcheanceScope::CRITERION_KEY => ['operator' => '=', 'value' => '']], $entrepriseA);
        $this->assertSame(6, $toutes['totalItems'], 'Les 6 avenants de l\'entreprise A, aucun de l\'entreprise B.');
    }

    public function testTriDuPlusUrgentAuMoinsUrgent(): void
    {
        $s = $this->seed();
        $entrepriseA = $this->em()->getRepository(Entreprise::class)->find($s['entrepriseA']);

        // Deux avenants dans la fenêtre « Sous 30 jours » (J+10 et J+20) : le plus proche
        // (le plus urgent) doit précéder le plus lointain (tri endingAt croissant).
        $sous30 = $this->service()->search(Avenant::class, [AvenantEcheanceScope::CRITERION_KEY => AvenantEcheanceScope::STATUT_30J], $entrepriseA);
        $this->assertSame([$s['sous30Proche'], $s['sous30Loin']], $this->ids($sous30), 'Échéance la plus proche en tête.');
    }

    public function testCombinaisonChipEtPerimetrePortefeuille(): void
    {
        $s = $this->seed();
        $entrepriseA = $this->em()->getRepository(Entreprise::class)->find($s['entrepriseA']);

        // « Échus » COMBINÉ au périmètre « Mon portefeuille » de l'invité A : l'avenant expiré
        // hors portefeuille est exclu (périmètre de sécurité respecté), seul reste celui du
        // portefeuille géré par l'invité.
        $echusScopes = $this->service()->search(
            Avenant::class,
            [
                AvenantEcheanceScope::CRITERION_KEY => AvenantEcheanceScope::STATUT_ECHUS,
                PortefeuilleScope::CRITERION_KEY => ['operator' => '=', 'value' => $s['inviteA']],
            ],
            $entrepriseA,
        );
        $this->assertSame([$s['echu']], $this->ids($echusScopes), 'Seul l\'expiré du portefeuille de l\'invité, pas celui hors portefeuille.');
        $this->assertNotContains($s['echuHorsPf'], $this->ids($echusScopes));
    }

    public function testStatutInvalideRetombeSurCheminStandard(): void
    {
        $s = $this->seed();
        $entrepriseA = $this->em()->getRepository(Entreprise::class)->find($s['entrepriseA']);

        $resultat = $this->service()->search(Avenant::class, [AvenantEcheanceScope::CRITERION_KEY => 'valeur-inconnue'], $entrepriseA);
        $this->assertNull($resultat['status']['error']);
        $this->assertSame(6, $resultat['totalItems'], 'Critère retiré, recherche standard scopée entreprise.');
    }

    public function testClassificationUrgencePourLeBadge(): void
    {
        $ref = new \DateTimeImmutable('2026-07-21 09:00:00');

        $this->assertNull(AvenantEcheanceScope::classifier(null, $ref), 'Pas d\'échéance → aucun badge.');
        $this->assertSame('critique', AvenantEcheanceScope::classifier($ref->modify('-1 day'), $ref)['niveau']);
        $this->assertSame('elevee', AvenantEcheanceScope::classifier($ref->modify('+10 days'), $ref)['niveau']);
        $this->assertSame('moderee', AvenantEcheanceScope::classifier($ref->modify('+45 days'), $ref)['niveau']);
        $this->assertSame('faible', AvenantEcheanceScope::classifier($ref->modify('+90 days'), $ref)['niveau']);

        // Bornes exactes (à minuit) : J+30 encore « sous 30 j », J+31 bascule en 31-60,
        // J+60 encore 31-60, J+61 bascule au-delà.
        $this->assertSame('elevee', AvenantEcheanceScope::classifier($ref->modify('+30 days'), $ref)['niveau']);
        $this->assertSame('moderee', AvenantEcheanceScope::classifier($ref->modify('+31 days'), $ref)['niveau']);
        $this->assertSame('moderee', AvenantEcheanceScope::classifier($ref->modify('+60 days'), $ref)['niveau']);
        $this->assertSame('faible', AvenantEcheanceScope::classifier($ref->modify('+61 days'), $ref)['niveau']);
    }

    public function testStrategieAlimenteLeBadgeUrgence(): void
    {
        /** @var AvenantIndicatorStrategy $strategy */
        $strategy = static::getContainer()->get(AvenantIndicatorStrategy::class);

        $avenant = (new Avenant())->setEndingAt(new \DateTimeImmutable('-3 days'));
        $calc = $strategy->calculate($avenant);
        $this->assertSame('critique', $calc['urgenceEcheanceNiveau'], 'Avenant expiré : badge critique.');
        $this->assertNotEmpty($calc['urgenceEcheance']);

        $sansEcheance = new Avenant();
        $this->assertNull($strategy->calculate($sansEcheance)['urgenceEcheanceNiveau'], 'Sans échéance : pas de badge.');
    }

    public function testChipParDefautDynamiqueSelonExpires(): void
    {
        $s = $this->seed();
        $entrepriseA = $this->em()->getRepository(Entreprise::class)->find($s['entrepriseA']);
        $entrepriseB = $this->em()->getRepository(Entreprise::class)->find($s['entrepriseB']);

        $controller = static::getContainer()->get(AvenantController::class);
        $ref = new \ReflectionMethod(AvenantController::class, 'getInitialSearchCriteria');
        $ref->setAccessible(true);

        // Entreprise A : le portefeuille de l'invité A contient un avenant expiré →
        // le chip « Échus » est actif par défaut (priorité absolue de traitement).
        $criteresA = $ref->invoke($controller, Avenant::class, $s['inviteA'], $entrepriseA);
        $this->assertSame(
            AvenantEcheanceScope::STATUT_ECHUS,
            $criteresA[AvenantEcheanceScope::CRITERION_KEY]['value'],
            'Avenants expirés dans le périmètre → défaut « Échus ».'
        );

        // Entreprise B : aucun avenant expiré → défaut « Sous 30 jours ».
        $criteresB = $ref->invoke($controller, Avenant::class, $s['inviteB'], $entrepriseB);
        $this->assertSame(
            AvenantEcheanceScope::STATUT_30J,
            $criteresB[AvenantEcheanceScope::CRITERION_KEY]['value'],
            'Aucun expiré dans le périmètre → défaut « Sous 30 jours ».'
        );
    }
}
