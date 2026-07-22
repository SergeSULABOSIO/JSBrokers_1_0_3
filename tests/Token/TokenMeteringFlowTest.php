<?php

namespace App\Tests\Token;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\TokenConsumptionRepository;
use App\Services\ServiceInitialisationEntreprise;
use App\Services\ServiceSuppressionEntreprise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Vérifie au runtime la chaîne complète de métrage en LECTURE sur un endpoint
 * de liste réel du workspace (contrôleur CRUD utilisant ControllerUtilsTrait) :
 *  - l'injection par setter #[Required] du TokenAccountService fonctionne ;
 *  - une consultation de liste journalise une consommation (sens sortie) ;
 *  - un propriétaire à sec voit la lecture BLOQUÉE (panneau de blocage).
 */
class TokenMeteringFlowTest extends WebTestCase
{
    private const EMAIL = 'phpunit-metering@test.local';
    private const PASSWORD = 'Test1234!';

    private KernelBrowser $client;
    private int $entrepriseId;
    private int $inviteId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        $user = new Utilisateur();
        $user->setEmail(self::EMAIL);
        $user->setNom('PHPUnit Metering');
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $em->persist($user);

        $entreprise = new Entreprise();
        $entreprise->setNom('PHPUnit Metering SARL');
        $entreprise->setLicence('LIC-MET');
        $entreprise->setAdresse('1 rue du Token');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-MET');
        $entreprise->setIdnat('IDNAT-MET');
        $entreprise->setNumimpot('IMP-MET');
        $entreprise->setUtilisateur($user);
        $em->persist($entreprise);

        $invite = new Invite();
        $invite->setNom('Administrateur');
        $invite->setUtilisateur($user);
        $invite->setEntreprise($entreprise);
        $invite->setProprietaire(true);
        $em->persist($invite);

        // Données par défaut (monnaies, taxes, chargements, types de revenu,
        // risques) comme à la création réelle : la liste des risques sera donc
        // non vide → le métrage en lecture a matière à s'appliquer.
        static::getContainer()->get(ServiceInitialisationEntreprise::class)
            ->initialiser($entreprise, $invite);

        $em->flush();
        $this->entrepriseId = $entreprise->getId();
        $this->inviteId = $invite->getId();
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
        $em = $this->em();

        // Suppression propre de l'entreprise et de toutes ses données scopées
        // (monnaies, taxes, risques… seedées) via le service métier dédié.
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy(['nom' => 'PHPUnit Metering SARL']);
        if ($entreprise) {
            static::getContainer()->get(ServiceSuppressionEntreprise::class)->supprimer($entreprise);
        }

        $conn = $em->getConnection();
        $conn->executeStatement(
            "DELETE tc FROM token_consumption tc LEFT JOIN utilisateur u ON tc.proprietaire_id = u.id WHERE u.email = :e",
            ['e' => self::EMAIL]
        );
        $conn->executeStatement(
            "DELETE i FROM invite i LEFT JOIN utilisateur u ON i.utilisateur_id = u.id WHERE u.email = :e",
            ['e' => self::EMAIL]
        );
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :e", ['e' => self::EMAIL]);
    }

    private function user(): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL]);
    }

    private function listUrl(): string
    {
        // Liste des risques : non vide après initialisation → matière à métrer.
        return '/admin/risque/index/' . $this->inviteId . '/' . $this->entrepriseId;
    }

    public function testListReadIsMeteredAndLogged(): void
    {
        $this->client->loginUser($this->user());
        $this->client->request('GET', $this->listUrl());

        // La page de liste se charge (le métrage ne casse rien) ...
        $this->assertResponseIsSuccessful();

        // ... et la consommation en lecture est journalisée pour le propriétaire.
        $repo = static::getContainer()->get(TokenConsumptionRepository::class);
        $logs = $repo->findBy(['proprietaire' => $this->user()->getId(), 'sens' => 'sortie']);
        $this->assertNotEmpty($logs, 'Une consommation en sortie doit être journalisée.');
    }

    /**
     * Page « Détails de consommation » : la colonne Coût suit la notation de la
     * langue active, comme les compteurs de la même ligne — décimale virgule et
     * milliers espace en français, l'inverse en anglais.
     */
    public function testCostColumnFollowsActiveLanguage(): void
    {
        $this->client->loginUser($this->user());
        $this->client->request('GET', $this->listUrl()); // génère une consommation

        $cout = fn (string $lang): string => trim(
            $this->client->request('GET', '/admin/tokens?lang=' . $lang)
                ->filter('.tkp-table tbody tr')->first()
                ->filter('td.tkp-num')->eq(3)->text()
        );

        $this->assertMatchesRegularExpression('/^\d{1,3}(?: \d{3})*,\d{4} \$$/', $cout('fr'));
        $this->assertMatchesRegularExpression('/^\d{1,3}(?:,\d{3})*\.\d{4} \$$/', $cout('en'));
    }

    public function testReadBlockedWhenOwnerHasNoTokens(): void
    {
        // On vide le solde du propriétaire dans une fenêtre fraîche (pas de renouvellement).
        $em = $this->em();
        $user = $this->user();
        $user->setFreeTokens(0);
        $user->setPaidTokens(0);
        $user->setFreeWindowStartedAt(new \DateTimeImmutable());
        $em->flush();

        $this->client->loginUser($this->user());
        $this->client->request('GET', $this->listUrl());

        // La réponse reste 200 mais affiche le panneau de blocage à la place de la liste.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.tokens-blocked');
        // Échéance annoncée comme un instant, affiché dans l'horloge de référence
        // de l'application (même règle que le widget de solde).
        $this->assertSelectorExists('.tokens-blocked time[data-controller="app-datetime"][datetime]');
    }
}
