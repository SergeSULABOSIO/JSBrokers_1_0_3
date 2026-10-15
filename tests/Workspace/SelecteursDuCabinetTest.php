<?php

namespace App\Tests\Workspace;

use App\Entity\ModelePieceSinistre;
use App\Entity\PieceSinistre;
use App\Entity\Utilisateur;
use App\Tests\Security\SemisDeDeuxCabinetsTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UN SÉLECTEUR N'AFFICHE JAMAIS LES DONNÉES D'UN AUTRE CABINET — c'est la règle (Serge,
 * 2026-10-15 ; skill `jsbrokers-dev` §2).
 *
 * La nature d'une pièce de sinistre se choisit parmi les modèles de pièces : le sélecteur
 * listait ceux de TOUS les cabinets, noms et descriptions compris.
 */
class SelecteursDuCabinetTest extends WebTestCase
{
    use SemisDeDeuxCabinetsTrait;

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

    public function testLeSelecteurDesModelesDePiecesNeMontreQueLeCabinet(): void
    {
        $s = $this->semerLesDeuxCabinets();
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($s['a']['owner']));
        $modeleA = $this->em()->find(ModelePieceSinistre::class, $this->identifiantsDuCabinet(ModelePieceSinistre::class, $s['a']['entreprise'])[0]);
        $modeleB = $this->em()->find(ModelePieceSinistre::class, $this->identifiantsDuCabinet(ModelePieceSinistre::class, $s['b']['entreprise'])[0]);

        $this->client->request('GET', '/admin/piecesinistre/api/get-form');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('value="' . $modeleA->getId() . '"', $html, 'Le modèle du cabinet est proposé.');
        self::assertStringNotContainsString('value="' . $modeleB->getId() . '"', $html, 'Celui d’un autre cabinet, jamais.');
        self::assertStringNotContainsString((string) $modeleB->getNom(), $html, 'Ni son nom.');
    }

    /** Le modèle d'un autre cabinet ne s'attache pas non plus par l'écriture. */
    public function testLeModeleDUnAutreCabinetNeSAttachePas(): void
    {
        $s = $this->semerLesDeuxCabinets();
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($s['a']['owner']));
        $modeleB = (int) $this->identifiantsDuCabinet(ModelePieceSinistre::class, $s['b']['entreprise'])[0];

        $this->client->request('POST', '/admin/piecesinistre/api/submit', [
            'idEntreprise' => $s['a']['entreprise'],
            'description' => 'Pièce pirate',
            'fourniPar' => 'Client',
            'receivedAt' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'type' => (string) $modeleB,
        ]);

        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 422]);
        $this->em()->clear();
        self::assertNull($this->em()->getRepository(PieceSinistre::class)->findOneBy(['description' => 'Pièce pirate']));
    }
}
