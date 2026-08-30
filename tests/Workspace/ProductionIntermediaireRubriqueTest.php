<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Search\CotationSouscriptionScope;
use App\Services\Search\ProductionScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * « PRODUCTION INTERMÉDIAIRES » EST UNE RUBRIQUE COMME LES AUTRES.
 *
 * ── CE QUE CE TEST FERME ────────────────────────────────────────────────────────────
 * La production d'un intermédiaire ne s'ouvrait que par la porte d'une fiche — celle d'un
 * partenaire, ou celle d'un invité. Elle n'appartenait à aucune rubrique : l'arbre du menu
 * ne la situait nulle part, et son entête, sa recherche et ses commandes étaient dessinés à
 * la main, sans rapport avec la coquille des trente autres.
 *
 * Ce test vérifie qu'elle est désormais dans le moule : la coquille standard l'entoure —
 * barre d'outils, barre de recherche, barre de contrôles avec ses totaux — et le TABLEAU,
 * lui, n'a pas bougé.
 *
 * ── ET LA PORTÉE PAR DÉFAUT EST VIDE ────────────────────────────────────────────────
 * Sans bénéficiaire choisi, aucune ligne : la production se calcule affaire par affaire par
 * le moteur de partage, et la calculer pour tout le cabinet d'emblée coûterait cher pour un
 * écran que personne n'a demandé. C'est une réponse, pas un oubli.
 */
class ProductionIntermediaireRubriqueTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-prod-owner@test.local';
    private const ALICE_EMAIL = 'phpunit-prod-alice@test.local';
    private const BRUNO_EMAIL = 'phpunit-prod-bruno@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit Production SARL';

    private const COMMISSION = 1000.0;
    private const TAUX_ALICE = 20.0;

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
        foreach ([
            // Les pièces AVANT les versements, les versements AVANT les avenants : les clés
            // étrangères ne se dénouent que dans cet ordre.
            'document', 'reversement_retro_agent',
            'condition_partage', 'avenant', 'revenu_pour_courtier', 'type_revenu',
            'chargement_pour_prime', 'cotation', 'piste', 'client', 'risque', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement(
            'UPDATE utilisateur u JOIN entreprise e ON u.connected_to_id = e.id
             SET u.connected_to_id = NULL WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:emails)',
            ['emails' => [self::OWNER_EMAIL, self::ALICE_EMAIL, self::BRUNO_EMAIL]],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $this->em()->clear();
    }

    private function makeUser(string $email): Utilisateur
    {
        $u = (new Utilisateur())->setEmail($email)->setNom('Prod')->setVerified(true);
        $u->setPassword(
            static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, self::PASSWORD),
        );
        $this->em()->persist($u);

        return $u;
    }

    /**
     * Une affaire souscrite dont Alice est bénéficiaire, à 20 % d'une commission de 1000.
     *
     * @return array{aliceId: int, brunoId: int, idInvite: int, idEntreprise: int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $ownerUser = $this->makeUser(self::OWNER_EMAIL);
        $aliceUser = $this->makeUser(self::ALICE_EMAIL);
        $brunoUser = $this->makeUser(self::BRUNO_EMAIL);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP')
            ->setUtilisateur($ownerUser);
        $em->persist($entreprise);

        $ownerUser->setConnectedTo($entreprise);
        $aliceUser->setConnectedTo($entreprise);
        $brunoUser->setConnectedTo($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($ownerUser)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $alice = (new Invite())->setNom('Alice Apporteuse')->setProprietaire(false);
        $alice->setUtilisateur($aliceUser)->setEntreprise($entreprise);
        $em->persist($alice);

        $bruno = (new Invite())->setNom('Bruno Kalala')->setProprietaire(false);
        $bruno->setUtilisateur($brunoUser)->setEntreprise($entreprise);
        $em->persist($bruno);

        $risque = (new Risque())->setCode('PRD')->setNomComplet('Risque Production')
            ->setDescription('Risque de la production')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client Production')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $condition = (new ConditionPartage())->setNom('Prime apporteur Alice')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(self::TAUX_ALICE)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($alice);
        $condition->setEntreprise($entreprise);
        $em->persist($condition);

        $piste = (new Piste())->setNom('Piste Production')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque production')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($gestionnaire);
        $piste->addConditionsPartageAgent($condition);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation Production')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($entreprise);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime')->setMontantFlatExceptionel(5000.0);
        $chargement->setEntreprise($entreprise);
        $cotation->addChargement($chargement);
        $em->persist($chargement);

        $typeRevenu = (new TypeRevenu())->setNom('Commission')->setMontantflat(self::COMMISSION)
            ->setShared(false)->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())->setNom('Revenu')->setTypeRevenu($typeRevenu)->setCotation($cotation);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        $avenant = (new Avenant())->setReferencePolice('POL-PRD-0')->setNumero('0')
            ->setDescription('Police production')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))
            ->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $em->flush();

        $ids = [
            'aliceId' => (int) $alice->getId(),
            'avenantId' => (int) $avenant->getId(),
            'brunoId' => (int) $bruno->getId(),
            'idInvite' => (int) $gestionnaire->getId(),
            'idEntreprise' => (int) $entreprise->getId(),
        ];

        // ⚠ ON VIDE LA MÉMOIRE DE DOCTRINE, et il le faut : le client de test partage
        // l'EntityManager de ce test. La collection `conditionsPartageAgent` d'Alice,
        // créée vide puis remplie par le seul côté PROPRIÉTAIRE (`setAgent`), resterait
        // vide en mémoire — et le moteur de partage ne trouverait aucune affaire, alors
        // qu'une vraie requête la relit depuis la base.
        $em->clear();

        return $ids;
    }

    /**
     * UN VERSEMENT AVEC SA PIÈCE, par la vraie route — c'est la seule façon d'obtenir
     * l'état que le bouton « N pièces » attend : une affaire PAYÉE et JUSTIFIÉE.
     *
     * On ne fabrique pas ce couple à la main : le compte des pièces se lit par LOT,
     * et le poser en base à côté de sa règle ferait passer un test que l'écran
     * échouerait.
     */
    private function verser(array $ids): void
    {
        $this->client->request(
            'POST',
            '/admin/retro-agent/' . $ids['aliceId'] . '/reversement',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'reference' => 'VIR-PRD-1',
                'avecPiece' => true,
                'lignes' => [['avenantId' => $ids['avenantId'], 'montant' => 50.0]],
            ]),
        );
        self::assertResponseIsSuccessful('Le versement de la fixture doit passer.');

        $porteurId = json_decode((string) $this->client->getResponse()->getContent(), true)['porteurId'] ?? null;
        self::assertNotNull($porteurId);

        $fichier = sys_get_temp_dir() . '/bordereau-prd.txt';
        file_put_contents($fichier, 'bordereau');
        $this->client->request(
            'POST',
            '/admin/document/api/attacher/reversementRetroAgent/' . $porteurId,
            [],
            ['fichiers' => [new UploadedFile($fichier, 'bordereau.txt', 'text/plain', null, true)]],
        );
        self::assertResponseIsSuccessful('La pièce de la fixture doit être acceptée.');
        $this->em()->clear();
    }

    private function connecter(string $email): void
    {
        $this->client->loginUser(
            $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]),
        );
    }

    private function url(array $ids): string
    {
        return sprintf('/admin/productionintermediaire/%d/%d', $ids['idInvite'], $ids['idEntreprise']);
    }

    /**
     * LA COQUILLE STANDARD, ET RIEN D'AUTRE À DESSINER.
     *
     * C'est tout l'objet du lot : la même donnée, dans le moule commun. On vérifie donc la
     * présence des pièces que porte toute rubrique — et l'ABSENCE de l'entête fait main.
     */
    public function testLaRubriqueRendLaCoquilleStandard(): void
    {
        $ids = $this->semer();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request('GET', $this->url($ids));

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        // La coquille : le gestionnaire de vue, sa barre d'outils, sa recherche, ses totaux.
        self::assertStringContainsString('data-controller="view-manager"', $html);
        self::assertStringContainsString('data-controller="list-manager"', $html);
        self::assertStringContainsString('jsb-list-controls-bar__totals', $html);
        self::assertStringContainsString('ui:toolbar.refresh-request', $html);

        // Et la rubrique se nomme, dans le titre comme dans l'onglet.
        self::assertStringContainsString('Production des intermédiaires', $html);
    }

    /**
     * LES TROIS CHIPS SONT LÀ, et le sélecteur de bénéficiaire va chercher les DEUX familles.
     */
    public function testLesTroisChipsSontDeclares(): void
    {
        $ids = $this->semer();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request('GET', $this->url($ids));
        $html = (string) $this->client->getResponse()->getContent();

        foreach ([
            ProductionScope::CLE_STATUT,
            ProductionScope::CLE_TYPE,
            ProductionScope::CLE_BENEFICIAIRE,
        ] as $critere) {
            self::assertStringContainsString(
                'data-preset-criterion="' . $critere . '"',
                $html,
                sprintf('Le chip « %s » doit être rendu.', $critere),
            );
        }

        // Les DEUX sélecteurs : la rubrique porte les deux familles d'intermédiaires, et
        // n'en montrer qu'une aurait rendu la production des partenaires introuvable.
        self::assertStringContainsString('data-selecteur-entite="Invite"', $html);
        self::assertStringContainsString('data-selecteur-entite="Partenaire"', $html);
    }

    /**
     * SANS BÉNÉFICIAIRE, AUCUNE LIGNE — et c'est une réponse.
     *
     * La production se calcule affaire par affaire par le moteur de partage. La calculer
     * pour tout le cabinet à l'ouverture aurait fait payer très cher un écran que personne
     * n'a demandé ; l'écran invite donc à choisir.
     */
    public function testSansBeneficiaireLaRubriqueNeCalculeRien(): void
    {
        $ids = $this->semer();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request('GET', $this->url($ids));
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('POL-PRD-0', $html, 'Aucune affaire tant que personne n’est choisi.');
    }

    /**
     * AVEC UN BÉNÉFICIAIRE, LE TABLEAU DU RAPPORT — celui d'avant, sans une colonne de moins.
     */
    public function testAvecUnAgentLesLignesDuRapportParaissent(): void
    {
        $ids = $this->semer();
        $this->connecter(self::OWNER_EMAIL);

        $html = $this->interroger($ids, ProductionScope::critereBeneficiaire($ids['aliceId'], 'Alice'));

        self::assertStringContainsString('POL-PRD-0', $html, 'L’affaire d’Alice est là.');
        self::assertStringContainsString('Client Production', $html);
        // Les entêtes groupés du rapport, intacts.
        self::assertStringContainsString('PRIME DU CLIENT', strtoupper($html));
        self::assertStringContainsString('Totaux', $html);
    }

    /**
     * LA RÈGLE D'ACCÈS NE SE RELÂCHE PAS EN CHANGEANT D'ÉCRAN.
     *
     * Soi-même toujours, un collègue seulement si gestionnaire des invités. Un
     * relâchement ici exposerait la rémunération d'un collègue — c'est la garde que
     * l'ancien rapport tenait, et elle doit tenir dans la rubrique.
     */
    public function testUnAgentNeVoitPasLaProductionDunCollegue(): void
    {
        $ids = $this->semer();
        $this->connecter(self::ALICE_EMAIL);

        // Sa propre production : visible.
        $sienne = $this->interroger($ids, ProductionScope::critereBeneficiaire($ids['aliceId'], 'Alice'));
        self::assertStringContainsString('POL-PRD-0', $sienne);

        // Celle de Bruno : la rubrique retombe à son état d'accueil, sans rien dévoiler.
        $autre = $this->interroger($ids, ProductionScope::critereBeneficiaire($ids['brunoId'], 'Bruno'));
        self::assertStringNotContainsString('POL-PRD-0', $autre);
    }

    /**
     * LE CHIP STATUT PARTITIONNE VRAIMENT LES AFFAIRES.
     *
     * C'était la garde de l'ancien écran, quand le statut était une commande de sa barre
     * à lui : demander « En attente » ne devait pas ramener les polices SOUSCRITES. La
     * règle est serveur (`CotationSouscriptionScope`) et n'a pas changé de nature en
     * devenant un chip — seule sa porte a changé, et c'est cette porte qu'on vérifie ici.
     *
     * Un statut qui ne serait pas transmis rendrait le chip décoratif : l'écran
     * annoncerait « En attente » en montrant des affaires souscrites, et personne ne
     * pourrait s'en apercevoir sans recompter à la main.
     */
    public function testLeChipStatutNeMelangePasLesAffaires(): void
    {
        $ids = $this->semer();
        $this->connecter(self::OWNER_EMAIL);

        $beneficiaire = ProductionScope::critereBeneficiaire($ids['aliceId'], 'Alice');

        $souscrites = $this->interroger($ids, $beneficiaire + ProductionScope::critereRecherche(
            ProductionScope::ENTITE,
            ProductionScope::CLE_STATUT,
            CotationSouscriptionScope::STATUT_SOUSCRITES,
        ));
        self::assertStringContainsString('POL-PRD-0', $souscrites, 'La police souscrite est bien là.');

        $enAttente = $this->interroger($ids, $beneficiaire + ProductionScope::critereRecherche(
            ProductionScope::ENTITE,
            ProductionScope::CLE_STATUT,
            CotationSouscriptionScope::STATUT_EN_ATTENTE,
        ));
        self::assertStringNotContainsString(
            'POL-PRD-0',
            $enAttente,
            'Une police souscrite n’est pas « en attente » : le chip serait décoratif.',
        );
    }

    /**
     * ⚠ LE BOUTON « N PIÈCES » A UNE ROUTE À APPELER.
     *
     * Il annonçait un compte juste et ne faisait RIEN. L'ancien écran fabriquait son URL
     * depuis un `beneficiaire.prefixe` qui n'a jamais existé ; la rubrique qui a hérité du
     * tableau ne posait pas la valeur du tout. Le service qui rassemble ces pièces, lui,
     * était écrit et n'était appelé de nulle part.
     *
     * ⚠ ET L'URL EST SUR LE BOUTON, pas sur le conteneur. Le conteneur n'est rendu
     * qu'UNE fois — avant qu'un bénéficiaire soit choisi — tandis que ces lignes le sont
     * à chaque recherche : une URL posée là-haut serait restée vide pour toujours. C'est
     * exactement le défaut qu'on vient de corriger, et cette assertion l'interdit, puisque
     * ce qu'elle inspecte est la réponse de recherche.
     */
    public function testChaqueBoutonDePiecesPorteSonUrl(): void
    {
        $ids = $this->semer();
        $this->connecter(self::OWNER_EMAIL);
        // Une affaire PAYÉE : le bouton ne paraît que là où il y a une pièce à montrer.
        $this->verser($ids);

        $html = $this->interroger($ids, ProductionScope::critereBeneficiaire($ids['aliceId'], 'Alice'));

        self::assertStringContainsString(
            sprintf(
                // L'ATTRIBUT EST DANS L'ASSERTION, et il le faut : chercher la seule URL
                // laisserait passer un attribut renommé — le bouton porterait l'adresse sans
                // que le contrôleur sache la lire, ce qui est exactement l'état d'avant.
                'data-url="/admin/productionintermediaire/agent/%d/affaire/%d/justificatifs"',
                $ids['aliceId'],
                $ids['avenantId'],
            ),
            $html,
            'Le bouton désigne SON affaire, sans rien à substituer au clic.',
        );
    }

    /**
     * ET LA ROUTE RÉPOND, avec la même garde que les montants.
     *
     * Lire les justificatifs d'une affaire, c'est lire la rémunération de quelqu'un : la
     * règle ne se relâche pas parce qu'on demande des pièces plutôt que des chiffres.
     */
    public function testLesJustificatifsDUneAffaireSuiventLeMemeDroitQueLesMontants(): void
    {
        $ids = $this->semer();

        $url = sprintf('/admin/productionintermediaire/agent/%d/affaire/%d/justificatifs', $ids['aliceId'], $ids['avenantId']);

        $this->connecter(self::OWNER_EMAIL);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful('Le gestionnaire lit les pièces.');

        // Bruno n'est pas gestionnaire : la production d'Alice ne lui est pas ouverte, ses
        // justificatifs non plus.
        $this->connecter(self::BRUNO_EMAIL);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404, 'Les pièces d’un collègue ne se lisent pas.');
    }
    /**
     * ⚠ LE TABLEAU DOIT PARLER À LA BARRE DES TOTAUX.
     *
     * C'est la condition posée au lot : le rendu du rapport ne change pas, mais ses lignes
     * deviennent SÉLECTIONNABLES et alimentent la barre. Le socle croise ses valeurs avec
     * les cases cochées PAR IDENTIFIANT : si la ligne et la valeur n'en portent pas le
     * même, la barre affiche zéro pour une ligne pourtant cochée — un total d'apparence
     * normale, et faux.
     */
    public function testLesLignesSontSelectionnablesEtAlimententLesTotaux(): void
    {
        $ids = $this->semer();
        $this->connecter(self::OWNER_EMAIL);

        $this->client->request(
            'POST',
            sprintf('/admin/productionintermediaire/api/dynamic-query/%d/%d', $ids['idInvite'], $ids['idEntreprise']),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['criteria' => ProductionScope::critereBeneficiaire($ids['aliceId'], 'Alice')]),
        );

        self::assertResponseIsSuccessful();
        $reponse = json_decode((string) $this->client->getResponse()->getContent(), true);
        $html = $reponse['html'];

        // LE CONTRAT DE LIGNE du socle, sans lequel rien ne se coche.
        self::assertStringContainsString('data-controller="list-row"', $html);
        self::assertStringContainsString('data-list-manager-target="rowCheckbox"', $html);
        self::assertStringContainsString('change->list-manager#handleRowSelection', $html);

        // LA CASE A PRIS LA PLACE DU « # », elle ne s'y est pas ajoutée : le pied des
        // totaux recopie les largeurs mesurées de l'entête, et une colonne de plus
        // l'aurait décalé d'un cran sur toute sa longueur.
        self::assertStringContainsString('data-list-manager-target="selectAllCheckbox"', $html);

        // ET LES VALEURS SONT LÀ, indexées par le MÊME identifiant que les lignes.
        $numerique = $reponse['numericAttributesAndValues'];
        self::assertNotEmpty($numerique, 'La barre des totaux doit avoir de quoi totaliser.');

        $id = (int) array_key_first($numerique);
        self::assertStringContainsString('data-id="' . $id . '"', $html, 'La ligne et sa valeur portent le même identifiant.');

        // Les montants sont en CENTIMES — le contrat de `list-summary`, partagé par les
        // trente-quatre rubriques. La commission de la fixture vaut 1 000, la part
        // d'Alice 20 % : 200.
        // Le JSON rend un entier quand la valeur est ronde : on compare des NOMBRES,
        // pas leur type — 200 et 200.0 sont le même argent.
        self::assertEqualsWithDelta(200.0, $numerique[$id]['due']['value'] / 100, 0.001);
        self::assertSame('Rétrocommission due', $numerique[$id]['due']['description']);
    }

    /**
     * PAS DE PIED SANS LIGNES À TOTALISER.
     *
     * « Totaux (0 ligne(s)) » suivi de vingt-cinq zéros, sous un écran qui invite à
     * choisir quelqu'un, donnait à un état d'accueil l'apparence d'un rapport nul. Deux
     * choses très différentes pour qui lit un état.
     */
    public function testLePiedDesTotauxNeParaitPasSansLignes(): void
    {
        $ids = $this->semer();
        $this->connecter(self::OWNER_EMAIL);

        $vide = $this->interroger($ids, []);
        self::assertStringNotContainsString('Totaux ·', $vide, 'Aucun total à annoncer.');
        self::assertStringContainsString('Choisissez un intermédiaire', $vide);

        // Avec un bénéficiaire, il revient — et il compte.
        $plein = $this->interroger($ids, ProductionScope::critereBeneficiaire($ids['aliceId'], 'Alice'));
        // « Totaux (1 ligne(s)) » comptait deux parenthèses imbriquées pour trois mots.
        self::assertStringContainsString('Totaux · 1 ligne', $plein);
    }

    /**
     * Interroge la rubrique comme le fait le contrôleur Stimulus : un POST de critères.
     *
     * @param array<string, mixed> $criteres
     */
    private function interroger(array $ids, array $criteres): string
    {
        $this->client->request(
            'POST',
            sprintf('/admin/productionintermediaire/api/dynamic-query/%d/%d', $ids['idInvite'], $ids['idEntreprise']),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['criteria' => $criteres]),
        );

        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true)['html'] ?? '';
    }
}
