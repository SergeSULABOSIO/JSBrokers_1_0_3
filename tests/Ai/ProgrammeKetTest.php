<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Programme\ProgrammeEnCours;
use App\Ai\Programme\ProgrammeRunner;
use App\Ai\Programme\ProgrammeVerificateur;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\PreparerProgrammeTool;
use App\Entity\AssistantConversation;
use App\Entity\AssistantProgramme;
use App\Entity\AssistantProgrammeEtape;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\PaiementPrime;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PROGRAMME — une mission en PLUSIEURS plans, validés l'un après l'autre.
 *
 * Régression fondatrice (capture d'écran de production) : l'utilisateur demande le
 * signalement du paiement de TROIS tranches. Ket prépare un plan, l'exécute… et
 * s'arrête. Les deux autres ne sont jamais présentées, et à la question « c'est
 * tout ? » elle répond que les trois sont enregistrés. Deux défauts distincts :
 *
 *  1. la boucle est ROMPUE après l'exécution (endpoint hors-LLM, aucun message
 *     créé) — d'où l'enchaînement DÉTERMINISTE testé ici ;
 *  2. rien ne vérifiait la CONSÉQUENCE des écritures — d'où le rapport final,
 *     relu en base, qui doit signaler une prime restée non soldée.
 *
 * WebTestCase et non KernelTestCase : le dry-run construit le FormType réel, dont
 * les champs autocomplete scopent sur getConnectedTo() de l'utilisateur connecté.
 */
class ProgrammeKetTest extends WebTestCase
{
    private const ENT = 'PHPUnit Programme SARL';
    private const OWNER = 'phpunit-programme-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
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
        $conn->executeStatement(
            'DELETE e FROM assistant_programme_etape e JOIN assistant_programme p ON e.programme_id = p.id
             JOIN entreprise ent ON p.entreprise_id = ent.id WHERE ent.nom = :n',
            ['n' => self::ENT],
        );
        $conn->executeStatement(
            'DELETE m FROM assistant_message m JOIN assistant_conversation c ON m.conversation_id = c.id
             JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n',
            ['n' => self::ENT],
        );
        foreach ([
            'assistant_programme', 'assistant_conversation', 'paiement_prime', 'tranche',
            'chargement_pour_prime', 'cotation', 'piste', 'client', 'portefeuille', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /**
     * Deux tranches de 1000, aucun paiement signalé : chacune a donc un solde de
     * prime de 1000. C'est le décor exact de l'incident (une série d'objets de
     * même nature, à traiter l'un après l'autre).
     *
     * @return array{scope: AiScope, conversation: AssistantConversation, tranches: array<int, int>}
     */
    private function seed(): array
    {
        $owner = (new Utilisateur())
            ->setEmail(self::OWNER)->setNom('PHPUnit Programme')->setVerified(true)->setPassword('x');
        $owner->setPaidTokens(1_000_000); // le module IA est premium.
        $this->em->persist($owner);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC-PRG')->setAdresse('1 rue des Missions')
            ->setTelephone('+243000000009')->setRccm('RCCM-PRG')->setIdnat('IDNAT-PRG')
            ->setNumimpot('IMP-PRG')->setUtilisateur($owner);
        $this->em->persist($ent);
        $owner->setConnectedTo($ent);

        $invite = (new Invite())->setNom('Propriétaire PRG');
        $invite->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($invite);

        $portefeuille = (new Portefeuille())->setNom('Portefeuille PRG')->setGestionnaire($invite);
        $portefeuille->setEntreprise($ent);
        $this->em->persist($portefeuille);

        $client = (new Client())->setNom('Client PRG')->setExonere(false);
        $client->setEntreprise($ent)->setPortefeuille($portefeuille);
        $this->em->persist($client);

        $tranches = [];
        foreach ([1, 2] as $rang) {
            $piste = (new Piste())
                ->setNom('Piste PRG ' . $rang)->setTypeAvenant(0)
                ->setDescriptionDuRisque('Risque programme ' . $rang)
                ->setExercice(2026)->setClient($client);
            $piste->setEntreprise($ent)->setInvite($invite);
            $this->em->persist($piste);

            $cotation = (new Cotation())->setNom('Cotation PRG ' . $rang)->setDuree(365);
            $cotation->setPiste($piste);
            $cotation->setEntreprise($ent);
            $this->em->persist($cotation);

            $chargement = (new ChargementPourPrime())
                ->setNom('Prime PRG ' . $rang)->setMontantFlatExceptionel(1000.0)->setCotation($cotation);
            $chargement->setEntreprise($ent);
            // Les DEUX côtés : sans l'adder, la collection en mémoire reste vide et
            // la prime calculée retombe à 0 (gotcha transversal du projet).
            $cotation->addChargement($chargement);
            $this->em->persist($chargement);

            $tranche = (new Tranche())
                ->setNom('Tranche PRG ' . $rang)
                ->setPourcentage(100.0) // POINTS : 100 = 100 %.
                ->setPayableAt(new \DateTimeImmutable('-30 days'))
                ->setEcheanceAt(new \DateTimeImmutable('-5 days'));
            $tranche->setCotation($cotation);
            $tranche->setEntreprise($ent);
            $cotation->addTranche($tranche);
            $this->em->persist($tranche);
            $tranches[] = $tranche;
        }

        $conversation = (new AssistantConversation())->setEntreprise($ent)->setInvite($invite)->setTitre('Fil PRG');
        $this->em->persist($conversation);

        $this->em->flush();
        $idsTranches = array_map(static fn (Tranche $t) => (int) $t->getId(), $tranches);
        $idConversation = (int) $conversation->getId();
        $idEnt = (int) $ent->getId();
        $idInvite = (int) $invite->getId();
        $idOwner = (int) $owner->getId();
        $this->em->clear();

        $owner = $this->em->getRepository(Utilisateur::class)->find($idOwner);
        $this->client->loginUser($owner);

        $conversation = $this->em->getRepository(AssistantConversation::class)->find($idConversation);

        return [
            'scope' => new AiScope(
                $this->em->getRepository(Entreprise::class)->find($idEnt),
                $this->em->getRepository(Invite::class)->find($idInvite),
                $conversation,
            ),
            'conversation' => $conversation,
            'tranches'     => $idsTranches,
        ];
    }

    private function outil(): PreparerProgrammeTool
    {
        return static::getContainer()->get(PreparerProgrammeTool::class);
    }

    private function runner(): ProgrammeRunner
    {
        return static::getContainer()->get(ProgrammeRunner::class);
    }

    /** Déclaration d'une série de signalements de paiement, une étape par tranche. */
    private function etapes(array $tranches, array $montants = []): array
    {
        $etapes = [];
        foreach ($tranches as $i => $id) {
            $etape = [
                'libelle' => sprintf('Tranche %d', $id),
                'outil'   => 'signaler_paiement_prime',
                'cibleId' => $id,
            ];
            if (isset($montants[$i])) {
                $etape['arguments'] = [['cle' => 'montant', 'valeur' => (string) $montants[$i]]];
            }
            $etapes[] = $etape;
        }

        return $etapes;
    }

    // ─────────────────── Déclaration et présentation ───────────────────

    public function testUneSeuleDeclarationPrepareLaPremiereEtapeEtSeulementElle(): void
    {
        ['scope' => $scope, 'tranches' => $tranches] = $this->seed();

        $resultat = $this->outil()->execute([
            'objectif' => 'Signaler le paiement des tranches',
            'etapes'   => $this->etapes($tranches),
        ], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $this->assertTrue($resultat->data['pret'], 'La première étape doit être prête à valider.');
        $this->assertSame(PlanEnAttente::ACTION_REVUE, $resultat->uiAction['type']);
        $this->assertSame(1, $resultat->uiAction['programme']['position']);
        $this->assertSame(2, $resultat->uiAction['programme']['total']);

        // L'étape 1 est PROPOSÉE, l'étape 2 encore EN ATTENTE : on ne fabrique
        // jamais deux barres de décision à la fois.
        $programme = $this->programme($scope);
        $statuts = array_map(
            static fn (AssistantProgrammeEtape $e) => $e->getStatut(),
            $programme->getEtapes()->toArray(),
        );
        $this->assertSame(
            [AssistantProgrammeEtape::STATUT_PROPOSEE, AssistantProgrammeEtape::STATUT_EN_ATTENTE],
            $statuts,
        );

        // Le plan présenté porte bien sur la PREMIÈRE tranche, et sur elle seule.
        $this->assertSame($tranches[0], $resultat->uiAction['plan'][0]['fields']['tranche']);
        $this->assertCount(1, $resultat->uiAction['plan']);

        // Références uniques et lisibles.
        $this->assertMatchesRegularExpression('/^PRG-\d{8}-[0-9A-F]{4}$/', (string) $programme->getReference());
        $this->assertSame($programme->getReference() . '/01', $programme->getEtapes()->first()->getReference());
    }

    public function testUnSeulProgrammeEnCoursALaFois(): void
    {
        ['scope' => $scope, 'tranches' => $tranches] = $this->seed();
        $this->outil()->execute(['objectif' => 'Première mission', 'etapes' => $this->etapes($tranches)], $scope);
        $premier = $this->programme($scope)->getReference();

        $refus = $this->outil()->execute(['objectif' => 'Seconde mission', 'etapes' => $this->etapes($tranches)], $scope);

        $this->assertFalse($refus->data['pret'], 'Une seconde série doit être refusée.');
        $this->assertSame($premier, $refus->data['programmeEnCours']);
        $this->assertNull($refus->uiAction, 'Un refus ne doit produire aucune barre de décision.');

        // L'échappatoire explicite remplace la mission : l'ancienne est close.
        $remplace = $this->outil()->execute([
            'objectif' => 'Seconde mission',
            'etapes'   => $this->etapes($tranches),
            'remplacerProgrammeEnCours' => true,
        ], $scope);
        $this->assertTrue($remplace->data['pret']);
        $this->assertNotSame($premier, $this->programme($scope)->getReference());
    }

    /**
     * Un outil qui exige une STRUCTURE et ne sait pas l'assembler lui-même reste
     * impilotable : l'annoncer serait mentir au modèle (modifier_composition_prime et
     * ses « composantes »).
     */
    public function testOutilNonPilotableParUneEtapeEstRefuse(): void
    {
        ['scope' => $scope, 'tranches' => $tranches] = $this->seed();

        $refus = $this->outil()->execute([
            'objectif' => 'Mission impossible',
            'etapes'   => [
                ['libelle' => 'A', 'outil' => 'modifier_composition_prime', 'cibleId' => 1],
                ['libelle' => 'B', 'outil' => 'signaler_paiement_prime', 'cibleId' => $tranches[1]],
            ],
        ], $scope);

        $this->assertFalse($refus->data['pret']);
        $this->assertStringContainsString('modifier_composition_prime', $refus->data['note']);
        $this->assertNull($this->programmeEnCours()->courant($scope->conversation), 'Aucun programme ne doit rester ouvert.');
    }

    /**
     * Une étape d'écriture ORDINAIRE mal décrite est refusée EN NOMMANT l'étape.
     *
     * Sans cette garde, l'étape partait sans arguments, la préparation échouait plus
     * loin, et le modèle recevait « aucune étape n'a pu être préparée » — un constat
     * qui ne dit pas laquelle est en cause.
     */
    public function testUneEtapeDEcritureSansEntiteEstRefuseeEnNommantLEtape(): void
    {
        ['scope' => $scope, 'tranches' => $tranches] = $this->seed();

        $refus = $this->outil()->execute([
            'objectif' => 'Écriture mal décrite',
            'etapes'   => [
                ['libelle' => 'Étape bancale', 'outil' => 'preparer_operations'],
                ['libelle' => 'B', 'outil' => 'signaler_paiement_prime', 'cibleId' => $tranches[1]],
            ],
        ], $scope);

        $this->assertFalse($refus->data['pret']);
        $this->assertStringContainsString('Étape bancale', $refus->data['note']);
        $this->assertNull($this->programmeEnCours()->courant($scope->conversation));
    }

    public function testUnSeulObjetNeFaitPasUnProgramme(): void
    {
        ['scope' => $scope, 'tranches' => $tranches] = $this->seed();

        $refus = $this->outil()->execute([
            'objectif' => 'Une seule tranche',
            'etapes'   => $this->etapes([$tranches[0]]),
        ], $scope);

        $this->assertFalse($refus->data['pret']);
        $this->assertNull($refus->uiAction);
    }

    // ─────────────────── L'ENCHAÎNEMENT (le cœur) ───────────────────

    public function testExecuterUneEtapePresenteImmediatementLaSuivante(): void
    {
        ['scope' => $scope, 'tranches' => $tranches] = $this->seed();
        $this->outil()->execute(['objectif' => 'Série', 'etapes' => $this->etapes($tranches)], $scope);
        $programme = $this->programme($scope);
        $etape1 = $programme->getEtapes()->first();

        // Ce que fait le contrôleur après une exécution réussie.
        $this->runner()->marquerExecutee($etape1, [['op' => 'create', 'entite' => 'PaiementPrime', 'id' => 1, 'statut' => 'ok', 'niveau' => 0]]);
        $suivant = $this->runner()->preparerProchaine($programme, $scope);

        $this->assertNotNull($suivant, 'L’étape suivante doit être présentée sans intervention du modèle.');
        $this->assertSame(2, $suivant['programme']['position']);
        $this->assertSame(PlanEnAttente::ACTION_REVUE, $suivant['action']['type']);
        // Elle vise la SECONDE tranche : aucune recopie du plan précédent.
        $this->assertSame($tranches[1], $suivant['action']['plan'][0]['fields']['tranche']);

        // Le plan de l'étape suivante est PERSISTÉ sur un vrai message, comme
        // n'importe quel plan : l'endpoint d'exécution le relira sans rien savoir
        // du programme, et un F5 le retrouvera.
        $message = $this->em->getRepository(\App\Entity\AssistantMessage::class)->find($suivant['idMessage']);
        $this->assertNotNull($message);
        $this->assertTrue(PlanEnAttente::estEnAttente($message->getMeta() ?? []));
        $this->assertSame('programme', $message->getMeta()['engine'], 'Cette bulle ne coûte aucun appel au modèle.');
        $this->assertStringContainsString('étape 2 sur 2', (string) $message->getContenu());
    }

    /**
     * LE CAS DE L'INCIDENT DU 2026-08-11 : « Commençons par créer ce fournisseur,
     * après nous poursuivrons l'enregistrement de la dépense. »
     *
     * Cette demande — deux ÉCRITURES ORDINAIRES sur des entités DIFFÉRENTES, validées
     * séparément parce que l'utilisateur l'a demandé — était tout bonnement
     * INEXPRIMABLE : `preparer_operations` étant déclaré impilotable, aucun programme
     * ne pouvait la porter. Ket a donc validé le premier plan puis s'est arrêtée net,
     * et l'utilisateur a dû relancer trois fois sans jamais obtenir le second bouton.
     *
     * Ici, la série entière est déclarée EN UNE FOIS, et l'identifiant créé par
     * l'étape 1 est injecté dans l'étape 2 par le SERVEUR — au moment où il existe.
     */
    public function testUneSerieDEcrituresOrdinairesSEnchaineAvecUneReferenceEntreEtapes(): void
    {
        ['scope' => $scope] = $this->seed();

        $resultat = $this->outil()->execute([
            'objectif' => 'Créer le client puis sa piste',
            'etapes'   => [
                [
                    'libelle'   => 'Le client',
                    'outil'     => 'preparer_operations',
                    'entite'    => 'Client',
                    'operation' => 'create',
                    'ref'       => 'client',
                    'champs'    => [['cle' => 'nom', 'valeur' => 'Client chaîné PRG']],
                ],
                [
                    'libelle'   => 'Sa piste',
                    'outil'     => 'preparer_operations',
                    'entite'    => 'Piste',
                    'operation' => 'create',
                    'champs'    => [
                        ['cle' => 'nom', 'valeur' => 'Piste chaînée PRG'],
                        ['cle' => 'typeAvenant', 'valeur' => '0'],
                        ['cle' => 'descriptionDuRisque', 'valeur' => 'Risque chaîné'],
                        ['cle' => 'exercice', 'valeur' => '2026'],
                        // L'identifiant n'existe PAS encore : il n'existera qu'après la
                        // validation de l'étape 1.
                        ['cle' => 'client', 'valeur' => '@client'],
                    ],
                ],
            ],
        ], $scope);

        $this->assertTrue($resultat->data['pret'], 'La première étape d’écriture ordinaire doit être prête.');
        $this->assertSame(PlanEnAttente::ACTION_REVUE, $resultat->uiAction['type']);
        $this->assertSame('Client', $resultat->uiAction['plan'][0]['entite']);
        $this->assertSame(2, $resultat->uiAction['programme']['total']);

        // L'étape 1 est validée et écrite : le journal porte l'identifiant réel. On
        // réutilise le client du décor comme enregistrement « créé » — ce qui vérifie
        // AUSSI que le plan de l'étape 2 reste valide avec un identifiant véritable.
        $programme = $this->programme($scope);
        $etape1 = $programme->getEtapes()->first();
        $idClient = (int) $this->em->getRepository(Client::class)
            ->findOneBy(['nom' => 'Client PRG'])->getId();
        $this->runner()->marquerExecutee($etape1, [
            ['op' => 'create', 'entite' => 'Client', 'libelle' => 'Clients', 'cible' => 'Client chaîné PRG',
             'id' => $idClient, 'statut' => 'ok', 'niveau' => 0],
        ]);

        $suivant = $this->runner()->preparerProchaine($programme, $scope);

        $this->assertNotNull($suivant, 'L’étape 2 doit être présentée SANS intervention du modèle.');
        $this->assertSame(2, $suivant['programme']['position']);
        $this->assertSame(PlanEnAttente::ACTION_REVUE, $suivant['action']['type']);
        $this->assertSame('Piste', $suivant['action']['plan'][0]['entite']);
        // LE CŒUR : « @client » est devenu l'identifiant réel, injecté par le serveur.
        $this->assertSame(
            $idClient,
            $suivant['action']['plan'][0]['fields']['client'],
            'La référence à l’étape précédente doit être résolue en identifiant réel.',
        );
    }

    /**
     * Une référence vers une étape qui n'a PAS été écrite (passée, refusée, en échec)
     * ne doit jamais devenir un identifiant arbitraire : l'étape est traversée avec
     * son motif, et la série continue.
     */
    public function testUneReferenceVersUneEtapeNonEcriteRendLEtapeImpossible(): void
    {
        ['scope' => $scope, 'tranches' => $tranches] = $this->seed();

        $this->outil()->execute([
            'objectif' => 'Client puis piste, mais le client sera refusé',
            'etapes'   => [
                [
                    'libelle' => 'Le client', 'outil' => 'preparer_operations', 'entite' => 'Client',
                    'operation' => 'create', 'ref' => 'client',
                    'champs' => [['cle' => 'nom', 'valeur' => 'Client jamais créé']],
                ],
                [
                    'libelle' => 'Sa piste', 'outil' => 'preparer_operations', 'entite' => 'Piste',
                    'operation' => 'create',
                    'champs' => [
                        ['cle' => 'nom', 'valeur' => 'Piste orpheline'],
                        ['cle' => 'typeAvenant', 'valeur' => '0'],
                        ['cle' => 'descriptionDuRisque', 'valeur' => 'Risque orphelin'],
                        ['cle' => 'exercice', 'valeur' => '2026'],
                        ['cle' => 'client', 'valeur' => '@client'],
                    ],
                ],
                ['libelle' => 'Un paiement', 'outil' => 'signaler_paiement_prime', 'cibleId' => $tranches[0]],
            ],
        ], $scope);

        $programme = $this->programme($scope);
        // L'utilisateur refuse l'étape 1 : rien n'a été écrit, donc « @client » ne
        // désigne rien.
        $this->runner()->marquerAnnulee($programme->getEtapes()->first());

        $suivant = $this->runner()->preparerProchaine($programme, $scope);

        $etape2 = $programme->getEtapes()->get(1);
        $this->assertSame(AssistantProgrammeEtape::STATUT_IMPOSSIBLE, $etape2->getStatut());
        $this->assertStringContainsString('client', (string) $etape2->getErreur());
        // La série n'est pas figée pour autant : la troisième étape est présentée.
        $this->assertNotNull($suivant, 'Une étape impossible ne doit jamais figer la mission.');
        $this->assertSame(3, $suivant['programme']['position']);
    }

    public function testUneEtapeRefuseeNInterrompsPasLaSerie(): void
    {
        ['scope' => $scope, 'tranches' => $tranches] = $this->seed();
        $this->outil()->execute(['objectif' => 'Série', 'etapes' => $this->etapes($tranches)], $scope);
        $programme = $this->programme($scope);

        $this->runner()->marquerAnnulee($programme->getEtapes()->first());
        $suivant = $this->runner()->preparerProchaine($programme, $scope);

        $this->assertNotNull($suivant, 'Refuser une étape ne doit pas figer la mission.');
        $this->assertSame(2, $suivant['programme']['position']);
    }

    public function testUneEtapeInfaisableEstTraverseeAvecSonMotif(): void
    {
        ['scope' => $scope, 'tranches' => $tranches] = $this->seed();

        // Première étape sur une tranche INEXISTANTE : l'outil la refusera.
        $resultat = $this->outil()->execute([
            'objectif' => 'Série avec une cible morte',
            'etapes'   => [
                ['libelle' => 'Tranche fantôme', 'outil' => 'signaler_paiement_prime', 'cibleId' => 999999999],
                ['libelle' => 'Tranche réelle', 'outil' => 'signaler_paiement_prime', 'cibleId' => $tranches[0]],
            ],
        ], $scope);

        // La série continue : c'est la seconde étape qui est présentée.
        $this->assertTrue($resultat->data['pret']);
        $this->assertSame(2, $resultat->uiAction['programme']['position']);

        $etapes = $this->programme($scope)->getEtapes();
        $this->assertSame(AssistantProgrammeEtape::STATUT_IMPOSSIBLE, $etapes->first()->getStatut());
        $this->assertNotEmpty($etapes->first()->getErreur(), 'Le motif du refus doit être conservé pour le rapport.');
    }

    // ─────────────────── Le RAPPORT FINAL vérifié en base ───────────────────

    public function testLeRapportRelitLaBaseEtSignaleUnePrimeNonSoldee(): void
    {
        ['scope' => $scope, 'tranches' => $tranches] = $this->seed();
        $programme = $this->creerProgrammeExecute($scope, $tranches[0], montantRegle: 400.0);

        $rapport = static::getContainer()->get(ProgrammeVerificateur::class)->verifier($programme, $scope);

        $this->assertFalse($rapport['conforme'], 'Une prime réglée à 400 sur 1000 n’est pas conforme.');
        $this->assertNotEmpty($rapport['ecarts']);
        $this->assertStringContainsString('n’est PAS soldée', implode(' ', $rapport['ecarts']));

        // La correction proposée solde le RESTE, et pas autre chose.
        $this->assertCount(1, $rapport['corrections']);
        $correction = $rapport['corrections'][0];
        $this->assertSame('signaler_paiement_prime', $correction['outil']);
        $this->assertSame($tranches[0], $correction['arguments']['trancheId']);
        $this->assertEqualsWithDelta(600.0, $correction['arguments']['montant'], 0.01);
    }

    public function testLeRapportConstateUnePrimeSoldeeSansEcart(): void
    {
        ['scope' => $scope, 'tranches' => $tranches] = $this->seed();
        $programme = $this->creerProgrammeExecute($scope, $tranches[0], montantRegle: 1000.0);

        $rapport = static::getContainer()->get(ProgrammeVerificateur::class)->verifier($programme, $scope);

        $this->assertTrue($rapport['conforme'], implode(' | ', $rapport['ecarts']));
        $this->assertSame([], $rapport['corrections']);
        $this->assertStringContainsString('soldée', implode(' ', $rapport['etapes'][0]['constats']));
    }

    public function testUneEtapeNonExecuteeEstNommeeDansLeRapportEtProposeeEnCorrection(): void
    {
        ['scope' => $scope, 'tranches' => $tranches] = $this->seed();
        $this->outil()->execute(['objectif' => 'Série', 'etapes' => $this->etapes($tranches)], $scope);
        $programme = $this->programme($scope);

        // L'utilisateur stoppe la mission : rien n'a été exécuté.
        $this->programmeEnCours()->interrompre($programme, 'Programme interrompu par l’utilisateur.');
        $rapport = static::getContainer()->get(ProgrammeVerificateur::class)->verifier($programme, $scope);

        $this->assertSame(AssistantProgramme::STATUT_INTERROMPU, $rapport['statut']);
        $this->assertSame(2, $rapport['compte']['annulee']);
        // Une étape REFUSÉE n'est pas un écart : c'est une décision de l'utilisateur.
        $this->assertTrue($rapport['conforme']);
    }

    /**
     * Programme d'UNE étape réellement exécutée : on signale un paiement du montant
     * demandé, puis on journalise comme le fait l'endpoint d'exécution. C'est le
     * décor du rapport (la relecture en base porte sur des écritures VRAIES).
     */
    private function creerProgrammeExecute(AiScope $scope, int $idTranche, float $montantRegle): AssistantProgramme
    {
        $tranche = $this->em->getRepository(Tranche::class)->find($idTranche);

        $paiement = (new PaiementPrime())
            ->setTranche($tranche)
            ->setMontant($montantRegle)
            ->setPaidAt(new \DateTimeImmutable('now'))
            ->setReference('PRIME-TEST');
        $paiement->setEntreprise($scope->entreprise);
        $this->em->persist($paiement);

        $programme = (new AssistantProgramme())
            ->setReference('PRG-20260806-TEST')
            ->setConversation($scope->conversation)
            ->setEntreprise($scope->entreprise)
            ->setInvite($scope->invite)
            ->setObjectif('Solder la prime');

        $etape = (new AssistantProgrammeEtape())
            ->setOrdre(1)
            ->setReference('PRG-20260806-TEST/01')
            ->setLibelle(sprintf('Tranche %d', $idTranche))
            ->setOutil('signaler_paiement_prime')
            ->setArguments(['trancheId' => $idTranche])
            ->setStatut(AssistantProgrammeEtape::STATUT_EXECUTEE);
        $programme->addEtape($etape);
        $this->em->persist($programme);
        $this->em->persist($etape);
        $this->em->flush();

        $etape->setJournal([[
            'op'      => 'create',
            'entite'  => 'PaiementPrime',
            'libelle' => 'Paiements de prime',
            'cible'   => 'PRIME-TEST',
            'id'      => (int) $paiement->getId(),
            'statut'  => 'ok',
            'niveau'  => 0,
        ]]);
        $this->em->flush();

        return $programme;
    }

    private function programmeEnCours(): ProgrammeEnCours
    {
        return static::getContainer()->get(ProgrammeEnCours::class);
    }

    private function programme(AiScope $scope): AssistantProgramme
    {
        $programme = $this->programmeEnCours()->courant($scope->conversation);
        $this->assertNotNull($programme, 'Un programme aurait dû être ouvert.');

        return $programme;
    }
}
