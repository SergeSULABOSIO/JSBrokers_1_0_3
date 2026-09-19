<?php

namespace App\Tests\Ai;

use App\Ai\Debit\BudgetDebit;
use App\Ai\Live\IntermedesDeKet;
use App\Ai\Oreille\OreilleDeKet;
use App\Ai\Oreille\Transcription;
use App\Ai\Oreille\TranscriptionElevenLabs;
use App\Ai\Oreille\TranscriptionGemini;
use App\Ai\Voix\CacheAudio;
use App\Ai\Voix\FournisseurDeVoix;
use App\Ai\Voix\MemoireDEpuisement;
use App\Ai\Voix\SyntheseVocaleElevenLabs;
use App\Ai\Voix\VoixDeKet;
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
 * MODE LIVE — les deux routes de la nouvelle couche : entendre une phrase (oreilles) et
 * dire un intermède pendant que Ket réfléchit.
 *
 * Le moteur de Ket n'est pas sollicité ici : une fois la parole transcrite, le texte
 * repart par le circuit ORDINAIRE d'un message, déjà couvert par ses propres tests.
 */
class AssistantIaLiveTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-live-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-live-guest@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Live SARL';

    private KernelBrowser $client;
    private string $racineCache;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->racineCache = sys_get_temp_dir() . '/ket-live-web-' . bin2hex(random_bytes(4));
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
            'DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:emails)', ['emails' => $emails], $types);
    }

    /** @return array{entreprise: Entreprise, owner: Utilisateur, guest: Utilisateur} */
    private function semer(int $tokensPayants = 1_000_000): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Live')->setVerified(true)->setPassword('x');
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

        $em->flush();

        return ['entreprise' => $e, 'owner' => $owner, 'guest' => $guest];
    }

    /** ~1 seconde de WAV 16 kHz mono : en-tête de 44 octets et des échantillons. */
    private static function wav(float $secondes = 1.0): string
    {
        return CacheAudio::wav(str_repeat("\x10\x00", (int) (16000 * $secondes)), 16000);
    }

    /**
     * @param list<\App\Ai\Oreille\FournisseurDOreille> $oreilles
     * @param list<FournisseurDeVoix>                   $voix
     */
    private function doublures(array $oreilles = [], array $voix = []): void
    {
        $conteneur = static::getContainer();
        $conteneur->set(CacheAudio::class, new CacheAudio($this->racineCache));
        $conteneur->set(OreilleDeKet::class, new OreilleDeKet($oreilles, 'elevenlabs,gemini'));
        $conteneur->set(VoixDeKet::class, new VoixDeKet($voix, 'elevenlabs,gemini'));
    }

    private function oreilleElevenLabs(MockHttpClient $http): TranscriptionElevenLabs
    {
        return new TranscriptionElevenLabs($http, new MemoireDEpuisement(new ArrayAdapter()), new NullLogger(), 'xi-test', 'scribe_v2');
    }

    private function oreilleGemini(MockHttpClient $http): TranscriptionGemini
    {
        return new TranscriptionGemini($http, new BudgetDebit(new ArrayAdapter()), new MemoireDEpuisement(new ArrayAdapter()), new NullLogger(), 'gm-test', 'modele-oreille');
    }

    private function voixElevenLabs(MockHttpClient $http): SyntheseVocaleElevenLabs
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

    private function transcrire(Entreprise $e, string $wav): array
    {
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/live/%d/transcrire', $e->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'audio/wav'],
            $wav,
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function intermede(Entreprise $e, string $cle): void
    {
        $this->client->request('GET', sprintf('/admin/assistant-ia/api/live/%d/intermede/%s', $e->getId(), $cle));
    }

    private function lignes(string $entite): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM token_consumption tc JOIN utilisateur u ON tc.proprietaire_id = u.id
             WHERE u.email = :email AND tc.entite_nom = :entite',
            ['email' => self::OWNER_EMAIL, 'entite' => $entite],
        );
    }

    public function testLaParoleEstTranscriteEtFactureeUneFois(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->semer();
        $this->client->loginUser($owner);
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode(['text' => 'Quel est le taux de la Caution ?'])));
        $this->doublures([$this->oreilleElevenLabs($http)]);

        $data = $this->transcrire($e, self::wav(2.0));

        self::assertResponseIsSuccessful();
        self::assertSame('Quel est le taux de la Caution ?', $data['texte']);
        self::assertSame('elevenlabs', $data['fournisseur']);
        self::assertSame(1, $this->lignes(TokenAccountService::ENTITE_OREILLE_IA));
    }

    public function testElevenLabsEpuiseGeminiEntend(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->semer();
        $this->client->loginUser($owner);
        $eleven = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"detail":{"code":"quota_exceeded"}}', ['http_code' => 401]));
        $gemini = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'Liste mes clients.']]]]],
        ])));
        $this->doublures([$this->oreilleElevenLabs($eleven), $this->oreilleGemini($gemini)]);

        $data = $this->transcrire($e, self::wav());

        self::assertResponseIsSuccessful();
        self::assertSame(['texte' => 'Liste mes clients.', 'fournisseur' => 'gemini'], $data);
    }

    public function testAucuneOreilleRenvoieLeRepliVersLeNavigateur(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->semer();
        $this->client->loginUser($owner);
        $this->doublures();

        $data = $this->transcrire($e, self::wav());

        // 200, ET NON 503 : un quota épuisé est un cas prévu, doté d'un repli qui
        // marche. Le navigateur écrit en rouge toute réponse 5xx — à chaque phrase,
        // pendant toute une conversation —, et les vraies pannes s'y noyaient.
        self::assertResponseIsSuccessful();
        self::assertSame(['repli' => Transcription::INDISPONIBLE], $data);
        self::assertSame(0, $this->lignes(TokenAccountService::ENTITE_OREILLE_IA));
    }

    public function testUnSilenceNeCoutteRien(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->semer();
        $this->client->loginUser($owner);
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode(['text' => '  '])));
        $this->doublures([$this->oreilleElevenLabs($http)]);

        $data = $this->transcrire($e, self::wav());

        self::assertResponseIsSuccessful();
        self::assertSame('', $data['texte']);
        self::assertSame(0, $this->lignes(TokenAccountService::ENTITE_OREILLE_IA));
    }

    public function testParoleVideOuTropLongueRefusee(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->semer();
        $this->client->loginUser($owner);
        $this->doublures([$this->oreilleElevenLabs(new MockHttpClient([]))]);

        $this->transcrire($e, '');
        self::assertResponseStatusCodeSame(400);

        $this->transcrire($e, str_repeat('x', 2_000_001));
        self::assertResponseStatusCodeSame(400);
    }

    public function testSoldeInsuffisant402(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->semer(1);
        $this->client->loginUser($owner);
        $this->doublures([$this->oreilleElevenLabs(new MockHttpClient([]))]);

        $this->transcrire($e, self::wav(60.0));

        self::assertResponseStatusCodeSame(402);
    }

    public function testSansModuleIa403(): void
    {
        ['entreprise' => $e, 'guest' => $guest] = $this->semer();
        $this->client->loginUser($guest);
        $this->doublures([$this->oreilleElevenLabs(new MockHttpClient([]))]);

        $this->transcrire($e, self::wav());

        self::assertResponseStatusCodeSame(403);
    }

    // ── Intermèdes ──────────────────────────────────────────────────────────────

    public function testUnIntermedeEstGenereUneFoisPuisServiParLeCacheSansJeton(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->semer();
        $this->client->loginUser($owner);
        $this->client->disableReboot();
        $pcm = "\x01\x00\x02\x00";
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse([$pcm]));
        $this->doublures([], [$this->voixElevenLabs($http)]);

        $this->intermede($e, 'debut-1');
        self::assertResponseIsSuccessful();
        self::assertSame('audio/wav', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(44 + \strlen($pcm), \strlen((string) $this->client->getResponse()->getContent()));

        // Deuxième demande : servie par le cache commun, sans nouvelle génération.
        $this->intermede($e, 'debut-1');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $http->getRequestsCount());
        // Les intermèdes ne disent rien du cabinet : ils ne coûtent aucun jeton.
        self::assertSame(0, $this->lignes(TokenAccountService::ENTITE_VOIX_IA));
    }

    public function testUneCleInconnueEstIntrouvable(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->semer();
        $this->client->loginUser($owner);
        $this->doublures([], [$this->voixElevenLabs(new MockHttpClient([]))]);

        $this->intermede($e, 'debut-999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSansVoixLIntermedeRenvoieLeRepli(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->semer();
        $this->client->loginUser($owner);
        $this->doublures();

        $this->intermede($e, 'relance-1');

        // UN INTERMÈDE SANS VOIX EST UN SILENCE. Il est chargé par un élément <audio> :
        // lui répondre du JSON le ferait échouer, et le navigateur s'en plaindrait à
        // l'écran. Cent millisecondes de silence ne s'entendent pas et ne coûtent rien.
        self::assertResponseIsSuccessful();
        self::assertSame('audio/wav', $this->client->getResponse()->headers->get('Content-Type'));
        $wav = (string) $this->client->getResponse()->getContent();
        self::assertSame('RIFF', substr($wav, 0, 4));
        self::assertSame(str_repeat(" ", \strlen($wav) - 44), substr($wav, 44), 'du silence, et rien d’autre');
    }

    public function testLeCatalogueDesIntermedesEstUtilisable(): void
    {
        $catalogue = IntermedesDeKet::catalogue();

        self::assertArrayHasKey(IntermedesDeKet::DEBUT, $catalogue);
        self::assertArrayHasKey(IntermedesDeKet::RELANCE, $catalogue);
        $cles = [];
        foreach ($catalogue as $moment => $phrases) {
            self::assertGreaterThanOrEqual(3, \count($phrases), sprintf('le moment « %s » doit offrir du choix', $moment));
            foreach ($phrases as $cle => $phrase) {
                self::assertMatchesRegularExpression('/^[a-z]+-[0-9]+$/', $cle, 'la clé sert d’URL et de nom de fichier');
                self::assertNotSame('', trim($phrase));
                self::assertSame($phrase, IntermedesDeKet::phrase($cle));
                $cles[] = $cle;
            }
        }
        self::assertSame($cles, array_unique($cles), 'les clés sont uniques');
        self::assertNull(IntermedesDeKet::phrase('debut-999'));
    }
}
