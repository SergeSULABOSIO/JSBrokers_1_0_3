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
    /**
     * COMPRENDRE AVANT D'AGIR (demandé le 2026-08-12). Le modèle doit remettre au
     * propre la demande avant de l'outiller, et s'ARRÊTER sur une reformulation +
     * des questions quand elle reste ambiguë — plutôt que d'agir de travers.
     */
    public function testLePromptImposeDeReformulerAvantDAgir(): void
    {
        $prompt = $this->builder()->toSystemPrompt($this->requete('USD'));

        $this->assertStringContainsString('COMPRENDRE AVANT D\'AGIR', $prompt);
        $this->assertStringContainsString('Ce que je comprends', $prompt);
        $this->assertStringContainsString('Voici comment j\'ai compris votre demande', $prompt);
        // La longueur ou le désordre d'une consigne ne sont PAS des motifs de question :
        // sans cette borne, la règle dégénère en interrogatoire.
        $this->assertStringContainsString('cela se range, cela ne se redemande pas', $prompt);
    }

    /**
     * Un refus d'outil doit être dit en clair. Le 2026-08-12, Ket a traduit un refus
     * exploitable en « Ajustement technique requis » puis a proposé de « lancer la
     * création par étapes » — une impasse polie, dont l'utilisateur ne pouvait rien
     * faire.
     */
    public function testLePromptInterditDeTraduireUnRefusEnPanneTechnique(): void
    {
        $prompt = $this->builder()->toSystemPrompt($this->requete('USD'));

        $this->assertStringContainsString('UN REFUS SE DIT EN CLAIR', $prompt);
        $this->assertStringContainsString('ajustement', $prompt);
        $this->assertStringContainsString('par étapes', $prompt);
    }

    /**
     * L'INTERDIT LE PLUS GRAVE (2026-08-12). Ket a annoncé « Le dossier complet a été
     * enregistré avec succès dans la base de données » sans qu'aucun plan n'ait été
     * validé — donc sans qu'une seule ligne n'ait été écrite. Le prompt doit poser
     * l'interdit en toutes lettres, et lever l'ambiguïté du « je confirme » : il
     * porte sur les PROPOSITIONS, jamais sur le plan, qui se valide au bouton.
     */
    public function testLePromptInterditDAnnoncerUnEnregistrementNonFait(): void
    {
        $prompt = $this->builder()->toSystemPrompt($this->requete('USD'));

        $this->assertStringContainsString('N\'ANNONCE JAMAIS UN ENREGISTREMENT QUI N\'A PAS EU LIEU', $prompt);
        $this->assertStringContainsString('enregistré avec succès', $prompt);
        $this->assertStringContainsString('RIEN N\'EST ENREGISTRÉ', $prompt);
        // Le « je confirme » de l'utilisateur ne vaut pas validation.
        $this->assertStringContainsString('je confirme', $prompt);
        // Et la bonne suite est nommée : appeler l'outil, pas récapituler.
        $this->assertStringContainsString('l\'APPEL de l\'outil d\'écriture', $prompt);
    }

    public function testLePromptInterditDeLibellerLeBudgetEnMonnaie(): void
    {
        $builder = $this->builder();

        $prompt = $builder->toSystemPrompt($this->requete('USD'));

        $this->assertStringContainsString('Le BUDGET en tokens, lui, n\'est', $prompt);
        $this->assertStringContainsString('PAS de l\'argent', $prompt);
        $this->assertStringContainsString('symbole monétaire', $prompt);
    }
}
