<?php

namespace App\Tests\Services;

use App\Controller\Admin\PisteController;
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
use App\Services\Search\PisteTransformationScope;
use App\Services\Search\PortefeuilleScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Moteur de recherche, critère synthétique « Statut de transformation » (Piste) : une piste est
 * « transformée » dès qu'une de ses cotations est souscrite (porte au moins un avenant = police),
 * « en cours » sinon — pendant, un cran plus haut, du statut de souscription d'une cotation. La
 * présence d'un avenant rattaché à l'une des cotations est exprimable en SQL, donc le filtrage
 * se fait directement en base (EXISTS / NOT EXISTS), sans service en mémoire. On vérifie : le
 * filtrage par statut, le repli « Toutes » (valeur vide), le scoping entreprise (AuditableTrait)
 * + périmètre portefeuille (« Mon portefeuille »), le chip actif par défaut (« En cours »), et
 * l'exposition du critère au canevas de recherche.
 */
class JSBDynamicSearchServicePisteTransformationTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-pistetransfo-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit PisteTransfo SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit PisteTransfo Autre SARL';

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
        $entreprise->setNom($nom)->setLicence('LIC-PT')->setAdresse('1 rue des Pistes')
            ->setTelephone('+243000000029')->setRccm('RCCM-PT')->setIdnat('IDNAT-PT')->setNumimpot('IMP-PT');
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
     * Une piste rattachée à un client (optionnellement à un portefeuille pour le périmètre),
     * portant une cotation. Si $avecAvenant, un avenant est ajouté à cette cotation → la piste
     * devient « transformée ».
     */
    private function makePiste(
        Entreprise $entreprise,
        Invite $invite,
        string $nom,
        ?Portefeuille $portefeuille,
        bool $avecAvenant
    ): Piste {
        $em = $this->em();

        $client = (new Client())->setNom('Client ' . $nom)->setExonere(false);
        $client->setEntreprise($entreprise);
        if ($portefeuille !== null) {
            $portefeuille->addClient($client);
        }
        $em->persist($client);

        $piste = (new Piste())
            ->setNom($nom)->setTypeAvenant(0)->setDescriptionDuRisque('Risque transformation')
            ->setExercice(2026)->setClient($client)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation ' . $nom)->setDuree(365);
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

        return $piste;
    }

    /**
     * Entreprise A (invité gestionnaire d'un portefeuille) : deux pistes transformées (une
     * cotation à avenant) et une en cours DANS le portefeuille, plus une transformée et une en
     * cours HORS portefeuille (même entreprise) pour prouver le périmètre. Entreprise B : une
     * piste (scoping — jamais visible depuis A).
     *
     * @return array<string, mixed>
     */
    private function seed(): array
    {
        $em = $this->em();

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit PisteTransfo')->setVerified(true)->setPassword('irrelevant');
        $em->persist($ownerUser);

        // --- Entreprise A ---
        $entrepriseA = $this->makeEntreprise(self::ENTREPRISE_NOM, $ownerUser);
        $inviteA = $this->makeInvite($entrepriseA, $ownerUser, 'Gestionnaire A');

        $portefeuille = (new Portefeuille())->setNom('Portefeuille A')->setGestionnaire($inviteA);
        $portefeuille->setEntreprise($entrepriseA);
        $em->persist($portefeuille);

        $transfoPf1 = $this->makePiste($entrepriseA, $inviteA, 'Transfo PF 1', $portefeuille, true);
        $transfoPf2 = $this->makePiste($entrepriseA, $inviteA, 'Transfo PF 2', $portefeuille, true);
        $enCoursPf = $this->makePiste($entrepriseA, $inviteA, 'En Cours PF', $portefeuille, false);

        // Hors portefeuille (client sans portefeuille), même entreprise.
        $transfoHorsPf = $this->makePiste($entrepriseA, $inviteA, 'Transfo Hors PF', null, true);
        $enCoursHorsPf = $this->makePiste($entrepriseA, $inviteA, 'En Cours Hors PF', null, false);

        // --- Entreprise B (scoping) ---
        $entrepriseB = $this->makeEntreprise(self::ENTREPRISE_B_NOM, $ownerUser);
        $inviteB = $this->makeInvite($entrepriseB, $ownerUser, 'Gestionnaire B');
        $portefeuilleB = (new Portefeuille())->setNom('Portefeuille B')->setGestionnaire($inviteB);
        $portefeuilleB->setEntreprise($entrepriseB);
        $em->persist($portefeuilleB);
        $pisteB = $this->makePiste($entrepriseB, $inviteB, 'Piste B', $portefeuilleB, true);

        $em->flush();
        $ids = [
            'entrepriseA' => $entrepriseA->getId(),
            'inviteA' => $inviteA->getId(),
            'entrepriseB' => $entrepriseB->getId(),
            'inviteB' => $inviteB->getId(),
            'transfoPf1' => $transfoPf1->getId(),
            'transfoPf2' => $transfoPf2->getId(),
            'enCoursPf' => $enCoursPf->getId(),
            'transfoHorsPf' => $transfoHorsPf->getId(),
            'enCoursHorsPf' => $enCoursHorsPf->getId(),
            'pisteB' => $pisteB->getId(),
        ];
        $em->clear();

        return $ids;
    }

    private function ids(array $resultat): array
    {
        return array_map(static fn (Piste $p) => $p->getId(), $resultat['data']);
    }

    public function testChaqueStatutNeRetourneQueSaMoitie(): void
    {
        $s = $this->seed();
        $entrepriseA = $this->em()->getRepository(Entreprise::class)->find($s['entrepriseA']);

        // « Transformées » : les pistes à cotation souscrite de l'entreprise A (dans et hors
        // portefeuille), jamais celles en cours ; « étrangère » de l'entreprise B jamais visible.
        $transformees = $this->service()->search(Piste::class, [PisteTransformationScope::CRITERION_KEY => PisteTransformationScope::STATUT_TRANSFORMEES], $entrepriseA);
        $this->assertNull($transformees['status']['error']);
        $this->assertEqualsCanonicalizing([$s['transfoPf1'], $s['transfoPf2'], $s['transfoHorsPf']], $this->ids($transformees));
        $this->assertNotContains($s['pisteB'], $this->ids($transformees));

        // « En cours » : les pistes SANS avenant de l'entreprise A.
        $enCours = $this->service()->search(Piste::class, [PisteTransformationScope::CRITERION_KEY => PisteTransformationScope::STATUT_EN_COURS], $entrepriseA);
        $this->assertEqualsCanonicalizing([$s['enCoursPf'], $s['enCoursHorsPf']], $this->ids($enCours));

        // « Toutes » (valeur vide) : critère retiré, recherche standard scopée entreprise.
        $toutes = $this->service()->search(Piste::class, [PisteTransformationScope::CRITERION_KEY => ['operator' => '=', 'value' => '']], $entrepriseA);
        $this->assertSame(5, $toutes['totalItems'], 'Les 5 pistes de l\'entreprise A, aucune de l\'entreprise B.');
    }

    public function testCombinaisonAvecPerimetrePortefeuille(): void
    {
        $s = $this->seed();
        $entrepriseA = $this->em()->getRepository(Entreprise::class)->find($s['entrepriseA']);

        // « Transformées » COMBINÉ au périmètre « Mon portefeuille » : seules les transformées du
        // portefeuille de l'invité, jamais celle hors portefeuille (périmètre de sécurité).
        $transfoScopees = $this->service()->search(
            Piste::class,
            [
                PisteTransformationScope::CRITERION_KEY => PisteTransformationScope::STATUT_TRANSFORMEES,
                PortefeuilleScope::CRITERION_KEY => ['operator' => '=', 'value' => $s['inviteA']],
            ],
            $entrepriseA,
        );
        $this->assertEqualsCanonicalizing([$s['transfoPf1'], $s['transfoPf2']], $this->ids($transfoScopees));
        $this->assertNotContains($s['transfoHorsPf'], $this->ids($transfoScopees));

        // « En cours » scopée : uniquement celle du portefeuille.
        $enCoursScopee = $this->service()->search(
            Piste::class,
            [
                PisteTransformationScope::CRITERION_KEY => PisteTransformationScope::STATUT_EN_COURS,
                PortefeuilleScope::CRITERION_KEY => ['operator' => '=', 'value' => $s['inviteA']],
            ],
            $entrepriseA,
        );
        $this->assertSame([$s['enCoursPf']], $this->ids($enCoursScopee));
    }

    public function testStatutInvalideRetombeSurCheminStandard(): void
    {
        $s = $this->seed();
        $entrepriseA = $this->em()->getRepository(Entreprise::class)->find($s['entrepriseA']);

        $resultat = $this->service()->search(Piste::class, [PisteTransformationScope::CRITERION_KEY => 'valeur-inconnue'], $entrepriseA);
        $this->assertNull($resultat['status']['error']);
        $this->assertSame(5, $resultat['totalItems'], 'Critère retiré, recherche standard scopée entreprise.');
    }

    public function testChipParDefautEstEnCours(): void
    {
        $s = $this->seed();
        $entrepriseA = $this->em()->getRepository(Entreprise::class)->find($s['entrepriseA']);

        $controller = static::getContainer()->get(PisteController::class);
        $ref = new \ReflectionMethod(PisteController::class, 'getInitialSearchCriteria');
        $ref->setAccessible(true);

        $criteres = $ref->invoke($controller, Piste::class, $s['inviteA'], $entrepriseA);
        $this->assertSame(
            PisteTransformationScope::STATUT_EN_COURS,
            $criteres[PisteTransformationScope::CRITERION_KEY]['value'],
            'Le chargement initial met en avant les pistes en cours (travail commercial restant).'
        );
    }

    public function testCanevasDeRechercheExposeLeCritereSynthetique(): void
    {
        /** @var SearchCanvasProvider $provider */
        $provider = static::getContainer()->get(SearchCanvasProvider::class);

        $parNom = [];
        foreach ($provider->getCanvas(Piste::class) as $critere) {
            $parNom[$critere['Nom']] = $critere;
        }

        $this->assertArrayHasKey(PisteTransformationScope::CRITERION_KEY, $parNom, 'Le badge/dialogue avancé doit exposer le statut de transformation.');
        $this->assertSame('Boolean', $parNom[PisteTransformationScope::CRITERION_KEY]['Type'], 'Rendu en <select> depuis la map de valeurs.');
        $this->assertSame(PisteTransformationScope::VALEURS, $parNom[PisteTransformationScope::CRITERION_KEY]['Valeur']);
    }

    public function testDetectionLangageNaturel(): void
    {
        $this->assertSame(PisteTransformationScope::STATUT_TRANSFORMEES, PisteTransformationScope::detecterDepuisTexte('quelles pistes transformees ?'));
        $this->assertSame(PisteTransformationScope::STATUT_EN_COURS, PisteTransformationScope::detecterDepuisTexte('combien de pistes en cours'));
        $this->assertSame(PisteTransformationScope::STATUT_EN_COURS, PisteTransformationScope::detecterDepuisTexte('les pistes non transformees'));
        $this->assertNull(PisteTransformationScope::detecterDepuisTexte('liste des pistes'));
    }
}
