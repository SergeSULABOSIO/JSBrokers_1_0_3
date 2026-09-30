<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\NotificationSinistre;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'ONGLET D'UN CLIENT NE MONTRE PAS LES ENFANTS DU CLIENT D'À CÔTÉ.
 *
 * ── POURQUOI CE BANC EXISTE, ET POURQUOI IL SÈME DEUX CLIENTS ───────────────────────
 * Le test d'un filtre qui vérifie seulement que l'enfant attendu est PRÉSENT ne prouve
 * rien : une liste non filtrée le contient aussi. C'est un faux positif parfait, et ce
 * projet l'a déjà payé — un critère dont l'opérateur était inconnu renvoyait l'ensemble
 * complet, et le test le validait.
 *
 * On sème donc DEUX clients dans le même cabinet, chacun avec ses enfants, et l'assertion
 * qui compte est l'ABSENCE de ceux du voisin.
 *
 * ── LE CHEMIN EXERCÉ EST LE SECOND, CELUI QUI MENTAIT ───────────────────────────────
 * Le premier affichage d'un onglet passe par le getter Doctrine du parent : il est juste
 * par construction, et c'est le seul que les tests existants exerçaient. Celui-ci attaque
 * `dynamic-query` — la route de la page 2, de la recherche, du « Réinitialiser » et du
 * rafraîchissement après enregistrement. C'est là que quatre des six onglets d'un client
 * affichaient tout le cabinet.
 *
 * Les quatre onglets fautifs sont couverts, dont les deux pièges :
 *  - SINISTRES : le champ ne s'appelle pas `client` mais `assure` ;
 *  - PARTENAIRES : c'est un ManyToMany — une égalité n'y exprime rien, il faut MEMBER OF.
 */
class OngletContextuelExclusiviteTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-onglet-exclusivite@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Onglet Exclusivite SARL';
    private const PASSWORD = 'Test1234!';

    /** Marqueurs : uniques, et lisibles dans un message d'échec. */
    private const CLIENT_X = 'ZZ-CLIENT-X-EXCLUSIVITE';
    private const CLIENT_Y = 'ZZ-CLIENT-Y-EXCLUSIVITE';
    private const ENFANT_X = 'ZZ-ENFANT-DE-X';
    private const ENFANT_Y = 'ZZ-ENFANT-DE-Y';

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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email = :email',
            ['email' => self::OWNER_EMAIL],
        );
        // La table de jonction d'abord : elle ne porte pas d'entreprise.
        $conn->executeStatement(
            'DELETE cp FROM client_partenaire cp
             JOIN client c ON cp.client_id = c.id
             JOIN entreprise e ON c.entreprise_id = e.id
             WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach (['note', 'piste', 'notification_sinistre', 'partenaire', 'client'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN entreprise e ON t.entreprise_id = e.id
                 WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement(
            'DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email = :email OR e.nom = :nom',
            ['email' => self::OWNER_EMAIL, 'nom' => self::ENTREPRISE_NOM],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    /**
     * Deux clients du MÊME cabinet, chacun doté des quatre enfants qui ne filtraient pas.
     *
     * @return array{0: Entreprise, 1: Invite, 2: Client, 3: Client}
     */
    private function seed(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new Utilisateur();
        $user->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit')->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $em->persist($user);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)
            ->setLicence('LIC-OE')->setAdresse('1 rue du Test')->setTelephone('+243000000000')
            ->setRccm('RCCM-OE')->setIdnat('IDNAT-OE')->setNumimpot('IMP-OE');
        $entreprise->setUtilisateur($user);
        $user->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Administrateur')->setProprietaire(true);
        $invite->setUtilisateur($user)->setEntreprise($entreprise);
        $em->persist($invite);

        $clientX = $this->semerClient($entreprise, $invite, self::CLIENT_X, self::ENFANT_X);
        $clientY = $this->semerClient($entreprise, $invite, self::CLIENT_Y, self::ENFANT_Y);

        $em->flush();

        return [$entreprise, $invite, $clientX, $clientY];
    }

    private function semerClient(Entreprise $e, Invite $invite, string $nom, string $marqueur): Client
    {
        $em = $this->em();

        $client = (new Client())->setNom($nom);
        $client->setEntreprise($e)->setInvite($invite);
        $em->persist($client);

        $piste = (new Piste())
            ->setNom($marqueur . '-PISTE')
            ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Risque de test')
            ->setExercice((int) date('Y'))
            ->setClient($client);
        $piste->setEntreprise($e)->setInvite($invite);
        $em->persist($piste);

        $note = (new Note())
            ->setNom($marqueur . '-NOTE')
            ->setReference($marqueur . '-REF')
            ->setType(Note::TYPE_NOTE_DE_DEBIT)
            ->setAddressedTo(Note::TO_CLIENT)
            ->setSignature('sig')
            // Colonne NOT NULL qu'aucun formulaire ne propose : cf. ValeursDeNaissance,
            // que ce semis court-circuite en montant l'entité à la main.
            ->setValidated(false)
            ->setClient($client);
        $note->setEntreprise($e)->setInvite($invite);
        $em->persist($note);

        // ⚠ `setAssure`, PAS `setClient` : sur un sinistre, le client s'appelle l'assuré.
        $sinistre = (new NotificationSinistre())
            ->setReferenceSinistre($marqueur . '-SINISTRE')
            ->setOccuredAt(new \DateTimeImmutable('now'))
            ->setAssure($client);
        $sinistre->setEntreprise($e)->setInvite($invite);
        $em->persist($sinistre);

        $partenaire = (new Partenaire())->setNom($marqueur . '-PARTENAIRE')->setPart(50.0);
        $partenaire->setEntreprise($e)->setInvite($invite);
        $em->persist($partenaire);
        // ManyToMany : on pose les deux côtés, la collection inverse n'est jamais
        // rafraîchie tant que l'entité managée n'est pas re-hydratée.
        $client->addPartenaire($partenaire);

        return $client;
    }

    /**
     * La requête que fait un onglet contextuel dès qu'il quitte son premier affichage :
     * page 2, recherche, « Réinitialiser », rafraîchissement.
     *
     * @return array{pagination: array, html: string}
     */
    private function ongletContextuel(string $racine, Invite $invite, Entreprise $e, ?array $parentContext): array
    {
        $this->client->request(
            'POST',
            sprintf('/admin/%s/api/dynamic-query/%d/%d', $racine, $invite->getId(), $e->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['criteria' => [], 'parentContext' => $parentContext, 'page' => 1]),
        );
        self::assertResponseIsSuccessful(sprintf('L\'onglet « %s » doit répondre 200.', $racine));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed> le contexte tel que le navigateur l'envoie */
    private function contexte(Client $client, string $collection, string $champ, string $nature = 'to_one'): array
    {
        return [
            'id' => $client->getId(),
            'collection' => $collection,
            'champ' => $champ,
            'nature' => $nature,
        ];
    }

    /**
     * LES QUATRE ONGLETS QUI MENTAIENT, chacun prouvé par l'ABSENCE du voisin.
     *
     * @dataProvider ongletsDUnClient
     */
    public function testUnOngletNeMontreQueLesEnfantsDeSonClient(
        string $racine,
        string $collection,
        string $champ,
        string $nature,
    ): void {
        [$entreprise, $invite, $clientX] = $this->seed();
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]));

        $res = $this->ongletContextuel(
            $racine,
            $invite,
            $entreprise,
            $this->contexte($clientX, $collection, $champ, $nature),
        );

        self::assertStringContainsString(self::ENFANT_X, $res['html'],
            sprintf('L\'onglet « %s » doit montrer l\'enfant de son client.', $collection),
        );
        self::assertStringNotContainsString(self::ENFANT_Y, $res['html'], sprintf(
            'L\'onglet « %s » montre l\'enfant d\'un AUTRE client. C\'est le défaut que ce '
            . 'lot ferme : la liste repartait vers la rubrique entière, sous un en-tête et '
            . 'une pastille portant le nom du client ouvert.',
            $collection,
        ));
        self::assertSame(1, $res['pagination']['totalItems'], sprintf(
            'L\'onglet « %s » doit compter UN seul élément : un total juste sur une liste '
            . 'fausse trahirait un filtre appliqué à la page et pas au comptage.',
            $collection,
        ));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function ongletsDUnClient(): iterable
    {
        yield 'Pistes'      => ['piste', 'pistes', 'client', 'to_one'];
        yield 'Notes'       => ['note', 'notes', 'client', 'to_one'];
        // Le champ s'appelle `assure` : un `client` posé par symétrie aurait été rejeté
        // sans erreur ni log, et la liste aurait tout montré.
        yield 'Sinistres'   => ['notificationsinistre', 'notificationSinistres', 'assure', 'to_one'];
        // ManyToMany : l'enfant porte une COLLECTION de clients → MEMBER OF.
        yield 'Partenaires' => ['partenaire', 'partenaires', 'clients', 'collection'];
    }

    /**
     * ⚠ UN ONGLET QUI NE SAIT PAS SE FILTRER NE MONTRE PLUS RIEN.
     *
     * L'ancienne garde échouait OUVERT : champ absent ou inconnu, aucune clause n'était
     * posée, et la liste se repliait sur le seul périmètre du cabinet — sans erreur, sans
     * log, sans le moindre signe. Un écran qui affiche une pastille « Client X » au-dessus
     * du cabinet entier est pire qu'un écran vide : il AFFIRME.
     */
    public function testUnLienIntrouvableVideLaListeAuLieuDeToutMontrer(): void
    {
        [$entreprise, $invite, $clientX] = $this->seed();
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]));

        $res = $this->ongletContextuel(
            'piste',
            $invite,
            $entreprise,
            // Le cas réel : l'onglet annonce un parent, mais le champ n'a pas pu être résolu.
            ['id' => $clientX->getId(), 'collection' => 'pistes', 'champ' => null, 'nature' => null],
        );

        self::assertSame(0, $res['pagination']['totalItems'],
            'Sans lien exploitable, la liste doit être VIDE — jamais le cabinet entier.',
        );
        self::assertStringNotContainsString(self::ENFANT_X, $res['html']);
        self::assertStringNotContainsString(self::ENFANT_Y, $res['html']);
    }

    /**
     * LE LIEN DESCEND JUSQU'AU DOM — sans quoi le navigateur n'aurait rien à renvoyer.
     *
     * C'est le maillon que les tests précédents ne couvrent pas : ils postent un contexte
     * écrit à la main. Ici on ouvre l'onglet pour de vrai et l'on vérifie que la page
     * porte le lien résolu par Doctrine. Sans cet attribut, la chaîne se romprait en
     * silence et l'on retomberait exactement sur le défaut d'origine.
     */
    public function testLOngletRenduPorteLeLienVersSonParent(): void
    {
        [, , $clientX] = $this->seed();
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]));

        $this->client->request('GET', sprintf('/admin/client/api/%d/pistes/generic', $clientX->getId()));
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        // ⚠ ON DÉCODE AVANT DE COMPARER. L'attribut est échappé pour un contexte HTML :
        // le « : » y devient `&#x3A;`. Chercher la chaîne brute échouerait sur la forme,
        // pas sur le fond — et la corriger à la main figerait l'échappement de Twig.
        self::assertSame(
            1,
            preg_match('/data-list-manager-parent-lien-value=\'([^\']*)\'/', $html, $m),
            'L\'onglet doit porter le lien vers son parent : c\'est ce que le navigateur renvoie ensuite.',
        );

        self::assertSame(
            ['collection' => 'pistes', 'champ' => 'client', 'nature' => 'to_one'],
            json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true),
            'Le lien résolu par Doctrine doit descendre intact jusqu\'au DOM.',
        );
    }

    /**
     * ET LA LISTE PRINCIPALE, ELLE, N'EST PAS BRIDÉE. Sans onglet contextuel, il n'y a pas
     * de parent : la rubrique Pistes doit continuer de montrer les deux clients.
     */
    public function testSansParentLaRubriqueMontreToutLeCabinet(): void
    {
        [$entreprise, $invite] = $this->seed();
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]));

        $res = $this->ongletContextuel('piste', $invite, $entreprise, null);

        self::assertStringContainsString(self::ENFANT_X, $res['html']);
        self::assertStringContainsString(self::ENFANT_Y, $res['html']);
    }
}
