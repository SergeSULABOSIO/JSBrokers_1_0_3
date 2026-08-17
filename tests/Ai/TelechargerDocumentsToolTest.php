<?php

namespace App\Tests\Ai;

use App\Ai\Resolution\CritereLieA;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\TelechargerDocumentsTool;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'outil « telecharger_documents » sur la VRAIE base — et il fallait la vraie base.
 *
 * CE QU'UN TEST UNITAIRE NE PROUVERAIT PAS. Cet outil repose sur trois mécanismes qui
 * n'existent qu'au contact du schéma : les CHEMINS DE RELATIONS entre Document et
 * l'entité de rattachement (« les documents de la police X » passe par
 * Document.avenant, « ceux du client » par Document.avenant.cotation.piste.client), le
 * SCOPING ENTREPRISE du service de recherche, et les INDICATEURS CALCULÉS du parent,
 * qui interrogent la base à leur tour. Un chemin faux ne lève aucune erreur : il rend
 * simplement moins de lignes — exactement le défaut que ce projet traque depuis le
 * début, une donnée qui existe et dont l'assistant conclut qu'elle n'existe pas.
 *
 * LES FICHIERS SONT RÉELS. L'outil écarte délibérément les documents dont le binaire
 * est absent du disque (un bouton qui rend 404 est pire que pas de bouton) : le seul
 * moyen de tester cette règle est d'en écrire vraiment sur le disque, et d'en laisser
 * un sans.
 */
class TelechargerDocumentsToolTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-teldoc-owner@test.local';
    private const AUTRE_EMAIL = 'phpunit-teldoc-autre@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit TelDoc SARL';
    private const ENTREPRISE_AUTRE = 'PHPUnit TelDoc Concurrente';

    /** @var list<string> binaires écrits sur le disque, à retirer en sortie */
    private array $fichiersEcrits = [];

    protected function setUp(): void
    {
        static::bootKernel();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        foreach ($this->fichiersEcrits as $chemin) {
            @unlink($chemin);
        }
        $this->fichiersEcrits = [];
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function tool(): TelechargerDocumentsTool
    {
        return static::getContainer()->get(TelechargerDocumentsTool::class);
    }

    private function dossierUploads(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/documents';
    }

    /**
     * Balaie les binaires laissés par une exécution PRÉCÉDENTE.
     *
     * La liste tenue en mémoire ne suffit pas : quand un test échoue dans setUp(),
     * PHPUnit n'appelle pas tearDown() et les fichiers restent. Le balayage porte sur
     * le préfixe de CE test uniquement — jamais sur le dossier entier, qui contient les
     * vrais documents du poste de développement.
     */
    private function balayerBinairesOrphelins(): void
    {
        foreach (glob($this->dossierUploads() . '/phpunit-teldoc-*') ?: [] as $orphelin) {
            @unlink($orphelin);
        }
    }

    private function cleanUp(): void
    {
        $this->balayerBinairesOrphelins();

        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_AUTRE];
        $emails = [self::OWNER_EMAIL, self::AUTRE_EMAIL];

        // Enfants avant parents : document précède avenant, qui précède cotation ; le
        // portefeuille suit le client, qui le référence.
        foreach (['document', 'avenant', 'cotation', 'assureur', 'piste', 'client', 'portefeuille', 'classeur', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
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

    /**
     * Écrit un vrai binaire dans le dossier Vich et rend son nom de stockage. Le
     * contenu est arbitraire mais NON VIDE : une taille de 0 octet se confondrait avec
     * « taille inconnue » et rendrait le test aveugle sur ce point précis.
     */
    private function ecrireBinaire(string $extension, int $octets = 128): string
    {
        $dossier = $this->dossierUploads();
        if (!is_dir($dossier)) {
            mkdir($dossier, 0o777, true);
        }
        $nomStocke = 'phpunit-teldoc-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $chemin = $dossier . '/' . $nomStocke;
        file_put_contents($chemin, str_repeat('x', $octets));
        $this->fichiersEcrits[] = $chemin;

        return $nomStocke;
    }

    /**
     * Un dossier complet : une police portant DEUX documents, un client en portant un
     * troisième, un document SANS binaire, et une entreprise concurrente portant le
     * sien — c'est cette dernière qui rend le test de scoping probant.
     *
     * @return array{scope: AiScope, avenant: Avenant, client: Client, idAutre: int}
     */
    private function seed(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)
            ->setNom('PHPUnit TelDoc')
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)
            ->setLicence('LIC-TDOC')
            ->setAdresse('1 rue des Pièces')
            ->setTelephone('+243000000010')
            ->setRccm('RCCM-TDOC')
            ->setIdnat('IDNAT-TDOC')
            ->setNumimpot('IMP-TDOC')
            ->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $invite = (new Invite())->setNom('Serge');
        $invite->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        // LE CLIENT APPARTIENT À UN PORTEFEUILLE, et ce détail n'en est pas un : depuis
        // que Document est soumis au périmètre portefeuille, un client sans portefeuille
        // rendrait ses documents invisibles — état qui n'existe pas en exploitation, et
        // qui ferait passer un test pour une régression.
        $portefeuille = (new Portefeuille())->setNom('Portefeuille de Serge')->setGestionnaire($invite);
        $portefeuille->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($portefeuille);

        $client = (new Client())->setNom('KIN AVIA')->setExonere(false);
        $client->setEntreprise($entreprise)->setInvite($invite)->setPortefeuille($portefeuille);
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Flotte 2026')
            ->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque de test téléchargement')
            ->setExercice(2026)
            ->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $assureur = (new Assureur())
            ->setNom('SFA TelDoc')
            ->setEmail('sfa-teldoc@example.test')
            ->setNumimpot('IMP-TDOC-A')
            ->setIdnat('NAT-TDOC-A')
            ->setRccm('RCCM-TDOC-A');
        $assureur->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($assureur);

        $cotation = (new Cotation())->setNom('Proposition flotte')->setDuree(365);
        $cotation->setPiste($piste)->setAssureur($assureur);
        $cotation->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($cotation);

        $avenant = (new Avenant())
            ->setReferencePolice('POL-TDOC-1')
            ->setNumero('0')
            ->setDescription('Police de test téléchargement')
            ->setStartingAt(new \DateTimeImmutable('2026-03-01'))
            ->setEndingAt(new \DateTimeImmutable('2027-02-28'));
        $avenant->setCotation($cotation);
        $avenant->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($avenant);

        $classeur = (new Classeur())->setNom('Contrats 2026')->setDescription('Classeur de test');
        $classeur->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($classeur);

        // DEUX documents sur la police, dont un rangé dans un classeur.
        $doc1 = (new Document())->setNom('Contrat signé');
        $doc1->setNomFichierStocke($this->ecrireBinaire('pdf', 2048));
        $doc1->setAvenant($avenant)->setClasseur($classeur);
        $doc1->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($doc1);

        $doc2 = (new Document())->setNom('Conditions particulières');
        $doc2->setNomFichierStocke($this->ecrireBinaire('docx', 512));
        $doc2->setAvenant($avenant);
        $doc2->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($doc2);

        // Un document SANS binaire sur le disque : il ne doit jamais être proposé.
        $doc3 = (new Document())->setNom('Annexe promise');
        $doc3->setNomFichierStocke('phpunit-teldoc-absent.pdf');
        $doc3->setAvenant($avenant);
        $doc3->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($doc3);

        // Un document du CLIENT, hors de la police : il ne doit pas remonter sur un
        // lieA=Avenant, mais bien sur un lieA=Client.
        $doc4 = (new Document())->setNom('Registre de commerce');
        $doc4->setNomFichierStocke($this->ecrireBinaire('pdf', 300));
        $doc4->setClient($client);
        $doc4->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($doc4);

        // ── UN AUTRE GESTIONNAIRE, dans la MÊME entreprise ──
        // C'est le cas que le périmètre portefeuille existe pour traiter : son client
        // n'est pas le mien, ses pièces ne me regardent pas — bien que nous partagions
        // l'entreprise, donc le scoping de sécurité.
        $autreGestionnaire = (new Invite())->setNom('Collègue');
        $autreGestionnaire->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($autreGestionnaire);

        $autrePortefeuille = (new Portefeuille())->setNom('Portefeuille du collègue')->setGestionnaire($autreGestionnaire);
        $autrePortefeuille->setEntreprise($entreprise)->setInvite($autreGestionnaire);
        $em->persist($autrePortefeuille);

        $clientDuCollegue = (new Client())->setNom('SOCIÉTÉ DU COLLÈGUE')->setExonere(false);
        $clientDuCollegue->setEntreprise($entreprise)->setInvite($autreGestionnaire)->setPortefeuille($autrePortefeuille);
        $em->persist($clientDuCollegue);

        $docDuCollegue = (new Document())->setNom('Dossier du collègue');
        $docDuCollegue->setNomFichierStocke($this->ecrireBinaire('pdf', 111));
        $docDuCollegue->setClient($clientDuCollegue);
        $docDuCollegue->setEntreprise($entreprise)->setInvite($autreGestionnaire);
        $em->persist($docDuCollegue);

        // UN ORPHELIN : rattaché à un classeur seulement, donc à aucun portefeuille.
        // Il doit rester VISIBLE — il n'appartient à personne en particulier.
        $orphelin = (new Document())->setNom('Note de service');
        $orphelin->setNomFichierStocke($this->ecrireBinaire('pdf', 222));
        $orphelin->setClasseur($classeur);
        $orphelin->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($orphelin);

        // ── L'entreprise concurrente, et son document homonyme ──
        $autreUser = (new Utilisateur())
            ->setEmail(self::AUTRE_EMAIL)
            ->setNom('PHPUnit TelDoc Autre')
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($autreUser);

        $autreEntreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_AUTRE)
            ->setLicence('LIC-TDOC2')
            ->setAdresse('2 rue Voisine')
            ->setTelephone('+243000000011')
            ->setRccm('RCCM-TDOC2')
            ->setIdnat('IDNAT-TDOC2')
            ->setNumimpot('IMP-TDOC2')
            ->setUtilisateur($autreUser);
        $em->persist($autreEntreprise);

        $autreInvite = (new Invite())->setNom('Voisin');
        $autreInvite->setUtilisateur($autreUser)->setEntreprise($autreEntreprise)->setProprietaire(true);
        $em->persist($autreInvite);

        $docAutre = (new Document())->setNom('Contrat signé');
        $docAutre->setNomFichierStocke($this->ecrireBinaire('pdf', 999));
        $docAutre->setEntreprise($autreEntreprise)->setInvite($autreInvite);
        $em->persist($docAutre);

        $em->flush();

        return [
            'scope'   => new AiScope($entreprise, $invite),
            'avenant' => $avenant,
            'client'  => $client,
            'idAutre' => (int) $docAutre->getId(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * LE CAS CENTRAL : « les documents de la police », par son identifiant — sans
     * qu'aucun identifiant de DOCUMENT n'ait été fourni. C'est ce que l'outil ne savait
     * pas faire : il fallait déjà connaître les documents pour les demander.
     */
    public function testTrouveLesDocumentsDUnePolice(): void
    {
        $seed = $this->seed();

        $resultat = $this->tool()->execute(
            ['lieA' => ['entite' => 'Avenant', 'id' => $seed['avenant']->getId()]],
            $seed['scope'],
        );

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);

        $noms = array_column($resultat->data['fichiers'], 'nom');
        sort($noms);
        self::assertSame(['Conditions particulières', 'Contrat signé'], $noms, 'Seuls les documents de la police, et tous ceux qui portent un fichier.');
    }

    /**
     * LE RATTACHEMENT SE DONNE PAR NOM, pas seulement par identifiant — c'est ce qui
     * évite au modèle un second tour d'outils qu'il n'a pas.
     *
     * Le nom est résolu sur le champ de libellé de l'entité visée (EntiteLibelle) :
     * pour un Client, c'est « nom ». Une police, elle, se résout par son « numero » et
     * non par sa référence — limite du résolveur partagé, la même pour
     * rechercher_entites, et c'est pourquoi ce test porte sur un client.
     */
    public function testLeRattachementSeDonneParNom(): void
    {
        $seed = $this->seed();

        $resultat = $this->tool()->execute(
            ['lieA' => ['entite' => 'Client', 'nom' => 'KIN AVIA']],
            $seed['scope'],
        );

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status, 'Le nom doit être résolu côté serveur, sans second tour.');
        self::assertContains('Registre de commerce', array_column($resultat->data['fichiers'], 'nom'));
    }

    /**
     * « LES FICHIERS DU CLIENT » DESCENDENT TOUT LE DOSSIER — ses pièces ET celles de
     * ses pistes, cotations et polices.
     *
     * CE TEST A ÉTÉ RETOURNÉ, et c'est la correction qui compte le plus ici. Il fixait
     * la limite inverse : le graphe générique de relations s'arrêtant à trois segments,
     * « les documents du client » ne rendait que les pièces DIRECTEMENT attachées, et le
     * test entérinait cette borne comme « un choix visible ». À l'usage, ce choix ne
     * tenait pas : l'utilisateur qui demandait les fichiers d'un client savait qu'il y
     * en avait sous ses polices, recevait « un seul fichier », et devait corriger
     * l'assistant tour après tour pour les obtenir un par un.
     *
     * La borne du graphe n'est plus en cause : la recherche ne filtre plus Document par
     * un chemin de relations, elle DESCEND depuis la fiche (DescenteDesDocuments).
     * Chaque pièce arrive avec le NIVEAU d'où elle sort.
     */
    public function testLesFichiersDUnClientDescendentJusquASesPolices(): void
    {
        $seed = $this->seed();

        $resultat = $this->tool()->execute(
            ['lieA' => ['entite' => 'Client', 'id' => $seed['client']->getId()]],
            $seed['scope'],
        );

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $noms = array_column($resultat->data['fichiers'], 'nom');
        self::assertContains('Registre de commerce', $noms, 'La pièce portée par le client lui-même.');
        self::assertContains('Contrat signé', $noms, 'La pièce de sa POLICE, quatre niveaux plus bas.');

        // Et chaque ligne dit d'où elle sort : c'est la première question devant une
        // liste qui mélange les étages du dossier.
        $niveaux = array_column($resultat->data['fichiers'], 'niveau', 'nom');
        self::assertNotSame(
            $niveaux['Registre de commerce'] ?? null,
            $niveaux['Contrat signé'] ?? null,
            'Deux pièces d’étages différents ne peuvent pas annoncer le même niveau.',
        );
    }

    /** Recherche par nom, sans aucun rattachement. */
    public function testTrouveUnDocumentParSonNom(): void
    {
        $seed = $this->seed();

        $resultat = $this->tool()->execute(['nom' => 'Registre'], $seed['scope']);

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertCount(1, $resultat->data['fichiers']);
        self::assertSame('Registre de commerce', $resultat->data['fichiers'][0]['nom']);
    }

    /**
     * UN SEUL TABLEAU, ET C'EST CELUI DU PANNEAU. L'outil ne déclare plus de
     * « presentation » : cette déclaration n'existait que pour guider le markdown du
     * modèle, et par la règle (1) du contrat de présentation elle l'OBLIGEAIT à écrire
     * le tableau — celui-là même que le panneau dessine déjà juste en dessous. Deux
     * tableaux pour une réponse. La présence de la clé ferait donc revenir la
     * redondance : ce test la garde absente.
     *
     * Les lignes restent numérotées et restent renvoyées : Ket doit pouvoir compter les
     * fichiers et citer un nom dans sa phrase de synthèse.
     */
    public function testAucuneDeclarationDePresentationEtDesLignesNumerotees(): void
    {
        $seed = $this->seed();

        $resultat = $this->tool()->execute(
            ['lieA' => ['entite' => 'Avenant', 'id' => $seed['avenant']->getId()]],
            $seed['scope'],
        );

        self::assertArrayNotHasKey(
            'presentation',
            $resultat->data,
            'Déclarer des colonnes ferait réécrire au modèle le tableau que l’interface affiche déjà.',
        );

        $lignes = $resultat->data['fichiers'];
        self::assertSame([1, 2], array_column($lignes, 'n°'), 'La numérotation commence à 1 et ne saute pas.');
    }

    /**
     * LE CONTRAT AVEC LE PANNEAU, croisé PHP ↔ JS. Puisque le tableau visible est
     * désormais celui du panneau et lui seul, toute colonne que le module JS dessine
     * doit être PRÉSENTE dans la charge utile envoyée à l'interface. Une colonne
     * dessinée sans donnée derrière produirait une colonne de tirets — l'anomalie que
     * le contrat de présentation nomme « colonne fantôme », transposée au panneau.
     *
     * On lit la source JS plutôt que de recopier la liste : une liste recopiée est une
     * seconde vérité, qui cesse d'être vraie au premier ajout de colonne.
     */
    public function testChaqueColonneDuPanneauEstAlimenteeParLOutil(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 2) . '/assets/controllers/assistant-files-download.js');
        self::assertIsString($source, 'Le module de présentation du panneau doit être lisible.');

        preg_match_all("/\{\s*cle:\s*'([^']+)'/", $source, $m);
        $colonnes = $m[1];
        self::assertNotEmpty($colonnes, 'Les colonnes du panneau doivent être déclarées dans COLONNES.');

        $seed = $this->seed();
        $resultat = $this->tool()->execute(
            ['lieA' => ['entite' => 'Avenant', 'id' => $seed['avenant']->getId()]],
            $seed['scope'],
        );

        $premiere = $resultat->uiAction['fichiers'][0];
        foreach ($colonnes as $cle) {
            // « n » est le rang, calculé par le panneau à partir de la position ; il n'a
            // pas à voyager. Toutes les autres colonnes sont des données.
            if ($cle === 'n') {
                continue;
            }
            self::assertArrayHasKey($cle, $premiere, sprintf('La colonne « %s » du panneau doit être ALIMENTÉE.', $cle));
        }

        self::assertArrayHasKey('url', $premiere, 'Sans URL, le bouton de téléchargement de la ligne est mort.');
    }

    /**
     * LE CONTEXTE, qui est la raison d'être de ce chantier : le fichier arrive avec sa
     * matérialité ET la fiche de l'objet dont il provient, indicateurs calculés
     * compris. Sans cela, l'utilisateur reçoit « contrat.pdf » et rouvre le logiciel
     * pour savoir d'où il sort.
     */
    public function testChaqueFichierPorteSonContexteEtCeluiDeSonParent(): void
    {
        $seed = $this->seed();

        $resultat = $this->tool()->execute(['nom' => 'Contrat signé'], $seed['scope']);

        $contexte = $resultat->data['contexte'][0];
        self::assertSame('PDF', $contexte['format']);
        self::assertSame('2,0 Ko', $contexte['taille'], 'Le poids réel du binaire, lu sur le disque.');
        self::assertNotSame('', $contexte['chargeLe'], 'La date de mise en ligne est renseignée.');
        self::assertSame('Contrats 2026', $contexte['classeur']);
        self::assertStringContainsString('POL-TDOC-1', $contexte['rattacheA'], 'Le rattachement nomme la police.');

        // Le nom de téléchargement porte l'extension : sans elle, le fichier reçu est
        // inouvrable — c'est précisément ce que servait l'ancienne route de l'interface.
        self::assertSame('Contrat signé.pdf', $contexte['fichier']);

        // La fiche du DOCUMENT porte ses attributs calculés.
        self::assertArrayHasKey('fiche', $contexte);
        self::assertArrayHasKey('parent_string', $contexte['fiche']);

        // Et la fiche du PARENT porte les siens : c'est là que vivent les 100 % promis.
        self::assertArrayHasKey('origine', $contexte, 'Le document doit exposer son objet d’origine.');
        self::assertSame('Avenant', $contexte['origine']['entite']);
        self::assertSame('POL-TDOC-1', $contexte['origine']['fiche']['referencePolice'] ?? null);
        self::assertGreaterThan(
            5,
            count($contexte['origine']['fiche']),
            'La fiche du parent doit être ENRICHIE (attributs stockés + indicateurs calculés), pas un simple identifiant.',
        );
    }

    /**
     * UN SEUL FICHIER : le MÊME panneau que pour dix, et toujours pas d'archive.
     *
     * CE TEST A ÉTÉ RETOURNÉ DEUX FOIS, et les deux fois pour la même raison — le
     * nombre de résultats ne doit pas changer où l'utilisateur va lire. Il a d'abord
     * exigé qu'un fichier unique soit présenté « sans tableau » ; il exige maintenant
     * que la note interdise le tableau au modèle, dans les deux cas, parce que le
     * tableau est celui de l'interface.
     *
     * L'archive, elle, reste réservée à plusieurs fichiers : proposer « tout
     * télécharger » pour un seul ajouterait un clic et un dossier à ouvrir.
     */
    public function testUnSeulFichierEstPresenteDansLeMemePanneau(): void
    {
        $seed = $this->seed();

        $resultat = $this->tool()->execute(['nom' => 'Registre'], $seed['scope']);

        self::assertCount(1, $resultat->uiAction['fichiers']);
        self::assertArrayNotHasKey('zipUrl', $resultat->uiAction, 'Une archive d’un seul fichier n’a pas de sens.');
        self::assertStringContainsString('N\'ÉCRIS AUCUN TABLEAU', $resultat->data['note']);
        self::assertSame(
            ['n°', 'nom', 'format', 'taille', 'niveau'],
            array_keys($resultat->data['fichiers'][0]),
            'Les colonnes ne dépendent pas du nombre de résultats.',
        );
    }

    /** PLUSIEURS FICHIERS : le tableau numéroté ET l'archive groupée. */
    public function testPlusieursFichiersProposentUneArchiveGroupee(): void
    {
        $seed = $this->seed();

        $resultat = $this->tool()->execute(
            ['lieA' => ['entite' => 'Avenant', 'id' => $seed['avenant']->getId()]],
            $seed['scope'],
        );

        self::assertCount(2, $resultat->uiAction['fichiers']);
        self::assertArrayHasKey('zipUrl', $resultat->uiAction);
        self::assertStringContainsString('/zip', $resultat->uiAction['zipUrl']);
        self::assertStringContainsString('Tout télécharger', $resultat->data['note'], 'La note doit annoncer le bouton d’archive, que le modèle ne voit pas.');

        // Chaque entrée porte de quoi peupler une ligne du tableau, URL comprise.
        foreach ($resultat->uiAction['fichiers'] as $entree) {
            foreach (['id', 'nom', 'format', 'taille', 'chargeLe', 'rattacheA', 'url'] as $cle) {
                self::assertArrayHasKey($cle, $entree);
            }
        }
    }

    /**
     * UN DOCUMENT SANS BINAIRE N'EST PAS PROPOSÉ, et son absence est DITE. Un bouton
     * qui rendrait 404 est pire que pas de bouton ; le taire laisserait croire que le
     * dossier est complet.
     */
    public function testUnDocumentSansFichierEstEcarteEtSignale(): void
    {
        $seed = $this->seed();

        $resultat = $this->tool()->execute(
            ['lieA' => ['entite' => 'Avenant', 'id' => $seed['avenant']->getId()]],
            $seed['scope'],
        );

        self::assertNotContains('Annexe promise', array_column($resultat->data['fichiers'], 'nom'));
        self::assertArrayHasKey('sansFichier', $resultat->data);
        self::assertStringContainsString('1 document', $resultat->data['sansFichier']);
    }

    /**
     * SCOPING ENTREPRISE. Un identifiant venu du modèle est une demande, jamais une
     * autorisation : le document d'une autre entreprise n'existe pas.
     */
    public function testUnDocumentDUneAutreEntrepriseResteInvisible(): void
    {
        $seed = $this->seed();

        $resultat = $this->tool()->execute(['ids' => [$seed['idAutre']]], $seed['scope']);

        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
        self::assertNull($resultat->uiAction, 'Aucun bouton ne doit être proposé pour un document hors périmètre.');
    }

    /** Le mode par identifiants reste opérant pour les documents de l'entreprise. */
    public function testLeModeParIdentifiantsFonctionne(): void
    {
        $seed = $this->seed();

        $reference = $this->tool()->execute(['nom' => 'Registre'], $seed['scope']);
        $id = $reference->uiAction['fichiers'][0]['id'];

        $resultat = $this->tool()->execute(['ids' => [$id]], $seed['scope']);

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertSame('Registre de commerce', $resultat->data['fichiers'][0]['nom']);
    }

    /**
     * « DONNE-MOI LE TABLEAU DES FICHIERS DE TOUT MON PORTEFEUILLE » — sans le moindre
     * critère, et c'est une demande complète.
     *
     * Elle ne déverse pas l'entreprise : elle rend le périmètre de CELUI QUI DEMANDE, par
     * la fabrique partagée avec la rubrique Documents. Le périmètre appliqué est déclaré
     * dans la réponse, pour que Ket ne puisse pas présenter un portefeuille comme la
     * totalité de l'entreprise.
     */
    public function testSansCritereLOutilRendTousLesFichiersDuPortefeuille(): void
    {
        $seed = $this->seed();

        $resultat = $this->tool()->execute([], $seed['scope']);

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $noms = array_column($resultat->data['fichiers'], 'nom');
        self::assertContains('Contrat signé', $noms);
        self::assertContains('Registre de commerce', $noms);
        self::assertSame('Mon portefeuille', $resultat->data['perimetre']);
    }

    /**
     * LE CLOISONNEMENT ENTRE GESTIONNAIRES, qui est la raison d'être du périmètre.
     *
     * Document n'y était soumis à aucun titre : la rubrique — et Ket avec elle — montrait
     * à chaque invité les pièces de TOUS les clients de l'entreprise, y compris ceux d'un
     * autre gestionnaire. « Les fichiers de mon portefeuille » ne voulait donc rien dire.
     */
    public function testLesDocumentsDUnAutreGestionnaireNeRemontentPas(): void
    {
        $seed = $this->seed();

        $resultat = $this->tool()->execute([], $seed['scope']);

        self::assertNotContains(
            'Dossier du collègue',
            array_column($resultat->data['fichiers'], 'nom'),
            'Le client appartient au portefeuille d’un autre gestionnaire.',
        );
    }

    /**
     * MAIS UN DOCUMENT SANS CLIENT RESTE VISIBLE. Un bordereau, un fournisseur, une note
     * rangée dans un classeur n'atteignent aucun portefeuille : les masquer les ferait
     * disparaître de l'écran alors qu'ils n'appartiennent au portefeuille de PERSONNE.
     */
    public function testUnDocumentSansPortefeuilleResteVisible(): void
    {
        $seed = $this->seed();

        $resultat = $this->tool()->execute([], $seed['scope']);

        self::assertContains(
            'Note de service',
            array_column($resultat->data['fichiers'], 'nom'),
            'Un orphelin n’appartient à personne : il ne doit être masqué à personne.',
        );
    }

    /**
     * L'ÉLARGISSEMENT RESTE POSSIBLE, mais sur demande explicite — et il est DIT.
     */
    public function testLePerimetreSElargitALEntrepriseSurDemande(): void
    {
        $seed = $this->seed();

        $resultat = $this->tool()->execute(['perimetre' => 'entreprise'], $seed['scope']);

        self::assertContains('Dossier du collègue', array_column($resultat->data['fichiers'], 'nom'));
        self::assertSame("toute l'entreprise", $resultat->data['perimetre']);
    }

    /**
     * FAIL-CLOSED. Sans droit de lecture sur Document, les fichiers n'existent pas pour
     * l'assistant — et rien n'est cherché du tout.
     */
    public function testSansDroitDeLectureRienNEstProposeH(): void
    {
        $seed = $this->seed();

        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('canRead')->willReturn(false);
        $resolver->method('libellesEntites')->willReturn([]);

        $conteneur = static::getContainer();
        $outil = new TelechargerDocumentsTool(
            $resolver,
            $conteneur->get(\App\Services\JSBDynamicSearchService::class),
            $conteneur->get('router'),
            $conteneur->get(CritereLieA::class),
            $conteneur->get(\App\Ai\Document\ContexteDeDocument::class),
            $conteneur->get(\App\Service\Document\DocumentFichier::class),
            $conteneur->get(\App\Token\TokenAccountService::class),
            $conteneur->get(\App\Services\Search\PortefeuilleCritereFactory::class),
            $conteneur->get(\App\Service\Document\DescenteDesDocuments::class),
            $conteneur->get(\Doctrine\ORM\EntityManagerInterface::class),
        );

        $resultat = $outil->execute(['nom' => 'Contrat'], $seed['scope']);

        self::assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $resultat->status);
        self::assertNull($resultat->uiAction);
    }
}
