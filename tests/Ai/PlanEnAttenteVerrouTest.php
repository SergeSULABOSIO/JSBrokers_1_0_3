<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\PreparerOperationsTool;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * VERROU ANTI-EMPILEMENT : tant qu'un plan d'écriture attend la décision de
 * l'utilisateur, Ket ne peut pas en préparer un second — sinon l'utilisateur se
 * retrouverait avec plusieurs barres « Valider et exécuter » à trancher l'une
 * après l'autre, ce qu'on veut précisément lui épargner.
 *
 * Le verrou est DUR (il vit dans l'outil, pas dans le prompt) et s'appuie sur
 * l'état de la CONVERSATION, porté jusqu'aux outils par AiScope. Son unique
 * échappatoire — remplacerPlanEnAttente — annule d'abord le plan en attente :
 * il n'y a jamais deux plans à valider.
 */
class PlanEnAttenteVerrouTest extends WebTestCase
{
    private const ENT = 'PHPUnit-KetVerrou';
    private const OWNER = 'phpunit-ketverrou-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private PreparerOperationsTool $preparer;
    private PlanEnAttente $planEnAttente;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->preparer = static::getContainer()->get(PreparerOperationsTool::class);
        $this->planEnAttente = static::getContainer()->get(PlanEnAttente::class);
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
        foreach (['assistant_conversation', 'client', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** @return array{0:Entreprise,1:Invite,2:AssistantConversation} */
    private function seed(): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($ent);
        $owner->setConnectedTo($ent);

        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);

        $conversation = (new AssistantConversation())->setEntreprise($ent)->setInvite($inv)->setTitre('Fil');
        $this->em->persist($conversation);
        $this->em->flush();
        $this->client->loginUser($owner);

        return [$ent, $inv, $conversation];
    }

    /** Message assistant portant un plan, dans l'état demandé. */
    private function seedPlan(AssistantConversation $conversation, ?string $decision = null): AssistantMessage
    {
        $meta = ['mutationPlan' => [
            'plan'   => [['op' => 'create', 'entite' => 'Client', 'fields' => ['nom' => 'Déjà proposé', 'exonere' => false]]],
            'budget' => ['coutEstime' => 30],
        ]];
        if ($decision !== null) {
            $meta[$decision] = true;
        }

        $message = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu('Voici le plan.')
            ->setMeta($meta);
        $conversation->addMessage($message);
        $this->em->flush();

        return $message;
    }

    private function preparer(Entreprise $ent, Invite $inv, AssistantConversation $conversation, array $args = []): array
    {
        return $this->preparer->execute($args + ['operations' => [
            ['op' => 'create', 'entite' => 'Client', 'champs' => ['nom' => 'Nouveau plan', 'exonere' => false]],
        ]], new AiScope($ent, $inv, $conversation))->data;
    }

    // ───────────────────────────── Le verrou ─────────────────────────────

    public function testSansPlanEnAttenteLaPreparationPasse(): void
    {
        [$ent, $inv, $conversation] = $this->seed();

        $data = $this->preparer($ent, $inv, $conversation);

        $this->assertTrue($data['pret'], 'Aucun plan en attente : la préparation se déroule normalement.');
    }

    public function testUnPlanEnAttenteInterditDenPreparerUnSecond(): void
    {
        [$ent, $inv, $conversation] = $this->seed();
        $this->seedPlan($conversation);

        $data = $this->preparer($ent, $inv, $conversation);

        $this->assertFalse($data['pret'], 'Le second plan est REFUSÉ tant que le premier n’est pas tranché.');
        $this->assertTrue($data['planEnAttente']);
        $this->assertArrayNotHasKey('plan', $data, 'Aucun tableau de plan n’est présenté.');
        $this->assertStringContainsString('1 opération, 30 tokens', $data['resumePlanEnAttente']);
    }

    public function testPlanValideOuAnnuleNeVerrouillePlus(): void
    {
        [$ent, $inv, $conversation] = $this->seed();
        $this->seedPlan($conversation, 'mutationPlanExecuted');

        $this->assertTrue(
            $this->preparer($ent, $inv, $conversation)['pret'],
            'Un plan déjà exécuté ne bloque plus la suite du travail.',
        );

        $this->seedPlan($conversation, 'mutationPlanCancelled');
        $this->assertTrue(
            $this->preparer($ent, $inv, $conversation)['pret'],
            'Un plan annulé ne bloque pas non plus.',
        );
    }

    /** L'échappatoire : l'utilisateur veut CHANGER le plan — l'ancien est annulé, pas empilé. */
    public function testRemplacerLePlanEnAttenteLannuleEtPresenteLeNouveau(): void
    {
        [$ent, $inv, $conversation] = $this->seed();
        $ancien = $this->seedPlan($conversation);

        $data = $this->preparer($ent, $inv, $conversation, ['remplacerPlanEnAttente' => true]);

        $this->assertTrue($data['pret'], 'Le nouveau plan est présenté.');
        $this->em->refresh($ancien);
        $this->assertTrue(
            PlanEnAttente::estAnnule($ancien->getMeta()),
            'L’ancien plan a été ANNULÉ : il n’y a jamais deux plans à valider.',
        );
        $this->assertNull(
            $this->planEnAttente->messageEnAttente($conversation),
            'Plus aucun plan de la conversation n’attend de décision au moment où le nouveau est présenté.',
        );
    }

    /**
     * UN PLAN REMPLACÉ N'EST PAS UN PLAN QUE L'UTILISATEUR A REFUSÉ.
     *
     * Les deux posaient `mutationPlanCancelled`, et le fil ne connaissait qu'un
     * libellé : « Plan annulé ». Le 2026-09-14, un courtier a donc vu ce bandeau
     * apparaître sous des plans qu'il n'avait jamais annulés — Ket venait d'en
     * présenter une version complétée après qu'il eut donné un renseignement de
     * plus. Le fil lui attribuait un refus là où il avait précisé sa demande.
     */
    public function testUnPlanRemplaceNeSeDitPasAnnuleParLUtilisateur(): void
    {
        [$ent, $inv, $conversation] = $this->seed();
        $ancien = $this->seedPlan($conversation);

        $this->preparer($ent, $inv, $conversation, ['remplacerPlanEnAttente' => true]);

        $this->em->refresh($ancien);
        $this->assertSame(
            \App\Ai\Mutation\FinDePlan::REMPLACE,
            \App\Ai\Mutation\FinDePlan::depuisMeta($ancien->getMeta()),
            'Un plan chassé par sa propre version corrigée doit être marqué REMPLACÉ : c’est ce qui '
            . 'empêche le fil de dire au courtier qu’il a annulé ce qu’il venait de préciser.',
        );
    }

    /**
     * ET RÉCIPROQUEMENT : le clic sur « Annuler » est la SEULE fin qui s'annonce
     * comme une annulation. C'est le seul geste que l'utilisateur ait réellement fait.
     */
    public function testLAnnulationParLUtilisateurEstTagueeCommeTelle(): void
    {
        [$ent, , $conversation] = $this->seed();
        $message = $this->seedPlan($conversation);

        $this->client->request('POST', sprintf(
            '/admin/assistant-ia/api/mutation/%d/%d/%d/cancel',
            $ent->getId(),
            $conversation->getId(),
            $message->getId(),
        ));

        $this->assertResponseIsSuccessful();
        $this->em->refresh($message);
        $this->assertSame(
            \App\Ai\Mutation\FinDePlan::UTILISATEUR,
            \App\Ai\Mutation\FinDePlan::depuisMeta($message->getMeta()),
        );
    }

    /**
     * UN CLIC TARDIF NE REQUALIFIE PAS UNE MORT DÉJÀ SURVENUE.
     *
     * Un onglet resté ouvert garde à l'écran la barre d'un plan que le serveur a
     * depuis remplacé ou laissé périmer. Le clic qui arrive alors ne doit pas
     * réécrire le motif : cela transformerait après coup une péremption en refus de
     * l'utilisateur, et le fil se mettrait à raconter une décision qui n'a pas eu
     * lieu. On refuse, comme on refuse déjà d'annuler un plan exécuté.
     */
    public function testUnClicTardifSurUnPlanDejaMortEstRefuse(): void
    {
        [$ent, , $conversation] = $this->seed();
        $message = $this->seedPlan($conversation, 'mutationPlanCancelled');
        $meta = $message->getMeta();
        $meta[\App\Ai\Mutation\FinDePlan::CLE_META] = \App\Ai\Mutation\FinDePlan::PERIME->value;
        $message->setMeta($meta);
        $this->em->flush();

        $this->client->request('POST', sprintf(
            '/admin/assistant-ia/api/mutation/%d/%d/%d/cancel',
            $ent->getId(),
            $conversation->getId(),
            $message->getId(),
        ));

        $this->assertResponseStatusCodeSame(409);
        $this->em->refresh($message);
        $this->assertSame(
            \App\Ai\Mutation\FinDePlan::PERIME,
            \App\Ai\Mutation\FinDePlan::depuisMeta($message->getMeta()),
            'Le motif d’origine doit survivre au clic tardif.',
        );
    }

    /** Ajoute n messages d'utilisateur au fil, après le plan. */
    private function messagesUtilisateur(AssistantConversation $conversation, int $combien): void
    {
        for ($i = 0; $i < $combien; $i++) {
            $conversation->addMessage((new AssistantMessage())
                ->setRole(AssistantMessage::ROLE_USER)
                ->setContenu('Question sans rapport n°' . ($i + 1)));
        }
        $this->em->flush();
    }

    /**
     * UN PLAN QUE PERSONNE NE TRANCHE FINIT PAR MOURIR.
     *
     * Rien, jusqu'ici, ne tuait un plan en attente : il survivait indéfiniment. Or
     * tant qu'il vit, il force la trousse d'ÉCRITURE — la plus lourde — et
     * court-circuite la phase de compréhension, c'est-à-dire la seule qui pourrait
     * remarquer l'enlisement. Le 2026-09-14, cette boucle a coûté ~490 000 jetons
     * pour zéro écriture.
     *
     * Le critère ne devine aucune intention : il CONSTATE l'état du fil.
     */
    public function testUnPlanQuePersonneNeTrancheFinitParPerimer(): void
    {
        [, , $conversation] = $this->seed();
        $message = $this->seedPlan($conversation);
        $this->messagesUtilisateur($conversation, 2);

        $this->planEnAttente->perimerSiOublie($conversation, false);

        $this->em->refresh($message);
        $this->assertNull(
            $this->planEnAttente->messageEnAttente($conversation),
            'Deux messages plus loin, l’utilisateur est passé à autre chose : le plan ne doit plus '
            . 'verrouiller la conversation.',
        );
        $this->assertSame(
            \App\Ai\Mutation\FinDePlan::PERIME,
            \App\Ai\Mutation\FinDePlan::depuisMeta($message->getMeta()),
        );
    }

    /**
     * MAIS PAS AU PREMIER MESSAGE. Celui qui suit un plan est presque toujours une
     * réponse AU plan — « oui », « ajoute son téléphone », « change le montant ».
     * Le faire périr là détruirait précisément la complétion qu'on vient d'ajouter.
     */
    public function testUnPlanSurvitAuMessageQuiLeComplete(): void
    {
        [, , $conversation] = $this->seed();
        $this->seedPlan($conversation);
        $this->messagesUtilisateur($conversation, 1);

        $this->planEnAttente->perimerSiOublie($conversation, false);

        $this->assertNotNull(
            $this->planEnAttente->messageEnAttente($conversation),
            'Un plan ne meurt pas du message qui vient le préciser.',
        );
    }

    /**
     * UN PROGRAMME EN COURS NE PÉRIME JAMAIS. Une étape de série est portée par un
     * message qui contient un plan ; la faire mourir laisserait l'étape « proposée »
     * sans barre, la série ne se clôturerait jamais, et le verrou de trousse
     * resterait armé pour toujours. On aurait remplacé un plan immortel par un
     * programme immortel.
     */
    public function testUnPlanDeProgrammeNePerimeJamais(): void
    {
        [, , $conversation] = $this->seed();
        $this->seedPlan($conversation);
        $this->messagesUtilisateur($conversation, 5);

        $this->planEnAttente->perimerSiOublie($conversation, true);

        $this->assertNotNull(
            $this->planEnAttente->messageEnAttente($conversation),
            'Une étape de programme ne se termine que par un geste de son propre vocabulaire.',
        );
    }

    /** Hors conversation (exécution différée, test), le verrou est simplement inopérant. */
    public function testScopeSansConversationNeVerrouillePas(): void
    {
        [$ent, $inv, $conversation] = $this->seed();
        $this->seedPlan($conversation);

        $data = $this->preparer->execute(['operations' => [
            ['op' => 'create', 'entite' => 'Client', 'champs' => ['nom' => 'Hors fil', 'exonere' => false]],
        ]], new AiScope($ent, $inv));

        $this->assertTrue($data->data['pret']);
    }

    // ─────────────────── Source unique de l'état d'un plan ───────────────────

    public function testEtatDUnPlanEstLuAuMemeEndroitPartout(): void
    {
        $sansPlan = [];
        $enAttente = ['mutationPlan' => ['plan' => []]];
        $execute = ['mutationPlan' => ['plan' => []], 'mutationPlanExecuted' => true];
        $annule = ['mutationPlan' => ['plan' => []], 'mutationPlanCancelled' => true];

        $this->assertFalse(PlanEnAttente::porteUnPlan($sansPlan));
        $this->assertFalse(PlanEnAttente::estEnAttente($sansPlan));

        $this->assertTrue(PlanEnAttente::estEnAttente($enAttente));
        $this->assertFalse(PlanEnAttente::estExecute($enAttente));
        $this->assertFalse(PlanEnAttente::estAnnule($enAttente));

        $this->assertTrue(PlanEnAttente::estExecute($execute));
        $this->assertFalse(PlanEnAttente::estEnAttente($execute));

        $this->assertTrue(PlanEnAttente::estAnnule($annule));
        $this->assertFalse(PlanEnAttente::estEnAttente($annule));
    }

    /**
     * Filet du MÊME tour : le verrou de conversation ne voit que les tours
     * précédents. Si le moteur présente deux plans dans une seule réponse, seul
     * le premier survit — le second n'aurait aucun plan stocké derrière lui.
     */
    public function testUneReponseNePorteQuUneSeuleBarreDeDecision(): void
    {
        $actions = PlanEnAttente::limiterAUnSeulPlan([
            ['type' => 'app:workspace.open-dialog', 'entite' => 'Client'],
            ['type' => PlanEnAttente::ACTION_REVUE, 'plan' => ['premier']],
            ['type' => PlanEnAttente::ACTION_REVUE, 'plan' => ['second']],
            ['type' => 'app:workspace.data-changed'],
        ]);

        $revues = array_values(array_filter(
            $actions,
            static fn (array $a) => ($a['type'] ?? null) === PlanEnAttente::ACTION_REVUE,
        ));
        $this->assertCount(1, $revues, 'Une seule barre de décision par message.');
        $this->assertSame(['premier'], $revues[0]['plan']);
        $this->assertCount(3, $actions, 'Les autres directives UI passent inchangées.');
    }

    /** Le dernier plan en attente du fil est celui que l'utilisateur voit. */
    public function testSeulLePlanNonTrancheEstRetenu(): void
    {
        [, , $conversation] = $this->seed();
        $this->seedPlan($conversation, 'mutationPlanExecuted');
        $attendu = $this->seedPlan($conversation);

        $this->assertSame(
            $attendu->getId(),
            $this->planEnAttente->messageEnAttente($conversation)?->getId(),
        );
    }

    // ─────────────── Garde-fou anti-plan FANTÔME (prose sans action) ───────────────

    /**
     * Reproduit l'incident : le modèle décrit un plan / un budget / un « bouton de
     * validation » dans sa PROSE sans avoir appelé l'outil. La prose doit être
     * reconnue comme simulant une décision — c'est ce qui, combiné à l'absence de
     * plan réel, déclenche l'avertissement autoritaire.
     */
    public function testProseSimuleUneDecisionReconnaitLesSurfacesFabriquees(): void
    {
        $cas = [
            'Le bouton de validation est désormais actif dans votre interface.',
            'Vous pourrez cliquer sur Valider et exécuter pour lancer l’opération.',
            'Une boîte de confirmation va apparaître.',
            "| Total estimé | 25 |\n| Solde disponible | 29 328 |\n| Reste après exécution | 29 303 |",
            // Phrases RÉELLES de l'incident du 2026-08-04 (mouvement de police).
            // La 1re revendique un plan prêt sans prononcer « bouton de validation » ;
            // la 2de ANNONCE un appel d'outil — une phrase ne déclenche rien, et il n'y
            // aura pas de tour suivant : l'utilisateur attend puis relance dans le vide.
            'Le plan de renouvellement pour l’avenant #62 est entièrement prêt à être exécuté.',
            'Je lance immédiatement l’outil preparer_mouvement_avenant pour l’avenant #62.',
            'Je vais appeler preparer_operations pour créer ce revenu.',
            // INCIDENT DU 2026-08-12 : le tableau de budget rendu en prose, sans le
            // moindre appel d'outil, et qu'aucun marqueur ne voyait alors.
            "**Budget de l'opération**\n\n| Étape | Coût estimé | Enregistrements | Obligatoire |\n"
                . "| Enregistrement complet du dossier | 50 € | 7 | Oui |\n| TOTAL | 50 € | 7 |",
            // La règle vise TOUTE monnaie, pas l'euro : un budget n'en porte aucune.
            'Budget de l’opération — coût estimé : 50 $ pour 7 enregistrements.',
            'Budget de l’opération — coût estimé : 50 USD pour 7 enregistrements.',
            'Budget de l’opération — coût estimé : 45 000 CDF pour 7 enregistrements.',
            'Le coût estimé de cette opération est de 50 euros.',
            // Et le tableau complet, même libellé en tokens : « planifiées » le trahit.
            "**Opérations planifiées**\n\n| # | Entité | Action |\n\n**Budget de l'opération** : "
                . '50 tokens pour 7 enregistrements.',
        ];
        foreach ($cas as $texte) {
            $this->assertTrue(
                PlanEnAttente::proseSimuleUneDecision($texte),
                sprintf('Devrait être détecté comme simulant une décision : « %s »', mb_substr($texte, 0, 40)),
            );
        }
    }

    /** Les réponses légitimes (question, récap d'après-exécution) ne déclenchent pas le garde-fou. */
    public function testProseNormaleNeSimulePasDeDecision(): void
    {
        $cas = [
            'Pour ajouter un revenu, de quel type de commission s’agit-il ?',
            'Oui, l’exécution a bien été réalisée : le revenu #189 a été supprimé.',
            'Voici la liste des cotations actuellement disponibles dans votre portefeuille.',
            // NOMMER un outil pour expliquer un manque est LÉGITIME : c'est la conjonction
            // avec un verbe d'intention à la 1re personne qui trahit l'annonce creuse.
            'Il me faudrait passer par preparer_operations, mais la référence de la police manque : laquelle visez-vous ?',
            // Restitution honnête d'un refus « dejaTraite » : aucune surface de décision.
            'Cette police porte déjà un mouvement enregistré : une piste de renouvellement existe, '
                . 'mais aucun avenant successeur n’a encore été émis.',
            // RÉCAPITULATIF D'APRÈS-EXÉCUTION : il parle d'opérations RÉALISÉES et
            // compte en tokens. C'est le seul voisin dangereux de la règle « budget
            // fabriqué » — il doit rester muet.
            '7 enregistrements ont été créés, pour un coût de 50 tokens. Solde restant : 29 303.',
            'Le dossier est enregistré : 7 opérations réalisées, budget consommé 50 tokens.',
            // Un MONTANT MÉTIER n'est pas un budget : la prime du client est en dollars,
            // et elle n'a rien à voir avec le coût en tokens d'un plan.
            'La prime de cette tranche est de 95,00 $, dont 80,00 $ de prime nette.',
            'Le coût de la police s’élève à 1 234,50 USD pour l’exercice 2026.',
        ];
        foreach ($cas as $texte) {
            $this->assertFalse(
                PlanEnAttente::proseSimuleUneDecision($texte),
                sprintf('Ne devrait PAS être détecté : « %s »', mb_substr($texte, 0, 40)),
            );
        }
    }

    /**
     * L'EXÉCUTION FANTÔME — le mensonge le plus coûteux de la série (2026-08-12).
     *
     * Ket a écrit « Le dossier complet a été préparé et ENREGISTRÉ AVEC SUCCÈS DANS
     * LA BASE DE DONNÉES », avec un récapitulatif détaillé : client, risque, police,
     * prime, règlement, document. Vérification faite en base : RIEN. Aucun plan
     * n'avait été présenté, donc aucun bouton, donc aucune validation, donc aucune
     * écriture — une écriture ne peut avoir lieu que par l'endpoint d'exécution.
     *
     * C'est pire qu'un plan fantôme : celui-là fait attendre un bouton qui ne vient
     * pas, et cela se voit. Celui-ci fait PARTIR l'utilisateur en croyant son dossier
     * constitué.
     */
    public function testUneExecutionAffirmeeSansPlanEstDemasquee(): void
    {
        $cas = [
            // La phrase RÉELLE de l'incident.
            'Le dossier complet pour le client Mr. Jean de Dieu a été préparé et enregistré avec '
                . 'succès dans la base de données. Voici un récapitulatif des opérations réalisées :',
            'Les 8 enregistrements ont été créés avec succès.',
            'Le client a bien été enregistré.',
            'J’ai enregistré l’ensemble du dossier dans la base de données.',
            'La piste a été créée en base, ainsi que sa proposition.',
        ];
        foreach ($cas as $texte) {
            $this->assertTrue(
                PlanEnAttente::proseAffirmeUnEnregistrement($texte),
                sprintf('Devrait être vu comme un enregistrement affirmé : « %s »', mb_substr($texte, 0, 45)),
            );
            // Aucune décision émise, aucun plan exécuté dans le fil => fantôme.
            $this->assertTrue(PlanEnAttente::estUneExecutionFantome($texte, true, true));
        }
    }

    /**
     * Ce qui ANNONCE une écriture à venir n'est pas une écriture faite : un plan
     * légitime décrit ce qu'il FERA. Le garde-fou ne doit pas le confondre, sinon il
     * démentirait les plans honnêtes.
     */
    public function testUneEcritureANNONCEENestPasUneExecutionAffirmee(): void
    {
        $cas = [
            'Le plan créera le client, sa piste et sa proposition.',
            'Je vais enregistrer ces huit éléments dès que vous aurez validé.',
            'Après validation, le dossier sera enregistré en base.',
            'Voici le plan : 8 opérations, 265 tokens. Validez pour lancer l’enregistrement.',
            'Souhaitez-vous que je prépare ce plan complet d’exécution pour validation ?',
        ];
        foreach ($cas as $texte) {
            $this->assertFalse(
                PlanEnAttente::proseAffirmeUnEnregistrement($texte),
                sprintf('Ne devrait PAS être vu comme accompli : « %s »', mb_substr($texte, 0, 45)),
            );
        }
    }

    /**
     * LE CAS LÉGITIME, qu'il ne faut surtout pas démentir : après une VRAIE
     * exécution, l'utilisateur demande « c'est bien fait ? » et Ket répond oui. Le
     * fil porte alors un plan exécuté — le garde-fou doit se taire.
     */
    public function testUnEnregistrementREELNestJamaisDementi(): void
    {
        $texte = 'Oui, le dossier a bien été enregistré dans la base de données.';

        // La prose est bien celle d'un accompli…
        $this->assertTrue(PlanEnAttente::proseAffirmeUnEnregistrement($texte));
        // …mais le fil porte un plan exécuté : aucun démenti.
        $this->assertFalse(
            PlanEnAttente::estUneExecutionFantome($texte, true, false),
            'Un enregistrement réel ne doit jamais être démenti.',
        );
        // Et si une décision a été émise ce tour-ci, non plus.
        $this->assertFalse(PlanEnAttente::estUneExecutionFantome($texte, false, true));
    }

    /**
     * LE CAS QUE LES MARQUEURS LEXICAUX ONT LAISSÉ PASSER (2026-08-11, deux messages
     * de suite). L'outil venait de refuser — date invalide, puis référence
     * introuvable — et Ket a pourtant rendu un tableau de plan complet, un
     * « Budget estimé : 10 unités (Solde disponible : 115 321) » recopié du tour
     * précédent, et « Veuillez valider ce plan ». Aucun de ces mots ne figurait dans
     * proseSimuleUneDecision, et l'utilisateur a cherché un bouton qui ne viendrait
     * jamais.
     *
     * C'est le signal SERVEUR — un outil de plan a refusé ce tour-ci — qui tranche.
     */
    public function testUnPlanDecritApresUnRefusDOutilEstDemasque(): void
    {
        $prose = "📄 Voici le plan d'opération préparé pour l'enregistrement de votre dépense :\n\n"
            . "| Étape | Entité | Action | Données |\n| --- | --- | --- | --- |\n"
            . "| Enregistrement | DepenseCourtier | Créer | Montant : 150,00 $ |\n\n"
            . "- Budget estimé : 10 unités (Solde disponible : 115 321 unités, suffisant).\n"
            . 'Veuillez valider ce plan pour finaliser l’enregistrement de cette dépense.';

        // Les mots seuls ne suffisaient pas — c'est justement le constat de l'incident.
        $this->assertFalse(
            PlanEnAttente::proseSimuleUneDecision($prose),
            'Ce texte ne revendique aucune surface de décision : les marqueurs de haute précision '
            . 'ne peuvent pas le voir, et il ne faut pas les élargir indéfiniment.',
        );

        // Mais la prose AFFICHE bien un plan, et un outil de plan a refusé : preuve faite.
        $this->assertTrue(PlanEnAttente::proseAfficheUnPlan($prose));
        $this->assertTrue(
            PlanEnAttente::estUnPlanFantome($prose, aucuneDecision: true, unOutilDePlanARefuse: true),
            'Un plan décrit alors que l’outil a refusé de le préparer est un plan fantôme.',
        );

        // Sans le refus d'outil, on ne conclut PAS : le même texte accompagnant un vrai
        // plan ne doit jamais déclencher l'avertissement.
        $this->assertFalse(
            PlanEnAttente::estUnPlanFantome($prose, aucuneDecision: true, unOutilDePlanARefuse: false),
        );
    }

    /**
     * LE VERROU QUI GARANTIT L'ABSENCE DE FAUX POSITIF : dès qu'une décision réelle
     * est en jeu (plan émis ce tour-ci, ou plan en attente dans le fil), aucun
     * avertissement — même si la prose parle de bouton de validation.
     */
    public function testUnePlanReelNeDeclencheJamaisLAvertissement(): void
    {
        $this->assertFalse(
            PlanEnAttente::estUnPlanFantome(
                'Le bouton de validation est actif : cliquez sur Valider et exécuter.',
                aucuneDecision: false,
                unOutilDePlanARefuse: false,
            ),
        );
        $this->assertFalse(
            PlanEnAttente::estUnPlanFantome(
                'Voici le plan. Budget estimé : 10 unités.',
                aucuneDecision: false,
                unOutilDePlanARefuse: true,
            ),
        );
    }

    /** Une réponse ordinaire n'affiche pas de plan, refus d'outil ou non. */
    public function testUneReponseOrdinaireNAffichePasDePlan(): void
    {
        foreach ([
            'Il me manque la date de la dépense (jj/mm/aaaa) pour préparer l’enregistrement.',
            'Voici la liste des dépenses de juillet 2026.',
            '« Loyken Motors » n’est pas répertorié : voulez-vous que je le crée ?',
        ] as $texte) {
            $this->assertFalse(
                PlanEnAttente::proseAfficheUnPlan($texte),
                sprintf('Ne devrait PAS être vu comme un plan affiché : « %s »', mb_substr($texte, 0, 40)),
            );
            $this->assertFalse(
                PlanEnAttente::estUnPlanFantome($texte, aucuneDecision: true, unOutilDePlanARefuse: true),
                'Une question honnête après un refus d’outil est le comportement ATTENDU, pas un défaut.',
            );
        }
    }

    /** L'état « un plan attend-il ? » reflète le fil (source unique), sans EM. */
    public function testAUnPlanEnAttenteRefleteLeFil(): void
    {
        $this->assertFalse(PlanEnAttente::aUnPlanEnAttente(null), 'Aucune conversation : rien en attente.');

        $vide = (new AssistantConversation())->setTitre('Vide');
        $this->assertFalse(PlanEnAttente::aUnPlanEnAttente($vide));

        $execute = (new AssistantConversation())->setTitre('Exécuté');
        $execute->addMessage((new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)->setContenu('Fait.')
            ->setMeta(['mutationPlan' => ['plan' => []], 'mutationPlanExecuted' => true]));
        $this->assertFalse(PlanEnAttente::aUnPlanEnAttente($execute), 'Un plan exécuté n’est plus en attente.');

        $enAttente = (new AssistantConversation())->setTitre('En attente');
        $enAttente->addMessage((new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)->setContenu('Voici le plan.')
            ->setMeta(['mutationPlan' => ['plan' => []]]));
        $this->assertTrue(PlanEnAttente::aUnPlanEnAttente($enAttente), 'Un plan présenté non tranché est en attente.');
    }
}
