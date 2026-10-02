<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Entity\Paiement;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Risque;
use App\Entity\Tache;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * LA RANGÉE D'UNE COLLECTION SE SCANNE.
 *
 * Un titre entre guillemets, un montant noyé en gras dans la ligne de métadonnées, un total
 * détaché qui flotte dans l'entête sans jamais désigner ce qu'il additionne : rien n'était
 * faux, rien ne se lisait d'un coup d'œil. La rangée porte désormais une colonne de valeur
 * alignée, le total se pose au pied, et l'état se lit en pastille.
 *
 * ── CE QUE CE TEST TIENT, ET POURQUOI ───────────────────────────────────────────────
 * Le gabarit de ligne est PARTAGÉ avec les listes principales : chaque assertion de
 * collection a donc son pendant en liste principale, qui doit rester inchangée. C'est la
 * moitié du test qui coûte le plus et qui protège le plus.
 */
class CollectionRangeeDialogueTest extends WebTestCase
{
    private const EMAIL = 'phpunit-rangee@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Rangee SARL';

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
        foreach (['paiement', 'compte_bancaire', 'tache', 'cotation', 'piste', 'client', 'portefeuille', 'risque', 'monnaie', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :m', ['m' => self::EMAIL]);
    }

    private function connecter(): void
    {
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL]));
    }

    /** Le HTML d'une collection, tel que le navigateur le reçoit. */
    private function collection(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($json);
        self::assertArrayHasKey('html', $json);

        return [new Crawler($json['html']), $json];
    }

    /**
     * LE TOTAL SE LIT SOUS LA COLONNE QU'IL SOMME — et il dit le MÊME nombre que le JSON.
     *
     * Deux additions pour un seul nombre finiraient par donner deux résultats : le pied
     * reçoit celui que l'appelant avait déjà calculé, il n'en refait aucun.
     */
    public function testLeTotalSeLitAuPiedEtDitLeMemeNombreQueLeJson(): void
    {
        $ids = $this->semer();
        $this->connecter();

        [$c, $json] = $this->collection('/admin/piste/api/' . $ids['pisteId'] . '/cotations/dialog');

        self::assertCount(1, $c->filter('table > tfoot'), 'La liste totalisable doit porter un pied, et un seul.');
        self::assertCount(1, $c->filter('table > tfoot th[scope="row"]'), 'Le libellé du pied est un entête de rangée.');

        $pied = trim($c->filter('table > tfoot .td-numeric')->text());
        self::assertStringContainsString(
            $this->nombreRendu((float) $json['totalValue']),
            $pied,
            'Le pied et la réponse JSON doivent dire le même nombre : une seule addition.',
        );
    }

    /** TROIS COLONNES, TOUJOURS — c'est ce qui aligne les onglets entre eux. */
    public function testUneCollectionATroisColonnesEtSonColgroup(): void
    {
        $ids = $this->semer();
        $this->connecter();

        foreach (['cotations', 'taches'] as $collection) {
            [$c] = $this->collection('/admin/piste/api/' . $ids['pisteId'] . '/' . $collection . '/dialog');

            self::assertCount(3, $c->filter('table > thead th'), sprintf('« %s » doit avoir 3 entêtes.', $collection));
            self::assertCount(3, $c->filter('table > tbody > tr:first-child > td'), sprintf('« %s » doit avoir 3 cellules par rangée.', $collection));

            // Les largeurs sont portées par le colgroup, jamais par un `style=` en ligne.
            self::assertCount(1, $c->filter('table > colgroup > col.jsb-col-valeur'));
            self::assertCount(1, $c->filter('table > colgroup > col.jsb-col-actions'));
            self::assertStringNotContainsString('style="width', $c->filter('table > thead')->html());
        }
    }

    /** Le contrat dont dépendent le contrôleur de collection et deux tests existants. */
    public function testLeContratDeLaRangeeEstPreserve(): void
    {
        $ids = $this->semer();
        $this->connecter();

        [$c] = $this->collection('/admin/piste/api/' . $ids['pisteId'] . '/cotations/dialog');

        $ligne = $c->filter('tbody > tr')->first();
        foreach (['data-item-id', 'data-id', 'data-label', 'data-list-row-idobjet-value'] as $attribut) {
            self::assertNotNull($ligne->attr($attribut), sprintf('Le <tr> doit garder %s.', $attribut));
        }
        self::assertStringStartsWith('liste_row_', (string) $ligne->attr('id'));
        self::assertCount(1, $ligne->filter('.list-row-primary'));
        self::assertCount(1, $ligne->filter('[data-collection-target="rowActions"]'));
        self::assertCount(1, $c->filter('[data-list-manager-target="listContainer"]'));
        self::assertCount(1, $c->filter('[data-list-manager-target="emptyStateContainer"]'));
    }

    /**
     * LES GUILLEMETS NE SURVIVENT QU'EN LISTE PRINCIPALE.
     *
     * Dans une collection, la rangée tient dans une boîte déjà nommée par son onglet : les
     * guillemets n'y désambiguïsent plus rien. Ailleurs, c'est la grammaire de l'écran, et
     * on ne la change pas : la seconde moitié de ce test est celle qui compte.
     */
    public function testLesGuillemetsDisparaissentEnCollectionSeulement(): void
    {
        $ids = $this->semer();
        $this->connecter();

        [$dialogue] = $this->collection('/admin/piste/api/' . $ids['pisteId'] . '/cotations/dialog');
        $titreDialogue = trim($dialogue->filter('.list-row-primary')->first()->text());
        self::assertStringNotContainsString('"', $titreDialogue, 'Pas de guillemets dans une collection.');

        $this->client->request('GET', '/admin/piste/api/' . $ids['pisteId'] . '/cotations/generic');
        self::assertResponseIsSuccessful();
        $liste = new Crawler((string) $this->client->getResponse()->getContent());
        $titreListe = trim($liste->filter('.list-row-primary')->first()->text());
        self::assertStringContainsString('"', $titreListe, 'La liste principale garde ses guillemets.');
    }

    /** L'unité reste un élément DISTINCT : c'est lui qui la dé-emphase et aligne les décimales. */
    public function testLaValeurPorteSonUniteDansUnElementDistinct(): void
    {
        $ids = $this->semer();
        $this->connecter();

        [$c] = $this->collection('/admin/piste/api/' . $ids['pisteId'] . '/cotations/dialog');

        $cellule = $c->filter('tbody > tr:first-child > td.td-numeric')->first();
        self::assertCount(1, $cellule->filter('.td-numeric-unit'), 'L\'unité vit dans son propre élément.');
        self::assertSame(
            'USD',
            trim($cellule->filter('.td-numeric-unit')->text()),
            "L'unite doit etre RENDUE : une chaine vide ferait passer ce test sans rien prouver.",
        );
    }

    /**
     * L'UNITÉ DU PIED EST CELLE DES LIGNES.
     *
     * Elle ne peut pas varier d'une ligne à l'autre : elle vient de la devise d'affichage de
     * l'espace de travail, résolue une fois par canevas. Ce test fige cet invariant — c'est
     * lui qui rend la somme licite.
     */
    public function testLUniteDuPiedEstCelleDesLignes(): void
    {
        $ids = $this->semer();
        $this->connecter();

        [$c] = $this->collection('/admin/piste/api/' . $ids['pisteId'] . '/cotations/dialog');

        $unitePied = trim($c->filter('tfoot .td-numeric-unit')->text());
        self::assertNotSame('', $unitePied, 'Une unité vide comparerait deux fois rien.');
        $unites = $c->filter('tbody .td-numeric-unit')->each(static fn (Crawler $n): string => trim($n->text()));
        self::assertNotEmpty($unites);
        foreach ($unites as $unite) {
            self::assertSame($unitePied, $unite, 'Le pied et ses termes doivent porter la même unité.');
        }
    }

    /** UN ÉTAT SE LIT EN PASTILLE — en collection seulement, et jamais via `.badge`. */
    public function testLEtatDevientUnePastilleEnCollectionSeulement(): void
    {
        $ids = $this->semer();
        $this->connecter();

        [$dialogue] = $this->collection('/admin/piste/api/' . $ids['pisteId'] . '/taches/dialog');
        self::assertGreaterThan(
            0,
            $dialogue->filter('.list-row-secondary .jsb-state-badge')->count(),
            'Le statut d\'une tâche doit se lire en pastille dans une collection.',
        );
        self::assertStringNotContainsString('class="badge', $dialogue->html(), '`.badge` est forcée en bleu plein : jamais ici.');

        $this->client->request('GET', '/admin/piste/api/' . $ids['pisteId'] . '/taches/generic');
        self::assertResponseIsSuccessful();
        $liste = new Crawler((string) $this->client->getResponse()->getContent());
        self::assertCount(
            0,
            $liste->filter('.list-row-secondary .jsb-state-badge'),
            'La liste principale ne change pas : pas de pastille.',
        );
    }

    /**
     * UN MONTANT NÉGATIF GARDE SON SIGNE — et une collection non totalisable n'a pas de pied.
     *
     * Les paiements d'un compte bancaire n'ont pas de champ totalisable : la colonne de
     * valeur retombe sur la première colonne chiffrée du canevas, et aucun total n'est
     * annoncé — on ne somme que ce que le serveur a explicitement demandé de sommer.
     */
    public function testUnMontantNegatifGardeSonSigneEtNAmenePasDePied(): void
    {
        $ids = $this->semer();
        $this->connecter();

        [$c] = $this->collection('/admin/comptebancaire/api/' . $ids['compteId'] . '/paiements/dialog');

        $valeur = trim($c->filter('tbody > tr:first-child > td.td-numeric')->text());
        self::assertStringContainsString('-', $valeur, 'Une ristourne reste négative à l\'écran.');
        self::assertCount(0, $c->filter('tfoot'), 'Sans champ totalisable, aucun total n\'est annoncé.');
        self::assertCount(3, $c->filter('table > thead th'), 'Trois colonnes, même sans rien à totaliser.');
    }

    /** Le nombre tel que le projet le formate — on n'écrit pas un format en dur dans un test. */
    private function nombreRendu(float $valeur): string
    {
        return static::getContainer()->get(\App\Services\ServiceNombres::class)->format($valeur, 2);
    }

    /** @return array{pisteId:int, compteId:int} */
    private function semer(): array
    {
        $em = $this->em();

        $user = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Rangee')->setVerified(true);
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

        $portefeuille = (new Portefeuille())->setNom('Portefeuille Rangee');
        $portefeuille->setGestionnaire($proprietaire);
        $portefeuille->setEntreprise($entreprise);
        $em->persist($portefeuille);

        // LA DEVISE D'AFFICHAGE DU CABINET. Sans elle, getCodeMonnaieAffichage() rend
        // null, toutes les unites sont vides, et une assertion du genre « le pied porte la
        // meme unite que ses lignes » passe en comparant deux chaines vides. AUCUN test du
        // projet n'en semait : c'est ce qui rendait ce trou invisible.
        $monnaie = (new Monnaie())->setNom('Dollar americain')->setCode('USD')
            ->setTauxusd('1')->setFonction(Monnaie::FONCTION_SAISIE_ET_AFFICHAGE)->setLocale(false);
        $monnaie->setEntreprise($entreprise);
        $em->persist($monnaie);

        $risque = (new Risque())->setCode('RNG')->setNomComplet('Risque de rangee')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client de rangee')->setExonere(false);
        $client->setEntreprise($entreprise);
        $portefeuille->addClient($client);
        $em->persist($client);

        $piste = (new Piste())->setNom('Affaire de rangee')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($proprietaire);
        $em->persist($piste);

        foreach ([1, 2] as $i) {
            $cotation = (new Cotation())->setNom('Cotation de rangee ' . $i)->setDuree(365);
            $cotation->setPiste($piste)->setEntreprise($entreprise);
            $em->persist($cotation);
        }

        $tache = (new Tache())->setDescription('Relancer le client sur la proposition')
            ->setToBeEndedAt(new \DateTimeImmutable('+30 days'))->setClosed(false);
        $tache->setPiste($piste)->setEntreprise($entreprise);
        $em->persist($tache);

        $compte = (new CompteBancaire())->setIntitule('Compte de rangee')->setNumero('00123')
            ->setBanque('Banque de rangee')->setCodeSwift('SWIFT');
        $compte->setEntreprise($entreprise);
        $em->persist($compte);

        $paiement = (new Paiement())->setDescription('Ristourne sur prime')->setMontant(-1234.56)
            ->setReference('RIST-1')->setPaidAt(new \DateTimeImmutable('-2 days'));
        $paiement->setCompteBancaire($compte);
        $paiement->setEntreprise($entreprise);
        $em->persist($paiement);

        $em->flush();
        $ids = ['pisteId' => (int) $piste->getId(), 'compteId' => (int) $compte->getId()];
        $em->clear();

        return $ids;
    }

    /**
     * PAS DE TOTAL QUAND LE PARENT N'EST PAS ENCORE ECRIT.
     *
     * Le serveur ne connait alors que les elements RATTACHES, jamais ceux qui attendent
     * dans le tampon du navigateur : un total calcule la-dessus serait faux, et un total
     * qui ment est pire qu'un total absent. Les trois colonnes, elles, restent — sans quoi
     * les lignes injectees par le tampon se desaligneraient du tableau.
     */
    public function testUnParentNonEcritNAucunPiedMaisGardeSesTroisColonnes(): void
    {
        $this->semer();
        $this->connecter();

        [$c] = $this->collection('/admin/piste/api/0/cotations/dialog');

        self::assertCount(0, $c->filter('tfoot'), 'Aucun total ne peut etre vrai avant l\'enregistrement.');
        self::assertCount(3, $c->filter('table > thead th'), 'Trois colonnes quand meme : le tampon y injecte ses lignes.');
    }
}
