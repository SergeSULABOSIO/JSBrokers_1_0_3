<?php

namespace App\Tests\Services;

use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\Utilisateur;
use App\Services\ServiceTaxes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UNE AFFAIRE EXONÉRÉE NE PORTE AUCUNE TAXE SUR SA COMMISSION.
 *
 * ── LE BUG QUE CES TESTS FERMENT ────────────────────────────────────────────────────
 * ⚠ `Client::$exonere` ET `Risque::$imposable` N'ÉTAIENT LUS NULLE PART. Les deux champs
 * existaient dans les formulaires, s'affichaient sur les fiches, et n'entraient dans aucun
 * calcul : un cabinet cochait « exonéré de taxes » et l'application facturait ses seize
 * pour cent quand même — sur l'écran, dans les indicateurs, dans les écritures comptables
 * et jusque dans les notes adressées à l'autorité fiscale.
 *
 * ── POURQUOI C'EST TESTÉ ICI, ET PAS SUR LA CASCADE ─────────────────────────────────
 * ⚠ UN TEST SANS BARÈME PROUVERAIT ZÉRO ÉGALE ZÉRO. Sans taxe configurée pour le cabinet,
 * `getMontantTaxe()` rend 0 de toute façon : un test d'exonération y passerait au vert
 * sans rien garantir. Il faut donc une entreprise et une TVA en base — et le jumeau
 * ci-dessous, qui vérifie qu'une affaire ORDINAIRE reste taxée, sans quoi une garde trop
 * large exonérerait tout le monde sans qu'aucun test ne bronche.
 *
 * La règle est interrogée là où elle vit — `ServiceTaxes` —, et non à travers la cascade
 * de calcul : la cotation n'a pas besoin d'être persistée, seuls ses getters sont lus.
 */
class CommissionExonereeTest extends KernelTestCase
{
    private const ENT = 'PHPUnit Exonération SARL';
    private const OWNER_EMAIL = 'phpunit-exoneration@test.local';
    private const TAUX_TVA = 16.0;
    private const COMMISSION_HT = 1000.0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    /**
     * Il suffit de l'UN des deux réglages : le client exonéré, ou le risque non imposable.
     *
     * @dataProvider affairesExonerees
     */
    public function testUneAffaireExonereeNePorteAucuneTaxe(bool $clientExonere, bool $risqueImposable): void
    {
        $entreprise = $this->cabinetAvecTva();
        $taxes = $this->taxes();
        $cotation = $this->cotation($clientExonere, $risqueImposable);

        // ⚠ LES DEUX TAXES TOMBENT ENSEMBLE. Une commission que l'assureur n'a jamais
        // majorée ne fait naître aucune dette fiscale pour le cabinet : n'en neutraliser
        // qu'une le ferait provisionner une taxe que personne ne lui a versée.
        foreach ([true, false] as $taxeAssureur) {
            self::assertSame(
                0.0,
                $taxes->getMontantTaxeSurCommission(self::COMMISSION_HT, true, $taxeAssureur, $cotation, $entreprise),
                $taxeAssureur ? 'Taxe de l\'assureur' : 'Taxe du courtier',
            );
        }
    }

    public static function affairesExonerees(): iterable
    {
        yield 'client exonéré' => [true, true];
        yield 'risque non imposable' => [false, false];
        yield 'les deux' => [true, false];
    }

    /**
     * ⚠ LE JUMEAU QUI REND LES AUTRES PROBANTS. Sans lui, une garde trop large —
     * « toute affaire est exonérée » — passerait les trois cas ci-dessus au vert.
     */
    public function testUneAffaireOrdinaireResteTaxee(): void
    {
        $entreprise = $this->cabinetAvecTva();
        $cotation = $this->cotation(clientExonere: false, risqueImposable: true);

        self::assertSame(
            160.0,
            $this->taxes()->getMontantTaxeSurCommission(self::COMMISSION_HT, true, true, $cotation, $entreprise),
            'Hors exonération, la TVA de 16 % sur 1 000 vaut 160.',
        );
    }

    /**
     * Une affaire sans cotation identifiable — un avoir manuel, un ajustement — reste
     * taxée : l'absence d'information n'est pas une exonération.
     */
    public function testSansAffaireIdentifiableLaTaxeSApplique(): void
    {
        $entreprise = $this->cabinetAvecTva();

        self::assertSame(
            160.0,
            $this->taxes()->getMontantTaxeSurCommission(self::COMMISSION_HT, true, true, null, $entreprise),
        );
    }

    /**
     * Une affaire montée en mémoire : `commissionExonereePour()` ne lit que des getters,
     * rien n'a besoin d'être persisté.
     */
    private function cotation(bool $clientExonere, bool $risqueImposable): Cotation
    {
        $client = (new Client())->setNom('KIN AVIA');
        $client->setExonere($clientExonere);

        $risque = (new Risque())->setNomComplet('RC Aviation')->setCode('RCA');
        $risque->setImposable($risqueImposable);

        $cotation = new Cotation();
        $cotation->setPiste((new Piste())->setClient($client)->setRisque($risque));

        return $cotation;
    }

    /** Un cabinet dont l'assureur précompte 16 % sur la commission du courtier. */
    private function cabinetAvecTva(): Entreprise
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Exo')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);
        $em->flush();

        $taxe = (new Taxe())->setCode('TVA')->setDescription('TVA sur commission');
        $taxe->setRedevable(Taxe::REDEVABLE_ASSUREUR);
        $taxe->setTauxIARD((string) self::TAUX_TVA);
        $taxe->setTauxVIE((string) self::TAUX_TVA);
        $taxe->setEntreprise($entreprise);
        $em->persist($taxe);

        // ⚠ LES DEUX REDEVABLES, sinon le cas « taxe du courtier » prouverait zéro égale
        // zéro : c'est précisément celui qu'on veut voir tomber à l'exonération.
        $taxeCourtier = (new Taxe())->setCode('TVA-C')->setDescription('TVA due par le courtier');
        $taxeCourtier->setRedevable(Taxe::REDEVABLE_COURTIER);
        $taxeCourtier->setTauxIARD((string) self::TAUX_TVA);
        $taxeCourtier->setTauxVIE((string) self::TAUX_TVA);
        $taxeCourtier->setEntreprise($entreprise);
        $em->persist($taxeCourtier);

        $em->flush();

        // ⚠ LE BARÈME EST MÉMOÏSÉ PAR ENTREPRISE le temps d'une requête : sans cette
        // remise à zéro, la taxe qu'on vient de poser resterait invisible.
        $this->taxes()->reset();

        return $entreprise;
    }

    private function taxes(): ServiceTaxes
    {
        return static::getContainer()->get(ServiceTaxes::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Purge dérivée du schéma — même patron que les autres tests du projet. */
    private function nettoyer(): void
    {
        $cnx = $this->em()->getConnection();
        $ids = $cnx->fetchFirstColumn('SELECT id FROM entreprise WHERE nom = ?', [self::ENT]);

        $cnx->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($ids as $id) {
                $cnx->executeStatement('DELETE FROM taxe WHERE entreprise_id = ?', [$id]);
                $cnx->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE connected_to_id = ?', [$id]);
                $cnx->executeStatement('DELETE FROM entreprise WHERE id = ?', [$id]);
            }
            $cnx->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::OWNER_EMAIL]);
        } finally {
            $cnx->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
