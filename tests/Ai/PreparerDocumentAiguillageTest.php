<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolProduisantUnPlan;
use App\Ai\Tool\PreparerDocumentTool;
use App\Ai\Trousse\AiToolEcriture;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\AssistantConversation;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'OUTIL EST-IL RÉELLEMENT À PORTÉE DE KET, ET DANS LE BON TOUR ?
 *
 * Un outil parfaitement écrit mais déclaré dans la mauvaise trousse est un outil
 * qui n'existe pas : le modèle ne le voit pas, décrit en prose ce qu'il aurait
 * fait, et l'utilisateur attend un bouton qui n'arrivera jamais. C'est la
 * pathologie que TrousseCatalogue et PromptSansOutilFantomeTest combattent ; ce
 * fichier fige la décision propre à `preparer_document`.
 */
class PreparerDocumentAiguillageTest extends KernelTestCase
{
    private function scope(): AiScope
    {
        $conteneur = static::getContainer();
        $entreprise = $conteneur->get(EntrepriseRepository::class)->findOneBy([]);
        self::assertNotNull($entreprise, 'Le jeu de test doit comporter au moins une entreprise.');
        $invite = $conteneur->get(InviteRepository::class)->findOneBy(['entreprise' => $entreprise]);
        self::assertNotNull($invite, 'Le jeu de test doit comporter au moins un invité.');

        return new AiScope($entreprise, $invite);
    }

    /**
     * LA DÉCISION CENTRALE : l'outil est déclaré dans les DEUX trousses.
     *
     * Produire un document est une RESTITUTION, pas une écriture de données métier.
     * La demande arrive presque toujours juste après une analyse en lecture
     * (« fais-m'en un Word ») : le confiner à la trousse d'écriture imposerait une
     * escalade et un tour de plus pour un livrable qui ne touche aucune donnée.
     */
    public function testLOutilEstDeclareDansLesDeuxTrousses(): void
    {
        static::bootKernel();
        $catalogue = static::getContainer()->get(TrousseCatalogue::class);
        $scope = $this->scope();

        foreach ([Trousse::LECTURE, Trousse::ECRITURE] as $trousse) {
            self::assertContains(
                'preparer_document',
                $catalogue->nomsDe($trousse, $scope),
                sprintf('preparer_document doit être déclaré dans la trousse « %s ».', $trousse->value),
            );
        }
    }

    /**
     * Corollaire obligatoire : il ne porte NI le marqueur d'écriture, NI celui des
     * outils de plan. Les deux sont liés (PromptSansOutilFantomeTest exige que tout
     * outil de plan appartienne à la trousse d'écriture), donc en poser un le
     * chasserait de la trousse de lecture.
     */
    public function testLOutilNePorteAucunMarqueurDEcriture(): void
    {
        static::bootKernel();
        $outil = static::getContainer()->get(PreparerDocumentTool::class);

        self::assertNotInstanceOf(AiToolEcriture::class, $outil,
            'Le marquer « écriture » le retirerait de la trousse de lecture, où la demande naît.');
        self::assertNotInstanceOf(AiToolProduisantUnPlan::class, $outil,
            'Ce marqueur entraînerait le précédent, et rangerait le document dans le protocole d’écriture.');
    }

    /** Sa règle d'aiguillage est rendue dans le prompt des deux trousses. */
    public function testSonAiguillageEstRenduDansLesDeuxPrompts(): void
    {
        static::bootKernel();
        $conteneur = static::getContainer();
        $scope = $this->scope();

        $requete = $conteneur->get(AiContextBuilder::class)
            ->build($scope->entreprise, $scope->invite, new AssistantConversation());

        foreach ([Trousse::LECTURE, Trousse::ECRITURE] as $trousse) {
            $prompt = $conteneur->get(AiContextBuilder::class)->toSystemPrompt($requete, $trousse);
            self::assertStringContainsString('preparer_document', $prompt,
                sprintf('Le prompt de la trousse « %s » doit nommer l’outil.', $trousse->value));
        }
    }

    /**
     * Le refus « je ne peux pas fournir de fichier » est ce que la fonctionnalité
     * vient supprimer : la consigne doit voyager avec l'outil.
     */
    public function testLOutilInterditLeRefusDeGenererUnFichier(): void
    {
        static::bootKernel();
        $outil = static::getContainer()->get(PreparerDocumentTool::class);

        self::assertStringContainsString('Ne dis JAMAIS que tu ne peux pas générer', $outil->description());
        self::assertNotSame('', trim($outil->aiguillage()));
        // La parade au plafond de sortie des moteurs doit être dite au modèle.
        self::assertStringContainsString('sourceMessageId', $outil->aiguillage());
    }

    /** Le schéma reste PLAT et sans mot-clé que Gemini élague. */
    public function testLeSchemaEstPlatEtSansAdditionalProperties(): void
    {
        static::bootKernel();
        $schema = static::getContainer()->get(PreparerDocumentTool::class)->schema();

        self::assertStringNotContainsString('additionalProperties', json_encode($schema));

        // Les cinq parties imposées ne sont PLUS requises au niveau du schéma, et ce
        // n'est pas un relâchement : `reprendreDocumentId` refait un document déjà
        // produit à partir de sa spec stockée, et les exiger obligerait le modèle à
        // réécrire ce qu'il vient justement de ne pas avoir à réécrire. Le dialecte
        // accepté par Gemini est plat — « requis sauf si » n'y est pas exprimable.
        // L'exigence est donc portée par la description ET par RapportSpec, qui
        // refuse en nommant ce qui manque.
        self::assertSame([], $schema['required']);
        self::assertArrayHasKey('reprendreDocumentId', $schema['properties']);
        foreach (['titre', 'problematique', 'introduction', 'sections', 'conclusion'] as $partie) {
            self::assertArrayHasKey($partie, $schema['properties']);
        }
        self::assertStringContainsString('Obligatoire', $schema['properties']['conclusion']['description']);

        // definitions et sections sont des TABLEAUX D'OBJETS SCALAIRES : le terme est
        // une valeur, jamais une clé dynamique (leçon des incidents « collections »).
        self::assertSame('array', $schema['properties']['definitions']['type']);
        self::assertSame(
            ['terme', 'explication'],
            $schema['properties']['definitions']['items']['required'],
        );
        self::assertSame('array', $schema['properties']['sections']['type']);
    }
}
