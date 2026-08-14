<?php

namespace App\Tests\Workspace;

use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * LE TÉLÉCHARGEMENT D'UN DOCUMENT DEPUIS LA RUBRIQUE — la route que la rubrique
 * Documents appelle au clic sur l'icône de téléchargement.
 *
 * POURQUOI CES TESTS N'EXISTAIENT PAS, ET POURQUOI ILS EXISTENT MAINTENANT. La route se
 * contentait du `ROLE_USER` porté par la classe du contrôleur, et chargeait le document
 * par sa clé primaire via le ParamConverter. Autrement dit : n'importe quel utilisateur
 * connecté, de n'importe quelle entreprise, obtenait n'importe quel document en
 * incrémentant l'identifiant dans l'URL. Rien ne le signalait, et aucun test ne
 * l'aurait vu — il n'y en avait aucun.
 *
 * Le second test porte sur quelque chose de moins grave mais de plus visible : le nom du
 * fichier reçu. Il arrivait sous son nom de STOCKAGE
 * (« licence-marsh-africa-19-266-77-6a69ff….pdf ») là où la fiche affiche « Police RC
 * Auto Orange ». C'est la même règle que celle appliquée par l'assistant, désormais
 * partagée : les deux surfaces servent le même fichier sous le même nom.
 */
class DocumentTelechargementTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-dltest-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-dltest-guest@test.local';
    private const VOISIN_EMAIL = 'phpunit-dltest-voisin@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit DLTest SARL';
    private const ENTREPRISE_AUTRE = 'PHPUnit DLTest Voisine';
    private const PASSWORD = 'Test1234!';

    private KernelBrowser $client;

    /** @var list<string> */
    private array $fichiersEcrits = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        foreach ($this->fichiersEcrits as $chemin) {
            @unlink($chemin);
        }
        $this->fichiersEcrits = [];
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email): ?Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function dossierUploads(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/documents';
    }

    /**
     * Balaie les binaires d'une exécution précédente : un échec dans setUp() empêche
     * tearDown(), et les fichiers survivraient. Préfixe de CE test uniquement.
     */
    private function balayerBinairesOrphelins(): void
    {
        foreach (glob($this->dossierUploads() . '/phpunit-dltest-*') ?: [] as $orphelin) {
            @unlink($orphelin);
        }
    }

    private function cleanUp(): void
    {
        $this->balayerBinairesOrphelins();

        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_AUTRE];
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL, self::VOISIN_EMAIL];

        foreach (['document', 'roles_en_administration', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM entreprise WHERE nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    private function makeUser(string $email): Utilisateur
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new Utilisateur())->setEmail($email)->setNom('PHPUnit DLTest')->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);

        return $user;
    }

    private function ecrireBinaire(string $contenu): string
    {
        $dossier = $this->dossierUploads();
        if (!is_dir($dossier)) {
            mkdir($dossier, 0o777, true);
        }
        $nomStocke = 'phpunit-dltest-' . bin2hex(random_bytes(6)) . '.pdf';
        $chemin = $dossier . '/' . $nomStocke;
        file_put_contents($chemin, $contenu);
        $this->fichiersEcrits[] = $chemin;

        return $nomStocke;
    }

    /** @return array{idDocument: int, idVoisin: int} */
    private function seed(): array
    {
        $em = $this->em();

        $owner = $this->makeUser(self::OWNER_EMAIL);
        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-DLT')->setAdresse('1 rue Servie')
            ->setTelephone('+243000000030')->setRccm('RCCM-DLT')->setIdnat('IDNAT-DLT')
            ->setNumimpot('IMP-DLT')->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $ownerInvite = (new Invite())->setNom('Propriétaire');
        $ownerInvite->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($ownerInvite);

        // Collaborateur de la MÊME entreprise, sans lecture sur Document.
        $guest = $this->makeUser(self::GUEST_EMAIL);
        $guest->setConnectedTo($entreprise);
        $guestInvite = (new Invite())->setNom('Collaborateur');
        $guestInvite->setUtilisateur($guest)->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($guestInvite);
        $role = (new RolesEnAdministration())->setNom('Rôle sans documents');
        $role->setAccessDocument([]);
        $role->setEntreprise($entreprise);
        $guestInvite->addRolesEnAdministration($role);
        $em->persist($role);

        $document = (new Document())->setNom('Police RC Auto Orange');
        $document->setNomFichierStocke($this->ecrireBinaire('CONTENU DE LA POLICE'));
        $document->setEntreprise($entreprise)->setInvite($ownerInvite);
        $em->persist($document);

        // ── Entreprise voisine, et son propre propriétaire ──
        $voisinUser = $this->makeUser(self::VOISIN_EMAIL);
        $autreEntreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_AUTRE)->setLicence('LIC-DLT2')->setAdresse('2 rue Voisine')
            ->setTelephone('+243000000031')->setRccm('RCCM-DLT2')->setIdnat('IDNAT-DLT2')
            ->setNumimpot('IMP-DLT2')->setUtilisateur($voisinUser);
        $em->persist($autreEntreprise);
        $voisinUser->setConnectedTo($autreEntreprise);
        $voisinInvite = (new Invite())->setNom('Voisin');
        $voisinInvite->setUtilisateur($voisinUser)->setEntreprise($autreEntreprise)->setProprietaire(true);
        $em->persist($voisinInvite);

        $em->flush();

        return ['idDocument' => (int) $document->getId(), 'idVoisin' => (int) $voisinInvite->getId()];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Le fichier arrive, et il arrive sous le nom que la fiche affiche. */
    public function testLeDocumentArriveSousSonNomLisible(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', '/admin/document/api/' . $seed['idDocument'] . '/download');

        self::assertResponseIsSuccessful();
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringContainsString('Police RC Auto Orange.pdf', $disposition, 'Le libellé du document, avec son extension — jamais le nom de stockage Vich.');
        self::assertStringNotContainsString('phpunit-dltest-', $disposition);
        self::assertSame('CONTENU DE LA POLICE', $this->client->getInternalResponse()->getContent());
    }

    /**
     * LA FAILLE, verrouillée : un utilisateur d'une AUTRE entreprise ne peut plus tirer
     * un document en devinant son identifiant. La réponse est 404 — celle qu'aurait
     * donnée un identifiant inexistant, et qui n'apprend donc rien sur ce qui existe.
     */
    public function testUnUtilisateurDUneAutreEntrepriseNObtientRien(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::VOISIN_EMAIL));

        $this->client->request('GET', '/admin/document/api/' . $seed['idDocument'] . '/download');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('CONTENU DE LA POLICE', $this->client->getInternalResponse()->getContent());
    }

    /**
     * Et dans l'entreprise elle-même, le droit de lecture sur Document est exigé : la
     * route suit le même périmètre que la rubrique qui l'appelle.
     */
    public function testSansDroitDeLectureLeTelechargementEstRefuse(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->client->request('GET', '/admin/document/api/' . $seed['idDocument'] . '/download');

        self::assertResponseStatusCodeSame(403);
    }
}
