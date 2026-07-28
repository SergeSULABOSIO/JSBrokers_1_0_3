<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\Scope\AiScope;
use App\Entity\Entreprise;
use App\Entity\Invite;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AiContextBuilder::toSystemPrompt() n'utilise que systemContext (jamais le
 * scope) : ce test construit une AiRequest en mémoire, sans fixtures ni
 * accès base — il garde la consigne Markdown/pastille du prompt système
 * stable face à un futur refactor (non-régression de la ligne 145-146
 * historique, remplacée pour autoriser le Markdown restreint).
 */
class AiContextBuilderSystemPromptFormatTest extends KernelTestCase
{
    public function testPromptAutoriseMarkdownEtEnseigneLaConventionPastille(): void
    {
        static::bootKernel();
        $builder = static::getContainer()->get(AiContextBuilder::class);

        $request = new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit Format SARL',
                'perimetre'      => [],
                'date'           => '2026-07-20',
                'objetsAttaches' => [],
            ],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite()),
        );

        $prompt = $builder->toSystemPrompt($request);

        $this->assertStringContainsString('Markdown', $prompt);
        $this->assertStringContainsString('[Payée](#success)', $prompt);
        $this->assertStringContainsString('[En retard](#danger)', $prompt);
        $this->assertStringContainsString('[À surveiller](#warning)', $prompt);
        $this->assertStringContainsString('[Info](#info)', $prompt);
        $this->assertStringContainsString('[Aucun impayé](#neutral)', $prompt);
    }

    public function testPromptPorteLeGlossaireFinancierEtLaConcision(): void
    {
        static::bootKernel();
        $builder = static::getContainer()->get(AiContextBuilder::class);

        $request = new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit Glossaire SARL',
                'perimetre'      => [],
                'date'           => '2026-07-25',
                'objetsAttaches' => [],
            ],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite()),
        );

        $prompt = $builder->toSystemPrompt($request);

        // Glossaire : CA = commissions encaissées, distinct de généré / production / prime.
        $this->assertStringContainsString('GLOSSAIRE FINANCIER', $prompt);
        $this->assertStringContainsString('commissions réellement ENCAISSÉES', $prompt);
        // Règle isBound : une proposition non validée ne compte aucune prime/commission.
        $this->assertStringContainsString('RÈGLE isBound', $prompt);
        $this->assertStringContainsString('SANS avenant', $prompt);
        $this->assertStringContainsString('chiffre_affaires_mensuel', $prompt);
        // Concision.
        $this->assertStringContainsString('CONCISION', $prompt);
    }

    public function testPromptSepareLesDeuxMondesDeTaxesEtInterditDinventerUnTaux(): void
    {
        static::bootKernel();
        $builder = static::getContainer()->get(AiContextBuilder::class);

        $request = new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit Taxes SARL',
                'perimetre'      => [],
                'date'           => '2026-07-28',
                'objetsAttaches' => [],
            ],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite()),
        );

        $prompt = $builder->toSystemPrompt($request);

        // Deux mondes de taxes distincts : prime vs commission.
        $this->assertStringContainsString('SUR LA PRIME', $prompt);
        $this->assertStringContainsString('SUR LA COMMISSION', $prompt);
        $this->assertStringContainsString('taxeAssureurMontant', $prompt);
        $this->assertStringContainsString('taxeCourtierMontant', $prompt);
        // Interdiction d'inventer un taux + où lire le vrai taux.
        $this->assertStringContainsString("N'INVENTE JAMAIS un taux de taxe", $prompt);
        $this->assertStringContainsString('lire_fiche(entite=Taxe)', $prompt);
    }

    public function testPromptPorteLaBoussoleEtSonEtat(): void
    {
        static::bootKernel();
        $builder = static::getContainer()->get(AiContextBuilder::class);

        $request = new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit Boussole SARL',
                'perimetre'      => [],
                'date'           => '2026-07-26',
                'objetsAttaches' => [],
                'boussole'       => [
                    'items' => [
                        ['axe' => 'saturation', 'libelle' => '4 client(s) sous 100 % de couverture', 'actionnable' => true, 'urgence' => 50],
                        ['axe' => 'fiscal', 'libelle' => 'TVA à jour', 'actionnable' => false, 'urgence' => 0],
                    ],
                    'prioritaire' => ['axe' => 'saturation', 'libelle' => '4 client(s) sous 100 % de couverture', 'urgence' => 50],
                ],
            ],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite()),
        );

        $prompt = $builder->toSystemPrompt($request);

        // Mission permanente + cadence + état dynamique injecté.
        $this->assertStringContainsString('TA BOUSSOLE', $prompt);
        $this->assertStringContainsString('RAPPEL À CHAQUE INTERACTION', $prompt);
        $this->assertStringContainsString('ÉTAT DE LA BOUSSOLE', $prompt);
        $this->assertStringContainsString('PRIORITÉ ACTUELLE', $prompt);
        $this->assertStringContainsString('4 client(s) sous 100 % de couverture', $prompt);
        // Routage vers le nouvel outil.
        $this->assertStringContainsString('saturation_portefeuille', $prompt);
    }
}
