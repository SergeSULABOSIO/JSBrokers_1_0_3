<?php

namespace App\Tests\Services;

use App\Controller\Admin\CotationController;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Utilisateur;
use App\Services\Canvas\SearchCanvasProvider;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\CotationSouscriptionScope;
use App\Services\Search\PortefeuilleScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Moteur de recherche, critère synthétique « Statut de souscription » (Cotation) : une
 * cotation est « souscrite » dès qu'elle porte au moins un avenant (= transformée en police),
 * « en attente » sinon. La présence d'un avenant est exprimable en SQL (Avenant.cotation),
 * donc le filtrage se fait directement en base (EXISTS / NOT EXISTS), sans service en mémoire.
 * On vérifie : le filtrage par statut, le repli « Toutes » (valeur vide), le scoping entreprise
 * (AuditableTrait) + périmètre portefeuille (« Mon portefeuille »), le chip actif par défaut
 * (« En attente »), et l'exposition du critère au canevas de recherche.
 */
class JSBDynamicSearchServiceCotationSouscriptionTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-cotsouscr-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit CotSouscr SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit CotSouscr Autre SARL';

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
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :email", ['email' => self::OWNER_EMAIL]);
    }

    private function makeEntreprise(string $nom, Utilisateur $owner): Entreprise
    {
        $entreprise = new Entreprise();
        $entreprise->setNom($nom)->setLicence('LIC-CS')->setAdresse('1 rue des Cotations')
            ->setTelephone('+243000000019')->setRccm('RCCM-CS')->setIdnat('IDNAT-CS')->setNumimpot('IMP-CS');
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
     * Une cotation rattachée à un client (optionnellement à un portefeuille pour le périmètre).
     * Si $avecAvenant, un avenant y est ajouté → la cotation devient « souscrite ».
     */
    private function makeCotation(
        Entreprise $entreprise,
        Invite $invite,
        string $nom,
        ?Portefeuille $portefeuille,
        bool $avecAvenant
    ): Cotation {
        $em = $this->em();

        $client = (new Client())->setNom('Client ' . $nom)->setExonere(false);
        $client->setEntreprise($entreprise);
        if ($portefeuille !== null) {
            $portefeuille->addClient($client);
        }
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Piste ' . $nom)->setTypeAvenant(0)->setDescriptionDuRisque('Risque souscription')
            ->setExercice(2026)->setClient($client)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom($nom)->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        if ($avecAvenant) {
            $avenant = new Avenant();
            $avenant->setCotation($cotation)->setReferencePolice('POL-' . $nom)->setNumero('0')
                ->setDescription('Avenant ' . $nom)
                ->setStartingAt(new \DateTimeImmutable('-30 days'))
                ->setEndingAt(new \DateTimeImmutable('+335 days'));
            $avenant->setEntreprise($entreprise);
            $avenant->setInvite($invite);
            $em->persist($avenant);
        }

        return $cotation;
    }

    /**
     * Entreprise A (invité gestionnaire d'un portefeuille) : deux cotations souscrites (avec
     * avenant) et une en attente DANS le portefeuille, plus une souscrite et une en attente
     * HORS portefeuille (même entreprise) pour prouver le périmètre. Entreprise B : une cotation
     * (scoping — jamais visible depuis A).
     *
     * @return array<string, mixed>
     */
    private function seed(): array
    {
        $em = $this->em();

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit CotSouscr')->setVerified(true)->setPassword('irrelevant');
        $em->persist($ownerUser);

        // --- Entreprise A ---
        $entrepriseA = $this->makeEntreprise(self::ENTREPRISE_NOM, $ownerUser);
        $inviteA = $this->makeInvite($entrepriseA, $ownerUser, 'Gestionnaire A');

        $portefeuille = (new Portefeuille())->setNom('Portefeuille A')->setGestionnaire($inviteA);
        $portefeuille->setEntreprise($entrepriseA);
        $em->persist($portefeuille);

        $souscritePf1 = $this->makeCotation($entrepriseA, $inviteA, 'Souscrite PF 1', $portefeuille, true);
        $souscritePf2 = $this->makeCotation($entrepriseA, $inviteA, 'Souscrite PF 2', $portefeuille, true);
        $enAttentePf = $this->makeCotation($entrepriseA, $inviteA, 'En Attente PF', $portefeuille, false);

        // Hors portefeuille (client sans portefeuille), même entreprise.
        $souscriteHorsPf = $this->makeCotation($entrepriseA, $inviteA, 'Souscrite Hors PF', null, true);
        $enAttenteHorsPf = $this->makeCotation($entrepriseA, $inviteA, 'En Attente Hors PF', null, false);

        // --- Entreprise B (scoping) ---
        $entrepriseB = $this->makeEntreprise(self::ENTREPRISE_B_NOM, $ownerUser);
        $inviteB = $this->makeInvite($entrepriseB, $ownerUser, 'Gestionnaire B');
        $portefeuilleB = (new Portefeuille())->setNom('Portefeuille B')->setGestionnaire($inviteB);
        $portefeuilleB->setEntreprise($entrepriseB);
        $em->persist($portefeuilleB);
        $cotationB = $this->makeCotation($entrepriseB, $inviteB, 'Cotation B', $portefeuilleB, true);

        $em->flush();
        $ids = [
            'entrepriseA' => $entrepriseA->getId(),
            'inviteA' => $inviteA->getId(),
            'entrepriseB' => $entrepriseB->getId(),
            'inviteB' => $inviteB->getId(),
            'souscritePf1' => $souscritePf1->getId(),
            'souscritePf2' => $souscritePf2->getId(),
            'enAttentePf' => $enAttentePf->getId(),
            'souscriteHorsPf' => $souscriteHorsPf->getId(),
            'enAttenteHorsPf' => $enAttenteHorsPf->getId(),
            'cotationB' => $cotationB->getId(),
        ];
        $em->clear();

        return $ids;
    }

    private function ids(array $resultat): array
    {
        return array_map(static fn (Cotation $c) => $c->getId(), $resultat['data']);
    }

    public function testChaqueStatutNeRetourneQueSaMoitie(): void
    {
        $s = $this->seed();
        $entrepriseA = $this->em()->getRepository(Entreprise::class)->find($s['entrepriseA']);

        // « Souscrites » : les cotations à avenant de l'entreprise A (dans et hors portefeuille),
        // jamais celles sans avenant ; « étrangère » de l'entreprise B jamais visible (scoping).
        $souscrites = $this->service()->search(Cotation::class, [CotationSouscriptionScope::CRITERION_KEY => CotationSouscriptionScope::STATUT_SOUSCRITES], $entrepriseA);
        $this->assertNull($souscrites['status']['error']);
        $this->assertEqualsCanonicalizing([$s['souscritePf1'], $s['souscritePf2'], $s['souscriteHorsPf']], $this->ids($souscrites));
        $this->assertNotContains($s['cotationB'], $this->ids($souscrites));

        // « En attente » : les cotations SANS avenant de l'entreprise A.
        $enAttente = $this->service()->search(Cotation::class, [CotationSouscriptionScope::CRITERION_KEY => CotationSouscriptionScope::STATUT_EN_ATTENTE], $entrepriseA);
        $this->assertEqualsCanonicalizing([$s['enAttentePf'], $s['enAttenteHorsPf']], $this->ids($enAttente));

        // « Toutes » (valeur vide) : critère retiré, recherche standard scopée entreprise.
        $toutes = $this->service()->search(Cotation::class, [CotationSouscriptionScope::CRITERION_KEY => ['operator' => '=', 'value' => '']], $entrepriseA);
        $this->assertSame(5, $toutes['totalItems'], 'Les 5 cotations de l\'entreprise A, aucune de l\'entreprise B.');
    }

    public function testCombinaisonAvecPerimetrePortefeuille(): void
    {
        $s = $this->seed();
        $entrepriseA = $this->em()->getRepository(Entreprise::class)->find($s['entrepriseA']);

        // « Souscrites » COMBINÉ au périmètre « Mon portefeuille » : seules les souscrites du
        // portefeuille de l'invité, jamais celle hors portefeuille (périmètre de sécurité).
        $souscritesScopes = $this->service()->search(
            Cotation::class,
            [
                CotationSouscriptionScope::CRITERION_KEY => CotationSouscriptionScope::STATUT_SOUSCRITES,
                PortefeuilleScope::CRITERION_KEY => ['operator' => '=', 'value' => $s['inviteA']],
            ],
            $entrepriseA,
        );
        $this->assertEqualsCanonicalizing([$s['souscritePf1'], $s['souscritePf2']], $this->ids($souscritesScopes));
        $this->assertNotContains($s['souscriteHorsPf'], $this->ids($souscritesScopes));

        // « En attente » scopée : uniquement celle du portefeuille.
        $enAttenteScopee = $this->service()->search(
            Cotation::class,
            [
                CotationSouscriptionScope::CRITERION_KEY => CotationSouscriptionScope::STATUT_EN_ATTENTE,
                PortefeuilleScope::CRITERION_KEY => ['operator' => '=', 'value' => $s['inviteA']],
            ],
            $entrepriseA,
        );
        $this->assertSame([$s['enAttentePf']], $this->ids($enAttenteScopee));
    }

    public function testStatutInvalideRetombeSurCheminStandard(): void
    {
        $s = $this->seed();
        $entrepriseA = $this->em()->getRepository(Entreprise::class)->find($s['entrepriseA']);

        $resultat = $this->service()->search(Cotation::class, [CotationSouscriptionScope::CRITERION_KEY => 'valeur-inconnue'], $entrepriseA);
        $this->assertNull($resultat['status']['error']);
        $this->assertSame(5, $resultat['totalItems'], 'Critère retiré, recherche standard scopée entreprise.');
    }

    /**
     * Une piste « bound » (cotation A souscrite) porte une proposition concurrente B non
     * souscrite : B est CADUQUE (le marché est attribué à A). Une piste distincte porte une
     * cotation C non souscrite et seule : C est simplement EN ATTENTE. Les trois statuts
     * partitionnent l'ensemble — le chip « En attente » ne doit PLUS contenir la caduque B.
     */
    public function testCaduquesEtEnAttenteSontDisjoints(): void
    {
        $em = $this->em();

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit CotSouscr')->setVerified(true)->setPassword('irrelevant');
        $em->persist($ownerUser);

        $entreprise = $this->makeEntreprise(self::ENTREPRISE_NOM, $ownerUser);
        $invite = $this->makeInvite($entreprise, $ownerUser, 'Gestionnaire A');

        // Piste bound : A souscrite (avenant), B concurrente non souscrite → B est caduque.
        $client1 = (new Client())->setNom('Client Piste Bound')->setExonere(false);
        $client1->setEntreprise($entreprise);
        $em->persist($client1);
        $pisteBound = (new Piste())->setNom('Piste Bound')->setTypeAvenant(0)->setDescriptionDuRisque('Risque')
            ->setExercice(2026)->setClient($client1)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($pisteBound);

        $cotationA = (new Cotation())->setNom('A Souscrite')->setDuree(365);
        $cotationA->setEntreprise($entreprise);
        $pisteBound->addCotation($cotationA);
        $em->persist($cotationA);
        $avenant = (new Avenant())->setReferencePolice('POL-A')->setNumero('0')->setDescription('Avenant A')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($invite);
        $cotationA->addAvenant($avenant);
        $em->persist($avenant);

        $cotationB = (new Cotation())->setNom('B Caduque')->setDuree(365);
        $cotationB->setEntreprise($entreprise);
        $pisteBound->addCotation($cotationB);
        $em->persist($cotationB);

        // Piste non bound : C non souscrite et seule → C est en attente (encore en course).
        $client2 = (new Client())->setNom('Client Piste En Cours')->setExonere(false);
        $client2->setEntreprise($entreprise);
        $em->persist($client2);
        $pisteEnCours = (new Piste())->setNom('Piste En Cours')->setTypeAvenant(0)->setDescriptionDuRisque('Risque')
            ->setExercice(2026)->setClient($client2)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($pisteEnCours);
        $cotationC = (new Cotation())->setNom('C En Attente')->setDuree(365);
        $cotationC->setEntreprise($entreprise);
        $pisteEnCours->addCotation($cotationC);
        $em->persist($cotationC);

        $em->flush();
        $ids = [
            'A' => $cotationA->getId(),
            'B' => $cotationB->getId(),
            'C' => $cotationC->getId(),
        ];
        $entrepriseId = $entreprise->getId();
        $em->clear();

        $entrepriseA = $this->em()->getRepository(Entreprise::class)->find($entrepriseId);

        // Souscrites : uniquement A.
        $souscrites = $this->service()->search(Cotation::class, [CotationSouscriptionScope::CRITERION_KEY => CotationSouscriptionScope::STATUT_SOUSCRITES], $entrepriseA);
        $this->assertSame([$ids['A']], $this->ids($souscrites));

        // Caduques : uniquement B (jamais C : sa piste n'est pas bound).
        $caduques = $this->service()->search(Cotation::class, [CotationSouscriptionScope::CRITERION_KEY => CotationSouscriptionScope::STATUT_CADUQUES], $entrepriseA);
        $this->assertSame([$ids['B']], $this->ids($caduques));

        // En attente : uniquement C (B exclue car caduque).
        $enAttente = $this->service()->search(Cotation::class, [CotationSouscriptionScope::CRITERION_KEY => CotationSouscriptionScope::STATUT_EN_ATTENTE], $entrepriseA);
        $this->assertSame([$ids['C']], $this->ids($enAttente));

        // Les trois groupes partitionnent bien les 3 cotations.
        $toutes = $this->service()->search(Cotation::class, [CotationSouscriptionScope::CRITERION_KEY => ['operator' => '=', 'value' => '']], $entrepriseA);
        $this->assertSame(3, $toutes['totalItems']);
    }

    public function testChipParDefautEstEnAttente(): void
    {
        $s = $this->seed();
        $entrepriseA = $this->em()->getRepository(Entreprise::class)->find($s['entrepriseA']);

        $controller = static::getContainer()->get(CotationController::class);
        $ref = new \ReflectionMethod(CotationController::class, 'getInitialSearchCriteria');
        $ref->setAccessible(true);

        $criteres = $ref->invoke($controller, Cotation::class, $s['inviteA'], $entrepriseA);
        $this->assertSame(
            CotationSouscriptionScope::STATUT_EN_ATTENTE,
            $criteres[CotationSouscriptionScope::CRITERION_KEY]['value'],
            'Le chargement initial met en avant les propositions en attente (travail commercial restant).'
        );
    }

    public function testCanevasDeRechercheExposeLeCritereSynthetique(): void
    {
        /** @var SearchCanvasProvider $provider */
        $provider = static::getContainer()->get(SearchCanvasProvider::class);

        $parNom = [];
        foreach ($provider->getCanvas(Cotation::class) as $critere) {
            $parNom[$critere['Nom']] = $critere;
        }

        $this->assertArrayHasKey(CotationSouscriptionScope::CRITERION_KEY, $parNom, 'Le badge/dialogue avancé doit exposer le statut de souscription.');
        $this->assertSame('Boolean', $parNom[CotationSouscriptionScope::CRITERION_KEY]['Type'], 'Rendu en <select> depuis la map de valeurs.');
        $this->assertSame(CotationSouscriptionScope::VALEURS, $parNom[CotationSouscriptionScope::CRITERION_KEY]['Valeur']);
    }

    public function testDetectionLangageNaturel(): void
    {
        $this->assertSame(CotationSouscriptionScope::STATUT_SOUSCRITES, CotationSouscriptionScope::detecterDepuisTexte('quelles propositions souscrites ?'));
        $this->assertSame(CotationSouscriptionScope::STATUT_EN_ATTENTE, CotationSouscriptionScope::detecterDepuisTexte('combien de propositions en attente'));
        $this->assertSame(CotationSouscriptionScope::STATUT_EN_ATTENTE, CotationSouscriptionScope::detecterDepuisTexte('les cotations non souscrites'));
        $this->assertSame(CotationSouscriptionScope::STATUT_CADUQUES, CotationSouscriptionScope::detecterDepuisTexte('les propositions caduques'));
        $this->assertSame(CotationSouscriptionScope::STATUT_CADUQUES, CotationSouscriptionScope::detecterDepuisTexte('quelles cotations sans suite ?'));
        $this->assertNull(CotationSouscriptionScope::detecterDepuisTexte('liste des propositions'));
    }
}
