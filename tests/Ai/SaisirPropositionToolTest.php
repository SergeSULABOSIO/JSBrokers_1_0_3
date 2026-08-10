<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\PlanBuilder;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Proposition\RevenuCourtierPrescrit;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\EntiteLibelle;
use App\Ai\Tool\SaisirPropositionTool;
use App\Entity\Assureur;
use App\Entity\Chargement;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Service\Workspace\ReferentielEnumerateur;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\JSBDynamicSearchService;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Outil « saisir_proposition » : le parcours de saisie d'une proposition exécuté
 * PAR LE SERVEUR, en un seul appel, au lieu d'être décrit au modèle qui le rejouait
 * en cinq tours (48 % des messages, 71 % des tokens et 12 des 13 saturations de la
 * campagne du 2026-08-08/09).
 *
 * Ce qui est vérifié ici tient en deux promesses : le serveur RÉSOUT ce qu'il peut
 * résoudre (assureur, opportunité, types de chargement, par leur nom), et il ne
 * DEVINE jamais le reste — un nom qui ne se résout pas devient une question portant
 * les valeurs disponibles, jamais une ligne écrite au jugé.
 *
 * La chaîne PlanBuilder est RÉELLE (seules ses dépendances sont simulées) : c'est
 * elle qui garantit que le plan produit ici est structurellement identique à celui
 * de preparer_operations.
 */
class SaisirPropositionToolTest extends TestCase
{
    use ResolveurDeTest;

    /** @var array<int, MutationOperation> opérations réellement soumises au constructeur de plan */
    protected array $operationsVues = [];

    /**
     * @param float|null $tauxRisque taux de commission prescrit par le risque de
     *                               l'opportunité, en POINTS ; null = aucun taux prescrit
     */
    protected function makeTool(
        bool $peutEcrire = true,
        array $assureursTrouves = [7 => 'SFA'],
        ?array $referentielChargement = null,
        ?float $tauxRisque = 15.0,
    ): SaisirPropositionTool {
        $this->operationsVues = [];

        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn([
            'Cotation' => 'Cotations', 'Assureur' => 'Assureurs', 'Piste' => 'Opportunités', 'Client' => 'Clients',
        ]);
        $resolver->method('can')->willReturn($peutEcrire);
        $resolver->method('canRead')->willReturn(true);

        // Le service de mutation : on capture l'opération soumise et on la déclare
        // valide — ce test porte sur ce que l'outil CONSTRUIT, pas sur la validation
        // métier, qui a ses propres tests.
        $mutation = $this->createMock(WorkspaceMutationService::class);
        $mutation->method('analyserOperation')->willReturnCallback(
            function (MutationOperation $op) {
                $this->operationsVues[] = $op;

                return [
                    'ok' => true, 'statut' => 'ok', 'entite' => $op->entityShortName,
                    'libelle' => 'Cotations', 'cible' => null, 'manquants' => [], 'impacts' => [],
                    'bloque' => false, 'portefeuille' => null,
                ];
            }
        );
        $mutation->method('facturablesDetailles')->willReturn([]);
        $mutation->method('collectionsProposables')->willReturn([]);

        $tokens = $this->createMock(TokenAccountService::class);
        $tokens->method('estimateWriteCost')->willReturn(120);
        $tokens->method('availableFor')->willReturn(10000);

        $referentiels = $this->createMock(ReferentielEnumerateur::class);
        $referentiels->method('codes')->willReturnCallback(
            static fn (string $entite) => match ($entite) {
                'Chargement' => $referentielChargement ?? [
                    1 => 'Prime nette', 2 => 'Frais accessoires', 3 => 'TVA', 4 => 'Frais ARCA',
                ],
                default      => [],
            }
        );

        // Le résolveur ne connaît que les assureurs qu'on lui donne : c'est ainsi
        // qu'on éprouve les trois issues — résolu, introuvable, ambigu.
        $resolveur = $this->resolveurAvec(['Assureur' => $assureursTrouves], $resolver);

        return new SaisirPropositionTool(
            new PlanBuilder(
                $mutation,
                $tokens,
                new PlanEnAttente($this->createMock(EntityManagerInterface::class)),
                $resolveur,
            ),
            $resolver,
            $referentiels,
            $resolveur,
            new RevenuCourtierPrescrit(
                $this->rechercheDuReferentielDeRevenus($tauxRisque),
                $resolver,
                $resolveur,
            ),
        );
    }

    /**
     * Le référentiel de revenus de l'entreprise, tel que la plateforme l'installe :
     * « Commission Ordinaire » (due par l'ASSUREUR, au taux du risque, assise sur la
     * prime nette) et « Frais de consultance » (dus par le CLIENT). Les deux ensemble,
     * car c'est leur COEXISTENCE qui faisait échouer la dérivation.
     */
    private function rechercheDuReferentielDeRevenus(?float $tauxRisque): JSBDynamicSearchService
    {
        $primeNette = (new Chargement())->setNom('Prime nette');
        (new \ReflectionProperty(Chargement::class, 'id'))->setValue($primeNette, 1);

        $ordinaire = (new TypeRevenu())->setNom('Commission Ordinaire')
            ->setAppliquerPourcentageDuRisque(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR)
            ->setShared(true)->setMultipayments(true)->setTypeChargement($primeNette);
        (new \ReflectionProperty(TypeRevenu::class, 'id'))->setValue($ordinaire, 1);

        $consultance = (new TypeRevenu())->setNom('Frais de consultance')->setPourcentage(5)
            ->setRedevable(TypeRevenu::REDEVABLE_CLIENT)
            ->setShared(false)->setMultipayments(false)->setTypeChargement($primeNette);
        (new \ReflectionProperty(TypeRevenu::class, 'id'))->setValue($consultance, 3);

        $risque = (new Risque())->setNomComplet('Incendie et Risques Annexes')->setCode('FAP')
            ->setImposable(true)->setPourcentageCommissionSpecifiqueHT($tauxRisque);
        $piste = (new Piste())->setRisque($risque);

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            static fn (string $fqcn) => ['status' => ['code' => 200], 'data' => match ($fqcn) {
                TypeRevenu::class => [$ordinaire, $consultance],
                Piste::class      => [$piste],
                default           => [],
            }]
        );

        return $search;
    }

    protected function makeScope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    /** L'offre du message #1335 du journal : tout était dit, rien n'avait été enregistré. */
    protected function argsOffreSfa(): array
    {
        return [
            'nom'            => 'Flotte automobile 2026',
            'assureur'       => 'SFA',
            'piste'          => '41',
            'dureeMois'      => 12,
            'composition'    => [
                ['nom' => 'Prime nette', 'montant' => 1000],
                ['nom' => 'Arca', 'montant' => 20],
                ['nom' => 'Tva', 'montant' => 160],
                ['nom' => 'Accessoire', 'montant' => 50],
            ],
            'nombreTranches' => 2,
        ];
    }

    public function testFailClosedSansDroitDEcriture(): void
    {
        $result = $this->makeTool(peutEcrire: false)->execute($this->argsOffreSfa(), $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
        $this->assertNull($result->uiAction);
        $this->assertSame([], $this->operationsVues, 'Aucune opération ne doit être analysée sans droit.');
    }

    /**
     * Le cas qui justifie tout le chantier : une offre dictée en clair produit un
     * plan complet, en UN SEUL appel d'outil — sans recherche préalable.
     */
    public function testUneOffreDicteeProduitUnPlanCompletEnUnSeulAppel(): void
    {
        $result = $this->makeTool()->execute($this->argsOffreSfa(), $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertTrue($result->data['pret'], 'Le plan doit être prêt sans aller-retour supplémentaire.');
        $this->assertNotNull($result->uiAction, 'Sans action d’interface, aucun bouton de validation n’apparaît.');
        $this->assertSame(PlanEnAttente::ACTION_REVUE, $result->uiAction['type']);

        $this->assertCount(1, $this->operationsVues, 'Une proposition = UNE opération de tête.');
        $op = $this->operationsVues[0];
        $this->assertSame('create', $op->op);
        $this->assertSame('Cotation', $op->entityShortName);
        $this->assertSame(7, $op->fields['assureur'], 'L’assureur doit être résolu par son nom.');
        $this->assertSame(41, (int) $op->fields['piste']);
        $this->assertSame(12, $op->fields['duree']);
    }

    /** Les composantes dictées en langage courant se rattachent au référentiel réel. */
    public function testLaCompositionEstRattacheeAuReferentielDeLEntreprise(): void
    {
        $this->makeTool()->execute($this->argsOffreSfa(), $this->makeScope());

        $chargements = $this->operationsVues[0]->collections['chargements'] ?? [];
        $this->assertCount(4, $chargements);

        $parType = [];
        foreach ($chargements as $chargement) {
            $parType[$chargement->fields['type']] = $chargement->fields['montantFlatExceptionel'];
        }
        // « Arca » → Frais ARCA (4), « Tva » → TVA (3), « Accessoire » → Frais accessoires (2).
        $this->assertSame([1 => 1000.0, 4 => 20.0, 3 => 160.0, 2 => 50.0], $parType);
    }

    /** Un découpage égal ne perd pas un centime sur l'arrondi. */
    public function testLesTranchesEgalesTotalisentExactementCentPourCent(): void
    {
        $args = ['nombreTranches' => 3] + $this->argsOffreSfa();
        $this->makeTool()->execute($args, $this->makeScope());

        $tranches = $this->operationsVues[0]->collections['tranches'] ?? [];
        $this->assertCount(3, $tranches);

        $total = 0.0;
        foreach ($tranches as $tranche) {
            $total += $tranche->fields['pourcentage'];
        }
        $this->assertSame(100.0, round($total, 2));
        // Convention unique de la plateforme : les taux sont des POINTS, jamais des fractions.
        $this->assertGreaterThan(1, $tranches[0]->fields['pourcentage']);
    }

    /**
     * Les dates des tranches partent au format DATE-HEURE attendu par les
     * formulaires. En date seule, le formulaire répondait « Veuillez saisir une date
     * et une heure valides » : le plan n'était jamais prêt et Ket réclamait à
     * l'utilisateur une exigibilité qu'elle savait pourtant dériver.
     */
    public function testLesDatesDeTranchesSontAuFormatAttenduParLesFormulaires(): void
    {
        $this->makeTool()->execute($this->argsOffreSfa(), $this->makeScope());

        foreach ($this->operationsVues[0]->collections['tranches'] as $tranche) {
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',
                $tranche->fields['payableAt'],
            );
        }
    }

    /**
     * « Payable en deux tranches de chacune 50 % » est une phrase COMPLÈTE : le
     * modèle la traduit en tranches détaillées sans date, et l'échelonnement doit
     * être dérivé, pas redemandé.
     */
    public function testDesTranchesDetailleesSansDateRecoiventUnEchelonnementDerive(): void
    {
        $args = $this->argsOffreSfa();
        unset($args['nombreTranches']);
        $args['tranches'] = [
            ['nom' => 'Tranche 1', 'pourcentage' => 50],
            ['nom' => 'Tranche 2', 'pourcentage' => 50],
        ];

        $result = $this->makeTool()->execute($args, $this->makeScope());

        $this->assertTrue($result->data['pret'], 'Aucune question ne doit être posée pour une date dérivable.');
        $tranches = $this->operationsVues[0]->collections['tranches'];
        $this->assertCount(2, $tranches);
        $this->assertNotSame(
            $tranches[0]->fields['payableAt'],
            $tranches[1]->fields['payableAt'],
            'Les tranches doivent être échelonnées, pas empilées sur la même date.',
        );
        $this->assertStringContainsString('échelonnée', implode(' ', $result->data['defauts']));
    }

    /** Un assureur introuvable devient une question portant les valeurs disponibles. */
    public function testAssureurIntrouvableDemandeAuLieuDeDeviner(): void
    {
        $result = $this->makeTool(assureursTrouves: [])->execute($this->argsOffreSfa(), $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertFalse($result->data['pret']);
        $this->assertNull($result->uiAction, 'Aucun plan, donc aucun bouton de validation.');
        $this->assertSame('Assureur', $result->data['aDemander'][0]['champ']);
        $this->assertSame('introuvable', $result->data['aDemander'][0]['probleme']);
        $this->assertSame([], $this->operationsVues, 'Rien ne doit être construit sur une relation non résolue.');
    }

    /** Deux assureurs plausibles : on demande lequel, on n'en choisit pas un. */
    public function testAssureurAmbiguDemandeLequel(): void
    {
        $result = $this->makeTool(assureursTrouves: [7 => 'SFA Vie', 9 => 'SFA IARD'])
            ->execute($this->argsOffreSfa(), $this->makeScope());

        $this->assertFalse($result->data['pret']);
        $this->assertSame('ambigu', $result->data['aDemander'][0]['probleme']);
        $this->assertSame([7 => 'SFA Vie', 9 => 'SFA IARD'], $result->data['aDemander'][0]['valeurs']);
    }

    /**
     * Une correspondance EXACTE l'emporte : « SFA » ne devient pas ambigu au motif
     * qu'un « SFA Vie » existe aussi (le LIKE « contains » les ramène tous les deux).
     */
    public function testUneCorrespondanceExacteLeveLAmbiguite(): void
    {
        $result = $this->makeTool(assureursTrouves: [7 => 'SFA', 9 => 'SFA Vie'])
            ->execute($this->argsOffreSfa(), $this->makeScope());

        $this->assertTrue($result->data['pret']);
        $this->assertSame(7, $this->operationsVues[0]->fields['assureur']);
    }

    /**
     * Le référentiel RÉEL de l'entreprise 1 (« Tva pour prime », « Frais Arca »,
     * « Frais accessoires », plus du bruit) : les libellés dictés au message #1335
     * s'y rattachent tous, sans qu'aucune question ne soit posée.
     */
    public function testRattachementSurLeReferentielReelDeLEntreprise(): void
    {
        $reel = [
            1 => 'Prime nette', 2 => 'Fronting', 3 => 'Frais accessoires',
            4 => 'Tva pour prime', 5 => 'Frais Arca', 6 => 'Sneca',
        ];

        $result = $this->makeTool(referentielChargement: $reel)->execute($this->argsOffreSfa(), $this->makeScope());

        $this->assertTrue($result->data['pret'], 'Les quatre composantes dictées doivent se rattacher seules.');
        $parType = [];
        foreach ($this->operationsVues[0]->collections['chargements'] as $c) {
            $parType[$c->fields['type']] = $c->fields['montantFlatExceptionel'];
        }
        $this->assertSame([1 => 1000.0, 5 => 20.0, 4 => 160.0, 3 => 50.0], $parType);
    }

    /**
     * L'inclusion est la règle la plus lâche : à plusieurs candidats, elle rend la
     * main. Sans cela, « Tva » aurait pris le premier de la liste — un chargement
     * rangé sous le mauvais type, et une commission fausse au bout.
     */
    public function testInclusionAmbigueDemandeAuLieuDePrendreLePremier(): void
    {
        $result = $this->makeTool(referentielChargement: [
            1 => 'Prime nette', 4 => 'Tva pour prime', 8 => 'Tva sur commission',
            3 => 'Frais accessoires', 5 => 'Frais Arca',
        ])->execute($this->argsOffreSfa(), $this->makeScope());

        $this->assertFalse($result->data['pret']);
        $demande = $result->data['aDemander'][0];
        $this->assertSame('ambigu', $demande['probleme']);
        $this->assertSame('Tva', $demande['terme']);
        $this->assertSame([4 => 'Tva pour prime', 8 => 'Tva sur commission'], $demande['valeurs']);
        $this->assertSame([], $this->operationsVues, 'Rien ne doit être écrit sur un type incertain.');
    }

    /** Sans type de chargement, la prime resterait à 0 : c'est une question, pas une ligne. */
    public function testChargementNonRattachableEstUneQuestion(): void
    {
        $result = $this->makeTool(referentielChargement: [1 => 'Prime nette'])
            ->execute($this->argsOffreSfa(), $this->makeScope());

        $this->assertFalse($result->data['pret']);
        $problemes = array_column($result->data['aDemander'], 'probleme');
        $this->assertContains('introuvable', $problemes);
        $this->assertSame([], $this->operationsVues);
    }

    /**
     * LE MANQUE QUI JUSTIFIE CE CHANTIER. L'offre du message #1335 ne dit pas un mot de
     * la commission — et il n'y a pas d'affaire d'assurance sans commission. Le plan doit
     * donc la porter, au taux prescrit par le risque, sans qu'on l'ait demandée.
     *
     * Avant, l'outil n'en créait une que si l'entreprise avait UN SEUL type de revenu :
     * condition jamais remplie (la plateforme en installe quatre), donc plus aucune
     * proposition ne repartait avec sa rémunération.
     */
    public function testLaRemunerationDuCourtierEstAjouteeSansQuOnLaDemande(): void
    {
        $result = $this->makeTool()->execute($this->argsOffreSfa(), $this->makeScope());

        $this->assertTrue($result->data['pret']);
        $revenus = $this->operationsVues[0]->collections['revenus'] ?? [];
        $this->assertCount(1, $revenus, 'Une proposition sans revenu est une affaire sans rémunération.');
        $this->assertSame(1, $revenus[0]->fields['typeRevenu'], 'La commission due par l’assureur, pas les frais du client.');
        $this->assertSame('Commission Ordinaire', $revenus[0]->fields['nom']);
        // Le taux n'est PAS recopié : il est prescrit par le risque et lu au calcul.
        $this->assertArrayNotHasKey('tauxExceptionel', $revenus[0]->fields);

        $annonce = implode(' ', $result->data['defauts']);
        $this->assertStringContainsString('15,00 %', $annonce);
        $this->assertStringContainsString('Incendie et Risques Annexes', $annonce);
    }

    /**
     * On ne devine pas un taux. Un risque qui n'en prescrit aucun fait poser la question
     * — plutôt qu'écrire une commission à 0, qui se lirait plus tard comme une affaire
     * sans rémunération.
     */
    public function testSansTauxPrescritLaQuestionEstPoseeAuLieuDEcrireZero(): void
    {
        $result = $this->makeTool(tauxRisque: null)->execute($this->argsOffreSfa(), $this->makeScope());

        $this->assertFalse($result->data['pret']);
        $this->assertNull($result->uiAction, 'Aucun plan, donc aucun bouton de validation.');
        $this->assertSame('tauxCommissionPercent', $result->data['aDemander'][0]['champ']);
        $this->assertSame([], $this->operationsVues, 'Rien ne doit être construit sur une commission incertaine.');
    }

    /** Un taux dicté DÉROGE au prescrit : il se pose sur la commission, et s'annonce comme tel. */
    public function testUnTauxDicteEstAppliqueEtAnnonce(): void
    {
        $args = ['tauxCommissionPercent' => 12.5] + $this->argsOffreSfa();

        $result = $this->makeTool()->execute($args, $this->makeScope());

        $revenus = $this->operationsVues[0]->collections['revenus'];
        // Convention unique de la plateforme : les taux sont des POINTS (12,5 = 12,5 %).
        $this->assertSame(12.5, $revenus[0]->fields['tauxExceptionel']);
        $this->assertStringContainsString('exceptionnel', implode(' ', $result->data['defauts']));
    }

    /**
     * UNE PRIME EST PAYABLE EN UNE FOIS, sauf fractionnement dicté. Sans échéance, rien
     * n'est exigible — et sans exigible, aucune commission n'est encaissable.
     */
    public function testSansFractionnementDicteLaPrimeFaitUneTrancheUniqueACentPourCent(): void
    {
        $args = $this->argsOffreSfa();
        unset($args['nombreTranches']);

        $result = $this->makeTool()->execute($args, $this->makeScope());

        $tranches = $this->operationsVues[0]->collections['tranches'] ?? [];
        $this->assertCount(1, $tranches, 'Une proposition sans échéancier ne rend rien exigible.');
        $this->assertSame(100.0, $tranches[0]->fields['pourcentage']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $tranches[0]->fields['payableAt']);
        $this->assertStringContainsString('une seule fois', implode(' ', $result->data['defauts']));
    }

    /** Les choix faits à la place de l'utilisateur voyagent avec le plan, pour être annoncés. */
    public function testLesDefautsAppliquesSontRestituesAvecLePlan(): void
    {
        $result = $this->makeTool()->execute($this->argsOffreSfa(), $this->makeScope());

        $this->assertNotEmpty($result->data['defauts'], 'Un défaut appliqué qui ne se dit pas est un défaut subi.');
        $this->assertNotEmpty($result->data['resolutions']);
        $this->assertStringContainsString('SFA', implode(' ', $result->data['resolutions']));
    }
}
