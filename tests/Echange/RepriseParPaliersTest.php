<?php

namespace App\Tests\Echange;

use App\Echange\Etat\EtatDuPortefeuille;
use App\Echange\Service\AvanceurDImport;
use App\Echange\Service\ImportateurJsbx;
use App\Entity\EchangeImportRun;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\RolesEnProduction;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LA REPRISE AVANCE PAR PALIERS — et c'est ce qui la rend possible.
 *
 * ── CE QUE CES TESTS GARDENT ────────────────────────────────────────────────────────
 * Le contrôle à blanc soumet chaque ligne au circuit d'écriture commun, ce qui garantit
 * qu'un import obéit aux mêmes règles qu'une saisie — et retient environ sept mégaoctets
 * par ligne, sans les rendre. Tout faire dans une requête imposait donc un plafond
 * calculé sur la mémoire du serveur, qui tombait à une trentaine de lignes : la rubrique
 * refusait le seul cas pour lequel elle existe.
 *
 * Le travail se découpe désormais, et chaque palier repart d'un processus neuf.
 *
 * ⚠ TROIS PROMESSES SONT TESTÉES ICI, ET AUCUNE NE VA DE SOI :
 *   1. le travail avance vraiment palier par palier, jusqu'au bout ;
 *   2. un palier ne coupe JAMAIS une police en deux — ses échéances se chaînent par des
 *      repères qui ne survivent pas à la frontière ;
 *   3. redéposer le même classeur ne duplique rien, ce qui est la condition pour qu'un
 *      import interrompu puisse être relancé.
 */
class RepriseParPaliersTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-paliers@test.local';
    private const ENT = 'PHPUnit Paliers SARL';

    /**
     * ⚠ UN PALIER D'UNE SEULE LIGNE, ET C'EST VOULU.
     *
     * Le calcul ordinaire déduit le lot de `memory_limit` et rendrait une trentaine de
     * lignes : un fichier de six lignes tiendrait dans un palier, et l'on n'aurait rien
     * prouvé. À un, chaque police doit forcer l'extension de la fenêtre — c'est la
     * promesse n° 2, et elle ne s'observe qu'ici.
     */
    private const PALIER = 1;

    /** @var string[] fichiers temporaires à effacer */
    private array $temporaires = [];

    private KernelBrowser $client;

    private ?string $palierPrecedent = null;

    protected function setUp(): void
    {
        $this->palierPrecedent = $_ENV['IMPORT_PALIER'] ?? null;
        $_ENV['IMPORT_PALIER'] = (string) self::PALIER;
        putenv('IMPORT_PALIER=' . self::PALIER);

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

        if ($this->palierPrecedent === null) {
            unset($_ENV['IMPORT_PALIER']);
            putenv('IMPORT_PALIER');
        } else {
            $_ENV['IMPORT_PALIER'] = $this->palierPrecedent;
            putenv('IMPORT_PALIER=' . $this->palierPrecedent);
        }

        parent::tearDown();
    }

    /**
     * ⚠ LE DÉPÔT NE CONTRÔLE PLUS RIEN, ET C'EST LE POINT DE DÉPART.
     *
     * Il ouvre le dossier — ce fichier est un classeur de reprise, il vient de ce cabinet
     * — et rend la main. Tenir le contrôle entier dans la requête du dépôt est exactement
     * ce qui faisait mourir PHP au milieu, sur un message qui accusait la base.
     */
    public function testLeDepotOuvreLeTravailSansLeFaire(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $run = $this->importateur()->deposer(
            $this->classeur($entreprise, $this->troisPolices()),
            'reprise.xlsx',
            $entreprise,
            $invite,
        );

        self::assertSame(EchangeImportRun::STATUT_CONTROLE, $run->getStatut());
        self::assertSame(0, $run->getCurseur(), 'Rien n\'a encore été jugé.');
        self::assertFalse($run->estConfirmable(), 'Rien ne peut être confirmé avant le contrôle.');
    }

    /**
     * ⚠ UN PALIER NE COUPE JAMAIS UNE POLICE EN DEUX.
     *
     * Les deux échéances d'une police se chaînent par un repère « @étiquette » : la
     * seconde renvoie à la proposition que la première a créée, et ce repère vit le temps
     * d'un palier. Les séparer ferait échouer la seconde sur « renvoi inconnu » — un motif
     * qui parle du lien et non de la cause.
     *
     * Le palier vaut UNE ligne dans ce test : la fenêtre doit malgré tout en prendre DEUX.
     */
    public function testUnPalierSEtendJusquALaFinDeLaPolice(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $run = $this->importateur()->deposer(
            $this->classeur($entreprise, $this->troisPolices()),
            'reprise.xlsx',
            $entreprise,
            $invite,
        );

        self::assertSame(self::PALIER, $this->avanceur()->tailleDuPalier(), 'Le réglage doit être pris en compte.');

        $this->avanceur()->avancerUnPalier($run);

        self::assertSame(2, $run->getCurseur(), 'Les DEUX échéances de la première police, pas une.');
        self::assertSame(6, $run->getTotalLignes());
        self::assertSame(EchangeImportRun::STATUT_CONTROLE, $run->getStatut(), 'Le travail n\'est pas fini.');
        self::assertTrue($run->resteAFaire());
    }

    /**
     * LE CYCLE COMPLET : le contrôle s'achève palier par palier, l'écriture aussi, et le
     * portefeuille arrive en base.
     *
     * ⚠ ET IL FAUT PLUSIEURS PALIERS POUR Y ARRIVER. Le test compte les tours : s'il n'y
     * en avait qu'un, tout ce fichier ne prouverait rien de ce qu'il annonce.
     */
    public function testLeControlePuisLEcritureSAchevent(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $run = $this->importateur()->deposer(
            $this->classeur($entreprise, $this->troisPolices()),
            'reprise.xlsx',
            $entreprise,
            $invite,
        );

        $tours = $this->pousser($run);

        self::assertGreaterThan(1, $tours, 'Le contrôle doit avoir demandé plusieurs paliers.');
        self::assertSame(EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION, $run->getStatut(), $this->motif($run));
        self::assertTrue($run->estConfirmable());
        self::assertSame(6, $run->getRapport()['lignes_lues'] ?? 0);

        $run = $this->importateur()->demarrerLEcriture($run, $invite->getUtilisateur());
        self::assertSame(EchangeImportRun::STATUT_EN_COURS, $run->getStatut());
        self::assertSame(0, $run->getCurseur(), 'L\'écriture repart de zéro : ce n\'est plus la même phase.');

        $this->pousser($run);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));
        self::assertSame(
            ['polices' => 3, 'propositions' => 3, 'opportunites' => 3, 'echeances' => 6],
            $this->portefeuille($entreprise),
        );
        self::assertNull($run->getCheminFichier(), 'Le dépôt est effacé une fois les données en base.');
    }

    /**
     * ⚠ REDÉPOSER LE MÊME CLASSEUR NE DOIT RIEN AJOUTER.
     *
     * C'est la condition pour qu'un import interrompu puisse être relancé — et donc la
     * condition pour que l'écriture par paliers soit acceptable. Sans elle, un utilisateur
     * dont le palier douze a échoué n'aurait aucun moyen de terminer sans doubler les onze
     * premiers.
     */
    public function testRedeposerLeMemeClasseurNeDupliqueRien(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $idEntreprise = (int) $entreprise->getId();
        $idInvite = (int) $invite->getId();
        $lignes = $this->troisPolices();

        foreach ([1, 2] as $depot) {
            // ⚠ ON RECHARGE, comme le ferait une seconde requête. L'écriture vide l'unité
            // de travail pour repartir propre : les objets du premier dépôt sont détachés,
            // et les réutiliser tels quels ferait croire à Doctrine qu'on lui présente un
            // cabinet tout neuf.
            $entreprise = $this->em()->find(Entreprise::class, $idEntreprise);
            $invite = $this->em()->find(Invite::class, $idInvite);

            $run = $this->importateur()->deposer(
                $this->classeur($entreprise, $lignes),
                'reprise.xlsx',
                $entreprise,
                $invite,
            );
            $this->pousser($run);
            self::assertSame(
                EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION,
                $run->getStatut(),
                sprintf('Dépôt %d : %s', $depot, $this->motif($run)),
            );

            $run = $this->importateur()->demarrerLEcriture($run, $invite->getUtilisateur());
            $this->pousser($run);
            self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));
        }

        self::assertSame(
            ['polices' => 3, 'propositions' => 3, 'opportunites' => 3, 'echeances' => 6],
            $this->portefeuille($entreprise),
            'Le second dépôt a retrouvé tout ce qui existait, et n\'a rien empilé.',
        );
    }

    /**
     * ⚠ DEUX PALIERS NE TRAVAILLENT JAMAIS EN MÊME TEMPS SUR LE MÊME CONTRÔLE.
     *
     * Deux onglets ouverts, un double-clic, deux workers démarrés pour absorber une
     * charge : les deux paliers partiraient sur la même fenêtre de lignes, chacun dans sa
     * transaction, et chacun créerait « son » client. L'idempotence n'y pourrait rien —
     * elle ne voit que ce qui est COMMITÉ. On aurait fabriqué le doublon que toute cette
     * reprise existe pour empêcher.
     *
     * Celui qui n'obtient pas le verrou rend la main sans rien faire : ce n'est pas une
     * erreur, c'est un autre qui travaille.
     */
    public function testUnSecondPousseurNeTravaillePasEnMemeTemps(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $run = $this->importateur()->deposer(
            $this->classeur($entreprise, $this->troisPolices()),
            'reprise.xlsx',
            $entreprise,
            $invite,
        );
        $runs = static::getContainer()->get(\App\Repository\EchangeImportRunRepository::class);
        $idRun = (int) $run->getId();

        // Un premier pousseur tient le verrou — exactement ce que fait un palier en cours.
        self::assertTrue($runs->prendreLeTravail($idRun));
        self::assertFalse($runs->prendreLeTravail($idRun), 'Le verrou ne se prend pas deux fois.');

        $run = $this->avanceur()->avancerUnPalier($run);
        self::assertSame(0, $run->getCurseur(), 'Le second pousseur n\'a rien traité.');

        $runs->relacherLeTravail($idRun);
        $run = $this->avanceur()->avancerUnPalier($run);
        self::assertSame(2, $run->getCurseur(), 'Une fois le verrou rendu, le travail repart.');
    }

    /** Un classeur sans la moindre ligne se refuse, et dit quoi faire. */
    public function testUnClasseurSansLigneEstRefuse(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $run = $this->importateur()->deposer(
            $this->classeur($entreprise, []),
            'vide.xlsx',
            $entreprise,
            $invite,
        );
        $this->pousser($run);

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertStringContainsString('aucune ligne', $this->motif($run));
    }

    /**
     * LE PARCOURS DE L'ÉCRAN, PAR SES ROUTES — dépôt, paliers, confirmation, paliers.
     *
     * ⚠ CE QUE LES TESTS DE SERVICE NE PROUVENT PAS. Ils appellent le moteur en direct ;
     * l'écran, lui, passe par HTTP, avec ses droits, son fichier téléversé et ses réponses
     * JSON. Entre les deux il y a tout ce qui peut être mal branché : une route qui
     * n'existe pas, un périmètre mal vérifié, un état que le navigateur ne sait pas lire.
     *
     * ⚠ ET LE DÉPÔT NE DOIT RIEN CONTRÔLER. C'est la promesse qui a fait tout ce chantier :
     * la requête qui reçoit le fichier ouvre le dossier et rend la main. Si elle contrôlait
     * encore, elle mourrait sur un portefeuille réel — et ce test-ci passerait quand même,
     * faute de volume. On vérifie donc le STATUT rendu, pas seulement le succès.
     */
    public function testLEcranMeneLImportParSesRoutes(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $base = sprintf('/admin/echange/importer/%d', $entreprise->getId());

        $this->client->request('POST', $base, [], [
            'fichier' => new UploadedFile($this->classeur($entreprise, $this->troisPolices()), 'reprise.xlsx', null, null, true),
        ]);

        $etat = $this->charge();
        self::assertResponseIsSuccessful();
        self::assertSame(EchangeImportRun::STATUT_CONTROLE, $etat['statut'], 'Le dépôt ouvre le travail, il ne le fait pas.');
        self::assertTrue($etat['travaille']);
        self::assertFalse($etat['async'], 'Sans worker, c\'est le navigateur qui pousse.');

        $idRun = $etat['idRun'];
        $etat = $this->pousserParHttp($base, $idRun);

        self::assertSame(EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION, $etat['statut'], $this->motifDeLEtat($etat));
        self::assertTrue($etat['confirmable']);
        self::assertSame(6, $etat['total']);
        // ⚠ ÉGALITÉ ET NON IDENTITÉ : JSON rend « 100 » là où PHP tient un flottant rond.
        self::assertEquals(100.0, $etat['pct']);

        // L'état se relit sans travailler : c'est ce qui permet à l'écran de retrouver un
        // import après un rafraîchissement.
        $this->client->request('GET', sprintf('%s/%d/etat', $base, $idRun));
        self::assertResponseIsSuccessful();
        self::assertSame(EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION, $this->charge()['statut']);

        $this->client->request('POST', sprintf('%s/%d/confirmer', $base, $idRun));
        self::assertResponseIsSuccessful();
        self::assertSame(EchangeImportRun::STATUT_EN_COURS, $this->charge()['statut']);

        $etat = $this->pousserParHttp($base, $idRun);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $etat['statut'], $this->motifDeLEtat($etat));
        self::assertSame(
            ['polices' => 3, 'propositions' => 3, 'opportunites' => 3, 'echeances' => 6],
            $this->portefeuille($entreprise),
        );
    }

    /** Un fichier qui n'est pas un classeur est refusé au dépôt, sans rien coûter. */
    public function testUnDepotHorsFormatEstRefuseParLaRoute(): void
    {
        [$entreprise] = $this->fixture();

        $chemin = sys_get_temp_dir() . '/paliers-' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($chemin, 'pas un classeur');
        $this->temporaires[] = $chemin;

        $this->client->request('POST', sprintf('/admin/echange/importer/%d', $entreprise->getId()), [], [
            'fichier' => new UploadedFile($chemin, 'notes.txt', null, null, true),
        ]);

        self::assertResponseStatusCodeSame(415);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Outillage
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Pousse les paliers comme le ferait le navigateur, et rend le dernier état.
     *
     * @return array<string, mixed>
     */
    private function pousserParHttp(string $base, int $idRun): array
    {
        $etat = [];

        for ($tour = 0; $tour < 50; ++$tour) {
            $this->client->request('POST', sprintf('%s/%d/avancer', $base, $idRun));
            self::assertResponseIsSuccessful();
            $etat = $this->charge();

            if (!$etat['travaille']) {
                return $etat;
            }
        }

        self::fail('Le travail ne se termine pas : ' . $this->motifDeLEtat($etat));
    }

    /** @return array<string, mixed> */
    private function charge(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @param array<string, mixed> $etat */
    private function motifDeLEtat(array $etat): string
    {
        $messages = array_map(
            static fn (array $a): string => sprintf('[%s] %s', $a['gravite'] ?? '?', $a['message'] ?? ''),
            $etat['rapport']['anomalies'] ?? [],
        );

        return $messages === [] ? 'aucune anomalie signalée' : implode(' | ', $messages);
    }

    /**
     * Pousse le travail palier par palier, comme le feraient le worker ou le navigateur.
     *
     * @return int le nombre de paliers qu'il a fallu
     */
    private function pousser(EchangeImportRun &$run): int
    {
        $tours = 0;
        $avanceur = $this->avanceur();

        while (in_array($run->getStatut(), [EchangeImportRun::STATUT_CONTROLE, EchangeImportRun::STATUT_EN_COURS], true)) {
            $avant = $run->getCurseur();

            // ⚠ ON RÉASSIGNE, et ce n'est pas une coquetterie : un palier qui échoue rouvre
            // le gestionnaire que Doctrine a fermé et RECHARGE le contrôle. L'objet d'avant
            // est alors détaché, figé sur l'état d'avant l'échec — le garder ferait lire un
            // curseur qui n'a pas bougé et conclure à un blocage qui n'existe pas.
            $run = $avanceur->avancerUnPalier($run);
            ++$tours;

            if ($run->getCurseur() === $avant && $run->resteAFaire()) {
                self::fail('Un palier n\'a pas avancé : ' . $this->motif($run));
            }
            if ($tours > 50) {
                self::fail('Le travail ne se termine pas.');
            }
        }

        return $tours;
    }

    /** Trois polices de deux échéances chacune — six lignes, trois groupes. */
    private function troisPolices(): array
    {
        $lignes = [];
        foreach (['POL/2026/001', 'POL/2026/002', 'POL/2026/003'] as $rang => $reference) {
            foreach ([1, 2] as $echeance) {
                $lignes[] = [
                    'policeReference' => $reference,
                    'policeDateEffet' => '01/01/2026',
                    'policeEcheance' => '31/12/2026',
                    'trancheNom' => 'Échéance ' . $echeance,
                    'tranchePayableAt' => $echeance === 1 ? '15/01/2026' : '15/06/2026',
                    'tranchePart' => '50',
                    'assure' => 'Client ' . ($rang + 1),
                    'risque' => 'RC Aviation',
                    'assureur' => 'SFA CONGO',
                ];
            }
        }

        return $lignes;
    }

    /**
     * ÉCRIT UN CLASSEUR DE REPRISE — feuille `DONNEES`, libellés en ligne 1.
     *
     * ⚠ LES LIBELLÉS VIENNENT DU CATALOGUE, ILS NE SONT PAS RECOPIÉS. La feuille est lue
     * par libellé — elle porte un filtre automatique, dont la plage doit être contiguë, et
     * une ligne de codes techniques y entrerait. Les écrire à la main ici, ce serait
     * tester la lecture contre une copie du catalogue plutôt que contre le catalogue.
     *
     * @param array<int, array<string, string>> $lignes
     *
     * @return string le chemin du fichier déposé
     */
    private function classeur(Entreprise $entreprise, array $lignes): string
    {
        $colonnes = static::getContainer()->get(EtatDuPortefeuille::class)->colonnes($entreprise);

        $classeur = new Spreadsheet();
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle(EtatDuPortefeuille::FEUILLE);

        $codes = array_keys($colonnes);
        foreach ($codes as $index => $code) {
            $feuille->setCellValue([$index + 1, 1], $colonnes[$code]->libelle);
        }

        foreach ($lignes as $rang => $valeurs) {
            foreach ($valeurs as $code => $valeur) {
                $colonne = array_search($code, $codes, true);
                self::assertNotFalse($colonne, sprintf('La colonne « %s » n\'existe pas au catalogue.', $code));
                $feuille->setCellValue([$colonne + 1, $rang + 2], $valeur);
            }
        }

        $chemin = sys_get_temp_dir() . '/paliers-' . bin2hex(random_bytes(8)) . '.xlsx';
        (new Xlsx($classeur))->save($chemin);
        $this->temporaires[] = $chemin;

        return $chemin;
    }

    /** @return array{polices: int, propositions: int, opportunites: int, echeances: int} */
    private function portefeuille(Entreprise $entreprise): array
    {
        $cnx = $this->em()->getConnection();
        $id = $entreprise->getId();

        return [
            'polices' => (int) $cnx->fetchOne('SELECT COUNT(*) FROM avenant WHERE entreprise_id = ?', [$id]),
            'propositions' => (int) $cnx->fetchOne('SELECT COUNT(*) FROM cotation WHERE entreprise_id = ?', [$id]),
            'opportunites' => (int) $cnx->fetchOne('SELECT COUNT(*) FROM piste WHERE entreprise_id = ?', [$id]),
            'echeances' => (int) $cnx->fetchOne('SELECT COUNT(*) FROM tranche WHERE entreprise_id = ?', [$id]),
        ];
    }

    /** Ce que le rapport reproche — pour que l'échec d'un test dise pourquoi. */
    private function motif(EchangeImportRun $run): string
    {
        $messages = array_map(
            static fn (array $a): string => sprintf('[%s] %s', $a['gravite'] ?? '?', $a['message'] ?? ''),
            $run->getRapport()['anomalies'] ?? [],
        );

        return $messages === [] ? 'aucune anomalie signalée' : implode(' | ', $messages);
    }

    private function importateur(): ImportateurJsbx
    {
        return static::getContainer()->get(ImportateurJsbx::class);
    }

    private function avanceur(): AvanceurDImport
    {
        return static::getContainer()->get(AvanceurDImport::class);
    }

    /** @return array{0: Entreprise, 1: Invite} */
    private function fixture(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Paliers')->setVerified(true)->setPassword('x');
        $owner->setPaidTokens(1000000);
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
        $em->flush();

        // ⚠ SANS SESSION, LES LISTES DE CHOIX DES FORMULAIRES SONT VIDES et chaque valeur
        // est refusée sur « le choix sélectionné est invalide ». L'import hérite de cette
        // contrainte, exactement comme une saisie à l'écran.
        $this->client->loginUser($owner);

        return [$entreprise, $proprietaire];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Purge dérivée du schéma — cf. ControleImportTest, même raison. */
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
