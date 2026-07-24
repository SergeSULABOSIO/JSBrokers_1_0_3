<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\MutationReferences;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\PreparerOperationsTool;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceMutationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PLAN MULTI-ENTITÉS à validation UNIQUE : Ket enregistre, en un seul plan validé
 * une seule fois, l'entité de base ET les entités qui en dépendent — qu'elles
 * soient des éléments de ses collections (composition de la prime) ou des
 * enregistrements distincts chaînés par RÉFÉRENCE (« @étiquette »), dont l'id
 * n'existe pas encore au moment où l'utilisateur valide.
 *
 * Couvre aussi l'ÉTENDUE choisie par l'utilisateur : les étapes du plan sont
 * inventoriées, et décocher une étape facultative élague le plan côté SERVEUR
 * (ainsi que tout ce qui en dépendait).
 */
class PlanMultiEntitesTest extends WebTestCase
{
    private const ENT = 'PHPUnit-KetPlan';
    private const OWNER = 'phpunit-ketplan-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private WorkspaceMutationService $service;
    private PreparerOperationsTool $preparer;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(WorkspaceMutationService::class);
        $this->preparer = static::getContainer()->get(PreparerOperationsTool::class);
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
        $conn->executeStatement('DELETE pp FROM piste_partenaire pp JOIN piste p ON pp.piste_id = p.id JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE cpp FROM chargement_pour_prime cpp JOIN entreprise e ON cpp.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE co FROM cotation co JOIN entreprise e ON co.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE p FROM piste p JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE c FROM client c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE pa FROM partenaire pa JOIN entreprise e ON pa.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
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

    // ───────────────────────── Chaînage par référence ─────────────────────────

    /**
     * Le cas fondateur : « crée le client, puis SA piste, puis la cotation de cette
     * piste avec la composition de sa prime » — trois entités dépendantes dont deux
     * n'ont pas encore d'id, plus une collection imbriquée : UN plan, UNE validation.
     */
    public function testPlanEnchaineTroisEntitesDependantesEnUneValidation(): void
    {
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $type = $this->seedChargementType($ent, $inv, 'Prime nette');
        $this->em->flush();
        $typeId = $type->getId();
        $this->client->loginUser($owner);
        $scope = new AiScope($ent, $inv);

        $plan = MutationPlan::fromArray([
            [
                'op' => 'create', 'entite' => 'Client', 'ref' => 'client', 'etape' => 'Le client',
                'champs' => ['nom' => 'ACME Congo', 'exonere' => false],
            ],
            [
                'op' => 'create', 'entite' => 'Piste', 'ref' => 'piste', 'etape' => 'L’opportunité',
                'champs' => ['nom' => 'RC Auto 2026', 'client' => '@client', 'typeAvenant' => 1, 'exercice' => 2026, 'descriptionDuRisque' => 'Flotte automobile'],
            ],
            [
                'op' => 'create', 'entite' => 'Cotation', 'etape' => 'La proposition',
                'champs' => ['nom' => 'Offre Flotte', 'duree' => 12, 'piste' => '@piste'],
                'collections' => [[
                    'collection' => 'chargements',
                    'elements' => [
                        ['op' => 'create', 'etape' => 'La composition de la prime', 'champs' => ['nom' => 'Prime nette', 'montantFlatExceptionel' => 9000, 'type' => $typeId]],
                        ['op' => 'create', 'etape' => 'La composition de la prime', 'champs' => ['nom' => 'TVA', 'montantFlatExceptionel' => 1600, 'type' => $typeId]],
                    ],
                ]],
            ],
        ]);

        // 1) DRY-RUN : le plan entier est prêt — aucun « manquant », alors que
        // « client » et « piste » ne sont fournis que par un renvoi.
        $refs = MutationReferences::dryRun();
        foreach ($plan->operations as $op) {
            $analyse = $this->service->analyserOperation($op, $scope, $refs);
            $this->assertTrue(
                $analyse['ok'],
                sprintf('L’opération sur %s doit être prête (manquants : %s).', $op->entityShortName, json_encode($analyse['manquants'])),
            );
        }

        // 2) EXÉCUTION : un seul registre de références pour tout le plan.
        $live = MutationReferences::live();
        foreach ($plan->operationsOrdonnees() as $op) {
            $this->service->executer($op, $scope, $owner, $live);
        }

        // 3) Les trois entités existent ET sont réellement chaînées entre elles.
        $this->em->clear();
        $client = $this->em->getRepository(Client::class)->findOneBy(['nom' => 'ACME Congo']);
        $piste = $this->em->getRepository(Piste::class)->findOneBy(['nom' => 'RC Auto 2026']);
        $cotation = $this->em->getRepository(Cotation::class)->findOneBy(['nom' => 'Offre Flotte']);

        $this->assertNotNull($client, 'Le client a été créé.');
        $this->assertNotNull($piste, 'La piste a été créée.');
        $this->assertNotNull($cotation, 'La cotation a été créée.');
        $this->assertSame($client->getId(), $piste->getClient()?->getId(), 'La piste pointe le client créé DANS LE MÊME plan.');
        $this->assertSame($piste->getId(), $cotation->getPiste()?->getId(), 'La cotation pointe la piste créée DANS LE MÊME plan.');
        $this->assertCount(2, $cotation->getChargements(), 'La composition de la prime est enregistrée avec la cotation.');
    }

    /** Fail-closed : un renvoi vers une étiquette jamais déclarée n'est jamais deviné. */
    public function testRenvoiVersUneEtiquetteInconnueEstSignaleCommeManquant(): void
    {
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $this->em->flush();
        $this->client->loginUser($owner);

        $op = MutationOperation::fromArray([
            'op' => 'create', 'entite' => 'Piste',
            'champs' => ['nom' => 'Orpheline', 'client' => '@inexistant', 'typeAvenant' => 1, 'exercice' => 2026, 'descriptionDuRisque' => 'x'],
        ]);
        $analyse = $this->service->analyserOperation($op, new AiScope($ent, $inv), MutationReferences::dryRun());

        $this->assertFalse($analyse['ok']);
        $this->assertArrayHasKey('client', $analyse['manquants'], 'Le renvoi non résolu est signalé sur son champ.');
    }

    /** Un renvoi « en avant » (vers une création postérieure) n'est pas résolu non plus. */
    public function testRenvoiEnAvantNestPasResolu(): void
    {
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $this->em->flush();
        $this->client->loginUser($owner);
        $scope = new AiScope($ent, $inv);

        $plan = MutationPlan::fromArray([
            ['op' => 'create', 'entite' => 'Piste', 'champs' => ['nom' => 'Avant', 'client' => '@client', 'typeAvenant' => 1, 'exercice' => 2026, 'descriptionDuRisque' => 'x']],
            ['op' => 'create', 'entite' => 'Client', 'ref' => 'client', 'champs' => ['nom' => 'Tardif', 'exonere' => false]],
        ]);

        $refs = MutationReferences::dryRun();
        $premiere = $this->service->analyserOperation($plan->operations[0], $scope, $refs);
        $this->assertFalse($premiere['ok'], 'Une référence déclarée plus loin dans le plan ne se résout pas.');
        $this->assertArrayHasKey('client', $premiere['manquants']);
    }

    // ───────────────────────── Relation multiple ─────────────────────────

    /** Une relation MULTIPLE (partenaires d'une piste) se donne par liste d'identifiants. */
    public function testRelationMultipleEcriteParListeDIdentifiants(): void
    {
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $p1 = (new Partenaire())->setNom('Courtier Sud')->setPart(0.5);
        $p2 = (new Partenaire())->setNom('Courtier Nord')->setPart(0.5);
        foreach ([$p1, $p2] as $p) {
            $p->setEntreprise($ent)->setInvite($inv);
            $this->em->persist($p);
        }
        $this->em->flush();
        [$id1, $id2] = [$p1->getId(), $p2->getId()];
        $this->client->loginUser($owner);

        $op = MutationOperation::fromArray([
            'op' => 'create', 'entite' => 'Piste',
            'champs' => ['nom' => 'Coassurance 2026', 'typeAvenant' => 1, 'exercice' => 2026, 'descriptionDuRisque' => 'x', 'partenaires' => [$id1, $id2]],
        ]);
        $this->assertSame([$id1, $id2], $op->fields['partenaires'], 'La liste d’identifiants survit au décodage.');

        $this->service->executer($op, new AiScope($ent, $inv), $owner);

        $this->em->clear();
        $piste = $this->em->getRepository(Piste::class)->findOneBy(['nom' => 'Coassurance 2026']);
        $this->assertNotNull($piste);
        $this->assertCount(2, $piste->getPartenaires(), 'Les deux partenaires sont rattachés à la piste.');
    }

    // ───────────────────────── Étendue choisie ─────────────────────────

    /** L'inventaire des étapes : l'étape socle est requise, les autres décochables. */
    public function testInventaireDesEtapesDistingueLeSocleDesEtapesFacultatives(): void
    {
        $plan = MutationPlan::fromArray([
            [
                'op' => 'create', 'entite' => 'Cotation', 'etape' => 'La proposition', 'champs' => ['nom' => 'X'],
                'collections' => [[
                    'collection' => 'chargements',
                    'elements' => [
                        ['op' => 'create', 'etape' => 'La composition de la prime', 'champs' => ['nom' => 'Prime nette']],
                        ['op' => 'create', 'etape' => 'La composition de la prime', 'champs' => ['nom' => 'TVA']],
                    ],
                ]],
            ],
        ]);

        $etapes = $plan->etapes();
        $this->assertSame(['la-proposition', 'la-composition-de-la-prime'], array_column($etapes, 'cle'));
        $this->assertTrue($etapes[0]['obligatoire'], 'L’étape socle ne se décoche pas.');
        $this->assertFalse($etapes[1]['obligatoire'], 'Une étape rattachée est facultative.');
        $this->assertSame(2, $etapes[1]['noeuds'], 'Elle compte ses deux enregistrements.');
    }

    /** Décocher une étape facultative l'élague — le socle, lui, est préservé. */
    public function testFiltrageParEtapesElagueLesEtapesDecochees(): void
    {
        $plan = MutationPlan::fromArray([
            [
                'op' => 'create', 'entite' => 'Cotation', 'etape' => 'La proposition', 'champs' => ['nom' => 'X'],
                'collections' => [[
                    'collection' => 'chargements',
                    'elements' => [['op' => 'create', 'etape' => 'La composition de la prime', 'champs' => ['nom' => 'Prime nette']]],
                ]],
            ],
        ]);

        $filtre = $plan->filtrerEtapes(['la-proposition']);
        $this->assertCount(1, $filtre->operations);
        $this->assertSame([], $filtre->operations[0]->collections, 'La composition de la prime a été abandonnée.');

        // Sans sélection transmise : plan INTÉGRAL (comportement historique).
        $this->assertSame(
            $plan->toArray(),
            $plan->filtrerEtapes([])->toArray(),
            'Aucune sélection = plan complet, inchangé.',
        );
    }

    /** Décocher une étape emporte les opérations qui DÉPENDAIENT d'elle (jamais d'orpheline). */
    public function testFiltrageParEtapesEmporteLesOperationsDependantes(): void
    {
        $plan = MutationPlan::fromArray([
            ['op' => 'create', 'entite' => 'Client', 'ref' => 'client', 'etape' => 'Le client', 'champs' => ['nom' => 'A']],
            ['op' => 'create', 'entite' => 'Piste', 'ref' => 'piste', 'etape' => 'L’opportunité', 'champs' => ['nom' => 'B', 'client' => '@client']],
            ['op' => 'create', 'entite' => 'Cotation', 'etape' => 'La proposition', 'champs' => ['nom' => 'C', 'piste' => '@piste']],
        ]);

        $filtre = $plan->filtrerEtapes(['le-client']);
        $this->assertCount(1, $filtre->operations, 'La cotation tombe avec la piste dont elle dépendait.');
        $this->assertSame('Client', $filtre->operations[0]->entityShortName);
    }

    // ───────────────────────── Budget d'un seul tenant ─────────────────────────

    /**
     * Le plan présenté chiffre TOUT ce que l'utilisateur validera — d'un seul
     * tenant — et le ventile par étape (« ce que coûte ce que vous avez accepté »).
     */
    public function testBudgetVentileParEtapeEtCouvreToutLePlan(): void
    {
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $type = $this->seedChargementType($ent, $inv, 'Prime nette');
        $this->em->flush();
        $typeId = $type->getId();
        $this->client->loginUser($owner);

        $result = $this->preparer->execute(['operations' => [
            [
                'op' => 'create', 'entite' => 'Client', 'ref' => 'client', 'etape' => 'Le client',
                'champs' => ['nom' => 'ACME Budget', 'exonere' => false],
            ],
            [
                'op' => 'create', 'entite' => 'Cotation', 'etape' => 'La proposition',
                'champs' => ['nom' => 'Offre budget', 'duree' => 12],
                'collections' => [[
                    'collection' => 'chargements',
                    'elements' => [
                        ['op' => 'create', 'etape' => 'La composition de la prime', 'champs' => ['nom' => 'Prime nette', 'montantFlatExceptionel' => 9000, 'type' => $typeId]],
                        ['op' => 'create', 'etape' => 'La composition de la prime', 'champs' => ['nom' => 'TVA', 'montantFlatExceptionel' => 1600, 'type' => $typeId]],
                    ],
                ]],
            ],
        ]], new AiScope($ent, $inv));

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertTrue($result->data['pret'], 'Le plan multi-entités est prêt en une seule préparation.');

        $budget = $result->data['budget'];
        $this->assertSame(4, $budget['enregistrements'], 'Les 4 enregistrements écrits sont comptés (client, cotation, 2 composantes).');

        $parEtape = array_column($budget['parEtape'], null, 'cle');
        $this->assertSame(
            ['le-client', 'la-proposition', 'la-composition-de-la-prime'],
            array_keys($parEtape),
            'Le budget est ventilé étape par étape, dans l’ordre du parcours.',
        );
        $this->assertSame(2, $parEtape['la-composition-de-la-prime']['enregistrements']);
        $this->assertSame(
            $budget['coutEstime'],
            array_sum(array_column($budget['parEtape'], 'cout')),
            'La somme des étapes est EXACTEMENT le coût total présenté.',
        );

        // Une seule étape est verrouillée : le socle. Les autres sont décochables.
        $this->assertTrue($parEtape['le-client']['obligatoire']);
        $this->assertFalse($parEtape['la-composition-de-la-prime']['obligatoire']);
    }

    /**
     * Anti-« second plan » : une création qui laisse de côté des collections que son
     * formulaire sait alimenter fait remonter un avertissement — Ket doit poser la
     * question MAINTENANT plutôt que d'imposer une seconde validation plus tard.
     */
    public function testPlanSignaleLesCollectionsNonCouvertesDUneCreation(): void
    {
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $this->em->flush();
        $this->client->loginUser($owner);

        $result = $this->preparer->execute(['operations' => [
            ['op' => 'create', 'entite' => 'Cotation', 'champs' => ['nom' => 'Offre nue', 'duree' => 12]],
        ]], new AiScope($ent, $inv));

        $this->assertTrue($result->data['pret']);
        $this->assertNotSame([], $result->data['avertissements'], 'Les collections non couvertes sont signalées.');
        $this->assertStringContainsString('Cotation', $result->data['avertissements'][0]);
    }

    /** Un plan sans étape reste chiffré et exécutable exactement comme avant. */
    public function testPlanSansEtapeResteInchange(): void
    {
        [$ent, $inv, $owner] = $this->seedWorkspace();
        $this->em->flush();
        $this->client->loginUser($owner);

        $result = $this->preparer->execute(['operations' => [
            ['op' => 'create', 'entite' => 'Client', 'champs' => ['nom' => 'Sans étape', 'exonere' => false]],
        ]], new AiScope($ent, $inv));

        $this->assertTrue($result->data['pret']);
        $this->assertSame([], $result->data['etapes'], 'Aucune étape déclarée : aucun sélecteur d’étendue.');
        $this->assertSame(1, $result->data['budget']['enregistrements']);
    }
}
