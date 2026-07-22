<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Contact;
use App\Service\Workspace\CascadeImpactAnalyzer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'analyseur d'impact de suppression lit les VRAIES métadonnées Doctrine
 * (ORM 3) : il détecte les enfants supprimés en cascade (orphanRemoval) et ne
 * produit aucun blocage sur une entité non encore persistée. Aucune connexion
 * BDD nécessaire (métadonnées + valeurs d'objet uniquement).
 */
class CascadeImpactAnalyzerTest extends KernelTestCase
{
    private function analyzer(): CascadeImpactAnalyzer
    {
        self::bootKernel();

        return self::getContainer()->get(CascadeImpactAnalyzer::class);
    }

    public function testDetecteLesEnfantsSupprimesEnCascade(): void
    {
        $client = (new Client())->setNom('Client test');
        $client->addContact(new Contact());
        $client->addContact(new Contact());

        $impact = $this->analyzer()->analyserSuppression($client);

        // Client.contacts est orphanRemoval : 2 contacts seraient supprimés.
        $contacts = array_filter($impact->enfants, static fn ($e) => $e['entite'] === 'Contact');
        $this->assertCount(1, $contacts);
        $this->assertSame(2, array_values($contacts)[0]['count']);
        $this->assertNotEmpty($impact->descriptions());
        $this->assertStringContainsString('2 Contact', $impact->descriptions()[0]);
    }

    public function testAucunBlocageSurEntiteNonPersistee(): void
    {
        // Sans identifiant, aucune requête de références entrantes n'est lancée.
        $impact = $this->analyzer()->analyserSuppression((new Client())->setNom('Nouveau'));

        $this->assertFalse($impact->estBloque());
    }
}
