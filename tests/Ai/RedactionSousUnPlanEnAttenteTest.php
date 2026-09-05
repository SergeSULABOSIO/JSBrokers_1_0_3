<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Trousse\Phase;
use App\Ai\Trousse\Trousse;
use App\Entity\AssistantConversation;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * « LE DOCUMENT A ÉTÉ CORRECTEMENT RATTACHÉ AU CLIENT 96 » — écrit au passé, l'affaire
 * close, juste au-dessus d'une barre « Valider et exécuter » que personne n'avait
 * touchée. Rien n'était enregistré (2026-08-16).
 *
 * C'est le mensonge le plus coûteux du chat, et pas le plus spectaculaire : l'utilisateur
 * ne voit aucune anomalie. Il lit une confirmation, referme la fenêtre, et ne découvre
 * qu'un mois plus tard que sa pièce n'a jamais été classée.
 *
 * LA CAUSE ÉTAIT UNE PRÉMISSE, PAS UN ÉCART. Le prompt de rédaction affirmait « LE
 * TRAVAIL EST DÉJÀ FAIT » à TOUS les tours. Quand la planification a lu des données,
 * c'est vrai ; quand elle a préparé un PLAN, c'est faux — et la consigne particulière de
 * l'outil (« n'annonce aucun enregistrement déjà fait ») perdait contre l'affirmation
 * générale placée en tête. On ne corrige pas une prémisse fausse par une exception
 * enfouie : la phase de rédaction doit SAVOIR dans quel régime elle est.
 *
 * Ce test verrouille les deux régimes, puis la ceinture qui rattrape le modèle s'il
 * écrit au passé malgré tout.
 */
class RedactionSousUnPlanEnAttenteTest extends KernelTestCase
{
    use JeuDeTestKetTrait;

    /**
     * RÉGIME « DÉCISION EN ATTENTE » : rien n'est écrit, la rédaction doit le dire et
     * employer le futur.
     */
    public function testAvecUnPlanEnAttenteLaRedactionEcritAuFutur(): void
    {
        $prompt = $this->promptDeRedaction(decisionEnAttente: true);

        $this->assertStringContainsString('RIEN N’EST ENCORE ENREGISTRÉ', $prompt);
        $this->assertStringContainsString('ÉCRIS DONC AU FUTUR', $prompt);
        $this->assertStringContainsString('a été rattaché', $prompt, 'La tournure fautive doit être nommée pour être proscrite.');
        $this->assertStringNotContainsString(
            'LE TRAVAIL EST DÉJÀ FAIT',
            $prompt,
            'C’est l’affirmation qui a produit l’incident : elle ne doit pas subsister quand une décision attend.',
        );
    }

    /**
     * RÉGIME ORDINAIRE : une lecture est bel et bien faite, et la rédaction doit garder
     * l'assurance qui lui permet de répondre sans se dédire. Corriger un défaut ne doit
     * pas en créer un autre — une Ket qui parlerait au futur d'un chiffre déjà calculé
     * serait aussi fausse, dans l'autre sens.
     */
    public function testSansDecisionEnAttenteLeRegimeOrdinaireEstIntact(): void
    {
        $prompt = $this->promptDeRedaction(decisionEnAttente: false);

        $this->assertStringContainsString('LE TRAVAIL EST DÉJÀ FAIT', $prompt);
        $this->assertStringNotContainsString('RIEN N’EST ENCORE ENREGISTRÉ', $prompt);
    }

    /**
     * LA CEINTURE. Le contenu d'une bulle reste écrit par un modèle : quand la prose
     * affirme malgré tout un enregistrement accompli sous une décision en attente, le
     * serveur rétablit les faits.
     *
     * @dataProvider prosesAuPasse
     */
    public function testLePasseSousUneDecisionEnAttenteEstRattrape(string $prose): void
    {
        $this->assertTrue(PlanEnAttente::estUneExecutionPrematuree($prose, true));
        $this->assertFalse(
            PlanEnAttente::estUneExecutionPrematuree($prose, false),
            'Sans décision en attente, ce démenti relève de l’autre garde-fou — pas de celui-ci.',
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function prosesAuPasse(): iterable
    {
        yield 'incident du 2026-08-16' => [
            'Le document « AR Demande IDNAT AIB RDC (1).pdf » a été correctement rattaché au client 96 '
            . '(Mr. jean de dieu).',
        ];
        yield 'création annoncée' => ['Le client a bien été enregistré dans la base de données.'];
    }

    /**
     * Une réponse déjà écrite AU FUTUR ne doit évidemment rien déclencher : le garde-fou
     * ne corrige que ce qui est faux.
     *
     * @dataProvider prosesHonnetes
     */
    public function testUneAnnonceAuFuturNestPasRattrapee(string $prose): void
    {
        $this->assertFalse(PlanEnAttente::estUneExecutionPrematuree($prose, true));
    }

    /** @return iterable<string, array{0: string}> */
    public static function prosesHonnetes(): iterable
    {
        yield 'futur simple' => [
            'Le document « AR Demande IDNAT.pdf » sera rattaché au client Jean de Dieu. '
            . 'Cliquez sur « Valider et exécuter » pour l’enregistrer.',
        ];
        yield 'conditionnel' => ['Si vous validez, la pièce serait classée dans les documents du client.'];
    }

    /** Le prompt réellement envoyé à la phase de rédaction, dans l'un des deux régimes. */
    private function promptDeRedaction(bool $decisionEnAttente): string
    {
        static::bootKernel();
        $conteneur = static::getContainer();

        [$entreprise, $invite] = $this->jeuDeTestKet();

        $requete = $conteneur->get(AiContextBuilder::class)
            ->build($entreprise, $invite, new AssistantConversation())
            ->withDecisionEnAttente($decisionEnAttente);

        return $conteneur->get(AiContextBuilder::class)->toSystemPrompt($requete, Trousse::ECRITURE, Phase::REDACTION);
    }
}
