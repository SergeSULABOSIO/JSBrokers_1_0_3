<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\PlanEnAttente;
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
        foreach (['assistant_conversation', 'tache', 'client', 'assureur', 'portefeuille', 'invite'] as $table) {
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

    /**
     * LE DOSSIER ENTIER EN UN SEUL PLAN — la capacité que l'incident du 2026-08-12 a
     * révélée manquante.
     *
     * Un courtier joint un contrat d'assurance et demande, à puces : le client, le
     * risque, la piste, la proposition, l'avenant, le document, le paiement de prime.
     * Ket avait choisi une SÉRIE de six plans (six validations), puis avait échoué à
     * en assembler la première étape. Or tout cela tient dans UN plan : les pièces se
     * tiennent par des relations, et « ref »/« @ref » les chaîne — y compris depuis
     * une COLLECTION (la tranche de l'échéancier) vers une opération racine (le
     * signalement de paiement).
     *
     * C'est ce dernier point qui est le moins évident et le plus utile : sans lui, le
     * paiement de la prime ne pourrait jamais entrer dans le même plan que la
     * proposition qui crée sa tranche.
     */
    public function testLeDossierDunContratTientDansUnSeulPlan(): void
    {
        [$scope] = $this->seed();

        $assureur = (new \App\Entity\Assureur())->setNom('SUNU Assurances IARD RDC')
            ->setEmail('sunu-phpunit@test.local')->setTelephone('+243000000012')
            ->setNumimpot('IMP-SUNU')->setRccm('RCCM-SUNU')->setIdnat('IDNAT-SUNU')
            ->setAdressePhysique('Gombe, Kinshasa');
        $assureur->setEntreprise($scope->entreprise);
        $this->em->persist($assureur);
        $this->em->flush();

        $resultat = $this->preparer->execute(['operations' => [
            [
                'op' => 'create', 'entite' => 'Client', 'ref' => 'client',
                'etape' => 'Le client',
                'champs' => ['nom' => 'MBUSA KAYITHULA JEAN DE DIEU'],
            ],
            [
                'op' => 'create', 'entite' => 'Risque', 'ref' => 'risque',
                'etape' => 'Le risque',
                'champs' => [
                    'nomComplet' => 'Assurance Voyage', 'code' => 'AVOY',
                    'branche' => 0, 'imposable' => true,
                ],
            ],
            [
                'op' => 'create', 'entite' => 'Piste', 'ref' => 'piste',
                'etape' => 'L’opportunité',
                'champs' => [
                    'nom' => 'Voyage SUISSE — MBUSA', 'typeAvenant' => 0, 'exercice' => 2026,
                    'descriptionDuRisque' => 'Assurance voyage espace Schengen',
                    'client' => '@client', 'risque' => '@risque',
                ],
            ],
            [
                'op' => 'create', 'entite' => 'Cotation', 'ref' => 'cotation',
                'etape' => 'La proposition',
                'champs' => [
                    'nom' => 'Proposition SUNU — Voyage SUISSE', 'duree' => 1,
                    'piste' => '@piste', 'assureur' => 'SUNU Assurances IARD RDC',
                ],
                'collections' => [[
                    'collection' => 'tranches',
                    'elements' => [[
                        'op' => 'create', 'ref' => 'tranche1', 'etape' => 'L’échéancier',
                        'champs' => ['nom' => 'Prime unique', 'pourcentage' => 100, 'payableAt' => '14/09/2026'],
                    ]],
                ]],
            ],
            [
                'op' => 'create', 'entite' => 'PaiementPrime',
                'etape' => 'Le paiement de la prime',
                'champs' => [
                    'montant' => 95, 'tranche' => '@tranche1',
                    'paidAt' => '10/08/2026', 'reference' => 'SURDCVO00018389',
                ],
            ],
        ]], $scope);

        $this->assertTrue(
            $resultat->data['pret'] ?? false,
            'Le dossier entier doit tenir dans un seul plan : ' . json_encode($resultat->data, JSON_UNESCAPED_UNICODE),
        );
        $this->assertCount(5, $resultat->uiAction['plan'], 'Cinq opérations racines, UNE seule validation.');
        $this->assertSame(
            ['Client', 'Risque', 'Piste', 'Cotation', 'PaiementPrime'],
            array_column($resultat->uiAction['plan'], 'entite'),
            'L’ordre métier dicté doit être conservé : une création précède toujours qui la référence.',
        );
        // LE CŒUR : le paiement renvoie à une tranche créée DANS une collection.
        $this->assertSame('@tranche1', $resultat->uiAction['plan'][4]['fields']['tranche']);
        // La tranche est bien facturée avec le reste : le budget couvre tout d'un tenant.
        $this->assertSame(6, $resultat->data['budget']['enregistrements'], '5 racines + la tranche.');
        $this->assertSame(PlanEnAttente::ACTION_REVUE, $resultat->uiAction['type']);
    }

    /**
     * LE DRY-RUN DOIT RENDRE LE MÊME VERDICT QUE L'EXÉCUTION.
     *
     * L'exécution ordonne les opérations — créations d'abord, puis éditions, puis
     * suppressions (MutationPlan::operationsOrdonnees) —, tandis que le dry-run
     * parcourait les opérations dans l'ordre DÉCLARÉ. Les deux ordres coïncident
     * pour un plan de créations pures, mais divergent dès qu'une ÉDITION renvoie à
     * une création déclarée plus bas : le dry-run refusait « référence inconnue »
     * un plan que l'exécution, elle, aurait parfaitement réussi.
     *
     * Ici, l'utilisateur range un client existant dans un portefeuille qu'il crée
     * dans le même souffle, et il l'a dit dans cet ordre-là. Le plan doit être
     * PRÊT — et le numéro affiché doit rester celui de l'ordre DÉCLARÉ, puisque
     * c'est le plan que l'utilisateur relit.
     */
    public function testUnRenvoiVersUneCreationDeclareePlusBasResteValide(): void
    {
        [$scope] = $this->seed();

        $client = (new Client())->setNom('Client à ranger');
        $client->setEntreprise($scope->entreprise);
        $this->em->persist($client);
        $this->em->flush();
        $idClient = (int) $client->getId();

        $resultat = $this->preparer->execute(['operations' => [
            // (1) L'ÉDITION vient EN PREMIER, et renvoie à une création qui suit.
            [
                'op' => 'edit', 'entite' => 'Client', 'id' => $idClient,
                'champs' => ['portefeuille' => '@pf'],
            ],
            // (2) La CRÉATION est déclarée APRÈS — mais sera exécutée AVANT.
            [
                'op' => 'create', 'entite' => 'Portefeuille', 'ref' => 'pf',
                'champs' => ['nom' => 'Portefeuille Voyages', 'gestionnaire' => 'Propriétaire SA'],
            ],
        ]], $scope);

        $this->assertTrue(
            $resultat->data['pret'] ?? false,
            'Le dry-run doit accepter ce que l’exécution réussirait : '
            . json_encode($resultat->data, JSON_UNESCAPED_UNICODE),
        );
        // La PRÉSENTATION garde l'ordre dicté : l'utilisateur relit son propre plan.
        $this->assertSame(
            ['edit', 'create'],
            array_column($resultat->uiAction['plan'], 'op'),
            'Le plan présenté doit rester dans l’ordre DÉCLARÉ.',
        );
        $this->assertSame([1, 2], array_column($resultat->data['plan'], 'n'));
        $this->assertSame('@pf', $resultat->uiAction['plan'][0]['fields']['portefeuille']);
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
