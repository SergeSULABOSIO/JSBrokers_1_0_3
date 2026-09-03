<?php

namespace App\Tests\Echange;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\EchangeConsulterTool;
use App\Ai\Tool\EchangeExporterTool;
use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Classeur\EcrivainJsbx;
use App\Echange\Classeur\Manifeste;
use App\Echange\Service\CompteurDOccurrences;
use App\Echange\Service\ExportateurJsbx;
use App\Entity\Client;
use App\Entity\EchangeOccurrence;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\RolesEnProduction;
use App\Entity\Utilisateur;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'EXPORTATION, de bout en bout.
 *
 * Ce que ces tests tiennent, dans l'ordre d'importance :
 *
 *  1. LE PÉRIMÈTRE RESPECTE LES DROITS, ressource par ressource. C'est le point sur
 *     lequel la rubrique peut le plus mal tourner : sans ce filtrage, elle deviendrait
 *     un contournement propre de toute la matrice d'accès de l'application.
 *  2. LE CLASSEUR EST RELISIBLE — ligne 2 technique, manifeste cohérent, empreinte
 *     reproductible. Un fichier qu'on ne sait pas relire n'est pas un aller-retour.
 *  3. LA FACTURATION COMPTE JUSTE, et ne compte que ce qui a abouti.
 *  4. KET DIT EXACTEMENT LA MÊME CHOSE QUE L'ÉCRAN — même périmètre, même coût.
 */
class ExportJsbxTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-echange-export@test.local';
    private const GUEST_EMAIL = 'phpunit-echange-invite@test.local';
    private const ENT = 'PHPUnit Échange SARL';

    protected function setUp(): void
    {
        static::bootKernel();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 1. Droits
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Le point critique de la rubrique : un invité qui ne peut lire que les clients
     * n'exporte QUE les clients — pas les polices, pas les commissions, pas le reste.
     */
    public function testLePerimetreDExportEstLimiteAuxDroitsDeLInvite(): void
    {
        [$entreprise, , $invite] = $this->fixture();

        $exportateur = $this->service(ExportateurJsbx::class);
        $codes = array_keys($exportateur->perimetre($invite));

        self::assertContains('Client', $codes, 'L\'invité a le droit de lecture sur les clients.');
        self::assertNotContains('Avenant', $codes, 'Il n\'a AUCUN droit sur les polices : elles ne doivent pas sortir.');
        self::assertNotContains('Note', $codes, 'Ni sur les notes de débit.');
        self::assertNotContains('Tranche', $codes, 'Ni sur les échéanciers.');
    }

    /** Le propriétaire, lui, voit tout : son bypass ne doit pas avoir été rompu. */
    public function testLeProprietaireExporteToutLePerimetre(): void
    {
        [, $proprietaire, ] = $this->fixture();

        $exportateur = $this->service(ExportateurJsbx::class);
        $canevas = $this->service(CanevasDEchange::class);

        self::assertCount(
            count($canevas->toutes()),
            $exportateur->perimetre($proprietaire),
            'Le propriétaire du cabinet doit pouvoir exporter toutes les données échangeables.',
        );
    }

    /**
     * Demander une donnée hors de son périmètre ne l'ajoute pas : la demande est
     * ramenée aux droits, elle ne les élargit jamais.
     */
    public function testUneDemandeNeGagneJamaisDeDroit(): void
    {
        [, , $invite] = $this->fixture();

        $codes = array_keys($this->service(ExportateurJsbx::class)->perimetre($invite, ['Client', 'Avenant', 'Note']));

        self::assertSame(['Client'], $codes, 'Seule la donnée réellement lisible est retenue.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 2. Le classeur
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Le classeur produit doit se relire : feuilles techniques présentes, ligne 2
     * portant les codes, données à partir de la ligne 3.
     */
    public function testLeClasseurEstStructureEtRelisible(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME Exportable');

        $classeur = $this->classeurDe($entreprise, $proprietaire, ['Client']);

        self::assertNotNull($classeur->getSheetByName(EcrivainJsbx::FEUILLE_MANIFESTE));
        self::assertNotNull($classeur->getSheetByName(EcrivainJsbx::FEUILLE_DICTIONNAIRE));
        self::assertNotNull($classeur->getSheetByName(EcrivainJsbx::FEUILLE_LISTES));

        $ressource = $this->service(CanevasDEchange::class)->ressource('Client');
        $feuille = $classeur->getSheetByName($ressource->feuille);
        self::assertNotNull($feuille, 'La feuille des clients doit exister.');

        // Ligne 2 = codes techniques, et c'est elle qui fait foi.
        $codes = array_map(
            static fn ($v) => is_string($v) ? $v : '',
            $feuille->rangeToArray('A2:' . $feuille->getHighestColumn() . '2', null, false, false, false)[0],
        );
        self::assertSame(CanevasDEchange::COL_UID, $codes[0]);
        self::assertSame(CanevasDEchange::COL_ACTION, $codes[1]);
        self::assertSame(CanevasDEchange::COL_REF, $codes[2]);
        self::assertSame(CanevasDEchange::COL_MODIFIE_LE, $codes[3]);
        self::assertContains('nom', $codes, 'Le code technique du nom doit figurer en ligne 2.');

        // La ligne technique est MASQUÉE, pas absente : la supprimer rendrait le
        // fichier illisible sans que rien à l'écran ne le laisse deviner.
        self::assertFalse($feuille->getRowDimension(2)->getVisible());

        // Données à partir de la ligne 3, avec un identifiant de la forme « Client:id ».
        $uid = (string) $feuille->getCell('A3')->getValue();
        self::assertMatchesRegularExpression('/^Client:\d+$/', $uid, 'Le _uid doit être « Ressource:id ».');
    }

    /** La colonne de scoping ne doit apparaître dans AUCUNE feuille du classeur produit. */
    public function testLeClasseurNExposeJamaisLaColonneDeScoping(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME Scoping');

        $classeur = $this->classeurDe($entreprise, $proprietaire, ['Client']);
        $ressource = $this->service(CanevasDEchange::class)->ressource('Client');
        $feuille = $classeur->getSheetByName($ressource->feuille);

        $codes = $feuille->rangeToArray('A2:' . $feuille->getHighestColumn() . '2', null, false, false, false)[0];
        self::assertNotContains('entreprise', $codes, 'La colonne d\'appartenance au cabinet ne sort jamais.');
    }

    /**
     * L'empreinte des en-têtes doit être REPRODUCTIBLE : deux exports du même périmètre
     * donnent la même. Sinon une réimportation immédiate se croirait altérée.
     */
    public function testLEmpreinteDesEntetesEstReproductible(): void
    {
        $canevas = $this->service(CanevasDEchange::class);
        $ressources = $canevas->toutes();

        $a = Manifeste::empreinte($ressources, EcrivainJsbx::COLONNES_TECHNIQUES);
        $b = Manifeste::empreinte($ressources, EcrivainJsbx::COLONNES_TECHNIQUES);

        self::assertSame($a, $b);
        self::assertSame(64, strlen($a), 'Une empreinte SHA-256 fait 64 caractères.');

        // Retirer une donnée du périmètre CHANGE l'empreinte : c'est tout son objet.
        $partiel = array_slice($ressources, 0, 3, true);
        self::assertNotSame($a, Manifeste::empreinte($partiel, EcrivainJsbx::COLONNES_TECHNIQUES));
    }

    /** Le manifeste identifie le cabinet émetteur et la version qui a produit le fichier. */
    public function testLeManifesteIdentifieLeCabinetEtLaVersion(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $classeur = $this->classeurDe($entreprise, $proprietaire, ['Client']);
        $feuille = $classeur->getSheetByName(EcrivainJsbx::FEUILLE_MANIFESTE);

        $valeurs = [];
        foreach ($feuille->toArray(null, true, false, false) as $ligne) {
            if (($ligne[0] ?? '') !== '' && $ligne[0] !== 'Clé') {
                $valeurs[$ligne[0]] = (string) ($ligne[2] ?? '');
            }
        }

        $manifeste = Manifeste::depuisValeurs($valeurs);
        self::assertSame((string) $entreprise->getId(), $manifeste->uidCabinet);
        self::assertSame(self::ENT, $manifeste->nomCabinet);
        self::assertNotSame('', $manifeste->versionSchema, 'La version vient du versionnage de l\'application.');
        self::assertSame(64, strlen($manifeste->empreinteEntetes));
        // Demander « Client » embarque ses dépendances : un client pend d'un groupe et
        // d'un portefeuille, et un fichier qui renverrait vers des lignes absentes ne
        // serait pas réimportable. La fermeture est donc attendue, pas subie.
        self::assertSame(['Groupe', 'Portefeuille', 'Client'], $manifeste->perimetre);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 3. Facturation
    // ─────────────────────────────────────────────────────────────────────────────

    /** Les premières opérations sont offertes, les suivantes facturées. */
    public function testLesPremieresOperationsSontOffertesPuisFacturees(): void
    {
        [$entreprise] = $this->fixture();
        $compteur = $this->service(CompteurDOccurrences::class);

        $quota = $compteur->etat($entreprise)['quotaGratuit'];
        self::assertSame($quota, $compteur->gratuitesRestantes($entreprise));
        self::assertSame(0, $compteur->coutProchaine($entreprise, EchangeOccurrence::TYPE_EXPORT));

        // On consomme le quota.
        for ($i = 0; $i < $quota; ++$i) {
            $this->enregistrerOccurrence($entreprise, 'graine-' . $i);
        }

        self::assertSame(0, $compteur->gratuitesRestantes($entreprise));
        self::assertGreaterThan(
            0,
            $compteur->coutProchaine($entreprise, EchangeOccurrence::TYPE_EXPORT),
            'Passé le quota, l\'exportation devient payante.',
        );
    }

    /** L'importation n'a jamais de forfait, quota épuisé ou non. */
    public function testLImportationNaJamaisDeForfait(): void
    {
        [$entreprise] = $this->fixture();
        $compteur = $this->service(CompteurDOccurrences::class);

        for ($i = 0; $i < $compteur->etat($entreprise)['quotaGratuit']; ++$i) {
            $this->enregistrerOccurrence($entreprise, 'import-graine-' . $i);
        }

        self::assertSame(
            0,
            $compteur->coutProchaine($entreprise, EchangeOccurrence::TYPE_IMPORT),
            'L\'import paie le métrage d\'écriture de chaque ligne, jamais un forfait par opération.',
        );
    }

    /** Un rejeu ne produit ni seconde occurrence, ni second débit. */
    public function testUnRejeuNeFacturePasDeuxFois(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $compteur = $this->service(CompteurDOccurrences::class);
        $em = $this->em();

        $cle = $compteur->cleIdempotence($entreprise, $proprietaire, EchangeOccurrence::TYPE_EXPORT, ['Client'], 'meme-clic');

        $a = $compteur->enregistrer($entreprise, $proprietaire, null, EchangeOccurrence::TYPE_EXPORT, ['Client'], 1, $cle);
        $em->flush();
        $b = $compteur->enregistrer($entreprise, $proprietaire, null, EchangeOccurrence::TYPE_EXPORT, ['Client'], 1, $cle);
        $em->flush();

        self::assertSame($a->getId(), $b->getId(), 'Le rejeu retrouve l\'occurrence existante.');
        self::assertSame(1, $compteur->consommees($entreprise), 'Une seule occurrence a été comptée.');
    }

    /** Solde insuffisant : refus AVANT génération, et aucune occurrence. */
    public function testSoldeInsuffisantRefuseAvantDeGenerer(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $compteur = $this->service(CompteurDOccurrences::class);
        $em = $this->em();

        // On épuise le quota gratuit, puis le solde de tokens du propriétaire.
        for ($i = 0; $i < $compteur->etat($entreprise)['quotaGratuit']; ++$i) {
            $this->enregistrerOccurrence($entreprise, 'solde-graine-' . $i);
        }
        $owner = $entreprise->getUtilisateur();
        $owner->setPaidTokens(0);
        $owner->setFreeTokens(0);
        $em->flush();

        $avant = $compteur->consommees($entreprise);

        $this->expectException(\App\Token\InsufficientTokensException::class);
        try {
            $this->service(ExportateurJsbx::class)->exporter($entreprise, $proprietaire, $owner, ['Client']);
        } finally {
            self::assertSame($avant, $compteur->consommees($entreprise), 'Un refus ne compte aucune occurrence.');
        }
    }

    /** Un export abouti laisse exactement une occurrence, avec son périmètre réel. */
    public function testUnExportAboutiEnregistreSonOccurrence(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME Occurrence');
        $compteur = $this->service(CompteurDOccurrences::class);

        $reponse = $this->service(ExportateurJsbx::class)->exporter($entreprise, $proprietaire, $entreprise->getUtilisateur(), ['Client']);

        self::assertSame(200, $reponse->getStatusCode());
        self::assertSame(1, $compteur->consommees($entreprise));

        $occurrence = $this->em()->getRepository(EchangeOccurrence::class)->findOneBy(['entreprise' => $entreprise]);
        self::assertNotNull($occurrence);
        self::assertSame(EchangeOccurrence::TYPE_EXPORT, $occurrence->getType());
        // Le périmètre journalisé est celui RÉELLEMENT sorti, dépendances comprises —
        // pas celui qui avait été demandé. C'est ce qui est parti du cabinet qui compte.
        self::assertSame(['Groupe', 'Portefeuille', 'Client'], $occurrence->getPerimetre());
        self::assertSame(1, $occurrence->getNbLignes(), 'Un seul client existe ; groupes et portefeuilles sont vides.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 4. Parité Ket
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * LE test de parité : le périmètre que Ket restitue est celui que l'écran affiche,
     * donnée par donnée, pour le MÊME utilisateur.
     */
    public function testKetRestitueExactementLePerimetreDeLEcran(): void
    {
        [$entreprise, , $invite] = $this->fixture();

        $ecran = array_keys($this->service(ExportateurJsbx::class)->perimetre($invite));

        $resultat = $this->service(EchangeConsulterTool::class)
            ->execute(['sujet' => 'perimetre'], new AiScope($entreprise, $invite, null));

        self::assertSame('OK', $resultat->status);
        $ket = array_column($resultat->data['perimetre'], 'code');

        self::assertSame($ecran, $ket, 'Ket et l\'écran doivent lister les mêmes données, dans le même ordre.');
    }

    /** Le coût annoncé par Ket vient du compteur, donc coïncide avec celui de l'écran. */
    public function testLeCoutAnnonceParKetEstCeluiDeLEcran(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $compteur = $this->service(CompteurDOccurrences::class);

        // On épuise le quota pour sortir du cas trivial « c'est gratuit ».
        for ($i = 0; $i < $compteur->etat($entreprise)['quotaGratuit']; ++$i) {
            $this->enregistrerOccurrence($entreprise, 'parite-graine-' . $i);
        }

        $ecran = $compteur->etat($entreprise);
        $resultat = $this->service(EchangeConsulterTool::class)
            ->execute(['sujet' => 'facturation'], new AiScope($entreprise, $proprietaire, null));

        self::assertSame($ecran['coutExport'], $resultat->data['facturation']['coutExport']);
        self::assertSame($ecran['gratuitesRestantes'], $resultat->data['facturation']['gratuitesRestantes']);
        self::assertSame($ecran['soldeDisponible'], $resultat->data['facturation']['soldeDisponible']);
    }

    /** Sans le droit d'accès à la rubrique, Ket refuse exactement comme l'écran. */
    public function testKetRefuseALIdentiqueDeLEcran(): void
    {
        [$entreprise, , , $sansDroit] = $this->fixture();

        foreach ([EchangeConsulterTool::class, EchangeExporterTool::class] as $outil) {
            $resultat = $this->service($outil)->execute([], new AiScope($entreprise, $sansDroit, null));
            self::assertSame(
                'HORS_PERIMETRE',
                $resultat->status,
                sprintf('%s doit refuser à un invité sans droit sur la rubrique.', $outil),
            );
        }
    }

    /** L'outil d'export ne dicte jamais son URL : elle est générée par le serveur. */
    public function testLOutilDExportEmetUneUrlServeurEtAnnonceLeCout(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $resultat = $this->service(EchangeExporterTool::class)
            ->execute(['donnees' => ['Clients']], new AiScope($entreprise, $proprietaire, null));

        self::assertSame('OK', $resultat->status);
        self::assertTrue($resultat->data['pret']);
        self::assertNotNull($resultat->uiAction);
        self::assertSame('open-url', $resultat->uiAction['type']);
        self::assertStringContainsString('/admin/echange/export/' . $entreprise->getId(), $resultat->uiAction['url']);
        self::assertArrayHasKey('cout', $resultat->data, 'Le coût est annoncé AVANT l\'exécution.');

        // « Clients » (libellé de rubrique) doit être reconnu comme le code « Client ».
        self::assertContains('Client', explode(',', $this->donneesDeLUrl($resultat->uiAction['url'])));
    }

    /**
     * LE critère d'acceptation de la parité : ajouter une donnée au périmètre la rend
     * visible et exportable par Ket SANS toucher au moindre outil. On le vérifie en
     * confrontant l'inventaire de l'outil à celui du canevas — s'ils divergeaient, c'est
     * qu'une liste aurait été recopiée quelque part.
     */
    public function testLInventaireDeKetEstDeriveEtNonRecopie(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $resultat = $this->service(EchangeConsulterTool::class)
            ->execute(['sujet' => 'perimetre'], new AiScope($entreprise, $proprietaire, null));

        $ket = array_column($resultat->data['perimetre'], 'code');
        sort($ket);

        $attendu = \App\Ai\Mutation\MutationAllowlist::MEMBRES;
        sort($attendu);

        self::assertSame(
            $attendu,
            $ket,
            'L\'inventaire de Ket doit se dériver de MutationAllowlist : ajouter un nom suffit.',
        );
    }

    /** Une question sur l'état de la rubrique se résout en UN SEUL appel. */
    public function testUneQuestionSurLEtatSeResoutEnUnSeulAppel(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $resultat = $this->service(EchangeConsulterTool::class)
            ->execute(['sujet' => 'tout'], new AiScope($entreprise, $proprietaire, null));

        foreach (['perimetre', 'facturation', 'historique', 'controle_en_cours'] as $cle) {
            self::assertArrayHasKey($cle, $resultat->data, sprintf('Le sujet « tout » doit couvrir « %s ».', $cle));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Outillage
    // ─────────────────────────────────────────────────────────────────────────────

    private function donneesDeLUrl(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        return (string) ($params['donnees'] ?? '');
    }

    /** Produit le classeur et le relit, comme le ferait un utilisateur. */
    private function classeurDe(Entreprise $entreprise, Invite $invite, array $codes): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $reponse = $this->service(ExportateurJsbx::class)->exporter($entreprise, $invite, $entreprise->getUtilisateur(), $codes);

        ob_start();
        $reponse->sendContent();
        $contenu = (string) ob_get_clean();

        $chemin = tempnam(sys_get_temp_dir(), 'jsbx_') . '.xlsx';
        file_put_contents($chemin, $contenu);

        try {
            return IOFactory::load($chemin);
        } finally {
            @unlink($chemin);
        }
    }

    private function enregistrerOccurrence(Entreprise $entreprise, string $graine): void
    {
        $compteur = $this->service(CompteurDOccurrences::class);
        $compteur->enregistrer(
            $entreprise,
            null,
            null,
            EchangeOccurrence::TYPE_EXPORT,
            ['Client'],
            0,
            hash('sha256', $graine),
        );
        $this->em()->flush();
    }

    private function creerClient(Entreprise $entreprise, Invite $invite, string $nom): Client
    {
        $client = new Client();
        $client->setNom($nom);
        $client->setEntreprise($entreprise);
        $client->setInvite($invite);

        $this->em()->persist($client);
        $this->em()->flush();

        return $client;
    }

    /**
     * Cabinet, propriétaire, invité au périmètre RESTREINT (clients seulement) et
     * invité SANS aucun droit.
     *
     * @return array{0: Entreprise, 1: Invite, 2: Invite, 3: Invite}
     */
    private function fixture(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)
            ->setPassword('x')
            ->setNom('Propriétaire')
            ->setVerified(true);
        $owner->setPaidTokens(100000);
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom(self::ENT)
            ->setRccm('RCCM-ECH')
            ->setIdnat('IDNAT-ECH')
            ->setNumimpot('IMP-ECH')
            ->setLicence('LIC-ECH')
            ->setAdresse('1 rue du Test')
            ->setTelephone('+243000000');
        $entreprise->setUtilisateur($owner);
        $owner->setConnectedTo($entreprise);
        $em->persist($entreprise);
        $em->flush();

        $proprietaire = (new Invite())->setNom('Propriétaire')->setEmail(self::OWNER_EMAIL);
        $proprietaire->setProprietaire(true);
        $proprietaire->setEntreprise($entreprise);
        $proprietaire->setUtilisateur($owner);
        $em->persist($proprietaire);

        // Invité RESTREINT : lecture sur les clients, plus l'accès à la rubrique.
        $invite = (new Invite())->setNom('Invité restreint')->setEmail(self::GUEST_EMAIL);
        $invite->setProprietaire(false);
        $invite->setEntreprise($entreprise);
        $em->persist($invite);

        $admin = (new RolesEnAdministration())->setNom('Admin restreint');
        $admin->setAccessEchange([Invite::ACCESS_LECTURE]);
        $admin->setEntreprise($entreprise);
        $admin->setInvite($invite);
        $em->persist($admin);
        $invite->addRolesEnAdministration($admin);

        $prod = (new RolesEnProduction())->setNom('Prod restreint');
        $prod->setAccessClient([Invite::ACCESS_LECTURE]);
        $prod->setEntreprise($entreprise);
        $prod->setInvite($invite);
        $em->persist($prod);
        $invite->addRolesEnProduction($prod);

        // Invité SANS AUCUN droit : pas même la porte de la rubrique.
        $sansDroit = (new Invite())->setNom('Sans droit')->setEmail('phpunit-echange-nul@test.local');
        $sansDroit->setProprietaire(false);
        $sansDroit->setEntreprise($entreprise);
        $em->persist($sansDroit);

        $em->flush();

        return [$entreprise, $proprietaire, $invite, $sansDroit];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @template T @param class-string<T> $classe @return T */
    private function service(string $classe): object
    {
        return static::getContainer()->get($classe);
    }

    /**
     * Purge le cabinet de test et tout ce qui s'y rattache.
     *
     * Les tables enfants sont DÉRIVÉES du schéma plutôt qu'énumérées : soixante-trois
     * tables référencent aujourd'hui `entreprise`, et une liste écrite à la main
     * deviendrait fausse au premier ajout d'entité — en laissant derrière elle des
     * lignes qui feraient échouer un test SANS RAPPORT, des jours plus tard.
     *
     * Les contrôles de clés étrangères sont levés le temps de la purge : l'ordre de
     * suppression entre enfants (un rôle pend d'un invité, qui pend du cabinet) n'a
     * aucun intérêt ici, et le rétablir à la main serait une seconde liste à tenir.
     */
    private function nettoyer(): void
    {
        $cnx = $this->em()->getConnection();

        $ids = $cnx->fetchFirstColumn('SELECT id FROM entreprise WHERE nom = ?', [self::ENT]);
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL, 'phpunit-echange-nul@test.local'];

        $cnx->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            if ($ids !== []) {
                $enfants = $cnx->fetchAllAssociative(
                    'SELECT DISTINCT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                     WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?',
                    ['entreprise'],
                );
                foreach ($ids as $id) {
                    foreach ($enfants as $enfant) {
                        $table = $enfant['TABLE_NAME'];
                        $colonne = $enfant['COLUMN_NAME'];
                        if ($table === 'utilisateur') {
                            // On ne SUPPRIME pas un utilisateur parce qu'il était connecté
                            // à ce cabinet : on le détache. Les comptes du test sont
                            // supprimés plus bas, nommément.
                            $cnx->executeStatement(sprintf('UPDATE `%s` SET `%s` = NULL WHERE `%s` = ?', $table, $colonne, $colonne), [$id]);
                            continue;
                        }
                        $cnx->executeStatement(sprintf('DELETE FROM `%s` WHERE `%s` = ?', $table, $colonne), [$id]);
                    }
                    $cnx->executeStatement('DELETE FROM entreprise WHERE id = ?', [$id]);
                }
            }

            foreach ($emails as $email) {
                $cnx->executeStatement('DELETE FROM utilisateur WHERE email = ?', [$email]);
            }
        } finally {
            $cnx->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->em()->clear();
    }
}
