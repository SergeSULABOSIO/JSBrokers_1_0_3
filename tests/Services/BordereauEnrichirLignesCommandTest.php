<?php

namespace App\Tests\Services;

use App\Entity\Bordereau;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rattrapage des bordereaux analysés avant la persistance des montants par police.
 *
 * Trois comportements sont vérifiés, parce que chacun protège d'une écriture fausse :
 * l'enrichissement d'une ligne intègre, le REFUS d'une ligne dont la police a changé dans
 * le fichier (le fichier a donc été remplacé depuis l'analyse, les montants ne sont plus les
 * siens), et l'idempotence.
 */
class BordereauEnrichirLignesCommandTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-enrichir-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Enrichir SARL';
    private const FICHIER = 'phpunit-enrichir-bordereau.xlsx';

    private string $cheminFichier;

    protected function setUp(): void
    {
        static::bootKernel();
        $this->cheminFichier = static::getContainer()->getParameter('kernel.project_dir')
            . '/public/uploads/documents/' . self::FICHIER;
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        foreach (['document', 'bordereau', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM]
            );
        }
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);

        if (is_file($this->cheminFichier)) {
            unlink($this->cheminFichier);
        }
    }

    /**
     * Un classeur à deux lignes, écrit là où la commande ira le lire. Les en-têtes sont en
     * première ligne : la commande la retire, comme l'analyse, si bien que row_index 0
     * désigne la première ligne de données.
     */
    private function ecrireClasseur(string $policeLigne2): void
    {
        $classeur = new Spreadsheet();
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle('Production');
        $feuille->fromArray([
            ['Police', 'Commission HT', 'Taxe'],
            ['POL-ENR-1', '1 200,50', '150,00'],
            [$policeLigne2, '800,00', '100,00'],
        ], null, 'A1');

        if (!is_dir(dirname($this->cheminFichier))) {
            mkdir(dirname($this->cheminFichier), 0777, true);
        }
        (new Xlsx($classeur))->save($this->cheminFichier);
    }

    /** @return array{bordereau: Bordereau} */
    private function seed(): array
    {
        $em = $this->em();

        $ownerUser = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit Enrichir')
            ->setVerified(true)->setPassword('irrelevant');
        $em->persist($ownerUser);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-ENR')->setAdresse('1 rue du Rattrapage')
            ->setTelephone('+243000000004')->setRccm('RCCM-ENR')->setIdnat('IDNAT-ENR')
            ->setNumimpot('IMP-ENR')->setUtilisateur($ownerUser);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Propriétaire Enrichir');
        $invite->setUtilisateur($ownerUser)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        // Lignes APPAUVRIES : l'état d'un bordereau analysé avant le correctif.
        $bordereau = (new Bordereau())
            ->setType(Bordereau::TYPE_BOREDERAU_PRODUCTION)
            ->setNom('Bordereau à rattraper')->setReference('BRD-ENR')
            ->setReceivedAt(new \DateTimeImmutable('-10 days'))
            ->setPeriodeDebut(new \DateTimeImmutable('-40 days'))
            ->setPeriodeFin(new \DateTimeImmutable('-10 days'))
            ->setSelectedSheetName('Production')
            ->setMappedColumns([
                'reference_police' => 'A',
                'commission_ht_payable_now' => 'B',
                'taxe_commission_payable_now' => 'C',
            ])
            ->setCurrentAnalysisStep(3)
            ->setMontantPayableNow(2250.50)
            ->setAnalysisResults([
                ['type' => 'match', 'row_index' => 0, 'reference_police' => 'POL-ENR-1', 'avenant_id' => null],
                ['type' => 'match', 'row_index' => 1, 'reference_police' => 'POL-ENR-2', 'avenant_id' => null],
            ]);
        $bordereau->setInvite($invite)->setEntreprise($entreprise);
        $em->persist($bordereau);

        // setNomFichierStocke() ne rend pas $this : pas de chaînage sur cet appel.
        // Le lien est porté par Bordereau (OneToMany documents), côté propriétaire Document.
        $document = (new Document())->setNom('Classeur');
        $document->setNomFichierStocke(self::FICHIER);
        $document->setEntreprise($entreprise);
        $bordereau->addDocument($document);
        $em->persist($document);

        $em->flush();
        $em->clear();

        return ['bordereau' => $em->getRepository(Bordereau::class)->find($bordereau->getId())];
    }

    private function lancer(bool $force): CommandTester
    {
        $commande = (new Application(static::$kernel))->find('app:bordereau:enrichir-lignes');
        $tester = new CommandTester($commande);
        $tester->execute($force ? ['--force' => true] : []);

        return $tester;
    }

    public function testDryRunNecritRien(): void
    {
        $this->ecrireClasseur('POL-ENR-2');
        $bordereau = $this->seed()['bordereau'];
        $id = $bordereau->getId();

        $this->lancer(false);
        $this->em()->clear();

        $lignes = $this->em()->getRepository(Bordereau::class)->find($id)->getAnalysisResults();
        $this->assertArrayNotHasKey('commission_ht_payable_now', $lignes[0], 'Le dry-run ne doit RIEN écrire.');
    }

    public function testEnrichitLesLignesIntegresEtEcarteCellesDontLaPoliceAChange(): void
    {
        // La deuxième ligne du fichier ne porte PLUS la police enregistrée à l'analyse :
        // le classeur a été remplacé depuis, ses montants appartiennent à une autre affaire.
        $this->ecrireClasseur('POL-AUTRE-CHOSE');
        $id = $this->seed()['bordereau']->getId();

        $tester = $this->lancer(true);
        $this->em()->clear();

        $lignes = $this->em()->getRepository(Bordereau::class)->find($id)->getAnalysisResults();

        // Ligne intègre : enrichie, séparateurs nettoyés.
        $this->assertEqualsWithDelta(1200.50, $lignes[0]['commission_ht_payable_now'], 0.001);
        $this->assertEqualsWithDelta(150.0, $lignes[0]['taxe_commission_payable_now'], 0.001);

        // Ligne suspecte : laissée INTACTE, et signalée.
        $this->assertArrayNotHasKey(
            'commission_ht_payable_now',
            $lignes[1],
            'Une ligne dont la police a changé ne doit jamais recevoir de montants.'
        );
        $this->assertStringContainsString('1 écartée', $tester->getDisplay());
    }

    public function testIdempotente(): void
    {
        $this->ecrireClasseur('POL-ENR-2');
        $id = $this->seed()['bordereau']->getId();

        $this->lancer(true);
        $this->em()->clear();
        $apresPremier = $this->em()->getRepository(Bordereau::class)->find($id)->getAnalysisResults();

        $tester = $this->lancer(true);
        $this->em()->clear();
        $apresSecond = $this->em()->getRepository(Bordereau::class)->find($id)->getAnalysisResults();

        $this->assertSame($apresPremier, $apresSecond, 'Un second passage ne doit rien changer.');
        $this->assertStringContainsString('0 ligne(s) enrichie(s)', $tester->getDisplay());

        // L'INVARIANT du dispositif : la somme des lignes vaut ce que la note réclame.
        $somme = array_sum(array_map(
            static fn (array $l) => (float) ($l['commission_ht_payable_now'] ?? 0) + (float) ($l['taxe_commission_payable_now'] ?? 0),
            $apresSecond,
        ));
        $this->assertEqualsWithDelta(2250.50, $somme, 0.01, 'Somme reconstruite = montantPayableNow.');
    }
}
