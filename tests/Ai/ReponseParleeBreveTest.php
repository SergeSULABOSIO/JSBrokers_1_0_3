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
     * LES RÈGLES QUI CONTREDISENT LA BRIÈVETÉ NE SONT PLUS ENVOYÉES À L'ORAL.
     *
     * C'est le levier le plus fort du lot, et il ne consiste pas à parler plus fort :
     * six lignes de brièveté ne pesaient rien contre quatre-vingt-dix lignes qui
     * expliquaient comment faire un tableau de vingt lignes, mettre des émojis en tête
     * et structurer par des titres. Le modèle suivait le plus bavard.
     *
     * @dataProvider phasesQuiRedigent
     */
    public function testEnModeLiveAucuneRegleDeMiseEnFormeNEstEnvoyee(?Phase $phase): void
    {
        $prompt = $this->builder()->toSystemPrompt($this->requete()->enModeLive(), null, $phase);

        foreach (['TABLEAUX', 'ÉMOJIS', 'GRAPHIQUES', 'vingt lignes'] as $interdit) {
            self::assertStringNotContainsString(
                $interdit,
                $prompt,
                sprintf('« %s » n’a aucun sens pour une oreille.', $interdit),
            );
        }
    }

    /**
     * ⚠ ET LE RÉGIME ÉCRIT NE PAIE RIEN. Les mêmes règles doivent rester intactes hors
     * mode Live : la correction ne concerne que la conversation parlée.
     */
    public function testHorsModeLiveLesReglesDeMiseEnFormeRestentIntactes(): void
    {
        $prompt = $this->builder()->toSystemPrompt($this->requete(), null, Phase::REDACTION);

        self::assertStringContainsString('TABLEAUX', $prompt);
        self::assertStringContainsString('ÉMOJIS', $prompt);
    }

    // ⚠ LA BOUSSOLE (point D du plan) N'EST PAS COUVERTE ICI, et il faut le dire : le
    // harnais de ce fichier construit un contexte SANS boussole — le prompt rend alors
    // « ÉTAT DE LA BOUSSOLE : indisponible », et aucune assertion sur la phrase de
    // rappel ne prouverait quoi que ce soit. Un vert obtenu ainsi serait pire que rien.
    // Le comportement se vérifie sur un contexte réel, par
    // `app:assistant:tokens:composition`.

    /** Les deux phases où une réponse peut être rédigée — planification comprise. */
    public static function phasesQuiRedigent(): array
    {
        // Un tour sans appel d'outil se termine DÈS la planification : c'est pourquoi
        // elle compte autant que la rédaction.
        return ['planification' => [null], 'rédaction' => [Phase::REDACTION]];
    }

    /**
     * ⚠ CE TEST A CHANGÉ DE FORME LE 2026-09-24, PAS D'INTENTION.
     *
     * Il exigeait que la consigne orale soit ABSENTE de la planification, pour une
     * raison juste : « l'y glisser reviendrait à faire chercher moins, au lieu de dire
     * plus court ». Mais une mesure de production l'a démenti — cent-soixante-et-onze
     * secondes de parole sur un seul tour. La cause : un tour qui n'appelle AUCUN outil
     * se termine DÈS LA PLANIFICATION, et ne voyait donc jamais la consigne. C'est le
     * cas le plus fréquent d'une conversation parlée.
     *
     * L'intention est conservée telle quelle, et c'est elle que ce test protège
     * désormais : la consigne posée en planification ne doit parler QUE DE FORME, et
     * dire expressément que le travail ne change pas.
     */
    public function testEnPlanificationLaConsigneOraleNeTouchePasAuTRAVAIL(): void
    {
        $prompt = $this->builder()->toSystemPrompt($this->requete()->enModeLive(), null, null);

        // La forme est bien rappelée là où la réponse peut se décider.
        self::assertStringContainsString('TU ES ÉCOUTÉE', $prompt);
        self::assertStringContainsString('DEUX À QUATRE PHRASES', $prompt);

        // ET LE GARDE-FOU, EN TOUTES LETTRES : chercher autant, dire plus court.
        self::assertStringContainsString('CELA NE CHANGE RIEN À TON TRAVAIL', $prompt);
        self::assertStringContainsString('mêmes outils, mêmes recherches, mêmes chiffres', $prompt);
    }

    /**
     * HORS MODE LIVE, LA PLANIFICATION NE BOUGE PAS D'UN OCTET. Le régime écrit n'a
     * aucune raison de payer une correction qui ne le concerne pas.
     */
    public function testHorsModeLiveLaPlanificationEstInchangee(): void
    {
        $prompt = $this->builder()->toSystemPrompt($this->requete(), null, null);

        self::assertStringNotContainsString('TU ES ÉCOUTÉE', $prompt);
        self::assertStringNotContainsString('CELA NE CHANGE RIEN À TON TRAVAIL', $prompt);
    }

    /**
     * LA CONSIGNE ORALE EST LE DERNIER MOT DU PROMPT DE RÉDACTION.
     *
     * La dernière instruction d'un prompt est la plus suivie. Elle arrivait avant la
     * boussole — laquelle réclame, elle, un rappel de fin de réponse : la brièveté se
     * faisait donc contredire par la position même de ce qui la suivait.
     */
    public function testLaConsigneOraleEstLeDernierBlocDeLaRedaction(): void
    {
        $prompt = $this->builder()->toSystemPrompt($this->requete()->enModeLive(), null, Phase::REDACTION);

        $position = mb_strpos($prompt, 'TU ES ÉCOUTÉE');
        self::assertNotFalse($position);
        self::assertStringNotContainsString(
            'PRIORITÉ ACTUELLE',
            mb_substr($prompt, $position),
            'la boussole doit précéder la consigne orale, jamais la suivre',
        );
    }
}
