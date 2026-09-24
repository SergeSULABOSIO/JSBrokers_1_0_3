<?php

namespace App\Tests\Console;

use App\Entity\Utilisateur;
use App\Enum\Departement;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * UN AGENT NE DOIT PAS POUVOIR DEVENIR SUPER-ADMINISTRATEUR.
 *
 * ── LES DEUX MARCHES QUI EXISTAIENT ─────────────────────────────────────────
 * `#[IsGranted('ROLE_ADMIN')]` dit qui ENTRE dans un écran, jamais sur QUI il
 * agit. Les deux écrans de comptes résolvaient leur cible par `{id}` :
 *
 *  1. `console.collaborateur.edit` porte un champ de mot de passe. Tout agent
 *     pouvait donc en poser un sur le compte d'un super-administrateur, puis se
 *     connecter avec. Le garde `$canGrantSuper` du contrôleur ne protégeait que
 *     le champ de RÔLE — le mot de passe passait à côté.
 *  2. `console.utilisateur.edit` et `.delete` — l'écran des comptes CLIENTS —
 *     acceptaient l'identifiant de n'importe quel utilisateur. Sa LISTE est
 *     filtrée (`paginateRegularUsers`), sa ROUTE ne l'était pas : on y réécrivait
 *     le mot de passe d'un agent, et l'on y supprimait le dernier
 *     super-administrateur — en contournant le garde-fou qui n'existe que dans
 *     l'écran des collaborateurs.
 *
 * ── CE QUI RESTE PERMIS, ET POURQUOI ────────────────────────────────────────
 * Un agent non super-administrateur garde le droit d'éditer un agent ORDINAIRE :
 * c'est le métier du département RH, seul lui et la Direction atteignant
 * `console.collaborateur.*`. La marche qu'on ferme est celle qui mène à la
 * RACINE. L'avant-dernier test de ce fichier le vérifie explicitement, pour
 * qu'un resserrement futur ne vide pas la rubrique RH sans que personne s'en
 * aperçoive.
 */
final class EscaladeDeComptesTest extends WebTestCase
{
    private const RH      = 'phpunit-escalade-rh@test.local';
    private const AGENT   = 'phpunit-escalade-agent@test.local';
    private const SUPER   = 'phpunit-escalade-super@test.local';
    private const SUPER_2 = 'phpunit-escalade-super2@test.local';
    private const CLIENT  = 'phpunit-escalade-client@test.local';
    private const PASSWORD = 'Test1234!';
    private const NOUVEAU_MOT_DE_PASSE = 'Pirate1234!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        $comptes = [
            // Le RH est l'agent de référence : c'est le seul département, avec la
            // Direction, dont le périmètre couvre `console.collaborateur.*`.
            self::RH      => [['ROLE_ADMIN'], Departement::RH],
            // ⚠ DIRECTION, ET C'EST OBLIGATOIRE. `console.utilisateur.*` n'est dans
            // AUCUN `routePrefixes()` de département : seule la Direction (préfixe
            // « console. ») l'atteint. Avec un autre département, les tests de
            // l'écran des clients renverraient 403 à cause du FILTRE DÉPARTEMENTAL,
            // et passeraient donc pour une bonne raison qui n'est pas la nôtre.
            self::AGENT   => [['ROLE_ADMIN'], Departement::DIRECTION],
            self::SUPER   => [['ROLE_SUPER_ADMIN'], Departement::DIRECTION],
            self::SUPER_2 => [['ROLE_SUPER_ADMIN'], Departement::DIRECTION],
            self::CLIENT  => [[], null],
        ];

        foreach ($comptes as $email => [$roles, $departement]) {
            $u = (new Utilisateur())
                ->setEmail($email)
                ->setNom('PHPUnit ' . $email)
                ->setVerified(true);
            $u->setRoles($roles);
            $u->setDepartement($departement);
            $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
            $em->persist($u);
        }
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
        $this->em()->getConnection()->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => [self::RH, self::AGENT, self::SUPER, self::SUPER_2, self::CLIENT]],
            ['e' => ArrayParameterType::STRING],
        );
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    // ── 1. L'écran des COLLABORATEURS ───────────────────────────────────────

    public function testUnAgentNAtteintPasLeFormulaireDUnSuperAdministrateur(): void
    {
        $this->client->loginUser($this->user(self::RH));
        $this->client->request('GET', '/console/collaborateurs/' . $this->user(self::SUPER)->getId() . '/edit');

        $this->assertResponseStatusCodeSame(
            403,
            "Un agent ne doit pas ouvrir le formulaire d'un super-administrateur : il y trouverait son champ de mot de passe."
        );
    }

    public function testUnAgentNePeutPasReecrireLeMotDePasseDUnSuperAdministrateur(): void
    {
        $cible = $this->user(self::SUPER);
        $empreinteAvant = $cible->getPassword();

        $this->client->loginUser($this->user(self::RH));
        // Appel DIRECT, sans passer par le formulaire : c'est le chemin qu'emprunterait
        // quelqu'un qui connaît la route. Un 403 ici vaut mieux qu'un formulaire caché.
        $this->client->request('POST', '/console/collaborateurs/' . $cible->getId() . '/edit', [
            'collaborateur' => [
                'nom'           => 'Pirate',
                'email'         => (string) $cible->getEmail(),
                'plainPassword' => self::NOUVEAU_MOT_DE_PASSE,
            ],
        ]);

        $this->assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertSame(
            $empreinteAvant,
            $this->user(self::SUPER)->getPassword(),
            "Le mot de passe du super-administrateur a changé : l'escalade agent vers super-admin est ouverte."
        );
    }

    public function testUnSuperAdministrateurEditeUnAutreSuperAdministrateur(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->client->request('GET', '/console/collaborateurs/' . $this->user(self::SUPER_2)->getId() . '/edit');

        $this->assertResponseIsSuccessful(
            'Entre super-administrateurs, rien ne change : la garde ne vise que la marche vers la racine.'
        );
    }

    /**
     * LE MÉTIER DU RH RESTE INTACT. Si ce test tombe, c'est qu'on a resserré la
     * garde au point de vider la rubrique « Collaborateurs » de son usage normal.
     */
    public function testUnAgentRhEditeToujoursUnAgentOrdinaire(): void
    {
        $this->client->loginUser($this->user(self::RH));
        $this->client->request('GET', '/console/collaborateurs/' . $this->user(self::AGENT)->getId() . '/edit');

        $this->assertResponseIsSuccessful(
            "Un agent RH doit pouvoir éditer un agent ordinaire : c'est le périmètre de son département."
        );
    }

    // ── 2. L'écran des UTILISATEURS (comptes clients) ───────────────────────

    public function testLEcranDesClientsRefuseUneCibleAgent(): void
    {
        $this->client->loginUser($this->user(self::AGENT));
        $this->client->request('GET', '/console/utilisateurs/' . $this->user(self::SUPER)->getId() . '/edit');

        $this->assertResponseStatusCodeSame(
            403,
            "L'écran des comptes clients ne doit pas servir de porte dérobée vers un compte d'agent."
        );
    }

    public function testLEcranDesClientsNeSupprimePasUnAgent(): void
    {
        $id = $this->user(self::SUPER)->getId();

        $this->client->loginUser($this->user(self::AGENT));

        // ON LIT LE MOTIF, PAS SEULEMENT LE CODE. Sans jeton CSRF, cette route répond
        // DÉJÀ 403 — le même code que notre garde —, si bien qu'un test qui se
        // contenterait du statut passerait même après suppression de la garde. On
        // laisse donc remonter l'exception et l'on vérifie QUI a refusé : la garde de
        // cible s'exécute avant la vérification du jeton.
        $this->client->catchExceptions(false);

        try {
            $this->client->request('POST', '/console/utilisateurs/' . $id);
            self::fail('La suppression d’un compte d’agent depuis l’écran des clients aurait dû être refusée.');
        } catch (AccessDeniedException $e) {
            self::assertStringContainsString(
                'comptes clients',
                $e->getMessage(),
                'Le refus vient du jeton CSRF, pas de la garde de cible : la garde a disparu.'
            );
        }

        $this->em()->clear();
        self::assertNotNull(
            $this->em()->getRepository(Utilisateur::class)->find($id),
            "Un super-administrateur a été supprimé depuis l'écran des comptes clients."
        );
    }

    public function testLEcranDesClientsEditeBienUnClient(): void
    {
        $this->client->loginUser($this->user(self::AGENT));
        $this->client->request('GET', '/console/utilisateurs/' . $this->user(self::CLIENT)->getId() . '/edit');

        $this->assertResponseIsSuccessful(
            "L'écran des comptes clients doit continuer de servir à ce pour quoi il existe."
        );
    }
}
