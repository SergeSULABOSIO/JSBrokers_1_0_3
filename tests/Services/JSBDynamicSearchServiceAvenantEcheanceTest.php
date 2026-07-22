<?php

namespace App\Tests\Services;

use App\Controller\Admin\AvenantController;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\AvenantIndicatorStrategy;
use App\Services\Canvas\SearchCanvasProvider;
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

        // Enfants avant parents (contraintes FK) : avenant → cotation → piste →
        // assureur/client/risque → portefeuille → invite, puis l'entreprise et
        // l'utilisateur propriétaire.
        foreach (['avenant', 'cotation', 'piste', 'assureur', 'client', 'risque', 'portefeuille', 'invite'] as $table) {
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
     * $assureur/$risque alimentent les chemins de recherche cotation.assureur et
     * cotation.piste.risque.
     */
    private function makeCotation(
        Entreprise $entreprise,
        Invite $invite,
        string $nom,
        ?Portefeuille $portefeuille,
        ?Assureur $assureur = null,
        ?Risque $risque = null
    ): Cotation {
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
        $piste->setRisque($risque);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom($nom)->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setAssureur($assureur);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        return $cotation;
    }

    private function makeAssureur(Entreprise $entreprise, string $nom, string $suffixe): Assureur
    {
        $assureur = (new Assureur())->setNom($nom)->setEmail('assureur-' . strtolower($suffixe) . '@test.local')
            ->setNumimpot('IMP-' . $suffixe)->setIdnat('IDNAT-' . $suffixe)->setRccm('RCCM-' . $suffixe);
        $assureur->setEntreprise($entreprise);
        $this->em()->persist($assureur);

        return $assureur;
    }

    private function makeRisque(Entreprise $entreprise, Invite $invite, string $nomComplet, string $code): Risque
    {
        $risque = (new Risque())->setNomComplet($nomComplet)->setCode($code)
            ->setDescription('Risque ' . $code)->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $risque->setInvite($invite);
        $this->em()->persist($risque);

        return $risque;
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

    /**
     * Recherche avancée : les critères « Assureur », « Client » et « Risque » doivent être
     * exposés au dialogue comme de vraies relations autocomplétées (mêmes chemins que la
     * rubrique Tranches), sans quoi la barre ne peut jamais les proposer.
     */
    public function testCanevasDeRechercheExposeAssureurClientRisque(): void
    {
        /** @var SearchCanvasProvider $provider */
        $provider = static::getContainer()->get(SearchCanvasProvider::class);

        $parNom = [];
        foreach ($provider->getCanvas(Avenant::class) as $critere) {
            $parNom[$critere['Nom']] = $critere;
        }

        foreach ([
            'cotation.assureur' => ['Assureur', 'Assureur', 'nom'],
            'cotation.piste.client' => ['Client', 'Client', 'nom'],
            'cotation.piste.risque' => ['Risque', 'Risque', 'nomComplet'],
        ] as $code => [$display, $targetEntity, $displayField]) {
            $this->assertArrayHasKey($code, $parNom, sprintf('Le critère « %s » doit être exposé dans le canevas de recherche d\'Avenant.', $display));
            $this->assertSame('Relation', $parNom[$code]['Type'], sprintf('« %s » doit être un sélecteur autocomplété.', $display));
            $this->assertSame($display, $parNom[$code]['Display']);
            $this->assertSame($targetEntity, $parNom[$code]['targetEntity'], 'L\'entité cible pilote l\'endpoint d\'autocomplétion.');
            $this->assertSame($displayField, $parNom[$code]['displayField'], 'Le champ d\'affichage doit être une colonne persistée (LIKE SQL).');
        }
    }

    /**
     * Rubrique Avenants : filtrer par assureur / assuré (client) / risque via les chemins
     * `cotation.assureur`, `cotation.piste.client` et `cotation.piste.risque`, en plus de
     * tout critère déjà actif. Vérifie le filtrage par identité (sélecteur autocomplété),
     * le repli texte (LIKE), la composition ET entre ces critères, et leur composition avec
     * les critères synthétiques propres à la rubrique — chip « Échéance » (chemin SQL
     * spécifique d'Avenant) et périmètre « Mon portefeuille ».
     */
    public function testFiltreParAssureurClientRisqueSeComposeAvecAutresCriteres(): void
    {
        $s = $this->seed();
        $em = $this->em();
        $entrepriseA = $em->getRepository(Entreprise::class)->find($s['entrepriseA']);
        $inviteA = $em->getRepository(Invite::class)->find($s['inviteA']);

        // Jeu « A » posé sur la cotation du portefeuille (porteuse des 5 avenants du seed).
        $assureurA = $this->makeAssureur($entrepriseA, 'Assureur A', 'A');
        $risqueA = $this->makeRisque($entrepriseA, $inviteA, 'Risque Incendie A', 'RQ-A');
        $cotationA = $em->getRepository(Avenant::class)->find($s['echu'])->getCotation();
        $cotationA->setAssureur($assureurA);
        $cotationA->getPiste()->setRisque($risqueA);
        $clientA = $cotationA->getPiste()->getClient();
        $portefeuille = $clientA->getPortefeuille();

        // Jeu « B » : cotation + avenant expiré témoin, même entreprise, HORS portefeuille.
        $assureurB = $this->makeAssureur($entrepriseA, 'Assureur B', 'B');
        $risqueB = $this->makeRisque($entrepriseA, $inviteA, 'Risque Auto B', 'RQ-B');
        $cotationB = $this->makeCotation($entrepriseA, $inviteA, 'Cotation Filtre B', null, $assureurB, $risqueB);
        $avenantB = $this->makeAvenant($cotationB, $entrepriseA, $inviteA, 'POL-FILTRE-B', new \DateTimeImmutable('-10 days'));

        $em->flush();

        $clientAId = $clientA->getId();
        $clientBId = $cotationB->getPiste()->getClient()->getId();
        $assureurAId = $assureurA->getId();
        $assureurBId = $assureurB->getId();
        $risqueAId = $risqueA->getId();
        $avenantBId = $avenantB->getId();
        $portefeuilleAvenants = [$s['echu'], $s['sous30Proche'], $s['sous30Loin'], $s['entre3160'], $s['auDela']];

        // 1) Filtrage par IDENTITÉ (sélecteur autocomplété) sur chacun des 3 critères :
        // seuls les avenants de la cotation « A » remontent — jamais celui de « B », jamais
        // celui de la cotation hors portefeuille (sans assureur/risque, autre client), ni
        // l'avenant de l'entreprise B (scoping entreprise).
        foreach ([
            'cotation.assureur' => $assureurAId,
            'cotation.piste.client' => $clientAId,
            'cotation.piste.risque' => $risqueAId,
        ] as $champ => $id) {
            $resultat = $this->service()->search(Avenant::class, [$champ => ['operator' => '=', 'value' => $id]], $entrepriseA);
            $this->assertNull($resultat['status']['error'], "Champ {$champ}");
            $this->assertEqualsCanonicalizing($portefeuilleAvenants, $this->ids($resultat), "Filtrage par {$champ} (identité)");
            $this->assertNotContains($avenantBId, $this->ids($resultat));
            $this->assertNotContains($s['echuHorsPf'], $this->ids($resultat));
            $this->assertNotContains($s['futurB'], $this->ids($resultat));
        }

        // 2) Repli texte (LIKE sur le champ d'affichage), utilisé par la recherche simple
        // ou en l'absence de sélection dans l'autocomplete.
        $parNom = $this->service()->search(
            Avenant::class,
            ['cotation.assureur' => ['operator' => 'LIKE', 'value' => 'Assureur A', 'targetField' => 'nom']],
            $entrepriseA,
        );
        $this->assertEqualsCanonicalizing($portefeuilleAvenants, $this->ids($parNom));

        $parNomB = $this->service()->search(
            Avenant::class,
            ['cotation.assureur' => ['operator' => 'LIKE', 'value' => 'Assureur B', 'targetField' => 'nom']],
            $entrepriseA,
        );
        $this->assertSame([$avenantBId], $this->ids($parNomB));

        // 3) Composition ET entre deux critères de relation : l'assureur de « A » combiné au
        // client de « B » ne peut rien matcher — preuve que les critères s'ADDITIONNENT.
        $contradictoire = $this->service()->search(
            Avenant::class,
            [
                'cotation.assureur' => ['operator' => '=', 'value' => $assureurAId],
                'cotation.piste.client' => ['operator' => '=', 'value' => $clientBId],
            ],
            $entrepriseA,
        );
        $this->assertSame(0, $contradictoire['totalItems'], 'Critères contradictoires (A ∩ B) : aucun avenant.');

        // 4) Composition avec le chip « Échéance » (actif par défaut sur la rubrique) : le
        // filtre s'applique À L'INTÉRIEUR du chemin SQL spécifique d'Avenant, sans l'écraser
        // — y compris pour le comptage total.
        $echusAssureurA = $this->service()->search(
            Avenant::class,
            [
                AvenantEcheanceScope::CRITERION_KEY => AvenantEcheanceScope::STATUT_ECHUS,
                'cotation.assureur' => ['operator' => '=', 'value' => $assureurAId],
            ],
            $entrepriseA,
        );
        $this->assertSame([$s['echu']], $this->ids($echusAssureurA), 'Échus + assureur A : le seul expiré de cette cotation.');
        $this->assertSame(1, $echusAssureurA['totalItems'], 'Le comptage total doit refléter le même filtre.');

        $echusAssureurB = $this->service()->search(
            Avenant::class,
            [
                AvenantEcheanceScope::CRITERION_KEY => AvenantEcheanceScope::STATUT_ECHUS,
                'cotation.piste.risque' => ['operator' => '=', 'value' => $risqueB->getId()],
            ],
            $entrepriseA,
        );
        $this->assertSame([$avenantBId], $this->ids($echusAssureurB), 'Échus + risque B : intersection correcte.');

        // 5) Composition avec le périmètre « Mon portefeuille » : le filtre relation s'ajoute
        // au périmètre de sécurité, il ne l'élargit jamais.
        $this->assertNotNull($portefeuille, 'Le client du seed doit bien être rattaché au portefeuille.');
        $perimetreEtRisque = $this->service()->search(
            Avenant::class,
            [
                PortefeuilleScope::CRITERION_KEY => ['operator' => '=', 'value' => $s['inviteA']],
                'cotation.piste.risque' => ['operator' => '=', 'value' => $risqueAId],
            ],
            $entrepriseA,
        );
        $this->assertEqualsCanonicalizing($portefeuilleAvenants, $this->ids($perimetreEtRisque));

        $perimetreEtRisqueB = $this->service()->search(
            Avenant::class,
            [
                PortefeuilleScope::CRITERION_KEY => ['operator' => '=', 'value' => $s['inviteA']],
                'cotation.piste.risque' => ['operator' => '=', 'value' => $risqueB->getId()],
            ],
            $entrepriseA,
        );
        $this->assertSame(0, $perimetreEtRisqueB['totalItems'], 'Le risque « B » est hors portefeuille : aucun avenant visible.');
    }
}
