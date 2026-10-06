<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Form\PisteType;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * OUVRIR UNE PISTE AU CLIENT QU'ON A SOUS LES YEUX.
 *
 * ── LA CORVÉE QUE CE LOT SUPPRIME ───────────────────────────────────────────────────
 * Décider d'ouvrir une affaire à un client depuis sa rubrique obligeait à changer de
 * rubrique, à créer une piste à blanc, puis à y rechercher ce même client à
 * l'autocomplétion. On ressaisissait ce qu'on venait de quitter.
 *
 * ── CE QUE CE BANC PROTÈGE ──────────────────────────────────────────────────────────
 *   1. l'action existe, et elle est rangée dans la famille « Créer… » ;
 *   2. la barre d'outils reste LISIBLE : elle ne montre que quatre entrées en ligne, et
 *      le client en portait déjà sept à plat ;
 *   3. la route rend le canevas de PISTE — celui de Client n'ouvrirait pas le bon
 *      dialogue — et refuse un client d'un autre cabinet SANS livrer son nom ;
 *   4. le formulaire arrive rempli, nom compris : c'est la demande d'origine ;
 *   5. le cross-selling du relevé de compte garde son nom AU CARACTÈRE PRÈS — la même
 *      règle sert les deux surfaces, et la seconde ne doit pas abîmer la première.
 */
class ClientCreerPisteActionTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-creer-piste@test.local';
    private const VOISIN_EMAIL = 'phpunit-creer-piste-voisin@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Creer Piste SARL';
    private const ENTREPRISE_VOISINE = 'PHPUnit Creer Piste Voisine SARL';
    private const PASSWORD = 'Test1234!';

    private const CLIENT_NOM = 'ZZ-CLIENT-CREER-PISTE';
    private const CLIENT_VOISIN = 'ZZ-CLIENT-DU-VOISIN-SECRET';

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
        foreach (['piste', 'risque', 'client'] as $table) {
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

    /** @return array{0: Entreprise, 1: Invite, 2: Client, 3: Client, 4: Risque} */
    private function seed(): array
    {
        $em = $this->em();

        [$entreprise, $invite] = $this->cabinet(self::OWNER_EMAIL, self::ENTREPRISE_NOM);
        [$voisine, $inviteVoisin] = $this->cabinet(self::VOISIN_EMAIL, self::ENTREPRISE_VOISINE);

        $client = (new Client())->setNom(self::CLIENT_NOM);
        $client->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($client);

        $clientVoisin = (new Client())->setNom(self::CLIENT_VOISIN);
        $clientVoisin->setEntreprise($voisine)->setInvite($inviteVoisin);
        $em->persist($clientVoisin);

        $risque = (new Risque())->setNomComplet('Incendie et risques annexes')->setCode('INC')->setBranche(0)->setImposable(true);
        $risque->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($risque);

        $em->flush();

        return [$entreprise, $invite, $client, $clientVoisin, $risque];
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
            ->setLicence('LIC-CP')->setAdresse('1 rue du Test')->setTelephone('+243000000000')
            ->setRccm('RCCM-CP')->setIdnat('IDNAT-CP')->setNumimpot('IMP-CP');
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

    /** @return array<int, array> les actions déclarées sur le canevas d'un client */
    private function actionsDuClient(Entreprise $entreprise, Client $client): array
    {
        /** @var CanvasBuilder $builder */
        $builder = static::getContainer()->get(CanvasBuilder::class);

        return $builder->getEntityFormCanvas($client, $entreprise->getId())['parametres']['attribute_actions'] ?? [];
    }

    /**
     * L'ACTION EXISTE, ET ELLE EST RANGÉE DANS « Créer… ».
     *
     * Elle a d'abord vécu à plat, seule de son espèce : une famille coûte un clic de plus,
     * et c'était cher pour le geste qu'on vient précisément faire. « Créer un sinistre »
     * l'a rejointe, et à deux elles remplissaient la barre — quatre entrées, soit le
     * plafond exact au-delà duquel le surplus tombe dans « Autres actions ».
     *
     * La famille leur rend cette place, et elle vaut CONVENTION : toute action de création
     * future rejoint « Créer… » plutôt que d'ouvrir une famille de plus. Rien n'est perdu
     * quand il n'en reste qu'une de visible — actions-groupees.js remet alors la famille à
     * plat tout seul.
     */
    public function testLActionEstDeclareeEtResteVisibleDEmblee(): void
    {
        [$entreprise, , $client] = $this->seed();

        $actions = $this->actionsDuClient($entreprise, $client);
        $creerPiste = null;
        foreach ($actions as $action) {
            if (($action['event'] ?? null) === 'ui:client.creer-piste') {
                $creerPiste = $action;
            }
        }

        self::assertNotNull($creerPiste, 'La rubrique Clients doit proposer « Créer une piste ».');
        self::assertSame('Créer une piste', $creerPiste['label']);
        self::assertSame('piste', $creerPiste['icon'], 'L\'alias vient d\'IconCanvasProvider, jamais un nom en dur.');
        self::assertSame('/admin/client/api/%id%/piste-context', $creerPiste['url']);
        self::assertSame('Créer…', $creerPiste['groupe'] ?? null,
            'Toute action de création se range dans la même famille : c\'est la convention.',
        );
        self::assertSame('action:add', $creerPiste['groupe_icone'] ?? null,
            'L\'icône de la famille est celle de l\'ajout — le même signe pour le même geste '
            . 'partout. Alias d\'IconCanvasProvider, jamais un nom en dur.',
        );
        self::assertArrayNotHasKey('condition', $creerPiste,
            'On peut toujours ouvrir une affaire à un client, quel que soit son état.',
        );
    }

    /**
     * ⚠ LA BARRE D'OUTILS N'AFFICHE QUE QUATRE ENTRÉES EN LIGNE.
     *
     * Le client en portait huit à plat — sept visibles à la fois pour un client rattaché
     * à un portefeuille et doté d'un lien SOA. Une neuvième ajoutée en queue serait
     * tombée dans « Autres actions », c'est-à-dire hors de la barre demandée.
     *
     * Le regroupement en familles ramène l'affichage à trois entrées. Ce test ne compte
     * pas des boutons : il vérifie que le PLAFOND ne peut plus être franchi en silence.
     *
     * ⚠ « Créer une piste » et « Créer un sinistre » ne comptent QUE POUR UNE : si une
     * création future sortait de la famille « Créer… », ce test le dirait.
     */
    public function testLesActionsSeRangentSousLePlafondDeLaBarre(): void
    {
        [$entreprise, , $client] = $this->seed();

        $actions = $this->actionsDuClient($entreprise, $client);

        $entrees = [];
        foreach ($actions as $action) {
            // Une famille compte pour UNE entrée, quel que soit son nombre de membres —
            // c'est la règle d'actions-groupees.js.
            $entrees[$action['groupe'] ?? ('seule:' . $action['event'])] = true;
        }

        self::assertLessThanOrEqual(
            4,
            count($entrees),
            sprintf(
                'La barre d\'outils n\'affiche que quatre entrées en ligne ; au-delà, le '
                . 'surplus tombe dans « Autres actions ». Entrées actuelles : %s.',
                implode(', ', array_keys($entrees)),
            ),
        );
        self::assertArrayHasKey('Relevé de compte', $entrees);
        self::assertArrayHasKey('Portefeuille', $entrees);
        self::assertArrayHasKey('Créer…', $entrees,
            'Les deux créations se rangent ensemble, et ne pèsent donc qu\'une entrée.',
        );
    }

    /** La route rend le canevas de PISTE : celui du client n'ouvrirait pas le bon dialogue. */
    public function testLaRouteRendLeCanevasDeLaPiste(): void
    {
        [, , $client] = $this->seed();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request('GET', sprintf('/admin/client/api/%d/piste-context', $client->getId()));
        self::assertResponseIsSuccessful();

        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($client->getId(), $charge['clientId']);
        self::assertSame(
            '/admin/piste/api/get-form',
            $charge['formCanvas']['parametres']['endpoint_form_url'] ?? null,
            'Le dialogue à ouvrir est celui d\'une piste.',
        );
    }

    /**
     * UN CLIENT D'UN AUTRE CABINET EST INTROUVABLE — et son nom ne fuit pas.
     *
     * Rendre 403 « accès refusé » confirmerait son existence. On rend 404, et la réponse
     * ne doit porter aucune trace de son nom.
     */
    public function testUnClientDUnAutreCabinetEstIntrouvable(): void
    {
        [, , , $clientVoisin] = $this->seed();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request('GET', sprintf('/admin/client/api/%d/piste-context', $clientVoisin->getId()));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(
            self::CLIENT_VOISIN,
            (string) $this->client->getResponse()->getContent(),
            'Un refus ne doit pas livrer le nom du client d\'un autre cabinet.',
        );
    }

    /**
     * LE FORMULAIRE ARRIVE REMPLI — c'est la demande d'origine.
     *
     * Le nom est le seul champ que le client permet de composer : `Souscription — <nom>`,
     * dont le préfixe est le LIBELLÉ DU TYPE, pris à sa source unique. Le contrôleur
     * `piste-name-sync` le tiendra à jour si le courtier change de type, sans une ligne
     * de JavaScript de plus.
     */
    public function testLeFormulaireSOuvrePreremplíSurLeClient(): void
    {
        [, , $client] = $this->seed();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request('GET', sprintf('/admin/piste/api/get-form?idClient=%d', $client->getId()));
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        $nomAttendu = PisteType::TYPE_AVENANT_LABELS[Piste::AVENANT_SOUSCRIPTION] . ' — ' . self::CLIENT_NOM;

        self::assertStringContainsString($nomAttendu, $html,
            'Le nom doit être proposé : il est obligatoire, et le laisser vide ferait taper '
            . 'la seule chose que le dossier permettait de déduire.',
        );
        self::assertStringContainsString((string) $client->getId(), $html, 'Le client doit être posé.');
        self::assertStringContainsString((string) date('Y'), $html, 'L\'exercice est celui de l\'année.');
    }

    /**
     * LE CLIENT DEJA CONNU N'EST PAS REDEMANDE.
     *
     * Le geste a nomme le client — c'est un clic droit SUR lui — et l'entete du dialogue
     * le rappelle. Lui redemander lequel, dans une carte deployee qui affiche ses chiffres
     * et porte une croix d'effacement, c'est proposer de defaire ce qu'on vient de faire.
     *
     * Le champ reste RENDU (il porte la valeur qui sera enregistree, et `render_rest` le
     * recracherait en bas du formulaire s'il disparaissait) : c'est sa RANGEE qui est
     * masquee.
     */
    public function testLeClientDejaConnuNEstPasRedemande(): void
    {
        [, , $client] = $this->seed();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request('GET', sprintf('/admin/piste/api/get-form?idClient=%d', $client->getId()));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString(
            'data-field-code="client"',
            $html,
            'Le champ doit rester rendu : il porte le client qui sera enregistre.',
        );
        self::assertStringContainsString(
            'd-none',
            (string) $this->rangeeDuClient($html),
            'La rangee du client doit etre masquee quand le client est deja connu.',
        );

        // SANS client connu, la question se pose vraiment : la rangee reste visible.
        $this->client->request('GET', '/admin/piste/api/get-form');
        self::assertResponseIsSuccessful();
        $htmlSansClient = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(
            'd-none',
            (string) $this->rangeeDuClient($htmlSansClient),
            'Une piste creee sans client doit encore demander lequel.',
        );
    }

    /** La rangee qui porte le champ client, pour juger de son masquage. */
    private function rangeeDuClient(string $html): ?string
    {
        $pos = strpos($html, 'data-field-code="client"');
        if ($pos === false) {
            return null;
        }
        $amont = substr($html, max(0, $pos - 2000), min($pos, 2000));
        $debut = strrpos($amont, '<div class="row');

        return $debut === false ? null : substr($amont, $debut);
    }

    /**
     * NON-RÉGRESSION : le cross-selling du relevé de compte garde SON nom.
     *
     * Les deux surfaces partagent la même règle. Quand le risque est connu — c'est le cas
     * depuis le relevé de compte —, le nom reste « <risque> — <client> », au caractère
     * près. Le préfixe de type ne s'y substitue pas.
     */
    public function testLeCrossSellingGardeSonNomQuandLeRisqueEstConnu(): void
    {
        [, , $client, , $risque] = $this->seed();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request('GET', sprintf(
            '/admin/piste/api/get-form?idClient=%d&idRisque=%d',
            $client->getId(),
            $risque->getId(),
        ));
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($risque->getNomComplet() . ' — ' . self::CLIENT_NOM, $html);
        self::assertStringNotContainsString(
            PisteType::TYPE_AVENANT_LABELS[Piste::AVENANT_SOUSCRIPTION] . ' — ' . self::CLIENT_NOM,
            $html,
            'Le nom du cross-selling nomme le RISQUE, pas le type : c\'est ce qui le rend utile.',
        );
    }
}
