<?php

namespace App\Tests\Services;

use App\Entity\Entreprise;
use App\Entity\Taxe;
use App\Entity\Utilisateur;
use App\Services\ServiceTaxes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les taxes sont PROPRES à chaque entreprise (colonne entreprise_id). getTaxes()
 * doit donc être scopé : l'ancien findAll() sommait les taxes de TOUTES les
 * entreprises (une TVA 16% × 3 entreprises → 48% appliqués). On vérifie le
 * scoping par entreprise explicite ET l'interprétation « pourcentage entier »
 * (16 = 16%, getMontantTaxe divise par 100).
 */
class ServiceTaxesScopingTest extends KernelTestCase
{
    private const NOM_A = 'PHPUnit-Taxe-A';
    private const NOM_B = 'PHPUnit-Taxe-B';

    private EntityManagerInterface $em;
    private ServiceTaxes $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(ServiceTaxes::class);
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
        $emails = [strtolower(self::NOM_A) . '@test.local', strtolower(self::NOM_B) . '@test.local'];
        foreach ([self::NOM_A, self::NOM_B] as $nom) {
            $conn->executeStatement('DELETE t FROM taxe t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n', ['n' => $nom]);
            $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => $nom]);
        }
        // connected_to avant suppression des entreprises, puis entreprise avant utilisateur (FK).
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:e)', ['e' => $emails], ['e' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (:n)', ['n' => [self::NOM_A, self::NOM_B]], ['n' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:e)', ['e' => $emails], ['e' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        $this->em->clear();
    }

    private function seedEntrepriseAvecTva(string $nom): Entreprise
    {
        $user = (new Utilisateur())->setEmail(strtolower($nom) . '@test.local')->setNom('PHPUnit')->setVerified(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $ent = (new Entreprise())
            ->setNom($nom)->setLicence('L')->setAdresse('1 rue')->setTelephone('+243')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
        $this->em->persist($ent);

        $tva = (new Taxe())->setCode('TVA')->setDescription('TVA')->setTauxIARD(16.0)->setTauxVIE(16.0)->setRedevable(Taxe::REDEVABLE_ASSUREUR);
        $tva->setEntreprise($ent);
        $this->em->persist($tva);

        return $ent;
    }

    public function testMontantTaxeScopeParEntrepriseExplicite(): void
    {
        $entA = $this->seedEntrepriseAvecTva(self::NOM_A);
        $this->seedEntrepriseAvecTva(self::NOM_B); // une AUTRE entreprise avec la même TVA
        $this->em->flush();

        // 1000 × 16% = 160, uniquement la TVA de A — PAS 320 (A + B).
        $montant = $this->service->getMontantTaxe(1000.0, true, true, $entA);
        $this->assertEqualsWithDelta(160.0, $montant, 0.01, 'La taxe ne doit compter que l’entreprise ciblée, pas toutes.');
    }

    public function testSansEntrepriseNiUtilisateurAucuneTaxe(): void
    {
        $this->seedEntrepriseAvecTva(self::NOM_A);
        $this->em->flush();

        // Hors contexte (pas d'entreprise explicite, pas d'utilisateur connecté) :
        // jamais toutes les entreprises → aucune taxe (fail-safe).
        $this->assertSame(0.0, (float) $this->service->getMontantTaxe(1000.0, true, true, null));
    }
}
