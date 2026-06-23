<?php

namespace App\Tests\Frontend;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Bascule de langue (FR/EN) + pied de page officiel sur l'espace « admin/entreprise ».
 *
 * Vérifie, sur les 3 pages (liste / création / édition), que :
 *  - le sélecteur de langue de la barre de titre (composant unifié .cs-lang) est
 *    présent, avec ses deux options câblées sur la barre de progression (top-progress#show) ;
 *  - le pied de page public officiel (.public-footer) est rendu, avec sa propre
 *    bascule de langue (.cs-lang) ;
 *  - la bascule est RÉELLEMENT fonctionnelle : ?lang=en bascule le rendu en anglais
 *    ET persiste la préférence de l'utilisateur en base (cf. applyLangPreference).
 */
class EntrepriseLanguageSwitchTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-lang-owner@test.local';
    private const PASSWORD     = 'Test1234!';

    private KernelBrowser $client;
    private int $entrepriseId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em     = $this->em();

        $user = new Utilisateur();
        $user->setEmail(self::OWNER_EMAIL);
        $user->setNom('PHPUnit Lang');
        $user->setVerified(true);
        $user->setLocale('fr');
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $em->persist($user);

        $entreprise = new Entreprise();
        $entreprise->setNom('PHPUnit Lang SARL');
        $entreprise->setLicence('LIC-LANG');
        $entreprise->setAdresse('1 rue du Test');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-LANG');
        $entreprise->setIdnat('IDNAT-LANG');
        $entreprise->setNumimpot('IMP-LANG');
        $entreprise->setUtilisateur($user);
        $em->persist($entreprise);

        // Invité « propriétaire » : la liste et l'édition s'appuient dessus pour
        // construire les liens vers l'espace de travail.
        $invite = new Invite();
        $invite->setNom('Administrateur');
        $invite->setUtilisateur($user);
        $invite->setEntreprise($entreprise);
        $invite->setProprietaire(true);
        $em->persist($invite);

        $em->flush();
        $this->entrepriseId = $entreprise->getId();
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

        $conn->executeStatement(
            "DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email = :email OR e.nom = :nom",
            ['email' => self::OWNER_EMAIL, 'nom' => 'PHPUnit Lang SARL']
        );
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => 'PHPUnit Lang SARL']);
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :email", ['email' => self::OWNER_EMAIL]);
    }

    private function login(): void
    {
        $this->client->loginUser(
            $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL])
        );
    }

    /** Câblage commun attendu sur chacune des pages entreprise. */
    private function assertPageWiring(Crawler $crawler, string $context): void
    {
        $this->assertResponseIsSuccessful();

        $this->assertGreaterThan(0, $crawler->filter('[data-controller~="top-progress"]')->count(),
            "Le contrôleur top-progress doit envelopper $context.");
        $this->assertGreaterThan(0, $crawler->filter('[data-top-progress-target="bar"]')->count(),
            "La barre de progression (cible) doit être présente sur $context.");

        // Sélecteur de langue de la barre de titre : 2 options, chacune déclenchant la barre.
        $topbarLinks = $crawler->filter('.ent-topbar .cs-lang a');
        $this->assertSame(2, $topbarLinks->count(), "Deux options de langue attendues dans la barre de titre de $context.");
        foreach ($topbarLinks->each(fn ($a) => $a->attr('data-action')) as $action) {
            $this->assertNotNull($action, "Le lien de langue de $context doit porter un data-action.");
            $this->assertStringContainsString('top-progress#show', $action,
                "Le clic sur la langue dans $context doit déclencher la barre de progression.");
        }

        // Pied de page officiel rendu, avec sa bascule de langue.
        $this->assertGreaterThan(0, $crawler->filter('.public-footer')->count(),
            "Le pied de page officiel doit être rendu sur $context.");
        $this->assertSame(2, $crawler->filter('.public-footer .cs-lang a')->count(),
            "Le pied de page de $context doit porter la bascule de langue (2 options).");
    }

    public function testListPageHasLanguageSwitcherAndFooter(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/admin/entreprise?lang=fr');
        $this->assertPageWiring($crawler, "la liste des entreprises");
    }

    public function testCreatePageHasLanguageSwitcherAndFooter(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/admin/entreprise/create?lang=fr');
        $this->assertPageWiring($crawler, "la création d'entreprise");
    }

    public function testEditPageHasLanguageSwitcherAndFooter(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/admin/entreprise/' . $this->entrepriseId . '?lang=fr');
        $this->assertPageWiring($crawler, "l'édition d'entreprise");
    }

    /**
     * Bascule effective : ?lang=en rend la liste en anglais ET persiste la
     * préférence de l'utilisateur en base (le UserLocaleListener s'en servira ensuite).
     */
    public function testSwitchingToEnglishRendersEnglishAndPersistsPreference(): void
    {
        $this->login();

        $this->client->request('GET', '/admin/entreprise?lang=en');
        $this->assertResponseIsSuccessful();
        // Texte connu de la liste, traduit en anglais (entreprise_description).
        $this->assertStringContainsString('Company list', $this->client->getResponse()->getContent());

        // Préférence persistée (lecture SQL directe, insensible à l'identity-map).
        $locale = $this->em()->getConnection()->fetchOne(
            'SELECT locale FROM utilisateur WHERE email = :email',
            ['email' => self::OWNER_EMAIL]
        );
        $this->assertSame('en', $locale, "La préférence de langue aurait dû être persistée à 'en'.");
    }

    /**
     * Le menu contextuel des cartes est 100% bilingue : les libellés « Éditer/Supprimer {nom} »
     * ne sont plus codés en dur en français dans le JS — ils proviennent de gabarits traduits
     * exposés en data-values sur le contrôleur Stimulus (placeholder %name%).
     */
    public function testContextMenuLabelTemplatesAreLocalised(): void
    {
        $this->login();

        // Version anglaise : les gabarits doivent être en anglais.
        $crawler = $this->client->request('GET', '/admin/entreprise?lang=en');
        $this->assertResponseIsSuccessful();
        $main = $crawler->filter('main[data-controller~="entreprise-context-menu"]');
        $this->assertSame(1, $main->count(), "Le contrôleur du menu contextuel doit envelopper la liste.");
        $this->assertSame('Edit %name%', $main->attr('data-entreprise-context-menu-edit-template-value'),
            "Le gabarit « Éditer » doit être traduit en anglais.");
        $this->assertSame('Delete %name%', $main->attr('data-entreprise-context-menu-delete-template-value'),
            "Le gabarit « Supprimer » doit être traduit en anglais.");

        // Version française : mêmes gabarits, en français.
        $crawler = $this->client->request('GET', '/admin/entreprise?lang=fr');
        $main = $crawler->filter('main[data-controller~="entreprise-context-menu"]');
        $this->assertSame('Éditer %name%', $main->attr('data-entreprise-context-menu-edit-template-value'),
            "Le gabarit « Éditer » doit être en français.");
        $this->assertSame('Supprimer %name%', $main->attr('data-entreprise-context-menu-delete-template-value'),
            "Le gabarit « Supprimer » doit être en français.");
    }

    /**
     * Dans la barre de titre de la liste, la bascule de langue est ancrée à l'extrême droite :
     * c'est le DERNIER élément du groupe d'actions.
     */
    public function testLanguageSwitcherIsLastInTopbarActions(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/admin/entreprise?lang=fr');
        $this->assertResponseIsSuccessful();

        $lastChild = $crawler->filter('.ent-topbar .ent-topbar-actions > *:last-child');
        $this->assertSame(1, $lastChild->count(), "Le groupe d'actions de la barre de titre doit exister.");
        $this->assertStringContainsString('cs-lang', (string) $lastChild->attr('class'),
            "La bascule de langue doit être le dernier élément (extrême droite) du groupe d'actions.");
    }

    /**
     * Sur les pages de création et d'édition, le pied de page officiel occupe 100% de la largeur :
     * il est rendu HORS de la colonne étroite .ent-content (même pattern que la liste).
     */
    public function testFooterIsFullWidthOnFormPages(): void
    {
        $this->login();

        foreach ([
            'la création' => '/admin/entreprise/create?lang=fr',
            "l'édition"   => '/admin/entreprise/' . $this->entrepriseId . '?lang=fr',
        ] as $context => $url) {
            $crawler = $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful();

            $this->assertGreaterThan(0, $crawler->filter('.public-footer')->count(),
                "Le pied de page doit être rendu sur $context.");
            $this->assertSame(0, $crawler->filter('.ent-content .public-footer')->count(),
                "Le pied de page de $context ne doit PAS être enfermé dans la colonne étroite .ent-content.");
        }
    }
}
