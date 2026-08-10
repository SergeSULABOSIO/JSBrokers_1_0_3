<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\PlanBuilder;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\SouscrireCotationTool;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\JSBDynamicSearchService;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * « souscrire_cotation » : le client a dit oui, la proposition devient une police.
 *
 * L'acte le plus banal du métier, et il n'existait aucun chemin pour lui. Le
 * 2026-08-10, « elle vient de confirmer son accord pour la proposition de SUNU » a
 * coûté cinq tours de recherche, 188 000 tokens, une fenêtre de débit vidée — et
 * rien n'a été enregistré.
 *
 * Deux promesses vérifiées ici : le serveur RETROUVE la proposition à partir de ce
 * que l'utilisateur en dit, et il ne CHOISIT jamais entre des propositions
 * concurrentes — se tromper attribuerait le marché au mauvais assureur.
 */
class SouscrireCotationToolTest extends TestCase
{
    use ResolveurDeTest;

    /** @var array<int, MutationOperation> */
    private array $operationsVues = [];

    /**
     * @param array<int, array{id: int, nom: string, duree: int, avenants: int}> $cotations
     */
    private function makeTool(
        array $cotations = [[ 'id' => 126, 'nom' => 'Assurance Incendie', 'duree' => 12, 'avenants' => 0 ]],
        bool $peutEcrire = true,
    ): SouscrireCotationTool {
        $this->operationsVues = [];

        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(['Avenant' => 'Avenants', 'Assureur' => 'Assureurs']);
        $resolver->method('can')->willReturn($peutEcrire);
        $resolver->method('canRead')->willReturn(true);

        $mutation = $this->createMock(WorkspaceMutationService::class);
        $mutation->method('analyserOperation')->willReturnCallback(
            function (MutationOperation $op) {
                $this->operationsVues[] = $op;

                return [
                    'ok' => true, 'statut' => 'ok', 'entite' => $op->entityShortName,
                    'libelle' => 'Avenants', 'cible' => null, 'manquants' => [], 'impacts' => [],
                    'bloque' => false, 'portefeuille' => null,
                ];
            }
        );
        $mutation->method('facturablesDetailles')->willReturn([]);
        $mutation->method('collectionsProposables')->willReturn([]);

        $tokens = $this->createMock(TokenAccountService::class);
        $tokens->method('estimateWriteCost')->willReturn(100);
        $tokens->method('availableFor')->willReturn(10000);

        // Recherche des COTATIONS : ce que l'outil interroge une fois les relations
        // résolues. Les entités du résolveur, elles, passent par sa propre doublure.
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            static function (string $fqcn) use ($cotations) {
                if ($fqcn !== Cotation::class) {
                    return ['status' => ['code' => 200], 'data' => []];
                }
                $entites = [];
                foreach ($cotations as $donnees) {
                    $c = new Cotation();
                    $c->setNom($donnees['nom']);
                    $c->setDuree($donnees['duree']);
                    (new \ReflectionProperty(Cotation::class, 'id'))->setValue($c, $donnees['id']);
                    for ($i = 0; $i < $donnees['avenants']; $i++) {
                        $c->addAvenant(new \App\Entity\Avenant());
                    }
                    $entites[] = $c;
                }

                return ['status' => ['code' => 200], 'data' => $entites];
            }
        );

        $resolveur = $this->resolveurAvec(['Assureur' => [4 => 'SUNU IARD RDC']], $resolver);

        return new SouscrireCotationTool(
            new PlanBuilder(
                $mutation,
                $tokens,
                new PlanEnAttente($this->createMock(EntityManagerInterface::class)),
                $resolveur,
            ),
            $resolver,
            $resolveur,
            $search,
        );
    }

    private function scope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    public function testFailClosedSansDroitDEcritureSurLesAvenants(): void
    {
        $result = $this->makeTool(peutEcrire: false)->execute(['assureur' => 'SUNU'], $this->scope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
        $this->assertSame([], $this->operationsVues);
    }

    /** Le cas réel : « la proposition de SUNU est à valider » produit le contrat. */
    public function testUnePropositionAccepteeDevientUnePoliceEnUnSeulAppel(): void
    {
        $result = $this->makeTool()->execute(
            ['assureur' => 'SUNU', 'referencePolice' => 'POL-2026-INC-0042'],
            $this->scope(),
        );

        $this->assertTrue($result->data['pret']);
        $this->assertNotNull($result->uiAction);
        $this->assertCount(1, $this->operationsVues);

        $op = $this->operationsVues[0];
        $this->assertSame('create', $op->op);
        $this->assertSame('Avenant', $op->entityShortName);
        $this->assertSame(126, $op->fields['cotation']);
        $this->assertSame('POL-2026-INC-0042', $op->fields['referencePolice']);
    }

    /**
     * La période se DÉDUIT de la durée portée par la proposition — on ne la demande
     * pas, et le format est celui qu'attendent les formulaires (date-heure).
     */
    public function testLaPeriodeEstDeduiteDeLaDureeDeLaProposition(): void
    {
        $result = $this->makeTool()->execute(
            ['assureur' => 'SUNU', 'referencePolice' => 'P1', 'dateEffet' => '2026-09-01'],
            $this->scope(),
        );

        $champs = $this->operationsVues[0]->fields;
        $this->assertSame('2026-09-01T00:00', $champs['startingAt']);
        $this->assertSame('2027-08-31T00:00', $champs['endingAt'], 'Douze mois, dernier jour inclus.');
        $this->assertStringContainsString('12 mois', implode(' ', $result->data['defauts']));
    }

    /**
     * DEUX PROPOSITIONS CONCURRENTES : on demande laquelle. Choisir reviendrait à
     * attribuer le marché au mauvais assureur, et le contrat serait faux.
     */
    public function testDeuxPropositionsConcurrentesFontPoserLaQuestion(): void
    {
        $result = $this->makeTool([
            ['id' => 126, 'nom' => 'Incendie — SUNU', 'duree' => 12, 'avenants' => 0],
            ['id' => 114, 'nom' => 'RC Auto — SUNU', 'duree' => 12, 'avenants' => 0],
        ])->execute(['assureur' => 'SUNU'], $this->scope());

        $this->assertFalse($result->data['pret']);
        $this->assertSame('ambigu', $result->data['aDemander'][0]['probleme']);
        $this->assertSame([126 => 'Incendie — SUNU', 114 => 'RC Auto — SUNU'], $result->data['aDemander'][0]['valeurs']);
        $this->assertSame([], $this->operationsVues, 'Rien ne doit être construit sur une proposition incertaine.');
    }

    /**
     * Une proposition DÉJÀ souscrite n'est plus à souscrire : la retenir créerait un
     * second contrat sur le même marché.
     */
    public function testUnePropositionDejaSouscriteEstEcartee(): void
    {
        $result = $this->makeTool([
            ['id' => 126, 'nom' => 'Incendie — SUNU', 'duree' => 12, 'avenants' => 1],
        ])->execute(['assureur' => 'SUNU'], $this->scope());

        $this->assertFalse($result->data['pret']);
        $this->assertSame('deja_souscrite', $result->data['aDemander'][0]['probleme']);
    }

    /** Sans aucune désignation, on demande — on ne prend pas la première venue. */
    public function testSansDesignationOnDemande(): void
    {
        $result = $this->makeTool()->execute([], $this->scope());

        $this->assertFalse($result->data['pret']);
        $this->assertSame('absent', $result->data['aDemander'][0]['probleme']);
        $this->assertSame([], $this->operationsVues);
    }
}
