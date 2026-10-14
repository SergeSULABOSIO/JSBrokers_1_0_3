<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Utilisateur;
use App\Services\Search\PisteTransformationScope;
use App\Services\Search\PortefeuilleScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UNE FICHE QUI EXISTE SE VOIT — ou l'écran dit pourquoi elle ne se voit pas.
 *
 * Deux incidents du 2026-10-13, une même cause : un filtre de lecture (« Mon portefeuille »)
 * qui cache une fiche sans le dire.
 *  - Une piste créée pour un client qu'aucun de mes portefeuilles ne porte : la liste se
 *    rafraîchissait sous « Mon portefeuille », le toast disait « réussi », et la piste
 *    semblait perdue. Le serveur dit maintenant, à la demande (`verifier`), si la fiche
 *    enregistrée passe le filtre — et lequel l'écarte.
 *  - L'onglet Cotations d'une telle piste s'ouvrait VIDE : il appliquait les défauts de la
 *    rubrique à ce que la fiche contient. Un onglet contextuel montre tout son contenu.
 */
class VisibiliteDesFichesTest extends WebTestCase
{
    private const EMAIL = 'phpunit-visibilite-fiches@test.local';
    private const EMAIL_LECTEUR = 'phpunit-visibilite-fiches-lecteur@test.local';
    private const EMAIL_COLLEGUE = 'phpunit-visibilite-fiches-collegue@test.local';
    private const CABINET = 'PHPUnit Visibilite Fiches SARL';
    private const VOISIN = 'PHPUnit Visibilite Fiches Voisin SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
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
        $noms = [self::CABINET, self::VOISIN];
        $emails = [self::EMAIL, self::EMAIL_LECTEUR, self::EMAIL_COLLEGUE];
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:e)', ['e' => $emails], ['e' => ArrayParameterType::STRING]);
        foreach (['avenant', 'tranche', 'chargement_pour_prime', 'cotation', 'piste', 'client', 'portefeuille', 'monnaie', 'roles_en_marketing', 'roles_en_production', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:n)",
                ['n' => $noms],
                ['n' => ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (:n)', ['n' => $noms], ['n' => ArrayParameterType::STRING]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:e)', ['e' => $emails], ['e' => ArrayParameterType::STRING]);
    }

    /** @return array<string, int> */
    private function semer(): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Visibilite')->setVerified(true)
            ->setPassword('x')->setPaidTokens(1_000_000);
        $em->persist($owner);

        [$cabinet, $invite] = $this->cabinet(self::CABINET, $owner);
        [$voisin, $inviteVoisin] = $this->cabinet(self::VOISIN, $owner);
        $owner->setConnectedTo($cabinet);

        $monnaie = (new Monnaie())->setNom('Dollar')->setCode('USD')->setTauxusd('1')
            ->setFonction(Monnaie::FONCTION_SAISIE_ET_AFFICHAGE)->setLocale(false);
        $monnaie->setEntreprise($cabinet);
        $em->persist($monnaie);

        $portefeuille = (new Portefeuille())->setNom('Mon portefeuille test')->setGestionnaire($invite);
        $portefeuille->setEntreprise($cabinet);
        $em->persist($portefeuille);
        $duPortefeuille = (new Client())->setNom('Client du portefeuille')->setExonere(false);
        $duPortefeuille->setEntreprise($cabinet)->setPortefeuille($portefeuille);
        $em->persist($duPortefeuille);
        // Le cas de l'incident : un client qu'AUCUN portefeuille ne porte.
        $sansPortefeuille = (new Client())->setNom('Marlette sans portefeuille')->setExonere(false);
        $sansPortefeuille->setEntreprise($cabinet);
        $em->persist($sansPortefeuille);

        $mienne = $this->piste($cabinet, $invite, 'Piste de mon portefeuille', $duPortefeuille);
        // Montées par un COLLÈGUE : sans gestionnaire de portefeuille, une piste revient à son
        // créateur (ResponsableDePiste) — créées par l'invité, elles seraient dans SON
        // portefeuille, et ne seraient plus « hors portefeuille ».
        $utilisateurCollegue = (new Utilisateur())->setEmail(self::EMAIL_COLLEGUE)->setNom('Collègue')->setVerified(true)->setPassword('x');
        $em->persist($utilisateurCollegue);
        $collegue = (new Invite())->setNom('Collègue')->setUtilisateur($utilisateurCollegue)->setEntreprise($cabinet)->setProprietaire(false);
        $em->persist($collegue);
        $horsPortefeuille = $this->piste($cabinet, $collegue, 'Piste hors portefeuille', $sansPortefeuille);
        $avecOffres = $this->piste($cabinet, $collegue, 'Piste aux trois offres', $sansPortefeuille);
        $enAttente = $this->cotation($avecOffres, 'Offre en attente');
        $prime = (new ChargementPourPrime())->setNom('Prime nette')->setMontantFlatExceptionel(500.0);
        $prime->setCotation($enAttente)->setEntreprise($cabinet);
        $em->persist($prime);
        // Une souscription sans prime n'a pas de sens : cette offre ne se souscrit pas encore.
        $this->cotation($avecOffres, 'Offre sans prime');
        $souscrite = $this->cotation($avecOffres, 'Offre souscrite');
        $a = (new Avenant())->setReferencePolice('POL-VISIBILITE')->setNumero('0')->setDescription('Souscription')
            ->setStartingAt(new \DateTimeImmutable('-10 days'))->setEndingAt(new \DateTimeImmutable('+355 days'))
            ->setCotation($souscrite);
        $a->setEntreprise($cabinet);
        $em->persist($a);
        $etrangere = $this->piste($voisin, $inviteVoisin, 'Piste etrangere', null);
        $em->flush();

        $ids = [
            'entreprise' => (int) $cabinet->getId(),
            'invite' => (int) $invite->getId(),
            'mienne' => (int) $mienne->getId(),
            'hors' => (int) $horsPortefeuille->getId(),
            'avecOffres' => (int) $avecOffres->getId(),
            'etrangere' => (int) $etrangere->getId(),
        ];
        $this->client->loginUser($owner);
        $em->clear();

        return $ids;
    }

    private function cabinet(string $nom, Utilisateur $owner): array
    {
        $cabinet = (new Entreprise())->setNom($nom)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000031')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em()->persist($cabinet);
        $invite = (new Invite())->setNom('Proprio ' . $nom)->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $this->em()->persist($invite);

        return [$cabinet, $invite];
    }

    private function piste(Entreprise $cabinet, Invite $invite, string $nom, ?Client $client): Piste
    {
        $p = (new Piste())->setNom($nom)->setTypeAvenant(0)->setDescriptionDuRisque('Risque')
            ->setExercice(2026)->setCommissionPotentielle(100.0)->setPrimePotentielle(1000.0);
        $p->setEntreprise($cabinet)->setInvite($invite);
        if ($client !== null) {
            $p->setClient($client);
        }
        $this->em()->persist($p);

        return $p;
    }

    private function cotation(Piste $piste, string $nom): Cotation
    {
        $c = (new Cotation())->setNom($nom)->setDuree(12);
        $c->setPiste($piste)->setEntreprise($piste->getEntreprise());
        $this->em()->persist($c);

        return $c;
    }

    /** Le rafraîchissement de la liste principale, tel que le Cerveau l'envoie après un enregistrement. */
    private function rafraichir(array $ids, array $payload): array
    {
        $this->client->request(
            'POST',
            sprintf('/admin/piste/api/dynamic-query/%d/%d', $ids['invite'], $ids['entreprise']),
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload),
        );
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** Les critères par défaut de la rubrique : « En cours » et « Mon portefeuille ». */
    private function criteresParDefaut(array $ids): array
    {
        return [
            PisteTransformationScope::CRITERION_KEY => ['operator' => '=', 'value' => PisteTransformationScope::STATUT_EN_COURS],
            PortefeuilleScope::CRITERION_KEY => ['operator' => '=', 'value' => $ids['invite']],
        ];
    }

    public function testUneFicheEcarteeParLePortefeuilleEstSignaleeAvecSonFiltre(): void
    {
        $ids = $this->semer();

        $reponse = $this->rafraichir($ids, ['criteria' => $this->criteresParDefaut($ids), 'verifier' => $ids['hors'], 'page' => 1]);

        self::assertStringNotContainsString('Piste hors portefeuille', $reponse['html'], 'Le filtre l’écarte bien de la liste…');
        self::assertSame(
            ['id' => $ids['hors'], 'visible' => false, 'masqueePar' => [['cle' => PortefeuilleScope::CRITERION_KEY, 'libelle' => 'Mon portefeuille']]],
            $reponse['verification'],
            '… et la réponse le dit, en nommant le filtre qui l’écarte.',
        );
    }

    public function testUneFicheVisibleNeDeclencheAucunAvertissement(): void
    {
        $ids = $this->semer();

        $reponse = $this->rafraichir($ids, ['criteria' => $this->criteresParDefaut($ids), 'verifier' => $ids['mienne'], 'page' => 1]);

        self::assertSame(['id' => $ids['mienne'], 'visible' => true, 'masqueePar' => []], $reponse['verification']);
    }

    /** La sonde ne dit RIEN d'une fiche d'un autre cabinet — pas même qu'elle existe. */
    public function testLaSondeNeRenseignePasSurUnAutreCabinet(): void
    {
        $ids = $this->semer();

        $reponse = $this->rafraichir($ids, ['criteria' => [], 'verifier' => $ids['etrangere'], 'page' => 1]);

        self::assertNull($reponse['verification']['visible']);
        self::assertSame([], $reponse['verification']['masqueePar']);
    }

    public function testSansDemandeLaReponseNeChangePas(): void
    {
        $ids = $this->semer();

        $reponse = $this->rafraichir($ids, ['criteria' => $this->criteresParDefaut($ids), 'page' => 1]);

        self::assertArrayNotHasKey('verification', $reponse);
    }

    /**
     * LES BADGES DES ONGLETS : une requête pour tous les onglets d'une fiche, sans charger
     * aucune liste — et le compte est celui que l'onglet montrera.
     */
    public function testLesComptesDesOngletsContextuels(): void
    {
        $ids = $this->semer();

        $this->client->request('GET', sprintf('/admin/comptes-contextuels/Piste/%d', $ids['avecOffres']));
        self::assertResponseIsSuccessful();
        $comptes = json_decode((string) $this->client->getResponse()->getContent(), true)['comptes'];

        self::assertSame(3, $comptes['cotations'], 'Trois offres, souscrite comprise — comme l’onglet.');
        self::assertSame(0, $comptes['taches']);
        self::assertSame(0, $comptes['documents']);
    }

    /** La fiche d'un autre cabinet n'a pas de compte : 404, comme son onglet. */
    public function testPasDeCompteHorsDuCabinet(): void
    {
        $ids = $this->semer();

        $this->client->request('GET', sprintf('/admin/comptes-contextuels/Piste/%d', $ids['etrangere']));
        self::assertResponseStatusCodeSame(404);
    }

    /** Le nom de rubrique de l'URL n'ouvre que ce qui existe : ni classe inventée, ni autre méthode. */
    public function testUneRubriqueInconnueNOuvreRien(): void
    {
        $ids = $this->semer();

        foreach (['Inexistante', 'ComptesContextuels', 'Utilisateur'] as $entite) {
            $this->client->request('GET', sprintf('/admin/comptes-contextuels/%s/%d', $entite, $ids['avecOffres']));
            self::assertResponseStatusCodeSame(404, "« $entite » ne doit rien ouvrir.");
        }
    }

    /** Le contenu HTML de la collection Cotations d'une piste, telle que son dialogue la charge. */
    private function collectionDeDialogue(int $piste): \Symfony\Component\DomCrawler\Crawler
    {
        $this->client->request('GET', sprintf('/admin/piste/api/%d/cotations/dialog', $piste));
        self::assertResponseIsSuccessful();

        return new \Symfony\Component\DomCrawler\Crawler((string) json_decode((string) $this->client->getResponse()->getContent(), true)['html']);
    }

    /**
     * « SOUSCRIRE » SUR UNE LIGNE DE COTATION : la création de l'avenant rattaché à elle.
     * Sur la cotation en attente seulement — une cotation souscrite ne se souscrit pas.
     */
    public function testUneCotationEnAttenteSeSouscritDepuisSaLigne(): void
    {
        $ids = $this->semer();
        $crawler = $this->collectionDeDialogue($ids['avecOffres']);

        $ligne = static fn (string $nom) => $crawler->filter('tr[data-item-id]')->reduce(static fn ($tr) => str_contains($tr->text(), $nom));
        $bouton = $ligne('Offre en attente')->filter('[data-action="click->collection#actionDeLigne"]');
        self::assertCount(1, $bouton, 'La cotation en attente porte le geste « Souscrire ».');
        self::assertSame('Souscrire', $bouton->attr('title'));
        self::assertSame('/admin/avenant/api/get-form', $bouton->attr('data-geste-form'));
        self::assertSame('/admin/avenant/api/submit', $bouton->attr('data-geste-submit'));
        self::assertSame('cotation', $bouton->attr('data-geste-champ'));
        self::assertCount(0, $ligne('Offre souscrite')->filter('[data-action="click->collection#actionDeLigne"]'),
            'Une cotation déjà souscrite ne se souscrit pas.');
        self::assertCount(0, $ligne('Offre sans prime')->filter('[data-action="click->collection#actionDeLigne"]'),
            'Une souscription sans prime n’a pas de sens : pas de geste tant que la prime n’est pas chiffrée.');
    }

    /** Sans le droit d'écrire un avenant, aucun bouton : un geste que le serveur refuserait n'est pas proposé. */
    public function testSansDroitSurLesAvenantsPasDeSouscription(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $cabinet = $em->find(Entreprise::class, $ids['entreprise']);
        $lecteur = (new Utilisateur())->setEmail(self::EMAIL_LECTEUR)->setNom('Lecteur')->setVerified(true)->setPassword('x');
        $lecteur->setConnectedTo($cabinet);
        $em->persist($lecteur);
        $invite = (new Invite())->setNom('Lecteur')->setUtilisateur($lecteur)->setEntreprise($cabinet)->setProprietaire(false);
        $em->persist($invite);
        $marketing = (new \App\Entity\RolesEnMarketing())->setNom('Lecture des pistes')->setAccessPiste([Invite::ACCESS_LECTURE]);
        $marketing->setEntreprise($cabinet);
        $invite->addRolesEnMarketing($marketing);
        $em->persist($marketing);
        $production = (new \App\Entity\RolesEnProduction())->setNom('Lecture des cotations')->setAccessCotation([Invite::ACCESS_LECTURE]);
        $production->setEntreprise($cabinet);
        $invite->addRolesEnProduction($production);
        $em->persist($production);
        $em->flush();
        $this->client->loginUser($lecteur);

        $crawler = $this->collectionDeDialogue($ids['avecOffres']);
        self::assertGreaterThan(0, $crawler->filter('tr[data-item-id]')->count(), 'Le lecteur voit les cotations…');
        self::assertCount(0, $crawler->filter('[data-action="click->collection#actionDeLigne"]'), '… sans « Souscrire ».');
    }

    /**
     * L'onglet Cotations d'une piste hors de mes portefeuilles montre SES cotations — toutes,
     * souscrites comprises — dès le premier chargement, sans badge de filtre qui mentirait.
     */
    public function testLOngletContextuelMontreToutLeContenuDeLaFiche(): void
    {
        $ids = $this->semer();

        $this->client->request('GET', sprintf('/admin/piste/api/%d/cotations/generic', $ids['avecOffres']));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Offre en attente', $html, 'Le portefeuille ne cache plus le contenu de la fiche ouverte.');
        self::assertStringContainsString('Offre souscrite', $html, 'Ni le statut « En attente » par défaut de la rubrique.');
        self::assertMatchesRegularExpression(
            '/data-list-manager-initial-criteria-value=(["\'])(\{\}|\[\])\1/',
            html_entity_decode($html, ENT_QUOTES | ENT_HTML5),
            'Aucun critère annoncé : le premier rafraîchissement montrera la même liste.',
        );
    }
}
