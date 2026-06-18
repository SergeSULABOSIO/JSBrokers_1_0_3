<?php

namespace App\Tests\Security;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Test fonctionnel du parcours de connexion et de l'imposition de la vérification d'e-mail.
 *
 * Le test crée ses propres comptes (un vérifié, un non vérifié) avec un mot de passe
 * connu, exerce le flux complet, puis les supprime — la base n'est pas modifiée durablement.
 *
 * Couverture :
 *  - page de login accessible anonymement ;
 *  - décision de l'authenticator (POST réel) : vérifié → liste entreprises, non vérifié → re-vérification ;
 *  - garde-fou global (EmailVerificationSubscriber) : un non vérifié ne peut atteindre aucune page protégée.
 */
class LoginFlowTest extends WebTestCase
{
    private const VERIFIED_EMAIL   = 'phpunit-verified@test.local';
    private const UNVERIFIED_EMAIL = 'phpunit-unverified@test.local';
    private const PASSWORD         = 'Test1234!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Nettoyage défensif (au cas où un run précédent aurait échoué avant tearDown).
        $this->removeTestUsers();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em     = $this->em();

        foreach ([self::VERIFIED_EMAIL => true, self::UNVERIFIED_EMAIL => false] as $email => $verified) {
            $user = new Utilisateur();
            $user->setEmail($email);
            $user->setNom('PHPUnit');
            $user->setVerified($verified);
            $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
            $em->persist($user);
        }
        $em->flush();
    }

    protected function tearDown(): void
    {
        $this->removeTestUsers();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        // Récupéré frais à chaque appel : le client peut redémarrer le kernel entre deux requêtes.
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function removeTestUsers(): void
    {
        $em   = $this->em();
        $repo = $em->getRepository(Utilisateur::class);
        foreach ([self::VERIFIED_EMAIL, self::UNVERIFIED_EMAIL] as $email) {
            if ($user = $repo->findOneBy(['email' => $email])) {
                $em->remove($user);
            }
        }
        $em->flush();
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    public function testLoginPageRendersForAnonymous(): void
    {
        $this->client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form input[name="email"]');
        $this->assertSelectorExists('form input[name="password"]');
    }

    public function testVerifiedUserReachesEntrepriseList(): void
    {
        $this->client->loginUser($this->user(self::VERIFIED_EMAIL));
        $this->client->request('GET', '/admin/entreprise');

        // L'utilisateur vérifié n'est PAS intercepté par le subscriber.
        $this->assertResponseIsSuccessful();
    }

    public function testUnverifiedUserIsRedirectedToReverify(): void
    {
        $this->client->loginUser($this->user(self::UNVERIFIED_EMAIL));
        $this->client->request('GET', '/admin/entreprise');

        // Le subscriber confine l'utilisateur non vérifié au parcours de re-vérification.
        $this->assertResponseRedirects('/reverify/email');
    }

    public function testUnverifiedDeepLinkIsBlocked(): void
    {
        // Tentative d'accès direct (deep-link) à une page protégée : doit être bloquée.
        $this->client->loginUser($this->user(self::UNVERIFIED_EMAIL));
        $this->client->request('GET', '/admin/entreprise/create');

        $this->assertResponseRedirects('/reverify/email');
    }

    public function testFormLoginVerifiedRedirectsToEntreprise(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            'email'    => self::VERIFIED_EMAIL,
            'password' => self::PASSWORD,
        ]);
        $this->client->submit($form);

        // Décision de l'authenticator : utilisateur vérifié → accueil applicatif.
        $this->assertResponseRedirects('/admin/entreprise');
    }

    public function testFormLoginUnverifiedRedirectsToReverify(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            'email'    => self::UNVERIFIED_EMAIL,
            'password' => self::PASSWORD,
        ]);
        $this->client->submit($form);

        // Décision de l'authenticator : non vérifié → re-vérification (target path ignoré).
        $this->assertResponseRedirects('/reverify/email');
    }

    /**
     * Non-régression ERR_TOO_MANY_REDIRECTS : la page de re-vérification doit être
     * une impasse 200 (et NON se rediriger vers elle-même). Avant correctif, elle
     * se terminait par un `$security->login()` qui ré-exécutait l'authenticator et
     * renvoyait vers `/reverify/email` → boucle infinie.
     */
    public function testReverifyPageIsTerminalAndDoesNotLoop(): void
    {
        $this->client->loginUser($this->user(self::UNVERIFIED_EMAIL));
        $this->client->request('GET', '/reverify/email');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Vérifiez votre adresse e-mail');
    }

    /**
     * Le renvoi explicite (clic sur « Renvoyer le lien ») envoie l'e-mail puis
     * repasse en GET « nu » (motif PRG) — pas de boucle, pas de renvoi au refresh.
     */
    public function testReverifyResendRedirectsBackToTerminalPage(): void
    {
        $this->client->loginUser($this->user(self::UNVERIFIED_EMAIL));
        $this->client->request('GET', '/reverify/email?send=1');

        $this->assertResponseRedirects('/reverify/email');
    }

    /**
     * Un utilisateur déjà vérifié qui atterrit sur la re-vérification est renvoyé
     * vers l'espace applicatif (il n'a rien à y faire).
     */
    public function testReverifyVerifiedUserIsRedirectedToEntreprise(): void
    {
        $this->client->loginUser($this->user(self::VERIFIED_EMAIL));
        $this->client->request('GET', '/reverify/email');

        $this->assertResponseRedirects('/admin/entreprise');
    }

    /**
     * La route de validation d'e-mail est PUBLIQUE : un accès anonyme ne doit PAS
     * être renvoyé vers la connexion (ancien comportement avec denyAccessUnlessGranted).
     * Sans paramètre `id` exploitable, on renvoie vers l'inscription.
     */
    public function testVerifyEmailRouteIsPublic(): void
    {
        $this->client->request('GET', '/verify/email');

        // Pas de redirection vers /login : la page n'exige plus d'être authentifié.
        $this->assertResponseRedirects('/register');
    }
}
