<?php

namespace App\Tests\Ai;

use App\Ai\Debit\BudgetDebit;
use App\Ai\Voix\CacheAudio;
use App\Ai\Voix\FournisseurDeVoix;
use App\Ai\Voix\MemoireDEpuisement;
use App\Ai\Voix\SyntheseVocaleElevenLabs;
use App\Ai\Voix\SyntheseVocaleGemini;
use App\Ai\Voix\VoixDeKet;
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
 * Route de la voix de Ket : gardes, borne du texte, repli (503/402), ordre des voix
 * (ElevenLabs puis Gemini) et, avec des fournisseurs réels sur HTTP factice, audio en
 * flux mis en cache et facturé UNE fois — la réécoute est servie par le cache.
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

    /**
     * Remplace les voix et le cache du conteneur par des doublures déterministes. Sans
     * fournisseur : aucune voix disponible (cas du moteur simulé).
     *
     * @param list<FournisseurDeVoix> $fournisseurs
     */
    private function doublures(array $fournisseurs = []): void
    {
        $conteneur = static::getContainer();
        $conteneur->set(CacheAudio::class, new CacheAudio($this->racineCache));
        $conteneur->set(VoixDeKet::class, new VoixDeKet($fournisseurs, 'elevenlabs,gemini'));
    }

    /** Le vrai fournisseur ElevenLabs, sur un client HTTP factice. */
    private function elevenLabs(MockHttpClient $http): SyntheseVocaleElevenLabs
    {
        return new SyntheseVocaleElevenLabs(
            $http,
            new MemoireDEpuisement(new ArrayAdapter()),
            new NullLogger(),
            'xi-test',
            'voix-test',
            'eleven_multilingual_v2',
            'eleven_flash_v2_5',
        );
    }

    /** Le vrai fournisseur Gemini, sur un client HTTP factice. */
    private function gemini(MockHttpClient $http): SyntheseVocaleGemini
    {
        return new SyntheseVocaleGemini($http, new BudgetDebit(new ArrayAdapter()), new MemoireDEpuisement(new ArrayAdapter()), new NullLogger(), 'cle-test', 'modele-test', 'Aoede');
    }

    private static function sseGemini(string $pcm): string
    {
        return 'data: ' . json_encode(['candidates' => [['content' => ['parts' => [['inlineData' => ['data' => base64_encode($pcm)]]]]]]]) . "\n\n";
    }

    private static function quotaElevenLabs(): MockHttpClient
    {
        return new MockHttpClient(static fn (): MockResponse => new MockResponse('{"detail":{"status":"quota_exceeded"}}', ['http_code' => 401]));
    }

    private function poster(Entreprise $e, AssistantConversation $c, AssistantMessage $m, string $texte, bool $vitesse = false): void
    {
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/messages/%d/%d/%d/voix', $e->getId(), $c->getId(), $m->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['texte' => $texte, 'vitesse' => $vitesse]),
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

    public function testAucuneVoixDisponibleRenvoieLeRepli(): void
    {
        ['entreprise' => $e, 'owner' => $owner, 'conversation' => $c, 'reponse' => $m] = $this->semer();
        $this->client->loginUser($owner);
        $this->doublures();

        $this->poster($e, $c, $m, self::TEXTE_ORAL);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(['repli' => FournisseurDeVoix::INDISPONIBLE], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame(0, $this->lignesVoix());
    }

    public function testElevenLabsParleEnFluxPuisReecouteDepuisLeCacheFactureUneSeuleFois(): void
    {
        ['entreprise' => $e, 'owner' => $owner, 'conversation' => $c, 'reponse' => $m] = $this->semer();
        $this->client->loginUser($owner);
        // Même conteneur pour les deux requêtes : les doublures (et le cache) survivent.
        $this->client->disableReboot();
        $pcm = "\x01\x00\x02\x00\x03\x00";
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse([substr($pcm, 0, 3), substr($pcm, 3)]));
        $this->doublures([$this->elevenLabs($http)]);

        $this->poster($e, $c, $m, self::TEXTE_ORAL);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('audio/L16', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('elevenlabs', $this->client->getResponse()->headers->get('X-Ket-Voix'));
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

    /**
     * EN LIVE, LA VOIX RAPIDE — ET SON PROPRE ENREGISTREMENT. Le modèle fait partie de
     * l'identité de la voix : sans cela, la phrase lue en conversation et la même
     * phrase écoutée à l'écrit se partageraient une entrée de cache, et l'on entendrait
     * l'une à la place de l'autre.
     */
    public function testEnModeVitesseLeModeleRapideEstDemandeEtLEnregistrementEstDistinct(): void
    {
        ['entreprise' => $e, 'owner' => $owner, 'conversation' => $c, 'reponse' => $m] = $this->semer();
        $this->client->loginUser($owner);
        $this->client->disableReboot();
        $modeles = [];
        $http = new MockHttpClient(function (string $methode, string $url, array $options) use (&$modeles): MockResponse {
            $modeles[] = json_decode($options['body'], true)['model_id'] ?? null;

            return new MockResponse([" "]);
        });
        $this->doublures([$this->elevenLabs($http)]);

        $this->poster($e, $c, $m, self::TEXTE_ORAL, true);
        self::assertResponseIsSuccessful();
        $this->poster($e, $c, $m, self::TEXTE_ORAL, true);
        self::assertResponseIsSuccessful();
        self::assertSame(['eleven_flash_v2_5'], $modeles, 'la réécoute en Live vient du cache');

        // La même phrase à l'écrit : un autre modèle, donc une autre génération.
        $this->poster($e, $c, $m, self::TEXTE_ORAL);
        self::assertResponseIsSuccessful();
        self::assertSame(['eleven_flash_v2_5', 'eleven_multilingual_v2'], $modeles);
    }

    public function testElevenLabsEpuiseGeminiPrendLaMain(): void
    {
        ['entreprise' => $e, 'owner' => $owner, 'conversation' => $c, 'reponse' => $m] = $this->semer();
        $this->client->loginUser($owner);
        $eleven = self::quotaElevenLabs();
        $gemini = new MockHttpClient(static fn (): MockResponse => new MockResponse([self::sseGemini("\x07\x00")]));
        $this->doublures([$this->elevenLabs($eleven), $this->gemini($gemini)]);

        $this->poster($e, $c, $m, self::TEXTE_ORAL);

        self::assertResponseIsSuccessful();
        self::assertSame('gemini', $this->client->getResponse()->headers->get('X-Ket-Voix'));
        self::assertSame("\x07\x00", $this->client->getInternalResponse()->getContent());
        self::assertSame(1, $eleven->getRequestsCount());
        self::assertSame(1, $this->lignesVoix(), 'facturé une fois, quel que soit le fournisseur');
    }

    public function testToutesLesVoixEpuiseesRenvoientLeRepli(): void
    {
        ['entreprise' => $e, 'owner' => $owner, 'conversation' => $c, 'reponse' => $m] = $this->semer();
        $this->client->loginUser($owner);
        $this->doublures([
            $this->elevenLabs(self::quotaElevenLabs()),
            $this->gemini(new MockHttpClient(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 429]))),
        ]);

        $this->poster($e, $c, $m, self::TEXTE_ORAL);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(['repli' => FournisseurDeVoix::QUOTA], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame(0, $this->lignesVoix());
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
        $this->doublures([$this->elevenLabs(new MockHttpClient([]))]);

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
