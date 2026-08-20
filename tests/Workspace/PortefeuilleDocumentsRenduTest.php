<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Portefeuille;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LA COLLECTION « DOCUMENTS » D'UN PORTEFEUILLE — absente pendant longtemps, sans un bruit.
 *
 * `PortefeuilleFormCanvasProvider` empilait `documents` dans une variable `$collections`,
 * puis passait un **tableau littéral** (les clients) à `addCollectionWidgetsToLayout()` :
 * la variable était abandonnée en chemin. Aucune erreur, aucune trace — simplement un bloc
 * qui ne s'affichait jamais. Toutes les autres fiches du workspace acceptent des pièces
 * jointes ; celle-ci ne le pouvait pas, et rien ne l'expliquait.
 *
 * Ce test est court parce que le bug l'était : il exige seulement que le bloc EXISTE.
 */
class PortefeuilleDocumentsRenduTest extends WebTestCase
{
    private const ENT = 'PHPUnit-PfDocs';
    private const OWNER = 'phpunit-pfdocs@test.local';

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
        foreach (['portefeuille', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    private function semer(): Portefeuille
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+243000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($ent);

        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);
        $owner->setConnectedTo($ent);

        // Le gestionnaire est obligatoire en base : un portefeuille appartient toujours
        // à quelqu'un.
        $portefeuille = (new Portefeuille())->setNom('Portefeuille du test')->setGestionnaire($inv);
        $portefeuille->setEntreprise($ent);
        $this->em->persist($portefeuille);

        $this->em->flush();
        $this->client->loginUser($owner);

        return $portefeuille;
    }

    public function testLeBlocDesPiecesJointesEstEnfinRendu(): void
    {
        $portefeuille = $this->semer();

        $this->client->request('GET', '/admin/portefeuille/api/get-form/' . $portefeuille->getId());
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString(
            'data-field-code="documents"',
            $html,
            'Un portefeuille doit pouvoir porter des pièces jointes, comme toutes les autres fiches.',
        );
        // La collection des clients ne doit pas avoir été perdue au passage : c'est elle
        // qui occupait seule le tableau transmis.
        self::assertStringContainsString('data-field-code="clients"', $html);
    }
}
