<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Entity\Piste;
use App\Entity\RolesEnMarketing;
use App\Entity\Utilisateur;
use App\Services\Search\PisteTransformationScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;

/**
 * LA VUE CARTES DES PISTES : le pipe actif, compté sur tout le filtre, avec la prochaine action.
 *
 * Elle réutilise la chaîne de la liste ; ce test en prouve les ajouts :
 *  - la vue rendue vient du cookie, dès le HTML (défaut et valeur inconnue : cartes) ;
 *  - un statut autre que « En cours » demandé en Cartes BASCULE en Liste, sans être écrasé ;
 *  - les en-têtes comptent tout le filtre, le corps montre la page ; les clôturées sortent ;
 *  - chaque carte porte l'action de son étape, et seulement si le droit le permet ;
 *  - l'action est décidée par le serveur sur des données fraîches (409 si devenue impossible,
 *    ouverture normale si la carte a seulement vieilli, 404 pour un autre cabinet).
 */
class PisteVueCartesTest extends WebTestCase
{
    private const EMAIL = 'phpunit-vue-cartes@test.local';
    private const EMAIL_LECTEUR = 'phpunit-vue-cartes-lecteur@test.local';
    private const CABINET = 'PHPUnit Vue Cartes SARL';
    private const VOISIN = 'PHPUnit Vue Cartes Voisin SARL';

    private KernelBrowser $client;

    /** Le client du portefeuille, que portent les pistes du cabinet. */
    private ?\App\Entity\Client $clientDuPortefeuille = null;

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
        $emails = [self::EMAIL, self::EMAIL_LECTEUR];
        $noms = [self::CABINET, self::VOISIN];
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:e)', ['e' => $emails], ['e' => ArrayParameterType::STRING]);
        foreach (['avenant', 'chargement_pour_prime', 'cotation', 'piste', 'client', 'portefeuille', 'monnaie', 'roles_en_marketing', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:n)",
                ['n' => $noms],
                ['n' => ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (:n)', ['n' => $noms], ['n' => ArrayParameterType::STRING]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:e)', ['e' => $emails], ['e' => ArrayParameterType::STRING]);
    }

    /**
     * Le cabinet : une piste par étape (commission 100 / 200 / 300 / 400), une clôturée, une
     * piste à DEUX cotations chiffrées, et — dans un cabinet voisin — une piste étrangère.
     *
     * @return array<string, int>
     */
    private function semer(int $enCotationEnPlus = 0): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Vue Cartes')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        [$cabinet, $invite] = $this->cabinet(self::CABINET, $owner);
        [$voisin, $inviteVoisin] = $this->cabinet(self::VOISIN, $owner);
        $owner->setConnectedTo($cabinet);

        $monnaie = (new Monnaie())->setNom('Euro')->setCode('EUR')->setTauxusd('1')
            ->setFonction(Monnaie::FONCTION_SAISIE_ET_AFFICHAGE)->setLocale(false);
        $monnaie->setEntreprise($cabinet);
        $em->persist($monnaie);

        // Le PÉRIMÈTRE « Mon portefeuille » s'applique par défaut : les pistes relèvent d'un
        // client du portefeuille que l'invité gère, comme dans un vrai cabinet.
        $portefeuille = (new \App\Entity\Portefeuille())->setNom('Portefeuille vue cartes')->setGestionnaire($invite);
        $portefeuille->setEntreprise($cabinet);
        $em->persist($portefeuille);
        $this->clientDuPortefeuille = (new \App\Entity\Client())->setNom('Client vue cartes')->setExonere(false);
        $this->clientDuPortefeuille->setEntreprise($cabinet)->setPortefeuille($portefeuille);
        $em->persist($this->clientDuPortefeuille);

        $ids = [];
        $this->piste($cabinet, $invite, 'Piste a coter', 100.0);
        $p = $this->piste($cabinet, $invite, 'Piste en cotation', 200.0);
        $this->cotation($p, 'Cotation sans prime');
        $p = $this->piste($cabinet, $invite, 'Piste chiffree', 300.0);
        $this->cotation($p, 'Cotation chiffree', 500.0);
        $p = $this->piste($cabinet, $invite, 'Piste transformee', 400.0);
        $this->cotation($p, 'Cotation souscrite', 800.0, true);
        $p = $this->piste($cabinet, $invite, 'Piste deux offres', 50.0);
        $this->cotation($p, 'Offre A', 100.0);
        $this->cotation($p, 'Offre B', 200.0);
        $this->piste($cabinet, $invite, 'Piste abandonnee', 999.0)->setClosed(true);
        for ($i = 1; $i <= $enCotationEnPlus; ++$i) {
            $this->cotation($this->piste($cabinet, $invite, "Piste en cotation $i", 1.0), "Cotation $i");
        }
        $etrangere = $this->piste($voisin, $inviteVoisin, 'Piste etrangere', 10.0);
        $em->flush();

        foreach ($em->getRepository(Piste::class)->findBy(['entreprise' => $cabinet]) as $piste) {
            $ids[$piste->getNom()] = (int) $piste->getId();
        }
        $ids['etrangere'] = (int) $etrangere->getId();
        $ids['entreprise'] = (int) $cabinet->getId();
        $ids['invite'] = (int) $invite->getId();
        $this->client->loginUser($owner);
        $em->clear();

        return $ids;
    }

    private function cabinet(string $nom, Utilisateur $owner): array
    {
        $cabinet = (new Entreprise())->setNom($nom)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000022')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em()->persist($cabinet);
        $invite = (new Invite())->setNom('Proprio ' . $nom)->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $this->em()->persist($invite);

        return [$cabinet, $invite];
    }

    private function piste(Entreprise $cabinet, Invite $invite, string $nom, float $commission): Piste
    {
        $p = (new Piste())->setNom($nom)->setTypeAvenant(0)->setDescriptionDuRisque('Risque')
            ->setExercice(2026)->setCommissionPotentielle($commission)->setPrimePotentielle($commission * 10);
        $p->setEntreprise($cabinet)->setInvite($invite);
        if ($this->clientDuPortefeuille !== null && $this->clientDuPortefeuille->getEntreprise() === $cabinet) {
            $p->setClient($this->clientDuPortefeuille);
        }
        $this->em()->persist($p);

        return $p;
    }

    private function cotation(Piste $piste, string $nom, ?float $prime = null, bool $souscrite = false): Cotation
    {
        $c = (new Cotation())->setNom($nom)->setDuree(12);
        $c->setPiste($piste)->setEntreprise($piste->getEntreprise());
        $this->em()->persist($c);
        if ($prime !== null) {
            $ch = (new ChargementPourPrime())->setNom("Prime $nom")->setMontantFlatExceptionel($prime);
            $ch->setCotation($c)->setEntreprise($piste->getEntreprise());
            $this->em()->persist($ch);
        }
        if ($souscrite) {
            $a = (new Avenant())->setReferencePolice("POL-$nom")->setNumero('0')->setDescription($nom)
                ->setStartingAt(new \DateTimeImmutable('-10 days'))->setEndingAt(new \DateTimeImmutable('+355 days'))
                ->setCotation($c);
            $a->setEntreprise($piste->getEntreprise());
            $this->em()->persist($a);
        }

        return $c;
    }

    private function vue(string $valeur, int $entreprise): void
    {
        $nom = sprintf('listeVue_%d_Piste', $entreprise);
        $pot = $this->client->getCookieJar();
        // Celui qu'une réponse a déposé (domaine `localhost`) et celui posé ici : deux
        // cookies du même nom partiraient sinon ensemble, et PHP n'en garderait qu'un.
        $pot->expire($nom, '/', 'localhost');
        $pot->expire($nom, '/');
        $pot->set(new Cookie($nom, $valeur));
    }

    private function rubrique(array $ids): Crawler
    {
        $crawler = $this->client->request('GET', sprintf('/espacedetravail/api/load-component/%d/%d', $ids['invite'], $ids['entreprise']), [
            'component' => '_view_manager_marketing.html.twig',
            'entity' => 'Piste',
        ]);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** Le contenu de la source des cartes (un <template> : son DOM n'est pas celui de la page). */
    private function source(string $html): Crawler
    {
        self::assertMatchesRegularExpression('/<template data-vue-cartes-source[^>]*>/', $html);
        preg_match('/<template data-vue-cartes-source[^>]*>(.*?)<\/template>/s', $html, $m);

        return new Crawler('<div>' . ($m[1] ?? '') . '</div>');
    }

    private function socle(Crawler $crawler): Crawler
    {
        return $crawler->filter('#principal[data-controller~="list-manager"]');
    }

    public function testSansCookieLaRubriqueSOuvreSurLePipeActif(): void
    {
        $ids = $this->semer();
        $crawler = $this->rubrique($ids);
        $html = (string) $this->client->getResponse()->getContent();

        $socle = $this->socle($crawler);
        self::assertStringContainsString('is-vue-cartes', (string) $socle->attr('class'));
        self::assertStringContainsString('vue-cartes', (string) $socle->attr('data-controller'));
        self::assertCount(4, $crawler->filter('.jsb-pipe [data-colonne]'), 'Trois colonnes actives et la colonne terminale.');
        self::assertCount(2, $crawler->filter('[data-vue-cartes-target="bascule"]'));

        $source = $this->source($html);
        $noms = $source->filter('[data-carte] .jsb-carte__titre')->each(static fn (Crawler $c) => $c->text());
        sort($noms);
        self::assertSame(['Piste a coter', 'Piste chiffree', 'Piste deux offres', 'Piste en cotation'], $noms,
            'Le pipe actif : ni la transformée, ni la clôturée.');

        $compteurs = [];
        $source->filter('data[data-etape]')->each(function (Crawler $d) use (&$compteurs) {
            $compteurs[$d->attr('data-etape')] = [(int) $d->attr('value'), (string) $d->attr('data-somme')];
        });
        self::assertSame(1, $compteurs['a_coter'][0], 'La clôturée ne compte pas dans « À coter ».');
        self::assertSame(1, $compteurs['en_cotation'][0]);
        self::assertSame(2, $compteurs['offres_chiffrees'][0]);
        self::assertSame(1, $compteurs['transformees'][0], 'La colonne terminale compte les transformées du filtre.');
        self::assertSame('1', $source->filter('data[data-cloturees]')->attr('value'));

        // MONNAIE : chaque somme est libellée par la monnaie d'affichage du cabinet.
        foreach ($compteurs as $etape => [, $somme]) {
            self::assertStringStartsWith('EUR ', $somme, "La somme de « $etape » doit être en monnaie d'affichage.");
        }
    }

    /** La Σ de l'en-tête est la somme des commissions potentielles des cartes, quand tout tient sur la page. */
    public function testLaSommeDeLEnTeteEstCelleDesCartes(): void
    {
        $ids = $this->semer();
        $this->rubrique($ids);
        $source = $this->source((string) $this->client->getResponse()->getContent());

        $nombre = static fn (string $texte): float => (float) str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], preg_replace('/[^0-9,\s\x{202F}\x{00A0}]/u', '', $texte));
        foreach (['a_coter', 'en_cotation', 'offres_chiffrees'] as $etape) {
            $cartes = 0.0;
            $source->filter(sprintf('[data-carte][data-etape="%s"] .jsb-carte__montant', $etape))
                ->each(function (Crawler $m) use (&$cartes, $nombre) { $cartes += $nombre($m->text()); });
            $entete = $nombre((string) $source->filter(sprintf('data[data-etape="%s"]', $etape))->attr('data-somme'));
            self::assertEqualsWithDelta($entete, $cartes, 0.001, "Σ de l'en-tête ≠ somme des cartes pour « $etape ».");
        }
    }

    /**
     * LA COLONNE « TRANSFORMÉES » DIT CE QUI A ÉTÉ GAGNÉ : la commission réellement souscrite
     * (celle que calcule le moteur des indicateurs pour les polices), pas l'estimation saisie
     * sur la piste — 400 ici, que la colonne ne doit pas reprendre.
     */
    public function testLaColonneTransformeesAfficheLaCommissionSouscrite(): void
    {
        $ids = $this->semer();
        $this->rubrique($ids);
        $source = $this->source((string) $this->client->getResponse()->getContent());

        $em = $this->em();
        $attendu = static::getContainer()->get(\App\Services\Canvas\CalculationProvider::class)->getIndicateursGlobaux(
            $em->find(Entreprise::class, $ids['entreprise']), true, ['pistesCibles' => [$ids['Piste transformee']]],
        )['commission_totale'];

        $somme = (string) $source->filter('data[data-etape="transformees"]')->attr('data-somme');
        self::assertStringStartsWith('EUR ', $somme);
        $nombre = (float) str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], substr($somme, 4));
        self::assertEqualsWithDelta($attendu, $nombre, 0.001, 'La colonne reprend la commission souscrite calculée par le moteur.');
        self::assertNotEqualsWithDelta(400.0, $nombre, 0.001, 'Et non la commission potentielle de la piste.');
    }

    /**
     * L'INFOBULLE (celle du tableau de bord) : chaque en-tête de colonne dit QUELLES pistes
     * l'étape réunit ; chaque carte, la fiche en bref. Une seule infobulle par carte : les
     * `title` natifs, qui se superposaient à elle, ont disparu.
     */
    public function testLesInfobullesDuPipe(): void
    {
        $ids = $this->semer();
        $crawler = $this->rubrique($ids);
        $html = (string) $this->client->getResponse()->getContent();

        self::assertCount(1, $crawler->filter('.jsb-pipe[data-controller~="infobulle"]'));
        foreach (PisteTransformationScope::ETAPES as $code => $etape) {
            $entete = json_decode((string) $crawler->filter(sprintf('[data-colonne="%s"] .jsb-pipe__entete', $code))->attr('data-infobulle'), true);
            self::assertSame($etape['description'], $entete[0]['texte'], "La description de « $code » vient de la source unique des étapes.");
            self::assertSame('Calcul', $entete[1]['titre']);
        }

        $carte = $this->source($html)->filter(sprintf('[data-carte][data-id="%d"]', $ids['Piste chiffree']));
        $bulle = json_decode((string) $carte->attr('data-infobulle'), true);
        self::assertSame(['Piste', 'Potentiel', 'Gestion'], array_column($bulle, 'titre'));
        self::assertSame(['Nom', 'Piste chiffree'], $bulle[0]['lignes'][0]);
        self::assertSame(['Prime', 'EUR 3 000,00'], str_replace("\u{202F}", ' ', $bulle[1]['lignes'][0]));
        self::assertCount(0, $carte->filter('[title]'), 'Aucune infobulle native dans la carte.');
    }

    public function testLeCookieChoisitLaVueDesLeHtml(): void
    {
        $ids = $this->semer();

        $this->vue('liste', $ids['entreprise']);
        $crawler = $this->rubrique($ids);
        self::assertStringContainsString('is-vue-liste', (string) $this->socle($crawler)->attr('class'));
        // La Liste montre aussi les pistes clôturées, avec leur marqueur.
        self::assertStringContainsString('Clôturée', $crawler->filter('tr[data-item-id="' . $ids['Piste abandonnee'] . '"]')->text());

        $this->vue('nimporte-quoi', $ids['entreprise']);
        $crawler = $this->rubrique($ids);
        self::assertStringContainsString('is-vue-cartes', (string) $this->socle($crawler)->attr('class'), 'Une valeur inconnue retombe sur les cartes.');
    }

    /** Un statut demandé n'est jamais écrasé : la réponse bascule en Liste, et le cookie suit. */
    public function testUnAutreStatutDemandeEnCartesBasculeEnListe(): void
    {
        $ids = $this->semer();
        $this->vue('cartes', $ids['entreprise']);

        foreach ([
            'transformees' => [PisteTransformationScope::CRITERION_KEY => ['operator' => '=', 'value' => 'transformees']],
            'toutes' => [],
        ] as $cas => $criteres) {
            // Chaque cas PART des cartes : la réponse du précédent a posé « liste ».
            $this->vue('cartes', $ids['entreprise']);
            $this->client->request('POST', sprintf('/admin/piste/api/dynamic-query/%d/%d', $ids['invite'], $ids['entreprise']),
                [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['criteria' => $criteres, 'page' => 1]));
            self::assertResponseIsSuccessful();
            $html = json_decode((string) $this->client->getResponse()->getContent(), true)['html'];

            self::assertStringContainsString('data-vue-effective="liste"', $html, "Cas « $cas » : la réponse doit être rendue en Liste.");
            self::assertStringContainsString('data-item-id="' . $ids['Piste transformee'] . '"', $html, "Cas « $cas » : le statut demandé est appliqué.");
            $cookie = $this->client->getResponse()->headers->getCookies()[0] ?? null;
            self::assertNotNull($cookie, "Cas « $cas » : le cookie doit suivre la bascule.");
            self::assertSame('liste', $cookie->getValue());
        }
    }

    public function testLesEnTetesComptentToutLeFiltreEtLeCorpsLaPage(): void
    {
        $ids = $this->semer(24); // 25 pistes « en cotation »
        $this->vue('cartes', $ids['entreprise']);
        $this->client->request('POST', sprintf('/admin/piste/api/dynamic-query/%d/%d', $ids['invite'], $ids['entreprise']),
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['criteria' => [PisteTransformationScope::CRITERION_KEY => ['operator' => '=', 'value' => 'en_cours']], 'page' => 1]));
        self::assertResponseIsSuccessful();
        $source = $this->source(json_decode((string) $this->client->getResponse()->getContent(), true)['html']);

        self::assertSame('25', $source->filter('data[data-etape="en_cotation"]')->attr('value'));
        self::assertCount(20, $source->filter('[data-carte]'), 'Le corps montre la page — vingt cartes.');
    }

    public function testChaqueCarteParteLActionDeSonEtape(): void
    {
        $ids = $this->semer();
        $this->rubrique($ids);
        $source = $this->source((string) $this->client->getResponse()->getContent());

        $action = static fn (int $id) => $source->filter(sprintf('[data-carte][data-id="%d"] .jsb-carte__action', $id));
        self::assertSame('ui:piste.coter-request', $action($ids['Piste a coter'])->attr('data-evenement'));
        self::assertStringContainsString('Ajouter une cotation', $action($ids['Piste a coter'])->text());
        self::assertSame('ui:piste.saisir-prime-request', $action($ids['Piste en cotation'])->attr('data-evenement'));
        self::assertSame('ui:piste.souscrire-request', $action($ids['Piste chiffree'])->attr('data-evenement'));
        self::assertStringContainsString('Souscrire', $action($ids['Piste chiffree'])->text());
    }

    public function testSansDroitDEcritureAucuneActionNEstProposee(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $lecteur = (new Utilisateur())->setEmail(self::EMAIL_LECTEUR)->setNom('Lecteur')->setVerified(true)->setPassword('x');
        $em->persist($lecteur);
        $cabinet = $em->find(Entreprise::class, $ids['entreprise']);
        $lecteur->setConnectedTo($cabinet);
        $invite = (new Invite())->setNom('Lecteur des pistes')->setUtilisateur($lecteur)->setEntreprise($cabinet)->setProprietaire(false);
        $em->persist($invite);
        $roles = (new RolesEnMarketing())->setNom('Lecture des pistes')->setAccessPiste([Invite::ACCESS_LECTURE]);
        $roles->setEntreprise($cabinet);
        $invite->addRolesEnMarketing($roles);
        $em->persist($roles);
        $em->flush();
        $this->client->loginUser($lecteur);

        // Le lecteur ne gère aucun portefeuille : on interroge la liste sur « En cours » seul.
        $this->client->request('POST', sprintf('/admin/piste/api/dynamic-query/%d/%d', $invite->getId(), $ids['entreprise']),
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['criteria' => [PisteTransformationScope::CRITERION_KEY => ['operator' => '=', 'value' => 'en_cours']], 'page' => 1]));
        self::assertResponseIsSuccessful();
        $source = $this->source(json_decode((string) $this->client->getResponse()->getContent(), true)['html']);

        self::assertGreaterThan(0, $source->filter('[data-carte]')->count(), 'Le lecteur voit le pipe…');
        self::assertCount(0, $source->filter('.jsb-carte__action'), '… sans action qu\'il n\'aurait pas le droit de mener.');

        $this->client->request('GET', sprintf('/admin/piste/api/action-pipe/%d/coter', $ids['Piste a coter']));
        self::assertResponseStatusCodeSame(409, 'Le serveur revérifie le droit au clic.');
    }

    public function testLeServeurDecideDeLaFicheAOuvrir(): void
    {
        $ids = $this->semer();
        $action = function (string $piste, string $action): array {
            $this->client->request('GET', sprintf('/admin/piste/api/action-pipe/%d/%s', $piste === 'etrangere' ? $this->ids['etrangere'] : $this->ids[$piste], $action));

            return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true) ?? []];
        };
        $this->ids = $ids;

        [$code, $r] = $action('Piste a coter', 'coter');
        self::assertSame(200, $code);
        self::assertTrue($r['isCreationMode']);
        self::assertSame(['id' => $ids['Piste a coter'], 'fieldName' => 'piste'], $r['parentContext']);
        self::assertArrayHasKey('endpoint_submit_url', $r['formCanvas']['parametres']);

        [$code, $r] = $action('Piste en cotation', 'saisir-prime');
        self::assertSame(200, $code);
        self::assertFalse($r['isCreationMode']);
        self::assertSame('chargements', $r['ongletInitial'], 'La prime se saisit dans l\'onglet Chargements de la COTATION.');

        [$code, $r] = $action('Piste chiffree', 'souscrire');
        self::assertSame(200, $code);
        self::assertTrue($r['isCreationMode'], 'Souscrire CRÉE un avenant…');
        self::assertSame('cotation', $r['parentContext']['fieldName'], '… sous la cotation chiffrée.');

        [$code, $r] = $action('Piste deux offres', 'souscrire');
        self::assertSame(200, $code);
        self::assertSame('cotations', $r['ongletInitial'], 'Deux offres chiffrées : la piste, onglet Cotations, pour choisir.');

        // CARTE PÉRIMÉE, ACTION TOUJOURS VALABLE : « Saisir la prime » sur une piste déjà chiffrée.
        [$code, $r] = $action('Piste chiffree', 'saisir-prime');
        self::assertSame(200, $code);
        self::assertSame('chargements', $r['ongletInitial']);

        // ACTION DEVENUE IMPOSSIBLE : 409, avec la raison.
        [$code, $r] = $action('Piste abandonnee', 'coter');
        self::assertSame(409, $code);
        self::assertStringContainsString('clôturée', $r['message']);
        [$code, $r] = $action('Piste transformee', 'souscrire');
        self::assertSame(409, $code);
        self::assertStringContainsString('déjà souscrite', $r['message']);

        // UN AUTRE CABINET : 404.
        [$code] = $action('etrangere', 'coter');
        self::assertSame(404, $code);
    }

    private array $ids = [];

    public function testLEtapeDUnePiste(): void
    {
        $ids = $this->semer();
        $this->client->request('GET', sprintf('/admin/piste/api/etape/%d', $ids['Piste chiffree']));
        self::assertResponseIsSuccessful();
        self::assertSame(['etape' => 'offres_chiffrees', 'libelle' => 'Offre chiffrée'], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->client->request('GET', sprintf('/admin/piste/api/etape/%d', $ids['etrangere']));
        self::assertResponseStatusCodeSame(404);
    }

    public function testUneAutreRubriqueNAPasDeVueCartes(): void
    {
        $ids = $this->semer();
        $this->client->request('GET', sprintf('/espacedetravail/api/load-component/%d/%d', $ids['invite'], $ids['entreprise']), [
            'component' => '_view_manager_marketing.html.twig', 'entity' => 'Tache',
        ]);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('vue-cartes', $html);
        self::assertStringNotContainsString('jsb-pipe', $html);
    }
}
