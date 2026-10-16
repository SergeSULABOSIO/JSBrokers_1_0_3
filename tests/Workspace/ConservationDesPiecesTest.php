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
}
