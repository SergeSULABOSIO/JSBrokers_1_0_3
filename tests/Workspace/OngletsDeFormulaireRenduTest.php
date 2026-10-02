<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\DataCollector\DataCollectorInterface;

/**
 * LE DIALOGUE S'OUVRE VRAIMENT EN ONGLETS — rendu par le serveur, pas lu dans un gabarit.
 *
 * OngletsDeFormulaireTest lit les sources : il garde les décisions (le préfixe d'instance,
 * la paresse, l'accord du pluriel). Celui-ci DEMANDE LE FORMULAIRE au serveur et regarde ce
 * qui en sort — la seule façon de prouver que la partition fonctionne sur de vraies données,
 * que chaque onglet a son panneau, et qu'un compte annoncé est le compte réel.
 *
 * Deux entités le méritent : la Piste (4 collections, dont une conditionnelle et une
 * totalisée) et l'Invité (7 collections, le plus chargé du logiciel).
 */
class OngletsDeFormulaireRenduTest extends WebTestCase
{
    private const EMAIL = 'phpunit-onglets@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Onglets SARL';

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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :m', ['m' => self::EMAIL]);
        // Ordre imposé par les clés étrangères : l'enfant avant son parent.
        foreach (['cotation', 'piste', 'client', 'risque', 'condition_partage', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :m', ['m' => self::EMAIL]);
    }

    /** Les intitulés des onglets rendus, dans l'ordre de la barre. */
    private function ongletsDe(string $html): array
    {
        preg_match_all('/<span class="jsb-onglet-titre">([^<]*)<\/span>/', $html, $trouves);

        return array_map('trim', $trouves[1]);
    }

    /** Les `id` des panneaux rendus. */
    private function panneauxDe(string $html): array
    {
        preg_match_all('/class="jsb-onglets-panneau[^"]*"\s+id="([^"]+)"/', $html, $trouves);

        return $trouves[1];
    }

    private function connecter(): void
    {
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL]));
    }

    /**
     * UNE PISTE S'OUVRE SUR « PRINCIPAL » ET SES QUATRE COLLECTIONS.
     *
     * C'est le dialogue des captures d'origine : on y descendait trois écrans pour trouver
     * la liste des cotations, puis on cliquait un disque « + ».
     */
    public function testLeDialogueDUnePisteSOuvreEnOnglets(): void
    {
        $ids = $this->semerUnePiste();
        $this->connecter();

        $this->client->request('GET', '/admin/piste/api/get-form/' . $ids['pisteId']);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('class="jsb-onglets-barre"', $html);

        $onglets = $this->ongletsDe($html);
        self::assertSame('Principal', $onglets[0] ?? null, 'Les champs ordinaires doivent former le premier onglet.');
        foreach (['Cotations', 'Tâches', 'Documents', 'Conditions de partage'] as $attendu) {
            self::assertContains($attendu, $onglets, sprintf('L\'onglet « %s » manque à la barre.', $attendu));
        }

        // UN PANNEAU PAR ONGLET, ET CHAQUE ONGLET POINTE VERS LE SIEN. Un aria-controls
        // orphelin, c'est un onglet qui n'ouvre rien — et rien à l'écran pour le dire.
        $panneaux = $this->panneauxDe($html);
        self::assertCount(count($onglets), $panneaux);

        preg_match_all('/role="tab"[^>]*aria-controls="([^"]+)"|aria-controls="([^"]+)"[^>]*role="tab"/', $html, $cibles);
        $vises = array_values(array_filter(array_merge($cibles[1], $cibles[2])));
        self::assertNotEmpty($vises);
        foreach ($vises as $cible) {
            self::assertContains($cible, $panneaux, sprintf('L\'onglet vise « %s », qui n\'existe pas.', $cible));
        }
    }

    /**
     * LE COMPTE ANNONCÉ EST LE COMPTE RÉEL — et il est accordé.
     *
     * La pastille vient d'un COUNT serveur ; la liste, elle, ne se charge qu'à l'ouverture
     * de l'onglet. Si les deux divergeaient, l'utilisateur lirait un chiffre qu'aucune liste
     * ne confirme.
     */
    public function testLaPastilleAnnonceLeCompteReelEtLAccorde(): void
    {
        $ids = $this->semerUnePiste(2);
        $this->connecter();

        $this->client->request('GET', '/admin/piste/api/get-form/' . $ids['pisteId']);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertMatchesRegularExpression(
            '/<span class="jsb-onglet-compte">2<span class="visually-hidden"> éléments<\/span>/u',
            $html,
            'Deux cotations en base : la pastille doit annoncer 2, et accorder « éléments ».',
        );

        // Et le compte de l'endpoint de liste dit la même chose que la pastille.
        $this->client->request('GET', '/admin/piste/api/' . $ids['pisteId'] . '/cotations/dialog');
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(2, $json['itemCount'], 'La pastille et la liste doivent compter la même chose.');
    }

    /** Une seule cotation : « 1 élément », jamais « 1 éléments ». */
    public function testLeSingulierEstAccorde(): void
    {
        $ids = $this->semerUnePiste(1);
        $this->connecter();

        $this->client->request('GET', '/admin/piste/api/get-form/' . $ids['pisteId']);
        $html = (string) $this->client->getResponse()->getContent();

        self::assertMatchesRegularExpression(
            '/<span class="jsb-onglet-compte">1<span class="visually-hidden"> élément<\/span>/u',
            $html,
        );
    }

    /**
     * TOUS LES IDENTIFIANTS SONT UNIQUES DANS LE DOCUMENT.
     *
     * Deux dialogues peuvent être ouverts en même temps ; on vérifie ici le préalable, à
     * savoir qu'un seul rendu ne se contredit pas lui-même.
     */
    public function testLesIdentifiantsRendusSontUniques(): void
    {
        $ids = $this->semerUnePiste();
        $this->connecter();

        $this->client->request('GET', '/admin/piste/api/get-form/' . $ids['pisteId']);
        $html = (string) $this->client->getResponse()->getContent();

        preg_match_all('/\bid="(ong[0-9]+-[^"]+)"/', $html, $trouves);
        self::assertNotEmpty($trouves[1], 'Aucun identifiant préfixé : le préfixe d\'instance ne sert plus.');
        self::assertSame(
            array_values(array_unique($trouves[1])),
            $trouves[1],
            'Deux éléments portent le même identifiant dans un seul dialogue.',
        );
    }

    /**
     * L'INVITÉ, LE DIALOGUE LE PLUS CHARGÉ : ses cinq jeux de rôles deviennent cinq onglets
     * nommés par leur département — « Rôle en Finance » nomme un formulaire d'ajout, pas un
     * onglet.
     */
    public function testLeDialogueDUnInviteOuvreUnOngletParDepartement(): void
    {
        $ids = $this->semerUnePiste();
        $this->connecter();

        $this->client->request('GET', '/admin/invite/api/get-form/' . $ids['agentId']);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $onglets = $this->ongletsDe($html);
        foreach (['Finance', 'Marketing', 'Production', 'Sinistre', 'Administration'] as $departement) {
            self::assertContains($departement, $onglets, sprintf('L\'onglet « %s » manque.', $departement));
        }
        self::assertSame('Principal', $onglets[0] ?? null);
        self::assertCount(count($onglets), $this->panneauxDe($html));
    }

    /**
     * UN DIALOGUE SANS COLLECTION NE BOUGE PAS D'UN PIXEL. C'est le cas de la grande
     * majorité d'entre eux ; une barre à un seul onglet y serait un coût sans contrepartie.
     */
    public function testUnDialogueSansCollectionNeRendAucuneBarre(): void
    {
        $this->semerUnePiste();
        $this->connecter();

        $this->client->request('GET', '/admin/jourferie/api/get-form');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('jsb-onglets-barre', $html);
        self::assertStringNotContainsString('jsb-onglets-panneau', $html);
        // Le formulaire, lui, est bien là.
        self::assertStringContainsString('<form', $html);
    }

    /**
     * LE COMPTE D'UN ONGLET COMPTE — IL N'HYDRATE PAS.
     *
     * `$collection->count()` serait tombé dans le piège : aucune association du projet n'est
     * en `fetch: EXTRA_LAZY`, il aurait chargé toute la collection. On exige donc, pour une
     * collection que RIEN D'AUTRE ne lit (les tâches : ni total, ni indicateur), un COUNT et
     * rien qu'un COUNT.
     */
    public function testLeCompteDUnOngletNHydratePasSaCollection(): void
    {
        $ids = $this->semerUnePiste();
        $this->connecter();

        $this->client->enableProfiler();
        $this->client->request('GET', '/admin/piste/api/get-form/' . $ids['pisteId']);
        self::assertResponseIsSuccessful();

        $profil = $this->client->getProfile();
        self::assertNotFalse($profil, 'Le profiler ne collecte pas : ce test ne prouve plus rien.');
        /** @var DataCollectorInterface $collecteur */
        $collecteur = $profil->getCollector('db');

        $requetes = [];
        foreach ($collecteur->getQueries() as $parConnexion) {
            foreach ($parConnexion as $requete) {
                $requetes[] = (string) $requete['sql'];
            }
        }
        self::assertNotEmpty($requetes);

        // `FROM` ET `JOIN` : le comptage part du PARENT (`FROM piste … JOIN tache …`), seule
        // forme qui vaille pour une ManyToMany ou une OneToMany unidirectionnelle. Ne
        // chercher que `FROM tache` ne trouvait rien — et le test se croyait rassuré.
        $surLesTaches = array_values(array_filter(
            $requetes,
            static fn (string $sql): bool => (bool) preg_match('/\b(?:FROM|JOIN)\s+`?tache`?\b/i', $sql),
        ));
        self::assertNotEmpty($surLesTaches, 'Aucune requête sur les tâches : le compte de l\'onglet a disparu.');
        foreach ($surLesTaches as $sql) {
            self::assertMatchesRegularExpression(
                '/SELECT\s+count\(/i',
                $sql,
                sprintf('Les tâches sont HYDRATÉES pour un simple compte : %s', $sql),
            );
        }
    }

    /**
     * @return array{pisteId:int, agentId:int}
     */
    private function semerUnePiste(int $nbCotations = 1): array
    {
        $em = $this->em();

        $user = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Onglets')->setVerified(true);
        $user->setPassword('peu importe : on se connecte par loginUser()');
        $em->persist($user);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($user);
        $em->persist($entreprise);
        $user->setConnectedTo($entreprise);

        $proprietaire = (new Invite())->setNom('Proprio')->setProprietaire(true);
        $proprietaire->setUtilisateur($user)->setEntreprise($entreprise);
        $em->persist($proprietaire);

        $agent = (new Invite())->setNom('Alice')->setProprietaire(false);
        $agent->setEntreprise($entreprise);
        $em->persist($agent);

        $risque = (new Risque())->setCode('ONG')->setNomComplet('Risque des onglets')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client des onglets')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Affaire des onglets')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($proprietaire);
        $em->persist($piste);

        for ($i = 1; $i <= $nbCotations; $i++) {
            $cotation = (new Cotation())->setNom('Cotation ' . $i)->setDuree(365);
            $cotation->setPiste($piste)->setEntreprise($entreprise);
            $em->persist($cotation);
        }

        $em->flush();
        $ids = ['pisteId' => (int) $piste->getId(), 'agentId' => (int) $agent->getId()];
        $em->clear();

        return $ids;
    }
}
