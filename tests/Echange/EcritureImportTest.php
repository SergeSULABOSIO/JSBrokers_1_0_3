<?php

namespace App\Tests\Echange;

use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Service\Anomalie;
use App\Echange\Service\ExportateurJsbx;
use App\Echange\Service\ImportImpossibleException;
use App\Echange\Service\ImportateurJsbx;
use App\Entity\Client;
use App\Entity\EchangeImportRun;
use App\Entity\EchangeOccurrence;
use App\Entity\Entreprise;
use App\Entity\Groupe;
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
 * PASSE 3 — L'ÉCRITURE.
 *
 * Ce que ces tests tiennent :
 *
 *  1. L'ALLER-RETOUR EST FIDÈLE. Exporter, réimporter sans rien changer, et retrouver
 *     la base rigoureusement identique. C'est la promesse centrale de la rubrique ;
 *     tout le reste n'a d'intérêt que si celle-là tient.
 *  2. TOUT OU RIEN. Une erreur en cours de route ne laisse AUCUNE trace — ni ligne
 *     écrite, ni occurrence, ni débit.
 *  3. RIEN NE S'ÉCRIT SANS CONFIRMATION, et une confirmation ne vaut qu'une fois.
 */
class EcritureImportTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-echange-ecr@test.local';
    private const ENT = 'PHPUnit Écriture SARL';

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
    // 1. Fidélité de l'aller-retour
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * LE test de la rubrique : exporter, réimporter tel quel, et ne RIEN avoir changé.
     *
     * On compare les valeurs métier avant et après, ligne à ligne. Si ce test tombe, le
     * format perd de l'information quelque part — et un aller-retour qui abîme les
     * données est pire qu'un aller-retour qui n'existe pas.
     */
    public function testUnAllerRetourSansModificationLaisseLaBaseIdentique(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME Fidèle', 'acme@test.local');
        $this->creerClient($entreprise, $proprietaire, 'Beta Fidèle', 'beta@test.local');

        $avant = $this->photographierClients($entreprise);

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $run = $this->importateur()->controler($chemin, 'fidele.xlsx', $entreprise, $proprietaire);
        self::assertTrue($run->estConfirmable(), $this->motifs($run));

        $run = $this->importateur()->executer($run, $entreprise->getUtilisateur());
        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motifs($run));

        self::assertSame($avant, $this->photographierClients($entreprise), 'L\'aller-retour a modifié des données.');
    }

    /** Une valeur modifiée dans le fichier est bien reportée en base. */
    public function testUneValeurModifieeEstEcrite(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $client = $this->creerClient($entreprise, $proprietaire, 'ACME Avant', 'avant@test.local');

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ecrireCellule($chemin, 'Client', 'nom', 3, 'ACME Après');

        $run = $this->importateur()->controler($chemin, 'maj.xlsx', $entreprise, $proprietaire);
        self::assertTrue($run->estConfirmable(), $this->motifs($run));
        $this->importateur()->executer($run, $entreprise->getUtilisateur());

        $this->em()->clear();
        $relu = $this->em()->getRepository(Client::class)->find($client->getId());
        self::assertSame('ACME Après', $relu->getNom());
    }

    /** Une ligne ajoutée hors ligne devient un enregistrement réel. */
    public function testUneLigneAjouteeEstCreee(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Créé Hors Ligne']);

        $run = $this->importateur()->controler($chemin, 'creation.xlsx', $entreprise, $proprietaire);
        self::assertTrue($run->estConfirmable(), $this->motifs($run));
        $this->importateur()->executer($run, $entreprise->getUtilisateur());

        self::assertSame(1, $this->compterClients($entreprise));
        self::assertNotNull(
            $this->em()->getRepository(Client::class)->findOneBy(['nom' => 'Client Créé Hors Ligne']),
        );
    }

    /**
     * LA CASCADE : un groupe et le client qui le désigne, créés ensemble par un simple
     * repère local. C'est ce qui distingue un import d'un chargement de lignes isolées.
     */
    public function testUnGroupeEtSonClientSontCreesEtRelies(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Groupe', 'Client']);
        $this->ajouterLigne($chemin, 'Groupe', ['nom' => 'Groupe Cascade', 'description' => 'Créé par import'], 'G1');
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Cascade', 'groupe' => 'G1']);

        $run = $this->importateur()->controler($chemin, 'cascade.xlsx', $entreprise, $proprietaire);
        self::assertTrue($run->estConfirmable(), $this->motifs($run));
        $this->importateur()->executer($run, $entreprise->getUtilisateur());

        $this->em()->clear();
        $client = $this->em()->getRepository(Client::class)->findOneBy(['nom' => 'Client Cascade']);
        self::assertNotNull($client, 'Le client doit avoir été créé.');
        self::assertNotNull($client->getGroupe(), 'Et rattaché au groupe créé dans le même fichier.');
        self::assertSame('Groupe Cascade', $client->getGroupe()->getNom());
    }

    /** Une suppression explicite, autorisée au dépôt, retire bien la ligne. */
    public function testUneSuppressionAutoriseeEstExecutee(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME À Supprimer', 'sup@test.local');

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ecrireCellule($chemin, 'Client', CanevasDEchange::COL_ACTION, 3, CanevasDEchange::ACTION_SUPPRIMER);

        $run = $this->importateur()->controler($chemin, 'sup.xlsx', $entreprise, $proprietaire, true);
        self::assertTrue($run->estConfirmable(), $this->motifs($run));
        self::assertSame(1, $run->getRapport()['suppressions'], 'Le contrôle doit avoir vu la suppression.');

        $run = $this->importateur()->executer($run, $entreprise->getUtilisateur());
        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motifs($run));

        self::assertSame(0, $this->compterClients($entreprise));
    }

    /**
     * Une suppression NON autorisée au dépôt bloque l'écriture entière — elle n'est pas
     * silencieusement ignorée, sans quoi l'utilisateur croirait ses lignes supprimées.
     */
    public function testUneSuppressionNonAutoriseeBloqueToutLImport(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME Protégée', 'prot@test.local');

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ecrireCellule($chemin, 'Client', CanevasDEchange::COL_ACTION, 3, CanevasDEchange::ACTION_SUPPRIMER);

        // suppressionsAutorisees reste à false : c'est le défaut, et c'est voulu.
        $run = $this->importateur()->controler($chemin, 'sup-refusee.xlsx', $entreprise, $proprietaire);
        $run = $this->importateur()->executer($run, $entreprise->getUtilisateur());

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertSame(1, $this->compterClients($entreprise), 'La ligne doit être intacte.');
        self::assertNotNull($this->anomalieDeCode($run, Anomalie::SUPPRESSION_REFUSEE));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 2. Tout ou rien
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ATOMICITÉ : une ligne fautive en milieu de fichier laisse la base STRICTEMENT
     * inchangée — y compris les lignes valides qui la précédaient.
     *
     * Le fichier est validé au contrôle, puis abîmé juste avant la confirmation : c'est
     * exactement le cas que le recontrôle de la passe 3 doit rattraper.
     */
    public function testUneErreurEnCoursDeRouteLaisseLaBaseInchangee(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Groupe', 'Client']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Valide Un']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Valide Deux']);

        $run = $this->importateur()->controler($chemin, 'atomique.xlsx', $entreprise, $proprietaire);
        self::assertTrue($run->estConfirmable(), $this->motifs($run));

        // On casse la seconde ligne APRÈS le contrôle : son renvoi ne désigne plus rien.
        $this->ecrireCellule($chemin, 'Client', 'groupe', 4, 'Groupe Qui N Existe Pas');

        $avant = $this->compterClients($entreprise);
        $run = $this->importateur()->executer($run, $entreprise->getUtilisateur());

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertSame($avant, $this->compterClients($entreprise), 'Aucune ligne, même valide, ne doit subsister.');
    }

    /** Un import en échec ne laisse ni occurrence, ni débit. */
    public function testUnImportEnEchecNeCompteAucuneOccurrence(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Groupe', 'Client']);
        $occurrencesAvant = $this->compterOccurrences($entreprise, EchangeOccurrence::TYPE_IMPORT);

        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Cassé', 'groupe' => 'Inexistant']);
        $run = $this->importateur()->controler($chemin, 'echec.xlsx', $entreprise, $proprietaire);

        self::assertFalse($run->estConfirmable());
        self::assertSame(
            $occurrencesAvant,
            $this->compterOccurrences($entreprise, EchangeOccurrence::TYPE_IMPORT),
            'Un contrôle en échec ne compte rien.',
        );
    }

    /** Un import abouti laisse UNE occurrence, sans forfait. */
    public function testUnImportAboutiEstTraceSansForfait(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Tracé']);

        $run = $this->importateur()->controler($chemin, 'trace.xlsx', $entreprise, $proprietaire);
        $this->importateur()->executer($run, $entreprise->getUtilisateur());

        $occurrence = $this->em()->getRepository(EchangeOccurrence::class)
            ->findOneBy(['entreprise' => $entreprise, 'type' => EchangeOccurrence::TYPE_IMPORT]);

        self::assertNotNull($occurrence, 'Un import abouti doit laisser une trace.');
        self::assertSame(0, $occurrence->getTokensDebites(), 'L\'import ne porte aucun forfait.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 3. La confirmation
    // ─────────────────────────────────────────────────────────────────────────────

    /** Un contrôle déjà exécuté ne se rejoue pas : la confirmation ne vaut qu'une fois. */
    public function testUneConfirmationNeVautQuUneFois(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Unique']);

        $run = $this->importateur()->controler($chemin, 'unique.xlsx', $entreprise, $proprietaire);
        $this->importateur()->executer($run, $entreprise->getUtilisateur());
        self::assertSame(1, $this->compterClients($entreprise));

        $this->expectException(ImportImpossibleException::class);
        try {
            $this->importateur()->executer($run, $entreprise->getUtilisateur());
        } finally {
            self::assertSame(1, $this->compterClients($entreprise), 'Le rejeu ne doit rien créer de plus.');
        }
    }

    /** Un contrôle annulé ne s'exécute plus, et son dépôt disparaît du disque. */
    public function testUnControleAnnuleNeSExecutePlus(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Annulé']);

        $run = $this->importateur()->controler($chemin, 'annule.xlsx', $entreprise, $proprietaire);
        $depot = $run->getCheminFichier();
        self::assertNotNull($depot);

        $this->importateur()->annuler($run);

        self::assertSame(EchangeImportRun::STATUT_ANNULE, $run->getStatut());
        self::assertFileDoesNotExist($depot, 'Le dépôt doit être effacé : il porte des données personnelles.');

        $this->expectException(ImportImpossibleException::class);
        $this->importateur()->executer($run, $entreprise->getUtilisateur());
    }

    /** Un import abouti efface lui aussi son dépôt : les données sont en base, désormais. */
    public function testLeDepotEstEffaceApresUnImportAbouti(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Éphémère']);

        $run = $this->importateur()->controler($chemin, 'ephemere.xlsx', $entreprise, $proprietaire);
        $depot = $run->getCheminFichier();

        // On reprend l'instance RENVOYÉE : l'écriture repart d'une unité de travail
        // propre, et l'objet d'origine est détaché depuis. S'accrocher à l'ancien
        // reviendrait à lire un état d'avant l'exécution.
        $run = $this->importateur()->executer($run, $entreprise->getUtilisateur());

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motifs($run));
        self::assertFileDoesNotExist($depot, 'Le dépôt ne doit pas survivre à un import abouti.');
        self::assertNull($run->getCheminFichier(), 'Et le contrôle ne doit plus le désigner.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Outillage
    // ─────────────────────────────────────────────────────────────────────────────

    private function importateur(): ImportateurJsbx
    {
        return static::getContainer()->get(ImportateurJsbx::class);
    }

    private function canevas(): CanevasDEchange
    {
        return static::getContainer()->get(CanevasDEchange::class);
    }

    /**
     * Valeurs métier des clients, pour comparer avant/après un aller-retour.
     *
     * On ne vide PAS l'unité de travail ici : le cabinet et l'invité passés aux services
     * deviendraient des entités détachées, que le flush suivant prendrait pour des
     * créations. La lecture se fait en tableau, ce qui suffit à comparer sans rien
     * charger de plus.
     */
    private function photographierClients(Entreprise $entreprise): array
    {
        $lignes = $this->em()->createQueryBuilder()
            ->select('c.id, c.nom, c.email, c.telephone, c.adresse, c.exonere')
            ->from(Client::class, 'c')
            ->andWhere('c.entreprise = :e')->setParameter('e', $entreprise)
            ->orderBy('c.id', 'ASC')
            ->getQuery()->getArrayResult();

        return $lignes;
    }

    private function motifs(EchangeImportRun $run): string
    {
        return json_encode(
            array_column($run->getRapport()['anomalies'] ?? [], 'message'),
            JSON_UNESCAPED_UNICODE,
        );
    }

    /** @return array<string, mixed>|null */
    private function anomalieDeCode(EchangeImportRun $run, string $code): ?array
    {
        foreach ($run->getRapport()['anomalies'] ?? [] as $anomalie) {
            if (($anomalie['code'] ?? '') === $code) {
                return $anomalie;
            }
        }

        return null;
    }

    private function exporter(Entreprise $entreprise, Invite $invite, array $codes): string
    {
        $reponse = static::getContainer()->get(ExportateurJsbx::class)
            ->exporter($entreprise, $invite, $entreprise->getUtilisateur(), $codes, uniqid('e', true));

        ob_start();
        $reponse->sendContent();
        $contenu = (string) ob_get_clean();

        $chemin = tempnam(sys_get_temp_dir(), 'jsbx_ecr_') . '.xlsx';
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

    private function ecrireCellule(string $chemin, string $codeRessource, string $codeColonne, int $numero, string $valeur): void
    {
        $classeur = IOFactory::load($chemin);
        $ressource = $this->canevas()->ressource($codeRessource);
        $feuille = $classeur->getSheetByName($ressource->feuille);

        $lettres = $this->lettresParCode($feuille);
        $feuille->setCellValue($lettres[$codeColonne] . $numero, $valeur);

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

    private function compterClients(Entreprise $entreprise): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->select('COUNT(c.id)')->from(Client::class, 'c')
            ->andWhere('c.entreprise = :e')->setParameter('e', $entreprise)
            ->getQuery()->getSingleScalarResult();
    }

    private function compterOccurrences(Entreprise $entreprise, string $type): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->select('COUNT(o.id)')->from(EchangeOccurrence::class, 'o')
            ->andWhere('o.entreprise = :e')->andWhere('o.type = :t')
            ->setParameter('e', $entreprise)->setParameter('t', $type)
            ->getQuery()->getSingleScalarResult();
    }

    private function creerClient(Entreprise $entreprise, Invite $invite, string $nom, string $email): Client
    {
        $client = (new Client())->setNom($nom)->setEmail($email);
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

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Écr')->setVerified(true)->setPassword('x');
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
