<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\Scope\AiScope;
use App\Ai\Trousse\Phase;
use App\Entity\Entreprise;
use App\Entity\Invite;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UNE RÉPONSE ÉCOUTÉE NE S'ÉCRIT PAS COMME UNE RÉPONSE LUE.
 *
 * LE REPROCHE (2026-09-19). « En mode conversation live, elle est trop verbeuse, elle
 * parle trop ; qu'elle fasse des synthèses au lieu de donner des détails. » Le fil 72
 * lui donne raison : à une question orale, Ket a répondu par un tableau de dix-neuf
 * clients et cinq colonnes. À l'écran, cela se parcourt en quelques secondes ; à
 * l'oreille, cela se subit cellule par cellule pendant plusieurs minutes.
 *
 * CE QUI CHANGE, ET CE QUI NE CHANGE PAS. Rien du métier : ni les outils, ni les
 * chiffres, ni la boussole, ni ce que Ket sait. Seule la FORME de la phrase finale est
 * concernée, et seulement quand la question a été posée à la voix. Le détail n'est pas
 * supprimé, il est DEMANDÉ : c'est le propre d'une conversation.
 */
class ReponseParleeBreveTest extends KernelTestCase
{
    private function requete(): AiRequest
    {
        return new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit Oral SARL',
                'perimetre'      => [],
                'date'           => '2026-09-19',
                'monnaie'        => 'USD',
                'objetsAttaches' => [],
            ],
            messages: [['role' => 'user', 'content' => 'Combien de clients ai-je ?']],
            scope: new AiScope(new Entreprise(), new Invite()),
        );
    }

    private function builder(): AiContextBuilder
    {
        static::bootKernel();

        return static::getContainer()->get(AiContextBuilder::class);
    }

    public function testEnModeLiveLaRedactionEstPrieeDEtreBreveEtSansTableau(): void
    {
        $prompt = $this->builder()->toSystemPrompt($this->requete()->enModeLive(), null, Phase::REDACTION);

        self::assertStringContainsString('TU ES ÉCOUTÉE, PAS LUE', $prompt);
        self::assertStringContainsString('DEUX À QUATRE PHRASES', $prompt);
        self::assertStringContainsString('AUCUN TABLEAU', $prompt);
        self::assertStringContainsString("LE DÉTAIL SEULEMENT S'IL EST DEMANDÉ", $prompt);
    }

    /** À L'ÉCRIT, RIEN NE CHANGE : un tableau y est souvent la meilleure réponse. */
    public function testHorsModeLiveAucuneConsigneOraleNEstDonnee(): void
    {
        $prompt = $this->builder()->toSystemPrompt($this->requete(), null, Phase::REDACTION);

        self::assertStringNotContainsString('TU ES ÉCOUTÉE', $prompt);
        self::assertStringNotContainsString('AUCUN TABLEAU', $prompt);
    }

    /**
     * La consigne ne vaut QUE pour la plume. La planification choisit les outils et lit
     * les données : l'y glisser reviendrait à faire chercher moins, au lieu de dire plus
     * court — c'est-à-dire à toucher au travail de Ket, et non à sa forme.
     */
    public function testLaConsigneOraleNeTouchePasALaPlanification(): void
    {
        $prompt = $this->builder()->toSystemPrompt($this->requete()->enModeLive(), null, null);

        self::assertStringNotContainsString('TU ES ÉCOUTÉE', $prompt);
    }
}
