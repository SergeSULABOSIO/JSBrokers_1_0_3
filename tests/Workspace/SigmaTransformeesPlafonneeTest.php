<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\RevenuPourCourtier;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\CalculationProvider;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\PisteTransformationScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * LA Σ DES TRANSFORMÉES EST PLAFONNÉE, ET CALCULÉE PAR PAQUETS.
 *
 * La colonne Transformées de la vue Cartes additionnait la commission de TOUTES les polices
 * souscrites du filtre (≈ 7 s pour 1 000 polices). Au-delà du plafond de la monnaie
 * d'affichage (IndicatorCalculationHelper::PLAFOND_SIGMA_TRANSFORMEES), le calcul s'arrête :
 * « + de USD 900 000 ». En deçà, le montant est EXACT — le même que getIndicateursGlobaux.
 */
class SigmaTransformeesPlafonneeTest extends WebTestCase
{
    private const EMAIL = 'phpunit-sigma-plafond@test.local';
    private const CABINET = 'PHPUnit Sigma Plafond SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = ?', [self::EMAIL]);
        $id = $conn->fetchOne('SELECT id FROM entreprise WHERE nom = ?', [self::CABINET]);
        if ($id !== false) {
            $tables = $conn->fetchFirstColumn(
                "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'entreprise_id' AND TABLE_NAME <> 'entreprise'"
            );
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($tables as $table) {
                    $conn->executeStatement("DELETE FROM `$table` WHERE entreprise_id = ?", [$id]);
                }
                $conn->executeStatement('DELETE FROM entreprise WHERE id = ?', [$id]);
            } finally {
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
        $this->em()->clear();
    }

    /**
     * `$nombre` pistes transformées, chacune avec une commission forfaitaire de `$commission`,
     * dans le portefeuille de l'invité, et la monnaie d'affichage `$monnaie`.
     *
     * @return array{entreprise: int, invite: int, cotations: list<int>}
     */
    private function semer(int $nombre, float $commission, string $monnaie = 'USD'): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Sigma')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000071')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $owner->setConnectedTo($cabinet);
        $invite = (new Invite())->setNom('Sigma')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $em->persist($invite);
        $devise = (new Monnaie())->setNom($monnaie)->setCode($monnaie)->setTauxusd('1')
            ->setFonction(Monnaie::FONCTION_SAISIE_ET_AFFICHAGE)->setLocale(false);
        $devise->setEntreprise($cabinet);
        $em->persist($devise);
        $pf = (new Portefeuille())->setNom('PF Sigma')->setGestionnaire($invite);
        $pf->setEntreprise($cabinet);
        $em->persist($pf);
        $client = (new Client())->setNom('Client Sigma')->setExonere(false);
        $client->setEntreprise($cabinet)->setPortefeuille($pf);
        $em->persist($client);
        $type = (new TypeRevenu())->setNom('Commission forfaitaire')->setPourcentage(0.0)->setShared(false)
            ->setMultipayments(false)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR)->setModeCalcul(TypeRevenu::MODE_CALCUL_MONTANT_FLAT);
        $type->setEntreprise($cabinet)->setInvite($invite);
        $em->persist($type);

        for ($i = 1; $i <= $nombre; ++$i) {
            $piste = (new Piste())->setNom("Piste $i")->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
                ->setDescriptionDuRisque('R')->setExercice(2026)->setClient($client);
            $piste->setEntreprise($cabinet)->setInvite($invite);
            $em->persist($piste);
            $cotation = (new Cotation())->setNom("Offre $i")->setDuree(12);
            $cotation->setPiste($piste)->setEntreprise($cabinet)->setInvite($invite);
            $em->persist($cotation);
            $charge = (new ChargementPourPrime())->setNom('Prime nette')->setMontantFlatExceptionel(100000.0);
            $charge->setCotation($cotation)->setEntreprise($cabinet);
            $em->persist($charge);
            $revenu = (new RevenuPourCourtier())->setNom('Commission')->setTypeRevenu($type)->setMontantFlatExceptionel($commission);
            $revenu->setCotation($cotation)->setEntreprise($cabinet);
            $em->persist($revenu);
            $avenant = (new Avenant())->setReferencePolice("POL-SIGMA-$i")->setNumero('0')->setDescription('Police')
                ->setStartingAt(new \DateTimeImmutable('-10 days'))->setEndingAt(new \DateTimeImmutable('+355 days'))->setCotation($cotation);
            $avenant->setEntreprise($cabinet);
            $em->persist($avenant);
        }
        $em->flush();
        $this->client->loginUser($owner);

        $ids = [
            'entreprise' => (int) $cabinet->getId(),
            'invite' => (int) $invite->getId(),
            'cotations' => array_map('intval', $em->getConnection()->fetchFirstColumn('SELECT id FROM cotation WHERE entreprise_id = ? ORDER BY id', [$cabinet->getId()])),
        ];
        $em->clear();

        return $ids;
    }

    /** Sous le plafond : le montant EXACT, celui de getIndicateursGlobaux. */
    public function testSousLePlafondLeMontantEstExact(): void
    {
        $ids = $this->semer(12, 899.0);
        $entreprise = $this->em()->find(Entreprise::class, $ids['entreprise']);
        $calcul = static::getContainer()->get(CalculationProvider::class);

        $cotations = static::getContainer()->get(JSBDynamicSearchService::class)->idsDesCotationsSouscritesDuFiltre([], $entreprise);
        self::assertSame($ids['cotations'], $cotations, 'Toutes les cotations souscrites du filtre, triées.');

        $borne = $calcul->commissionSouscriteBornee($cotations, IndicatorCalculationHelper::PLAFOND_SIGMA_TRANSFORMEES['USD']);
        $pistes = $this->em()->getConnection()->fetchFirstColumn('SELECT piste_id FROM cotation WHERE entreprise_id = ?', [$ids['entreprise']]);
        $global = $calcul->getIndicateursGlobaux($entreprise, true, ['pistesCibles' => $pistes])['commission_totale'];

        self::assertFalse($borne['plafonne']);
        self::assertGreaterThan(0.0, $borne['somme']);
        self::assertEqualsWithDelta($global, $borne['somme'], 0.01, 'Même chiffre que la synthèse : même calcul, cotation par cotation.');
    }

    /** Au-delà : arrêt au PREMIER paquet — les suivants ne sont jamais chargés. */
    public function testAuDelaDuPlafondLeCalculSArreteAuPremierPaquet(): void
    {
        $ids = $this->semer(150, 10000.0);
        $em = $this->em();
        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);

        $borne = $helper->commissionSouscriteBornee($ids['cotations'], 900000.0, 100);

        self::assertTrue($borne['plafonne']);
        self::assertGreaterThan(900000.0, $borne['somme']);
        $uow = $em->getUnitOfWork();
        $chargees = count(array_filter($ids['cotations'], static fn (int $id) => $uow->tryGetById($id, Cotation::class) !== false));
        self::assertSame(100, $chargees, 'Un seul paquet de cent cotations chargé : le second ne l’a jamais été.');
    }

    /** Le code de la monnaie est lu tel que saisi : la casse et les espaces n'y changent rien. */
    public function testLeCodeDeLaMonnaieEstNormalise(): void
    {
        self::assertSame(900000.0, IndicatorCalculationHelper::plafondSigmaTransformees(' usd '));
        self::assertNull(IndicatorCalculationHelper::plafondSigmaTransformees(''));
        self::assertNull(IndicatorCalculationHelper::plafondSigmaTransformees(null));
    }

    /** Une monnaie d'affichage absente de la table : pas de plafond, montant exact. */
    public function testSansPlafondPourLaMonnaieLeMontantEstExact(): void
    {
        self::assertArrayNotHasKey('CDF', IndicatorCalculationHelper::PLAFOND_SIGMA_TRANSFORMEES);
        $ids = $this->semer(150, 10000.0, 'CDF');

        $borne = static::getContainer()->get(CalculationProvider::class)->commissionSouscriteBornee(
            $ids['cotations'],
            IndicatorCalculationHelper::PLAFOND_SIGMA_TRANSFORMEES['CDF'] ?? null,
        );

        self::assertFalse($borne['plafonne']);
        self::assertEqualsWithDelta(150 * 10000.0, $borne['somme'], 0.01);
    }

    /** L'écran : « + de USD 900 000 », et l'en-tête dit pourquoi. */
    public function testLaColonneDitPlusDeEtSonInfobulleExplique(): void
    {
        $ids = $this->semer(150, 10000.0);
        $this->client->getCookieJar()->set(new Cookie(sprintf('listeVue_%d_Piste', $ids['entreprise']), 'cartes'));

        $this->client->request('POST', sprintf('/admin/piste/api/dynamic-query/%d/%d', $ids['invite'], $ids['entreprise']), [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['criteria' => [PisteTransformationScope::CRITERION_KEY => ['operator' => '=', 'value' => PisteTransformationScope::STATUT_EN_COURS]], 'page' => 1]));
        self::assertResponseIsSuccessful();
        $html = html_entity_decode((string) json_decode((string) $this->client->getResponse()->getContent(), true)['html'], ENT_QUOTES | ENT_HTML5);
        self::assertMatchesRegularExpression('/data-etape="transformees" value="150"\s*data-somme="\+ de USD 900 000"\s*data-plafonne="900000"/u', str_replace("\u{202F}", ' ', str_replace("\u{00A0}", ' ', $html)));

        $this->client->request('GET', sprintf('/espacedetravail/api/load-component/%d/%d', $ids['invite'], $ids['entreprise']), [
            'component' => '_view_manager_marketing.html.twig',
            'entity' => 'Piste',
        ]);
        self::assertResponseIsSuccessful();
        // L'infobulle voyage en JSON dans `data-infobulle` : on la décode avant de la lire.
        $page = html_entity_decode((string) $this->client->getResponse()->getContent(), ENT_QUOTES | ENT_HTML5);
        preg_match_all('/data-infobulle="([^"]*)"/', (string) $this->client->getResponse()->getContent(), $bulles);
        $textes = implode(' ', array_map(
            static fn (string $b) => str_replace(["\u{202F}", "\u{00A0}"], ' ', (string) json_encode(json_decode(html_entity_decode($b, ENT_QUOTES | ENT_HTML5), true), JSON_UNESCAPED_UNICODE)),
            $bulles[1],
        ));
        self::assertNotSame('', $page);
        self::assertStringContainsString('Montant plafonné : le calcul s’arrête au-delà de 900 000 USD', $textes);
    }
}
