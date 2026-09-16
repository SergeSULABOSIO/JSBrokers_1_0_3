<?php

namespace App\Tests\Ai;

use App\Ai\Debit\BudgetDebit;
use App\Ai\Dictee\FinisseurDeDictee;
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
 * Route de finition d'une dictée : mêmes gardes que l'envoi d'un message (module IA,
 * premium, taille), 402 quand le solde ne couvre pas la finition, et facturation
 * SEULEMENT quand le texte a réellement été mis au propre.
 */
class AssistantIaDicteeTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-dictee-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-dictee-guest@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Dictee SARL';

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

    private function utilisateur(string $email): Utilisateur
    {
        $u = (new Utilisateur())->setEmail($email)->setNom('Dictée')->setVerified(true)->setPassword('x');
        $this->em()->persist($u);

        return $u;
    }

    /** @return array{entreprise: Entreprise, owner: Utilisateur, guest: Utilisateur} */
    private function semer(int $tokensPayants = 1_000_000): array
    {
        $em = $this->em();
        $owner = $this->utilisateur(self::OWNER_EMAIL);
        $owner->setPaidTokens($tokensPayants);
        $owner->setFreeTokens(0);
        $owner->setFreeWindowStartedAt(new \DateTimeImmutable());

        $e = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $e->setUtilisateur($owner);
        $em->persist($e);
        $owner->setConnectedTo($e);

        $invite = (new Invite())->setNom('Propriétaire')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($e);
        $em->persist($invite);

        // Invité SANS le module IA.
        $guest = $this->utilisateur(self::GUEST_EMAIL);
        $guest->setConnectedTo($e);
        $sansIa = (new Invite())->setNom('Sans IA')->setProprietaire(false);
        $sansIa->setUtilisateur($guest)->setEntreprise($e);
        $em->persist($sansIa);

        $em->flush();

        return ['entreprise' => $e, 'owner' => $owner, 'guest' => $guest];
    }

    private function poster(Entreprise $e, string $texte): array
    {
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/dictee/%d', $e->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['texte' => $texte]),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function lignesDictee(): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM token_consumption tc JOIN utilisateur u ON tc.proprietaire_id = u.id
             WHERE u.email = :email AND tc.entite_nom = :entite',
            ['email' => self::OWNER_EMAIL, 'entite' => TokenAccountService::ENTITE_DICTEE_IA],
        );
    }

    public function testMoteurSimuleLeTexteRevientBrutEtGratuit(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->semer();
        $this->client->loginUser($owner);

        $data = $this->poster($e, 'euh bonjour Ket');

        self::assertResponseIsSuccessful();
        self::assertSame(['texte' => 'euh bonjour Ket', 'finie' => false], $data);
        self::assertSame(0, $this->lignesDictee());
    }

    public function testUneFinitionReussieEstFactureeAuForfait(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->semer();
        $this->client->loginUser($owner);
        static::getContainer()->set(FinisseurDeDictee::class, new FinisseurDeDictee(
            new MockHttpClient([new MockResponse(json_encode([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['texte' => 'Bonjour Ket, liste mes clients.'])]]]]],
            ]))]),
            new BudgetDebit(new ArrayAdapter()),
            new NullLogger(),
            'gm-test',
            'gemini-flash-lite-test',
            '',
        ));

        $data = $this->poster($e, 'euh bonjour Ket euh liste mes clients');

        self::assertResponseIsSuccessful();
        self::assertSame(['texte' => 'Bonjour Ket, liste mes clients.', 'finie' => true], $data);
        self::assertSame(1, $this->lignesDictee());
    }

    public function testSoldeInsuffisant402(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->semer(1);
        $this->client->loginUser($owner);

        $data = $this->poster($e, 'euh bonjour Ket');

        self::assertResponseStatusCodeSame(402);
        self::assertTrue($data['blocked']);
        self::assertSame(static::getContainer()->get(TokenAccountService::class)->coutDicteeIa(), $data['required']);
    }

    public function testTexteVide400(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->semer();
        $this->client->loginUser($owner);

        $this->poster($e, '   ');

        self::assertResponseStatusCodeSame(400);
    }

    public function testSansModuleIa403(): void
    {
        ['entreprise' => $e, 'guest' => $guest] = $this->semer();
        $this->client->loginUser($guest);

        $this->poster($e, 'euh bonjour Ket');

        self::assertResponseStatusCodeSame(403);
    }
}
