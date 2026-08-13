<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\NormaliseurDeDates;
use App\Ai\Mutation\PlanBuilder;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\EntiteLibelle;
use App\Ai\Tool\PreparerOperationsTool;
use App\Ai\Proposition\RevenuCourtierPrescrit;
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
    use EntiteCanoniqueDeTest;

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
            new NormaliseurDeDates(),
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

        $generique = (new PreparerOperationsTool($builder, $this->entiteCanonique()))->execute([
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
            // Aucun type de revenu dans ce jeu d'essai : la dérivation de la commission
            // s'abstient et le dit, ce qui ne change pas la STRUCTURE du plan — c'est
            // précisément ce que ce test compare.
            new RevenuCourtierPrescrit($search, $resolver, $this->resolveurAvec([], $resolver)),
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

        $result = (new PreparerOperationsTool($this->planBuilder($mutation, $tokens), $this->entiteCanonique()))->execute([
            'operations' => [['op' => 'create', 'entite' => 'Cotation', 'champs' => ['nom' => 'X']]],
        ], $this->scope());

        $this->assertFalse($result->data['pret']);
        $this->assertNotEmpty($result->data['manquants']);
        $this->assertArrayHasKey('Cotation', $result->data['inventaire']);
        $this->assertArrayHasKey('obligatoires', $result->data['inventaire']['Cotation']);
        $this->assertStringContainsString('N\'appelle PAS inventaire_champs', $result->data['note']);
    }

    /**
     * L'INCIDENT DU 2026-08-13, DE BOUT EN BOUT : « Modifie le taux de commission à
     * 20 % » sur un risque.
     *
     * Le modèle dicte `tauxCommission` — le libellé que la fiche affiche, camel-casé.
     * Le formulaire, lui, nomme ce champ `pourcentageCommissionSpecifiqueHT`. Le
     * champ était écarté sans un mot, la modification devenait VIDE, le plan
     * s'affichait quand même (budget 0), l'utilisateur validait, l'exécuteur
     * journalisait « ok » — et Ket annonçait « taux de commission : 20 % » sur une
     * fiche restée vide. Le libellé, ici, rattrape ce que le préfixe ne pouvait pas.
     */
    public function testUnChampDicteSousSonLibelleEntreDansLePlan(): void
    {
        [, , $tokens] = $this->dependances();

        $mutation = $this->createMock(WorkspaceMutationService::class);
        $mutation->method('analyserOperation')->willReturn([
            'ok' => true, 'statut' => 'ok', 'entite' => 'Risque', 'libelle' => 'Risques',
            'cible' => 'VOY', 'manquants' => [], 'impacts' => [], 'bloque' => false, 'portefeuille' => null,
        ]);
        $mutation->method('facturablesDetailles')->willReturn([
            ['fqcn' => 'App\\Entity\\Risque', 'entite' => 'Risque', 'etape' => null],
        ]);
        $mutation->method('collectionsProposables')->willReturn([]);
        $mutation->method('inventaireChamps')->willReturn(self::INVENTAIRE_RISQUE);

        $result = (new PreparerOperationsTool($this->planBuilder($mutation, $tokens), $this->entiteCanonique()))->execute([
            'operations' => [['op' => 'edit', 'entite' => 'Risque', 'id' => 142, 'champs' => ['tauxCommission' => 20]]],
        ], $this->scope());

        $this->assertTrue($result->data['pret']);
        $this->assertSame(
            ['pourcentageCommissionSpecifiqueHT' => 20],
            $result->uiAction['plan'][0]['fields'],
            'Le plan doit porter le NOM RÉEL du champ, sans quoi il n’écrira rien.',
        );
        $this->assertNotEmpty($result->data['defauts'], 'Le rattrapage est annoncé, jamais silencieux.');
    }

    /**
     * ET QUAND ON NE SAIT PAS RATTACHER : plus de plan du tout. Une modification à
     * qui il ne reste aucun champ n'écrirait rien — la présenter, c'est promettre un
     * enregistrement qui n'aura pas lieu.
     */
    public function testUneModificationSansChampRattachableNeDevientPasUnPlan(): void
    {
        [, , $tokens] = $this->dependances();

        $mutation = $this->createMock(WorkspaceMutationService::class);
        $mutation->method('analyserOperation')->willReturn([
            'ok' => true, 'statut' => 'ok', 'entite' => 'Risque', 'libelle' => 'Risques',
            'cible' => 'VOY', 'manquants' => [], 'impacts' => [], 'bloque' => false, 'portefeuille' => null,
        ]);
        $mutation->method('facturablesDetailles')->willReturn([]);
        $mutation->method('collectionsProposables')->willReturn([]);
        $mutation->method('inventaireChamps')->willReturn(self::INVENTAIRE_RISQUE);

        $result = (new PreparerOperationsTool($this->planBuilder($mutation, $tokens), $this->entiteCanonique()))->execute([
            'operations' => [['op' => 'edit', 'entite' => 'Risque', 'id' => 142, 'champs' => ['couleurPreferee' => 'bleu']]],
        ], $this->scope());

        $this->assertFalse($result->data['pret']);
        $this->assertNull($result->uiAction, 'Aucune barre de décision : il n’y a rien à valider.');
        $this->assertNotEmpty($result->data['champsIgnores']);
        $this->assertArrayHasKey('Risque', $result->data['inventaire']);
        $this->assertStringContainsString('couleurPreferee', $result->data['note']);
    }

    /** L'inventaire réel d'un Risque : chaque champ porte son libellé d'écran. */
    private const INVENTAIRE_RISQUE = [
        'entite'       => 'Risque',
        'libelle'      => 'Risques',
        'obligatoires' => [
            ['champ' => 'nomComplet', 'libelle' => 'Nom Complet'],
            ['champ' => 'code', 'libelle' => 'Code'],
        ],
        'facultatifs'  => [
            ['champ' => 'description', 'libelle' => 'Description'],
            ['champ' => 'pourcentageCommissionSpecifiqueHT', 'libelle' => 'Taux de commission'],
            ['champ' => 'branche', 'libelle' => 'Branche'],
            ['champ' => 'imposable', 'libelle' => 'Imposable ?'],
        ],
        'auto'         => [],
    ];

    /**
     * LE PIÈGE DE L'UNION « + », qui a déjà mordu quatre fois.
     *
     * L'union de tableaux de PHP garde la clé de GAUCHE. Un outil qui enrichit le
     * résultat de PlanBuilder par `$data + ['defauts' => …]` voit donc sa propre
     * liste ÉCARTÉE EN SILENCE dès que PlanBuilder produit la même clé — ce qui est
     * arrivé le 2026-08-12 quand les déductions contextuelles sont entrées dans
     * `defauts` : trois outils ont cessé d'annoncer leurs hypothèses, sans la moindre
     * erreur. Le même piège avait déjà été payé sur `avertissements`, et le remède
     * est écrit en commentaire dans ces fichiers depuis.
     *
     * Ces listes se COMPOSENT (array_merge), elles ne se remplacent pas : les
     * hypothèses du serveur et celles de l'outil sont toutes des choix faits à la
     * place de l'utilisateur, et toutes doivent lui être annoncées. Ce test lit les
     * sources : il tombe si quelqu'un réintroduit la forme dangereuse.
     */
    public function testAucunOutilNEcraseLesListesCumulativesDuPlan(): void
    {
        // Les clés surveillées sont DÉRIVÉES d'un vrai plan, jamais recopiées : ce
        // sont exactement celles que PlanBuilder possède. Une clé qu'il ne produit
        // pas (« resolutions », propre à certains outils) ne court aucun risque, et
        // le jour où il en produira une nouvelle, elle sera surveillée d'office.
        [$generique] = $this->lesDeuxPlans();
        $cumulatives = array_keys(array_filter($generique->data, is_array(...)));
        self::assertContains('defauts', $cumulatives, 'Le plan doit porter ses déductions.');

        $fichiers = glob(__DIR__ . '/../../src/Ai/Tool/*.php') ?: [];
        self::assertNotEmpty($fichiers, 'Les sources des outils doivent être lisibles.');

        foreach ($fichiers as $fichier) {
            $source = (string) file_get_contents($fichier);
            foreach ($cumulatives as $cle) {
                // Une clé cumulative posée dans un littéral d'union « + [ … ] ».
                $dangereux = (bool) preg_match(
                    '/\+\s*\[[^]]{0,400}?[\'"]' . $cle . '[\'"]\s*=>/s',
                    $source,
                );
                self::assertFalse(
                    $dangereux,
                    sprintf(
                        "%s pose « %s » dans une union « + » : la clé de PlanBuilder l'emporterait et cette "
                        . 'liste disparaîtrait en silence. Compose-la avec array_merge().',
                        basename($fichier),
                        $cle,
                    ),
                );
            }
        }
    }
}
