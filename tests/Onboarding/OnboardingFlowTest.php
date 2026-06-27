<?php

namespace App\Tests\Onboarding;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Services\ServiceSuppressionEntreprise;
use App\Token\TokenAccountService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Test fonctionnel du parcours d'onboarding self-service du courtier.
 *
 * Couverture :
 *  - un courtier vérifié SANS espace voit l'assistant (GET /onboarding → 200) ;
 *  - la soumission crée l'entreprise + l'invité propriétaire, SANS débiter de token
 *    (première entreprise offerte), et redirige directement dans l'espace (?welcome=1) ;
 *  - l'espace fraîchement créé affiche le panneau d'accueil (étapes suggérées) ;
 *  - un utilisateur disposant déjà d'un espace (invitation) est renvoyé vers la liste.
 *
 * Le test crée ses propres comptes et purge intégralement les entreprises créées
 * (données scopées comprises) via ServiceSuppressionEntreprise — la base n'est pas
 * modifiée durablement.
 */
class OnboardingFlowTest extends WebTestCase
{
    private const NEW_EMAIL      = 'phpunit-onboarding-new@test.local';
    private const MEMBER_EMAIL   = 'phpunit-onboarding-member@test.local';
    private const PASSWORD       = 'Test1234!';
    private const COMPANY_NOM    = 'Cabinet Onboarding PHPUnit';
    private const MEMBER_COMPANY = 'PHPUnit Member SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em     = $this->em();

        // Courtier neuf : vérifié, mais SANS entreprise ni invitation.
        $new = new Utilisateur();
        $new->setEmail(self::NEW_EMAIL);
        $new->setNom('PHPUnit Neuf');
        $new->setVerified(true);
        $new->setPassword($hasher->hashPassword($new, self::PASSWORD));
        $em->persist($new);

        // Utilisateur établi : vérifié, avec une entreprise et son invité propriétaire.
        $member = new Utilisateur();
        $member->setEmail(self::MEMBER_EMAIL);
        $member->setNom('PHPUnit Membre');
        $member->setVerified(true);
        $member->setPassword($hasher->hashPassword($member, self::PASSWORD));
        $em->persist($member);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::MEMBER_COMPANY);
        $entreprise->setLicence('LIC-MEMBER');
        $entreprise->setAdresse('2 rue du Test');
        $entreprise->setTelephone('+243000000001');
        // rccm / idnat / numimpot sont NOT NULL en base.
        $entreprise->setRccm('RCCM-MEMBER');
        $entreprise->setIdnat('IDNAT-MEMBER');
        $entreprise->setNumimpot('IMP-MEMBER');
        $entreprise->setUtilisateur($member);
        $em->persist($entreprise);

        $invite = new Invite();
        $invite->setNom('Administrateur');
        $invite->setUtilisateur($member);
        $invite->setEntreprise($entreprise);
        $invite->setProprietaire(true);
        $em->persist($invite);

        $em->flush();
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
        $suppression = static::getContainer()->get(ServiceSuppressionEntreprise::class);
        $repo        = $this->em()->getRepository(Entreprise::class);

        // Purge intégrale des entreprises de test (données scopées + invités compris).
        foreach ([self::COMPANY_NOM, self::MEMBER_COMPANY] as $nom) {
            foreach ($repo->findBy(['nom' => $nom]) as $entreprise) {
                $suppression->supprimer($entreprise);
            }
        }

        $this->em()->getConnection()->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:emails)',
            ['emails' => [self::NEW_EMAIL, self::MEMBER_EMAIL]],
            ['emails' => ArrayParameterType::STRING]
        );
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    public function testNewBrokerSeesOnboardingAssistant(): void
    {
        $this->client->loginUser($this->user(self::NEW_EMAIL));
        $this->client->request('GET', '/onboarding');

        $this->assertResponseIsSuccessful();
        // Le formulaire allégé porte bien les champs requis + pays/ville.
        $this->assertSelectorExists('select[name="onboarding_entreprise[pays]"]');
        $this->assertSelectorExists('input[name="onboarding_entreprise[nom]"]');
    }

    public function testUserWithWorkspaceIsRedirectedAwayFromOnboarding(): void
    {
        // L'utilisateur établi (entreprise + invitation) n'a rien à faire dans l'assistant.
        $this->client->loginUser($this->user(self::MEMBER_EMAIL));
        $this->client->request('GET', '/onboarding');

        $this->assertResponseRedirects('/admin/entreprise');
    }

    public function testOnboardingCreatesWorkspaceWithoutTokenDebitAndRedirectsIntoIt(): void
    {
        $tokens = static::getContainer()->get(TokenAccountService::class);

        $this->client->loginUser($this->user(self::NEW_EMAIL));
        $crawler = $this->client->request('GET', '/onboarding');
        $this->assertResponseIsSuccessful();

        $balanceBefore = $tokens->getBalance($this->user(self::NEW_EMAIL));

        $form = $crawler->selectButton('Créer mon espace de travail')->form();
        $form['onboarding_entreprise[nom]']       = self::COMPANY_NOM;
        $form['onboarding_entreprise[pays]']->select('250'); // France (présent dans pays_villes.json)
        $form['onboarding_entreprise[adresse]']   = '1 rue du Test';
        $form['onboarding_entreprise[telephone]'] = '+243000000000';
        $form['onboarding_entreprise[licence]']   = 'LIC-ONB';
        $this->client->submit($form);

        // Redirection DIRECTE dans l'espace de travail fraîchement créé, avec ?welcome=1.
        $location = $this->client->getResponse()->headers->get('Location');
        $this->assertMatchesRegularExpression('#^/espacedetravail/\d+/\d+\?welcome=1$#', (string) $location);

        // Entreprise + invité propriétaire bien créés et rattachés au courtier.
        $this->em()->clear();
        $created = $this->em()->getRepository(Entreprise::class)->findOneBy(['nom' => self::COMPANY_NOM]);
        $this->assertNotNull($created, "L'entreprise doit être créée par l'assistant.");
        $this->assertSame(self::NEW_EMAIL, $created->getUtilisateur()->getEmail());

        $invite = $this->em()->getRepository(Invite::class)->findOneBy(['entreprise' => $created, 'proprietaire' => true]);
        $this->assertNotNull($invite, "L'invité propriétaire doit être créé.");

        // Première entreprise OFFERTE : aucun token débité par l'assistant. On compare
        // les soldes (et non les horodatages de fenêtre, qui varient à la microseconde).
        $balanceAfter = $tokens->getBalance($this->user(self::NEW_EMAIL));
        $this->assertSame($balanceBefore['free'], $balanceAfter['free'], 'Aucun token gratuit ne doit être débité.');
        $this->assertSame($balanceBefore['paid'], $balanceAfter['paid'], 'Aucun token prépayé ne doit être débité.');
        $this->assertSame($balanceBefore['total'], $balanceAfter['total'], 'Le solde total doit être inchangé.');
    }

    public function testFreshWorkspaceShowsWelcomePanel(): void
    {
        $this->client->loginUser($this->user(self::NEW_EMAIL));

        // On crée l'espace via l'assistant…
        $crawler = $this->client->request('GET', '/onboarding');
        $form = $crawler->selectButton('Créer mon espace de travail')->form();
        $form['onboarding_entreprise[nom]']       = self::COMPANY_NOM;
        $form['onboarding_entreprise[pays]']->select('250');
        $form['onboarding_entreprise[adresse]']   = '1 rue du Test';
        $form['onboarding_entreprise[telephone]'] = '+243000000000';
        $form['onboarding_entreprise[licence]']   = 'LIC-ONB';
        $this->client->submit($form);

        // … puis on suit la redirection vers l'espace : le panneau d'accueil est présent.
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.ws-welcome');
        $this->assertSelectorExists('.ws-step-card');
    }
}
