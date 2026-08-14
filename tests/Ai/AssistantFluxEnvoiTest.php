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
 * LES DEUX TRANSPORTS d'un même envoi de message.
 *
 * Le chat restait aveugle entre le clic et la réponse : un fetch bloquant de vingt à
 * quarante secondes, et un « Ket réfléchit… » figé pendant que trois appels au modèle
 * s'enchaînaient. Le fil d'activité comble ce silence — mais il ne devait rien coûter
 * à l'existant.
 *
 * Ces tests verrouillent donc les deux moitiés du contrat :
 *  - SANS l'en-tête, la réponse est exactement celle d'avant. C'est ce test-là qui
 *    protège les huit fichiers de tests qui lisent un JSON d'un seul bloc ;
 *  - AVEC l'en-tête, le même contenu arrive, précédé d'événements — et surtout la
 *    charge utile finale est identique clé pour clé, sans quoi les deux chemins
 *    divergeraient en silence.
 */
class AssistantFluxEnvoiTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-iaflux-owner@test.local';
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

        // getInternalResponse(), et non getResponse() : une StreamedResponse n'a pas
        // de contenu en propre — le navigateur de test l'obtient en encadrant
        // sendContent() d'un tampon de sortie. C'est aussi pourquoi le contrôleur
        // ne doit jamais FERMER ce tampon (ob_flush, jamais ob_end_flush).
        return (string) $this->client->getInternalResponse()->getContent();
    }

    /**
     * LE test de non-régression. Un client qui ne demande rien reçoit exactement ce
     * qu'il recevait avant : même statut, même structure, même type.
     */
    public function testSansEnTeteLaReponseResteUnJsonDUnSeulBloc(): void
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
        self::assertStringNotContainsString('data: ', $corps, 'Aucun événement ne doit polluer ce chemin.');
    }

    public function testAvecLEnTeteLaReponseDevientUnFluxDEvenements(): void
    {
        $seed = $this->seed();
        $corps = $this->envoyer($seed, 'Combien de clients ai-je ?', ['HTTP_ACCEPT' => self::FLUX_MIME]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            'text/event-stream; charset=utf-8',
            $this->client->getResponse()->headers->get('Content-Type')
        );
        // Sans lui, nginx retiendrait le flux et la fonctionnalité n'existerait plus.
        self::assertSame('no', $this->client->getResponse()->headers->get('X-Accel-Buffering'));

        $evenements = $this->evenements($corps);
        self::assertNotSame([], $evenements, 'Le flux doit au moins porter sa conclusion.');

        $fin = end($evenements);
        self::assertSame('fin', $fin['type'], 'Le dernier événement conclut toujours le message.');
        self::assertSame(200, $fin['statut'], 'Le statut voyage DANS le flux, les en-têtes étant déjà partis.');
        self::assertArrayHasKey('assistant', $fin['donnees']);
    }

    /**
     * Le point qui compte vraiment : deux transports, une seule vérité. Si les
     * charges utiles divergeaient, le chat afficherait deux choses différentes
     * selon qu'il a demandé le fil ou non.
     */
    public function testLesDeuxTransportsRendentLaMemeChargeUtile(): void
    {
        $seed = $this->seed();

        $bloc = json_decode($this->envoyer($seed, 'Une première question.'), true);

        $evenements = $this->evenements($this->envoyer(
            $seed,
            'Une seconde question.',
            ['HTTP_ACCEPT' => self::FLUX_MIME]
        ));
        $flux = end($evenements)['donnees'];

        self::assertSame(array_keys($bloc), array_keys($flux));
        self::assertSame(array_keys($bloc['user']), array_keys($flux['user']));
        self::assertSame(array_keys($bloc['assistant']), array_keys($flux['assistant']));
    }

    /**
     * Le moteur des tests (AI_ENGINE=simulated) ne passe pas par le journal : aucune
     * étape n'est donc attendue ici. Ce test dit que c'est BIEN une absence propre —
     * pas d'événement bancal, pas de récapitulatif rempli de zéros.
     */
    public function testUnMoteurSansTelemetrieNAnnonceAucuneEtape(): void
    {
        $seed = $this->seed();
        $corps = $this->envoyer($seed, 'Bonjour.', ['HTTP_ACCEPT' => self::FLUX_MIME]);

        $evenements = $this->evenements($corps);
        $types = array_column($evenements, 'type');

        self::assertSame(['fin'], $types, 'Sans télémétrie, le flux ne porte que sa conclusion.');
        // La clé reste présente — le front n'a pas à distinguer « absente » de
        // « vide » — mais elle est NULLE : mieux vaut ne rien montrer que montrer
        // des zéros. Dans meta, array_filter l'écarte purement et simplement.
        self::assertNull(end($evenements)['donnees']['assistant']['activite']);
    }

    /**
     * Chaque ligne « data: » du flux, décodée.
     *
     * @return list<array<string, mixed>>
     */
    private function evenements(string $corps): array
    {
        $evenements = [];
        foreach (explode("\n", $corps) as $ligne) {
            if (!str_starts_with($ligne, 'data: ')) {
                continue;
            }
            $decode = json_decode(substr($ligne, 6), true);
            self::assertIsArray($decode, 'Chaque ligne du flux doit être un JSON valide.');
            $evenements[] = $decode;
        }

        return $evenements;
    }
}
