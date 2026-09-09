<?php

namespace App\Tests\Echange;

use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Service\Anomalie;
use App\Echange\Service\ImportateurJsbx;
use App\Entity\EchangeImportRun;
use App\Entity\EchangeOccurrence;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'ÉCRITURE : ce que la confirmation fait réellement.
 *
 * ── CE QUI EST GARDÉ ICI ────────────────────────────────────────────────────────────
 * La confirmation est le SEUL geste de toute la rubrique qui écrive en base. Ces tests
 * vérifient qu'elle écrit ce que le rapport avait annoncé, qu'elle refuse ce qui n'a pas
 * été autorisé, qu'elle ne s'exécute qu'une fois, et qu'elle ne laisse derrière elle ni
 * fichier ni trace mensongère.
 *
 * ⚠ IL N'Y A PLUS DE « TOUT OU RIEN » À L'ÉCHELLE DU FICHIER, et c'est délibéré. L'import
 * avance par paliers, chacun dans sa transaction : une erreur à la deux millième ligne
 * n'annule plus les mille neuf cent quatre-vingt-dix-neuf premières. Ce qui n'est
 * acceptable que parce que la reprise est IDEMPOTENTE — redéposer le fichier reprend là
 * où il s'était arrêté sans rien dupliquer, ce que `RepriseParPaliersTest` prouve.
 *
 * Ce qui reste vrai, et qui est testé ici : un PALIER qui échoue ne conserve rien.
 */
class EcritureImportTest extends WebTestCase
{
    use ClasseurDeRepriseTrait;

    private const OWNER_EMAIL = 'phpunit-echange-ecr@test.local';
    private const ENT = 'PHPUnit Écriture SARL';

    private KernelBrowser $client;

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
    // Ce que l'écriture produit
    // ─────────────────────────────────────────────────────────────────────────────

    /** Une ligne nouvelle crée toute sa chaîne : client, opportunité, proposition, police, échéance. */
    public function testUneLigneAjouteeCreeTouteSaChaine(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $run = $this->importer($entreprise, $invite, [$this->uneEcheance()]);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));
        self::assertSame(
            ['clients' => 1, 'polices' => 1, 'propositions' => 1, 'echeances' => 1],
            $this->portefeuille($entreprise),
        );
    }

    /**
     * ⚠ UNE LIGNE QUI PORTE SON IDENTIFIANT NE TOUCHE PAS À SON ASCENDANCE.
     *
     * C'est le geste du correctif : on exporte, on corrige une valeur, on redépose. Refaire
     * toute la chaîne écrirait des modifications que personne n'a demandées, et le journal
     * annoncerait cinq écritures pour une.
     */
    public function testUneValeurCorrigeeEstEcriteSansRefaireLAscendance(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->importer($entreprise, $invite, [$this->uneEcheance()]);

        $idTranche = (int) $this->em()->getConnection()->fetchOne(
            'SELECT id FROM tranche WHERE entreprise_id = ?',
            [$entreprise->getId()],
        );

        $run = $this->importer($entreprise, $invite, [[
            'id' => $idTranche,
            'trancheNom' => 'Prime corrigée',
            'tranchePayableAt' => '15/01/2026',
        ]]);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));
        self::assertSame(
            'Prime corrigée',
            $this->em()->getConnection()->fetchOne('SELECT nom FROM tranche WHERE id = ?', [$idTranche]),
        );
        self::assertSame(
            ['clients' => 1, 'polices' => 1, 'propositions' => 1, 'echeances' => 1],
            $this->portefeuille($entreprise),
            'Rien n\'a été recréé au passage.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Ce que l'écriture refuse
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ UNE SUPPRESSION NON AUTORISÉE AU DÉPÔT BLOQUE TOUT L'IMPORT.
     *
     * Passée sous silence, l'utilisateur croirait ses lignes supprimées. La case est
     * décochée par défaut, et le reste : une colonne « Action » mal recopiée ne doit pas
     * pouvoir vider un portefeuille.
     */
    public function testUneSuppressionNonAutoriseeBloqueToutLImport(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->importer($entreprise, $invite, [$this->uneEcheance()]);

        $idTranche = (int) $this->em()->getConnection()->fetchOne(
            'SELECT id FROM tranche WHERE entreprise_id = ?',
            [$entreprise->getId()],
        );

        $run = $this->importer($entreprise, $invite, [[
            'id' => $idTranche,
            CanevasDEchange::COL_ACTION => CanevasDEchange::ACTION_SUPPRIMER,
        ]]);

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertNotNull($this->anomalieDeCode($run, Anomalie::SUPPRESSION_REFUSEE), $this->motif($run));
        self::assertSame(1, $this->portefeuille($entreprise)['echeances'], 'L\'échéance est toujours là.');
    }

    /** Autorisée explicitement, la même suppression s'exécute. */
    public function testUneSuppressionAutoriseeEstExecutee(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->importer($entreprise, $invite, [$this->uneEcheance()]);

        $idTranche = (int) $this->em()->getConnection()->fetchOne(
            'SELECT id FROM tranche WHERE entreprise_id = ?',
            [$entreprise->getId()],
        );

        $run = $this->importer($entreprise, $invite, [[
            'id' => $idTranche,
            CanevasDEchange::COL_ACTION => CanevasDEchange::ACTION_SUPPRIMER,
        ]], suppressions: true);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));
        self::assertSame(0, $this->portefeuille($entreprise)['echeances']);
        self::assertSame(1, $this->portefeuille($entreprise)['polices'], 'La police survit à son échéance.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Ce qui ne s'exécute pas deux fois
    // ─────────────────────────────────────────────────────────────────────────────

    /** Une confirmation ne vaut qu'une fois : le second appel est refusé, pas rejoué. */
    public function testUneConfirmationNeVautQuUneFois(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $run = $this->importer($entreprise, $invite, [$this->uneEcheance()]);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));

        $this->expectException(\App\Echange\Service\ImportImpossibleException::class);
        $this->importateur()->executer($run, $invite->getUtilisateur());
    }

    /** Un contrôle annulé ne s'exécute plus, même si l'on garde son identifiant sous la main. */
    public function testUnControleAnnuleNeSExecutePlus(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $run = $this->importateur()->controler(
            $this->classeurDeReprise($entreprise, [$this->uneEcheance()]),
            'annule.xlsx',
            $entreprise,
            $invite,
        );
        $this->importateur()->annuler($run);

        $this->expectException(\App\Echange\Service\ImportImpossibleException::class);
        $this->importateur()->executer($run, $invite->getUtilisateur());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Ce que l'écriture laisse derrière elle
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ LE DÉPÔT EST EFFACÉ UNE FOIS LES DONNÉES EN BASE. Un classeur de reprise porte le
     * nom, l'adresse et les primes de tous les clients d'un cabinet : il n'a rien à faire
     * sur le disque une fois qu'il a servi.
     */
    public function testLeDepotEstEffaceApresUnImportAbouti(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $chemin = $this->classeurDeReprise($entreprise, [$this->uneEcheance()]);
        $run = $this->importateur()->controler($chemin, 'depot.xlsx', $entreprise, $invite);
        $run = $this->importateur()->executer($run, $invite->getUtilisateur());

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));
        self::assertNull($run->getCheminFichier());
        self::assertFileDoesNotExist($chemin);
    }

    /** Un import abouti est tracé — sans forfait : chaque ligne a déjà payé son métrage. */
    public function testUnImportAboutiEstTraceSansForfait(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->importer($entreprise, $invite, [$this->uneEcheance()]);

        $occurrence = $this->em()->getConnection()->fetchAssociative(
            'SELECT type, tokens_debites, nb_lignes FROM echange_occurrence WHERE entreprise_id = ?',
            [$entreprise->getId()],
        );

        self::assertNotFalse($occurrence, 'L\'import doit laisser une trace.');
        self::assertSame(EchangeOccurrence::TYPE_IMPORT, $occurrence['type']);
        self::assertSame(0, (int) $occurrence['tokens_debites'], 'L\'importation n\'a jamais de forfait.');
        self::assertSame(1, (int) $occurrence['nb_lignes']);
    }

    /** Un import en échec ne compte aucune occurrence : il n'a rien produit. */
    public function testUnImportEnEchecNeCompteAucuneOccurrence(): void
    {
        [$entreprise, $invite] = $this->fixture();

        // Une ligne sans référence de police ne peut pas être rattachée : le contrôle
        // échoue, et la confirmation ne s'ouvre jamais.
        $run = $this->importateur()->controler(
            $this->classeurDeReprise($entreprise, [['assure' => 'KIN AVIA', 'trancheNom' => 'Prime']]),
            'echec.xlsx',
            $entreprise,
            $invite,
        );

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertSame(
            0,
            (int) $this->em()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM echange_occurrence WHERE entreprise_id = ?',
                [$entreprise->getId()],
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Outillage
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Dépose, contrôle et confirme — le parcours complet, en une ligne de test.
     *
     * ⚠ ON RECHARGE LE CABINET, comme le ferait une seconde requête. L'écriture vide
     * l'unité de travail pour repartir propre : les objets d'un import précédent sont
     * détachés, et les réutiliser tels quels ferait croire à Doctrine qu'on lui présente
     * un cabinet tout neuf.
     */
    private function importer(Entreprise $entreprise, Invite $invite, array $lignes, bool $suppressions = false): EchangeImportRun
    {
        $entreprise = $this->em()->find(Entreprise::class, $entreprise->getId());
        $invite = $this->em()->find(Invite::class, $invite->getId());

        $run = $this->importateur()->controler(
            $this->classeurDeReprise($entreprise, $lignes),
            'reprise.xlsx',
            $entreprise,
            $invite,
            $suppressions,
        );

        if ($run->getStatut() !== EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION) {
            return $run;
        }

        return $this->importateur()->executer($run, $invite->getUtilisateur());
    }

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

    /** @return array{clients: int, polices: int, propositions: int, echeances: int} */
    private function portefeuille(Entreprise $entreprise): array
    {
        $cnx = $this->em()->getConnection();
        $id = $entreprise->getId();

        return [
            'clients' => (int) $cnx->fetchOne('SELECT COUNT(*) FROM client WHERE entreprise_id = ?', [$id]),
            'polices' => (int) $cnx->fetchOne('SELECT COUNT(*) FROM avenant WHERE entreprise_id = ?', [$id]),
            'propositions' => (int) $cnx->fetchOne('SELECT COUNT(*) FROM cotation WHERE entreprise_id = ?', [$id]),
            'echeances' => (int) $cnx->fetchOne('SELECT COUNT(*) FROM tranche WHERE entreprise_id = ?', [$id]),
        ];
    }

    private function importateur(): ImportateurJsbx
    {
        return static::getContainer()->get(ImportateurJsbx::class);
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

    private function motif(EchangeImportRun $run): string
    {
        $messages = array_map(
            static fn (array $a): string => sprintf('[%s] %s', $a['gravite'] ?? '?', $a['message'] ?? ''),
            $run->getRapport()['anomalies'] ?? [],
        );

        return $messages === [] ? 'aucune anomalie signalée' : implode(' | ', $messages);
    }

    /** @return array{0: Entreprise, 1: Invite} */
    private function fixture(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Écriture')->setVerified(true)->setPassword('x');
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
