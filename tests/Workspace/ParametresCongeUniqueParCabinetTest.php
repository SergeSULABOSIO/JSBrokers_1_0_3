<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\ParametresConge;
use App\Entity\Utilisateur;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UN SEUL JEU DE PARAMÈTRES DE CONGÉ PAR CABINET — garanti par la BASE (2026-10-30).
 *
 * L'écran et Ket refusent déjà la seconde fiche (DecisionDeCreation::UNIQUE_PAR_CABINET,
 * `CreationPariteTest`) ; ce test tient le dernier rempart : même un chemin qui oublierait la
 * décision (import, script) ne peut pas créer deux réglages concurrents — un contrôle qui
 * s'appliquerait ou non selon la ligne lue.
 */
class ParametresCongeUniqueParCabinetTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-parametres-conge-unique@test.local';
    private const ENT = 'PHPUnit Paramètres Congé Unique SARL';

    protected function setUp(): void
    {
        static::bootKernel();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        static::bootKernel();
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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $conn->executeStatement(
            'DELETE t FROM parametres_conge t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENT],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    public function testLaBaseRefuseUnSecondJeuDeParametresPourLeMemeCabinet(): void
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Unique')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);

        $premier = new ParametresConge();
        $premier->setEntreprise($ent);
        $em->persist($premier);
        $em->flush();

        $second = new ParametresConge();
        $second->setEntreprise($ent);
        $em->persist($second);

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }
}
