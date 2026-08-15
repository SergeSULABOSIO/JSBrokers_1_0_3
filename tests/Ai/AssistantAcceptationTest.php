<?php

namespace App\Tests\Ai;

use App\Entity\AssistantConversation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'ACCEPTATION D'UN MESSAGE — le contrat de la réponse d'envoi.
 *
 * Ce fichier s'appelait AssistantFluxEnvoiTest et défendait deux transports : une
 * réponse d'un bloc, et la même précédée d'un flux d'événements qui meublait les
 * vingt à quarante secondes d'attente. Le traitement étant passé en tâche de
 * fond, cette attente n'existe plus et le flux non plus — la progression se lit
 * sur l'endpoint d'état, qui survit au rechargement de page.
 *
 * Le test le plus précieux du fichier, lui, SURVIT INTACT :
 * testLaReponseEstUnJsonDUnSeulBloc protégeait les huit fichiers de tests qui
 * lisent un JSON d'un bloc ; il garde exactement le même rôle, en gardien du
 * chemin où le traitement a lieu pendant la requête (ASSISTANT_ASYNC=0).
 */
class AssistantAcceptationTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-iaaccept-owner@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit IAFLUX SARL';

    /** Type que le chat NOMME pour obtenir le fil (cf. AssistantIaController::FLUX_MIME). */
    private const FLUX_MIME = 'text/event-stream';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
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
        foreach (['assistant_conversation_contexte', 'assistant_message'] as $table) {
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
    }

    /** @return array{entreprise: Entreprise, conversation: AssistantConversation} */
    private function seed(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new Utilisateur();
        $user->setEmail(self::OWNER_EMAIL);
        $user->setNom('PHPUnit IAFLUX');
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $user->setPaidTokens(1_000_000);
        $em->persist($user);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-IAFLUX');
        $entreprise->setAdresse('1 rue du Flux');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-IAFLUX');
        $entreprise->setIdnat('IDNAT-IAFLUX');
        $entreprise->setNumimpot('IMP-IAFLUX');
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

        $conversation = (new AssistantConversation())
            ->setEntreprise($entreprise)
            ->setInvite($invite);
        $em->persist($conversation);
        $em->flush();

        $this->client->loginUser($user);

        return ['entreprise' => $entreprise, 'conversation' => $conversation];
    }

    private function envoyer(array $seed, string $contenu, array $enTetes = []): string
    {
        $this->client->request(
            'POST',
            sprintf(
                '/admin/assistant-ia/api/messages/%d/%d',
                $seed['entreprise']->getId(),
                $seed['conversation']->getId()
            ),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'] + $enTetes,
            json_encode(['contenu' => $contenu])
        );

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * LE test de non-régression, inchangé depuis le temps du flux. Il protégeait
     * les huit fichiers de tests qui lisent un JSON d'un seul bloc ; il garde ce
     * rôle exact pour le chemin où le traitement a lieu pendant la requête.
     */
    public function testLaReponseEstUnJsonDUnSeulBloc(): void
    {
        $seed = $this->seed();
        $corps = $this->envoyer($seed, 'Combien de clients ai-je ?');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'application/json',
            (string) $this->client->getResponse()->headers->get('Content-Type')
        );

        $data = json_decode($corps, true);
        self::assertSame(['user', 'assistant', 'conversationTitre'], array_keys($data));
        self::assertArrayHasKey('contenu', $data['assistant']);
        self::assertStringNotContainsString('data: ', $corps, "Le flux d'événements n'existe plus.");
    }

    /**
     * Le type d'événements n'a plus aucun effet. Un navigateur resté sur une
     * version ancienne de la page — celle qui NOMMAIT ce type pour obtenir le
     * fil — reçoit la même réponse JSON que les autres, plutôt qu'un flux vide
     * qu'il attendrait indéfiniment.
     */
    public function testLAncienEnTeteDeFluxNaPlusDEffet(): void
    {
        $seed = $this->seed();
        $corps = $this->envoyer($seed, 'Bonjour.', ['HTTP_ACCEPT' => 'text/event-stream']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'application/json',
            (string) $this->client->getResponse()->headers->get('Content-Type')
        );
        self::assertSame(['user', 'assistant', 'conversationTitre'], array_keys(json_decode($corps, true)));
    }

}
