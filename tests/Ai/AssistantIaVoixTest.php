<?php

namespace App\Tests\Ai;

use App\Ai\Debit\BudgetDebit;
use App\Ai\Voix\CacheAudio;
use App\Ai\Voix\SyntheseVocaleGemini;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Route de la voix de Ket : gardes, borne du texte, repli (503/402) et, avec une
 * synthèse factice, audio en flux mis en cache et facturé UNE fois — la réécoute est
 * servie par le cache, sans génération ni jeton.
 */
class AssistantIaVoixTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-voix-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-voix-guest@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Voix SARL';
    // Assez long pour coûter plus d'un jeton (prorata par tranche de 1 000 caractères).
    private const REPONSE = "Le taux de commission configuré pour la **Caution** est de 15 %. Cette couverture permet à l'entreprise de se porter garante de l'exécution de ses obligations contractuelles envers un bénéficiaire, notamment dans les marchés publics, sans immobiliser sa trésorerie. Elle est adaptée aux entreprises du bâtiment et des travaux publics qui soumissionnent régulièrement.";
    private const TEXTE_ORAL = "Le taux de commission configuré pour la Caution est de 15 %. Cette couverture permet à l'entreprise de se porter garante de l'exécution de ses obligations contractuelles envers un bénéficiaire, notamment dans les marchés publics, sans immobiliser sa trésorerie. Elle est adaptée aux entreprises du bâtiment et des travaux publics qui soumissionnent régulièrement.";

    private KernelBrowser $client;
    private string $racineCache;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->racineCache = sys_get_temp_dir() . '/ket-voix-web-' . bin2hex(random_bytes(4));
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        foreach (glob($this->racineCache . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->racineCache . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->racineCache);
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL];
        $types = ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING];
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)', ['emails' => $emails], $types);
        $conn->executeStatement(
            'DELETE tc FROM token_consumption tc JOIN utilisateur u ON tc.proprietaire_id = u.id WHERE u.email IN (:emails)',
            ['emails' => $emails],
            $types,
        );
        $conn->executeStatement(
            'DELETE m FROM assistant_message m JOIN assistant_conversation c ON m.conversation_id = c.id
             JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach (['assistant_conversation', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:emails)', ['emails' => $emails], $types);
    }

    /** @return array{entreprise: Entreprise, owner: Utilisateur, guest: Utilisateur, conversation: AssistantConversation, reponse: AssistantMessage, question: AssistantMessage} */
    private function semer(int $tokensPayants = 1_000_000): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Voix')->setVerified(true)->setPassword('x');
        $owner->setPaidTokens($tokensPayants);
        $owner->setFreeTokens(0);
        $owner->setFreeWindowStartedAt(new \DateTimeImmutable());
        $em->persist($owner);

        $e = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $e->setUtilisateur($owner);
        $em->persist($e);
        $owner->setConnectedTo($e);

        $invite = (new Invite())->setNom('Propriétaire')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($e);
        $em->persist($invite);

        $guest = (new Utilisateur())->setEmail(self::GUEST_EMAIL)->setNom('Sans IA')->setVerified(true)->setPassword('x');
        $guest->setConnectedTo($e);
        $em->persist($guest);
        $sansIa = (new Invite())->setNom('Sans IA')->setProprietaire(false);
        $sansIa->setUtilisateur($guest)->setEntreprise($e);
        $em->persist($sansIa);

        $conversation = (new AssistantConversation())->setEntreprise($e)->setInvite($invite);
        $em->persist($conversation);
        $question = (new AssistantMessage())->setConversation($conversation)->setRole(AssistantMessage::ROLE_USER)->setContenu('Taux de la Caution ?');
        $reponse = (new AssistantMessage())->setConversation($conversation)->setRole(AssistantMessage::ROLE_ASSISTANT)->setContenu(self::REPONSE);
        $conversation->addMessage($question)->addMessage($reponse);
        $em->persist($question);
        $em->persist($reponse);
        $em->flush();

        return compact('owner', 'guest', 'conversation', 'reponse', 'question') + ['entreprise' => $e];
    }

    /** Remplace la synthèse et le cache du conteneur par des doublures déterministes. */
    private function doublures(?MockHttpClient $http = null, string $moteur = ''): void
    {
        $conteneur = static::getContainer();
        $conteneur->set(CacheAudio::class, new CacheAudio($this->racineCache));
        if ($http !== null) {
            $conteneur->set(SyntheseVocaleGemini::class, new SyntheseVocaleGemini(
                $http,
                new BudgetDebit(new ArrayAdapter()),
                new ArrayAdapter(),
                new NullLogger(),
                'cle-test',
                'modele-test',
                'Aoede',
                $moteur,
            ));
        }
    }

    private function poster(Entreprise $e, AssistantConversation $c, AssistantMessage $m, string $texte): void
    {
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/messages/%d/%d/%d/voix', $e->getId(), $c->getId(), $m->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['texte' => $texte]),
        );
    }

    private function lignesVoix(): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM token_consumption tc JOIN utilisateur u ON tc.proprietaire_id = u.id
             WHERE u.email = :email AND tc.entite_nom = :entite',
            ['email' => self::OWNER_EMAIL, 'entite' => TokenAccountService::ENTITE_VOIX_IA],
        );
    }

    public function testMoteurSimuleRenvoieLeRepli(): void
    {
        ['entreprise' => $e, 'owner' => $owner, 'conversation' => $c, 'reponse' => $m] = $this->semer();
        $this->client->loginUser($owner);
        $this->doublures();

        $this->poster($e, $c, $m, self::TEXTE_ORAL);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(['repli' => SyntheseVocaleGemini::INDISPONIBLE], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame(0, $this->lignesVoix());
    }

    public function testFluxPuisReecouteDepuisLeCacheFactureUneSeuleFois(): void
    {
        ['entreprise' => $e, 'owner' => $owner, 'conversation' => $c, 'reponse' => $m] = $this->semer();
        $this->client->loginUser($owner);
        // Même conteneur pour les deux requêtes : les doublures (et le cache) survivent.
        $this->client->disableReboot();
        $pcm ="\x01\x00\x02\x00\x03\x00";
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse([
            'data: ' . json_encode(['candidates' => [['content' => ['parts' => [['inlineData' => ['data' => base64_encode($pcm)]]]]]]]) . "\n\n",
        ]));
        $this->doublures($http);

        $this->poster($e, $c, $m, self::TEXTE_ORAL);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('audio/L16', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame($pcm, $this->client->getInternalResponse()->getContent());
        self::assertSame(1, $this->lignesVoix(), 'première génération facturée');

        // Réécoute : servie par le cache, sans nouvel appel ni nouveau débit.
        $this->poster($e, $c, $m, self::TEXTE_ORAL);
        self::assertResponseIsSuccessful();
        self::assertSame('audio/wav', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(44 + \strlen($pcm), \strlen((string) $this->client->getResponse()->getContent()));
        self::assertSame(1, $http->getRequestsCount(), 'aucune nouvelle génération');
        self::assertSame(1, $this->lignesVoix(), 'aucun nouveau débit');
    }

    public function testUnTexteQuiNEstPasLaReponseEstRefuse(): void
    {
        ['entreprise' => $e, 'owner' => $owner, 'conversation' => $c, 'reponse' => $m] = $this->semer();
        $this->client->loginUser($owner);
        $this->doublures();

        $this->poster($e, $c, $m, self::TEXTE_ORAL . str_repeat(' Et encore autre chose à faire lire.', 5));

        self::assertResponseStatusCodeSame(400);
    }

    public function testUneQuestionDeLUtilisateurNeSeLitPas(): void
    {
        ['entreprise' => $e, 'owner' => $owner, 'conversation' => $c, 'question' => $q] = $this->semer();
        $this->client->loginUser($owner);
        $this->doublures();

        $this->poster($e, $c, $q, 'Taux');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSoldeInsuffisant402(): void
    {
        ['entreprise' => $e, 'owner' => $owner, 'conversation' => $c, 'reponse' => $m] = $this->semer(1);
        $this->client->loginUser($owner);
        $this->doublures(new MockHttpClient([]));

        $this->poster($e, $c, $m, self::TEXTE_ORAL);

        self::assertResponseStatusCodeSame(402);
    }

    public function testSansModuleIa403(): void
    {
        ['entreprise' => $e, 'guest' => $guest, 'conversation' => $c, 'reponse' => $m] = $this->semer();
        $this->client->loginUser($guest);
        $this->doublures();

        $this->poster($e, $c, $m, self::TEXTE_ORAL);

        self::assertResponseStatusCodeSame(403);
    }
}
