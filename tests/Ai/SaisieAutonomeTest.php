<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\PreparerOperationsTool;
use App\Entity\AssistantConversation;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Portefeuille;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CE QUE LE SERVEUR DÉDUIT SEUL — l'autonomie des outils de saisie.
 *
 * Tout ce que le serveur sait faire lui-même est une consigne de moins dans le
 * prompt, donc une occasion de moins de la manquer. Deux manques constatés le
 * 2026-08-11, sur une demande aussi banale que « le 11/08/2026, 150 $, entretien
 * du véhicule, fournisseur Loyken Motors » :
 *
 *  1. LA DATE. Le formulaire attend « 2026-08-11 » ; le modèle a fidèlement
 *     transmis « 11/08/2026 » — ce que l'utilisateur avait écrit, dans une
 *     application francophone. Le plan n'a jamais été prêt, aucun bouton n'est
 *     apparu, et Ket a présenté DEUX fois un tableau de plan sans surface de
 *     décision. Le serveur connaissait le champ ET le format attendu.
 *
 *  2. LE NOM INCONNU. « Loyken Motors » n'existait pas : l'outil n'a proposé que
 *     les fournisseurs déjà enregistrés. Or un nom introuvable est le plus souvent
 *     un enregistrement à CRÉER — et Ket sait créer. Il a fallu que l'utilisateur
 *     dicte lui-même la marche à suivre, et quatre messages plus tard la dépense
 *     n'était toujours pas enregistrée.
 *
 * WebTestCase et non KernelTestCase : le dry-run construit le FormType réel, dont
 * les champs autocomplete scopent sur getConnectedTo() de l'utilisateur connecté.
 */
class SaisieAutonomeTest extends WebTestCase
{
    private const ENT = 'PHPUnit-SaisieAutonome';
    private const OWNER = 'phpunit-saisieautonome-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private PreparerOperationsTool $preparer;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
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
        $conn->executeStatement(
            'DELETE m FROM assistant_message m JOIN assistant_conversation c ON m.conversation_id = c.id
             JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n',
            ['n' => self::ENT],
        );
        foreach (['assistant_conversation', 'tache', 'client', 'portefeuille', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** @return array{0: AiScope} */
    private function seed(): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit Saisie')->setVerified(true);
        $owner->setPassword('x');
        $owner->setPaidTokens(1_000_000);
        $this->em->persist($owner);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC-SA')->setAdresse('1 rue de l’Autonomie')
            ->setTelephone('+243000000011')->setRccm('RCCM-SA')->setIdnat('IDNAT-SA')
            ->setNumimpot('IMP-SA')->setUtilisateur($owner);
        $this->em->persist($ent);
        $owner->setConnectedTo($ent);

        $invite = (new Invite())->setNom('Propriétaire SA');
        $invite->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($invite);

        // UN SEUL portefeuille : l'outil y rangera le client sans avoir à le demander.
        $portefeuille = (new Portefeuille())->setNom('Portefeuille SA')->setGestionnaire($invite);
        $portefeuille->setEntreprise($ent);
        $this->em->persist($portefeuille);

        $conversation = (new AssistantConversation())->setEntreprise($ent)->setInvite($invite)->setTitre('Fil SA');
        $this->em->persist($conversation);

        $this->em->flush();
        $this->client->loginUser($owner);

        return [new AiScope($ent, $invite, $conversation)];
    }

    // ─────────────────────────── 1. Les dates dictées ───────────────────────────

    /**
     * LE CAS DE L'INCIDENT. Une date écrite à la française sur un champ HORODATÉ —
     * celui qui exige « Y-m-d\TH:i » et refuse une date nue (le GOTCHA déjà payé sur
     * Tranche.payableAt). Le plan doit être PRÊT, donc porter un bouton.
     */
    public function testUneDateDicteeALaFrancaiseRendLePlanPret(): void
    {
        [$scope] = $this->seed();

        $resultat = $this->preparer->execute(['operations' => [[
            'op'     => 'create',
            'entite' => 'Tache',
            'champs' => ['description' => 'Relancer le client', 'closed' => false, 'toBeEndedAt' => '11/08/2026'],
        ]]], $scope);

        $this->assertTrue(
            $resultat->data['pret'] ?? false,
            'Une date française ne doit plus bloquer un plan : '
            . json_encode($resultat->data['manquants'] ?? $resultat->data, JSON_UNESCAPED_UNICODE),
        );
        $this->assertNotNull($resultat->uiAction, 'Un plan prêt porte toujours sa barre de décision.');
        // La valeur STOCKÉE est celle que le formulaire attend : c'est elle que
        // l'endpoint d'exécution relira, un jour ou l'autre, après un F5.
        $this->assertSame('2026-08-11T00:00', $resultat->uiAction['plan'][0]['fields']['toBeEndedAt']);
    }

    /** Une date déjà correcte n'est pas réécrite : on ne touche pas ce qui marche. */
    public function testUneDateDejaAuBonFormatEstLaisseeIntacte(): void
    {
        [$scope] = $this->seed();

        $resultat = $this->preparer->execute(['operations' => [[
            'op'     => 'create',
            'entite' => 'Tache',
            'champs' => ['description' => 'Relancer le client', 'closed' => false, 'toBeEndedAt' => '2026-08-11T09:30'],
        ]]], $scope);

        $this->assertTrue($resultat->data['pret'] ?? false);
        $this->assertSame('2026-08-11T09:30', $resultat->uiAction['plan'][0]['fields']['toBeEndedAt']);
    }

    /**
     * Ce qui n'est PAS une date reste refusé, en NOMMANT le champ. Une tolérance qui
     * inventerait une date serait bien pire que le refus qu'elle remplace.
     */
    public function testUneDateIncomprehensibleEstRefuseeEnNommantLeChamp(): void
    {
        [$scope] = $this->seed();

        $resultat = $this->preparer->execute(['operations' => [[
            'op'     => 'create',
            'entite' => 'Tache',
            'champs' => ['description' => 'Relancer le client', 'closed' => false, 'toBeEndedAt' => 'la semaine prochaine'],
        ]]], $scope);

        $this->assertFalse($resultat->data['pret'] ?? true);
        $this->assertStringContainsString('toBeEndedAt', implode(' ', $resultat->data['manquants']));
    }

    // ────────────────────── 2. Le nom introuvable = une création ──────────────────────

    /**
     * Un nom que le cabinet ne connaît pas : l'outil doit dire qu'il PEUT le créer,
     * en plus de proposer les candidats existants. C'est la troisième issue qui
     * manquait, et sans laquelle l'utilisateur doit conduire lui-même la manœuvre.
     */
    public function testUnNomIntrouvableAnnonceLaCreationPossible(): void
    {
        [$scope] = $this->seed();

        $resultat = $this->preparer->execute(['operations' => [[
            'op'     => 'create',
            'entite' => 'Piste',
            'champs' => [
                'nom' => 'Piste SA', 'typeAvenant' => 0, 'exercice' => 2026,
                'descriptionDuRisque' => 'Flotte automobile',
                'client' => 'Société Totalement Inconnue',
            ],
        ]]], $scope);

        $this->assertFalse($resultat->data['pret'] ?? true);
        $this->assertNotEmpty($resultat->data['aDemander']);
        $this->assertSame(
            [['entite' => 'Client', 'libelle' => 'Clients', 'terme' => 'Société Totalement Inconnue']],
            $resultat->data['creationsPossibles'],
        );
        // La consigne doit envoyer le modèle vers le PLAN UNIQUE (une validation),
        // et ne mentionner le programme que comme second choix.
        $this->assertStringContainsString('MÊME plan', $resultat->data['note']);
        $this->assertStringContainsString('preparer_programme', $resultat->data['note']);
    }

    /**
     * Et la sortie annoncée FONCTIONNE : les deux opérations tiennent dans UN plan,
     * chaînées par « ref »/« @ref », pour UNE seule validation. C'est ce que
     * l'utilisateur aurait dû obtenir dès son premier message.
     */
    public function testLaCreationManquanteTientDansLeMemePlanEtUneSeuleValidation(): void
    {
        [$scope] = $this->seed();

        $resultat = $this->preparer->execute(['operations' => [
            [
                'op' => 'create', 'entite' => 'Client', 'ref' => 'client',
                'champs' => ['nom' => 'Société Totalement Inconnue'],
            ],
            [
                'op' => 'create', 'entite' => 'Piste',
                'champs' => [
                    'nom' => 'Piste SA', 'typeAvenant' => 0, 'exercice' => 2026,
                    'descriptionDuRisque' => 'Flotte automobile',
                    'client' => '@client',
                ],
            ],
        ]], $scope);

        $this->assertTrue(
            $resultat->data['pret'] ?? false,
            'Le plan chaîné doit être prêt : ' . json_encode($resultat->data, JSON_UNESCAPED_UNICODE),
        );
        $this->assertCount(2, $resultat->uiAction['plan'], 'Deux opérations, une seule validation.');
        $this->assertSame('@client', $resultat->uiAction['plan'][1]['fields']['client']);
        $this->assertSame(2, $resultat->data['budget']['enregistrements']);
    }

    // ───────────────────── 3. L'avertissement après rechargement ─────────────────────

    /**
     * L'avertissement « aucun plan n'a pu être préparé » doit dire la MÊME chose après
     * un F5 qu'en direct — motif compris. Et il doit continuer de s'afficher pour les
     * messages ARCHIVÉS, qui portent un simple booléen : un fil d'archive qui casse à
     * l'affichage serait un défaut bien pire que celui qu'on corrige.
     *
     * @dataProvider formesDeLAvertissement
     */
    public function testLAvertissementSurvitAuRechargementDansSesDeuxFormes(
        mixed $mutationAbsent,
        string $attendu,
    ): void {
        [$scope] = $this->seed();

        $message = (new \App\Entity\AssistantMessage())
            ->setRole(\App\Entity\AssistantMessage::ROLE_ASSISTANT)
            ->setContenu('Voici le plan d’opération préparé.')
            ->setMeta(['engine' => 'gemini', 'mutationAbsent' => $mutationAbsent]);
        $scope->conversation->addMessage($message);
        $this->em->flush();

        $this->client->request('GET', sprintf(
            '/admin/assistant-ia/chat/%d/%d',
            $scope->entreprise->getId(),
            $scope->conversation->getId(),
        ));

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($attendu, $this->client->getResponse()->getContent());
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function formesDeLAvertissement(): iterable
    {
        yield 'avec le motif du serveur' => [
            ['motif' => 'Informations manquantes : #1 Dépenses — dateDepense.'],
            'dateDepense',
        ];
        yield 'sans motif' => [
            ['motif' => null],
            'Aucun plan n\'est réellement en attente de validation',
        ];
        // Forme ARCHIVÉE, d'avant le motif.
        yield 'booléen historique' => [true, 'Aucun plan n\'est réellement en attente de validation'];
    }

    /** Une entité hors périmètre d'écriture n'est jamais proposée à la création. */
    public function testSeulesLesEntitesEcrivablesSontProposeesALaCreation(): void
    {
        [$scope] = $this->seed();

        // Un client EXISTANT mais nommé de façon ambiguë : une ambiguïté se tranche
        // par un choix, jamais par un doublon de plus.
        foreach (['Ambigu SA 1', 'Ambigu SA 2'] as $nom) {
            $client = (new Client())->setNom($nom)->setExonere(false);
            $client->setEntreprise($scope->entreprise);
            $this->em->persist($client);
        }
        $this->em->flush();

        $resultat = $this->preparer->execute(['operations' => [[
            'op'     => 'create',
            'entite' => 'Piste',
            'champs' => [
                'nom' => 'Piste SA', 'typeAvenant' => 0, 'exercice' => 2026,
                'descriptionDuRisque' => 'Flotte automobile',
                'client' => 'Ambigu SA',
            ],
        ]]], $scope);

        $this->assertFalse($resultat->data['pret'] ?? true);
        $this->assertSame([], $resultat->data['creationsPossibles'], 'Une ambiguïté n’appelle pas une création.');
    }
}
