<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\NotificationSinistre;
use App\Entity\Utilisateur;
use App\Form\ClientType;
use App\Service\Workspace\FormTreeInspector;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * LES AFFAIRES ET LES DOSSIERS DU CLIENT, SUR SA PROPRE FICHE.
 *
 * ── LA CORVÉE QUE CE LOT SUPPRIME ───────────────────────────────────────────────────
 * La fiche d'un client montrait ses contacts et ses documents, mais ni ses PISTES ni ses
 * SINISTRES. Pour voir les affaires qu'on lui avait ouvertes, il fallait quitter sa
 * fiche, changer de rubrique, et l'y rechercher — celui-là même qu'on venait de quitter.
 *
 * ── CE QUE CE BANC PROTÈGE ──────────────────────────────────────────────────────────
 *   1. les deux onglets existent, AVEC leur champ de formulaire — un onglet déclaré au
 *      seul canevas s'affiche avec un panneau VIDE, et il ne dit jamais pourquoi ;
 *   2. l'URL des sinistres vise /admin/client/, jamais /admin/assure/ — le champ par
 *      lequel un sinistre désigne son client s'appelle `assure`, et c'est le piège exact
 *      que `parentRouteName` désamorce ;
 *   3. le gabarit d'ajout (prototype) ne se reconstruit pas : sans ce verrou, ouvrir une
 *      fiche client instancierait tout l'arbre des formulaires sous PisteType ;
 *   4. les deux onglets ne se comportent pas pareil EN CRÉATION, et c'est voulu ;
 *   5. la liste d'un onglet ne montre que les enfants de CE client, et un client d'un
 *      autre cabinet reste introuvable ;
 *   6. « Créer un sinistre » existe, rangée avec « Créer une piste » dans « Créer… » ;
 *   7. l'assuré arrive prérempli — et un identifiant forgé ne préremplit rien.
 */
class ClientPistesEtSinistresTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-pistes-sinistres@test.local';
    private const VOISIN_EMAIL = 'phpunit-pistes-sinistres-voisin@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Pistes Sinistres SARL';
    private const ENTREPRISE_VOISINE = 'PHPUnit Pistes Sinistres Voisine SARL';
    private const PASSWORD = 'Test1234!';

    private const CLIENT_NOM = 'ZZ-CLIENT-PISTES-SINISTRES';
    private const CLIENT_AUTRE = 'ZZ-CLIENT-SANS-SINISTRE';
    private const CLIENT_VOISIN = 'ZZ-CLIENT-VOISIN-SECRET';

    private const SINISTRE_REF = 'ZZ-SIN-DU-CLIENT';
    private const SINISTRE_REF_AUTRE = 'ZZ-SIN-DE-L-AUTRE';

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
        $emails = [self::OWNER_EMAIL, self::VOISIN_EMAIL];
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_VOISINE];

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        foreach (['notification_sinistre', 'piste', 'client'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN entreprise e ON t.entreprise_id = e.id
                 WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email IN (:emails) OR e.nom IN (:noms)',
            ['emails' => $emails, 'noms' => $noms],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING, 'noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
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

    /** @return array{0: Entreprise, 1: Invite, 2: Client, 3: Client, 4: Client} */
    private function seed(): array
    {
        $em = $this->em();

        [$entreprise, $invite] = $this->cabinet(self::OWNER_EMAIL, self::ENTREPRISE_NOM);
        [$voisine, $inviteVoisin] = $this->cabinet(self::VOISIN_EMAIL, self::ENTREPRISE_VOISINE);

        $client = $this->creerClient(self::CLIENT_NOM, $entreprise, $invite);
        $autre = $this->creerClient(self::CLIENT_AUTRE, $entreprise, $invite);
        $voisin = $this->creerClient(self::CLIENT_VOISIN, $voisine, $inviteVoisin);

        // Un sinistre pour chacun des DEUX clients du même cabinet : c'est ce qui permet
        // de prouver que la liste d'un onglet borne au client, et non au cabinet.
        $em->persist($this->creerSinistre(self::SINISTRE_REF, $client, $entreprise, $invite));
        $em->persist($this->creerSinistre(self::SINISTRE_REF_AUTRE, $autre, $entreprise, $invite));

        $em->flush();

        return [$entreprise, $invite, $client, $autre, $voisin];
    }

    private function creerClient(string $nom, Entreprise $entreprise, Invite $invite): Client
    {
        $c = (new Client())->setNom($nom);
        $c->setEntreprise($entreprise)->setInvite($invite);
        $this->em()->persist($c);

        return $c;
    }

    /**
     * Les dates sont CALCULÉES, jamais figées : un test arrimé à une date écrite en dur
     * passe aujourd'hui et échoue le jour où le calendrier le rattrape.
     */
    private function creerSinistre(string $reference, Client $assure, Entreprise $entreprise, Invite $invite): NotificationSinistre
    {
        $s = new NotificationSinistre();
        $s->setReferenceSinistre($reference);
        $s->setReferencePolice('POL-' . $reference);
        $s->setDescriptionDeFait('RAS');
        $s->setOccuredAt(new \DateTimeImmutable('-10 days'));
        $s->setNotifiedAt(new \DateTimeImmutable('-2 days'));
        $s->setEvaluationChiffree(1000.0);
        $s->setEntreprise($entreprise);
        $s->setInvite($invite);

        // LES DEUX CÔTÉS DU LIEN, ET PAS SEULEMENT LE PROPRIÉTAIRE. `setAssure()` suffit à
        // la base, mais l'entité Client garde en mémoire une collection que rien ne
        // rafraîchirait : la liste reviendrait vide alors que la ligne est bien écrite.
        $assure->addNotificationSinistre($s);

        return $s;
    }

    /** @return array{0: Entreprise, 1: Invite} */
    private function cabinet(string $email, string $nom): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new Utilisateur();
        $user->setEmail($email)->setNom('PHPUnit')->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $em->persist($user);

        $entreprise = (new Entreprise())
            ->setNom($nom)
            ->setLicence('LIC-PS')->setAdresse('1 rue du Test')->setTelephone('+243000000000')
            ->setRccm('RCCM-PS')->setIdnat('IDNAT-PS')->setNumimpot('IMP-PS');
        $entreprise->setUtilisateur($user);
        $user->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Administrateur')->setProprietaire(true);
        $invite->setUtilisateur($user)->setEntreprise($entreprise);
        $em->persist($invite);

        return [$entreprise, $invite];
    }

    private function connecter(string $email): void
    {
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]));
    }

    /** @return array<string, array{rangee: array, champ: array}> les rangées d'onglet, par champ */
    private function rangeesDOnglet(object $client, Entreprise $entreprise): array
    {
        /** @var CanvasBuilder $builder */
        $builder = static::getContainer()->get(CanvasBuilder::class);
        $canvas = $builder->getEntityFormCanvas($client, $entreprise->getId());

        $rangees = [];
        foreach ($canvas['form_layout'] as $rangee) {
            if (!isset($rangee['onglet_titre'])) {
                continue;
            }
            $champ = $rangee['colonnes'][0]['champs'][0];
            $rangees[$champ['field_code']] = ['rangee' => $rangee, 'champ' => $champ];
        }

        return $rangees;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // 1 & 2. LES ONGLETS, ET L'URL QUI LES ALIMENTE
    // ────────────────────────────────────────────────────────────────────────────────

    public function testLesDeuxOngletsSontDeclaresSurLaFicheClient(): void
    {
        [$entreprise, , $client] = $this->seed();

        $rangees = $this->rangeesDOnglet($client, $entreprise);

        self::assertArrayHasKey('pistes', $rangees, 'La fiche client doit porter un onglet « Pistes ».');
        self::assertArrayHasKey('notificationSinistres', $rangees, 'La fiche client doit porter un onglet « Sinistres ».');

        self::assertSame('Pistes', $rangees['pistes']['rangee']['onglet_titre']);
        self::assertSame('Sinistres', $rangees['notificationSinistres']['rangee']['onglet_titre']);

        self::assertSame(
            'primeTotale',
            $rangees['pistes']['champ']['options']['totalizableField'] ?? null,
            'Le pied de l\'onglet somme ce que ce client pèse en portefeuille.',
        );
        self::assertSame(
            'evaluationChiffree',
            $rangees['notificationSinistres']['champ']['options']['totalizableField'] ?? null,
        );
    }

    /**
     * LE PIÈGE DE CE LOT, ET LE SEUL.
     *
     * Le champ par lequel un sinistre désigne son client s'appelle `assure`, pas `client`.
     * L'URL de la liste se fabrique à partir du nom de champ du parent : sans
     * `parentRouteName`, elle viserait /admin/assure/… — une route qui n'existe pas, et un
     * onglet qui ne dirait jamais pourquoi il reste vide.
     *
     * `parentFieldName` doit, LUI, rester `assure` : c'est le setter de l'enfant et la clé
     * du FormData, pas un segment d'URL.
     */
    public function testLUrlDesSinistresViseLaRouteDuClientEtNonCelleDeLAssure(): void
    {
        [$entreprise, , $client] = $this->seed();

        $options = $this->rangeesDOnglet($client, $entreprise)['notificationSinistres']['champ']['options'];

        self::assertStringStartsWith(
            sprintf('/admin/client/api/%d/notificationSinistres', $client->getId()),
            $options['listUrl'],
            'Sans parentRouteName, l\'URL viserait /admin/assure/ — route inexistante.',
        );
        self::assertStringNotContainsString('/admin/assure/', $options['listUrl']);
        self::assertSame(
            'assure',
            $options['parentFieldName'],
            'Le nom de CHAMP reste `assure` : c\'est lui qui pose le parent sur l\'enfant.',
        );
        self::assertArrayNotHasKey(
            'parentRouteName',
            $options,
            'C\'est une donnée de construction d\'URL : le navigateur n\'en a aucun usage.',
        );
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // 3. LE GABARIT D'AJOUT NE SE RECONSTRUIT PAS
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * LE PROTOTYPE N'EST LU PAR PERSONNE — et le construire coûte tout l'arbre.
     *
     * Ajouter un élément ouvre le dialogue de l'enfant (itemFormUrl) ; le gabarit de
     * Symfony finit dans un `display:none` qu'aucun `data-prototype` ne vient lire. Le
     * laisser se construire ferait instancier ET rendre PisteType, puis ses cotations,
     * puis leurs tranches et leurs avenants — à chaque ouverture d'une fiche client, y
     * compris pour corriger un numéro de téléphone.
     *
     * `allow_add` reste VRAI : c'est lui — et non le prototype — que FormTreeInspector lit
     * pour ouvrir la même surface à l'assistant.
     */
    public function testLesDeuxCollectionsNeConstruisentPasLeurPrototype(): void
    {
        /** @var FormFactoryInterface $factory */
        $factory = static::getContainer()->get(FormFactoryInterface::class);
        $form = $factory->create(ClientType::class, new Client());

        foreach (['pistes', 'notificationSinistres'] as $champ) {
            self::assertTrue($form->has($champ), sprintf(
                'Sans le champ `%s` au FormType, l\'onglet s\'affiche avec un PANNEAU VIDE : '
                . 'le gabarit garde son rendu derrière `form[field_code] is defined`.',
                $champ,
            ));

            $config = $form->get($champ)->getConfig();
            self::assertFalse($config->getOption('prototype'), sprintf(
                'Le prototype de `%s` ferait instancier tout l\'arbre des formulaires enfants, '
                . 'pour un gabarit que rien ne lit.',
                $champ,
            ));
            self::assertTrue($config->getOption('allow_add'), 'La parité avec l\'assistant passe par allow_add.');
            self::assertTrue($config->getOption('allow_delete'));
            self::assertFalse(
                $config->getOption('mapped'),
                'Chaque enfant est créé, modifié et supprimé par sa propre API.',
            );
        }
    }

    /** La surface ouverte à l'assistant est EXACTEMENT celle du formulaire. */
    public function testLAssistantRecoitLaMemeSurfaceQueLEcran(): void
    {
        /** @var FormTreeInspector $inspecteur */
        $inspecteur = static::getContainer()->get(FormTreeInspector::class);

        $sinistres = $inspecteur->collectionEditable('Client', 'notificationSinistres');
        self::assertNotNull($sinistres, 'L\'assistant doit voir la collection que l\'écran expose.');
        self::assertSame(
            'setAssure',
            $sinistres->setterInverse,
            'Le lien se pose par `assure` : c\'est le nom du champ, pas celui de la route.',
        );
        self::assertTrue($sinistres->allowAdd);
        self::assertTrue($sinistres->allowDelete);

        self::assertNotNull($inspecteur->collectionEditable('Client', 'pistes'));
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // 4. LES DEUX ONGLETS NE SE COMPORTENT PAS PAREIL EN CRÉATION
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * UN CLIENT NEUF N'A AUCUNE POLICE — donc aucun sinistre à déclarer.
     *
     * Les pistes, elles, se saisissent très bien d'avance : on ouvre une affaire à un
     * client qu'on est en train de créer, et le tampon les rattache après l'enregistrement.
     *
     * L'onglet « Sinistres » reste VISIBLE — on voit qu'il existe et qu'il attend — mais sa
     * liste dit « Commencez par enregistrer » et son bouton d'ajout est retiré. `hidden`
     * doit rester faux : sans cela, le défaut de la mécanique ferait DISPARAÎTRE l'onglet
     * au lieu de le désarmer.
     */
    public function testEnCreationSeulLOngletDesSinistresEstDesarme(): void
    {
        [$entreprise] = $this->seed();

        $rangees = $this->rangeesDOnglet(new Client(), $entreprise);

        self::assertFalse(
            $rangees['pistes']['champ']['options']['disabled'],
            'Une piste se saisit d\'avance : le tampon la rattachera.',
        );

        self::assertTrue(
            $rangees['notificationSinistres']['champ']['options']['disabled'],
            'Un sinistre porte sur une police déjà souscrite : un client neuf n\'en a aucune.',
        );
        self::assertFalse(
            $rangees['notificationSinistres']['rangee']['hidden'],
            'L\'onglet se voit — désarmé n\'est pas absent.',
        );
    }

    /** Une fois le client enregistré, les deux onglets sont armés. */
    public function testUneFoisEnregistreLesDeuxOngletsSontArmes(): void
    {
        [$entreprise, , $client] = $this->seed();

        $rangees = $this->rangeesDOnglet($client, $entreprise);

        self::assertFalse($rangees['pistes']['champ']['options']['disabled']);
        self::assertFalse($rangees['notificationSinistres']['champ']['options']['disabled']);
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // 5. LA LISTE BORNE AU CLIENT, ET LE CABINET RESTE ÉTANCHE
    // ────────────────────────────────────────────────────────────────────────────────

    public function testLaListeNeMontreQueLesSinistresDeCeClient(): void
    {
        [, , $client] = $this->seed();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request('GET', sprintf('/admin/client/api/%d/notificationSinistres/dialog', $client->getId()));
        self::assertResponseIsSuccessful();

        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);
        $html = (string) ($charge['html'] ?? '');

        self::assertStringContainsString(self::SINISTRE_REF, $html);
        self::assertStringNotContainsString(
            self::SINISTRE_REF_AUTRE,
            $html,
            'La liste borne au CLIENT, pas au cabinet : le sinistre d\'un autre client n\'y est pas.',
        );
        self::assertSame(1, $charge['itemCount'] ?? null);
    }

    public function testUnClientDUnAutreCabinetNEstPasLisible(): void
    {
        [, , , , $voisin] = $this->seed();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request('GET', sprintf('/admin/client/api/%d/notificationSinistres/dialog', $voisin->getId()));

        self::assertGreaterThanOrEqual(
            400,
            $this->client->getResponse()->getStatusCode(),
            'Un client d\'un autre cabinet n\'ouvre pas sa collection.',
        );
        self::assertStringNotContainsString(self::CLIENT_VOISIN, (string) $this->client->getResponse()->getContent());
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // 6 & 7. L'ACTION, SA FAMILLE, ET LE PRÉREMPLISSAGE
    // ────────────────────────────────────────────────────────────────────────────────

    public function testLActionCreerUnSinistreEstRangeeDansLaFamilleCreer(): void
    {
        [$entreprise, , $client] = $this->seed();

        /** @var CanvasBuilder $builder */
        $builder = static::getContainer()->get(CanvasBuilder::class);
        $actions = $builder->getEntityFormCanvas($client, $entreprise->getId())['parametres']['attribute_actions'] ?? [];

        $parEvenement = [];
        foreach ($actions as $action) {
            $parEvenement[$action['event'] ?? ''] = $action;
        }

        self::assertArrayHasKey(
            'ui:client.creer-sinistre',
            $parEvenement,
            'La rubrique Clients doit proposer « Créer un sinistre ».',
        );

        $sinistre = $parEvenement['ui:client.creer-sinistre'];
        self::assertSame('Créer un sinistre', $sinistre['label']);
        self::assertSame('sinistre', $sinistre['icon'], 'Alias d\'IconCanvasProvider, jamais un nom en dur.');
        self::assertSame('/admin/client/api/%id%/sinistre-context', $sinistre['url']);
        self::assertSame(
            'Créer…',
            $sinistre['groupe'],
            'Toute action de création se range dans la même famille : c\'est la convention.',
        );
        self::assertArrayNotHasKey(
            'condition',
            $sinistre,
            'On peut toujours déclarer un sinistre à un client, quel que soit son état.',
        );

        self::assertSame(
            'Créer…',
            $parEvenement['ui:client.creer-piste']['groupe'] ?? null,
            'La piste rejoint la même famille, sinon la barre reste pleine.',
        );
    }

    public function testLaRouteRendLeCanevasDuSinistre(): void
    {
        [, , $client] = $this->seed();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request('GET', sprintf('/admin/client/api/%d/sinistre-context', $client->getId()));
        self::assertResponseIsSuccessful();

        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($client->getId(), $charge['clientId']);
        self::assertSame(
            '/admin/notificationsinistre/api/submit',
            $charge['formCanvas']['parametres']['endpoint_submit_url'] ?? null,
            'Le dialogue à ouvrir est celui d\'un sinistre, pas celui du client.',
        );
    }

    public function testLaRouteDeContexteRefuseUnClientDUnAutreCabinet(): void
    {
        [, , , , $voisin] = $this->seed();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request('GET', sprintf('/admin/client/api/%d/sinistre-context', $voisin->getId()));

        self::assertSame(
            404,
            $this->client->getResponse()->getStatusCode(),
            'On rend 404 : un 403 confirmerait l\'existence du client.',
        );
        self::assertStringNotContainsString(self::CLIENT_VOISIN, (string) $this->client->getResponse()->getContent());
    }

    /**
     * L'ASSURÉ ARRIVE PRÉREMPLI — et un identifiant forgé ne préremplit rien.
     *
     * L'appartenance est vérifiée DANS le contrôleur du sinistre, et pas seulement dans la
     * route de contexte : le paramètre vient de l'URL, et s'en remettre à l'amont
     * laisserait préremplir le client d'un autre cabinet.
     */
    public function testLAssureArrivePreremplEtLeCabinetResteEtanche(): void
    {
        [, , $client, , $voisin] = $this->seed();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request('GET', sprintf('/admin/notificationsinistre/api/get-form?idClient=%d', $client->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            self::CLIENT_NOM,
            (string) $this->client->getResponse()->getContent(),
            'Déclarer un sinistre au client qu\'on a sous les yeux ne doit pas obliger à le rechercher.',
        );

        $this->client->request('GET', sprintf('/admin/notificationsinistre/api/get-form?idClient=%d', $voisin->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            self::CLIENT_VOISIN,
            (string) $this->client->getResponse()->getContent(),
            'Un identifiant d\'un autre cabinet ne préremplit rien, et ne livre aucun nom.',
        );
    }
}
