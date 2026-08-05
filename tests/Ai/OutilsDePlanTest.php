<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\Mutation\OutilsDePlan;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolProduisantUnPlan;
use App\Entity\Entreprise;
use App\Entity\Invite;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La liste des outils capables de faire naître un plan (et donc le bouton
 * « Valider et exécuter ») était énumérée À LA MAIN dans le prompt système, et a
 * dérivé deux fois en production : un outil d'écriture ajouté n'y figurait pas, et
 * le modèle — ne sachant plus qu'il devait l'APPELER — recopiait en prose le
 * tableau du tour précédent (plan fantôme, aucun bouton).
 *
 * Ces tests ferment la dérive dans les deux sens : la liste est DÉRIVÉE du code, et
 * un outil qui délègue à preparer_operations sans porter le marqueur fait ÉCHOUER
 * la suite.
 */
class OutilsDePlanTest extends KernelTestCase
{
    /** Les seuls outils dont un « pret: true » produit une barre de décision. */
    private const ATTENDUS = [
        'preparer_operations',
        'preparer_marquage_non_renouvelable',
        'preparer_mouvement_avenant',
        'modifier_composition_prime',
        'signaler_paiement_prime',
    ];

    /**
     * Outils qui produisent une uiAction ou un gabarit SANS barre de décision : les
     * annoncer comme producteurs de plan ferait croire au modèle qu'un bouton
     * « Valider et exécuter » existe. parcours_saisie était dans l'ancienne liste
     * écrite à la main — il n'a jamais chiffré de budget.
     */
    private const EXCLUS = [
        'parcours_saisie',
        'suivi_impayes',
        'ouvrir_dialogue',
        'preparer_envoi_soa',
        'rechercher_entites',
    ];

    private function registre(): OutilsDePlan
    {
        static::bootKernel();

        return static::getContainer()->get(OutilsDePlan::class);
    }

    public function testListeDeriveeDuCodeEtDeterministe(): void
    {
        $noms = $this->registre()->noms();

        foreach (self::ATTENDUS as $attendu) {
            $this->assertContains($attendu, $noms, sprintf('%s doit être marqué AiToolProduisantUnPlan.', $attendu));
        }
        foreach (self::EXCLUS as $exclu) {
            $this->assertNotContains($exclu, $noms, sprintf('%s ne produit PAS de barre de décision.', $exclu));
        }

        // Le pivot en tête : c'est l'outil que le modèle doit appeler par défaut.
        $this->assertSame('preparer_operations', $noms[0]);
        // Ordre stable d'un tour à l'autre (le prompt système ne doit pas bouger) :
        // l'ordre d'itération d'un tag de conteneur, lui, n'est pas garanti.
        $this->assertSame($noms, $this->registre()->noms());
    }

    public function testEnumerationLisiblePourLePrompt(): void
    {
        $enumeration = $this->registre()->enumeration();

        $this->assertStringContainsString('preparer_operations, ', $enumeration);
        $this->assertStringContainsString(' ou ', $enumeration);
        $this->assertStringNotContainsString('parcours_saisie', $enumeration);
    }

    /**
     * GARDE-FOU STRUCTUREL — la cause racine des deux incidents. Tout outil qui
     * délègue à PreparerOperationsTool produit un plan : il DOIT porter le marqueur,
     * sinon le prompt ne le nommera pas et l'oubli repassera en production.
     */
    public function testToutOutilDelegantAPreparerOperationsPorteLeMarqueur(): void
    {
        $fichiers = glob(__DIR__ . '/../../src/Ai/Tool/*Tool.php') ?: [];
        $this->assertNotEmpty($fichiers, 'Aucun outil trouvé : chemin de scan à revoir.');

        $verifies = 0;
        foreach ($fichiers as $fichier) {
            $source = (string) file_get_contents($fichier);
            // Signature de la délégation : l'outil confie ses opérations au moteur unique.
            if (!str_contains($source, '$this->preparer->execute(')) {
                continue;
            }
            ++$verifies;
            $classe = 'App\\Ai\\Tool\\' . basename($fichier, '.php');
            $this->assertTrue(
                is_subclass_of($classe, AiToolProduisantUnPlan::class),
                sprintf(
                    '%s délègue à preparer_operations : il produit un plan et doit implémenter '
                    . 'AiToolProduisantUnPlan, sans quoi le prompt système ne le nommera pas '
                    . '(régression du plan fantôme).',
                    $classe,
                ),
            );
        }

        $this->assertGreaterThanOrEqual(4, $verifies, 'Les outils délégants ne sont plus détectés.');
    }

    /**
     * Le prompt doit nommer TOUS les outils dérivés — c'est le nom manquant
     * (signaler_paiement_prime, le 2026-08-05) qui a laissé le modèle croire qu'un
     * plan pouvait naître d'une simple lecture.
     */
    public function testLePromptNommeTousLesOutilsDePlanEtInterditLeRecopiage(): void
    {
        static::bootKernel();
        $builder = static::getContainer()->get(AiContextBuilder::class);
        $registre = static::getContainer()->get(OutilsDePlan::class);

        $prompt = $builder->toSystemPrompt(new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit OutilsDePlan SARL',
                'perimetre'      => [],
                'date'           => '2026-08-05',
                'objetsAttaches' => [],
            ],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite()),
        ));

        $this->assertStringContainsString('liste EXHAUSTIVE de ces outils', $prompt);
        foreach ($registre->noms() as $nom) {
            $this->assertStringContainsString($nom, $prompt, sprintf('Le prompt doit nommer %s.', $nom));
        }

        // Interdiction de réutiliser le tableau d'un tour précédent comme gabarit,
        // et nommage explicite du piège des demandes répétitives.
        $this->assertStringContainsString('NE RECOPIE JAMAIS UN PLAN DÉJÀ PRÉSENTÉ', $prompt);
        $this->assertStringContainsString('« le suivant »', $prompt);
        // Une lecture ne fait pas un plan, même quand elle donne toutes les valeurs
        // nécessaires pour en écrire un (le cas exact de l'incident).
        $this->assertStringContainsString('autre outil de LECTURE', $prompt);
        $this->assertStringContainsString('même si la lecture t\'a donné toutes les valeurs', $prompt);
    }
}
