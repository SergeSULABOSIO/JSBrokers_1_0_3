<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Scope\AiScope;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Ai\Tool\ModifierCompositionPrimeTool;
use App\Service\Workspace\WorkspaceMutationService;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Édition RÉELLE des éléments d'une COLLECTION appartenant à une entité (parité
 * formulaire récursive) : Ket crée / modifie / supprime les composantes de la
 * prime (« chargements ») d'une Cotation à travers son parent, exactement comme
 * l'utilisateur le ferait depuis le formulaire. Couvre aussi le chiffrage : le
 * budget d'un plan inclut CHAQUE enfant écrit.
 */
class CollectionMutationTest extends WebTestCase
{
    private const ENT = 'PHPUnit-KetColl';
    private const OWNER = 'phpunit-ketcoll-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private WorkspaceMutationService $service;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(WorkspaceMutationService::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        $conn->executeStatement('DELETE cpp FROM chargement_pour_prime cpp JOIN entreprise e ON cpp.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE co FROM cotation co JOIN entreprise e ON co.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE ch FROM chargement ch JOIN entreprise e ON ch.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** @return array{0:Entreprise,1:Invite,2:Utilisateur} */
    private function seedWorkspace(): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($ent);

        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);

        $owner->setConnectedTo($ent); // le FormType autocomplete scope sur l'entreprise active.

        return [$ent, $inv, $owner];
    }

    private function seedChargementType(Entreprise $ent, Invite $inv, string $nom): Chargement
    {
        $ch = (new Chargement())->setNom($nom);
        $ch->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($ch);

        return $ch;
    }

    private function seedCotation(Entreprise $ent, Invite $inv, string $nom): Cotation
    {
        $c = (new Cotation())->setNom($nom)->setDuree(12);
        $c->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($c);

        return $c;
    }

    private function seedChargement(Cotation $cot, Entreprise $ent, Invite $inv, string $nom, float $montant): ChargementPourPrime
    {
        $cpp = (new ChargementPourPrime())->setNom($nom)->setMontantFlatExceptionel($montant);
        $cpp->setEntreprise($ent)->setInvite($inv);
        $cot->addChargement($cpp); // pose les DEUX côtés de la relation (comme l'UI).
        $this->em->persist($cpp);

        return $cpp;
    }

    public function testAjoutDeChargementsViaCollection(): void
    {
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $type = $this->seedChargementType($ent, $inv, 'Prime nette');
        $cot = $this->seedCotation($ent, $inv, 'Offre Flotte RC Auto');
        $this->em->flush();
        [$cotId, $typeId] = [$cot->getId(), $type->getId()];
        $this->client->loginUser($owner);

        // Édition « conteneur » : la tête ne change pas, seules ses collections évoluent.
        $op = new MutationOperation('edit', 'Cotation', $cotId, [], [
            'chargements' => [
                new MutationOperation('create', '', null, ['nom' => 'Prime nette', 'montantFlatExceptionel' => 9000, 'type' => $typeId]),
                new MutationOperation('create', '', null, ['nom' => 'Frais accessoires', 'montantFlatExceptionel' => 500, 'type' => $typeId]),
            ],
        ]);
        $step = $this->service->executer($op, new AiScope($ent, $inv), $owner);

        $this->assertCount(2, $step['enfants'], 'Deux sous-opérations enfant ont été exécutées.');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Cotation::class)->find($cotId);
        $chargements = $reloaded->getChargements();
        $this->assertCount(2, $chargements, 'Les deux chargements sont réellement rattachés à la cotation.');
        $montants = array_map(static fn (ChargementPourPrime $c) => $c->getMontantFlatExceptionel(), $chargements->toArray());
        sort($montants);
        $this->assertSame([500.0, 9000.0], $montants);
        foreach ($chargements as $c) {
            $this->assertSame($cotId, $c->getCotation()->getId(), 'La relation inverse (cotation) est posée.');
        }
    }

    public function testEditionEtSuppressionDeChargements(): void
    {
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $cot = $this->seedCotation($ent, $inv, 'Offre à corriger');
        $garde = $this->seedChargement($cot, $ent, $inv, 'Prime nette', 1000);
        $aSupprimer = $this->seedChargement($cot, $ent, $inv, 'Erreur', 42);
        $this->em->flush();
        [$cotId, $gardeId, $supprId] = [$cot->getId(), $garde->getId(), $aSupprimer->getId()];
        $this->client->loginUser($owner);

        $op = new MutationOperation('edit', 'Cotation', $cotId, [], [
            'chargements' => [
                new MutationOperation('edit', '', $gardeId, ['montantFlatExceptionel' => 9000]),
                new MutationOperation('delete', '', $supprId),
            ],
        ]);
        $this->service->executer($op, new AiScope($ent, $inv), $owner);

        $this->em->clear();
        $garde = $this->em->getRepository(ChargementPourPrime::class)->find($gardeId);
        $this->assertNotNull($garde);
        $this->assertSame(9000.0, $garde->getMontantFlatExceptionel(), 'Le montant a été réellement modifié.');
        $this->assertNull($this->em->getRepository(ChargementPourPrime::class)->find($supprId), 'L’élément a été supprimé (orphanRemoval).');
    }

    public function testBudgetDuPlanInclutChaqueEnfantEcrit(): void
    {
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $type = $this->seedChargementType($ent, $inv, 'Prime nette');
        $cot = $this->seedCotation($ent, $inv, 'Offre budget');
        $this->em->flush();
        [$cotId, $typeId] = [$cot->getId(), $type->getId()];
        $this->client->loginUser($owner);

        $op = new MutationOperation('edit', 'Cotation', $cotId, [], [
            'chargements' => [
                new MutationOperation('create', '', null, ['nom' => 'Prime nette', 'montantFlatExceptionel' => 9000, 'type' => $typeId]),
                new MutationOperation('create', '', null, ['nom' => 'TVA', 'montantFlatExceptionel' => 1600, 'type' => $typeId]),
            ],
        ]);
        $res = $this->service->analyserOperation($op, new AiScope($ent, $inv));
        $this->assertTrue($res['ok'], 'Le plan est prêt.');

        // Source UNIQUE du chiffrage (partagée budget ⇔ exécution) : tête = édition
        // conteneur (aucun champ propre) => NON facturée ; 2 enfants créés => facturés.
        $facturables = $this->service->facturablesArbre($op, new AiScope($ent, $inv));
        $this->assertSame(
            [ChargementPourPrime::class, ChargementPourPrime::class],
            $facturables,
            'Le budget inclut chaque composante créée, et pas la tête (édition conteneur).',
        );
    }

    public function testChiffrageTeteCreeePlusEnfants(): void
    {
        // Création de la Cotation ELLE-MÊME + 2 chargements : le budget doit inclure
        // la tête (create réel) ET chaque enfant, à travers l'imbrication.
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $type = $this->seedChargementType($ent, $inv, 'Prime nette');
        $this->em->flush();
        $typeId = $type->getId();
        $this->client->loginUser($owner);

        $op = new MutationOperation('create', 'Cotation', null, ['nom' => 'Nouvelle offre', 'duree' => 12], [
            'chargements' => [
                new MutationOperation('create', '', null, ['nom' => 'Prime nette', 'montantFlatExceptionel' => 9000, 'type' => $typeId]),
                new MutationOperation('create', '', null, ['nom' => 'ARCA', 'montantFlatExceptionel' => 200, 'type' => $typeId]),
            ],
        ]);

        $facturables = $this->service->facturablesArbre($op, new AiScope($ent, $inv));
        $this->assertSame(
            [Cotation::class, ChargementPourPrime::class, ChargementPourPrime::class],
            $facturables,
            'Le budget = tête créée + chaque enfant écrit, sur toute la profondeur.',
        );
    }

    public function testBudgetEgaleTokensReellementConsommes(): void
    {
        // Preuve END-TO-END : les tokens débités par l'exécution == le budget annoncé
        // (tête créée + chaque enfant, sur toute l'imbrication). Même barème des deux
        // côtés (estimateWriteCost ⇔ meterWrite), source unique facturablesArbre.
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $type = $this->seedChargementType($ent, $inv, 'Prime nette');
        $this->em->flush();
        $typeId = $type->getId();
        $this->client->loginUser($owner);

        $tokens = static::getContainer()->get(TokenAccountService::class);
        $tokens->credit($owner, 100000); // solvabilité garantie, débit pris sur le prépayé.
        $scope = new AiScope($ent, $inv);

        $op = new MutationOperation('create', 'Cotation', null, ['nom' => 'Offre conso', 'duree' => 12], [
            'chargements' => [
                new MutationOperation('create', '', null, ['nom' => 'Prime nette', 'montantFlatExceptionel' => 9000, 'type' => $typeId]),
                new MutationOperation('create', '', null, ['nom' => 'TVA', 'montantFlatExceptionel' => 1600, 'type' => $typeId]),
            ],
        ]);

        $coutEstime = $tokens->estimateWriteCost($this->service->facturablesArbre($op, $scope));
        $this->assertGreaterThan(0, $coutEstime);

        $avant = $tokens->availableFor($ent);
        $this->service->executer($op, $scope, $owner);
        $apres = $tokens->availableFor($ent);

        $this->assertSame(
            $coutEstime,
            $avant - $apres,
            'Les tokens réellement consommés == le budget annoncé (tête + toutes imbrications).',
        );
    }

    public function testOutilDedieCompositionPrimePrepareUnPlanFacture(): void
    {
        // L'outil typé modifier_composition_prime traduit {nom, montant} en une
        // édition de Cotation + créations sur « chargements », délègue au moteur
        // unique et renvoie un plan validable au budget NON nul (une écriture par
        // composante). Prouve la voie fiable pour un modèle faible en JSON imbriqué.
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $cot = $this->seedCotation($ent, $inv, 'Offre Flotte RC Auto - SFA');
        $this->em->flush();
        $cotId = $cot->getId();
        $this->client->loginUser($owner);

        $tool = static::getContainer()->get(ModifierCompositionPrimeTool::class);
        $result = $tool->execute([
            'cotationId'  => $cotId,
            'composantes' => [
                ['nom' => 'Prime nette', 'montant' => 9000],
                ['nom' => 'Frais accessoires', 'montant' => 500],
                ['nom' => 'TVA', 'montant' => 1600],
                ['nom' => 'Frais ARCA', 'montant' => 200],
            ],
        ], new AiScope($ent, $inv));

        $this->assertTrue($result->data['pret'] ?? false, 'Le plan doit être prêt.');
        $this->assertGreaterThan(0, $result->data['budget']['coutEstime'], 'Le budget doit inclure les 4 composantes créées (jamais 0).');
        $this->assertSame('ket-mutation.review', $result->uiAction['type']);

        $chargements = $result->uiAction['plan'][0]['collections']['chargements'] ?? [];
        $this->assertCount(4, $chargements, 'Les 4 composantes sont des sous-opérations sur « chargements ».');
        $this->assertSame('create', $chargements[0]['op']);
    }

    public function testCompositionIdentiqueNeRepresentePasDePlan(): void
    {
        // Idempotence : une composition déjà à jour (mêmes montants) ne doit PAS
        // reproduire un plan ni une barre « Valider et exécuter » — juste une
        // confirmation. C'est ce qui évite la barre parasite après exécution.
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $cot = $this->seedCotation($ent, $inv, 'Offre déjà à jour');
        $this->seedChargement($cot, $ent, $inv, 'Prime nette', 9000);
        $this->seedChargement($cot, $ent, $inv, 'TVA', 1600);
        $this->em->flush();
        $cotId = $cot->getId();
        $this->client->loginUser($owner);

        $tool = static::getContainer()->get(ModifierCompositionPrimeTool::class);
        $result = $tool->execute([
            'cotationId'  => $cotId,
            'composantes' => [
                ['nom' => 'Prime nette', 'montant' => 9000],
                ['nom' => 'TVA', 'montant' => 1600],
            ],
        ], new AiScope($ent, $inv));

        $this->assertFalse($result->data['pret'] ?? true, 'Aucun plan à présenter.');
        $this->assertTrue($result->data['dejaAJour'] ?? false, 'La composition est signalée déjà à jour.');
        $this->assertNull($result->uiAction, 'Aucune barre de validation (uiAction) ne doit être émise.');
        $this->assertSame(10600.0, $result->data['primeTotale']);
    }

    public function testModificationDUnMontantRepresenteUnPlan(): void
    {
        // À l'inverse : changer un montant DOIT reproduire un plan (edit) facturé.
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $cot = $this->seedCotation($ent, $inv, 'Offre à corriger');
        $this->seedChargement($cot, $ent, $inv, 'Prime nette', 9000);
        $this->em->flush();
        $cotId = $cot->getId();
        $this->client->loginUser($owner);

        $tool = static::getContainer()->get(ModifierCompositionPrimeTool::class);
        $result = $tool->execute([
            'cotationId'  => $cotId,
            'composantes' => [['nom' => 'Prime nette', 'montant' => 12000]],
        ], new AiScope($ent, $inv));

        $this->assertTrue($result->data['pret'] ?? false, 'Un vrai changement doit produire un plan.');
        $this->assertGreaterThan(0, $result->data['budget']['coutEstime']);
        $chargements = $result->uiAction['plan'][0]['collections']['chargements'] ?? [];
        $this->assertCount(1, $chargements);
        $this->assertSame('edit', $chargements[0]['op'], 'Composante existante => édition (pas de doublon).');
    }

    public function testCollectionInconnueRefusee(): void
    {
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $cot = $this->seedCotation($ent, $inv, 'Offre X');
        $this->em->flush();
        $this->client->loginUser($owner);

        // « inconnue » n'est pas une collection déclarée par le formulaire de Cotation.
        $op = new MutationOperation('edit', 'Cotation', $cot->getId(), [], [
            'inconnue' => [new MutationOperation('create', '', null, ['x' => 1])],
        ]);
        $res = $this->service->analyserOperation($op, new AiScope($ent, $inv));

        $this->assertFalse($res['ok'], 'Une collection hors formulaire est rejetée (fail-closed).');
        $this->assertArrayHasKey('inconnue', $res['manquants']);
    }
}
