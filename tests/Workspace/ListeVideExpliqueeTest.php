<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnProduction;
use App\Entity\Utilisateur;
use App\Services\Search\PortefeuilleScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LA LISTE VIDE DIT POURQUOI (chantier 6, décisions de Serge du 2026-10-21).
 *
 *   — « Mon portefeuille » est la SEULE cause : « Aucune fiche dans votre portefeuille » et un
 *     lien « Afficher tout le cabinet » qui retire la chip — proposé à TOUS, la chip étant un
 *     confort de lecture (option A) ;
 *   — d'autres filtres sont actifs : « Effacer les filtres » retire ceux-là, et ne retire
 *     « Mon portefeuille » que pour un ADMINISTRATEUR du cabinet ;
 *   — rien ne filtre (ou le cabinet n'a aucune fiche) : « Aucune donnée ».
 * C'est le SERVEUR qui sait s'il existe des fiches hors filtres, et qui rend l'état vide.
 */
final class ListeVideExpliqueeTest extends WebTestCase
{
    private const ENTREPRISE = 'PHPUNIT Liste vide';
    private const PROPRIETAIRE = 'phpunit-liste-vide-proprio@test.local';
    private const INVITE = 'phpunit-liste-vide-invite@test.local';
    private const CLIENT = 'PHPUNIT-LV Client hors portefeuille';

    private KernelBrowser $navigateur;

    protected function setUp(): void
    {
        $this->navigateur = static::createClient();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function nettoyer(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:a, :b)', ['a' => self::PROPRIETAIRE, 'b' => self::INVITE]);
        $conn->executeStatement('DELETE c FROM client c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENTREPRISE]);
        $conn->executeStatement('DELETE r FROM roles_en_production r JOIN invite i ON r.invite_id = i.id JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENTREPRISE]);
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENTREPRISE]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENTREPRISE]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:a, :b)', ['a' => self::PROPRIETAIRE, 'b' => self::INVITE]);
    }

    /**
     * Un cabinet, son propriétaire (administrateur), un invité qui ne fait que LIRE les clients
     * (non administrateur), et — si demandé — un client hors de tout portefeuille.
     *
     * @return array{proprio: Invite, invite: Invite, entreprise: Entreprise}
     */
    private function semer(bool $avecClient = true): array
    {
        $em = $this->em();
        $utilisateur = fn (string $email) => (new Utilisateur())->setEmail($email)->setNom($email)->setVerified(true)->setPassword('x');

        $u1 = $utilisateur(self::PROPRIETAIRE);
        $em->persist($u1);
        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE)->setLicence('L')->setAdresse('A')->setTelephone('T')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($u1);
        $em->persist($entreprise);
        $u1->setConnectedTo($entreprise);
        $proprio = (new Invite())->setNom('Propriétaire')->setUtilisateur($u1)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($proprio);

        $u2 = $utilisateur(self::INVITE);
        $em->persist($u2);
        $u2->setConnectedTo($entreprise);
        $invite = (new Invite())->setNom('Lecteur')->setUtilisateur($u2)->setEntreprise($entreprise)->setProprietaire(false);
        $role = (new RolesEnProduction())->setNom('Lecture des clients');
        $role->setAccessClient([Invite::ACCESS_LECTURE]);
        $role->setEntreprise($entreprise);
        $invite->addRolesEnProduction($role);
        $em->persist($role);
        $em->persist($invite);

        if ($avecClient) {
            $client = (new Client())->setNom(self::CLIENT)->setExonere(false);
            $client->setEntreprise($entreprise);
            $em->persist($client);
        }
        $em->flush();

        return ['proprio' => $proprio, 'invite' => $invite, 'entreprise' => $entreprise];
    }

    private function seConnecter(string $email): void
    {
        $this->navigateur->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]));
    }

    /** Le HTML de la rubrique Clients au premier affichage (critère « Mon portefeuille » par défaut). */
    private function premierAffichage(Invite $invite, Entreprise $e): string
    {
        $this->navigateur->request('GET', sprintf('/admin/client/index/%d/%d', $invite->getId(), $e->getId()));
        self::assertResponseIsSuccessful();

        // Brut : l'attribut des critères porte du JSON échappé, que clesDuBouton décode lui-même.
        return (string) $this->navigateur->getResponse()->getContent();
    }

    /** @return array<string, mixed> */
    private function recherche(Invite $invite, Entreprise $e, array $criteres): array
    {
        $this->navigateur->request('POST', sprintf('/admin/client/api/dynamic-query/%d/%d', $invite->getId(), $e->getId()), [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['criteria' => $criteres, 'parentContext' => null, 'page' => 1]));
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->navigateur->getResponse()->getContent(), true);
    }

    private function monPortefeuille(Invite $invite): array
    {
        return [PortefeuilleScope::CRITERION_KEY => ['operator' => '=', 'value' => $invite->getId(), 'label' => 'Mon portefeuille']];
    }

    /** Les clés que porte le bouton d'un état vide, lues dans son attribut. */
    private static function clesDuBouton(string $html, string $libelle): ?array
    {
        if (!preg_match('/<button\b((?:[^>"]|"[^"]*")*)>(?:(?!<\/button>).)*' . preg_quote($libelle, '/') . '/s', $html, $m)) {
            return null;
        }
        if (!preg_match('/data-list-manager-cles-param="([^"]*)"/', $m[1], $c)) {
            return [];
        }
        $cles = json_decode(html_entity_decode($c[1], ENT_QUOTES), true) ?? [];
        sort($cles);

        return $cles;
    }

    // ── « Mon portefeuille » cache tout ─────────────────────────────────────────

    public function testLePortefeuilleCacheToutPourUnAdministrateur(): void
    {
        ['proprio' => $proprio, 'entreprise' => $e] = $this->semer();
        $this->seConnecter(self::PROPRIETAIRE);

        $html = $this->premierAffichage($proprio, $e);
        self::assertStringNotContainsString(self::CLIENT, $html, 'Préalable : le portefeuille cache le client.');
        self::assertStringContainsString('Aucune fiche dans votre portefeuille', $html);
        self::assertSame([PortefeuilleScope::CRITERION_KEY], self::clesDuBouton($html, 'Afficher tout le cabinet'));
        self::assertNull(self::clesDuBouton($html, 'Effacer les filtres'));

        $reponse = $this->recherche($proprio, $e, $this->monPortefeuille($proprio));
        self::assertSame('portefeuille', $reponse['etatVide']['cause'] ?? null);
        self::assertStringContainsString('Afficher tout le cabinet', $reponse['etatVide']['html']);
    }

    public function testLePortefeuilleCacheToutPourUnNonAdministrateur(): void
    {
        ['invite' => $invite, 'entreprise' => $e] = $this->semer();
        $this->seConnecter(self::INVITE);

        $html = $this->premierAffichage($invite, $e);
        self::assertStringNotContainsString(self::CLIENT, $html, 'Préalable : le portefeuille cache le client.');
        self::assertStringContainsString('Aucune fiche dans votre portefeuille', $html);
        // Proposé à TOUS : la chip est un confort de lecture (option A).
        self::assertSame([PortefeuilleScope::CRITERION_KEY], self::clesDuBouton($html, 'Afficher tout le cabinet'));
        self::assertNull(self::clesDuBouton($html, 'Effacer les filtres'));

        $reponse = $this->recherche($invite, $e, $this->monPortefeuille($invite));
        self::assertSame('portefeuille', $reponse['etatVide']['cause'] ?? null);
    }

    // ── D'autres filtres actifs ─────────────────────────────────────────────────

    public function testEffacerLesFiltresRetireLePortefeuillePourUnAdministrateurSeulement(): void
    {
        ['proprio' => $proprio, 'invite' => $invite, 'entreprise' => $e] = $this->semer();
        $terme = ['nom' => ['operator' => 'LIKE', 'value' => 'introuvable-zz']];

        $this->seConnecter(self::PROPRIETAIRE);
        $admin = $this->recherche($proprio, $e, $terme + $this->monPortefeuille($proprio));
        self::assertSame('filtres', $admin['etatVide']['cause'] ?? null);
        self::assertSame([PortefeuilleScope::CRITERION_KEY, 'nom'], self::clesDuBouton($admin['etatVide']['html'], 'Effacer les filtres'));

        $this->seConnecter(self::INVITE);
        $lecteur = $this->recherche($invite, $e, $terme + $this->monPortefeuille($invite));
        self::assertSame('filtres', $lecteur['etatVide']['cause'] ?? null);
        self::assertSame(['nom'], self::clesDuBouton($lecteur['etatVide']['html'], 'Effacer les filtres'));
    }

    /**
     * ADMINISTRATEUR = le propriétaire OU le DÉLÉGUÉ « gestionnaire des invités » (décision de
     * Serge du 2026-10-21) — pas un cumul de droits par rubrique. Seul le propriétaire garde
     * la suppression du cabinet et l'achat des tokens.
     */
    public function testLeDelegueEstAdministrateurDuCabinet(): void
    {
        ['invite' => $invite, 'entreprise' => $e] = $this->semer();
        $invite = $this->em()->find(Invite::class, $invite->getId());
        $invite->setGestionnaireInvites(true);
        $this->em()->flush();
        $this->seConnecter(self::INVITE);

        $reponse = $this->recherche($invite, $e, ['nom' => ['operator' => 'LIKE', 'value' => 'introuvable-zz']] + $this->monPortefeuille($invite));
        self::assertSame('filtres', $reponse['etatVide']['cause'] ?? null);
        self::assertSame([PortefeuilleScope::CRITERION_KEY, 'nom'], self::clesDuBouton($reponse['etatVide']['html'], 'Effacer les filtres'), 'Le délégué est administrateur : le portefeuille part aussi.');

        $resolveur = static::getContainer()->get(\App\Service\Workspace\WorkspaceAccessResolver::class);
        self::assertTrue($resolveur->estAdministrateurDuCabinet($invite));
        $invite->setGestionnaireInvites(false);
        self::assertFalse($resolveur->estAdministrateurDuCabinet($invite), 'Sans la délégation, un simple lecteur ne l’est pas.');
    }

    // ── Rien à montrer ──────────────────────────────────────────────────────────

    public function testUnCabinetSansFicheDitAucuneDonnee(): void
    {
        ['proprio' => $proprio, 'entreprise' => $e] = $this->semer(false);
        $this->seConnecter(self::PROPRIETAIRE);

        $html = $this->premierAffichage($proprio, $e);
        self::assertStringContainsString('Aucune donnée', $html);
        self::assertNull(self::clesDuBouton($html, 'Afficher tout le cabinet'));
        self::assertNull(self::clesDuBouton($html, 'Effacer les filtres'));

        $reponse = $this->recherche($proprio, $e, ['nom' => ['operator' => 'LIKE', 'value' => 'x']]);
        self::assertSame('aucune_donnee', $reponse['etatVide']['cause'] ?? null);
    }

    /**
     * UN ONGLET CONTEXTUEL (collection d'une fiche) juge ses droits d'ajout ailleurs
     * (droitsSurLaCollection) : son état vide ne propose jamais « Ajouter » — sinon une
     * troisième porte vers un geste que le serveur refusera (revue du chantier 6).
     */
    public function testUnOngletContextuelVideNeProposePasDAjouter(): void
    {
        ['proprio' => $proprio, 'entreprise' => $e] = $this->semer();
        $this->seConnecter(self::PROPRIETAIRE);
        $client = $this->em()->getRepository(Client::class)->findOneBy(['nom' => self::CLIENT]);

        $this->navigateur->request('POST', sprintf('/admin/piste/api/dynamic-query/%d/%d', $proprio->getId(), $e->getId()), [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['criteria' => [], 'parentContext' => ['id' => $client->getId(), 'collection' => 'pistes', 'champ' => 'client'], 'page' => 1]));
        self::assertResponseIsSuccessful();
        $reponse = json_decode((string) $this->navigateur->getResponse()->getContent(), true);
        self::assertSame('aucune_donnee', $reponse['etatVide']['cause'] ?? null);
        self::assertStringNotContainsString('requestAddItem', $reponse['etatVide']['html']);
    }

    public function testUneListeNonVideNePorteAucunEtatVide(): void
    {
        ['proprio' => $proprio, 'entreprise' => $e] = $this->semer();
        $this->seConnecter(self::PROPRIETAIRE);

        $reponse = $this->recherche($proprio, $e, []);
        self::assertGreaterThan(0, $reponse['pagination']['totalItems']);
        self::assertArrayNotHasKey('etatVide', $reponse);
    }
}
