<?php

namespace App\Tests\Workspace;

use App\Constantes\Constante;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use App\Form\TrancheType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * CONVENTION du pourcentage de tranche : stocké en POINTS (100 = 100 %,
 * 33,33 = 33,33 %), plus jamais en fraction. Les calculs monétaires dérivent la
 * fraction via Tranche::getFraction(). Ce test verrouille les deux bouts :
 *  - le FORMULAIRE (PercentType integer) stocke la valeur saisie TELLE QUELLE ;
 *  - getFraction() rend la fraction correcte pour les calculs et le diagnostic.
 */
class TranchePourcentageConventionTest extends WebTestCase
{
    private const ENT = 'PHPUnit-TranchePct';
    private const OWNER = 'phpunit-tranchepct-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        $conn->executeStatement('DELETE t FROM tranche t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE c FROM cotation c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE t FROM invite t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** Un utilisateur connecté à une entreprise : requis par les champs autocomplete de TrancheType. */
    private function login(): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('P')->setVerified(true);
        $owner->setPassword('x');
        $owner->setPaidTokens(1_000_000);
        $this->em->persist($owner);
        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('L')->setAdresse('a')->setTelephone('t')
            ->setRccm('r')->setIdnat('i')->setNumimpot('n')->setUtilisateur($owner);
        $this->em->persist($ent);
        $inv = (new Invite())->setNom('O')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);
        $owner->setConnectedTo($ent);
        $this->em->flush();
        $this->client->loginUser($owner);

        return [$ent, $inv];
    }

    /**
     * La tranche « unique » créée AUTOMATIQUEMENT à l'enregistrement d'une cotation
     * couvre 100 % de la prime — donc 100 POINTS.
     *
     * Elle était restée à 1.0 : sous l'ancienne convention fractionnaire, 1,0 valait
     * 100 % ; depuis le passage en points, la même valeur vaut 1 %. Toute cotation
     * créée à l'écran depuis lors n'échelonnait qu'un centième de sa prime, en
     * silence. L'import bordereau, lui, posait bien 100.0.
     */
    public function testTrancheAutoCreeeCouvreCentPourCent(): void
    {
        [$ent, $inv] = $this->login();

        $this->client->request('POST', '/admin/cotation/api/submit', [
            'idEntreprise' => $ent->getId(),
            'idInvite'     => $inv->getId(),
            'nom'          => 'Cotation convention points',
            'duree'        => 12,
        ]);
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $cotation = $this->em->getRepository(Cotation::class)->findOneBy(['nom' => 'Cotation convention points']);
        $this->assertNotNull($cotation);
        $this->assertCount(1, $cotation->getTranches(), 'Une tranche unique est créée d’office.');

        $tranche = $cotation->getTranches()->first();
        $this->assertSame(100.0, $tranche->getPourcentage(), 'Tranche unique = 100 POINTS, pas 1.');
        $this->assertSame(1.0, $tranche->getFraction(), 'Soit la totalité de la prime.');
    }

    /**
     * Même exigence sur l'autre porte d'entrée : l'ouverture de la collection
     * « tranches » d'une cotation qui n'en a aucune en crée une, et la PERSISTE.
     */
    public function testTrancheAutoCreeeParLOuvertureDeLaCollection(): void
    {
        [$ent, $inv] = $this->login();

        $cotation = (new Cotation())->setNom('Cotation sans tranche')->setDuree(12)
            ->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($cotation);
        $this->em->flush();
        $idCotation = $cotation->getId();

        $this->client->request('GET', sprintf('/admin/cotation/api/%d/tranches/generic', $idCotation));
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $cotation = $this->em->getRepository(Cotation::class)->find($idCotation);
        $this->assertCount(1, $cotation->getTranches());
        $this->assertSame(100.0, $cotation->getTranches()->first()->getPourcentage());
    }

    public function testGetFractionDeriveLaFractionDepuisLesPoints(): void
    {
        $this->assertSame(1.0, (new Tranche())->setPourcentage(100.0)->getFraction(), '100 points = fraction 1,0 (100 %).');
        $this->assertEqualsWithDelta(0.3333, (new Tranche())->setPourcentage(33.33)->getFraction(), 0.0001, '33,33 points = 0,3333.');
        $this->assertSame(0.5, (new Tranche())->setPourcentage(50.0)->getFraction());
        $this->assertSame(0.0, (new Tranche())->getFraction(), 'Pourcentage nul/non défini : fraction 0 (pas d’erreur).');
    }

    /** Le formulaire (PercentType integer) stocke le pourcentage SAISI, sans le diviser par 100. */
    public function testFormulaireStockeLePourcentageEnPointsSansDivision(): void
    {
        $this->login();
        /** @var FormFactoryInterface $factory */
        $factory = static::getContainer()->get('form.factory');

        $dates = ['payableAt' => '2026-07-25T10:00', 'echeanceAt' => '2026-08-25T10:00'];

        $cent = new Tranche();
        $factory->create(TrancheType::class, $cent)->submit(['nom' => 'Unique', 'pourcentage' => '100'] + $dates);
        $this->assertSame(100.0, $cent->getPourcentage(), 'Saisir 100 stocke 100 (points), PAS 1 (fraction).');
        $this->assertSame(1.0, $cent->getFraction());

        $tiers = new Tranche();
        $factory->create(TrancheType::class, $tiers)->submit(['nom' => 'Tiers', 'pourcentage' => '33.33'] + $dates);
        $this->assertEqualsWithDelta(33.33, $tiers->getPourcentage(), 0.001, 'Les décimales sont conservées (scale 2).');
    }

    /**
     * Le diagnostic de somme des tranches raisonne en fraction (== 1) via
     * getFraction() : une tranche unique à 100 points est conforme (pas de badge),
     * une tranche à 50 points signale les 50 % manquants.
     */
    public function testDiagnosticSommeTranchesEnPoints(): void
    {
        $constante = static::getContainer()->get(Constante::class);

        $complete = (new Cotation())->addTranche((new Tranche())->setNom('Unique')->setPourcentage(100.0));
        $this->assertSame('', $constante->Cotation_getTrancheDiagnostic($complete), 'Tranche unique 100 % : aucune anomalie.');

        $partielle = (new Cotation())->addTranche((new Tranche())->setNom('Moitié')->setPourcentage(50.0));
        $diagnostic = $constante->Cotation_getTrancheDiagnostic($partielle);
        $this->assertStringContainsString('50', $diagnostic, 'Une seule tranche à 50 % signale 50 % restants.');
    }
}
