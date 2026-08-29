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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * LE RAPPORT DE PRODUCTION D'UN AGENT — contenu, filtres et surtout QUI PEUT LE VOIR.
 *
 * ── LA RÈGLE D'ACCÈS EST LE CŒUR DE CE TEST ─────────────────────────────────────────
 * L'entité Invite relève de la gestion des invités : sa lecture exige normalement le
 * privilège d'administration de l'espace. Or l'exigence métier veut qu'un agent retrouve
 * SES rétrocommissions depuis SON compte. La règle retenue est donc :
 *
 *      soi-même toujours · un autre agent seulement si gestionnaire d'invités
 *
 * Elle doit tenir dans les deux sens : Alice voit son rapport, mais pas celui de Bruno.
 * Un relâchement ici exposerait la rémunération d'un collègue.
 *
 * ── ET VERSER N'EST PAS CONSULTER ───────────────────────────────────────────────────
 * Personne ne se paie soi-même : le picker de reversement exige le privilège de gestion,
 * même sur sa propre fiche.
 */
class RapportProductionAgentTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-rpa-owner@test.local';
    private const ALICE_EMAIL = 'phpunit-rpa-alice@test.local';
    private const BRUNO_EMAIL = 'phpunit-rpa-bruno@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit Rapport SARL';

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

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function makeUser(string $email): Utilisateur
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new Utilisateur())->setEmail($email)->setNom('PHPUnit')->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);

        return $user;
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL, self::ALICE_EMAIL, self::BRUNO_EMAIL];

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE pcp FROM piste_condition_partage pcp JOIN piste p ON pcp.piste_id = p.id
             JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach ([
            'reversement_retro_agent', 'condition_partage', 'avenant', 'revenu_pour_courtier',
            'type_revenu', 'chargement_pour_prime', 'cotation', 'piste', 'client', 'risque', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    public function testLeProprietaireOuvreLeRapportAvecSesLignesEtSesTotaux(): void
    {
        $ids = $this->semer();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', '/admin/retro-agent/' . $ids['aliceId'] . '/rapport');

        self::assertResponseIsSuccessful();
        $charge = json_decode($this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('Alice', $charge['title']);

        $html = $charge['html'];
        // Une ligne par police souscrite, la référence en clair.
        self::assertStringContainsString('POL-RPA-0', $html);
        self::assertStringContainsString('POL-RPA-1', $html);
        // Le gestionnaire est nommé : rappel que le bénéficiaire n'est pas celui qui gère.
        self::assertStringContainsString('Gestionnaire', $html);
        // Deux affaires à 20 % de 1000 de commission pure : 400 au total.
        self::assertStringContainsString('Totaux (2 ligne(s))', $html);
        self::assertStringContainsString('400,00', $html);
    }

    public function testLeFiltreEnAttenteNeRamenePasLesPolicesSouscrites(): void
    {
        $ids = $this->semer(avecProposition: true);
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request(
            'GET',
            '/admin/retro-agent/' . $ids['aliceId'] . '/rapport?statut=' . CotationSouscriptionScope::STATUT_EN_ATTENTE,
        );

        self::assertResponseIsSuccessful();
        $html = json_decode($this->client->getResponse()->getContent(), true)['html'];

        // La proposition apparaît, les polices non.
        self::assertStringContainsString('Proposition en cours', $html);
        self::assertStringNotContainsString('POL-RPA-0', $html);
        // Et l'écran DIT que ce n'est pas un dû.
        self::assertStringContainsString('Projection', $html);
    }

    public function testLeRapportSActualiseSansPerdreLeStatutAffiche(): void
    {
        $ids = $this->semer(avecProposition: true);
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // On lit le rapport SOUS UN FILTRE : c'est là que l'actualisation peut mentir.
        $this->client->request(
            'GET',
            '/admin/retro-agent/' . $ids['aliceId'] . '/rapport?statut=' . CotationSouscriptionScope::STATUT_EN_ATTENTE,
        );

        self::assertResponseIsSuccessful();
        $html = json_decode($this->client->getResponse()->getContent(), true)['html'];

        // La commande existe, et elle est branchée.
        self::assertStringContainsString('click->retro-agent-rapport#actualiser', $html);
        self::assertStringContainsString('Actualiser', $html);

        // ET ELLE RELIT LES MÊMES AFFAIRES. Le statut affiché voyage jusqu'au contrôleur :
        // sans cette valeur, actualiser redemanderait « Souscrites » et effacerait en
        // silence le filtre que l'utilisateur venait de poser — un rapport qui change de
        // contenu sans qu'on le lui ait demandé.
        self::assertStringContainsString(
            'data-retro-agent-rapport-statut-value="' . CotationSouscriptionScope::STATUT_EN_ATTENTE . '"',
            $html,
        );

        // L'HEURE DE LECTURE est dite : elle est la raison d'être du bouton — sans elle,
        // rien ne distingue des montants de l'instant de montants vieux d'une heure.
        self::assertMatchesRegularExpression('/lus? à \d{2}:\d{2}/u', $html);
    }

    public function testUnAgentConsulteSonProprePeriMetreMaisPasCeluiDunCollegue(): void
    {
        $ids = $this->semer();
        $this->client->loginUser($this->user(self::ALICE_EMAIL));

        // Soi-même : toujours autorisé, même sans privilège de gestion.
        $this->client->request('GET', '/admin/retro-agent/' . $ids['aliceId'] . '/rapport');
        self::assertResponseIsSuccessful('Un agent doit pouvoir consulter SES rétrocommissions.');

        // Un collègue : refusé.
        $this->client->request('GET', '/admin/retro-agent/' . $ids['brunoId'] . '/rapport');
        self::assertResponseStatusCodeSame(403, 'La rémunération d\'un collègue n\'est pas consultable.');
    }

    public function testVerserExigeLePrivilegeDeGestionMemeSurSaPropreFiche(): void
    {
        $ids = $this->semer();
        $this->client->loginUser($this->user(self::ALICE_EMAIL));

        $this->client->request('GET', '/admin/retro-agent/' . $ids['aliceId'] . '/reversement-picker');
        self::assertResponseStatusCodeSame(403, 'Personne ne se paie soi-même.');
    }

    public function testLePickerNeProposeQueLesSoldesExigibles(): void
    {
        $ids = $this->semer();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', '/admin/retro-agent/' . $ids['aliceId'] . '/reversement-picker');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        // ── LE PICKER DOIT S'OUVRIR, PAS SEULEMENT RÉPONDRE ────────────────────────
        //
        // Cette réponse était une enveloppe JSON `{html, title}`, et ce test se
        // contentait d'y lire la clé « html » : il passait au vert pendant que le picker
        // ne s'ouvrait JAMAIS. `picker-open.js` — l'ouvreur commun au portefeuille, aux
        // risques ciblés et aux clients — lit la réponse en TEXTE et insère son premier
        // élément ; une chaîne JSON n'en contient aucun, d'où « Contenu du picker vide ».
        //
        // On vérifie donc ce dont l'ouvreur a réellement besoin : un fragment HTML dont
        // la racine porte le contrôleur du picker.
        self::assertStringStartsWith('<div', ltrim($html));
        self::assertStringContainsString('data-controller="reversement-retro-picker"', $html);

        // Aucune commission n'a été encaissée par le cabinet : rien n'est encore
        // réclamable, et le picker l'explique au lieu d'afficher un tableau vide muet.
        self::assertStringContainsString('Rien à reverser', $html);
    }

    /**
     * Les trois champs du versement s'ouvrent RENSEIGNÉS — date, référence, compte.
     *
     * Ce contrôle se fait sur les fichiers et non sur une page rendue : le bloc « Le
     * versement » n'existe que s'il reste un solde exigible, ce que ce jeu de données
     * n'a pas (aucune commission encaissée). Vérifier le câblage a tout de même du
     * sens — une variable renommée d'un seul côté ne se voit qu'à l'exécution, et
     * seulement pour un agent qu'on doit réellement payer.
     */
    public function testLesChampsDuVersementSontProposesRemplis(): void
    {
        $gabarit = (string) file_get_contents(
            __DIR__ . '/../../templates/components/retro_agent/_reversement_picker.html.twig'
        );

        // La référence : proposée par le serveur, et le champ NOMMÉ — un champ texte
        // anonyme se fait remplir tout seul par le navigateur.
        self::assertStringContainsString('value="{{ referenceParDefaut }}"', $gabarit);
        self::assertStringContainsString('name="reversement_reference"', $gabarit);
        self::assertStringContainsString('autocomplete="off"', $gabarit);

        $controleur = (string) file_get_contents(
            __DIR__ . '/../../src/Controller/Admin/RetroAgentController.php'
        );
        self::assertStringContainsString("'referenceParDefaut' =>", $controleur);

        // Le compte retenu d'office est celui que le SERVICE propose — pas « le premier
        // de la boucle » : c'est la même règle qui sert Ket, et deux formulations de la
        // même règle finissent par désigner deux comptes différents.
        self::assertStringContainsString("compte.id == compteProposeId", $gabarit);
        self::assertStringContainsString("compteProposeId is null ? ' selected' : ''", $gabarit);
        self::assertStringContainsString("'compteProposeId' =>", $controleur);

        // Ni le gabarit ni le contrôleur ne portent la formule de référence.
        self::assertStringNotContainsString("'RETRO-' .", $controleur);
    }
    public function testUnReversementEnLotEcritUneLigneParAffaireEtUneSeuleReference(): void
    {
        $ids = $this->semer();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request(
            'POST',
            '/admin/retro-agent/' . $ids['aliceId'] . '/reversement',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'reference' => 'VIR-TEST-77',
                // Un versement ne s'enregistre plus sans justificatif : le client annonce
                // la pièce qu'il déposera juste après, sur le porteur du lot.
                'avecPiece' => true,
                'lignes' => [
                    ['avenantId' => $ids['avenantIds'][0], 'montant' => 120.0],
                    ['avenantId' => $ids['avenantIds'][1], 'montant' => 80.0],
                ],
            ]),
        );

        self::assertResponseIsSuccessful();
        $reponse = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(2, $reponse['crees']);
        // json_decode rend 200 (int) quand le total est rond : on compare la VALEUR, pas
        // le type que la sérialisation JSON a choisi.
        self::assertEqualsWithDelta(200.0, $reponse['total'], 0.001);

        // DEUX lignes (le solde reste exact affaire par affaire) mais UN seul lot : c'est
        // ce qui permettra à la comptabilité de n'émettre qu'une écriture.
        $lignes = $this->em()->getRepository(\App\Entity\ReversementRetroAgent::class)
            ->findBy(['reference' => 'VIR-TEST-77']);
        self::assertCount(2, $lignes);
        foreach ($lignes as $ligne) {
            self::assertSame('VIR-TEST-77', $ligne->getLotReference());
        }
    }

    public function testUnReversementIsoleNaPasDeReferenceDeLot(): void
    {
        $ids = $this->semer();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request(
            'POST',
            '/admin/retro-agent/' . $ids['aliceId'] . '/reversement',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'reference' => 'VIR-SOLO-1',
                'avecPiece' => true,
                'lignes' => [['avenantId' => $ids['avenantIds'][0], 'montant' => 50.0]],
            ]),
        );

        self::assertResponseIsSuccessful();
        $ligne = $this->em()->getRepository(\App\Entity\ReversementRetroAgent::class)
            ->findOneBy(['reference' => 'VIR-SOLO-1']);

        self::assertNotNull($ligne);
        self::assertNull(
            $ligne->getLotReference(),
            'Un reversement seul ne doit jamais pouvoir être fondu dans le lot d\'un autre.',
        );
    }

    /**
     * PAS DE VERSEMENT SANS PREUVE, et le refus tombe AVANT la moindre écriture.
     *
     * Un reversement est une sortie de fonds réelle : sans bordereau, c'est un montant que
     * rien ne rattache à la banque. Refuser après coup aurait laissé un décaissement
     * enregistré sans justificatif — précisément ce que la règle interdit.
     */
    public function testUnVersementSansJustificatifEstRefuseAvantTouteEcriture(): void
    {
        $ids = $this->semer();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request(
            'POST',
            '/admin/retro-agent/' . $ids['aliceId'] . '/reversement',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'reference' => 'VIR-SANS-PIECE',
                'lignes' => [['avenantId' => $ids['avenantIds'][0], 'montant' => 50.0]],
            ]),
        );

        self::assertResponseStatusCodeSame(422);
        $reponse = json_decode($this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('justificatif', $reponse['message']);

        // RIEN n'a été écrit : c'est tout l'intérêt d'une garde posée avant la boucle.
        self::assertCount(
            0,
            $this->em()->getRepository(\App\Entity\ReversementRetroAgent::class)
                ->findBy(['reference' => 'VIR-SANS-PIECE']),
            'Un versement refusé ne doit laisser aucune ligne derrière lui.',
        );
    }
    public function testUnAvenantHorsPerimetreEstIgnore(): void
    {
        $ids = $this->semer();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request(
            'POST',
            '/admin/retro-agent/' . $ids['aliceId'] . '/reversement',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['lignes' => [['avenantId' => 999999999, 'montant' => 50.0]]]),
        );

        // Aucune ligne exploitable : refus explicite, jamais une écriture silencieuse.
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Deux polices souscrites dont Alice est BÉNÉFICIAIRE (via une condition de partage à
     * son nom), gérées par un TROISIÈME invité. Bruno existe pour éprouver la garde
     * d'accès ; il n'est bénéficiaire de rien.
     *
     * @return array{aliceId:int, brunoId:int, avenantIds:int[]}
     */
    private function semer(bool $avecProposition = false): array
    {
        $em = $this->em();

        $ownerUser = $this->makeUser(self::OWNER_EMAIL);
        $aliceUser = $this->makeUser(self::ALICE_EMAIL);
        $brunoUser = $this->makeUser(self::BRUNO_EMAIL);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($ownerUser);
        $em->persist($entreprise);

        $ownerUser->setConnectedTo($entreprise);
        $aliceUser->setConnectedTo($entreprise);
        $brunoUser->setConnectedTo($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($ownerUser)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $alice = (new Invite())->setNom('Alice')->setProprietaire(false);
        $alice->setUtilisateur($aliceUser)->setEntreprise($entreprise);
        $em->persist($alice);

        $bruno = (new Invite())->setNom('Bruno')->setProprietaire(false);
        $bruno->setUtilisateur($brunoUser)->setEntreprise($entreprise);
        $em->persist($bruno);

        $risque = (new Risque())->setCode('RP')->setNomComplet('Risque Rapport')->setDescription('Risque du rapport')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client Rapport')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $condition = (new ConditionPartage())->setNom('Prime apporteur Alice')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(self::TAUX_ALICE)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($alice);
        $condition->setEntreprise($entreprise);
        $em->persist($condition);

        $avenantIds = [];
        $nb = $avecProposition ? 3 : 2;
        for ($i = 0; $i < $nb; ++$i) {
            $estProposition = $avecProposition && $i === 2;

            $piste = (new Piste())->setNom('Piste Rapport ' . $i)->setTypeAvenant(0)
                ->setDescriptionDuRisque('Risque rapport')->setExercice((int) date('Y'))
                ->setClient($client)->setRisque($risque);
            // ⚠ Le GESTIONNAIRE, pas Alice : les deux rôles sont indépendants.
            $piste->setEntreprise($entreprise)->setInvite($gestionnaire);
            $piste->addConditionsPartageAgent($condition);
            $em->persist($piste);

            $cotation = (new Cotation())->setNom($estProposition ? 'Proposition en cours' : 'Cotation Rapport ' . $i)
                ->setDuree(365);
            $cotation->setPiste($piste);
            $cotation->setEntreprise($entreprise);
            $em->persist($cotation);

            $chargement = (new ChargementPourPrime())->setNom('Prime ' . $i)->setMontantFlatExceptionel(5000.0);
            $chargement->setEntreprise($entreprise);
            $cotation->addChargement($chargement);
            $em->persist($chargement);

            $typeRevenu = (new TypeRevenu())->setNom('Commission ' . $i)->setMontantflat(self::COMMISSION)
                ->setShared(false)->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
            $typeRevenu->setEntreprise($entreprise);
            $em->persist($typeRevenu);

            $revenu = (new RevenuPourCourtier())->setNom('Revenu ' . $i)->setTypeRevenu($typeRevenu)->setCotation($cotation);
            $revenu->setEntreprise($entreprise);
            $em->persist($revenu);

            if (!$estProposition) {
                $avenant = (new Avenant())->setReferencePolice('POL-RPA-' . $i)->setNumero('0')
                    ->setDescription('Police rapport')
                    ->setStartingAt(new \DateTimeImmutable('-30 days'))
                    ->setEndingAt(new \DateTimeImmutable('+335 days'));
                $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
                $cotation->addAvenant($avenant);
                $em->persist($avenant);
            }
        }

        $em->flush();

        foreach ($em->getRepository(Avenant::class)->findBy(['entreprise' => $entreprise], ['id' => 'ASC']) as $avenant) {
            $avenantIds[] = (int) $avenant->getId();
        }

        $ids = [
            'aliceId' => (int) $alice->getId(),
            'brunoId' => (int) $bruno->getId(),
            'avenantIds' => $avenantIds,
        ];
        $em->clear();

        return $ids;
    }
}
