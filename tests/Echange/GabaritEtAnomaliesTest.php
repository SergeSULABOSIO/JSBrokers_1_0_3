<?php

namespace App\Tests\Echange;

use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Classeur\AnnotateurJsbx;
use App\Echange\Classeur\EcrivainJsbx;
use App\Echange\Service\ExportateurJsbx;
use App\Echange\Service\ImportateurJsbx;
use App\Entity\Client;
use App\Entity\EchangeImportRun;
use App\Entity\EchangeOccurrence;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LE GABARIT VIERGE, LE FICHIER ANNOTÉ, ET LE CHOIX DE CE QU'ON IMPORTE.
 *
 * Trois manques que l'usage a fait apparaître ensemble : on ne savait pas quoi faire
 * d'une anomalie, on ne pouvait pas n'importer qu'une partie du fichier, et il n'existait
 * aucun classeur vierge pour préparer des données hors ligne.
 */
class GabaritEtAnomaliesTest extends WebTestCase
{
    use ClasseurDeRepriseTrait;

    private const OWNER_EMAIL = 'phpunit-echange-gab@test.local';
    private const ENT = 'PHPUnit Gabarit SARL';

    /** @var string[] */
    private array $temporaires = [];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaires as $chemin) {
            @unlink($chemin);
        }
        $this->temporaires = [];
        $this->effacerLesClasseurs();
        $this->nettoyer();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le gabarit vierge
    // ─────────────────────────────────────────────────────────────────────────────

    /** Même structure qu'un export, et pas une seule ligne de données. */
    public function testLeGabaritALaMemeStructureEtAucuneDonnee(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME Qui Ne Doit Pas Sortir');

        $classeur = $this->gabarit($entreprise, $proprietaire, ['Client']);

        self::assertNotNull($classeur->getSheetByName(EcrivainJsbx::FEUILLE_MANIFESTE));
        self::assertNotNull($classeur->getSheetByName(EcrivainJsbx::FEUILLE_DICTIONNAIRE));
        self::assertNotNull($classeur->getSheetByName(EcrivainJsbx::FEUILLE_LISTES));

        $ressource = $this->service(CanevasDEchange::class)->ressource('Client');
        $feuille = $classeur->getSheetByName($ressource->feuille);
        self::assertNotNull($feuille, 'La feuille des clients doit exister, même vide.');

        // Les deux lignes d'en-tête sont là : c'est ce qui fait du gabarit un fichier
        // relisible, et non une feuille blanche.
        $codes = $feuille->rangeToArray('A2:' . $feuille->getHighestColumn() . '2', null, false, false, false)[0];
        self::assertContains(CanevasDEchange::COL_UID, $codes);
        self::assertContains('nom', $codes);

        // ⚠ ET AUCUNE DONNÉE DU CABINET. Le client existe en base : il ne doit pas être là.
        $lettreNom = $this->lettreDuCode($feuille, 'nom');
        for ($ligne = 3; $ligne <= max(3, $feuille->getHighestDataRow()); ++$ligne) {
            self::assertSame(
                '',
                trim((string) $feuille->getCell($lettreNom . $ligne)->getValue()),
                sprintf('La ligne %d du gabarit porte une donnée : il doit être vierge.', $ligne),
            );
        }
    }

    /**
     * ⚠ LE GABARIT NE COÛTE RIEN ET NE COMPTE POUR RIEN.
     *
     * Il ne contient aucune donnée du cabinet : le facturer reviendrait à faire payer la
     * documentation du format, et à décourager le seul geste qui évite les fichiers
     * reconstruits à la main — lesquels finissent refusés.
     */
    /**
     * ⚠ LA RESTRICTION TRAVERSE JUSQU'AU FICHIER.
     *
     * C'est tout l'objet du chantier : qui prépare de la production hors ligne recevait
     * un classeur de quarante-cinq feuilles dont trente-neuf ne le concernaient pas. Il
     * devait deviner lesquelles remplir — et le risque était d'en effacer une dont
     * l'import a besoin.
     */
    public function testLeGabaritRestreintNePorteQueLesFeuillesDemandees(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $classeur = $this->gabarit($entreprise, $proprietaire, ['Client']);
        $canevas = $this->service(CanevasDEchange::class);

        self::assertNotNull(
            $classeur->getSheetByName($canevas->ressource('Client')->feuille),
            'La feuille demandée doit être là.',
        );
        self::assertNull(
            $classeur->getSheetByName($canevas->ressource('Taxe')->feuille),
            'Une donnée non demandée ne doit pas encombrer le gabarit.',
        );
    }

    /**
     * ⚠ MAIS ELLE RESTE FERMÉE SUR SES DÉPENDANCES.
     *
     * Une police a besoin de son client. Un gabarit qui porterait la feuille des polices
     * sans celle des clients produirait, une fois rempli, un fichier renvoyant vers des
     * lignes absentes — refusé au contrôle, après le travail de saisie. C'est le pire
     * moment pour l'apprendre.
     */
    public function testLeGabaritRestreintTireCeQueSesDonneesAppellent(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $canevas = $this->service(CanevasDEchange::class);
        $classeur = $this->gabarit($entreprise, $proprietaire, ['Avenant']);

        foreach ($canevas->ressource('Avenant')->dependances as $dep) {
            self::assertNotNull(
                $classeur->getSheetByName($canevas->ressource($dep)->feuille),
                sprintf('Une police a besoin de « %s » : sa feuille doit suivre.', $dep),
            );
        }
    }

    /**
     * Le manifeste d'un gabarit restreint annonce le périmètre RÉDUIT.
     *
     * C'est ce qui permet, trois mois plus tard, de savoir ce que contient le fichier
     * qu'on retrouve sur son bureau — sans avoir à ouvrir chaque onglet.
     */
    public function testLeManifesteDUnGabaritRestreintAnnonceSonPerimetre(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $classeur = $this->gabarit($entreprise, $proprietaire, ['Client']);
        $feuille = $classeur->getSheetByName(EcrivainJsbx::FEUILLE_MANIFESTE);
        self::assertNotNull($feuille);

        $texte = '';
        foreach ($feuille->toArray(null, false, false, false) as $ligne) {
            $texte .= implode(' | ', array_map(static fn ($v) => (string) $v, $ligne)) . "
";
        }

        self::assertStringContainsString('Client', $texte);
        self::assertStringNotContainsString('Taxe', $texte, 'Le manifeste annoncerait une feuille absente.');
    }

    /** ⚠ Gratuit, et donc jamais compté : le vérifier deux fois de suite le prouve. */
    public function testLeGabaritNeDecompteAucuneOccurrence(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $avant = $this->compterOccurrences($entreprise);
        $this->gabarit($entreprise, $proprietaire, ['Client']);
        $this->gabarit($entreprise, $proprietaire, ['Client']);

        self::assertSame($avant, $this->compterOccurrences($entreprise), 'Un gabarit ne consomme pas d\'occurrence.');
    }

    /**
     * ⚠ UN GABARIT VIERGE DÉPOSÉ TEL QUEL EST REFUSÉ, et c'est un service rendu.
     *
     * Il ne contient aucune ligne : l'accepter en annonçant « zéro création » laisserait
     * croire à un import réussi, et l'utilisateur chercherait ses données à l'écran. Le
     * refus, lui, dit ce qui manque — une ligne par échéance.
     */
    public function testUnGabaritViergeDeposeTelQuelEstRefuse(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $run = $this->importateur()->controler(
            $this->gabaritDeReprise($entreprise, $proprietaire),
            'gabarit.xlsx',
            $entreprise,
            $proprietaire,
        );

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertStringContainsString('aucune ligne', $this->motifs($run));
    }

    /**
     * ⚠ LE GABARIT QUE LA RUBRIQUE DISTRIBUE SE REDÉPOSE — c'est tout son objet.
     *
     * Ce test part du fichier RÉELLEMENT produit, pas d'un classeur fabriqué pour
     * l'occasion : si ce que le cabinet télécharge ne se relisait pas, le seul geste qui
     * rend une première reprise possible conduirait à un refus.
     */
    public function testUnGabaritRempliCreeLesLignesSaisies(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->gabaritDeReprise($entreprise, $proprietaire);
        $this->remplirLeGabarit($chemin, $entreprise, [[
            'policeReference' => 'POL/2026/077',
            'policeDateEffet' => '01/01/2026',
            'policeEcheance' => '31/12/2026',
            'trancheNom' => 'Prime unique',
            'tranchePayableAt' => '15/01/2026',
            'assure' => 'Client Préparé Hors Ligne',
            'risque' => 'RC Aviation',
            'assureur' => 'SFA CONGO',
        ]]);

        $run = $this->importateur()->controler($chemin, 'gabarit-rempli.xlsx', $entreprise, $proprietaire);
        self::assertTrue($run->estConfirmable(), $this->motifs($run));
        self::assertGreaterThan(0, $run->getRapport()['creations']);

        $this->importateur()->executer($run, $entreprise->getUtilisateur());

        self::assertNotNull(
            $this->em()->getRepository(Client::class)->findOneBy(['nom' => 'Client Préparé Hors Ligne']),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le fichier annoté
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * LA CELLULE FAUTIVE EST MONTRÉE DU DOIGT — couleur et commentaire, à l'endroit exact.
     *
     * C'est ce qui transforme un constat d'échec en marche à suivre : l'utilisateur
     * corrige dans le fichier qu'il connaît, là où on lui a pointé le problème.
     */
    public function testLaCelluleFautiveEstSurligneeEtCommentee(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        // Une ligne sans référence de police : rien ne permet de la rattacher, et le
        // refus vise la cellule qui manque.
        $chemin = $this->classeurDeReprise($entreprise, [[
            'assure' => 'Client Orphelin',
            'trancheNom' => 'Prime unique',
        ]]);

        $run = $this->importateur()->controler($chemin, 'fautif.xlsx', $entreprise, $proprietaire);
        self::assertFalse($run->getRapport()['confirmable'], 'Le test suppose une anomalie.');

        $anomalie = $this->premiereAnomalieSituee($run);
        self::assertNotNull($anomalie, 'Il faut une anomalie située pour éprouver l\'annotation.');

        $annote = $this->service(AnnotateurJsbx::class)->annoter($run->getCheminFichier(), $run->getRapport());

        $feuille = $annote->getSheetByName($anomalie['feuille']);
        self::assertNotNull($feuille);
        $cellule = $anomalie['colonne'] . $anomalie['ligne'];

        self::assertSame(
            'FFF8D7DA',
            $feuille->getStyle($cellule)->getFill()->getStartColor()->getARGB(),
            'La cellule en erreur doit porter le fond de la charte.',
        );
        self::assertStringContainsString(
            'À corriger',
            (string) $feuille->getComment($cellule)->getText(),
            'Et un commentaire qui dit quoi faire.',
        );
    }

    /** Le rapport voyage AVEC le fichier, en première feuille. */
    public function testLeClasseurAnnotePorteSaFeuilleDeRapport(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Groupe', 'Client']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Perdu', 'groupe' => 'Néant']);
        $run = $this->importateur()->controler($chemin, 'rapport.xlsx', $entreprise, $proprietaire);

        $annote = $this->service(AnnotateurJsbx::class)->annoter($run->getCheminFichier(), $run->getRapport());
        $rapport = $annote->getSheetByName(EcrivainJsbx::FEUILLE_RAPPORT);

        self::assertNotNull($rapport, 'Le classeur annoté doit porter sa feuille de rapport.');
        self::assertSame(0, $annote->getIndex($rapport), 'Elle vient en tête : c\'est ce qu\'on ouvre.');

        $texte = implode(' ', array_map(
            static fn (array $ligne) => implode(' ', array_map('strval', $ligne)),
            $rapport->toArray(null, true, false, false),
        ));
        self::assertStringContainsString('Rapport de contrôle', $texte);
        self::assertStringContainsString('Ce qu\'il faut corriger', $texte);
    }

    /**
     * ⚠ LE DÉPÔT D'ORIGINE RESTE INTACT.
     *
     * C'est lui que la confirmation relira. L'annoter ferait diverger ce qui a été
     * contrôlé de ce qui sera écrit — l'utilisateur validerait un rapport portant sur un
     * fichier qui n'existe plus.
     */
    public function testLAnnotationNeTouchePasAuDepot(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Groupe', 'Client']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Témoin', 'groupe' => 'Néant']);
        $run = $this->importateur()->controler($chemin, 'temoin.xlsx', $entreprise, $proprietaire);

        $depot = (string) $run->getCheminFichier();
        $avant = hash_file('sha256', $depot);

        $this->service(AnnotateurJsbx::class)->annoter($depot, $run->getRapport());

        self::assertSame($avant, hash_file('sha256', $depot), 'Le dépôt a été modifié : il ne doit jamais l\'être.');
    }

    /**
     * Une anomalie SANS adresse — fichier illisible, manifeste absent — n'a aucune cellule
     * à colorer. Elle doit figurer au rapport plutôt que disparaître.
     */
    public function testUneAnomalieSansAdresseFigureAuRapport(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        // Un classeur sans manifeste : le refus porte sur le fichier entier.
        $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $classeur->getActiveSheet()->setTitle('Feuille1')->setCellValue('A1', 'Bonjour');
        $chemin = $this->ecrire($classeur);

        $run = $this->importateur()->controler($chemin, 'sans-manifeste.xlsx', $entreprise, $proprietaire);
        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());

        $annote = $this->service(AnnotateurJsbx::class)->annoter($run->getCheminFichier(), $run->getRapport());
        $rapport = $annote->getSheetByName(EcrivainJsbx::FEUILLE_RAPPORT);

        $texte = implode(' ', array_map(
            static fn (array $ligne) => implode(' ', array_map('strval', $ligne)),
            $rapport->toArray(null, true, false, false),
        ));
        self::assertStringContainsString('ne vise aucune cellule', $texte);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Outillage
    // ─────────────────────────────────────────────────────────────────────────────

    private function importateur(): ImportateurJsbx
    {
        return $this->service(ImportateurJsbx::class);
    }

    private function canevas(): CanevasDEchange
    {
        return $this->service(CanevasDEchange::class);
    }

    /** @template T @param class-string<T> $classe @return T */
    private function service(string $classe): object
    {
        return static::getContainer()->get($classe);
    }

    private function gabarit(Entreprise $entreprise, Invite $invite, array $codes): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $exportateur = $this->service(ExportateurJsbx::class);
        [$classeur] = $exportateur->produire(
            $entreprise,
            $invite,
            $entreprise->getUtilisateur(),
            $exportateur->perimetre($invite, $codes),
            null,
            gabarit: true,
        );

        return $classeur;
    }

    /**
     * LE CLASSEUR DE REPRISE VIERGE, tel que la rubrique le distribue.
     *
     * ⚠ IL PASSE PAR `produire()` ET NON PAR `exporter()`, comme la route qui le sert :
     * le gabarit n'est jamais facturé ni décompté — il ne contient rien du cabinet.
     */
    private function gabaritDeReprise(Entreprise $entreprise, Invite $invite): string
    {
        [$classeur] = $this->service(\App\Echange\Etat\ProducteurDeLEtat::class)->produire(
            $entreprise,
            $invite,
            $entreprise->getUtilisateur(),
            [],
            '',
            \App\Echange\Etat\ExerciceDesTranches::TOUS,
            gabarit: true,
        );

        return $this->ecrire($classeur);
    }

    private function exporter(Entreprise $entreprise, Invite $invite, array $codes): string
    {
        $reponse = $this->service(ExportateurJsbx::class)
            ->exporter($entreprise, $invite, $entreprise->getUtilisateur(), $codes, uniqid('g', true));

        ob_start();
        $reponse->sendContent();

        return $this->fichierTemporaire((string) ob_get_clean());
    }

    private function ecrire(\PhpOffice\PhpSpreadsheet\Spreadsheet $classeur): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'jsbx_gab_') . '.xlsx';
        (new Xlsx($classeur))->save($chemin);
        $this->temporaires[] = $chemin;

        return $chemin;
    }

    private function fichierTemporaire(string $contenu): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'jsbx_gab_') . '.xlsx';
        file_put_contents($chemin, $contenu);
        $this->temporaires[] = $chemin;

        return $chemin;
    }

    private function ajouterLigne(string $chemin, string $codeRessource, array $valeurs, ?string $repere = null): void
    {
        $classeur = IOFactory::load($chemin);
        $ressource = $this->canevas()->ressource($codeRessource);
        $feuille = $classeur->getSheetByName($ressource->feuille);

        $lettres = $this->lettresParCode($feuille);
        $numero = max(3, $feuille->getHighestDataRow() + 1);

        if ($repere !== null) {
            $feuille->setCellValue($lettres[CanevasDEchange::COL_REF] . $numero, $repere);
        }
        foreach ($valeurs as $code => $valeur) {
            if (isset($lettres[$code])) {
                $feuille->setCellValue($lettres[$code] . $numero, $valeur);
            }
        }

        (new Xlsx($classeur))->save($chemin);
    }

    /** @return array<string, string> */
    private function lettresParCode($feuille): array
    {
        $lettres = [];
        $derniere = Coordinate::columnIndexFromString($feuille->getHighestDataColumn());
        for ($i = 1; $i <= $derniere; ++$i) {
            $lettre = Coordinate::stringFromColumnIndex($i);
            $code = trim((string) $feuille->getCell($lettre . '2')->getValue());
            if ($code !== '') {
                $lettres[$code] = $lettre;
            }
        }

        return $lettres;
    }

    private function lettreDuCode($feuille, string $code): string
    {
        return $this->lettresParCode($feuille)[$code] ?? 'A';
    }

    /** @return array<string, mixed>|null */
    private function premiereAnomalieSituee(EchangeImportRun $run): ?array
    {
        foreach ($run->getRapport()['anomalies'] ?? [] as $anomalie) {
            if (($anomalie['feuille'] ?? null) && ($anomalie['ligne'] ?? null) && ($anomalie['colonne'] ?? null)) {
                return $anomalie;
            }
        }

        return null;
    }

    private function motifs(EchangeImportRun $run): string
    {
        return json_encode(array_column($run->getRapport()['anomalies'] ?? [], 'message'), JSON_UNESCAPED_UNICODE);
    }

    private function compterOccurrences(Entreprise $entreprise): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->select('COUNT(o.id)')->from(EchangeOccurrence::class, 'o')
            ->andWhere('o.entreprise = :e')->setParameter('e', $entreprise)
            ->getQuery()->getSingleScalarResult();
    }

    private function creerClient(Entreprise $entreprise, Invite $invite, string $nom): Client
    {
        $client = (new Client())->setNom($nom);
        $client->setEntreprise($entreprise);
        $client->setInvite($invite);
        $this->em()->persist($client);
        $this->em()->flush();

        return $client;
    }

    /** @return array{0: Entreprise, 1: Invite} */
    private function fixture(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Gab')->setVerified(true)->setPassword('x');
        $owner->setPaidTokens(500000);
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);
        $em->flush();

        $proprietaire = (new Invite())->setNom('Le Patron')->setEmail(self::OWNER_EMAIL);
        $proprietaire->setProprietaire(true);
        $proprietaire->setEntreprise($entreprise);
        $proprietaire->setUtilisateur($owner);
        $em->persist($proprietaire);

        $admin = (new RolesEnAdministration())->setNom('Admin');
        $admin->setAccessEchange([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);
        $admin->setEntreprise($entreprise);
        $admin->setInvite($proprietaire);
        $em->persist($admin);
        $proprietaire->addRolesEnAdministration($admin);

        $em->flush();

        // Session ouverte : les listes de choix des formulaires filtrent sur le cabinet
        // actif, et sans elles aucun renvoi ne serait accepté.
        $this->client->loginUser($owner);

        return [$entreprise, $proprietaire];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Purge dérivée du schéma — cf. ExportJsbxTest, même raison. */
    private function nettoyer(): void
    {
        $cnx = $this->em()->getConnection();
        $ids = $cnx->fetchFirstColumn('SELECT id FROM entreprise WHERE nom = ?', [self::ENT]);

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
                        $sql = $enfant['TABLE_NAME'] === 'utilisateur'
                            ? sprintf('UPDATE `%s` SET `%s` = NULL WHERE `%s` = ?', $enfant['TABLE_NAME'], $enfant['COLUMN_NAME'], $enfant['COLUMN_NAME'])
                            : sprintf('DELETE FROM `%s` WHERE `%s` = ?', $enfant['TABLE_NAME'], $enfant['COLUMN_NAME']);
                        $cnx->executeStatement($sql, [$id]);
                    }
                    $cnx->executeStatement('DELETE FROM entreprise WHERE id = ?', [$id]);
                }
            }
            $cnx->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::OWNER_EMAIL]);
        } finally {
            $cnx->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->em()->clear();
    }
}
