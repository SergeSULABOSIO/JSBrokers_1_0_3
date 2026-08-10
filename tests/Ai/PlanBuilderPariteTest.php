<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\PlanBuilder;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\EntiteLibelle;
use App\Ai\Tool\PreparerOperationsTool;
use App\Ai\Tool\SaisirPropositionTool;
use App\Entity\Assureur;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Workspace\ReferentielEnumerateur;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\JSBDynamicSearchService;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;

/**
 * PARITÉ DE STRUCTURE entre les deux outils qui produisent un plan.
 *
 * C'est le garde-fou du chantier. Le plan est stocké dans la meta du message, puis
 * relu par l'exécuteur déterministe, par le verrou de plan unique (PlanEnAttente),
 * par le chiffrage (estimateWriteCost) et par le bandeau de programme. Tous
 * attendent EXACTEMENT la même forme. Si un second outil se met à produire un plan
 * légèrement différent — une clé en moins dans l'action d'interface, un budget sans
 * sa ventilation par étape —, rien ne casse au moment de la préparation : la
 * divergence n'apparaît qu'à l'exécution, sur les données réelles de l'utilisateur.
 *
 * D'où ce test : les deux outils passent par PlanBuilder, et on le VÉRIFIE au lieu
 * de l'espérer.
 */
class PlanBuilderPariteTest extends TestCase
{
    use ResolveurDeTest;

    private function dependances(): array
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(['Cotation' => 'Cotations', 'Assureur' => 'Assureurs']);
        $resolver->method('can')->willReturn(true);
        $resolver->method('canRead')->willReturn(true);

        $mutation = $this->createMock(WorkspaceMutationService::class);
        $mutation->method('analyserOperation')->willReturnCallback(
            static fn (MutationOperation $op) => [
                'ok' => true, 'statut' => 'ok', 'entite' => $op->entityShortName,
                'libelle' => 'Cotations', 'cible' => null, 'manquants' => [], 'impacts' => [],
                'bloque' => false, 'portefeuille' => null,
            ]
        );
        $mutation->method('facturablesDetailles')->willReturn([
            ['fqcn' => 'App\\Entity\\Cotation', 'entite' => 'Cotation', 'etape' => 'La proposition (cotation)'],
        ]);
        $mutation->method('collectionsProposables')->willReturn([]);

        $tokens = $this->createMock(TokenAccountService::class);
        $tokens->method('estimateWriteCost')->willReturn(120);
        $tokens->method('availableFor')->willReturn(10000);

        return [$resolver, $mutation, $tokens];
    }

    private function planBuilder(WorkspaceMutationService $mutation, TokenAccountService $tokens): PlanBuilder
    {
        return new PlanBuilder(
            $mutation,
            $tokens,
            new PlanEnAttente($this->createMock(EntityManagerInterface::class)),
            $this->resolveurAvec(),
        );
    }

    private function emAvecChampNom(): EntityManagerInterface
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturnCallback(static fn (string $c) => $c === 'nom');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);

        return $em;
    }

    private function scope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    /** @return array{0: AiToolResult, 1: AiToolResult} générique, puis parcours */
    private function lesDeuxPlans(): array
    {
        [$resolver, $mutation, $tokens] = $this->dependances();
        $builder = $this->planBuilder($mutation, $tokens);

        $generique = (new PreparerOperationsTool($builder))->execute([
            'operations' => [[
                'op'     => 'create',
                'entite' => 'Cotation',
                'etape'  => 'La proposition (cotation)',
                'champs' => ['nom' => 'Flotte automobile 2026', 'assureur' => 7, 'piste' => 41, 'duree' => 12],
            ]],
        ], $this->scope());

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(static function (string $fqcn) {
            if ($fqcn !== Assureur::class) {
                return ['status' => ['code' => 200], 'data' => []];
            }
            $a = new Assureur();
            $a->setNom('SFA');
            (new \ReflectionProperty(Assureur::class, 'id'))->setValue($a, 7);

            return ['status' => ['code' => 200], 'data' => [$a]];
        });

        $referentiels = $this->createMock(ReferentielEnumerateur::class);
        $referentiels->method('codes')->willReturnCallback(
            static fn (string $e) => $e === 'Chargement' ? [1 => 'Prime nette'] : []
        );

        $parcours = (new SaisirPropositionTool(
            $builder,
            $resolver,
            $referentiels,
            $this->resolveurAvec(['Assureur' => [7 => 'SFA']], $resolver),
        ))->execute([
            'nom'         => 'Flotte automobile 2026',
            'assureur'    => 'SFA',
            'piste'       => '41',
            'dureeMois'   => 12,
            'composition' => [['nom' => 'Prime nette', 'montant' => 1000]],
        ], $this->scope());

        return [$generique, $parcours];
    }

    public function testLesDeuxOutilsProduisentLaMemeStructureDePlan(): void
    {
        [$generique, $parcours] = $this->lesDeuxPlans();

        $this->assertSame(AiToolResult::STATUS_OK, $generique->status);
        $this->assertSame(AiToolResult::STATUS_OK, $parcours->status);
        $this->assertTrue($generique->data['pret']);
        $this->assertTrue($parcours->data['pret']);

        // Le parcours ajoute ses propres clés (resolutions, defauts, noteDefauts) :
        // ce sont des INFORMATIONS pour le modèle, pas la structure du plan. Toutes
        // les clés du plan générique, elles, doivent être présentes à l'identique.
        $manquantes = array_diff(array_keys($generique->data), array_keys($parcours->data));
        $this->assertSame([], $manquantes, 'Le parcours ne doit omettre aucune clé du plan générique.');
    }

    public function testLesDeuxActionsDInterfacePortentLesMemesCles(): void
    {
        [$generique, $parcours] = $this->lesDeuxPlans();

        $this->assertNotNull($generique->uiAction);
        $this->assertNotNull($parcours->uiAction);
        $this->assertSame(
            array_keys($generique->uiAction),
            array_keys($parcours->uiAction),
            'La barre de décision est dessinée à partir de ces clés : toute divergence la casse.',
        );
        $this->assertSame($generique->uiAction['type'], $parcours->uiAction['type']);
    }

    public function testLeBudgetGardeSaVentilationParEtapeDansLesDeuxCas(): void
    {
        [$generique, $parcours] = $this->lesDeuxPlans();

        foreach (['coutEstime', 'soldeDisponible', 'resteApres', 'suffisant', 'enregistrements', 'parEtape'] as $cle) {
            $this->assertArrayHasKey($cle, $generique->data['budget'], sprintf('budget.%s manquant (générique)', $cle));
            $this->assertArrayHasKey($cle, $parcours->data['budget'], sprintf('budget.%s manquant (parcours)', $cle));
        }
        $this->assertSame($generique->data['budget']['parEtape'], $parcours->data['budget']['parEtape']);
    }

    /**
     * L'inventaire voyage désormais avec le refus « manquants » : c'est ce qui
     * supprime le tour d'inventaire_champs du protocole de saisie.
     */
    public function testLeRefusManquantsEmbarqueLInventaire(): void
    {
        [, $mutation, $tokens] = $this->dependances();

        $mutation = $this->createMock(WorkspaceMutationService::class);
        $mutation->method('analyserOperation')->willReturn([
            'ok' => false, 'statut' => 'invalide', 'entite' => 'Cotation', 'libelle' => 'Cotations',
            'cible' => null, 'manquants' => ['assureur' => ['Ce champ est obligatoire.']],
            'impacts' => [], 'bloque' => false, 'portefeuille' => null,
        ]);
        $mutation->method('facturablesDetailles')->willReturn([]);
        $mutation->method('collectionsProposables')->willReturn([]);
        $mutation->expects($this->once())
            ->method('inventaireChamps')
            ->with('Cotation')
            ->willReturn(['obligatoires' => ['assureur' => []], 'facultatifs' => [], 'auto' => []]);

        $result = (new PreparerOperationsTool($this->planBuilder($mutation, $tokens)))->execute([
            'operations' => [['op' => 'create', 'entite' => 'Cotation', 'champs' => ['nom' => 'X']]],
        ], $this->scope());

        $this->assertFalse($result->data['pret']);
        $this->assertNotEmpty($result->data['manquants']);
        $this->assertArrayHasKey('Cotation', $result->data['inventaire']);
        $this->assertArrayHasKey('obligatoires', $result->data['inventaire']['Cotation']);
        $this->assertStringContainsString('N\'appelle PAS inventaire_champs', $result->data['note']);
    }
}
