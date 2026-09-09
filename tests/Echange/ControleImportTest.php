<?php

namespace App\Tests\Echange;

use App\Echange\Etat\EtatDuPortefeuille;
use App\Echange\Service\Anomalie;
use App\Echange\Service\ImportateurJsbx;
use App\Entity\EchangeImportRun;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\RolesEnProduction;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LE CONTRÔLE À BLANC : le fichier est-il recevable, et que ferait-il ?
 *
 * Ce qui est vérifié ici tient en une phrase : le contrôle doit être GRATUIT, ne RIEN
 * écrire, et dire exactement ce qui se passerait — y compris quand la réponse est
 * « rien, et voici pourquoi ».
 *
 * Les cas de refus comptent autant que les cas nominaux. Un import qui échoue mal — en
 * silence, ou sans dire où — coûte plus cher qu'un import qui n'existe pas : il fait
 * perdre le travail hors ligne ET la confiance dans l'outil.
 *
 * ⚠ UN SEUL FORMAT EST DÉSORMAIS ACCEPTÉ, et ces tests l'ont suivi. Ils étaient écrits
 * sur le classeur « normalisé », à une feuille par entité, que l'importation ne relit
 * plus : il n'était plus produit nulle part. Les règles, elles, n'ont pas changé de
 * nature — un fichier illisible, un fichier venu d'ailleurs, un droit manquant, une
 * anomalie située — et c'est à ce titre qu'elles sont ici, sur la feuille `DONNEES`.
 *
 * Ce qui a disparu avec l'ancien format n'a pas été remplacé par du vide : « feuille
 * inconnue », « colonne technique supprimée », « repère local », « renvoi du mauvais
 * type » décrivaient des accidents propres à une structure à plusieurs feuilles. Le
 * refus qui les remplace tous est celui du fichier qui n'est pas un classeur de reprise.
 */
class ControleImportTest extends WebTestCase
{
    use ClasseurDeRepriseTrait;

    private const OWNER_EMAIL = 'phpunit-echange-ctrl@test.local';
    private const LECTEUR_EMAIL = 'phpunit-echange-lecteur@test.local';
    private const ENT = 'PHPUnit Contrôle SARL';

    private KernelBrowser $client;

    /**
     * ⚠ UN UTILISATEUR CONNECTÉ EST NÉCESSAIRE, et ce n'est pas une commodité de test.
     *
     * Les champs de relation des formulaires (« Groupe », « Portefeuille »…) filtrent
     * leurs choix sur le CABINET ACTIF de l'utilisateur — c'est ce qui empêche de
     * rattacher un client au portefeuille du cabinet voisin. Sans session, la liste des
     * choix est vide et le formulaire refuse toute valeur avec « le choix sélectionné
     * est invalide », quel que soit le soin mis à résoudre le renvoi en amont.
     *
     * L'import hérite donc de cette contrainte : il ne peut s'exécuter que dans le
     * contexte d'un utilisateur, exactement comme une saisie à l'écran. C'est le cas en
     * production (requête HTTP authentifiée) comme depuis l'assistant.
     */
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->effacerLesClasseurs();
        $this->nettoyer();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Passe 1 — le fichier est-il recevable ?
    // ─────────────────────────────────────────────────────────────────────────────

    /** Un fichier qui n'est pas un classeur s'arrête net, sans rien coûter. */
    public function testUnFichierIllisibleEstRefuseImmediatement(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = sys_get_temp_dir() . '/faux-' . bin2hex(random_bytes(6)) . '.xlsx';
        file_put_contents($chemin, 'ceci n\'est pas un classeur');
        $this->classeursTemporaires[] = $chemin;

        $run = $this->importateur()->controler($chemin, 'faux.xlsx', $entreprise, $proprietaire);

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertSame(Anomalie::FICHIER_ILLISIBLE, $this->premierCode($run));
    }

    /**
     * ⚠ UN CLASSEUR QUELCONQUE EST REFUSÉ, ET LE REFUS DIT QUOI FAIRE.
     *
     * C'est le cas le plus fréquent d'une première reprise : le cabinet arrive avec un
     * fichier à lui — un extrait de son ancien logiciel, un tableau maison. « Feuille
     * DONNEES absente » serait exact et inutile : il ne sait pas qu'elle devrait s'y
     * trouver, ni comment l'obtenir. Ce qui lui manque, c'est le gabarit, et le message
     * doit le nommer.
     */
    public function testUnClasseurQuelconqueEstRefuseEtOrienteVersLeGabarit(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $run = $this->importateur()->controler(
            $this->classeurSansDonnees(),
            'mon-tableau.xlsx',
            $entreprise,
            $proprietaire,
        );

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());

        $anomalie = $this->anomalieDeCode($run, Anomalie::MANIFESTE_ABSENT);
        self::assertNotNull($anomalie);
        self::assertStringContainsString('classeur de reprise', $anomalie['message']);
        self::assertStringContainsString(EtatDuPortefeuille::FEUILLE, $anomalie['message']);
    }

    /**
     * ⚠ UN FICHIER VENU D'UN AUTRE CABINET EST BLOQUÉ, mais l'utilisateur peut lever.
     *
     * Les identifiants qu'il contient ne désignent rien ici : chaque ligne serait créée
     * en double. Importer les données d'un cabinet dans un autre est parfois voulu — une
     * reprise —, jamais anodin.
     */
    public function testUnFichierDUnAutreCabinetExigeUneConfirmation(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->classeurDeReprise($entreprise, [$this->uneEcheance()], [
            $this->cleDuCabinet() => '999999',
        ]);

        $run = $this->importateur()->controler($chemin, 'ailleurs.xlsx', $entreprise, $proprietaire);
        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertSame(Anomalie::AUTRE_CABINET, $this->premierCode($run));

        // Le même fichier, confirmé : l'avertissement subsiste, le contrôle passe.
        $chemin = $this->classeurDeReprise($entreprise, [$this->uneEcheance()], [
            $this->cleDuCabinet() => '999999',
        ]);

        $run = $this->importateur()->controler($chemin, 'ailleurs.xlsx', $entreprise, $proprietaire, false, true);
        self::assertSame(EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION, $run->getStatut(), $this->motif($run));

        $avertissement = $this->anomalieDeCode($run, Anomalie::AUTRE_CABINET);
        self::assertNotNull($avertissement);
        self::assertSame(Anomalie::AVERTISSEMENT, $avertissement['gravite']);
    }

    /** Un classeur de reprise sans la moindre ligne n'a rien à importer, et le dit. */
    public function testUnClasseurSansLigneEstRefuse(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $run = $this->importateur()->controler(
            $this->classeurDeReprise($entreprise, []),
            'vide.xlsx',
            $entreprise,
            $proprietaire,
        );

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertStringContainsString('aucune ligne', $this->motif($run));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Passe 2 — le contrôle à blanc
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ LE CONTRÔLE N'ÉCRIT RIEN. C'est la promesse qui rend la confirmation utile : si
     * le contrôle écrivait, l'utilisateur déciderait après coup.
     */
    public function testLeControleNecritRienEnBase(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $avant = $this->compterClients($entreprise);

        $run = $this->importateur()->controler(
            $this->classeurDeReprise($entreprise, [$this->uneEcheance()]),
            'sans-ecriture.xlsx',
            $entreprise,
            $proprietaire,
        );

        self::assertSame(EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION, $run->getStatut(), $this->motif($run));
        self::assertSame($avant, $this->compterClients($entreprise), 'Aucun client n\'a été créé.');
    }

    /** Une ligne sans identifiant décrit une affaire nouvelle : le rapport annonce des créations. */
    public function testUneLigneSansIdentifiantDevientUneCreation(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $rapport = $this->importateur()->controler(
            $this->classeurDeReprise($entreprise, [$this->uneEcheance()]),
            'creation.xlsx',
            $entreprise,
            $proprietaire,
        )->getRapport();

        self::assertGreaterThan(0, $rapport['creations'] ?? 0);
        self::assertSame(0, $rapport['suppressions'] ?? -1, 'Une suppression ne se déduit jamais.');
        self::assertSame(1, $rapport['lignes_lues'] ?? 0);
    }

    /**
     * ⚠ CHAQUE ANOMALIE DE LIGNE EST SITUÉE. Lire « une valeur est invalide » sans savoir
     * où oblige à relire le fichier entier — et c'est là qu'on abandonne, pas à l'erreur.
     */
    public function testChaqueAnomalieDeLigneEstSituee(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        // Une ligne sans référence de police : rien ne permet de la rattacher.
        $run = $this->importateur()->controler(
            $this->classeurDeReprise($entreprise, [['assure' => 'KIN AVIA', 'trancheNom' => 'Prime']]),
            'sans-cle.xlsx',
            $entreprise,
            $proprietaire,
        );

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());

        $situees = array_filter(
            $run->getRapport()['anomalies'] ?? [],
            static fn (array $a): bool => ($a['gravite'] ?? '') === Anomalie::ERREUR && ($a['ligne'] ?? null) !== null,
        );

        self::assertNotEmpty($situees, 'Une erreur de ligne doit porter son numéro de ligne.');
        foreach ($situees as $anomalie) {
            self::assertSame(EtatDuPortefeuille::FEUILLE, $anomalie['feuille']);
            self::assertGreaterThanOrEqual(2, $anomalie['ligne'], 'La ligne 1 porte les libellés.');
        }
    }

    /**
     * ⚠ UN DROIT MANQUANT SE SIGNALE, IL NE S'IGNORE PAS.
     *
     * L'invité n'a que la lecture sur les données de production : il peut ouvrir la
     * rubrique et déposer un fichier, mais pas en écrire une ligne. Le contrôle doit le
     * dire — sans quoi il croirait ses données enregistrées.
     */
    public function testUnInviteSansDroitDEcritureVoitSesLignesRefusees(): void
    {
        [$entreprise, , $lecteur] = $this->fixture();

        $run = $this->importateur()->controler(
            $this->classeurDeReprise($entreprise, [$this->uneEcheance()]),
            'sans-droit.xlsx',
            $entreprise,
            $lecteur,
        );

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertNotNull(
            $this->anomalieDeCode($run, Anomalie::DROIT_INSUFFISANT),
            'Le refus doit nommer le droit qui manque : ' . $this->motif($run),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Outillage
    // ─────────────────────────────────────────────────────────────────────────────

    /** Une échéance complète, telle qu'un cabinet la saisirait dans le gabarit. */
    private function uneEcheance(array $surcharges = []): array
    {
        return [
            'policeReference' => 'POL/2026/001',
            'policeDateEffet' => '01/01/2026',
            'policeEcheance' => '31/12/2026',
            'trancheNom' => 'Prime unique',
            'tranchePayableAt' => '15/01/2026',
            'assure' => 'KIN AVIA',
            'risque' => 'RC Aviation',
            'assureur' => 'SFA CONGO',
        ] + $surcharges;
    }

    private function importateur(): ImportateurJsbx
    {
        return static::getContainer()->get(ImportateurJsbx::class);
    }

    private function premierCode(EchangeImportRun $run): string
    {
        return (string) ($run->getRapport()['anomalies'][0]['code'] ?? '');
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

    /** Ce que le rapport reproche — pour qu'un échec de test dise pourquoi. */
    private function motif(EchangeImportRun $run): string
    {
        $messages = array_map(
            static fn (array $a): string => sprintf('[%s] %s', $a['gravite'] ?? '?', $a['message'] ?? ''),
            $run->getRapport()['anomalies'] ?? [],
        );

        return $messages === [] ? 'aucune anomalie signalée' : implode(' | ', $messages);
    }

    private function compterClients(Entreprise $entreprise): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM client WHERE entreprise_id = ?',
            [$entreprise->getId()],
        );
    }

    /** @return array{0: Entreprise, 1: Invite, 2: Invite} */
    private function fixture(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Ctrl')->setVerified(true)->setPassword('x');
        $owner->setPaidTokens(100000);
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

        $lecteur = (new Invite())->setNom('Lecteur')->setEmail(self::LECTEUR_EMAIL);
        $lecteur->setProprietaire(false);
        $lecteur->setEntreprise($entreprise);
        $em->persist($lecteur);

        $admin = (new RolesEnAdministration())->setNom('Admin lecteur');
        $admin->setAccessEchange([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);
        $admin->setEntreprise($entreprise);
        $admin->setInvite($lecteur);
        $em->persist($admin);
        $lecteur->addRolesEnAdministration($admin);

        // Lecture seule sur les clients : il peut ouvrir la rubrique et exporter, mais
        // pas écrire une seule ligne de données.
        $prod = (new RolesEnProduction())->setNom('Prod lecteur');
        $prod->setAccessClient([Invite::ACCESS_LECTURE]);
        $prod->setEntreprise($entreprise);
        $prod->setInvite($lecteur);
        $em->persist($prod);
        $lecteur->addRolesEnProduction($prod);

        $em->flush();

        // Session ouverte sur ce cabinet : c'est elle qui peuple les listes de choix
        // des formulaires, donc qui rend les renvois acceptables.
        $this->client->loginUser($owner);

        return [$entreprise, $proprietaire, $lecteur];
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
            foreach ([self::OWNER_EMAIL, self::LECTEUR_EMAIL] as $email) {
                $cnx->executeStatement('DELETE FROM utilisateur WHERE email = ?', [$email]);
            }
        } finally {
            $cnx->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->em()->clear();
    }
}
