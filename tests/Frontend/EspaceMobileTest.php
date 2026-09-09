<?php

namespace App\Tests\Frontend;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\Utilisateur;
use App\Service\Terminal\DetecteurDeTerminal;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'ESPACE DE TRAVAIL SERT DEUX SURFACES, ET C'EST LE TERMINAL QUI CHOISIT.
 *
 * Même route, même URL, mêmes gardes : un téléphone reçoit la conversation avec Ket
 * en plein écran, un ordinateur garde les quatre colonnes. Un lien vers l'espace de
 * travail reste donc partageable d'un appareil à l'autre.
 *
 * ── CE QUE CE TEST PROTÈGE ───────────────────────────────────────────────────────
 *  - LA NON-RÉGRESSION DU BUREAU. La bascule est posée au milieu d'une action
 *    existante ; se tromper de sens y servirait la coquille mobile à tout le monde.
 *  - L'ÉCHAPPATOIRE. Cookie et `?terminal=` doivent primer sur le `User-Agent`,
 *    sinon la bascule « Afficher la version ordinateur » est un bouton qui ne tient
 *    pas — et plus personne ne peut vérifier le mode ordinateur depuis une tablette.
 *  - LA PORTE FERMÉE. Ket est verrouillée par deux conditions (module + compte
 *    payant). Sur ordinateur, un refus ne coûte qu'une rubrique ; sur téléphone, où
 *    il n'y a rien d'autre, un refus muet donnerait un écran vide. La page de refus
 *    doit donc exister, NOMMER la raison, et ne jamais proposer un achat à un
 *    invité qui ne peut pas l'effectuer.
 */
class EspaceMobileTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-mobile-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-mobile-guest@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit Mobile SARL';

    /** Marqueurs de MARKUP, choisis parce qu'ils ne peuvent pas apparaître ailleurs. */
    private const MARQUEUR_MOBILE = 'data-controller="ket-mobile"';
    private const MARQUEUR_BUREAU = 'class="interactive-menu"';

    private const UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    private const UA_IPAD = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    private const UA_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

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
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL];
        $noms = [self::ENTREPRISE_NOM];

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE m FROM assistant_message m
             JOIN assistant_conversation c ON m.conversation_id = c.id
             JOIN entreprise e ON c.entreprise_id = e.id
             WHERE e.nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => ArrayParameterType::STRING],
        );
        foreach (['assistant_conversation', 'assistant_parametres'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'DELETE r FROM roles_en_administration r
             JOIN invite i ON r.invite_id = i.id
             JOIN entreprise e ON i.entreprise_id = e.id
             WHERE e.nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email IN (:emails) OR e.nom IN (:noms)',
            ['emails' => $emails, 'noms' => $noms],
            ['emails' => ArrayParameterType::STRING, 'noms' => ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM entreprise WHERE nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => ArrayParameterType::STRING],
        );
    }

    private function makeUser(string $email): Utilisateur
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new Utilisateur();
        $user->setEmail($email);
        $user->setNom('PHPUnit Mobile');
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);

        return $user;
    }

    /**
     * @param bool $comptePayant crédite le propriétaire (Ket exige un solde payant)
     * @param bool $withIaRole   accorde à l'invité la lecture du module Assistant IA
     *
     * @return array{owner: Invite, guest: Invite, entreprise: Entreprise}
     */
    private function seed(bool $comptePayant = true, bool $withIaRole = true): array
    {
        $em = $this->em();

        $ownerUser = $this->makeUser(self::OWNER_EMAIL);
        if ($comptePayant) {
            $ownerUser->setPaidTokens(1_000_000);
        }

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-MOB');
        $entreprise->setAdresse('1 rue du Mobile');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-MOB');
        $entreprise->setIdnat('IDNAT-MOB');
        $entreprise->setNumimpot('IMP-MOB');
        $entreprise->setUtilisateur($ownerUser);
        $em->persist($entreprise);
        $ownerUser->setConnectedTo($entreprise);

        $ownerInvite = new Invite();
        $ownerInvite->setNom('Administrateur');
        $ownerInvite->setUtilisateur($ownerUser);
        $ownerInvite->setEntreprise($entreprise);
        $ownerInvite->setProprietaire(true);
        $em->persist($ownerInvite);

        $guestUser = $this->makeUser(self::GUEST_EMAIL);
        $guestUser->setConnectedTo($entreprise);
        $guestInvite = new Invite();
        $guestInvite->setNom('Collaborateur');
        $guestInvite->setUtilisateur($guestUser);
        $guestInvite->setEntreprise($entreprise);
        $guestInvite->setProprietaire(false);
        $em->persist($guestInvite);

        if ($withIaRole) {
            $roleIa = new RolesEnAdministration();
            $roleIa->setNom('Rôle module IA');
            $roleIa->setAccessAssistantIa([Invite::ACCESS_LECTURE]);
            $roleIa->setEntreprise($entreprise);
            $guestInvite->addRolesEnAdministration($roleIa);
            $em->persist($roleIa);
        }

        $em->flush();

        return ['owner' => $ownerInvite, 'guest' => $guestInvite, 'entreprise' => $entreprise];
    }

    private function ouvrirEspace(Invite $invite, Entreprise $entreprise, string $ua, string $suffixe = ''): string
    {
        $url = sprintf('/espacedetravail/%d/%d%s', $invite->getId(), $entreprise->getId(), $suffixe);
        $this->client->request('GET', $url, [], [], ['HTTP_USER_AGENT' => $ua]);

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * UN TÉLÉPHONE REÇOIT LA CONVERSATION, PAS LES COLONNES.
     */
    public function testUnTelephoneRecoitLaCoquilleKet(): void
    {
        $data = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $html = $this->ouvrirEspace($data['owner'], $data['entreprise'], self::UA_IPHONE);

        self::assertStringContainsString(self::MARQUEUR_MOBILE, $html);
        self::assertStringNotContainsString(
            self::MARQUEUR_BUREAU,
            $html,
            "La coquille à colonnes ne doit pas être servie à un téléphone : c'est tout l'objet du mode Ket.",
        );
        // Le mode est publié au front : la sonde le compare, elle ne le devine pas.
        self::assertStringContainsString('data-terminal="mobile"', $html);
        // Les deux adresses dont la coquille a besoin sont posées par le serveur.
        self::assertStringContainsString('data-ket-mobile-conversations-url-value', $html);
        self::assertStringContainsString('data-ket-mobile-sortie-url-value', $html);
    }

    /**
     * UNE TABLETTE EST TRAITÉE COMME UN TÉLÉPHONE.
     *
     * Décision produit : mobile ET tablette travaillent en conversation. Le seul
     * endroit qui le dit est `Terminal::modeKet()` ; ce test vérifie que la bascule
     * de l'espace de travail s'y réfère bien, et n'a pas retenu « mobile » seul.
     */
    public function testUneTabletteRecoitAussiLaCoquilleKet(): void
    {
        $data = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $html = $this->ouvrirEspace($data['owner'], $data['entreprise'], self::UA_IPAD);

        self::assertStringContainsString(self::MARQUEUR_MOBILE, $html);
        self::assertStringContainsString('data-terminal="tablette"', $html);
    }

    /**
     * UN ORDINATEUR NE VOIT AUCUN CHANGEMENT.
     */
    public function testUnOrdinateurGardeLEspaceDeTravailComplet(): void
    {
        $data = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $html = $this->ouvrirEspace($data['owner'], $data['entreprise'], self::UA_WINDOWS);

        self::assertStringContainsString(self::MARQUEUR_BUREAU, $html);
        self::assertStringNotContainsString(self::MARQUEUR_MOBILE, $html);
        self::assertStringContainsString('data-terminal="ordinateur"', $html);
    }

    /**
     * LA BASCULE « VERSION ORDINATEUR » TIENT, ET ELLE SE MÉMORISE.
     *
     * Deux mécanismes, testés ensemble parce qu'ils n'ont de sens qu'ensemble : le
     * paramètre d'URL rend la page demandée dans le bon mode, et le cookie fait que
     * la page SUIVANTE s'en souvient. Sans le second, le premier lien interne cliqué
     * ramènerait le téléphone au mode Ket.
     */
    public function testLaBasculeVersLOrdinateurEstMemorisee(): void
    {
        $data = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $html = $this->ouvrirEspace(
            $data['owner'],
            $data['entreprise'],
            self::UA_IPHONE,
            '?' . DetecteurDeTerminal::PARAM . '=ordinateur',
        );

        self::assertStringContainsString(self::MARQUEUR_BUREAU, $html);
        // Le mode a été CHOISI : la sonde du navigateur doit se taire.
        self::assertStringContainsString('data-terminal-fige="1"', $html);

        $cookie = $this->client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($cookie, 'Le choix doit être mémorisé, sinon il ne survit pas au lien suivant.');
        self::assertSame(DetecteurDeTerminal::COOKIE, $cookie->getName());
        self::assertSame('ordinateur', $cookie->getValue());
        self::assertFalse($cookie->isHttpOnly(), 'La sonde écrit ce même cookie en JavaScript.');

        // Le cookie SEUL, sans paramètre, doit suffire à la page suivante.
        $this->client->getCookieJar()->set(new Cookie(DetecteurDeTerminal::COOKIE, 'ordinateur'));
        $html = $this->ouvrirEspace($data['owner'], $data['entreprise'], self::UA_IPHONE);
        self::assertStringContainsString(self::MARQUEUR_BUREAU, $html);
    }

    /**
     * LA BASCULE A UN RETOUR, ET IL N'EXISTE QUE LÀ OÙ IL SERT.
     *
     * Une tablette qui demande la version ordinateur mémorise ce choix pour un an.
     * Sans chemin de retour VISIBLE dans cette version-là, l'utilisateur serait
     * enfermé — un aller sans billet de retour. Le lien doit donc apparaître dans la
     * navigation du workspace de bureau lorsque l'appareil est tactile, et NULLE PART
     * ailleurs : sur un vrai ordinateur, il n'aurait aucun sens.
     */
    public function testLeRetourAuModeConversationExisteSurAppareilTactileSeulement(): void
    {
        $data = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Une tablette passée en version ordinateur : le retour doit être offert.
        $html = $this->ouvrirEspace(
            $data['owner'],
            $data['entreprise'],
            self::UA_IPAD,
            '?' . DetecteurDeTerminal::PARAM . '=ordinateur',
        );
        self::assertStringContainsString(self::MARQUEUR_BUREAU, $html);
        self::assertStringContainsString('Mode conversation', $html);
        // Le lien nomme le terminal RÉEL de l'appareil, pas « mobile » par défaut.
        self::assertStringContainsString(DetecteurDeTerminal::PARAM . '=tablette', $html);

        // Le voyage de retour aboutit bien à la conversation.
        $retour = $this->client->getCrawler()
            ->filter('a.nav-item[title="Revenir au mode conversation"]')
            ->attr('href');
        $this->client->request('GET', $retour, [], [], ['HTTP_USER_AGENT' => self::UA_IPAD]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            self::MARQUEUR_MOBILE,
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /**
     * UN VRAI ORDINATEUR NE VOIT PAS LA BASCULE.
     *
     * Ajouter une entrée de navigation permanente que personne n'utilise est un coût
     * pour tous les utilisateurs. Elle n'a de sens que comme chemin de retour.
     */
    public function testUnVraiOrdinateurNeVoitPasLaBascule(): void
    {
        $data = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $html = $this->ouvrirEspace($data['owner'], $data['entreprise'], self::UA_WINDOWS);

        self::assertStringContainsString(self::MARQUEUR_BUREAU, $html);
        self::assertStringNotContainsString('Mode conversation', $html);
    }

    /**
     * UN INVITÉ SANS LE MODULE N'EST PAS LAISSÉ DEVANT UN ÉCRAN VIDE.
     *
     * Et surtout : on ne lui propose pas d'acheter des tokens. Seul le propriétaire
     * peut le faire ; le lui proposer serait une impasse déguisée en solution.
     */
    public function testUnInviteSansLeModuleVoitUneExplicationSansCtaDAchat(): void
    {
        $data = $this->seed(comptePayant: true, withIaRole: false);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $html = $this->ouvrirEspace($data['guest'], $data['entreprise'], self::UA_IPHONE);

        self::assertStringNotContainsString(self::MARQUEUR_MOBILE, $html);
        self::assertStringNotContainsString(self::MARQUEUR_BUREAU, $html);
        self::assertStringContainsString('périmètre', $html);
        self::assertStringNotContainsString(
            '/admin/tokens/buy',
            $html,
            "Un invité ne peut pas acheter de tokens : le CTA d'achat ne doit pas lui être montré.",
        );
        // Jamais de cul-de-sac : les deux sorties restent offertes.
        self::assertStringContainsString('Afficher la version ordinateur', $html);
        self::assertStringContainsString('Revenir à ma page personnelle', $html);
    }

    /**
     * UN COLLABORATEUR AVEC LE DROIT TRAVAILLE DEPUIS SON TÉLÉPHONE.
     *
     * C'est le cas d'usage le plus courant du mode Ket — un gestionnaire en
     * déplacement — et il n'est PAS couvert par le test du propriétaire : la porte
     * de Ket teste le périmètre de l'INVITÉ pour le module, et le solde du CABINET
     * pour le premium. Les deux conditions ne portent pas sur la même personne, et
     * seul un invité non propriétaire le prouve.
     */
    public function testUnInviteAvecLeDroitRecoitLaCoquilleKet(): void
    {
        $data = $this->seed(comptePayant: true, withIaRole: true);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $html = $this->ouvrirEspace($data['guest'], $data['entreprise'], self::UA_IPHONE);

        self::assertStringContainsString(self::MARQUEUR_MOBILE, $html);
        self::assertStringNotContainsString(self::MARQUEUR_BUREAU, $html);
    }

    /**
     * UN COMPTE SANS SOLDE PAYANT VOIT L'OFFRE, ET LE PROPRIÉTAIRE VOIT L'ACTION.
     *
     * Le panneau servi est celui de la rubrique et du chat sur grand écran
     * (`_assistant_ia_premium.html.twig`) : une seule formulation de l'offre dans
     * toute l'application.
     */
    public function testUnCompteNonPayantVoitLOffrePremiumSurMobile(): void
    {
        $data = $this->seed(comptePayant: false);
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $html = $this->ouvrirEspace($data['owner'], $data['entreprise'], self::UA_IPHONE);

        self::assertStringNotContainsString(self::MARQUEUR_MOBILE, $html);
        self::assertStringContainsString('jsb-ai-premium', $html);
        self::assertStringContainsString('solde de tokens payant', $html);
        self::assertStringContainsString('/admin/tokens/buy', $html);
    }

    /**
     * LE REFUS N'EST PAS UNE PERTE DE DROITS.
     *
     * Un compte sans Ket doit pouvoir demander l'espace de travail complet depuis son
     * téléphone. La page de refus n'a pas le droit de retirer ce choix.
     */
    public function testDepuisLeRefusOnPeutEncoreDemanderLaVersionOrdinateur(): void
    {
        $data = $this->seed(comptePayant: false);
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->ouvrirEspace($data['owner'], $data['entreprise'], self::UA_IPHONE);
        $lien = $this->client->getCrawler()->filter('a.ki-sortie')->first()->attr('href');

        $this->client->request('GET', $lien, [], [], ['HTTP_USER_AGENT' => self::UA_IPHONE]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            self::MARQUEUR_BUREAU,
            (string) $this->client->getResponse()->getContent(),
        );
    }
}
