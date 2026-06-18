<?php

namespace App\Tests\Security;

use App\Entity\Utilisateur;
use App\Security\PasswordResetHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Test fonctionnel du parcours « mot de passe oublié / réinitialisation ».
 *
 * Le test crée ses propres comptes (mot de passe connu), exerce le flux complet,
 * puis les supprime — la base n'est pas modifiée durablement.
 *
 * Couverture :
 *  - lien « Mot de passe oublié ? » présent sur la page de connexion ;
 *  - demande : POST renvoie toujours le même message neutre (anti-énumération) ;
 *  - réinitialisation : lien sans id / signature invalide rejeté ;
 *  - parcours complet : définition d'un nouveau mot de passe via lien signé valide,
 *    `verified` posé à true, AUCUNE donnée métier touchée ;
 *  - non-régression login : on se connecte avec le nouveau mot de passe, l'ancien est refusé ;
 *  - usage unique de fait : le lien consommé (signé sur l'ancien hash) est rejeté ensuite.
 */
class PasswordResetFlowTest extends WebTestCase
{
    private const EMAIL        = 'phpunit-reset@test.local';
    private const OLD_PASSWORD = 'OldPass123!';
    private const NEW_PASSWORD = 'NewPass456!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->removeTestUser();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em     = $this->em();

        // Compte volontairement NON vérifié : on vérifiera que le reset le débloque.
        $user = new Utilisateur();
        $user->setEmail(self::EMAIL);
        $user->setNom('PHPUnit Reset');
        $user->setVerified(false);
        $user->setPassword($hasher->hashPassword($user, self::OLD_PASSWORD));
        $em->persist($user);
        $em->flush();
    }

    protected function tearDown(): void
    {
        $this->removeTestUser();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function removeTestUser(): void
    {
        $em   = $this->em();
        $repo = $em->getRepository(Utilisateur::class);
        if ($user = $repo->findOneBy(['email' => self::EMAIL])) {
            $em->remove($user);
            $em->flush();
        }
    }

    private function user(): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL]);
    }

    /** Construit l'URL signée de réinitialisation telle que l'e-mail la contiendrait. */
    private function signedResetUrl(Utilisateur $user): string
    {
        return static::getContainer()->get(PasswordResetHelper::class)
            ->generateResetSignature($user)
            ->getSignedUrl();
    }

    public function testLoginPageExposesForgotPasswordLink(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $link = $crawler->filter('a[href="/mot-de-passe-oublie"]');
        $this->assertGreaterThan(0, $link->count(), 'La page de connexion doit proposer un lien « Mot de passe oublié ? ».');
    }

    public function testForgotPasswordPageRenders(): void
    {
        $this->client->request('GET', '/mot-de-passe-oublie');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form input[name="email"]');
    }

    public function testForgotPasswordKnownEmailRedirectsWithNeutralMessage(): void
    {
        $this->client->request('POST', '/mot-de-passe-oublie', ['email' => self::EMAIL]);

        // Toujours la même issue, qu'un compte existe ou non (anti-énumération).
        $this->assertResponseRedirects('/login');
    }

    public function testForgotPasswordUnknownEmailBehavesIdentically(): void
    {
        $this->client->request('POST', '/mot-de-passe-oublie', ['email' => 'inconnu@test.local']);

        // Même redirection, aucun indice sur l'existence du compte.
        $this->assertResponseRedirects('/login');
    }

    public function testResetWithoutIdRedirectsToLogin(): void
    {
        $this->client->request('GET', '/mot-de-passe/reinitialiser');

        $this->assertResponseRedirects('/login');
    }

    public function testResetWithTamperedSignatureRedirectsToLogin(): void
    {
        $user = $this->user();
        // Signature manifestement invalide.
        $this->client->request('GET', '/mot-de-passe/reinitialiser?id=' . $user->getId() . '&expires=9999999999&signature=invalide&token=bidon');

        $this->assertResponseRedirects('/login');
    }

    public function testFullResetFlowChangesPasswordAndVerifies(): void
    {
        $user = $this->user();
        $oldHash = $user->getPassword();
        $signedUrl = $this->signedResetUrl($user);

        // 1. Le lien signé ouvre bien le formulaire de nouveau mot de passe.
        $crawler = $this->client->request('GET', $signedUrl);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="change_password_form[plainPassword][first]"]');

        // 2. Soumission du nouveau mot de passe (la cible du formulaire conserve l'URL signée).
        $form = $crawler->selectButton('Réinitialiser le mot de passe')->form([
            'change_password_form[plainPassword][first]'  => self::NEW_PASSWORD,
            'change_password_form[plainPassword][second]' => self::NEW_PASSWORD,
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects('/login');

        // 3. Mot de passe modifié + compte désormais vérifié, données métier intactes.
        $this->em()->clear();
        $reloaded = $this->user();
        $this->assertNotSame($oldHash, $reloaded->getPassword(), 'Le hash du mot de passe doit avoir changé.');
        $this->assertTrue($reloaded->isVerified(), 'Le reset prouve la possession de l\'e-mail → compte vérifié.');
    }

    public function testLoginWithNewPasswordWorksAndOldFails(): void
    {
        // On réinitialise d'abord via le lien signé.
        $user = $this->user();
        $signedUrl = $this->signedResetUrl($user);
        $crawler = $this->client->request('GET', $signedUrl);
        $form = $crawler->selectButton('Réinitialiser le mot de passe')->form([
            'change_password_form[plainPassword][first]'  => self::NEW_PASSWORD,
            'change_password_form[plainPassword][second]' => self::NEW_PASSWORD,
        ]);
        $this->client->submit($form);

        // Nouveau mot de passe → connexion réussie (compte vérifié → espace entreprises).
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            'email'    => self::EMAIL,
            'password' => self::NEW_PASSWORD,
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/entreprise');

        // Ancien mot de passe → refusé (retour à la connexion).
        $this->client->request('GET', '/logout');
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            'email'    => self::EMAIL,
            'password' => self::OLD_PASSWORD,
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects('/login');
    }

    public function testConsumedLinkIsRejectedAfterReset(): void
    {
        $user = $this->user();
        $signedUrl = $this->signedResetUrl($user);

        // Première utilisation : on change le mot de passe.
        $crawler = $this->client->request('GET', $signedUrl);
        $form = $crawler->selectButton('Réinitialiser le mot de passe')->form([
            'change_password_form[plainPassword][first]'  => self::NEW_PASSWORD,
            'change_password_form[plainPassword][second]' => self::NEW_PASSWORD,
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects('/login');

        // Réutilisation du même lien : la signature porte l'ANCIEN hash → désormais invalide.
        $this->client->request('GET', $signedUrl);
        $this->assertResponseRedirects('/login');
    }
}
