<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Contact;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\SoaAccesToken;
use App\Entity\Tranche;
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

        // Fichiers de test du téléchargement de documents.
        foreach (glob(self::uploadsDir() . '/phpunit-soa-*') ?: [] as $fichier) {
            @unlink($fichier);
        }

        foreach ($noms as $nom) {
            // Ordre des FK : document/envoi/token → tranche → avenant → cotation → piste
            // → risque → contact → client → invite → entreprise → utilisateur.
            $conn->executeStatement("DELETE d FROM document d JOIN entreprise e ON d.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
            $conn->executeStatement("DELETE s FROM soa_envoi s JOIN entreprise e ON s.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
            $conn->executeStatement("DELETE t FROM soa_acces_token t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
            $conn->executeStatement("DELETE tr FROM tranche tr JOIN entreprise e ON tr.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
            $conn->executeStatement("DELETE a FROM avenant a JOIN entreprise e ON a.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
            $conn->executeStatement("DELETE co FROM cotation co JOIN entreprise e ON co.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
            $conn->executeStatement("DELETE p FROM piste p JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
            $conn->executeStatement("DELETE r FROM risque r JOIN entreprise e ON r.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
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

    private static function uploadsDir(): string
    {
        return \dirname(__DIR__, 2) . '/public/uploads/documents';
    }

    /**
     * Complète le seed d'un pipe de police complet pour clientA (risque → piste →
     * cotation → avenant → tranche) avec un document attaché à CHAQUE niveau
     * (client, piste, cotation, police), plus un pipe minimal et un document pour
     * clientB (entreprise B) pour les contrôles d'isolation.
     *
     * @return array{avenant: Avenant, avenantB: Avenant, docs: array<string, Document>, docB: Document}
     */
    private function seedPolice(array $ctx): array
    {
        $em = $this->em();
        $entreprise = $ctx['entreprise'];
        $clientA = $ctx['clientA'];
        $clientB = $ctx['clientB'];
        $entrepriseB = $clientB->getEntreprise();

        $risque = (new Risque())
            ->setNomComplet('Incendie Tous Risques PHPUnit')
            ->setCode('INC-SOA')
            ->setDescription('Risque de test SOA')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $piste = (new Piste())
            ->setNom('Piste SOA Docs')
            ->setClient($clientA)
            ->setRisque($risque)
            ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Pipe de test documents')
            ->setExercice(2026);
        $piste->setEntreprise($entreprise);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation SOA Docs')->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $avenant = (new Avenant())
            ->setReferencePolice('POL-SOA-DOCS-1')
            ->setDescription('Police de test documents')
            ->setStartingAt(new \DateTimeImmutable('2026-01-01'))
            ->setEndingAt(new \DateTimeImmutable('2026-12-31'))
            ->setCotation($cotation);
        $avenant->setEntreprise($entreprise);
        $em->persist($avenant);

        $tranche = (new Tranche())
            ->setNom('Tranche unique')
            ->setPayableAt(new \DateTimeImmutable('2026-03-01'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);

        // Un document par niveau du pipe, avec des formats variés (pastilles).
        $docsSpec = [
            'client'   => ['nom' => 'Contrat cadre du client', 'fichier' => 'phpunit-soa-client.pdf', 'attach' => fn (Document $d) => $d->setClient($clientA)],
            'piste'    => ['nom' => 'Etude de risque initiale', 'fichier' => 'phpunit-soa-piste.docx', 'attach' => fn (Document $d) => $d->setPiste($piste)],
            'cotation' => ['nom' => 'Tableau de garanties', 'fichier' => 'phpunit-soa-cotation.xlsx', 'attach' => fn (Document $d) => $d->setCotation($cotation)],
            'avenant'  => ['nom' => 'Police signee', 'fichier' => 'phpunit-soa-avenant.pdf', 'attach' => fn (Document $d) => $d->setAvenant($avenant)],
        ];
        $docs = [];
        foreach ($docsSpec as $niveau => $spec) {
            $doc = (new Document())->setNom($spec['nom']);
            $doc->setNomFichierStocke($spec['fichier']);
            $spec['attach']($doc);
            $doc->setEntreprise($entreprise);
            $em->persist($doc);
            $docs[$niveau] = $doc;
        }

        // Pipe minimal + document pour le client de l'AUTRE entreprise (isolation).
        $risqueB = (new Risque())
            ->setNomComplet('Risque B')
            ->setCode('INC-SOA-B')
            ->setDescription('Risque B')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setImposable(true);
        $risqueB->setEntreprise($entrepriseB);
        $em->persist($risqueB);
        $pisteB = (new Piste())
            ->setNom('Piste B')
            ->setClient($clientB)
            ->setRisque($risqueB)
            ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Pipe B')
            ->setExercice(2026);
        $pisteB->setEntreprise($entrepriseB);
        $em->persist($pisteB);
        $cotationB = (new Cotation())->setNom('Cotation B')->setDuree(365);
        $cotationB->setPiste($pisteB);
        $cotationB->setEntreprise($entrepriseB);
        $em->persist($cotationB);
        $avenantB = (new Avenant())
            ->setReferencePolice('POL-SOA-DOCS-B')
            ->setDescription('Police B')
            ->setStartingAt(new \DateTimeImmutable('2026-01-01'))
            ->setEndingAt(new \DateTimeImmutable('2026-12-31'))
            ->setCotation($cotationB);
        $avenantB->setEntreprise($entrepriseB);
        $em->persist($avenantB);
        $docB = (new Document())->setNom('Document client B');
        $docB->setNomFichierStocke('phpunit-soa-clientB.pdf');
        $docB->setClient($clientB);
        $docB->setEntreprise($entrepriseB);
        $em->persist($docB);

        $em->flush();

        // Fichiers réels pour les téléchargements (mapping VichUploader).
        if (!is_dir(self::uploadsDir())) {
            mkdir(self::uploadsDir(), 0777, true);
        }
        foreach (array_merge(array_column($docsSpec, 'fichier'), ['phpunit-soa-clientB.pdf']) as $fichier) {
            file_put_contents(self::uploadsDir() . '/' . $fichier, '%PDF-1.4 contenu de test PHPUnit');
        }

        $ids = [
            'avenant' => $avenant->getId(),
            'avenantB' => $avenantB->getId(),
            'docs' => array_map(fn (Document $d) => $d->getId(), $docs),
            'docB' => $docB->getId(),
        ];
        $em->clear();

        return [
            'avenant' => $em->getRepository(Avenant::class)->find($ids['avenant']),
            'avenantB' => $em->getRepository(Avenant::class)->find($ids['avenantB']),
            'docs' => array_map(fn (int $id) => $em->getRepository(Document::class)->find($id), $ids['docs']),
            'docB' => $em->getRepository(Document::class)->find($ids['docB']),
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

        $this->client->request('POST', sprintf('/admin/soa/api/client/%d/lien-public', $clientB->getId()));
        $this->assertResponseStatusCodeSame(404, 'La copie du lien doit refuser un client hors workspace.');

        $this->client->request('DELETE', sprintf('/admin/soa/api/client/%d/revoquer-lien', $clientB->getId()));
        $this->assertResponseStatusCodeSame(404, 'La révocation doit refuser un client hors workspace.');
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

    // ── Copie du lien public ──────────────────────────────────────────────────

    public function testLienPublicReturnsSharableUrlAndReusesToken(): void
    {
        ['clientA' => $clientA] = $this->seed();
        $clientId = $clientA->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('POST', sprintf('/admin/soa/api/client/%d/lien-public', $clientId));
        $this->assertResponseIsSuccessful('La génération du lien public doit réussir.');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($payload['success']);
        $this->assertMatchesRegularExpression('#/soa/[a-f0-9]{64}$#', $payload['url'], "L'URL retournée doit être le lien public tokenisé.");
        $this->assertStringContainsString('valable jusqu', strtolower($payload['message']), 'Le message annonce la validité.');

        // Le lien copié fonctionne pour le client (sans session).
        $this->em()->clear();
        $token = $this->em()->getRepository(SoaAccesToken::class)->findOneBy(['client' => $clientId]);
        $this->assertStringEndsWith('/soa/' . $token->getToken(), $payload['url']);

        // Second appel : même jeton (prolongé), pas de nouveau lien.
        $this->client->request('POST', sprintf('/admin/soa/api/client/%d/lien-public', $clientId));
        $this->assertResponseIsSuccessful();
        $payload2 = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame($payload['url'], $payload2['url'], 'Copies successives = même lien.');
    }

    // ── Révocation du lien ────────────────────────────────────────────────────

    public function testRevoquerLienInvalidatesPublicAccess(): void
    {
        ['clientA' => $clientA, 'entreprise' => $entreprise] = $this->seed();
        $clientId = $clientA->getId();
        $token = $this->createToken($clientA, $entreprise);
        $tokenValue = $token->getToken();
        $this->em()->clear();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Le lien fonctionne avant révocation.
        $this->client->request('GET', '/soa/' . $tokenValue);
        $this->assertResponseIsSuccessful();

        // L'indicateur calculé expose un lien actif (condition de l'action côté front).
        $canvasBuilder = static::getContainer()->get(\App\Services\CanvasBuilder::class);
        $reloadedClient = $this->em()->getRepository(Client::class)->find($clientId);
        $canvasBuilder->loadAllCalculatedValues($reloadedClient);
        $this->assertTrue($reloadedClient->hasLienSoa, 'hasLienSoa doit être vrai quand un jeton actif existe.');

        // Révocation.
        $this->client->request('DELETE', sprintf('/admin/soa/api/client/%d/revoquer-lien', $clientId));
        $this->assertResponseIsSuccessful('La révocation doit réussir.');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('révoqué', $payload['message']);

        // Le lien ne fonctionne plus, avec la réponse uniforme.
        $this->client->request('GET', '/soa/' . $tokenValue);
        $this->assertResponseStatusCodeSame(404, 'Un lien révoqué ne doit plus servir le SOA.');

        // L'indicateur retombe à faux et une seconde révocation n'a rien à faire.
        $this->em()->clear();
        $reloadedClient = $this->em()->getRepository(Client::class)->find($clientId);
        $canvasBuilder->loadAllCalculatedValues($reloadedClient);
        $this->assertFalse($reloadedClient->hasLienSoa, 'hasLienSoa doit retomber à faux après révocation.');

        $this->client->request('DELETE', sprintf('/admin/soa/api/client/%d/revoquer-lien', $clientId));
        $this->assertResponseStatusCodeSame(404, 'Sans lien actif, la révocation doit répondre 404.');
    }

    // ── Historique des envois ─────────────────────────────────────────────────

    public function testHistoriqueDesEnvoisLoggedAndDisplayed(): void
    {
        ['clientA' => $clientA] = $this->seed();
        $clientId = $clientA->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Avant tout envoi : pas de bloc d'historique dans le dialogue.
        $this->client->request('GET', sprintf('/admin/soa/client/%d/envoi-picker', $clientId));
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Derniers envois', (string) $this->client->getResponse()->getContent());

        // Deux envois vers deux destinataires.
        $this->postEnvoyer($clientId, ['email' => self::CLI_EMAIL, 'message' => 'Premier envoi.']);
        $this->assertResponseIsSuccessful();
        $this->postEnvoyer($clientId, ['email' => self::CONTACT_EMAIL]);
        $this->assertResponseIsSuccessful();

        // Journal persisté : destinataire, expéditeur, validité figée, message.
        $this->em()->clear();
        $envois = $this->em()->getRepository(\App\Entity\SoaEnvoi::class)->findBy(['client' => $clientId], ['id' => 'ASC']);
        $this->assertCount(2, $envois, 'Chaque envoi doit être journalisé.');
        $this->assertSame(self::CLI_EMAIL, $envois[0]->getEmailDestinataire());
        $this->assertSame('Premier envoi.', $envois[0]->getMessage());
        $this->assertNotNull($envois[0]->getLienExpireAt());
        $this->assertSame('Administrateur', $envois[0]->getInvite()?->getNom(), "L'expéditeur (invité) doit être tracé.");
        $this->assertSame(self::CONTACT_EMAIL, $envois[1]->getEmailDestinataire());
        $this->assertNull($envois[1]->getMessage(), 'Sans message, le journal stocke null.');

        // Le dialogue affiche l'historique (plus récent en premier).
        $this->client->request('GET', sprintf('/admin/soa/client/%d/envoi-picker', $clientId));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Derniers envois', $html);
        $this->assertStringContainsString(self::CLI_EMAIL, $html);
        $this->assertStringContainsString(self::CONTACT_EMAIL, $html);
        $this->assertStringContainsString('Administrateur', $html, "L'expéditeur apparaît dans l'historique.");
    }

    // ── Échéancier : colonnes Risque et Assureur ──────────────────────────────

    public function testEcheancierAfficheRisqueEtAssureur(): void
    {
        $ctx = $this->seedPolice($this->seed());
        $clientId = $ctx['avenant']->getCotation()->getPiste()->getClient()->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf('/admin/soa/client/%d/apercu', $clientId));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $echeancier = $this->extraireSection($html, 'soa-section-echeancier');
        $this->assertStringContainsString('>Risque</th>', $echeancier, "L'échéancier doit avoir une colonne Risque.");
        $this->assertStringContainsString('>Assureur</th>', $echeancier, "L'échéancier doit avoir une colonne Assureur.");
        $this->assertStringContainsString('Incendie Tous Risques PHPUnit', $echeancier, 'Le risque de la tranche doit être affiché.');
    }

    // ── Documents de la police (picker + téléchargement) ─────────────────────

    public function testPoliceDocumentsPickerAdminListsAllLevels(): void
    {
        $ctx = $this->seedPolice($this->seed());
        $avenantId = $ctx['avenant']->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf('/admin/soa/api/police/%d/documents', $avenantId));
        $this->assertResponseIsSuccessful('Le picker des documents doit se charger.');
        $html = (string) $this->client->getResponse()->getContent();

        // Les 4 documents, chacun avec son niveau d'attache.
        foreach (['Contrat cadre du client', 'Etude de risque initiale', 'Tableau de garanties', 'Police signee'] as $nom) {
            $this->assertStringContainsString($nom, $html, sprintf('Le document « %s » doit être listé.', $nom));
        }
        foreach (['Piste', 'Cotation', 'Police', 'Client'] as $niveau) {
            $this->assertStringContainsString('soa-docs-niveau">' . $niveau . '<', $html, sprintf('Le niveau %s doit être indiqué.', $niveau));
        }
        // Pastilles de format + formats en texte + téléchargement admin.
        foreach (['>PDF<', '>DOC<', '>XLS<'] as $pastille) {
            $this->assertStringContainsString($pastille, $html, sprintf('La pastille %s doit être rendue.', $pastille));
        }
        $this->assertStringContainsString('DOCX', $html, 'Le format en clair doit accompagner la pastille.');
        $this->assertStringContainsString('/admin/document/api/', $html, 'Les téléchargements passent par la route admin.');
        $this->assertStringContainsString('Télécharger', $html);

        // Isolation : la police d'une autre entreprise est introuvable.
        $this->client->request('GET', sprintf('/admin/soa/api/police/%d/documents', $ctx['avenantB']->getId()));
        $this->assertResponseStatusCodeSame(404, 'Une police hors workspace doit être refusée.');
    }

    public function testPoliceDocumentsPickerPublic(): void
    {
        $ctx = $this->seedPolice($this->seed());
        $clientA = $ctx['avenant']->getCotation()->getPiste()->getClient();
        $token = $this->createToken($clientA, $clientA->getEntreprise());
        $tokenValue = $token->getToken();
        $avenantId = $ctx['avenant']->getId();
        $avenantBId = $ctx['avenantB']->getId();
        $this->em()->clear();

        // AUCUNE session : le picker public est gardé par le seul jeton.
        $this->client->request('GET', sprintf('/soa/%s/police/%d/documents', $tokenValue, $avenantId));
        $this->assertResponseIsSuccessful('Le picker public des documents doit se charger sans compte.');
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Police signee', $html);
        $this->assertStringContainsString(sprintf('/soa/%s/document/', $tokenValue), $html, 'Les téléchargements publics sont tokenisés.');
        $this->assertStringNotContainsString('/admin/', $html, 'Aucune URL admin ne doit fuiter côté client.');

        // La police d'un AUTRE client → réponse uniforme.
        $this->client->request('GET', sprintf('/soa/%s/police/%d/documents', $tokenValue, $avenantBId));
        $this->assertResponseStatusCodeSame(404, "Une police hors du pipe du client du jeton doit être refusée.");
        $this->assertStringContainsString("Ce lien n'est plus valide", (string) $this->client->getResponse()->getContent());

        // Jeton inconnu → réponse uniforme.
        $this->client->request('GET', sprintf('/soa/%s/police/%d/documents', str_repeat('ab', 32), $avenantId));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testPublicDocumentDownloadGuardedByToken(): void
    {
        $ctx = $this->seedPolice($this->seed());
        $clientA = $ctx['avenant']->getCotation()->getPiste()->getClient();
        $token = $this->createToken($clientA, $clientA->getEntreprise());
        $tokenValue = $token->getToken();
        $docAvenantId = $ctx['docs']['avenant']->getId();
        $docBId = $ctx['docB']->getId();
        $this->em()->clear();

        // Téléchargement d'un document du pipe : servi en pièce jointe.
        $this->client->request('GET', sprintf('/soa/%s/document/%d/telecharger', $tokenValue, $docAvenantId));
        $this->assertResponseIsSuccessful('Le document du pipe du client doit être téléchargeable.');
        $this->assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('content-disposition'), 'Le fichier doit partir en pièce jointe.');

        // Document d'un AUTRE client → réponse uniforme, rien ne fuit.
        $this->client->request('GET', sprintf('/soa/%s/document/%d/telecharger', $tokenValue, $docBId));
        $this->assertResponseStatusCodeSame(404, "Un document hors du pipe du client doit être refusé.");

        // Jeton révoqué → plus aucun téléchargement.
        $reloaded = $this->em()->getRepository(SoaAccesToken::class)->findOneBy(['token' => $tokenValue]);
        $reloaded->setRevokedAt(new \DateTimeImmutable());
        $this->em()->flush();
        $this->em()->clear();
        $this->client->request('GET', sprintf('/soa/%s/document/%d/telecharger', $tokenValue, $docAvenantId));
        $this->assertResponseStatusCodeSame(404, 'Un jeton révoqué ne télécharge plus rien.');
    }

    public function testPublicSoaPolicesTableExposeDocsButton(): void
    {
        $ctx = $this->seedPolice($this->seed());
        $clientA = $ctx['avenant']->getCotation()->getPiste()->getClient();
        $token = $this->createToken($clientA, $clientA->getEntreprise());
        $tokenValue = $token->getToken();
        $avenantId = $ctx['avenant']->getId();
        $this->em()->clear();

        $this->client->request('GET', '/soa/' . $tokenValue);
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString(
            sprintf('data-soa-docs-url="/soa/%s/police/%d/documents"', $tokenValue, $avenantId),
            $html,
            'La table des polices doit exposer le bouton Documents tokenisé.'
        );

        // Côté courtier (aperçu), le même bouton pointe la route admin.
        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $this->client->request('GET', sprintf('/admin/soa/client/%d/apercu', $clientA->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            sprintf('data-soa-docs-url="/admin/soa/api/police/%d/documents"', $avenantId),
            (string) $this->client->getResponse()->getContent(),
            "L'aperçu courtier doit exposer le bouton Documents (route admin)."
        );
    }

    /** Découpe le HTML autour d'une section du SOA (de son id au </section> suivant). */
    private function extraireSection(string $html, string $sectionId): string
    {
        $debut = strpos($html, $sectionId);
        $this->assertNotFalse($debut, sprintf('La section %s doit exister.', $sectionId));
        $fin = strpos($html, '</section>', $debut);

        return substr($html, $debut, ($fin !== false ? $fin : strlen($html)) - $debut);
    }
}
