<?php

namespace App\Tests\Services;

use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Taxe;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les taxes SUR LA COMMISSION exposent désormais leur TAUX (en %) à côté de leur
 * montant : Ket lit ainsi « TVA 16 % » au lieu de reconstituer un taux (faux) en
 * divisant un montant par une assiette supposée. Le taux est scopé à l'entreprise
 * de la cotation (fonctionne sans utilisateur connecté, contrairement au montant).
 */
class CotationTauxTaxeTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-tauxtaxe-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit TauxTaxe SARL';

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
        foreach (['revenu_pour_courtier', 'type_revenu', 'chargement_pour_prime', 'cotation', 'piste', 'client', 'taxe', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :email", ['email' => self::OWNER_EMAIL]);
    }

    private function makeTaxe(Entreprise $e, string $code, string $taux, int $redevable): void
    {
        $taxe = (new Taxe())
            ->setCode($code)
            ->setDescription($code)
            ->setTauxIARD($taux)
            ->setTauxVIE($taux) // même taux IARD/VIE : rate déterministe sans dépendre d'un Risque
            ->setRedevable($redevable);
        $taxe->setEntreprise($e);
        $this->em()->persist($taxe);
    }

    public function testLesTauxDesTaxesSurCommissionSontExposesEtScopes(): void
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('TauxTaxe Owner')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($invite);

        // TVA 16 % (assureur / DGI) et ARCA 2 % (courtier) — comme le seed réel.
        $this->makeTaxe($entreprise, 'TVA', '16.00', Taxe::REDEVABLE_ASSUREUR);
        $this->makeTaxe($entreprise, 'ARCA', '2.00', Taxe::REDEVABLE_COURTIER);

        $client = (new Client())->setNom('Client TauxTaxe')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste TauxTaxe')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque test')->setExercice(2026)
            ->setClient($client)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation TauxTaxe')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($entreprise);
        $em->persist($cotation);

        $em->flush();
        $cotationId = $cotation->getId();
        $em->clear();

        $cotation = $em->getRepository(Cotation::class)->find($cotationId);
        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);

        // Le taux est LU dans la config de la taxe (scopé entreprise de la cotation),
        // sans dépendre d'un utilisateur connecté.
        $this->assertSame(16.0, $helper->getCotationTauxTaxeAssureurPercent($cotation), 'TVA assureur = 16 %.');
        $this->assertSame(2.0, $helper->getCotationTauxTaxeCourtierPercent($cotation), 'Taxe courtier (ARCA) = 2 %.');
    }
}
