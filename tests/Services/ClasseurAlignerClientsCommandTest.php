<?php

namespace App\Tests\Services;

use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * LE RATTRAPAGE DES ANCIENNES DONNÉES — et surtout ce qu'il refuse de défaire.
 *
 * POURQUOI IL Y A UN RATTRAPAGE. Le classement des documents n'existait qu'à moitié : le
 * champ était là, le formulaire l'offrait, et rien ne le remplissait jamais. Tout
 * l'historique porte donc la marque de ce vide. Le rangement est devenu automatique pour
 * ce qui s'écrit ; cette commande s'occupe de ce qui était déjà écrit.
 *
 * CE QUE CE TEST PROTÈGE EN PRIORITÉ. Une reprise de données est ce qu'on n'exécute qu'une
 * fois, sur des données qu'on ne peut plus reconstituer. Trois propriétés comptent donc
 * plus que le reste :
 *
 *  1. le DRY-RUN n'écrit rien — sinon on ne peut pas lire avant d'agir ;
 *  2. elle est IDEMPOTENTE — un second passage ne doit pas doubler les classeurs ;
 *  3. elle ne DÉPLACE pas un classement fait à la main, sauf demande explicite. C'est la
 *     seule opération irréversible du lot : un document rangé dans « Docs de production »
 *     y a été mis par quelqu'un, et le déplacer d'office lui ferait chercher sa pièce là
 *     où il l'avait laissée.
 */
class ClasseurAlignerClientsCommandTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-align-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Align SARL';
    private const CLIENT_NOM = 'PHPUnit Client À Aligner';
    private const CLASSEUR_MANUEL = 'PHPUnit Docs de production';

    protected function setUp(): void
    {
        static::bootKernel();
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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();

        foreach (['document', 'classeur', 'client', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Un client, et DEUX documents à lui : l'un non classé, l'autre rangé à la main.
     *
     * Les deux documents sont posés en SQL direct, sans passer par l'entity manager. Ce
     * n'est pas un raccourci : l'écouteur Doctrine rangerait le premier au moment du
     * flush, et il n'y aurait alors plus rien à rattraper. On fabrique donc l'état
     * d'AVANT — le seul que cette commande ait à traiter.
     *
     * @return array{client: int, nonClasse: int, dejaClasse: int, classeurManuel: int}
     */
    private function seed(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit Align')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-ALI')->setAdresse('1 rue')
            ->setTelephone('+243000000030')->setRccm('R-ALI')->setIdnat('I-ALI')->setNumimpot('N-ALI')
            ->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $invite = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        $client = (new Client())->setNom(self::CLIENT_NOM);
        $client->setPortefeuille(null)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($client);

        $manuel = (new Classeur())->setNom(self::CLASSEUR_MANUEL)->setDescription('Rangé à la main');
        $manuel->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($manuel);

        $em->flush();

        $conn = $em->getConnection();

        // L'ÉTAT D'AVANT, FABRIQUÉ À LA MAIN. Depuis que le classeur naît avec le client,
        // celui qu'on vient de créer en a déjà un — et il n'y aurait plus rien à
        // rattraper. On le retire donc, pour reproduire exactement ce que la base contient
        // pour les clients d'avant ce chantier : un client sans dossier.
        $conn->executeStatement('DELETE FROM classeur WHERE client_id = :id', ['id' => $client->getId()]);

        $colonnes = 'nom, client_id, entreprise_id, invite_id, created_at';
        $conn->executeStatement(
            "INSERT INTO document ({$colonnes}) VALUES (:nom, :client, :ent, :inv, NOW())",
            ['nom' => 'Pièce jamais classée', 'client' => $client->getId(), 'ent' => $entreprise->getId(), 'inv' => $invite->getId()],
        );
        $nonClasse = (int) $conn->lastInsertId();

        $conn->executeStatement(
            "INSERT INTO document ({$colonnes}, classeur_id) VALUES (:nom, :client, :ent, :inv, NOW(), :cla)",
            [
                'nom' => 'Pièce rangée à la main', 'client' => $client->getId(),
                'ent' => $entreprise->getId(), 'inv' => $invite->getId(), 'cla' => $manuel->getId(),
            ],
        );
        $dejaClasse = (int) $conn->lastInsertId();

        $em->clear();

        return [
            'client' => (int) $client->getId(),
            'nonClasse' => $nonClasse,
            'dejaClasse' => $dejaClasse,
            'classeurManuel' => (int) $manuel->getId(),
        ];
    }

    /** @param list<string> $options */
    private function lancer(array $options = []): CommandTester
    {
        $commande = (new Application(static::$kernel))->find('app:classeur:aligner-clients');
        $tester = new CommandTester($commande);
        $tester->execute(array_fill_keys($options, true));

        return $tester;
    }

    private function classeurIdDe(int $idDocument): ?int
    {
        $valeur = $this->em()->getConnection()->fetchOne(
            'SELECT classeur_id FROM document WHERE id = :id',
            ['id' => $idDocument],
        );

        return ($valeur === false || $valeur === null) ? null : (int) $valeur;
    }

    private function nombreDeClasseursDe(int $idClient): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM classeur WHERE client_id = :id',
            ['id' => $idClient],
        );
    }

    /**
     * LE DRY-RUN RAPPORTE ET N'ÉCRIT RIEN.
     *
     * C'est la propriété qui rend la commande utilisable : on doit pouvoir lire ce qu'elle
     * ferait sur une base de production avant de l'y lâcher.
     */
    public function testLeDryRunNecritRien(): void
    {
        $seed = $this->seed();

        $sortie = $this->lancer()->getDisplay();

        self::assertStringContainsString('RIEN N\'A ÉTÉ ÉCRIT', $sortie);
        self::assertStringContainsString(self::CLIENT_NOM, $sortie, 'Elle doit dire quel classeur elle créerait.');
        self::assertSame(0, $this->nombreDeClasseursDe($seed['client']), 'Aucun classeur ne doit être créé en dry-run.');
        self::assertNull($this->classeurIdDe($seed['nonClasse']), 'Et aucun document ne doit être rangé.');
    }

    /**
     * AVEC --force : le client reçoit son classeur, et sa pièce non classée y entre.
     */
    public function testLeRattrapageDonneSonClasseurAuClientEtYRangeSesPieces(): void
    {
        $seed = $this->seed();

        $this->lancer(['--force']);
        $this->em()->clear();

        self::assertSame(1, $this->nombreDeClasseursDe($seed['client']), 'Un classeur, et un seul.');

        $idClasseur = $this->classeurIdDe($seed['nonClasse']);
        self::assertNotNull($idClasseur, 'La pièce non classée doit rejoindre le dossier du client.');

        $classeur = $this->em()->getRepository(Classeur::class)->find($idClasseur);
        self::assertInstanceOf(Classeur::class, $classeur);
        self::assertSame(self::CLIENT_NOM, $classeur->getNom());
        self::assertSame($seed['client'], $classeur->getClient()?->getId());
    }

    /**
     * SANS --reclasser, LE CLASSEMENT MANUEL EST INTACT.
     *
     * La seule opération irréversible du lot, et donc celle qui ne doit jamais arriver par
     * défaut.
     */
    public function testLeClassementManuelNestPasDefaitParDefaut(): void
    {
        $seed = $this->seed();

        $this->lancer(['--force']);

        self::assertSame(
            $seed['classeurManuel'],
            $this->classeurIdDe($seed['dejaClasse']),
            'Le document rangé à la main doit rester où l’utilisateur l’avait mis.',
        );
    }

    /**
     * AVEC --reclasser, ET SEULEMENT ALORS, il rejoint le dossier du client.
     *
     * L'option existe parce que l'alignement complet est une demande légitime ; elle est
     * explicite parce que défaire le travail de quelqu'un ne doit pas être un défaut.
     */
    public function testAvecReclasserLaPieceRejointLeDossierDuClient(): void
    {
        $seed = $this->seed();

        $sortie = $this->lancer(['--force', '--reclasser'])->getDisplay();

        self::assertStringContainsString('DÉPLACÉS', $sortie, 'L’option doit avertir de ce qu’elle fait.');

        $idClasseur = $this->classeurIdDe($seed['dejaClasse']);
        self::assertNotNull($idClasseur);
        self::assertNotSame($seed['classeurManuel'], $idClasseur, 'La pièce doit avoir quitté le classeur manuel.');

        $classeur = $this->em()->getRepository(Classeur::class)->find($idClasseur);
        self::assertSame($seed['client'], $classeur?->getClient()?->getId());
    }

    /**
     * IDEMPOTENTE : le second passage ne trouve plus rien à faire.
     *
     * Sans cette garantie, une commande relancée par prudence — le réflexe normal quand on
     * ne sait plus si elle a tourné — doublerait les classeurs de chaque client.
     */
    public function testUnSecondPassageNeDoublePasLesClasseurs(): void
    {
        $seed = $this->seed();

        $this->lancer(['--force']);
        $this->em()->clear();
        $premier = $this->classeurIdDe($seed['nonClasse']);

        $sortie = $this->lancer(['--force'])->getDisplay();
        $this->em()->clear();

        self::assertSame(1, $this->nombreDeClasseursDe($seed['client']), 'Toujours un seul classeur.');
        self::assertSame($premier, $this->classeurIdDe($seed['nonClasse']), 'Et la pièce n’a pas changé de place.');
        self::assertStringNotContainsString(
            self::CLIENT_NOM,
            $sortie,
            'Le second passage ne doit RIEN annoncer sur ce client : plus rien à créer ni à ranger.',
        );
    }
}
