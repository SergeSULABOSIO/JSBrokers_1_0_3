<?php

namespace App\Tests\Frontend;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Vitrine publique : l'assistant IA est mis en valeur (badge hero + bloc
 * feature en tête, bilingue) et le plan tarifaire l'affiche comme argument
 * premium (paquets payants ✓, offre gratuite ✗). La page « Fonctionnement des
 * tokens » documente la réservation aux comptes payants.
 */
class VitrineAssistantIaTest extends WebTestCase
{
    public function testHomeMetEnValeurLAssistantIa(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        // Badge hero (ancre vers le bloc) + bloc feature IA en tête.
        $this->assertStringContainsString('public-hero__flag', $content);
        $this->assertStringContainsString('Ket', $content);
        $this->assertStringContainsString('id="feature-ia"', $content);
        $this->assertStringContainsString('Assistant IA intégré', $content);
        // Démonstration de chat (remplace l'image du bloc).
        $this->assertStringContainsString('ia-demo', $content);
        $this->assertStringContainsString('Quel est le solde de la trésorerie ?', $content);

        // Tarifs : argument premium sur les paquets, exclusion sur l'offre gratuite.
        $this->assertStringContainsString('pp-feature--ia', $content);
        $this->assertStringContainsString('Assistant IA non inclus', $content);
    }

    public function testHomeEnAnglaisTraduitLeBlocIa(): void
    {
        $client = static::createClient();
        $client->request('GET', '/?lang=en');
        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('Built-in AI assistant', $content);
        $this->assertStringContainsString('AI assistant not included', $content);
    }

    public function testPageTokensDocumenteLaReservationPremium(): void
    {
        $client = static::createClient();

        $client->request('GET', '/fonctionnement-tokens');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            'réservé aux comptes disposant d\'un solde de tokens payant',
            (string) $client->getResponse()->getContent(),
        );

        $client->request('GET', '/fonctionnement-tokens?lang=en');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            'reserved for accounts with a paid token balance',
            (string) $client->getResponse()->getContent(),
        );
    }
}
