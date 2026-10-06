<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Chargement;
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
        foreach (['paiement', 'compte_bancaire', 'tache', 'chargement_pour_prime', 'chargement', 'cotation', 'piste', 'client', 'portefeuille', 'risque', 'monnaie', 'invite'] as $table) {
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

    /** @return array{pisteId:int, compteId:int, entrepriseId:int, inviteId:int, chargementId:int} */
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

        // LE CATALOGUE DES CHARGEMENTS : « Prime nette » est le type que porte la ligne
        // dont le dry-run doit savoir dire la valeur.
        $chargement = (new Chargement())->setNom('Prime nette')->setFonction(Chargement::FONCTION_PRIME_NETTE);
        $chargement->setEntreprise($entreprise);
        $em->persist($chargement);

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
        $ids = [
            'pisteId' => (int) $piste->getId(),
            'compteId' => (int) $compte->getId(),
            'entrepriseId' => (int) $entreprise->getId(),
            'inviteId' => (int) $proprietaire->getId(),
            'chargementId' => (int) $chargement->getId(),
        ];
        $em->clear();

        return $ids;
    }

    /**
     * UN PARENT NON ECRIT AFFICHE LE TOTAL DE CE QUI ATTEND.
     *
     * Le pied disparaissait en creation : le serveur ne connaissait que les elements
     * RATTACHES, jamais ceux qui patientaient dans le tampon du navigateur, et un total qui
     * ment est pire qu'un total absent. On n'a pas leve la regle, on a leve sa cause — le
     * navigateur rend ces valeurs-la, et l'addition reste faite ICI, une seule fois.
     *
     * Les trois colonnes restent, sans quoi les lignes injectees par le tampon se
     * desaligneraient du tableau.
     */
    public function testUnParentNonEcritAfficheLeTotalDeCeQuiAttend(): void
    {
        $ids = $this->semer();
        $this->connecter();

        [$c, $json] = $this->collection('/admin/piste/api/0/cotations/dialog?en_attente=1000,100,160,20');

        self::assertCount(1, $c->filter('table > tfoot'), 'Le total vaut avant l\'enregistrement comme apres.');
        self::assertCount(3, $c->filter('table > thead th'), 'Trois colonnes quand meme : le tampon y injecte ses lignes.');

        self::assertSame(1280.0, (float) $json['totalValue'], 'Le serveur additionne ce que le tampon lui a rendu.');
        self::assertStringContainsString(
            $this->nombreRendu(1280.0),
            trim($c->filter('table > tfoot .td-numeric')->text()),
            'Le pied dit le meme nombre que la reponse JSON : une seule addition.',
        );

        // L'UNITE EST CELLE DE L'EDITION. Un pied de creation qui dirait une autre devise
        // que le meme pied en edition serait un second affichage, pas le meme.
        [$edition] = $this->collection('/admin/piste/api/' . $ids['pisteId'] . '/cotations/dialog');
        self::assertSame(
            trim($edition->filter('tfoot .td-numeric-unit')->text()),
            trim($c->filter('tfoot .td-numeric-unit')->text()),
            'Creation et edition portent la meme unite.',
        );
    }

    /**
     * RIEN A TOTALISER, AUCUN PIED — « Total 0,00 » ferait lire une somme nulle la ou il
     * n'y a pas encore de termes. C'est le controleur qui tranche (`totalValue` a `null`),
     * la vue ne compte plus de lignes pour le deviner.
     */
    public function testUneCollectionVideNAnnonceAucunTotal(): void
    {
        $this->semer();
        $this->connecter();

        [$c, $json] = $this->collection('/admin/piste/api/0/cotations/dialog');

        self::assertNull($json['totalValue'], 'Pas de termes, pas de total.');
        // SOUS LE TABLEAU, et non n'importe ou : les deux gabarits de squelette portent eux
        // aussi un <tfoot>, et DOMDocument ne met pas le contenu d'un <template> a part
        // comme le fait un navigateur.
        self::assertCount(0, $c->filter('table > tfoot'), 'Et donc pas de pied.');
        self::assertCount(3, $c->filter('table > thead th'), 'Les trois colonnes, elles, tiennent l\'alignement.');
    }

    /**
     * LES GABARITS DE PIED SONT LA DES LA CREATION — et pas ailleurs.
     *
     * `FormatsEtStructureDeListeTest` lit la CONDITION dans le gabarit ; ce test-ci lit ce
     * que le serveur REND vraiment, dans les trois situations qui comptent :
     *
     *   1. parent non ecrit ET liste vide — le cas ou aucun pied n'existe encore, et
     *      precisement celui ou le premier ajout doit pouvoir montrer un squelette ;
     *   2. collection SANS champ totalisable — rien a masquer, donc aucun gabarit ;
     *   3. le squelette porte le MEME nombre de cellules que le vrai pied, sans quoi le
     *      tableau changerait de geometrie pendant l'attente.
     */
    public function testLesGabaritsDePiedExistentDesLaCreationEtPasAilleurs(): void
    {
        $ids = $this->semer();
        $this->connecter();

        // 1. Parent non ecrit, liste vide : pas de pied, mais les deux gabarits.
        [$creation] = $this->collection('/admin/piste/api/0/cotations/dialog');
        self::assertCount(0, $creation->filter('table > tfoot'), 'Rien a totaliser, donc pas de pied.');
        self::assertCount(1, $creation->filter('template[data-collection-target="squelettePied"]'));
        self::assertCount(1, $creation->filter('template[data-collection-target="piedIndisponible"]'));

        // 2. Collection sans champ totalisable : aucun gabarit.
        [$taches] = $this->collection('/admin/piste/api/' . $ids['pisteId'] . '/taches/dialog');
        self::assertCount(0, $taches->filter('template[data-collection-target]'), 'Rien a totaliser, aucun gabarit.');

        // 3. Le squelette a la geometrie du vrai pied.
        [$edition] = $this->collection('/admin/piste/api/' . $ids['pisteId'] . '/cotations/dialog');
        $vrai = $edition->filter('table > tfoot')->first();
        self::assertCount(1, $vrai, 'Il faut un vrai pied pour avoir quelque chose a comparer.');

        foreach (['squelettePied', 'piedIndisponible'] as $cible) {
            $gabarit = $edition->filter('template[data-collection-target="' . $cible . '"]');
            self::assertCount(1, $gabarit, $cible . ' doit etre rendu en edition aussi.');
            self::assertSame(
                $vrai->filter('th, td')->count(),
                $gabarit->filter('th, td')->count(),
                sprintf('« %s » doit avoir autant de cellules que le vrai pied.', $cible),
            );
        }
    }

    /**
     * `en_attente` NE GONFLE PAS LE TOTAL D'UNE FICHE ECRITE.
     *
     * Une fiche enregistree n'a pas de tampon : le parametre n'a aucun sens pour elle, et
     * l'honorer laisserait une URL forgee afficher n'importe quel total sur un dossier
     * reel. Meme garde que `ids`, au meme endroit : `$id === 0` seulement.
     */
    public function testEnAttenteNeGonflePasLeTotalDUneFicheEcrite(): void
    {
        $ids = $this->semer();
        $this->connecter();

        [, $reference] = $this->collection('/admin/piste/api/' . $ids['pisteId'] . '/cotations/dialog');
        [$c, $force] = $this->collection('/admin/piste/api/' . $ids['pisteId'] . '/cotations/dialog?en_attente=999999');

        self::assertSame($reference['totalValue'], $force['totalValue'], 'Le total d\'une fiche ecrite ne s\'ajoute pas.');
        self::assertStringNotContainsString(
            '999',
            trim($c->filter('table > tfoot .td-numeric')->text()),
            'Rien de ce qui vient de l\'URL n\'entre dans le pied d\'une fiche reelle.',
        );
    }

    /**
     * LE DRY-RUN REND LA VALEUR QUE SA LIGNE AFFICHE.
     *
     * C'est l'invariant de tout le mecanisme : le pied somme EXACTEMENT ce que la colonne
     * montre. Les deux sortent donc du meme `resoudreColonneValeur()` et de la meme lecture
     * — deux chemins pour un seul nombre finiraient par en donner deux.
     */
    public function testLeDryRunRendLaValeurQueSaLigneAffiche(): void
    {
        $ids = $this->semer();
        $this->connecter();

        // UN CHARGEMENT, et non une cotation vide : il faut un montant NON NUL pour que
        // l'egalite entre la valeur rendue et la cellule affichee prouve quelque chose.
        // C'est aussi le cas de la capture d'ecran — une prime nette de 1 000.
        $this->client->request('POST', '/admin/chargementpourprime/api/submit', [
            'idEntreprise' => $ids['entrepriseId'],
            'idInvite' => $ids['inviteId'],
            'nom' => 'Prime nette en attente',
            'montantFlatExceptionel' => 1000,
            'type' => $ids['chargementId'],
            'dry_run' => '1',
            // Ce que le navigateur transmet depuis `data-collection-totalizable-field-value`.
            'ligne_colonne_valeur' => 'montant_final',
        ]);

        self::assertResponseIsSuccessful();
        $reponse = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertTrue($reponse['valide'] ?? false, 'La saisie tient sans etre ecrite.');
        self::assertArrayHasKey('valeur', $reponse, 'La ligne en attente repart avec sa valeur.');
        self::assertIsNumeric($reponse['valeur']);

        // LA VALEUR CALCULEE EXISTE SUR UNE ENTITE NON PERSISTEE. Si elle tombait a zero,
        // tout le mecanisme serait vain : le pied additionnerait des riens.
        self::assertSame(1000.0, (float) $reponse['valeur'], 'Une entite detachee sait deja ce qu elle vaut.');

        $cellule = trim((new Crawler($reponse['ligne']))->filter('.td-numeric')->text());
        self::assertStringContainsString(
            $this->nombreRendu((float) $reponse['valeur']),
            $cellule,
            'La valeur rendue est celle que la cellule affiche.',
        );
    }

}
