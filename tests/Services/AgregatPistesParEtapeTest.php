<?php

namespace App\Tests\Services;

use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\PisteTransformationScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LES EN-TÊTES DU KANBAN COMPTENT TOUT LE FILTRE, ET UNE PISTE UNE SEULE FOIS.
 *
 * La vue Cartes montre une page (vingt cartes) ; ses en-têtes, eux, disent combien de pistes
 * et quelle commission potentielle porte chaque étape pour TOUT le filtre courant. Trois
 * exigences : le compte dépasse la page, une jointure vers une collection (recherche sur les
 * cotations) ne double pas une piste dans le SUM, et les pistes clôturées sont comptées à
 * part — elles sortent du pipe actif.
 */
class AgregatPistesParEtapeTest extends KernelTestCase
{
    private const EMAIL = 'phpunit-agregat-etapes@test.local';
    private const CABINET = 'PHPUnit Agregat Etapes SARL';

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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        foreach (['chargement_pour_prime', 'cotation', 'piste', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:n)",
                ['n' => [self::CABINET]],
                ['n' => ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = ?', [self::CABINET]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
    }

    private function semer(): Entreprise
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Agregat')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000033')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $invite = (new Invite())->setNom('Proprio')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $em->persist($invite);

        $piste = function (string $nom, float $commission, bool $close = false) use ($em, $cabinet, $invite): Piste {
            $p = (new Piste())->setNom($nom)->setTypeAvenant(0)->setDescriptionDuRisque('Risque')
                ->setExercice(2026)->setCommissionPotentielle($commission)->setClosed($close);
            $p->setEntreprise($cabinet)->setInvite($invite);
            $em->persist($p);

            return $p;
        };
        $cotation = function (Piste $p, string $nom, ?float $prime = null) use ($em, $cabinet): void {
            $c = (new Cotation())->setNom($nom)->setDuree(12);
            $c->setPiste($p)->setEntreprise($cabinet);
            $em->persist($c);
            if ($prime !== null) {
                $ch = (new ChargementPourPrime())->setNom("Prime $nom")->setMontantFlatExceptionel($prime);
                $ch->setCotation($c)->setEntreprise($cabinet);
                $em->persist($ch);
            }
        };

        // Vingt-cinq pistes « en cotation » : plus qu'une page.
        for ($i = 1; $i <= 25; ++$i) {
            $cotation($piste("En cotation $i", 10.0), "Recherche $i");
        }
        // Une piste chiffrée à DEUX cotations : une jointure vers ses cotations la ramènerait deux fois.
        $double = $piste('Chiffree a deux cotations', 300.0);
        $cotation($double, 'Recherche A', 500.0);
        $cotation($double, 'Recherche B');
        // Une piste à coter, et une clôturée.
        $piste('A coter', 40.0);
        $piste('Abandonnee', 999.0, true);

        $em->flush();
        $em->clear();

        return $em->find(Entreprise::class, $cabinet->getId());
    }

    private function agreger(array $criteres, Entreprise $cabinet): array
    {
        return static::getContainer()->get(JSBDynamicSearchService::class)->agregerPistesParEtape($criteres, $cabinet);
    }

    public function testLesCompteursPortentSurToutLeFiltre(): void
    {
        $cabinet = $this->semer();
        $agregat = $this->agreger([], $cabinet);

        self::assertSame(25, $agregat['etapes'][PisteTransformationScope::ETAPE_EN_COTATION]['nombre'], 'Vingt-cinq pistes, et non la page de vingt.');
        self::assertEqualsWithDelta(250.0, $agregat['etapes'][PisteTransformationScope::ETAPE_EN_COTATION]['somme'], 0.001);
        self::assertSame(1, $agregat['etapes'][PisteTransformationScope::ETAPE_OFFRES_CHIFFREES]['nombre']);
        self::assertEqualsWithDelta(300.0, $agregat['etapes'][PisteTransformationScope::ETAPE_OFFRES_CHIFFREES]['somme'], 0.001);
        self::assertSame(1, $agregat['etapes'][PisteTransformationScope::ETAPE_A_COTER]['nombre']);
        self::assertSame(0, $agregat['etapes'][PisteTransformationScope::STATUT_TRANSFORMEES]['nombre']);
        self::assertSame(1, $agregat['cloturees'], 'La piste clôturée est comptée à part…');
        self::assertEqualsWithDelta(40.0, $agregat['etapes'][PisteTransformationScope::ETAPE_A_COTER]['somme'], 0.001, '… et sa commission ne gonfle aucune colonne.');
    }

    public function testUneJointureVersLesCotationsNeDoublePasUnePiste(): void
    {
        $cabinet = $this->semer();
        // Recherche sur une COLLECTION : la piste à deux cotations « Recherche A/B » passe deux fois la jointure.
        $agregat = $this->agreger(['cotations' => ['operator' => 'LIKE', 'value' => 'Recherche', 'targetField' => 'nom']], $cabinet);

        self::assertSame(1, $agregat['etapes'][PisteTransformationScope::ETAPE_OFFRES_CHIFFREES]['nombre']);
        self::assertEqualsWithDelta(300.0, $agregat['etapes'][PisteTransformationScope::ETAPE_OFFRES_CHIFFREES]['somme'], 0.001, 'La commission n\'est pas comptée deux fois.');
        self::assertSame(25, $agregat['etapes'][PisteTransformationScope::ETAPE_EN_COTATION]['nombre']);
        self::assertSame(0, $agregat['etapes'][PisteTransformationScope::ETAPE_A_COTER]['nombre'], 'La recherche s\'applique : la piste sans cotation ne répond pas.');
    }

    public function testLeStatutEtLeFiltreDesPistesOuvertesNeBornentPasLesColonnes(): void
    {
        $cabinet = $this->semer();
        $agregat = $this->agreger([
            PisteTransformationScope::CRITERION_KEY => ['operator' => '=', 'value' => PisteTransformationScope::STATUT_EN_COURS],
            PisteTransformationScope::CLE_PISTES_OUVERTES => true,
        ], $cabinet);

        self::assertSame(1, $agregat['cloturees'], 'Le filtre des pistes ouvertes est retiré : sinon les clôturées ne se compteraient plus.');
        self::assertSame(25, $agregat['etapes'][PisteTransformationScope::ETAPE_EN_COTATION]['nombre']);
    }
}
