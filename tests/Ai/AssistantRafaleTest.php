<?php

namespace App\Tests\Ai;

use App\Ai\Traitement\VerrouDeConversation;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\AssistantTache;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\Utilisateur;
use App\Message\TraiterMessagesAssistant;
use App\MessageHandler\TraiterMessagesAssistantHandler;
use App\Repository\AssistantTacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * LA RAFALE. Trois questions envoyées sans attendre les réponses.
 *
 * CE QUE CES TESTS DÉFENDENT. La refonte asynchrone décorrèle l'ACCEPTATION du
 * TRAITEMENT — elle ne parallélise rien. Traiter deux questions d'un même fil en
 * même temps violerait cinq invariants métier : la fenêtre des vingt derniers
 * messages, le verrou « un seul plan en attente », l'unicité d'un document ou
 * d'une clarification en attente, le rattachement d'une étape de programme à SON
 * message, et les garde-fous anti-plan-fantôme, qui raisonnent sur l'état
 * complet du fil.
 *
 * Le test central est donc une COMPARAISON : une rafale de trois drainée d'un
 * coup doit produire le même fil que trois envois traités un par un. Même
 * transport, même moteur, même questions — la seule variable est le groupage.
 * S'ils divergent, c'est que l'asynchrone a changé le produit.
 *
 * AUCUN WORKER N'EST NÉCESSAIRE ICI. Les questions sont déposées avec
 * ASSISTANT_ASYNC=1, donc le signal part sur le transport `async` et personne ne
 * le consomme : la file reste observable. Le drainage est ensuite déclenché à la
 * main, exactement comme le ferait `messenger:consume`.
 */
class AssistantRafaleTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-iarafale-owner@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit IARAFALE SARL';

    private KernelBrowser $client;
    private ?string $asyncPrecedent = null;

    protected function setUp(): void
    {
        // Posé AVANT le démarrage du noyau : c'est lui qui décide si le dépôt
        // estampille l'enveloppe pour `sync`. À 1, rien n'est traité pendant
        // l'envoi — c'est toute la raison d'être de ce fichier.
        $this->asyncPrecedent = $_ENV['ASSISTANT_ASYNC'] ?? null;
        $_ENV['ASSISTANT_ASYNC'] = '1';
        $_SERVER['ASSISTANT_ASYNC'] = '1';

        $this->client = static::createClient();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        if ($this->asyncPrecedent === null) {
            unset($_ENV['ASSISTANT_ASYNC'], $_SERVER['ASSISTANT_ASYNC']);
        } else {
            $_ENV['ASSISTANT_ASYNC'] = $this->asyncPrecedent;
            $_SERVER['ASSISTANT_ASYNC'] = $this->asyncPrecedent;
        }

        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function taches(): AssistantTacheRepository
    {
        return static::getContainer()->get(AssistantTacheRepository::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL];
        $noms = [self::ENTREPRISE_NOM];
        $typeEmails = ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING];
        $typeNoms = ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING];

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            $typeEmails
        );
        foreach (['assistant_tache', 'assistant_conversation_contexte', 'assistant_message'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN assistant_conversation c ON t.conversation_id = c.id
                 JOIN entreprise e ON c.entreprise_id = e.id
                 WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                $typeNoms
            );
        }
        foreach (['assistant_conversation', 'assistant_parametres'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                $typeNoms
            );
        }
        $conn->executeStatement(
            'DELETE tc FROM token_consumption tc LEFT JOIN utilisateur u ON tc.proprietaire_id = u.id WHERE u.email IN (:emails)',
            ['emails' => $emails],
            $typeEmails
        );
        $conn->executeStatement(
            'DELETE r FROM roles_en_administration r
             JOIN invite i ON r.invite_id = i.id
             JOIN entreprise e ON i.entreprise_id = e.id
             WHERE e.nom IN (:noms)',
            ['noms' => $noms],
            $typeNoms
        );
        $conn->executeStatement(
            'DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email IN (:emails) OR e.nom IN (:noms)',
            ['emails' => $emails, 'noms' => $noms],
            $typeEmails + $typeNoms
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (:noms)', ['noms' => $noms], $typeNoms);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:emails)', ['emails' => $emails], $typeEmails);
        // Les signaux déposés par un test précédent réveilleraient un drainage
        // inattendu si un worker tournait par ailleurs.
        $conn->executeStatement('DELETE FROM messenger_messages');
    }

    /**
     * ⚠️ TOUT SE MANIPULE PAR IDENTIFIANT DANS CE FICHIER. KernelBrowser
     * redémarre le noyau à chaque requête : l'EntityManager change, et un objet
     * obtenu avant un envoi est détaché juste après. Ne garder que des entiers,
     * et relire depuis l'EntityManager courant, est la seule façon d'écrire des
     * scénarios à plusieurs requêtes sans se battre contre le détachement.
     *
     * @return array{entreprise: int, invite: int}
     */
    private function seed(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new Utilisateur();
        $user->setEmail(self::OWNER_EMAIL);
        $user->setNom('PHPUnit IARAFALE');
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $user->setPaidTokens(1_000_000);
        $em->persist($user);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-IARAFALE');
        $entreprise->setAdresse('1 rue de la Rafale');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-IARAFALE');
        $entreprise->setIdnat('IDNAT-IARAFALE');
        $entreprise->setNumimpot('IMP-IARAFALE');
        $entreprise->setUtilisateur($user);
        $em->persist($entreprise);
        $user->setConnectedTo($entreprise);

        $invite = new Invite();
        $invite->setNom('Administrateur');
        $invite->setUtilisateur($user);
        $invite->setEntreprise($entreprise);
        $invite->setProprietaire(true);
        $em->persist($invite);

        $roleIa = new RolesEnAdministration();
        $roleIa->setNom('Rôle module IA');
        $roleIa->setAccessAssistantIa([Invite::ACCESS_LECTURE]);
        $roleIa->setEntreprise($entreprise);
        $invite->addRolesEnAdministration($roleIa);
        $em->persist($roleIa);

        $em->flush();

        $this->client->loginUser($user);

        return ['entreprise' => (int) $entreprise->getId(), 'invite' => (int) $invite->getId()];
    }

    /** @param array{entreprise: int, invite: int} $seed */
    private function conversation(array $seed): int
    {
        $em = $this->em();
        $conversation = (new AssistantConversation())
            ->setEntreprise($em->getReference(Entreprise::class, $seed['entreprise']))
            ->setInvite($em->getReference(Invite::class, $seed['invite']));
        $em->persist($conversation);
        $em->flush();

        return (int) $conversation->getId();
    }

    private function envoyer(int $idEntreprise, int $idConversation, string $contenu): array
    {
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/messages/%d/%d', $idEntreprise, $idConversation),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['contenu' => $contenu])
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?: [];
    }

    private function etat(int $idEntreprise, int $idConversation, ?int $depuis = null): array
    {
        $url = sprintf('/admin/assistant-ia/api/messages/%d/%d/etat', $idEntreprise, $idConversation);
        if ($depuis !== null) {
            $url .= '?depuis=' . $depuis;
        }
        $this->client->request('GET', $url);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?: [];
    }

    /** Ce que ferait `messenger:consume` en prenant le signal. */
    private function drainer(int $idConversation): void
    {
        $handler = static::getContainer()->get(TraiterMessagesAssistantHandler::class);
        $handler(new TraiterMessagesAssistant($idConversation));
    }

    /**
     * @return list<AssistantTache>
     */
    private function tachesDe(int $idConversation): array
    {
        return $this->taches()->findBy(['conversation' => $idConversation], ['id' => 'ASC']);
    }

    private function relire(int $idConversation): AssistantConversation
    {
        $conversation = $this->em()->find(AssistantConversation::class, $idConversation);
        self::assertNotNull($conversation, 'La conversation doit exister.');

        return $conversation;
    }

    /**
     * Le fil, réduit à ce qui est comparable entre deux exécutions : la suite des
     * rôles et des contenus. Les identifiants et les horodatages diffèrent par
     * construction, ils ne disent rien du produit.
     *
     * @return list<array{role: string, contenu: string}>
     */
    private function fil(int $idConversation): array
    {
        $conversation = $this->relire($idConversation);
        $this->em()->refresh($conversation);

        return array_values(array_map(
            static fn (AssistantMessage $m) => ['role' => $m->getRole(), 'contenu' => (string) $m->getContenu()],
            $conversation->getMessages()->toArray()
        ));
    }

    /**
     * L'ACCEPTATION. Trois envois d'affilée sont tous acceptés, immédiatement, et
     * aucun n'est traité — c'est exactement ce que le drapeau `sending` du
     * navigateur empêchait, en jetant les deuxième et troisième en silence.
     */
    public function testUneRafaleEstAccepteeEnEntierSansRienTraiter(): void
    {
        $seed = $this->seed();
        $conversation = $this->conversation($seed);

        foreach (['Première question.', 'Deuxième question.', 'Troisième question.'] as $question) {
            $reponse = $this->envoyer($seed['entreprise'], $conversation, $question);

            self::assertResponseStatusCodeSame(202, "Chaque question doit être acceptée sur-le-champ.");
            self::assertSame(['user', 'tache', 'conversationTitre'], array_keys($reponse));
            self::assertSame($question, $reponse['user']['contenu'], "L'accusé rend la question telle qu'acceptée.");
            // Nul, et volontairement : la question n'entrera dans le fil qu'au
            // drainage, pour que son identifiant se place juste avant celui de sa
            // réponse. C'est l'identifiant de TÂCHE qui sert de prise en attendant.
            self::assertNull($reponse['user']['id']);
            self::assertNotNull($reponse['tache']['id']);
            self::assertSame(AssistantTache::STATUT_EN_ATTENTE, $reponse['tache']['statut']);
            self::assertNull($reponse['tache']['assistant'], "Aucune réponse ne peut exister à ce stade.");
        }

        $taches = $this->tachesDe($conversation);
        self::assertCount(3, $taches, 'Les trois questions attendent leur tour.');
        foreach ($taches as $tache) {
            self::assertSame(AssistantTache::STATUT_EN_ATTENTE, $tache->getStatut());
        }

        self::assertSame(
            [],
            $this->fil($conversation),
            "Le fil reste VIDE : rien n'y entre avant son tour, ni question ni réponse."
        );
    }

    /**
     * LE DRAINAGE. Un seul signal suffit à répondre aux trois, dans l'ordre où
     * elles ont été acceptées — donc dans l'ordre où l'utilisateur a tapé.
     */
    public function testLeDrainageRepondAuxTroisDansLOrdre(): void
    {
        $seed = $this->seed();
        $conversation = $this->conversation($seed);

        foreach (['Première question.', 'Deuxième question.', 'Troisième question.'] as $question) {
            $this->envoyer($seed['entreprise'], $conversation, $question);
        }

        $this->drainer($conversation);

        $fil = $this->fil($conversation);
        self::assertSame(
            [
                AssistantMessage::ROLE_USER, AssistantMessage::ROLE_ASSISTANT,
                AssistantMessage::ROLE_USER, AssistantMessage::ROLE_ASSISTANT,
                AssistantMessage::ROLE_USER, AssistantMessage::ROLE_ASSISTANT,
            ],
            array_column($fil, 'role'),
            'Question, réponse, question, réponse… : une seule chose se traite à la fois.'
        );
        self::assertSame('Première question.', $fil[0]['contenu']);
        self::assertSame('Deuxième question.', $fil[2]['contenu']);
        self::assertSame('Troisième question.', $fil[4]['contenu']);

        foreach ($this->tachesDe($conversation) as $tache) {
            self::assertSame(AssistantTache::STATUT_TERMINEE, $tache->getStatut());
            self::assertNotNull($tache->getMessageAssistant(), 'Une tâche terminée porte sa réponse.');
            self::assertNull($tache->getEtape(), "L'étape courante n'a plus de sens une fois la réponse rendue.");
        }

        self::assertNull(
            $this->taches()->prochaineEnAttente($conversation),
            'La file est vide.'
        );
    }

    /**
     * ⭐ LE TEST QUI PROUVE « AUCUN CHANGEMENT DE LOGIQUE MÉTIER ».
     *
     * Deux conversations, les mêmes trois questions, le même moteur. L'une reçoit
     * la rafale puis un seul drainage ; l'autre est traitée question par question,
     * comme avant la refonte. Les deux fils doivent être identiques.
     */
    public function testUneRafaleProduitLeMemeFilQueTroisEnvoisSequentiels(): void
    {
        $seed = $this->seed();
        $questions = ['Combien de clients ai-je ?', 'Et combien de polices ?', 'Merci.'];

        $enRafale = $this->conversation($seed);
        foreach ($questions as $question) {
            $this->envoyer($seed['entreprise'], $enRafale, $question);
        }
        $this->drainer($enRafale);

        $sequentielle = $this->conversation($seed);
        foreach ($questions as $question) {
            $this->envoyer($seed['entreprise'], $sequentielle, $question);
            $this->drainer($sequentielle);
        }

        self::assertSame(
            $this->fil($sequentielle),
            $this->fil($enRafale),
            'Une rafale doit être indiscernable de trois envois séquentiels.'
        );
        self::assertSame(
            $this->relire($sequentielle)->getTitre(),
            $this->relire($enRafale)->getTitre(),
            'Le titre vient du PREMIER message dans les deux cas.'
        );
    }

    /**
     * LE VERROU. Tant qu'un drainage court, un second signal ne fait rien — et
     * surtout ne double pas les réponses. C'est la garantie qui permet de faire
     * tourner plusieurs workers sans réfléchir.
     */
    public function testUnVerrouDejaPrisEmpecheUnSecondDrainage(): void
    {
        $seed = $this->seed();
        $conversation = $this->conversation($seed);
        $this->envoyer($seed['entreprise'], $conversation, 'Une question.');

        $verrou = static::getContainer()->get(VerrouDeConversation::class);
        self::assertTrue($verrou->prendre($conversation), 'Le verrou est libre au départ.');
        self::assertFalse(
            $verrou->prendre($conversation),
            'Deux prises concurrentes ne peuvent pas réussir toutes les deux.'
        );

        $this->drainer($conversation);

        self::assertNotNull(
            $this->taches()->prochaineEnAttente($conversation),
            "Le drainage s'est retiré : la question attend toujours."
        );
        self::assertCount(0, $this->fil($conversation), "Le fil n'a pas bougé d'un message.");

        $verrou->relacher($conversation);
        $this->drainer($conversation);

        self::assertNull(
            $this->taches()->prochaineEnAttente($conversation),
            'Une fois le verrou relâché, la file se vide.'
        );
        self::assertCount(2, $this->fil($conversation));
    }

    /**
     * Le verrou se relâche AUSSI quand il n'y avait rien à faire : un signal reçu
     * pour une conversation déjà vidée ne doit pas la geler jusqu'à péremption.
     */
    public function testUnDrainageSansTravailRelacheLeVerrou(): void
    {
        $seed = $this->seed();
        $conversation = $this->conversation($seed);

        $this->drainer($conversation);

        $verrou = static::getContainer()->get(VerrouDeConversation::class);
        self::assertTrue(
            $verrou->prendre($conversation),
            'Le verrou doit être libre après un drainage à vide.'
        );
        $verrou->relacher($conversation);
    }

    /**
     * L'ENDPOINT D'ÉTAT. C'est par lui que le navigateur suit un traitement
     * parti en tâche de fond — et qu'il recolle son fil après un rechargement.
     */
    public function testLEtatDecritLaFileAvantEtApresLeDrainage(): void
    {
        $seed = $this->seed();
        $conversation = $this->conversation($seed);
        $this->envoyer($seed['entreprise'], $conversation, 'Une question suivie.');

        $etat = $this->etat($seed['entreprise'], $conversation);
        self::assertTrue($etat['enCours'], "Le navigateur apprend qu'il doit continuer à scruter.");
        self::assertCount(1, $etat['taches']);
        self::assertSame(AssistantTache::STATUT_EN_ATTENTE, $etat['taches'][0]['statut']);
        self::assertSame('Une question suivie.', $etat['taches'][0]['user']['contenu']);
        self::assertNull($etat['taches'][0]['assistant']);

        $this->drainer($conversation);

        $etat = $this->etat($seed['entreprise'], $conversation);
        self::assertFalse($etat['enCours'], 'La file est vide : le scrutin doit cesser.');
        self::assertSame([], $etat['taches'], 'Rien de neuf depuis la dernière synchronisation.');

        // En repartant de zéro, la tâche conclue est renvoyée AVEC sa réponse :
        // c'est ce qui permet de recoller le fil après un F5.
        $etat = $this->etat($seed['entreprise'], $conversation, 0);
        self::assertCount(1, $etat['taches']);
        self::assertSame(AssistantTache::STATUT_TERMINEE, $etat['taches'][0]['statut']);
        self::assertNotNull($etat['taches'][0]['user']['id'], 'La question est désormais dans le fil.');
        self::assertNotNull($etat['taches'][0]['assistant']['contenu']);
        self::assertFalse($etat['taches'][0]['assistant']['refus']);
    }

    /**
     * FAIL-CLOSED. Tant qu'une question attend sa réponse, aucune décision ne se
     * prend sur ce fil. Le verrou de session garantissait cela implicitement tant
     * que tout tournait dans le même processus ; le worker l'a fait disparaître,
     * on le rend donc explicite.
     */
    public function testAucuneDecisionNEstPossiblePendantUnTraitement(): void
    {
        $seed = $this->seed();
        $conversation = $this->conversation($seed);
        $this->envoyer($seed['entreprise'], $conversation, 'Une question en cours.');

        $routes = [
            '/admin/assistant-ia/api/mutation/%d/%d/1/execute',
            '/admin/assistant-ia/api/mutation/%d/%d/1/cancel',
            '/admin/assistant-ia/api/document/%d/%d/1/produire',
            '/admin/assistant-ia/api/document/%d/%d/1/cancel',
            '/admin/assistant-ia/api/programme/%d/%d/1/interrompre',
            '/admin/assistant-ia/api/programme/%d/%d/1/corriger',
        ];
        foreach ($routes as $route) {
            $this->client->request(
                'POST',
                sprintf($route, $seed['entreprise'], $conversation),
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                '{}'
            );
            self::assertResponseStatusCodeSame(409, "Refus attendu sur {$route}.");
            $corps = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertTrue($corps['occupe'], "Le refus doit se DIRE, pas se deviner.");
        }
    }

    /**
     * UN WORKER TUÉ NET NE GÈLE PAS LA CONVERSATION POUR TOUJOURS. Sans
     * péremption, un déploiement au mauvais moment laisserait le verrou posé et
     * l'utilisateur n'aurait aucun moyen de s'en sortir — Ket cesserait
     * simplement de lui répondre, sans rien dire.
     */
    public function testUnVerrouPerimeEstRepris(): void
    {
        $seed = $this->seed();
        $conversation = $this->conversation($seed);
        $this->envoyer($seed['entreprise'], $conversation, 'Une question orpheline.');

        $verrou = static::getContainer()->get(VerrouDeConversation::class);
        self::assertTrue($verrou->prendre($conversation));

        // On simule le worker disparu : le verrou a été posé il y a plus
        // longtemps que sa durée de vie.
        $vieux = (new \DateTimeImmutable())
            ->sub(new \DateInterval('PT' . (VerrouDeConversation::EXPIRATION_SECONDES + 60) . 'S'));
        $this->em()->getConnection()->update(
            'assistant_conversation',
            ['traitement_depuis' => $vieux->format('Y-m-d H:i:s')],
            ['id' => $conversation],
        );

        $this->drainer($conversation);

        self::assertNull(
            $this->taches()->prochaineEnAttente($conversation),
            'Un verrou périmé se reprend, et la question finit par obtenir sa réponse.'
        );
        self::assertCount(2, $this->fil($conversation));
    }

    /**
     * DEUX CONVERSATIONS NE SE BLOQUENT PAS. Aucun invariant métier ne les relie :
     * le verrou est par conversation, jamais global.
     */
    public function testLeVerrouEstParConversationEtNonGlobal(): void
    {
        $seed = $this->seed();
        $premiere = $this->conversation($seed);
        $seconde = $this->conversation($seed);

        $this->envoyer($seed['entreprise'], $premiere, 'Question du premier fil.');
        $this->envoyer($seed['entreprise'], $seconde, 'Question du second fil.');

        $verrou = static::getContainer()->get(VerrouDeConversation::class);
        $verrou->prendre($premiere);

        $this->drainer($seconde);

        self::assertCount(2, $this->fil($seconde), 'Le second fil avance malgré le verrou du premier.');
        self::assertCount(0, $this->fil($premiere), 'Le premier reste intact, sa question toujours en file.');

        $verrou->relacher($premiere);
    }

    /**
     * Une question acceptée pendant que le drainage tourne encore doit finir par
     * être traitée : le handler réémet le signal quand il constate, après avoir
     * relâché, qu'il reste du travail. Sans cela, cette question-là resterait
     * orpheline — son propre signal s'était retiré en no-op.
     */
    public function testUneTacheArriveeApresLaBoucleEstReprise(): void
    {
        $seed = $this->seed();
        $conversation = $this->conversation($seed);
        $this->envoyer($seed['entreprise'], $conversation, 'Question tardive.');

        // Le handler draine, ne trouve rien à faire côté boucle... puis constate
        // qu'il reste une tâche et réémet. Ici on observe la conséquence : la
        // question finit répondue, sans intervention extérieure.
        $this->drainer($conversation);

        self::assertNull($this->taches()->prochaineEnAttente($conversation));
        self::assertCount(2, $this->fil($conversation));
    }
}
