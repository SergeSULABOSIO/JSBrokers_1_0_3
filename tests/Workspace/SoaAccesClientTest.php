<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Contact;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\SoaAccesToken;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels de l'accès client au SOA (relevé de compte) :
 *  - correctif de périmètre sur les routes SOA du workspace (client d'une autre
 *    entreprise → 404) ;
 *  - boîte de choix du destinataire (e-mail du client + contacts, état vide) ;
 *  - envoi par e-mail : jeton créé à J+30, e-mail parti avec le lien public,
 *    prolongation du même jeton au second envoi, refus d'une adresse hors liste ;
 *  - page publique tokenisée : vue épurée (sections courtier absentes), traçabilité
 *    (accessCount), réponse 404 STRICTEMENT identique pour jeton inconnu/expiré/révoqué.
 *
 * On agit en tant que PROPRIÉTAIRE de l'entreprise (bypass du contrôle d'accès) pour
 * isoler la logique testée. Chaque test crée ses données et les nettoie.
 */
class SoaAccesClientTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-soa-owner@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit SoaAcces SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit SoaAcces Autre SARL';

    private const CLI_NOM = 'PHPUNIT-SOA-CLIENT';
    private const CLI_EMAIL = 'phpunit-soa-client@test.local';
    private const CONTACT_NOM = 'PHPUNIT-SOA-CONTACT';
    private const CONTACT_EMAIL = 'phpunit-soa-contact@test.local';
    private const CLI_SANS_EMAIL_NOM = 'PHPUNIT-SOA-CLIENT-SANS-EMAIL';
    private const CLI_B_NOM = 'PHPUNIT-SOA-CLIENT-AUTRE-ENTREPRISE';

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

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_B_NOM];

        $conn->executeStatement(
            "UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e",
            ['e' => self::OWNER_EMAIL]
        );

        foreach ($noms as $nom) {
            // Ordre des FK : token → contact → client → invite → entreprise → utilisateur.
            $conn->executeStatement("DELETE t FROM soa_acces_token t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
            $conn->executeStatement("DELETE ct FROM contact ct JOIN client c ON ct.client_id = c.id JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
            $conn->executeStatement("DELETE c FROM client c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
            $conn->executeStatement("DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
            $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => $nom]);
        }
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :e", ['e' => self::OWNER_EMAIL]);
    }

    /**
     * Jeu de données : entreprise A (workspace courant du propriétaire) avec un client
     * complet (e-mail + contact avec e-mail) et un client sans aucune adresse ; et une
     * entreprise B (même propriétaire) avec un client hors workspace courant.
     *
     * @return array{entreprise: Entreprise, clientA: Client, clientSansEmail: Client, clientB: Client}
     */
    private function seed(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER_EMAIL);
        $ownerUser->setNom('PHPUnit Owner');
        $ownerUser->setVerified(true);
        $ownerUser->setPassword($hasher->hashPassword($ownerUser, self::PASSWORD));
        $em->persist($ownerUser);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-TEST');
        $entreprise->setAdresse('1 rue du Test');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-TEST');
        $entreprise->setIdnat('IDNAT-TEST');
        $entreprise->setNumimpot('IMP-TEST');
        $entreprise->setUtilisateur($ownerUser);
        $ownerUser->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $ownerInvite = new Invite();
        $ownerInvite->setNom('Administrateur');
        $ownerInvite->setUtilisateur($ownerUser);
        $ownerInvite->setEntreprise($entreprise);
        $ownerInvite->setProprietaire(true);
        $em->persist($ownerInvite);

        $entrepriseB = new Entreprise();
        $entrepriseB->setNom(self::ENTREPRISE_B_NOM);
        $entrepriseB->setLicence('LIC-TEST-B');
        $entrepriseB->setAdresse('2 rue du Test');
        $entrepriseB->setTelephone('+243000000001');
        $entrepriseB->setRccm('RCCM-TEST-B');
        $entrepriseB->setIdnat('IDNAT-TEST-B');
        $entrepriseB->setNumimpot('IMP-TEST-B');
        $entrepriseB->setUtilisateur($ownerUser);
        $em->persist($entrepriseB);

        $clientA = new Client();
        $clientA->setNom(self::CLI_NOM);
        $clientA->setEmail(self::CLI_EMAIL);
        $clientA->setExonere(false);
        $clientA->setEntreprise($entreprise);
        $em->persist($clientA);

        $contact = new Contact();
        $contact->setNom(self::CONTACT_NOM);
        $contact->setTelephone('+243000000002');
        $contact->setEmail(self::CONTACT_EMAIL);
        $contact->setFonction('Directeur financier');
        $contact->setType(2); // Administration
        $contact->setClient($clientA);
        $contact->setEntreprise($entreprise); // AuditableTrait : entreprise NOT NULL
        $em->persist($contact);

        $clientSansEmail = new Client();
        $clientSansEmail->setNom(self::CLI_SANS_EMAIL_NOM);
        $clientSansEmail->setExonere(false);
        $clientSansEmail->setEntreprise($entreprise);
        $em->persist($clientSansEmail);

        $clientB = new Client();
        $clientB->setNom(self::CLI_B_NOM);
        $clientB->setExonere(false);
        $clientB->setEntreprise($entrepriseB);
        $em->persist($clientB);

        $em->flush();

        // Le KernelBrowser partage l'EM du test : on recharge les entités pour que les
        // relations soient lues depuis la base comme dans une vraie requête.
        $ids = [
            'entreprise' => $entreprise->getId(),
            'clientA' => $clientA->getId(),
            'clientSansEmail' => $clientSansEmail->getId(),
            'clientB' => $clientB->getId(),
        ];
        $em->clear();

        return [
            'entreprise' => $em->getRepository(Entreprise::class)->find($ids['entreprise']),
            'clientA' => $em->getRepository(Client::class)->find($ids['clientA']),
            'clientSansEmail' => $em->getRepository(Client::class)->find($ids['clientSansEmail']),
            'clientB' => $em->getRepository(Client::class)->find($ids['clientB']),
        ];
    }

    /** POST JSON vers la route d'envoi du SOA. */
    private function postEnvoyer(int $clientId, array $payload): void
    {
        $this->client->request(
            'POST',
            sprintf('/admin/soa/api/client/%d/envoyer', $clientId),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Requested-With' => 'XMLHttpRequest'],
            json_encode($payload)
        );
    }

    // ── Correctif de périmètre sur les routes SOA existantes ─────────────────

    public function testSoaRoutesRefuseClientFromAnotherEntreprise(): void
    {
        ['clientB' => $clientB] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        foreach ([
            sprintf('/admin/soa/client/%d/apercu', $clientB->getId()),
            sprintf('/admin/soa/client/%d/workspace', $clientB->getId()),
            sprintf('/admin/soa/client/%d/envoi-picker', $clientB->getId()),
        ] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseStatusCodeSame(404, sprintf('%s doit refuser un client hors workspace.', $url));
        }

        $this->postEnvoyer($clientB->getId(), ['email' => self::CLI_EMAIL]);
        $this->assertResponseStatusCodeSame(404, "L'envoi doit refuser un client hors workspace.");
    }

    public function testSoaApercuStillWorksForOwnClient(): void
    {
        ['clientA' => $clientA] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf('/admin/soa/client/%d/apercu', $clientA->getId()));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString(self::CLI_NOM, $html);
        // Vue courtier complète : les sections internes sont bien présentes.
        $this->assertStringContainsString('soa-section-pistes', $html);
        $this->assertStringContainsString('soa-section-taches', $html);
        $this->assertStringContainsString('soa-section-solvabilite', $html);
    }

    // ── Boîte de choix du destinataire ────────────────────────────────────────

    public function testEnvoiPickerListsClientAndContactEmails(): void
    {
        ['clientA' => $clientA] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf('/admin/soa/client/%d/envoi-picker', $clientA->getId()));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString(self::CLI_EMAIL, $html, "L'e-mail du client doit être proposé.");
        $this->assertStringContainsString(self::CONTACT_EMAIL, $html, "L'e-mail du contact doit être proposé.");
        $this->assertStringContainsString('Directeur financier', $html, 'La fonction du contact contextualise le choix.');
        $this->assertStringContainsString('data-picker-send', $html, "Le bouton d'envoi doit être présent.");
        $this->assertStringContainsString('soa-envoi-message', $html, "Le message d'accompagnement doit être proposé.");
    }

    public function testEnvoiPickerExplainsWhenNoEmailAvailable(): void
    {
        ['clientSansEmail' => $clientSansEmail] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf('/admin/soa/client/%d/envoi-picker', $clientSansEmail->getId()));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('Aucune adresse e-mail', $html, "L'état vide doit expliquer le blocage.");
        $this->assertStringNotContainsString('data-picker-send', $html, "Sans destinataire, pas de bouton d'envoi.");
    }

    // ── Envoi par e-mail ──────────────────────────────────────────────────────

    public function testEnvoyerCreatesTokenAndSendsEmailWithPublicLink(): void
    {
        ['clientA' => $clientA, 'entreprise' => $entreprise] = $this->seed();
        $clientId = $clientA->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->postEnvoyer($clientId, ['email' => self::CLI_EMAIL, 'message' => 'Votre relevé du trimestre.']);
        $this->assertResponseIsSuccessful("L'envoi du SOA doit réussir.");
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($payload['success']);
        $this->assertStringContainsString(self::CLI_EMAIL, $payload['message'], 'Le message de succès rappelle le destinataire.');

        // Jeton persisté, actif, à J+30.
        $this->em()->clear();
        $token = $this->em()->getRepository(SoaAccesToken::class)->findOneBy(['client' => $clientId]);
        $this->assertNotNull($token, 'Un jeton doit avoir été créé.');
        $this->assertTrue($token->isActif(new \DateTimeImmutable()));
        $joursRestants = (new \DateTimeImmutable())->diff($token->getExpiresAt())->days;
        $this->assertGreaterThanOrEqual(29, $joursRestants, 'Le jeton doit être valable ~30 jours.');
        $this->assertLessThanOrEqual(30, $joursRestants);

        // E-mail parti (mis en file Messenger), avec le lien public et le message d'accompagnement.
        $this->assertQueuedEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailHtmlBodyContains($email, '/soa/' . $token->getToken());
        $this->assertEmailHtmlBodyContains($email, 'Votre relevé du trimestre.');
    }

    public function testSecondEnvoiProlongsSameToken(): void
    {
        ['clientA' => $clientA] = $this->seed();
        $clientId = $clientA->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->postEnvoyer($clientId, ['email' => self::CLI_EMAIL]);
        $this->assertResponseIsSuccessful();
        $this->em()->clear();
        $premier = $this->em()->getRepository(SoaAccesToken::class)->findOneBy(['client' => $clientId]);

        $this->postEnvoyer($clientId, ['email' => self::CONTACT_EMAIL]);
        $this->assertResponseIsSuccessful();
        $this->em()->clear();
        $tokens = $this->em()->getRepository(SoaAccesToken::class)->findBy(['client' => $clientId]);

        $this->assertCount(1, $tokens, 'Le second envoi doit RÉUTILISER le jeton actif, pas en créer un autre.');
        $this->assertSame($premier->getToken(), $tokens[0]->getToken(), 'Les deux destinataires partagent le même lien.');
    }

    public function testEnvoyerRefusesArbitraryEmail(): void
    {
        ['clientA' => $clientA] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->postEnvoyer($clientA->getId(), ['email' => 'pirate@exfiltration.evil']);
        $this->assertResponseStatusCodeSame(400, "Une adresse hors de l'ensemble client+contacts doit être refusée.");
        $this->assertQueuedEmailCount(0, null, 'Aucun e-mail ne doit partir vers une adresse arbitraire.');
    }

    // ── Page publique tokenisée ───────────────────────────────────────────────

    /** Crée directement un jeton actif pour le client donné (sans passer par l'envoi). */
    private function createToken(Client $client, Entreprise $entreprise, string $modifier = '+30 days'): SoaAccesToken
    {
        $em = $this->em();
        $token = (new SoaAccesToken())
            ->setToken(bin2hex(random_bytes(32)))
            ->setClient($client);
        $token->setEntreprise($entreprise);
        $token->setExpiresAt(new \DateTimeImmutable($modifier));
        $em->persist($token);
        $em->flush();

        return $token;
    }

    public function testPublicSoaShowsClientViewWithoutBrokerSections(): void
    {
        ['clientA' => $clientA, 'entreprise' => $entreprise] = $this->seed();
        $token = $this->createToken($clientA, $entreprise);
        $tokenId = $token->getId();
        $this->em()->clear();

        // AUCUNE session : la page publique se consulte sans authentification.
        $this->client->request('GET', '/soa/' . $token->getToken());
        $this->assertResponseIsSuccessful('Le SOA public doit être accessible sans compte.');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString(self::CLI_NOM, $html);
        $this->assertStringContainsString('valable jusqu', $html, "La date d'expiration du lien doit être annoncée.");

        // Vue épurée : sections client présentes…
        foreach (['soa-section-recap', 'soa-section-contacts', 'soa-section-polices', 'soa-section-echeancier', 'soa-section-sinistres'] as $section) {
            $this->assertStringContainsString($section, $html, sprintf('La section %s doit être visible par le client.', $section));
        }
        // … et données de travail du courtier ABSENTES.
        foreach (['soa-section-pistes', 'soa-section-cotations', 'soa-section-taches', 'soa-section-sp', 'soa-section-solvabilite'] as $section) {
            $this->assertStringNotContainsString($section, $html, sprintf('La section %s ne doit PAS être exposée au client.', $section));
        }

        // Traçabilité : consultation comptée.
        $this->em()->clear();
        $reloaded = $this->em()->getRepository(SoaAccesToken::class)->find($tokenId);
        $this->assertSame(1, $reloaded->getAccessCount());
        $this->assertNotNull($reloaded->getLastAccessedAt());
    }

    public function testPublicSoaFailureResponseIsUniform(): void
    {
        ['clientA' => $clientA, 'entreprise' => $entreprise] = $this->seed();

        $expire = $this->createToken($clientA, $entreprise, '-1 day');
        $revoque = $this->createToken($clientA, $entreprise);
        $revoque->setRevokedAt(new \DateTimeImmutable());
        $this->em()->flush();
        $urls = [
            'inconnu' => '/soa/' . str_repeat('ab', 32),
            'expiré' => '/soa/' . $expire->getToken(),
            'révoqué' => '/soa/' . $revoque->getToken(),
        ];
        $this->em()->clear();

        $bodies = [];
        foreach ($urls as $cas => $url) {
            $this->client->request('GET', $url);
            $this->assertResponseStatusCodeSame(404, sprintf('Jeton %s → 404.', $cas));
            $html = (string) $this->client->getResponse()->getContent();
            $this->assertStringContainsString("Ce lien n'est plus valide", $html);
            $this->assertStringNotContainsString(self::CLI_NOM, $html, 'Aucune donnée client ne doit fuiter.');
            $bodies[$cas] = $html;
        }

        // Réponse STRICTEMENT identique : aucun oracle entre inconnu / expiré / révoqué.
        $this->assertSame($bodies['inconnu'], $bodies['expiré']);
        $this->assertSame($bodies['expiré'], $bodies['révoqué']);
    }

    public function testPublicSoaRejectsMalformedToken(): void
    {
        $this->client->request('GET', '/soa/pas-un-token');
        $this->assertResponseStatusCodeSame(404, 'Un jeton hors format [a-f0-9]{64} ne matche pas la route.');
    }
}
