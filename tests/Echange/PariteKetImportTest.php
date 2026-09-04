<?php

namespace App\Tests\Echange;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\EchangeConsulterTool;
use App\Ai\Tool\EchangeExporterTool;
use App\Ai\Tool\EchangeImporterTool;
use App\Ai\Trousse\AiToolEcriture;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Echange\Service\ExportateurJsbx;
use App\Echange\Service\ImportateurJsbx;
use App\Entity\AssistantConversation;
use App\Entity\Client;
use App\Entity\EchangeImportRun;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PARITÉ KET — et la limite qui ne doit jamais bouger.
 *
 * L'exigence est double, et les deux moitiés comptent autant :
 *
 *  1. KET SAIT TOUT DIRE ET TOUT FAIRE de la rubrique, dans la limite EXACTE des droits
 *     de l'utilisateur. Une capacité présente à l'écran et absente du chat est un
 *     défaut, au même titre qu'un test rouge.
 *
 *  2. KET NE CONFIRME JAMAIS UNE IMPORTATION. Pas « en principe », pas « sauf si
 *     l'utilisateur insiste » : l'outil n'a AUCUN chemin qui écrive. C'est une propriété
 *     de structure, pas une consigne de prompt — et c'est ce que ce fichier vérifie.
 */
class PariteKetImportTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-echange-parite@test.local';
    private const ENT = 'PHPUnit Parité SARL';

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
        $this->nettoyer();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La limite
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ LE TEST LE PLUS IMPORTANT DE CE FICHIER.
     *
     * Un contrôle est en attente, exécutable, et l'utilisateur insiste. Ket ne doit
     * RIEN écrire : il ouvre l'écran, et c'est tout. On le vérifie sur la base, pas sur
     * la réponse — la seule preuve qui compte est qu'aucune ligne n'a bougé.
     */
    public function testKetNeConfirmeJamaisUneImportationMemeSurDemandeInsistante(): void
    {
        [$entreprise, $proprietaire, $conversation] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ajouterLigneClient($chemin, 'Client Que Ket Ne Doit Pas Creer');

        $scope = new AiScope($entreprise, $proprietaire, $conversation);

        // Le contrôle est posé PAR KET, sur le circuit qu'il emprunte réellement.
        $controle = $this->outilImport()->execute(
            ['etape' => 'controle', 'idFichier' => $this->joindre($conversation, $chemin)],
            $scope,
        );
        self::assertSame('OK', $controle->status, json_encode($controle->data, JSON_UNESCAPED_UNICODE));
        self::assertTrue($controle->data['confirmable'], 'Le contrôle doit être exécutable, sinon le test ne prouve rien.');

        $run = $this->em()->getRepository(EchangeImportRun::class)->find($controle->data['idControle']);
        $avant = $this->compterClients($entreprise);

        // Toutes les formulations, y compris celles qui sonnent comme un ordre.
        foreach ([
            ['etape' => 'confirmation'],
            ['etape' => 'confirmation', 'autoriserSuppressions' => true],
            ['etape' => 'CONFIRMATION'],
        ] as $arguments) {
            $resultat = $this->outilImport()->execute($arguments, $scope);
            self::assertSame('OK', $resultat->status);
            self::assertSame(
                $avant,
                $this->compterClients($entreprise),
                'Ket a écrit en base : l\'étape « confirmation » ne doit ouvrir qu\'un écran.',
            );
        }

        // Et le contrôle est toujours en attente : rien n'a été consommé.
        $this->em()->clear();
        $relu = $this->em()->getRepository(EchangeImportRun::class)->find($run->getId());
        self::assertSame(EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION, $relu->getStatut());
    }

    /** L'étape « confirmation » renvoie vers l'écran, et le dit explicitement au modèle. */
    public function testLEtapeDeConfirmationRenvoieVersLEcranEtLeDit(): void
    {
        [$entreprise, $proprietaire, $conversation] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ajouterLigneClient($chemin, 'Client En Attente');
        $this->importateur()->controler($chemin, 'ecran.xlsx', $entreprise, $proprietaire);

        $resultat = $this->outilImport()->execute(
            ['etape' => 'confirmation'],
            new AiScope($entreprise, $proprietaire, $conversation),
        );

        self::assertTrue($resultat->data['pret']);
        self::assertNotNull($resultat->uiAction, 'L\'écran doit s\'ouvrir chez l\'utilisateur.');
        self::assertSame('open-url', $resultat->uiAction['type']);
        self::assertStringContainsString('onglet=importer', $resultat->uiAction['url']);
        // La note doit dire au modèle que la validation appartient à l'utilisateur :
        // sans cela il annoncerait volontiers « c'est importé ».
        self::assertStringContainsString('à lui de cliquer', $resultat->data['note']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les capacités
    // ─────────────────────────────────────────────────────────────────────────────

    /** Le contrôle par Ket rend le MÊME verdict que l'écran, et ne touche à rien. */
    public function testLeControleParKetEstGratuitEtIdentiqueALEcran(): void
    {
        [$entreprise, $proprietaire, $conversation] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ajouterLigneClient($chemin, 'Client Contrôlé');

        // L'écran, d'abord.
        $parLEcran = $this->importateur()->controler($chemin, 'ecran.xlsx', $entreprise, $proprietaire);
        $avant = $this->compterClients($entreprise);

        self::assertTrue($parLEcran->estConfirmable());
        self::assertSame(1, $parLEcran->getRapport()['creations']);
        self::assertSame($avant, $this->compterClients($entreprise), 'Un contrôle n\'écrit rien.');

        // Et Ket, sur le même fichier joint.
        $resultat = $this->outilImport()->execute(
            ['etape' => 'controle', 'idFichier' => $this->joindre($conversation, $chemin)],
            new AiScope($entreprise, $proprietaire, $conversation),
        );

        self::assertSame('OK', $resultat->status);
        self::assertTrue($resultat->data['confirmable'], json_encode($resultat->data['anomalies'], JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $resultat->data['creations'], 'Ket doit annoncer la même création que l\'écran.');
        self::assertSame($avant, $this->compterClients($entreprise), 'Le contrôle de Ket n\'écrit rien non plus.');
    }

    /** Sans droit d'ÉCRITURE, l'import est refusé à Ket comme à l'écran. */
    public function testSansDroitDEcritureKetRefuseLImport(): void
    {
        [$entreprise, , $conversation, $lecteurSeul] = $this->fixture();

        $resultat = $this->outilImport()->execute(
            ['etape' => 'controle'],
            new AiScope($entreprise, $lecteurSeul, $conversation),
        );

        self::assertSame('HORS_PERIMETRE', $resultat->status, 'La lecture de la rubrique ne suffit pas à importer.');
    }

    /** L'annulation est gratuite et sans effet sur les données. */
    public function testKetPeutAnnulerUnControleEnAttente(): void
    {
        [$entreprise, $proprietaire, $conversation] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ajouterLigneClient($chemin, 'Client Abandonné');
        $run = $this->importateur()->controler($chemin, 'abandon.xlsx', $entreprise, $proprietaire);

        $avant = $this->compterClients($entreprise);
        $resultat = $this->outilImport()->execute(
            ['etape' => 'annulation'],
            new AiScope($entreprise, $proprietaire, $conversation),
        );

        self::assertTrue($resultat->data['annule']);
        self::assertSame($avant, $this->compterClients($entreprise));

        $this->em()->clear();
        $relu = $this->em()->getRepository(EchangeImportRun::class)->find($run->getId());
        self::assertSame(EchangeImportRun::STATUT_ANNULE, $relu->getStatut());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le contrat de trousse
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Les trois outils sont dans les trousses attendues : lecture pour consulter et
     * exporter (« exporte-moi ça » suit presque toujours une lecture), écriture pour
     * importer.
     */
    public function testLesTroisOutilsSontDansLesBonnesTrousses(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $catalogue = static::getContainer()->get(TrousseCatalogue::class);
        $scope = new AiScope($entreprise, $proprietaire, null);

        $lecture = $catalogue->nomsDe(Trousse::LECTURE, $scope);
        self::assertContains('echange_consulter', $lecture, 'Consulter doit être disponible en lecture.');
        self::assertContains('echange_exporter', $lecture, 'Exporter aussi : il ne mute rien.');
        self::assertNotContains('echange_importer', $lecture, 'Importer est un outil d\'écriture.');

        self::assertInstanceOf(
            AiToolEcriture::class,
            $this->outilImport(),
            'L\'outil d\'import doit porter le marqueur d\'écriture.',
        );
    }

    /** L'outil d'import ne se déclare que si un fichier est joint : sinon il coûterait pour rien. */
    public function testLOutilDImportNeSeDeclareQueSiUnFichierEstJoint(): void
    {
        [$entreprise, $proprietaire, $conversation] = $this->fixture();

        self::assertFalse(
            $this->outilImport()->estDisponible(new AiScope($entreprise, $proprietaire, $conversation)),
            'Sans pièce jointe, l\'outil n\'a rien à lire : il ne doit pas être déclaré.',
        );

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->joindre($conversation, $chemin);

        self::assertTrue(
            $this->outilImport()->estDisponible(new AiScope($entreprise, $proprietaire, $conversation)),
            'Dès qu\'un fichier est joint, l\'outil doit être proposé.',
        );
    }

    /** Les trois outils refusent à l'identique un invité sans accès à la rubrique. */
    public function testLesTroisOutilsRefusentUnInviteSansAcces(): void
    {
        [$entreprise, , $conversation, , $sansDroit] = $this->fixture();
        $scope = new AiScope($entreprise, $sansDroit, $conversation);

        foreach ([EchangeConsulterTool::class, EchangeExporterTool::class, EchangeImporterTool::class] as $classe) {
            $resultat = static::getContainer()->get($classe)->execute([], $scope);
            self::assertSame('HORS_PERIMETRE', $resultat->status, $classe . ' doit refuser.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Outillage
    // ─────────────────────────────────────────────────────────────────────────────

    private function outilImport(): EchangeImporterTool
    {
        return static::getContainer()->get(EchangeImporterTool::class);
    }

    private function importateur(): ImportateurJsbx
    {
        return static::getContainer()->get(ImportateurJsbx::class);
    }

    /**
     * Attache un fichier à la conversation, comme le ferait le dépôt du chat.
     *
     * Le fichier est COPIÉ là où Vich le cherchera : le résolveur passe par
     * `storage->resolvePath()`, et une entité qui pointe vers un fichier absent est
     * refusée — à juste titre, c'est exactement ce qu'on veut en production.
     */
    private function joindre(AssistantConversation $conversation, string $chemin): int
    {
        $nom = 'test-' . bin2hex(random_bytes(8)) . '.xlsx';
        $destination = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads/assistant';
        if (!is_dir($destination)) {
            mkdir($destination, 0775, true);
        }
        copy($chemin, $destination . '/' . $nom);
        $this->temporaires[] = $destination . '/' . $nom;

        $fichier = new \App\Entity\AssistantConversationFichier();
        $fichier->setNomOriginal(basename($chemin));
        $fichier->setNomFichierStocke($nom);
        $fichier->setMimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $fichier->setTaille((int) filesize($chemin));
        $fichier->setConversation($conversation);
        $conversation->addFichier($fichier);

        $this->em()->persist($fichier);
        $this->em()->flush();

        return (int) $fichier->getId();
    }

    private function exporter(Entreprise $entreprise, Invite $invite, array $codes): string
    {
        $reponse = static::getContainer()->get(ExportateurJsbx::class)
            ->exporter($entreprise, $invite, $entreprise->getUtilisateur(), $codes, uniqid('p', true));

        ob_start();
        $reponse->sendContent();
        $contenu = (string) ob_get_clean();

        $chemin = tempnam(sys_get_temp_dir(), 'jsbx_par_') . '.xlsx';
        file_put_contents($chemin, $contenu);
        $this->temporaires[] = $chemin;

        return $chemin;
    }

    private function ajouterLigneClient(string $chemin, string $nom): void
    {
        $classeur = \PhpOffice\PhpSpreadsheet\IOFactory::load($chemin);
        $ressource = static::getContainer()->get(\App\Echange\Canevas\CanevasDEchange::class)->ressource('Client');
        $feuille = $classeur->getSheetByName($ressource->feuille);

        $derniere = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($feuille->getHighestDataColumn());
        $numero = max(3, $feuille->getHighestDataRow() + 1);
        for ($i = 1; $i <= $derniere; ++$i) {
            $lettre = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            if (trim((string) $feuille->getCell($lettre . '2')->getValue()) === 'nom') {
                $feuille->setCellValue($lettre . $numero, $nom);
                break;
            }
        }

        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))->save($chemin);
    }

    private function compterClients(Entreprise $entreprise): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->select('COUNT(c.id)')->from(Client::class, 'c')
            ->andWhere('c.entreprise = :e')->setParameter('e', $entreprise)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Cabinet, propriétaire, conversation, invité en LECTURE SEULE sur la rubrique, et
     * invité sans aucun accès.
     *
     * @return array{0: Entreprise, 1: Invite, 2: AssistantConversation, 3: Invite, 4: Invite}
     */
    private function fixture(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Parité')->setVerified(true)->setPassword('x');
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

        // Lecture seule sur la rubrique : il consulte et exporte, mais n'importe pas.
        $lecteur = (new Invite())->setNom('Lecteur')->setEmail('phpunit-echange-parite-lect@test.local');
        $lecteur->setProprietaire(false);
        $lecteur->setEntreprise($entreprise);
        $em->persist($lecteur);

        $roleLecteur = (new RolesEnAdministration())->setNom('Admin lecteur');
        $roleLecteur->setAccessEchange([Invite::ACCESS_LECTURE]);
        $roleLecteur->setEntreprise($entreprise);
        $roleLecteur->setInvite($lecteur);
        $em->persist($roleLecteur);
        $lecteur->addRolesEnAdministration($roleLecteur);

        $sansDroit = (new Invite())->setNom('Sans droit')->setEmail('phpunit-echange-parite-nul@test.local');
        $sansDroit->setProprietaire(false);
        $sansDroit->setEntreprise($entreprise);
        $em->persist($sansDroit);

        $conversation = new AssistantConversation();
        $conversation->setEntreprise($entreprise);
        $conversation->setInvite($proprietaire);
        $em->persist($conversation);

        $em->flush();

        $this->client->loginUser($owner);

        return [$entreprise, $proprietaire, $conversation, $lecteur, $sansDroit];
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
            foreach ([self::OWNER_EMAIL, 'phpunit-echange-parite-lect@test.local', 'phpunit-echange-parite-nul@test.local'] as $email) {
                $cnx->executeStatement('DELETE FROM utilisateur WHERE email = ?', [$email]);
            }
        } finally {
            $cnx->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->em()->clear();
    }
}
