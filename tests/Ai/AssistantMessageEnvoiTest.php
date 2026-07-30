<?php

namespace App\Tests\Ai;

use App\Ai\Export\MessageDestinataires;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Assureur;
use App\Entity\Client;
use App\Entity\Contact;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Utilisateur;
use App\Repository\TokenConsumptionRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels de l'ENVOI par e-mail d'un message du chat : carnet
 * d'adresses (sources, scoping, droits, dédoublonnage), picker, envoi réel, et
 * garde-fous de l'adresse saisie à la main.
 *
 * Les points sensibles couverts ici :
 *  - une source non lisible par l'invité ne doit PAS apparaître dans le carnet
 *    (sinon le picker devient une porte latérale vers des données hors périmètre) ;
 *  - une adresse hors carnet est acceptée mais validée, plafonnée et TRACÉE ;
 *  - le `replyTo` porte l'adresse du courtier, jamais celle de la plateforme.
 */
class AssistantMessageEnvoiTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-iaenv-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-iaenv-guest@test.local';
    private const COLLEGUE_EMAIL = 'phpunit-iaenv-collegue@test.local';
    /** Propriétaire de l'entreprise témoin (contrôle du scoping). */
    private const AUTRE_OWNER_EMAIL = 'phpunit-iaenv-autre@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit IAENV SARL';
    private const AUTRE_ENTREPRISE_NOM = 'PHPUnit IAENV AUTRE SARL';

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

    private function user(string $email): ?Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function makeUser(string $email, string $nom = 'PHPUnit IAENV'): Utilisateur
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new Utilisateur())->setEmail($email)->setNom($nom);
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);

        return $user;
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL, self::COLLEGUE_EMAIL, self::AUTRE_OWNER_EMAIL];
        $noms = [self::ENTREPRISE_NOM, self::AUTRE_ENTREPRISE_NOM];

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => ArrayParameterType::STRING],
        );
        foreach (['assistant_conversation_fichier', 'assistant_conversation_contexte', 'assistant_message'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN assistant_conversation c ON t.conversation_id = c.id
                 JOIN entreprise e ON c.entreprise_id = e.id
                 WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => ArrayParameterType::STRING],
            );
        }
        foreach (['assistant_conversation', 'contact', 'assureur', 'partenaire', 'fournisseur', 'client'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'DELETE tc FROM token_consumption tc LEFT JOIN utilisateur u ON tc.proprietaire_id = u.id WHERE u.email IN (:emails)',
            ['emails' => $emails],
            ['emails' => ArrayParameterType::STRING],
        );
        foreach ([
            'roles_en_finance', 'roles_en_marketing', 'roles_en_production',
            'roles_en_sinistre', 'roles_en_administration',
        ] as $table) {
            $conn->executeStatement(
                "DELETE r FROM {$table} r
                 JOIN invite i ON r.invite_id = i.id
                 JOIN entreprise e ON i.entreprise_id = e.id
                 WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'DELETE i FROM invite i LEFT JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => ArrayParameterType::STRING],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (:noms)', ['noms' => $noms], ['noms' => ArrayParameterType::STRING]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:emails)', ['emails' => $emails], ['emails' => ArrayParameterType::STRING]);
    }

    private function makeEntreprise(string $nom, Utilisateur $proprietaire): Entreprise
    {
        $entreprise = (new Entreprise())
            ->setNom($nom)->setLicence('LIC-IAENV')->setAdresse('1 rue Envoyée')
            ->setTelephone('+243000000000')->setRccm('RCCM-IAENV')->setIdnat('IDNAT-IAENV')
            ->setNumimpot('IMP-IAENV')->setUtilisateur($proprietaire);
        $this->em()->persist($entreprise);

        return $entreprise;
    }

    /**
     * @param array<int, string> $entitesLisibles noms courts autorisés en lecture
     * @return array{guest: Invite, entreprise: Entreprise, conversation: AssistantConversation, message: AssistantMessage}
     */
    private function seed(
        array $entitesLisibles = ['Contact', 'Partenaire', 'Assureur', 'Fournisseur'],
        bool $withIaRole = true,
        bool $comptePayant = true,
        bool $avecCarnet = true,
    ): array {
        $em = $this->em();

        $owner = $this->makeUser(self::OWNER_EMAIL, 'Propriétaire IAENV');
        if ($comptePayant) {
            $owner->setPaidTokens(1_000_000);
        }
        $entreprise = $this->makeEntreprise(self::ENTREPRISE_NOM, $owner);
        $owner->setConnectedTo($entreprise);

        $guestUser = $this->makeUser(self::GUEST_EMAIL, 'Marie Courtier');
        $guestUser->setConnectedTo($entreprise);
        $guest = (new Invite())->setNom('Marie Courtier')->setUtilisateur($guestUser)
            ->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($guest);

        // Les droits du carnet sont répartis sur TROIS modules distincts :
        // Contact/Assureur/Partenaire relèvent de la Production, Fournisseur de
        // la Finance, et l'accès au module IA de l'Administration. On crée donc
        // un rôle par module — c'est ce qui permet de tester le fail-closed
        // source par source.
        $roleAdmin = new \App\Entity\RolesEnAdministration();
        $roleAdmin->setNom('Rôle IAENV — module IA');
        $roleAdmin->setEntreprise($entreprise);
        if ($withIaRole) {
            $roleAdmin->setAccessAssistantIa([Invite::ACCESS_LECTURE]);
        }
        $guest->addRolesEnAdministration($roleAdmin);
        $em->persist($roleAdmin);

        $roleProduction = new \App\Entity\RolesEnProduction();
        $roleProduction->setNom('Rôle IAENV — production');
        $roleProduction->setEntreprise($entreprise);
        $roleFinance = new \App\Entity\RolesEnFinance();
        $roleFinance->setNom('Rôle IAENV — finance');
        $roleFinance->setEntreprise($entreprise);

        $porteurs = [
            'Contact' => $roleProduction,
            'Assureur' => $roleProduction,
            'Partenaire' => $roleProduction,
            'Fournisseur' => $roleFinance,
        ];
        foreach ($entitesLisibles as $entite) {
            $porteur = $porteurs[$entite] ?? null;
            $setter = 'setAccess' . $entite;
            if ($porteur !== null && method_exists($porteur, $setter)) {
                $porteur->{$setter}([Invite::ACCESS_LECTURE]);
            }
        }
        $guest->addRolesEnProduction($roleProduction);
        $guest->addRolesEnFinance($roleFinance);
        $em->persist($roleProduction);
        $em->persist($roleFinance);

        // Collaborateur : présent au carnet via son COMPTE (Invite::$email est
        // transitoire et ne doit pas servir de carnet d'adresses).
        $collegueUser = $this->makeUser(self::COLLEGUE_EMAIL, 'Jean Collègue');
        $collegueUser->setConnectedTo($entreprise);
        $collegue = (new Invite())->setNom('Jean Collègue')->setUtilisateur($collegueUser)
            ->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($collegue);
        // Invité SANS compte : ne doit jamais apparaître.
        $em->persist((new Invite())->setNom('Invitation en attente')->setEntreprise($entreprise)->setProprietaire(false));

        if ($avecCarnet) {
            $client = (new Client())->setNom('SONAS')->setExonere(false)->setEntreprise($entreprise);
            $em->persist($client);

            $em->persist((new Contact())->setNom('Alice Sinistre')->setTelephone('+243111111111')
                ->setEmail('alice@sonas.cd')->setFonction('Chef de service')
                ->setType(Contact::TYPE_CONTACT_SINISTRE)->setClient($client)->setEntreprise($entreprise));
            // Doublon de casse : une seule ligne attendue au carnet. Nommé « Zoé »
            // pour trier APRÈS « Alice Sinistre » : la source retenue est la
            // première vue, et l'ordre est alphabétique — le test resterait donc
            // ambigu avec deux noms interchangeables.
            $em->persist((new Contact())->setNom('Zoé Doublon')->setTelephone('+243111111112')
                ->setEmail('ALICE@SONAS.CD')->setType(Contact::TYPE_CONTACT_AUTRES)->setEntreprise($entreprise));
            // Contact SANS e-mail : jamais proposé.
            $em->persist((new Contact())->setNom('Sans adresse')->setTelephone('+243111111113')
                ->setType(Contact::TYPE_CONTACT_PRODUCTION)->setEntreprise($entreprise));

            $em->persist((new Assureur())->setNom('RAWSURE')->setEmail('contact@rawsure.cd')
                ->setIdnat('I1')->setNumimpot('N1')->setRccm('R1')->setEntreprise($entreprise));
            $em->persist((new Partenaire())->setNom('CoCourtier SPRL')->setEmail('bureau@cocourtier.cd')
                ->setPart(35.0)->setEntreprise($entreprise));
            $em->persist((new Fournisseur())->setNom('Papeterie Centrale')->setPersonneContact('Paul Papier')
                ->setEmail('paul@papeterie.cd')->setActif(true)->setEntreprise($entreprise));
        }

        // Autre entreprise : son contact ne doit JAMAIS fuiter.
        $autreOwner = $this->makeUser(self::AUTRE_OWNER_EMAIL, 'Autre proprio');
        $autreEntreprise = $this->makeEntreprise(self::AUTRE_ENTREPRISE_NOM, $autreOwner);
        $em->persist((new Contact())->setNom('Contact Étranger')->setTelephone('+243999999999')
            ->setEmail('etranger@ailleurs.cd')->setType(Contact::TYPE_CONTACT_AUTRES)->setEntreprise($autreEntreprise));

        $conversation = (new AssistantConversation())->setEntreprise($entreprise)->setInvite($guest);
        $em->persist($conversation);
        $message = (new AssistantMessage())->setConversation($conversation)
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu("## Situation\n\nVotre portefeuille compte **3 avenants** échus.");
        $conversation->addMessage($message);
        $em->persist($message);

        $em->flush();

        return ['guest' => $guest, 'entreprise' => $entreprise, 'conversation' => $conversation, 'message' => $message];
    }

    private function urlPicker(array $seed): string
    {
        return sprintf(
            '/admin/assistant-ia/api/messages/%d/%d/%d/destinataires',
            $seed['entreprise']->getId(),
            $seed['conversation']->getId(),
            $seed['message']->getId()
        );
    }

    private function urlEnvoi(array $seed): string
    {
        return sprintf(
            '/admin/assistant-ia/api/messages/%d/%d/%d/envoyer',
            $seed['entreprise']->getId(),
            $seed['conversation']->getId(),
            $seed['message']->getId()
        );
    }

    /** Le clear() force le contrôleur à recharger depuis la base, comme en production. */
    private function ouvrirPicker(array $seed): string
    {
        $url = $this->urlPicker($seed);
        $this->em()->clear();
        $this->client->request('GET', $url);

        return (string) $this->client->getResponse()->getContent();
    }

    private function envoyer(array $seed, array $payload): array
    {
        $url = $this->urlEnvoi($seed);
        $this->em()->clear();
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * Documents réellement JOINTS. Le layout corporate embarque le logo en CID :
     * il figure dans getAttachments() sans être une pièce jointe pour
     * l'utilisateur. On ne retient donc que nos exports, reconnaissables à leur
     * nom de fichier (MessageExporter::nomFichier).
     *
     * @return array<int, \Symfony\Component\Mime\Part\DataPart>
     */
    private function piecesJointes(\Symfony\Component\Mime\Email $email): array
    {
        return array_values(array_filter(
            $email->getAttachments(),
            static fn ($piece): bool => str_starts_with((string) $piece->getFilename(), 'message-ia-')
        ));
    }

    private function metaEnvois(array $seed): array
    {
        $this->em()->clear();
        $message = $this->em()->getRepository(AssistantMessage::class)->find($seed['message']->getId());

        return ($message?->getMeta() ?? [])['envois'] ?? [];
    }

    // ── Le mailer de test répond-il ? ──────────────────────────────────────

    public function testLesAssertionsEmailFonctionnentDansCeProjet(): void
    {
        // Garde-fou méthodologique : MAILER_DSN=null://null en test, et aucun
        // test du dépôt n'assérait d'e-mail jusqu'ici. Si ce test tombe, tous
        // les suivants sont à réinterpréter.
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->envoyer($seed, ['emails' => ['alice@sonas.cd'], 'format' => null]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertQueuedEmailCount(1);
    }

    // ── Carnet d'adresses ──────────────────────────────────────────────────

    public function testLePickerListeLesQuatreSources(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $html = $this->ouvrirPicker($seed);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(self::GUEST_EMAIL, $html);        // soi
        self::assertStringContainsString('alice@sonas.cd', $html);          // contact
        self::assertStringContainsString(self::COLLEGUE_EMAIL, $html);      // collaborateur
        self::assertStringContainsString('bureau@cocourtier.cd', $html);    // partenaire
        self::assertStringContainsString('contact@rawsure.cd', $html);      // assureur
        self::assertStringContainsString('paul@papeterie.cd', $html);       // fournisseur
    }

    public function testLeCarnetEstScopeALEntrepriseEtSansDoublonNiVide(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $html = $this->ouvrirPicker($seed);

        self::assertStringNotContainsString('etranger@ailleurs.cd', $html);
        self::assertStringNotContainsString('Sans adresse', $html);

        // Dédoublonnage : le contact retenu est le premier vu (ordre alphabétique),
        // l'homonyme de casse disparaît. Assertion sur le SERVICE plutôt que sur le
        // HTML — l'attribut `value` est échappé en html_attr (« @ » → « &#x40; »),
        // donc illisible à la regex, alors que la règle vit dans le service.
        self::assertStringNotContainsString('Zoé Doublon', $html);
        $carnet = static::getContainer()->get(MessageDestinataires::class)
            ->collecter($seed['entreprise'], $seed['guest'], $this->user(self::GUEST_EMAIL))['destinataires'];
        $adresses = array_map('mb_strtolower', array_column($carnet, 'email'));
        self::assertSame(1, \count(array_keys($adresses, 'alice@sonas.cd', true)));
        self::assertNotContains('etranger@ailleurs.cd', $adresses);
        // Un invité sans compte n'a pas d'adresse exploitable.
        self::assertStringNotContainsString('Invitation en attente', $html);
    }

    public function testUneSourceNonLisibleNapparaitPas(): void
    {
        // Fail-closed : l'invité « sinistres » ne découvre pas le carnet
        // Assureurs par une porte latérale.
        $seed = $this->seed(entitesLisibles: ['Contact']);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $html = $this->ouvrirPicker($seed);

        self::assertStringContainsString('alice@sonas.cd', $html);
        self::assertStringNotContainsString('contact@rawsure.cd', $html);
        self::assertStringNotContainsString('bureau@cocourtier.cd', $html);
        self::assertStringNotContainsString('paul@papeterie.cd', $html);
    }

    public function testLePickerPorteLesCrochetsDuFiltrageProfond(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $html = $this->ouvrirPicker($seed);

        foreach ([
            'data-picker-search',
            'data-picker-row',
            'data-picker-categorie',
            'data-picker-count-shown',
            'data-picker-empty',
            'data-picker-email-libre',
            'data-picker-selection',
            'data-picker-format',
            // Sélection MULTIPLE : cases à cocher, jamais des boutons radio.
            'type="checkbox"',
            // L'image doit figurer dans les formats ET être le défaut, comme
            // lorsque Ket envoie lui-même : c'est le seul rendu fidèle.
            'id="aimsg-format-image"',
            'data-assistant-message-picker-id-message-value',
        ] as $crochet) {
            self::assertStringContainsString($crochet, $html, sprintf('Crochet « %s » absent du picker.', $crochet));
        }
        // L'ORIGINE est ce qui permet de retrouver « le contact sinistres de SONAS ».
        self::assertStringContainsString('SONAS', $html);
        self::assertStringContainsString('Sinistres', $html);

        // Seule l'option « image » est pré-cochée parmi les formats.
        self::assertSame(
            1,
            preg_match_all('/name="aimsg-format"[^>]*value="image"[^>]*checked|checked[^>]*name="aimsg-format"[^>]*value="image"/', $html)
        );
        // data-search agrège les facettes, en minuscules.
        self::assertStringContainsString('data-search="alice sinistre', strtolower($html));
    }

    public function testCarnetVideAfficheUnEtatExplicite(): void
    {
        $seed = $this->seed(avecCarnet: false);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $html = $this->ouvrirPicker($seed);

        // « Vous » et le collaborateur restent : l'état vide concerne le carnet
        // externe, jamais l'impossibilité d'envoyer — la saisie libre reste offerte.
        self::assertStringContainsString(self::GUEST_EMAIL, $html);
        self::assertStringContainsString('Autres adresses', $html);
        self::assertStringContainsString('data-picker-email-libre', $html);
    }

    public function testCollecterExposeCategoriesEtOrigine(): void
    {
        $seed = $this->seed();
        $service = static::getContainer()->get(MessageDestinataires::class);

        $carnet = $service->collecter($seed['entreprise'], $seed['guest'], $this->user(self::GUEST_EMAIL));
        $parEmail = array_column($carnet['destinataires'], null, 'email');

        self::assertSame(0, $carnet['tronque']);
        self::assertSame(MessageDestinataires::CATEGORIE_MOI, $parEmail[self::GUEST_EMAIL]['categorie']);
        self::assertSame(MessageDestinataires::CATEGORIE_CONTACT, $parEmail['alice@sonas.cd']['categorie']);
        self::assertSame('SONAS', $parEmail['alice@sonas.cd']['origine']);
        self::assertStringContainsString('Sinistres', $parEmail['alice@sonas.cd']['detail']);
        self::assertSame('Paul Papier', $parEmail['paul@papeterie.cd']['nom']);
    }

    public function testTrouverEstInsensibleALaCasseEtNullHorsCarnet(): void
    {
        $seed = $this->seed();
        $service = static::getContainer()->get(MessageDestinataires::class);
        $acteur = $this->user(self::GUEST_EMAIL);

        self::assertNotNull($service->trouver($seed['entreprise'], $seed['guest'], $acteur, 'ALICE@SONAS.CD'));
        self::assertNotNull($service->trouver($seed['entreprise'], $seed['guest'], $acteur, '  alice@sonas.cd  '));
        self::assertNull($service->trouver($seed['entreprise'], $seed['guest'], $acteur, 'inconnu@ailleurs.cd'));
        self::assertNull($service->trouver($seed['entreprise'], $seed['guest'], $acteur, ''));
    }

    // ── Envoi ──────────────────────────────────────────────────────────────

    public function testEnvoiNominalAvecPieceJointePdf(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $data = $this->envoyer($seed, ['emails' => ['alice@sonas.cd'], 'format' => 'pdf', 'message' => 'Bonjour Alice,']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($data['success']);
        self::assertQueuedEmailCount(1);

        $email = self::getMailerMessage();
        self::assertSame('alice@sonas.cd', $email->getTo()[0]->getAddress());
        self::assertSame(
            sprintf('JS Brokers - Message de Ket - %s', self::ENTREPRISE_NOM),
            $email->getSubject()
        );
        // Le destinataire répond au COURTIER, pas à la plateforme.
        self::assertSame(self::GUEST_EMAIL, $email->getReplyTo()[0]->getAddress());
        // Corps : le contenu du message et le mot d'accompagnement.
        self::assertStringContainsString('avenants', $email->getHtmlBody());
        self::assertStringContainsString('Bonjour Alice,', $email->getHtmlBody());
        // Une pièce jointe PDF, au bon nom.
        $pieces = $this->piecesJointes($email);
        self::assertCount(1, $pieces);
        self::assertStringEndsWith('.pdf', (string) $pieces[0]->getFilename());
        self::assertStringContainsString('application/pdf', $pieces[0]->getPreparedHeaders()->get('content-type')->toString());
        self::assertStringStartsWith('%PDF-', $pieces[0]->getBody());
    }

    public function testFormatNulNEnvoieAucunePieceJointe(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->envoyer($seed, ['emails' => ['alice@sonas.cd'], 'format' => null]);

        self::assertQueuedEmailCount(1);
        self::assertCount(0, $this->piecesJointes(self::getMailerMessage()));
    }

    public function testLaTraceEstAttacheeAuMessage(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->envoyer($seed, ['emails' => ['alice@sonas.cd'], 'format' => 'pdf']);
        self::assertCount(1, $this->metaEnvois($seed));

        $this->envoyer($seed, ['emails' => ['contact@rawsure.cd'], 'format' => null]);
        $envois = $this->metaEnvois($seed);

        self::assertCount(2, $envois);
        self::assertSame('alice@sonas.cd', $envois[0]['email']);
        self::assertSame('pdf', $envois[0]['format']);
        self::assertFalse($envois[0]['horsCarnet']);
        self::assertSame('contact@rawsure.cd', $envois[1]['email']);
        self::assertNull($envois[1]['format']);
    }

    // ── Adresse saisie à la main ───────────────────────────────────────────

    public function testAdresseHorsCarnetValideEstAccepteeEtTracee(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $data = $this->envoyer($seed, ['emails' => ['nouveau.client@exemple.com'], 'format' => null]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($data['success']);
        self::assertQueuedEmailCount(1);
        self::assertSame('nouveau.client@exemple.com', self::getMailerMessage()->getTo()[0]->getAddress());

        $envois = $this->metaEnvois($seed);
        self::assertTrue($envois[0]['horsCarnet'], 'Un envoi hors carnet doit être marqué comme tel.');
    }

    /** @dataProvider adressesInvalides */
    public function testAdresseHorsCarnetInvalideEstRefusee(mixed $email): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->envoyer($seed, ['email' => $email, 'format' => null]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertQueuedEmailCount(0);
        self::assertCount(0, $this->metaEnvois($seed));
    }

    /**
     * Formats réellement invalides. Les valeurs VIDES relèvent d'un autre cas —
     * « aucun destinataire » — couvert par testAucunDestinataireEstRefuse : les
     * confondre masquerait le message d'erreur propre à chaque situation.
     */
    public static function adressesInvalides(): array
    {
        return [
            'pas un e-mail' => ['pas-un-email'],
            'domaine manquant' => ['a@'],
            'espaces internes' => ['a b@exemple.com'],
        ];
    }

    public function testPlafondDEnvoisParMessage(): void
    {
        // Un e-mail sortant engage la marque : le plafond borne l'abus d'un
        // message donné, adresse libre comprise.
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        for ($i = 1; $i <= 10; ++$i) {
            $this->envoyer($seed, ['emails' => [sprintf('destinataire%d@exemple.com', $i)], 'format' => null]);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), "Envoi #{$i}");
        }

        $data = $this->envoyer($seed, ['emails' => ['onzieme@exemple.com'], 'format' => null]);

        self::assertSame(429, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('déjà été envoyé', $data['message']);
        self::assertCount(10, $this->metaEnvois($seed));
    }

    // ── Sélection multiple ─────────────────────────────────────────────────

    public function testEnvoiAPlusieursDestinatairesProduitUnEmailParPersonne(): void
    {
        // Jamais de « À » collectif : les correspondants d'un courtier ne doivent
        // pas découvrir mutuellement leurs adresses.
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $data = $this->envoyer($seed, [
            'emails' => ['alice@sonas.cd', 'contact@rawsure.cd', 'externe@exemple.com'],
            'format' => null,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($data['success']);
        self::assertQueuedEmailCount(3);
        self::assertStringContainsString('3 destinataires', $data['message']);

        $destinataires = [];
        foreach ([0, 1, 2] as $index) {
            $email = self::getMailerMessage($index);
            self::assertCount(1, $email->getTo(), 'Chaque e-mail ne porte qu\'UN destinataire.');
            $destinataires[] = $email->getTo()[0]->getAddress();
        }
        sort($destinataires);
        self::assertSame(['alice@sonas.cd', 'contact@rawsure.cd', 'externe@exemple.com'], $destinataires);
    }

    public function testChaqueDestinataireLaisseSaPropreTrace(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->envoyer($seed, ['emails' => ['alice@sonas.cd', 'externe@exemple.com'], 'format' => 'pdf']);
        $envois = $this->metaEnvois($seed);

        self::assertCount(2, $envois);
        self::assertSame(['alice@sonas.cd', 'externe@exemple.com'], array_column($envois, 'email'));
        self::assertFalse($envois[0]['horsCarnet']);
        self::assertTrue($envois[1]['horsCarnet']);
        self::assertSame(['pdf', 'pdf'], array_column($envois, 'format'));
    }

    public function testAucunDestinataireEstRefuse(): void
    {
        // L'interface autorise à ne rien cocher ; l'envoi, lui, exige une cible.
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        foreach ([['emails' => []], ['emails' => ['', '   ']], []] as $payload) {
            $data = $this->envoyer($seed, $payload + ['format' => null]);
            self::assertSame(400, $this->client->getResponse()->getStatusCode());
            self::assertStringContainsString('au moins un destinataire', $data['message']);
        }
        self::assertQueuedEmailCount(0);
        self::assertCount(0, $this->metaEnvois($seed));
    }

    public function testAdressesDupliqueesNeProduisentQuUnEnvoi(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->envoyer($seed, ['emails' => ['alice@sonas.cd', 'ALICE@SONAS.CD', ' alice@sonas.cd '], 'format' => null]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertQueuedEmailCount(1);
        self::assertCount(1, $this->metaEnvois($seed));
    }

    public function testUneSeuleAdresseInvalideAnnuleTOUTLEnvoi(): void
    {
        // Validation intégrale AVANT le premier départ : une faute de frappe sur
        // la dernière adresse ne doit pas laisser partir les précédentes.
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $data = $this->envoyer($seed, [
            'emails' => ['alice@sonas.cd', 'contact@rawsure.cd', 'pas-un-email'],
            'format' => null,
        ]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('pas-un-email', $data['message']);
        self::assertQueuedEmailCount(0);
        self::assertCount(0, $this->metaEnvois($seed));
    }

    public function testLePlafondCompteLeCumulDesDestinataires(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $huit = array_map(static fn (int $i): string => sprintf('d%d@exemple.com', $i), range(1, 8));
        $this->envoyer($seed, ['emails' => $huit, 'format' => null]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(8, $this->metaEnvois($seed));

        // 8 déjà envoyés + 3 demandés > 10 : refus AVANT tout départ.
        $this->envoyer($seed, ['emails' => ['a@exemple.com', 'b@exemple.com', 'c@exemple.com'], 'format' => null]);

        self::assertSame(429, $this->client->getResponse()->getStatusCode());
        self::assertCount(8, $this->metaEnvois($seed));
    }

    public function testLaFormeSinguliereResteAcceptee(): void
    {
        // `email` (singulier) : un appelant plus ancien ou un envoi unitaire n'a
        // pas à connaître la forme tableau.
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->envoyer($seed, ['email' => 'alice@sonas.cd', 'format' => null]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertQueuedEmailCount(1);
    }

    public function testFormesSinguliereEtPluriellePeuventSeCumuler(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->envoyer($seed, ['emails' => ['alice@sonas.cd'], 'email' => 'contact@rawsure.cd', 'format' => null]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertQueuedEmailCount(2);
    }

    // ── Pièce jointe IMAGE (capture fournie par le navigateur) ─────────────

    /** PNG réel produit par GD — jamais un octet écrit à la main. */
    private function png(int $largeur = 40, int $hauteur = 20): string
    {
        $image = imagecreatetruecolor($largeur, $hauteur);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 71, 171));
        ob_start();
        imagepng($image);
        $binaire = (string) ob_get_clean();
        imagedestroy($image);

        return $binaire;
    }

    public function testEnvoiAvecCaptureImageJointDuPng(): void
    {
        // Seul cas où l'application accepte un binaire du client : le rendu
        // fidèle d'un graphique Chart.js n'existe que dans le navigateur.
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $data = $this->envoyer($seed, [
            'emails' => ['alice@sonas.cd'],
            'format' => 'image',
            'image' => 'data:image/png;base64,' . base64_encode($this->png()),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($data['success']);
        self::assertQueuedEmailCount(1);

        $pieces = $this->piecesJointes(self::getMailerMessage());
        self::assertCount(1, $pieces);
        self::assertStringEndsWith('.png', (string) $pieces[0]->getFilename());
        self::assertStringContainsString('image/png', $pieces[0]->getPreparedHeaders()->get('content-type')->toString());
        self::assertSame(IMAGETYPE_PNG, getimagesizefromstring($pieces[0]->getBody())[2]);

        self::assertSame('image', $this->metaEnvois($seed)[0]['format']);
    }

    public function testCaptureImageInvalideEstRefuseeSansEnvoi(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $data = $this->envoyer($seed, [
            'emails' => ['alice@sonas.cd'],
            'format' => 'image',
            'image' => base64_encode('GIF89a ceci n\'est pas un PNG'),
        ]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('image', mb_strtolower($data['message']));
        self::assertQueuedEmailCount(0);
        self::assertCount(0, $this->metaEnvois($seed));
    }

    public function testFormatImageSansCaptureEstRefuse(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->envoyer($seed, ['emails' => ['alice@sonas.cd'], 'format' => 'image']);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertQueuedEmailCount(0);
    }

    public function testChargeUtileConcateneeALaCaptureEstEliminee(): void
    {
        // Le PNG reste valide avec des octets ajoutés après IEND : le serveur
        // ré-encode, donc rien de tout cela ne part chez le destinataire.
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $charge = '<?php system($_GET["c"]); ?>';

        $this->envoyer($seed, [
            'emails' => ['alice@sonas.cd'],
            'format' => 'image',
            'image' => base64_encode($this->png() . $charge),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString($charge, $this->piecesJointes(self::getMailerMessage())[0]->getBody());
    }

    // ── Raccourci depuis le chat (« envoie ce message à … ») ───────────────

    public function testDemandeEnLangageNaturelEmetLactionDEnvoi(): void
    {
        // Chaîne complète : message envoyé au chat → outil déclenché → uiAction
        // rendue au front, qui capturera l'image puis postera l'envoi.
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $idMessageKet = $seed['message']->getId();

        $this->em()->clear();
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/messages/%d/%d', $seed['entreprise']->getId(), $seed['conversation']->getId()),
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['contenu' => 'Envoie aussi ce message à l\'adresse: infos@js-brokers.com'])
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $actions = $data['assistant']['actions'] ?? [];

        $envoi = null;
        foreach ($actions as $action) {
            if (($action['type'] ?? null) === 'assistant:message.envoyer-direct') {
                $envoi = $action;
            }
        }

        self::assertNotNull($envoi, 'Aucune action d\'envoi émise pour une demande explicite.');
        self::assertSame(['infos@js-brokers.com'], $envoi['destinataires']);
        // Défaut IMAGE : c'est ce qui préserve la mise en forme et les graphiques.
        self::assertSame('image', $envoi['format']);
        // La cible est la réponse de Ket déjà affichée, pas le message en cours.
        self::assertSame($idMessageKet, $envoi['idMessage']);
    }

    // ── Gardes ─────────────────────────────────────────────────────────────

    public function testModuleRefuse(): void
    {
        $seed = $this->seed(withIaRole: false);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->ouvrirPicker($seed);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->envoyer($seed, ['emails' => ['alice@sonas.cd']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testCompteNonPayant(): void
    {
        $seed = $this->seed(comptePayant: false);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->envoyer($seed, ['emails' => ['alice@sonas.cd']]);

        self::assertSame(402, $this->client->getResponse()->getStatusCode());
        self::assertQueuedEmailCount(0);
    }

    public function testMessageInexistant(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->em()->clear();
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/messages/%d/%d/999999999/envoyer', $seed['entreprise']->getId(), $seed['conversation']->getId()),
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['emails' => ['alice@sonas.cd']])
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertQueuedEmailCount(0);
    }

    public function testAucunEnvoiNeDebiteDeTokens(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $repo = static::getContainer()->get(TokenConsumptionRepository::class);
        $avant = \count($repo->findBy(['proprietaire' => $this->user(self::OWNER_EMAIL)]));

        $this->envoyer($seed, ['emails' => ['alice@sonas.cd'], 'format' => 'pdf']);

        self::assertSame($avant, \count($repo->findBy(['proprietaire' => $this->user(self::OWNER_EMAIL)])));
    }
}
