<?php

namespace App\Tests\Workspace;

use App\Entity\ChargeCourtier;
use App\Entity\DepenseCourtier;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\Invite;
use App\Entity\RolesEnFinance;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels du référentiel Fournisseurs du courtier (module Finances) :
 *  - gating par rôle (RolesEnFinance::accessFournisseur, fail-closed) + menu filtré ;
 *  - flux propriétaire : création d'un fournisseur (scopé entreprise, métré en
 *    tokens), formulaire avec dossier documentaire (contrats, agréments…) ;
 *  - rattachement d'une dépense à un fournisseur enregistré (le tiers affiché /
 *    comptabilisé devient le fournisseur) ;
 *  - bloc « Comptabilité » du tableau de bord (gated Dépenses, contenu rendu).
 * Chaque test crée ses données et les nettoie ensuite.
 */
class WorkspaceFournisseurTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-fourn-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-fourn-guest@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit Fournisseurs SARL';
    private const DENIED_MARKER = 'jsb-access-denied';

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

    private function makeUser(string $email): Utilisateur
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new Utilisateur();
        $user->setEmail($email);
        $user->setNom('PHPUnit');
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);

        return $user;
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL];

        $conn->executeStatement(
            "UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        foreach ([
            'depense_courtier', 'charge_courtier', 'fournisseur',
            'roles_en_finance', 'roles_en_marketing', 'roles_en_production',
            'roles_en_sinistre', 'roles_en_administration',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN entreprise e ON t.entreprise_id = e.id
                 WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM]
            );
        }
        $conn->executeStatement(
            "DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email IN (:emails) OR e.nom = :nom",
            ['emails' => $emails, 'nom' => self::ENTREPRISE_NOM],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement(
            "DELETE FROM utilisateur WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    /**
     * @return array{owner: Invite, guest: Invite, entreprise: Entreprise}
     */
    private function seed(array $accessFournisseur = [], array $accessDepense = []): array
    {
        $em = $this->em();

        $ownerUser = $this->makeUser(self::OWNER_EMAIL);
        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-TEST');
        $entreprise->setAdresse('1 rue du Test');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-TEST');
        $entreprise->setIdnat('IDNAT-TEST');
        $entreprise->setNumimpot('IMP-TEST');
        $entreprise->setUtilisateur($ownerUser);
        $ownerUser->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $ownerInvite = new Invite();
        $ownerInvite->setNom('Administrateur');
        $ownerInvite->setUtilisateur($ownerUser);
        $ownerInvite->setEntreprise($entreprise);
        $ownerInvite->setProprietaire(true);
        $em->persist($ownerInvite);

        $guestUser = $this->makeUser(self::GUEST_EMAIL);
        $guestUser->setConnectedTo($entreprise);
        $guestInvite = new Invite();
        $guestInvite->setNom('Collaborateur restreint');
        $guestInvite->setUtilisateur($guestUser);
        $guestInvite->setEntreprise($entreprise);
        $guestInvite->setProprietaire(false);
        $em->persist($guestInvite);

        if ($accessFournisseur !== [] || $accessDepense !== []) {
            $role = new RolesEnFinance();
            $role->setNom('Rôle fournisseurs');
            $role->setAccessFournisseur($accessFournisseur);
            $role->setAccessDepense($accessDepense);
            $role->setEntreprise($entreprise);
            $guestInvite->addRolesEnFinance($role);
            $em->persist($role);
        }

        $em->flush();

        return ['owner' => $ownerInvite, 'guest' => $guestInvite, 'entreprise' => $entreprise];
    }

    public function testGatingFournisseurFailClosedEtMenu(): void
    {
        // Invité avec Lecture Dépenses mais AUCUN droit Fournisseurs.
        ['guest' => $guest, 'entreprise' => $e] = $this->seed([], [Invite::ACCESS_LECTURE]);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->client->request('GET', sprintf('/admin/fournisseur/index/%d/%d', $guest->getId(), $e->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(self::DENIED_MARKER, (string) $this->client->getResponse()->getContent(), 'Sans droit Fournisseurs, la rubrique doit être refusée (fail-closed).');

        // Menu : la rubrique Fournisseurs est filtrée, les Dépenses restent visibles.
        $this->client->request('GET', sprintf('/espacedetravail/%d/%d', $guest->getId(), $e->getId()));
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('entity-name-param="Fournisseur"', $html, 'La rubrique Fournisseurs (hors périmètre) doit disparaître du menu.');
        $this->assertStringContainsString('entity-name-param="DepenseCourtier"', $html);
    }

    public function testInviteAvecLectureAccedeALaRubrique(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed([Invite::ACCESS_LECTURE]);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->client->request('GET', sprintf('/admin/fournisseur/index/%d/%d', $guest->getId(), $e->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString(self::DENIED_MARKER, (string) $this->client->getResponse()->getContent());
    }

    public function testProprietaireCreeFournisseurAvecDossierEtTokens(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Le formulaire respecte le pattern des dialogues : entête contextuel +
        // dossier documentaire (collection de documents).
        $this->client->request('GET', sprintf('/admin/fournisseur/api/get-form?idEntreprise=%d&idInvite=%d', $e->getId(), $owner->getId()));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('form-intro', $html, "L'entête contextuel du pattern des dialogues doit être rendu.");
        $this->assertStringContainsString('Fournisseur professionnel', $html);
        $this->assertStringContainsString('Document', $html, 'Le widget de collection du dossier documentaire doit être proposé.');

        // Création : persistée, scopée entreprise, métrée en tokens (écriture).
        $this->client->request('POST', '/admin/fournisseur/api/submit', [
            'idEntreprise'    => $e->getId(),
            'idInvite'        => $owner->getId(),
            'nom'             => 'Global Broadband Services',
            'personneContact' => 'Jean Kabila',
            'telephone'       => '+243999000000',
            'email'           => 'contact@gbs.cd',
        ]);
        $this->assertResponseIsSuccessful('La création du fournisseur ne doit pas échouer.');
        $this->assertStringContainsString('Enregistr', (string) $this->client->getResponse()->getContent());

        $fournisseur = $this->em()->getRepository(Fournisseur::class)->findOneBy(['nom' => 'Global Broadband Services']);
        $this->assertNotNull($fournisseur, 'Le fournisseur doit être persisté.');
        $this->assertSame($e->getId(), $fournisseur->getEntreprise()->getId(), 'Le fournisseur doit être scopé à l\'entreprise du workspace.');
        $this->assertTrue($fournisseur->isActif());

        // Facturation : la création est journalisée dans les consommations de tokens.
        $consommations = $this->em()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM token_consumption tc
             JOIN entreprise e ON tc.entreprise_id = e.id
             WHERE e.nom = :nom AND tc.entite_nom = 'Fournisseur' AND tc.sens = 'entree'",
            ['nom' => self::ENTREPRISE_NOM]
        );
        $this->assertGreaterThanOrEqual(1, (int) $consommations, 'La création du fournisseur doit consommer des tokens (écriture).');

        // La liste rechargée AVEC données rend le fournisseur.
        $this->client->request('GET', sprintf('/admin/fournisseur/index/%d/%d', $owner->getId(), $e->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Global Broadband Services', (string) $this->client->getResponse()->getContent());
    }

    public function testDepenseRattacheeAUnFournisseurEnregistre(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $em = $this->em();

        $fournisseur = new Fournisseur();
        $fournisseur->setNom('GBS Internet');
        $fournisseur->setEntreprise($e);
        $em->persist($fournisseur);

        $charge = new ChargeCourtier();
        $charge->setCode('NET')->setLibelle('Frais de fourniture d\'Internet')->setCompteOhada('60');
        $charge->setEntreprise($e);
        $em->persist($charge);
        $em->flush();

        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Le formulaire de dépense propose le choix d'un fournisseur enregistré.
        $this->client->request('GET', sprintf('/admin/depensecourtier/api/get-form?idEntreprise=%d&idInvite=%d', $e->getId(), $owner->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Fournisseur enregistré', (string) $this->client->getResponse()->getContent());

        // Dépense rattachée au fournisseur (sans bénéficiaire libre).
        $this->client->request('POST', '/admin/depensecourtier/api/submit', [
            'idEntreprise'  => $e->getId(),
            'idInvite'      => $owner->getId(),
            'charge'        => $charge->getId(),
            'fournisseur'   => $fournisseur->getId(),
            'dateDepense'   => '2026-07-03',
            'montant'       => '600.00',
            'tauxTva'       => '16.00',
            'moyenPaiement' => 'banque',
            'statut'        => 'payee',
        ]);
        $this->assertResponseIsSuccessful('La création de la dépense liée au fournisseur ne doit pas échouer.');
        $this->assertStringContainsString('Enregistr', (string) $this->client->getResponse()->getContent());

        $depense = $this->em()->getRepository(DepenseCourtier::class)->findOneBy(['charge' => $charge]);
        $this->assertNotNull($depense);
        $this->assertSame($fournisseur->getId(), $depense->getFournisseur()?->getId(), 'La dépense doit être rattachée au fournisseur enregistré.');
        $this->assertSame('GBS Internet', $depense->getTiersLibelle(), 'Le tiers affiché / comptabilisé doit être le fournisseur.');

        // La liste des dépenses affiche le fournisseur comme tiers.
        $this->client->request('GET', sprintf('/admin/depensecourtier/index/%d/%d', $owner->getId(), $e->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('GBS Internet', (string) $this->client->getResponse()->getContent());
    }

    public function testBlocComptaDuTableauDeBord(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed([], []);
        $url = sprintf('/admin/entreprise_dashbord/block/compta/%d', $e->getId());

        // Invité sans droit Dépenses : réponse vide (défense en profondeur).
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();
        $this->assertSame('', trim((string) $this->client->getResponse()->getContent()), 'Le bloc Comptabilité doit être vide pour un invité hors périmètre.');

        // Propriétaire : KPIs comptables rendus, avec infobulle qui suit le curseur
        // (pattern data-compta-tip, calqué sur data-renew-tip) et ses deux paragraphes.
        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Commissions encaissées', $html);
        $this->assertStringContainsString('Résultat net', $html);
        $this->assertStringContainsString('fournisseur', $html, 'Le pouls des fournisseurs actifs doit être affiché.');
        $this->assertStringContainsString('data-compta-tip', $html, "Les KPIs doivent porter l'infobulle qui suit le curseur.");
        $this->assertStringContainsString('data-tip-intro', $html, "L'infobulle doit contenir le paragraphe de représentation.");
        $this->assertStringContainsString('data-tip-calcul', $html, "L'infobulle doit contenir le paragraphe de mode de calcul.");

        // Le tableau de bord du propriétaire embarque le bloc (gate Twig).
        $this->client->request('GET', sprintf('/admin/entreprise_dashbord/workspace/%d', $e->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('block/compta', (string) $this->client->getResponse()->getContent(), 'Le bloc Comptabilité doit être déclaré dans le tableau de bord.');
    }

    public function testBlocDepensesDuTableauDeBord(): void
    {
        ['owner' => $owner, 'guest' => $guest, 'entreprise' => $e] = $this->seed([], []);
        $em = $this->em();

        // Une dépense récente (fournisseur enregistré) à faire remonter dans le bloc.
        $fournisseur = new Fournisseur();
        $fournisseur->setNom('GBS Internet');
        $fournisseur->setEntreprise($e);
        $em->persist($fournisseur);

        $charge = new ChargeCourtier();
        $charge->setCode('NET')->setLibelle('Frais de fourniture d\'Internet')->setCompteOhada('60');
        $charge->setEntreprise($e);
        $em->persist($charge);

        $depense = new DepenseCourtier();
        $depense->setCharge($charge)->setFournisseur($fournisseur)
            ->setDateDepense(new \DateTimeImmutable('now'))
            ->setMontant('600.00')->setTauxTva('16.00')
            ->setMoyenPaiement('banque')->setStatut('payee');
        $depense->setEntreprise($e);
        $em->persist($depense);
        $em->flush();

        $url = sprintf('/admin/entreprise_dashbord/block/depenses/%d', $e->getId());

        // Invité sans droit Dépenses : réponse vide (défense en profondeur).
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();
        $this->assertSame('', trim((string) $this->client->getResponse()->getContent()), 'Le bloc Dépenses doit être vide pour un invité hors périmètre.');

        // Propriétaire : la dépense remonte, avec le tiers et le montant en sortie,
        // l'infobulle qui suit le curseur (data-dep-tip) et le menu contextuel
        // (clic droit → ajouter / modifier / supprimer).
        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('GBS Internet', $html, 'La dépense doit afficher son fournisseur.');
        $this->assertStringContainsString('db-dep-list', $html, 'Le conteneur de liste (auto-refresh) doit être présent.');
        $this->assertStringContainsString('depenses-fragment', $html, "L'URL de rafraîchissement doit être déclarée.");
        $this->assertStringContainsString('data-dep-tip', $html, "L'infobulle qui suit le curseur doit être posée sur chaque dépense.");
        $this->assertStringContainsString('dbDepCtxOpen(event,this)', $html, 'Le clic droit doit ouvrir le menu contextuel de la dépense.');
        $this->assertStringContainsString('id="dbDepCtxMenu"', $html, 'Le menu contextuel dépense doit être présent.');
        $this->assertStringContainsString('Ajouter une dépense', $html);
        $this->assertStringContainsString('Modifier la dépense', $html);
        $this->assertStringContainsString('db-dep-csrf', $html, 'Le jeton CSRF de suppression doit être présent.');

        // Fragment de rafraîchissement (miroir encaissements-fragment) : rend les lignes
        // avec leurs data-attributs de menu contextuel.
        $this->client->request('GET', sprintf('/admin/entreprise_dashbord/depenses-fragment/%d', $e->getId()));
        $this->assertResponseIsSuccessful();
        $fragment = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('GBS Internet', $fragment);
        $this->assertStringContainsString('data-dep-id', $fragment, 'Chaque ligne doit porter son identifiant pour le menu contextuel.');
        $this->assertStringContainsString('oncontextmenu', $fragment);

        // Le tableau de bord du propriétaire embarque le bloc (gate Twig).
        $this->client->request('GET', sprintf('/admin/entreprise_dashbord/workspace/%d', $e->getId()));
        $this->assertStringContainsString('block/depenses', (string) $this->client->getResponse()->getContent(), 'Le bloc Dépenses doit être déclaré dans le tableau de bord.');
    }
}
