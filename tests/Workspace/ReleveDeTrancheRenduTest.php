<?php

namespace App\Tests\Workspace;

use App\Entity\Article;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\PaiementPrime;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\RolesEnFinance;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * LES ONGLETS FINANCIERS DE LA FICHE TRANCHE, TELS QUE L'ÉCRAN LES REND.
 *
 * Le gestionnaire de compte voit, sur la fiche d'une échéance, sa prime, sa commission,
 * ses rétrocommissions et ses taxes — dû, exigible, payé, solde, et chaque mouvement —
 * SANS pouvoir toucher à ce qui relève de la comptabilité. Ce test tient :
 *  - les onglets (l'ancien « Paiements de prime » est fondu dans « Prime ») ;
 *  - le contrat de collection de l'endpoint (l'onglet se charge sans JS dédié) ;
 *  - la lecture seule, et les SEULS gestes permis : ceux des signalements de prime,
 *    selon les droits sur PaiementPrime ;
 *  - les gardes : famille et usage connus, droit de lecture, cabinet ouvert ;
 *  - le compte bancaire, montré à qui lit la pièce qui le porte ;
 *  - l'unité, celle de la monnaie d'affichage, sans conversion (comme les indicateurs) ;
 *  - et qu'enregistrer la tranche n'efface pas ses paiements de prime.
 */
class ReleveDeTrancheRenduTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-releve-rendu-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-releve-rendu-guest@test.local';
    private const AUTRE_EMAIL = 'phpunit-releve-rendu-autre@test.local';
    private const ENT = 'PHPUnit Releve Rendu SARL';
    private const ENT_AUTRE = 'PHPUnit Releve Rendu Autre SARL';

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
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL, self::AUTRE_EMAIL];
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:e)', ['e' => $emails], ['e' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        foreach ([self::ENT, self::ENT_AUTRE] as $nom) {
            foreach (['paiement_prime', 'paiement', 'article', 'note', 'compte_bancaire', 'avenant', 'tranche', 'revenu_pour_courtier',
                      'type_revenu', 'cotation', 'piste', 'client', 'monnaie', 'roles_en_finance', 'invite'] as $table) {
                $conn->executeStatement(
                    "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                    ['nom' => $nom],
                );
            }
            $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => $nom]);
        }
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:e)', ['e' => $emails], ['e' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        $this->em()->clear();
        static::getContainer()->get(IndicatorCalculationHelper::class)->reset();
    }

    /**
     * Le cabinet (monnaie LOCALE CDF ≠ monnaie d'AFFICHAGE USD), une affaire validée, une
     * tranche portant un signalement de prime et une commission réglée sur un compte.
     *
     * @param array<string, int[]> $droitsInvite accessTranche / accessNote
     *
     * @return array{tranche: int, paiementPrime: int}
     */
    private function semer(array $droitsInvite = []): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Patron')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);
        $proprietaire = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $proprietaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($proprietaire);

        $guestUser = (new Utilisateur())->setEmail(self::GUEST_EMAIL)->setNom('Gestionnaire')->setVerified(true)->setPassword('x');
        $guestUser->setConnectedTo($ent);
        $em->persist($guestUser);
        $guest = (new Invite())->setNom('Gestionnaire de compte')->setProprietaire(false);
        $guest->setUtilisateur($guestUser)->setEntreprise($ent);
        $roles = (new RolesEnFinance())->setNom('Finance')
            ->setAccessTranche($droitsInvite['tranche'] ?? [])
            ->setAccessNote($droitsInvite['note'] ?? []);
        $roles->setEntreprise($ent);
        $guest->addRolesEnFinance($roles);
        $em->persist($roles);
        $em->persist($guest);

        // DEUX MONNAIES : la saisie en francs, l'affichage en dollars. Les indicateurs ne
        // convertissent rien ; le relevé non plus — sinon son pied quitterait la fiche.
        foreach ([['Franc congolais', 'CDF', '2800', Monnaie::FONCTION_SAISIE_UNIQUEMENT, true], ['Dollar', 'USD', '1', Monnaie::FONCTION_AFFICHAGE_UNIQUEMENT, false]] as [$nom, $code, $taux, $fonction, $locale]) {
            $m = (new Monnaie())->setNom($nom)->setCode($code)->setTauxusd($taux)->setFonction($fonction)->setLocale($locale);
            $m->setEntreprise($ent);
            $em->persist($m);
        }

        $client = (new Client())->setNom('Client Rendu')->setExonere(false)->setEntreprise($ent);
        $em->persist($client);
        $piste = (new Piste())->setNom('Piste Rendu')->setTypeAvenant(0)->setDescriptionDuRisque('Risque')
            ->setExercice(2026)->setClient($client)->setEntreprise($ent)->setInvite($proprietaire);
        $em->persist($piste);
        $cotation = (new Cotation())->setNom('Cotation Rendu')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($ent);
        $em->persist($cotation);
        $type = (new TypeRevenu())->setNom('Commission')->setMontantflat(200.0)->setShared(false)
            ->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $type->setEntreprise($ent);
        $em->persist($type);
        $revenu = (new RevenuPourCourtier())->setNom('Revenu')->setTypeRevenu($type);
        $revenu->setEntreprise($ent);
        $cotation->addRevenu($revenu);
        $em->persist($revenu);
        $tranche = (new Tranche())->setNom('Tranche Rendu')->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('-30 days'))->setEcheanceAt(new \DateTimeImmutable('-5 days'));
        $tranche->setEntreprise($ent);
        $cotation->addTranche($tranche);
        $em->persist($tranche);
        $avenant = (new Avenant())->setReferencePolice('POL-RENDU')->setNumero('0')->setDescription('Avenant')
            ->setStartingAt(new \DateTimeImmutable('-60 days'))->setEndingAt(new \DateTimeImmutable('+305 days'));
        $avenant->setEntreprise($ent)->setInvite($proprietaire);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $signalement = (new PaiementPrime())->setPaidAt(new \DateTimeImmutable('-4 days'))->setMontant(150.0)->setReference('PP-RENDU');
        $signalement->setEntreprise($ent);
        $tranche->addPaiementsPrime($signalement);
        $em->persist($signalement);

        $compte = (new CompteBancaire())->setNom('Compte courant')->setIntitule('Joseara SARL')->setBanque('Rawbank')->setNumero('00012')->setCodeSwift('RAWBCDKI');
        $compte->setEntreprise($ent);
        $em->persist($compte);
        $note = (new Note())->setNom('ND-RENDU')->setReference('ND-RENDU')->setType(Note::TYPE_NOTE_DE_DEBIT)
            ->setAddressedTo(Note::TO_ASSUREUR)->setValidated(true)->setSignature('sig');
        $note->setEntreprise($ent)->setInvite($proprietaire);
        $em->persist($note);
        $article = (new Article())->setQuantite(1.0)->setRevenuFacture($revenu);
        $article->setEntreprise($ent);
        $tranche->addArticle($article);
        $note->addArticle($article);
        $em->persist($article);
        $paiement = (new Paiement())->setMontant(80.0)->setPaidAt(new \DateTimeImmutable('-2 days'))->setReference('VIR-RENDU');
        $paiement->setEntreprise($ent);
        $paiement->setCompteBancaire($compte);
        $note->addPaiement($paiement);
        $em->persist($paiement);

        $em->flush();
        $ids = ['tranche' => $tranche->getId(), 'paiementPrime' => $signalement->getId()];
        $em->clear();

        return $ids;
    }

    private function connecter(string $email): void
    {
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]));
    }

    /** @return array{json: array, crawler: Crawler} */
    private function releve(int $trancheId, string $famille): array
    {
        $this->client->request('GET', sprintf('/admin/tranche/api/%d/releve/%s/dialog', $trancheId, $famille));
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);

        return ['json' => $json, 'crawler' => new Crawler($json['html'])];
    }

    /** Le chiffre de synthèse nommé, tel qu'affiché. */
    private function chiffre(Crawler $crawler, string $libelle): string
    {
        foreach ($crawler->filter('.jsb-releve-chiffres .jsb-chiffre') as $noeud) {
            $c = new Crawler($noeud);
            if (trim($c->filter('dt')->text()) === $libelle) {
                return trim($c->filter('dd')->text());
            }
        }
        self::fail("Chiffre « $libelle » introuvable.");
    }

    // ───────────────────────────── La fiche ─────────────────────────────

    public function testLaFicheOffreQuatreOngletsFinanciersEnLectureSeule(): void
    {
        $ids = $this->semer();
        $this->connecter(self::OWNER_EMAIL);

        $crawler = $this->client->request('GET', '/admin/tranche/api/get-form/' . $ids['tranche']);
        self::assertResponseIsSuccessful();

        $onglets = $crawler->filter('[role="tab"].jsb-onglet')->each(static fn (Crawler $c) => $c->attr('data-tab-id'));
        self::assertSame(['principal', 'relevePrime', 'releveCommission', 'releveRetro', 'releveTaxe', 'documents'], $onglets);
        self::assertSame(0, $crawler->filter('[data-tab-id="paiementsPrime"]')->count(), '« Paiements de prime » est fondu dans « Prime ».');

        foreach (['relevePrime', 'releveCommission', 'releveRetro', 'releveTaxe'] as $champ) {
            $widget = $crawler->filter(sprintf('[data-field-code="%s"] [data-controller="collection"]', $champ));
            self::assertSame(1, $widget->count(), "Le relevé « $champ » est un widget de collection.");
            self::assertStringContainsString('/releve/', (string) $widget->attr('data-collection-list-url-value'));
            self::assertStringContainsString('d-none', (string) $crawler->filter(sprintf('[data-field-code="%s"] [data-collection-target="addButtonContainer"]', $champ))->attr('class'), 'Lecture seule : pas d\'« Ajouter ».');
        }
    }

    // ───────────────────────────── L'endpoint ─────────────────────────────

    /**
     * Le contrat des collections ({html, itemCount}), un pied égal au « payé » de la
     * synthèse, l'unité d'AFFICHAGE (USD) sans conversion, et aucun geste.
     */
    public function testLeReleveTientLeContratEtSonPiedEgaleLaSynthese(): void
    {
        $ids = $this->semer();
        $this->connecter(self::OWNER_EMAIL);

        ['json' => $json, 'crawler' => $crawler] = $this->releve($ids['tranche'], 'commission');

        self::assertArrayHasKey('itemCount', $json);
        self::assertSame(1, $json['itemCount'], 'La pastille compte les lignes de mouvement.');
        $pied = trim($crawler->filter('table > tfoot td.td-numeric')->text());
        self::assertSame($this->chiffre($crawler, 'Encaissée'), $pied, 'Le pied égale le « payé » de la synthèse.');
        self::assertStringStartsWith('USD', $pied, 'L\'unité est la monnaie d\'AFFICHAGE.');
        self::assertStringContainsString('80,00', $pied, 'Aucune conversion : 80 saisis, 80 affichés (comme les indicateurs).');
        self::assertStringNotContainsString('collection#addItem', $json['html']);
        self::assertSame(0, $crawler->filter('[data-action*="collection#"]')->count(), 'La commission ne se modifie pas d\'ici.');
    }

    public function testUneFamilleOuUnUsageInconnusRepondent404(): void
    {
        $ids = $this->semer();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request('GET', sprintf('/admin/tranche/api/%d/releve/inconnue/dialog', $ids['tranche']));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', sprintf('/admin/tranche/api/%d/releve/prime/generic', $ids['tranche']));
        self::assertResponseStatusCodeSame(404);
    }

    public function testSansLectureDeLaTrancheLeReleveEstRefuse(): void
    {
        $ids = $this->semer();
        $this->connecter(self::GUEST_EMAIL);

        $this->client->request('GET', sprintf('/admin/tranche/api/%d/releve/prime/dialog', $ids['tranche']));
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnAutreCabinetNeLitPasLeReleve(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $autre = (new Utilisateur())->setEmail(self::AUTRE_EMAIL)->setNom('Autre')->setVerified(true)->setPassword('x');
        $em->persist($autre);
        $entAutre = (new Entreprise())->setNom(self::ENT_AUTRE)->setLicence('L2')->setAdresse('2 rue')
            ->setTelephone('+2430001')->setRccm('R2')->setIdnat('I2')->setNumimpot('N2')->setUtilisateur($autre);
        $em->persist($entAutre);
        $autre->setConnectedTo($entAutre);
        $invite = (new Invite())->setNom('Autre patron')->setProprietaire(true);
        $invite->setUtilisateur($autre)->setEntreprise($entAutre);
        $em->persist($invite);
        $em->flush();
        $this->connecter(self::AUTRE_EMAIL);

        $this->client->request('GET', sprintf('/admin/tranche/api/%d/releve/commission/dialog', $ids['tranche']));
        self::assertResponseStatusCodeSame(404);
    }

    // ───────────────────────────── Droits ─────────────────────────────

    /**
     * Les gestes d'un signalement suivent les droits sur PaiementPrime : avec la
     * Modification, le crayon ; sans la Suppression, pas de corbeille.
     */
    public function testLesGestesDUnSignalementSuiventLesDroitsSurPaiementPrime(): void
    {
        $ids = $this->semer(['tranche' => [Invite::ACCESS_LECTURE, Invite::ACCESS_MODIFICATION]]);
        $this->connecter(self::GUEST_EMAIL);

        ['crawler' => $crawler] = $this->releve($ids['tranche'], 'prime');
        $ligne = $crawler->filter(sprintf('tr[data-item-id="%d"]', $ids['paiementPrime']));
        self::assertSame(1, $ligne->count(), 'Le signalement porte son id : il se corrige depuis sa ligne.');
        self::assertSame(1, $ligne->filter('[data-action="click->collection#editItem"]')->count());
        self::assertSame(0, $ligne->filter('[data-action="click->collection#deleteItem"]')->count());
    }

    /** En simple lecture, le signalement se CONSULTE. */
    public function testEnLectureLeSignalementSeConsulte(): void
    {
        $ids = $this->semer(['tranche' => [Invite::ACCESS_LECTURE]]);
        $this->connecter(self::GUEST_EMAIL);

        ['crawler' => $crawler] = $this->releve($ids['tranche'], 'prime');
        self::assertSame(1, $crawler->filter('[data-action="click->collection#consulterItem"]')->count());
        self::assertSame(0, $crawler->filter('[data-action="click->collection#editItem"], [data-action="click->collection#deleteItem"]')->count());
    }

    /** Le compte bancaire d'une note n'est montré qu'à qui lit les notes. */
    public function testLeCompteBancaireSuitLeDroitDeLectureDeLaPiece(): void
    {
        $ids = $this->semer(['tranche' => [Invite::ACCESS_LECTURE]]);

        $this->connecter(self::GUEST_EMAIL);
        ['json' => $sansDroit] = $this->releve($ids['tranche'], 'commission');
        self::assertStringNotContainsString('Rawbank', $sansDroit['html']);
        self::assertStringContainsString('VIR-RENDU', $sansDroit['html'], 'Le mouvement, lui, reste visible.');

        $this->connecter(self::OWNER_EMAIL);
        ['json' => $avecDroit] = $this->releve($ids['tranche'], 'commission');
        self::assertStringContainsString('Rawbank', $avecDroit['html']);
    }

    // ───────────────────────────── Enregistrement ─────────────────────────────

    /**
     * L'onglet « Paiements de prime » a disparu du formulaire, mais pas le champ du
     * FormType (Ket et la reprise s'en servent). Enregistrer la tranche ne doit pas
     * soumettre une collection vide qui, par orphanRemoval, effacerait les paiements.
     */
    public function testEnregistrerLaTrancheNEffacePasSesPaiementsDePrime(): void
    {
        $ids = $this->semer();
        $this->connecter(self::OWNER_EMAIL);
        $tranche = $this->em()->getRepository(Tranche::class)->find($ids['tranche']);

        $this->client->request('POST', '/admin/tranche/api/submit', [
            'id'          => $ids['tranche'],
            'nom'         => 'Tranche renommée',
            'modeCalcul'  => 'pourcentage',
            'pourcentage' => '100',
            'payableAt'   => $tranche->getPayableAt()->format('Y-m-d\TH:i'),
            'echeanceAt'  => $tranche->getEcheanceAt()->format('Y-m-d\TH:i'),
            'idEntreprise' => $tranche->getEntreprise()->getId(),
            // `paiementsPrime` ABSENT, comme le poste le navigateur : son onglet n'existe
            // plus. DEUX protections tiennent alors la collection — le champ est
            // `mapped: false`, et `submit(…, false)` ne vide pas un champ absent. Retirer
            // les deux ferait effacer les paiements par orphanRemoval : ce test le verrait.
        ]);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        self::assertSame('Tranche renommée', $this->em()->getRepository(Tranche::class)->find($ids['tranche'])->getNom());
        self::assertNotNull($this->em()->getRepository(PaiementPrime::class)->find($ids['paiementPrime']), 'Le paiement de prime survit à l\'enregistrement.');
    }
}
