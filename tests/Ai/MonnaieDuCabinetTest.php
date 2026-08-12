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
 * LA MONNAIE DU CABINET, ET L'EURO QUI N'EST LA MONNAIE DE RIEN.
 *
 * L'INCIDENT (2026-08-12). Ket a présenté à un courtier congolais — qui travaille
 * en dollars — un « Budget de l'opération : COÛT ESTIMÉ 50 € ». Deux fautes en une :
 * le budget de la plateforme est en TOKENS et ne porte aucune monnaie, et l'euro
 * n'est la monnaie de personne ici (l'économie de tokens est libellée en USD, et
 * le cabinet lit ses montants dans la monnaie configurée dans ses paramètres).
 *
 * LA CAUSE ÉTAIT DANS LE PROMPT. Il ne nommait AUCUNE monnaie, et le seul symbole
 * monétaire que le modèle y rencontrait était « € », dans l'exemple de graphique
 * (« "unite":"€" … en euros »). Ket a appris l'euro de nous.
 *
 * Ces assertions verrouillent les deux sens : plus aucun euro en dur, et la monnaie
 * réellement configurée est ÉNONCÉE — aux deux phases, car c'est en RÉDIGEANT qu'on
 * écrit un symbole monétaire, pas en planifiant.
 */
class MonnaieDuCabinetTest extends KernelTestCase
{
    private function requete(?string $monnaie): AiRequest
    {
        return new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit Monnaie SARL',
                'perimetre'      => [],
                'date'           => '2026-08-12',
                'monnaie'        => $monnaie,
                'objetsAttaches' => [],
            ],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite()),
        );
    }

    private function builder(): AiContextBuilder
    {
        static::bootKernel();

        return static::getContainer()->get(AiContextBuilder::class);
    }

    public function testLePromptNeContientAucunEuroEnDur(): void
    {
        $builder = $this->builder();

        foreach ([null, 'USD', 'CDF'] as $monnaie) {
            foreach ([null, Phase::REDACTION] as $phase) {
                $prompt = $builder->toSystemPrompt($this->requete($monnaie), null, $phase);

                $this->assertStringNotContainsString('€', $prompt, 'Aucun symbole euro ne doit subsister dans le prompt.');
                $this->assertStringNotContainsString('en euros', $prompt, 'Aucune légende « en euros » ne doit subsister.');
            }
        }
    }

    public function testLePromptEnonceLaMonnaieConfigureeDuCabinet(): void
    {
        $builder = $this->builder();

        $prompt = $builder->toSystemPrompt($this->requete('CDF'));

        $this->assertStringContainsString('MONNAIE', $prompt);
        $this->assertStringContainsString('ce cabinet lit ses montants en CDF', $prompt);
    }

    /** C'est en rédigeant qu'on écrit un montant : la règle doit survivre à la phase 2. */
    public function testLaRegleDeMonnaieEstPresenteAussiALaRedaction(): void
    {
        $builder = $this->builder();

        $prompt = $builder->toSystemPrompt($this->requete('CDF'), null, Phase::REDACTION);

        $this->assertStringContainsString('ce cabinet lit ses montants en CDF', $prompt);
    }

    /**
     * Sans monnaie configurée, le repli est USD — celui de ServiceMonnaies —, jamais
     * l'euro. C'est précisément la substitution silencieuse qu'on interdit.
     */
    public function testSansMonnaieConfigureeLeRepliEstUsdEtJamaisLEuro(): void
    {
        $builder = $this->builder();

        $prompt = $builder->toSystemPrompt($this->requete(null));

        $this->assertStringContainsString('ce cabinet lit ses montants en USD', $prompt);
    }

    /**
     * Le budget n'est pas de l'argent : le prompt doit le dire, sans quoi le modèle
     * lui accole naturellement un symbole monétaire — c'est exactement ce qu'il a fait.
     */
    public function testLePromptInterditDeLibellerLeBudgetEnMonnaie(): void
    {
        $builder = $this->builder();

        $prompt = $builder->toSystemPrompt($this->requete('USD'));

        $this->assertStringContainsString('Le BUDGET en tokens, lui, n\'est', $prompt);
        $this->assertStringContainsString('PAS de l\'argent', $prompt);
        $this->assertStringContainsString('symbole monétaire', $prompt);
    }
}
