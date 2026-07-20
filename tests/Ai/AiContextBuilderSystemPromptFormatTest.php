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
}
