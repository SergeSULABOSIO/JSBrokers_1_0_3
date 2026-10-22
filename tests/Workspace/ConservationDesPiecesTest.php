<?php

namespace App\Tests\Workspace;

use App\Command\CabinetViderCommand;
use App\Entity\Entreprise;
use App\Entity\Paiement;
use App\Entity\Utilisateur;
use App\Service\Note\ArchiveComptableDuCabinet;
use App\Service\Note\ConservationDesPieces;
use App\Service\Note\EmissionDeNote;
use App\Services\ServiceInitialisationEntreprise;
use App\Services\ServiceSuppressionEntreprise;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * LES PIÈCES COMPTABLES SE CONSERVENT DIX ANS (Acte uniforme OHADA ; décision de Serge du
 * 2026-10-15). Rien ne purge une note émise — sauf la suppression du cabinet entier, et
 * seulement après la remise de son ARCHIVE COMPTABLE, à jour : le PDF de chaque note, ses
 * lignes, ses paiements, le journal, et les empreintes de tout cela.
 */
class ConservationDesPiecesTest extends WebTestCase
{
    use AffaireFacturableTrait;

    private const EMAIL = 'phpunit-conservation@test.local';
    private const CABINET = 'PHPUnit Conservation SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $this->effacerLesCabinets([self::CABINET], [self::EMAIL]);
        foreach ($this->fichiers ?? [] as $fichier) {
            @unlink($fichier);
        }
    }

    /** Un cabinet, une note émise (1 160 TTC) et son règlement partiel. */
    private function cabinetAvecUneNoteEmise(): array
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $id = $this->brouillon($c, 'Commission émise');
        static::getContainer()->get(EmissionDeNote::class)->valider($this->note($id));
        $em = $this->em();
        $paiement = (new Paiement())->setMontant(500.0)->setPaidAt(new \DateTimeImmutable('-1 day'))->setReference('VIR-1')
            ->setNote($em->find(\App\Entity\Note::class, $id));
        $paiement->setEntreprise($em->find(Entreprise::class, $c['entreprise']));
        $em->persist($paiement);
        $em->flush();
        $em->clear();

        return [$c, $id];
    }

    private function cabinet(array $c): Entreprise
    {
        return $this->em()->find(Entreprise::class, $c['entreprise']);
    }

    public function testLArchiveReunitLesPiecesEtSesEmpreintes(): void
    {
        [$c, $id] = $this->cabinetAvecUneNoteEmise();
        $reference = $this->note($id)->getReference();

        $remise = static::getContainer()->get(ArchiveComptableDuCabinet::class)->remettre($this->cabinet($c));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($remise['chemin']));

        $pdf = 'notes/note-' . $reference . '.pdf';
        foreach ([$pdf, 'notes.csv', 'lignes.csv', 'paiements.csv', 'journal.csv', 'MANIFESTE.txt'] as $fichier) {
            self::assertNotFalse($zip->locateName($fichier), $fichier . ' est dans l’archive.');
        }
        self::assertStringStartsWith('%PDF', (string) $zip->getFromName($pdf));
        self::assertStringContainsString($reference . ';Débit', (string) $zip->getFromName('notes.csv'));
        self::assertStringContainsString('1160.00;500.00', (string) $zip->getFromName('notes.csv'), 'TTC figé, puis payé.');
        self::assertStringContainsString('VIR-1', (string) $zip->getFromName('paiements.csv'));
        self::assertStringContainsString(';411;', (string) $zip->getFromName('journal.csv'));

        // Le manifeste porte l'empreinte de chaque fichier : l'archive se vérifie.
        $manifeste = (string) $zip->getFromName('MANIFESTE.txt');
        foreach (['notes.csv', 'journal.csv', $pdf] as $fichier) {
            self::assertStringContainsString(hash('sha256', (string) $zip->getFromName($fichier)) . '  ' . $fichier, $manifeste);
        }
        $zip->close();

        $cabinet = $this->cabinet($c);
        self::assertNotNull($cabinet->getArchiveComptableRemiseLe(), 'La remise est enregistrée.');
        self::assertSame(hash_file('sha256', $remise['chemin']), $cabinet->getArchiveComptableEmpreinte());
        @unlink($remise['chemin']);
    }

    public function testLeProprietaireTelechargeLArchive(): void
    {
        [$c] = $this->cabinetAvecUneNoteEmise();
        $this->client->loginUser($this->em()->find(Utilisateur::class, $c['owner']));

        // Un GET ne remet rien : la remise rend la suppression possible, elle exige le jeton.
        $this->client->request('GET', '/admin/entreprise/' . $c['entreprise'] . '/archive-comptable');
        self::assertSame(405, $this->client->getResponse()->getStatusCode());

        // Le formulaire de la carte du cabinet, avec son jeton.
        $page = $this->client->request('GET', '/admin/entreprise');
        $formulaire = $page->filter('form.ent-archive-form')->form();
        $this->client->submit($formulaire);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment; filename=archive-comptable-', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->em()->clear();
        self::assertNotNull($this->cabinet($c)->getArchiveComptableRemiseLe());
    }

    /** Sans archive : refusé ; après la remise : permis ; une pièce émise depuis : refusé de nouveau. */
    public function testLaSuppressionDuCabinetAttendUneArchiveAJour(): void
    {
        [$c] = $this->cabinetAvecUneNoteEmise();
        $suppression = static::getContainer()->get(ServiceSuppressionEntreprise::class);

        try {
            $suppression->supprimer($this->cabinet($c));
            self::fail('Sans archive remise, le cabinet ne se supprime pas.');
        } catch (\DomainException $refus) {
            self::assertStringContainsString('à conserver dix ans', $refus->getMessage());
        }

        // Une archive remise HIER, puis une nouvelle note émise : l'archive n'est plus à jour.
        $this->em()->getConnection()->executeStatement(
            'UPDATE entreprise SET archive_comptable_remise_le = ? WHERE id = ?',
            [(new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'), $c['entreprise']],
        );
        $this->em()->clear();
        self::assertStringContainsString('n’est plus à jour', (string) static::getContainer()->get(ConservationDesPieces::class)->refusDeSuppression($this->cabinet($c)));

        // Le parcours réel : les pièces datent d'avant (ici, d'il y a une heure), l'archive est
        // remise maintenant — la suppression passe, et emporte le cabinet.
        $conn = $this->em()->getConnection();
        $uneHeure = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $conn->executeStatement('UPDATE note SET emise_le = ?, figee_le = ?, created_at = ?, updated_at = NULL WHERE entreprise_id = ?', [$uneHeure, $uneHeure, $uneHeure, $c['entreprise']]);
        $conn->executeStatement('UPDATE paiement SET created_at = ?, updated_at = NULL WHERE entreprise_id = ?', [$uneHeure, $c['entreprise']]);
        $this->em()->clear();
        $remise = static::getContainer()->get(ArchiveComptableDuCabinet::class)->remettre($this->cabinet($c));
        @unlink($remise['chemin']);
        $this->em()->clear();
        $suppression->supprimer($this->cabinet($c));
        self::assertFalse($this->em()->getConnection()->fetchOne('SELECT 1 FROM entreprise WHERE id = ?', [$c['entreprise']]));
    }

    /** Les fichiers écrits par ces tests (justificatifs), effacés à la fin. */
    private array $fichiers = [];

    /** Une dépense du cabinet (100, TVA 16 %), avec ou sans justificatif joint. */
    private function depense(array $c, bool $avecJustificatif = false): int
    {
        $em = $this->em();
        $cabinet = $this->cabinet($c);
        $charge = (new \App\Entity\ChargeCourtier())->setCode('CH-CONS')->setLibelle('Loyer')
            ->setCompteOhada('6010')->setComportement(\App\Entity\Charge::COMPORTEMENT_FIXE)
            ->setPeriodicite(\App\Entity\Charge::PERIODICITE_MENSUELLE)->setActif(true);
        $charge->setEntreprise($cabinet);
        $em->persist($charge);
        $depense = (new \App\Entity\DepenseCourtier())->setDateDepense(new \DateTimeImmutable('-3 days'))
            ->setMontant('100')->setTauxTva('16')->setMoyenPaiement(\App\Entity\Depense::MOYEN_BANQUE)
            ->setStatut(\App\Entity\Depense::STATUT_ENGAGEE)->setCharge($charge)->setReference('FACT-LOYER');
        $depense->setEntreprise($cabinet);
        $em->persist($depense);
        if ($avecJustificatif) {
            $dossier = \App\Tests\Support\DossierDesPieces::chemin(static::getContainer());
            if (!is_dir($dossier)) {
                mkdir($dossier, 0o777, true);
            }
            $stocke = 'phpunit-justif-' . bin2hex(random_bytes(6)) . '.pdf';
            file_put_contents($dossier . '/' . $stocke, '%PDF-1.4 facture du loyer');
            $this->fichiers[] = $dossier . '/' . $stocke;
            $document = (new \App\Entity\Document())->setNom('Facture du loyer');
            $document->setNomFichierStocke($stocke);
            $document->setEntreprise($cabinet)->setInvite($em->find(\App\Entity\Invite::class, $c['invite']));
            $document->setDepenseCourtier($depense);
            $em->persist($document);
        }
        $em->flush();
        $id = (int) $depense->getId();
        $em->clear();

        return $id;
    }

    /** Dater les pièces d'il y a une heure : la remise faite maintenant leur est postérieure. */
    private function vieillirLesPieces(array $c): void
    {
        $conn = $this->em()->getConnection();
        $uneHeure = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        foreach (['note', 'paiement', 'paiement_prime', 'depense_courtier', 'reversement_retro_agent'] as $table) {
            $conn->executeStatement("UPDATE `$table` SET created_at = ?, updated_at = NULL WHERE entreprise_id = ?", [$uneHeure, $c['entreprise']]);
        }
        $conn->executeStatement('UPDATE note SET emise_le = ?, figee_le = IF(figee_le IS NULL, NULL, ?) WHERE entreprise_id = ?', [$uneHeure, $uneHeure, $c['entreprise']]);
        $this->em()->clear();
    }

    /**
     * UN BROUILLON N'EST PAS UNE PIÈCE, NI LE CAPITAL DÉCLARÉ SUR LA FICHE (décisions du
     * 2026-10-16) : un cabinet qui n'a que cela se supprime sans archive.
     */
    public function testUnCabinetSansPieceSeSupprimeSansArchive(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $this->brouillon($c, 'Brouillon');
        $this->em()->getConnection()->executeStatement('UPDATE entreprise SET capital_sociale = 1000000 WHERE id = ?', [$c['entreprise']]);
        $this->em()->clear();

        self::assertNull(static::getContainer()->get(ConservationDesPieces::class)->refusDeSuppression($this->cabinet($c)));
        static::getContainer()->get(ServiceSuppressionEntreprise::class)->supprimer($this->cabinet($c));
        self::assertFalse($this->em()->getConnection()->fetchOne('SELECT 1 FROM entreprise WHERE id = ?', [$c['entreprise']]));
    }

    /** LA MOINDRE PIÈCE COMPTABLE : une seule dépense, sans aucune note, exige l'archive. */
    public function testUneDepenseSeuleExigeLArchive(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $this->depense($c);

        $refus = static::getContainer()->get(ConservationDesPieces::class)->refusDeSuppression($this->cabinet($c));
        self::assertNotNull($refus);
        self::assertStringContainsString('1 dépense', $refus);

        $this->vieillirLesPieces($c);
        $remise = static::getContainer()->get(ArchiveComptableDuCabinet::class)->remettre($this->cabinet($c));
        @unlink($remise['chemin']);
        $this->em()->clear();
        static::getContainer()->get(ServiceSuppressionEntreprise::class)->supprimer($this->cabinet($c));
        self::assertFalse($this->em()->getConnection()->fetchOne('SELECT 1 FROM entreprise WHERE id = ?', [$c['entreprise']]));
    }

    /**
     * L'ARCHIVE PORTE TOUTES LES PIÈCES — dépenses, paiements de prime, reversements — et
     * LEURS JUSTIFICATIFS, rangés par nature sous un nom qui renvoie à la ligne du CSV, avec
     * leur empreinte au manifeste.
     */
    public function testLArchivePorteLesAutresPiecesEtLeursJustificatifs(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $idDepense = $this->depense($c, avecJustificatif: true);
        $em = $this->em();
        $cabinet = $this->cabinet($c);
        $prime = (new \App\Entity\PaiementPrime())->setReference('PRIME-1')->setMontant(10000.0)
            ->setPaidAt(new \DateTimeImmutable('-2 days'))->setTranche($em->find(\App\Entity\Tranche::class, $c['tranche']));
        $prime->setEntreprise($cabinet);
        $em->persist($prime);
        $reversement = (new \App\Entity\ReversementRetroAgent())->setAgent($em->find(\App\Entity\Invite::class, $c['invite']))
            ->setMontant(50.0)->setPaidAt(new \DateTimeImmutable('-1 day'))->setReference('VIR-RETRO');
        $reversement->setEntreprise($cabinet)->setInvite($em->find(\App\Entity\Invite::class, $c['invite']));
        $em->persist($reversement);
        $em->flush();
        $em->clear();

        $remise = static::getContainer()->get(ArchiveComptableDuCabinet::class)->remettre($this->cabinet($c));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($remise['chemin']));
        self::assertStringContainsString('FACT-LOYER', (string) $zip->getFromName('depenses.csv'));
        self::assertStringContainsString('PRIME-1', (string) $zip->getFromName('paiements-de-prime.csv'));
        self::assertStringContainsString('VIR-RETRO', (string) $zip->getFromName('reversements.csv'));

        // Le justificatif : rangé par nature, son nom renvoie à la ligne (l'id de la dépense).
        $justificatif = null;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            if (str_starts_with((string) $zip->getNameIndex($i), 'justificatifs/depenses/' . $idDepense . '-')) {
                $justificatif = (string) $zip->getNameIndex($i);
            }
        }
        self::assertNotNull($justificatif, 'Le justificatif de la dépense est dans l’archive.');
        self::assertSame('%PDF-1.4 facture du loyer', $zip->getFromName($justificatif));
        self::assertStringContainsString($justificatif, (string) $zip->getFromName('depenses.csv'), 'La ligne du CSV renvoie au fichier.');
        self::assertStringContainsString(hash('sha256', '%PDF-1.4 facture du loyer') . '  ' . $justificatif, (string) $zip->getFromName('MANIFESTE.txt'));
        $zip->close();
        @unlink($remise['chemin']);
    }

    /**
     * UNE ARCHIVE QUI N'A PAS PU S'ÉCRIRE N'EST PAS REMISE. libzip n'écrit qu'à la fermeture :
     * un justificatif supprimé pendant la construction, un disque plein, et le ZIP sortirait
     * vide. La fermeture échoue ici (écriture simulée) : la remise n'est pas enregistrée, le
     * fichier temporaire est effacé, et la suppression du cabinet reste refusée.
     */
    public function testUneArchiveQuiNAPuSEcrireNestPasRemise(): void
    {
        [$c] = $this->cabinetAvecUneNoteEmise();
        $conteneur = static::getContainer();
        $fermetureRatee = new class extends \App\Service\Note\EcritureZip {
            public ?string $chemin = null;

            public function ouvrir(string $chemin): bool
            {
                $this->chemin = $chemin;

                return parent::ouvrir($chemin);
            }

            public function fermer(): bool
            {
                parent::fermer();

                return false; // ce que rend libzip quand un fichier ajouté a disparu
            }
        };
        $archive = new ArchiveComptableDuCabinet(
            $this->em(),
            $conteneur->get(\App\Service\Note\NotePdf::class),
            $conteneur->get(\App\Services\Canvas\Indicator\IndicatorCalculationHelper::class),
            $conteneur->get(\App\Comptabilite\CourtierEcritureComptableService::class),
            $conteneur->get(\App\Service\Document\DocumentFichier::class),
            $conteneur->get(ConservationDesPieces::class),
            $fermetureRatee,
        );

        try {
            $archive->remettre($this->cabinet($c));
            self::fail('Une archive qui n’a pas pu s’écrire ne se remet pas.');
        } catch (\RuntimeException $echec) {
            self::assertStringContainsString('n’a pas pu être écrite', $echec->getMessage());
        }

        self::assertFileDoesNotExist((string) $fermetureRatee->chemin, 'Le fichier temporaire est effacé.');
        $this->em()->clear();
        $cabinet = $this->cabinet($c);
        self::assertNull($cabinet->getArchiveComptableRemiseLe(), 'Aucune remise enregistrée.');
        self::assertNull($cabinet->getArchiveComptableEmpreinte());
        self::assertNotNull($conteneur->get(ConservationDesPieces::class)->refusDeSuppression($cabinet));
        try {
            $conteneur->get(ServiceSuppressionEntreprise::class)->supprimer($cabinet);
            self::fail('La suppression reste refusée.');
        } catch (\DomainException) {
        }
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM entreprise WHERE id = ?', [$c['entreprise']]));
    }

    /** Un justificatif joint APRÈS la remise n'est pas dans l'archive remise : elle n'est plus à jour. */
    public function testUnJustificatifJointApresLaRemiseRendLArchivePasAJour(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $this->depense($c, avecJustificatif: true);
        $this->vieillirLesPieces($c);
        $this->em()->getConnection()->executeStatement('UPDATE document SET created_at = ?, updated_at = NULL WHERE entreprise_id = ?',
            [(new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), $c['entreprise']]);
        $this->em()->clear();
        $remise = static::getContainer()->get(ArchiveComptableDuCabinet::class)->remettre($this->cabinet($c));
        @unlink($remise['chemin']);
        $conservation = static::getContainer()->get(ConservationDesPieces::class);
        $this->em()->clear();
        self::assertNull($conservation->refusDeSuppression($this->cabinet($c)), 'Juste après la remise : à jour.');

        // Une facture jointe ensuite à la dépense.
        $this->em()->getConnection()->executeStatement('UPDATE document SET created_at = ? WHERE entreprise_id = ?',
            [(new \DateTimeImmutable('+1 minute'))->format('Y-m-d H:i:s'), $c['entreprise']]);
        $this->em()->clear();
        self::assertStringContainsString('n’est plus à jour', (string) $conservation->refusDeSuppression($this->cabinet($c)));
    }

    /** En PRODUCTION, l'outil de remise à zéro ne vide pas un cabinet qui porte des notes émises. */
    public function testLaCommandeViderRefuseEnProduction(): void
    {
        [$c] = $this->cabinetAvecUneNoteEmise();
        $conteneur = static::getContainer();
        $commande = new CabinetViderCommand(
            $this->em(),
            $this->em()->getConnection(),
            $conteneur->get(ServiceInitialisationEntreprise::class),
            $conteneur->get(ConservationDesPieces::class),
            'prod',
        );

        $testeur = new CommandTester($commande);
        $code = $testeur->execute(['cabinet' => (string) $c['entreprise'], '--confirmer' => true]);

        self::assertSame(1, $code);
        self::assertStringContainsString('à conserver dix ans', $testeur->getDisplay());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM note WHERE entreprise_id = ?', [$c['entreprise']]));
    }

    /** Une dépense seule suffit aussi à refuser le vidage en production. */
    public function testLaCommandeViderRefuseEnProductionPourUneDepenseSeule(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $this->depense($c);
        $conteneur = static::getContainer();
        $commande = new CabinetViderCommand($this->em(), $this->em()->getConnection(),
            $conteneur->get(ServiceInitialisationEntreprise::class), $conteneur->get(ConservationDesPieces::class), 'prod');

        $testeur = new CommandTester($commande);
        self::assertSame(1, $testeur->execute(['cabinet' => (string) $c['entreprise'], '--confirmer' => true]));
        self::assertStringContainsString('1 dépense', $testeur->getDisplay());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM depense_courtier WHERE entreprise_id = ?', [$c['entreprise']]));
    }
}
