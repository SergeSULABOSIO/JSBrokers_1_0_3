<?php

namespace App\Tests\Onboarding;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * LES SURFACES DE RAPPEL DE LA DETTE DE CONFIGURATION.
 *
 * Le voyant de la colonne 1 et le bandeau du tableau de bord disent la même chose au
 * même moment : ils s'allument et s'éteignent ensemble. Ce test le verrouille, parce que
 * deux rappels qui divergent valent moins qu'un seul — l'utilisateur ne saurait plus
 * lequel croire.
 */
class OnboardingRappelTest extends WebTestCase
{
    private const ENT = 'PHPUnit-Onboarding-Rappel';
    private const OWNER = 'phpunit-onboarding-rappel-owner@test.local';
    private const GUEST = 'phpunit-onboarding-rappel-guest@test.local';
    private const PASSWORD = 'Test1234!';

    /** La classe du bouton de la colonne 1 — le marqueur du voyant. */
    private const VOYANT = 'ws-onboarding-jauge';
    private const BANDEAU = 'jsb-onboarding-bandeau';

    private $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    /**
     * @return array{owner: Invite, guest: Invite, entreprise: Entreprise}
     */
    private function seed(): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER)->setNom('Propriétaire');
        $ownerUser->setPassword($hasher->hashPassword($ownerUser, self::PASSWORD));
        $ownerUser->setVerified(true);
        $this->em->persist($ownerUser);

        $entreprise = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($ownerUser);
        $this->em->persist($entreprise);

        $owner = (new Invite())->setNom('Administrateur')->setUtilisateur($ownerUser)
            ->setEntreprise($entreprise)->setProprietaire(true);
        $this->em->persist($owner);

        $guestUser = new Utilisateur();
        $guestUser->setEmail(self::GUEST)->setNom('Collaborateur');
        $guestUser->setPassword($hasher->hashPassword($guestUser, self::PASSWORD));
        $guestUser->setVerified(true);
        $this->em->persist($guestUser);

        $guest = (new Invite())->setNom('Collaborateur')->setUtilisateur($guestUser)
            ->setEntreprise($entreprise)->setProprietaire(false);
        $this->em->persist($guest);

        // Le workspace synchronise son périmètre sur `connectedTo` : sans cela, les
        // services de scoping travailleraient sur une entreprise obsolète.
        $ownerUser->setConnectedTo($entreprise);
        $guestUser->setConnectedTo($entreprise);

        $this->em->flush();

        return ['owner' => $owner, 'guest' => $guest, 'entreprise' => $entreprise];
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $emails = [self::OWNER, self::GUEST];

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:e)',
            ['e' => $emails],
            ['e' => ArrayParameterType::STRING],
        );
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => $emails],
            ['e' => ArrayParameterType::STRING],
        );
        $this->em->clear();
    }

    private function user(string $email): Utilisateur
    {
        return $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function ouvrirWorkspace(Invite $invite, Entreprise $entreprise, string $email, string $query = ''): string
    {
        $this->client->loginUser($this->user($email));
        $this->client->request('GET', sprintf('/espacedetravail/%d/%d%s', $invite->getId(), $entreprise->getId(), $query));
        $this->assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    public function testLeProprietaireVoitLeVoyantEtSonPourcentage(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $html = $this->ouvrirWorkspace($owner, $e, self::OWNER);

        $this->assertStringContainsString(self::VOYANT, $html);
        // Le pourcentage est écrit en clair : l'anneau seul ne porterait l'information
        // que par la couleur et la forme (WCAG 1.4.1).
        $this->assertStringContainsString('ws-jauge-pct', $html);
        // Et le titre de l'onglet se lit dans `.nav-text` : y mettre le pourcentage
        // aurait intitulé l'onglet « 0 % ».
        $this->assertStringContainsString('<span class="nav-text">Démarrage</span>', $html);
    }

    public function testLInviteNeVoitAucunRappel(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();

        $html = $this->ouvrirWorkspace($guest, $e, self::GUEST);

        // Configurer le cabinet n'est pas son affaire : le lui rappeler serait lui
        // demander ce qu'il ne peut pas faire.
        $this->assertStringNotContainsString(self::VOYANT, $html);
        $this->assertStringNotContainsString(self::BANDEAU, $html);
    }

    public function testLInfobulleEstRendueParLeServeurAvecSonBouton(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $html = $this->ouvrirWorkspace($owner, $e, self::OWNER);

        // Rendue côté serveur dans un <template> : aucune requête au survol, et aucun
        // risque de voir l'infobulle annoncer un chiffre différent du voyant.
        $this->assertStringContainsString('data-onboarding-jauge-target="modele"', $html);
        $this->assertStringContainsString('ws-onboarding-detail', $html);
        $this->assertStringContainsString('Reprendre la configuration', $html);
        // Le panneau vit sous <body>, hors de toute portée Stimulus : son bouton ne
        // porte donc AUCUNE action, il en serait inerte.
        $this->assertStringContainsString('data-onboarding-cta', $html);
    }

    public function testLeBandeauDuTableauDeBordNommeCeQuiManque(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $this->client->loginUser($this->user(self::OWNER));
        $this->client->request('GET', sprintf('/admin/entreprise_dashbord/workspace/%d', $e->getId()));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString(self::BANDEAU, $html);
        $this->assertStringContainsString('Terminer la configuration', $html);
        // Un pourcentage ne dit pas quoi faire : le bandeau cite les manques.
        $this->assertStringContainsString('Il vous manque encore', $html);
        $this->assertStringContainsString('Assureurs', $html);
    }

    public function testLeBandeauRemplaceLesEtapesEcritesEnDurDuPanneauDAccueil(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $html = $this->ouvrirWorkspace($owner, $e, self::OWNER, '?welcome=1');

        $this->assertStringContainsString(self::BANDEAU, $html);
        // Les trois cartes suggérées citaient « Taxes », que le semis pose d'office :
        // on invitait le courtier à faire une chose déjà faite.
        //
        // MARQUEUR DE MARKUP, et non le simple nom de classe : `.ws-step-card` apparaît
        // aussi dans la feuille de style inline du gabarit, où il ne prouve rien.
        $this->assertStringNotContainsString('class="ws-step-card"', $html);
    }

    public function testLeMarqueurDOuvertureAutomatiqueSuitLaQueryDuCourriel(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $sans = $this->ouvrirWorkspace($owner, $e, self::OWNER);
        $this->assertStringContainsString('data-onboarding-jauge-auto-ouvrir-value="false"', $sans);

        // C'est ce marqueur qui fait ouvrir le guide de lui-même quand on arrive par le
        // bouton du courriel de synthèse.
        $avec = $this->ouvrirWorkspace($owner, $e, self::OWNER, '?onboarding=1');
        $this->assertStringContainsString('data-onboarding-jauge-auto-ouvrir-value="true"', $avec);
    }
}
