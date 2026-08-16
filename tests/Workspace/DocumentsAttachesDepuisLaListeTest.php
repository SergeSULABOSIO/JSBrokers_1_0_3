<?php

namespace App\Tests\Workspace;

use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Services\Canvas\FormCanvasProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * ATTACHER DES PIÈCES DEPUIS LA LISTE — le parcours réel, de l'action déclarée jusqu'aux
 * documents en base.
 *
 * Ce que ce test protège, dans l'ordre où l'utilisateur le vit :
 *  1. l'action est bien PROPOSÉE sur la rubrique, aux deux surfaces à la fois (elles
 *     lisent la même déclaration) ;
 *  2. déposer trois fichiers en crée trois, rattachés à la fiche désignée ;
 *  3. et surtout : aucun chemin détourné n'existe. L'URL porte le nom du champ de
 *     rattachement — donc un nom fabriqué à la main tenterait de choisir lui-même où
 *     atterrit la pièce, et une fiche d'un autre cabinet serait une cible parfaite.
 *
 * ⚠️ WebTestCase : le canevas n'injecte ses actions que pour un invité authentifié DANS
 * le workspace demandé (fail-closed hors HTTP). Sans `loginUser`, la déclaration serait
 * vide et le premier test passerait pour de mauvaises raisons.
 */
class DocumentsAttachesDepuisLaListeTest extends WebTestCase
{
    private const ENT = 'PHPUnit-DocsAttach SARL';
    private const AUTRE = 'PHPUnit-DocsAttach Concurrente';
    private const OWNER = 'phpunit-docsattach-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> binaires écrits par les uploads, à retirer en sortie */
    private array $binaires = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        foreach ($this->binaires as $chemin) {
            @unlink($chemin);
        }
        $this->binaires = [];
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $noms = [self::ENT, self::AUTRE];
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);

        // LES BINAIRES D'ABORD, ET PAR LEUR VRAI NOM. Vich renomme le fichier déposé
        // (SmartUniqueNamer) : un balayage par préfixe de test ne les retrouverait pas,
        // et chaque exécution laisserait ses pièces dans public/uploads/documents.
        $dossier = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/documents';
        $stockes = $conn->fetchFirstColumn(
            'SELECT d.nom_fichier_stocke FROM document d JOIN entreprise e ON d.entreprise_id = e.id WHERE e.nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        foreach ($stockes as $nomStocke) {
            if ((string) $nomStocke !== '') {
                @unlink($dossier . '/' . $nomStocke);
            }
        }

        foreach (['document', 'risque'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
        foreach (['roles_en_production', 'roles_en_administration', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'DELETE FROM entreprise WHERE nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);

        $this->em->clear();
    }

    /** @return array{0:Entreprise,1:Invite,2:Utilisateur,3:Risque} */
    private function seed(): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $owner->setPaidTokens(1_000_000);
        $this->em->persist($owner);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($ent);
        $owner->setConnectedTo($ent);

        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);

        // Un RISQUE : rubrique sans dossier SOA, et qui ne pouvait rien porter avant ce
        // chantier — le cas qui prouve le plus.
        $risque = (new Risque())
            ->setCode('RCA7')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setNomComplet('RC Automobile attachable')->setImposable(true)->setEntreprise($ent);
        $this->em->persist($risque);

        $this->em->flush();
        $this->client->loginUser($owner);

        return [$ent, $inv, $owner, $risque];
    }

    /** Un vrai fichier temporaire, au format accepté. */
    private function fichier(string $nom, string $contenu = 'contenu de test suffisamment long pour être reconnu'): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'phpunit_att_');
        file_put_contents($chemin, $contenu);
        $this->binaires[] = $chemin;

        return new UploadedFile($chemin, $nom, 'text/plain', null, true);
    }

    /**
     * 1. L'ACTION EST PROPOSÉE. La barre d'outils et le menu contextuel lisent la même
     * déclaration : la vérifier une fois les couvre tous les deux.
     */
    public function testLaRubriqueProposeLAttachementDesPieces(): void
    {
        [$ent, , , ] = $this->seed();

        $canvas = static::getContainer()->get(FormCanvasProvider::class)->getCanvas(new Risque(), $ent->getId());
        $actions = $canvas['parametres']['attribute_actions'] ?? [];

        $attacher = null;
        foreach ($actions as $action) {
            if (($action['event'] ?? null) === 'ui:documents.attach-request') {
                $attacher = $action;
            }
        }

        $this->assertNotNull($attacher, 'La rubrique doit proposer d’attacher des pièces.');
        $this->assertSame('Attacher des pièces', $attacher['label']);
        $this->assertStringContainsString('%id%', $attacher['url'], 'L’URL doit viser la LIGNE sélectionnée.');
        $this->assertArrayNotHasKey(
            'multi',
            $attacher,
            'Sans `multi`, l’action n’apparaît que sur une sélection unique — c’est la règle demandée.',
        );
    }

    /** 2. TROIS FICHIERS DÉPOSÉS, TROIS DOCUMENTS RATTACHÉS — binaires compris. */
    public function testUnLotDeFichiersDevientAutantDeDocumentsRattaches(): void
    {
        [$ent, , , $risque] = $this->seed();
        $idRisque = $risque->getId();

        $this->client->request(
            'POST',
            sprintf('/admin/document/api/attacher/risque/%d', $idRisque),
            [],
            ['fichiers' => [
                $this->fichier('contrat.txt'),
                $this->fichier('attestation.txt'),
                $this->fichier('annexe.txt'),
            ]],
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(3, $data['crees']);
        $this->assertSame([], $data['refuses']);

        $this->em->clear();
        $documents = $this->em->getRepository(Document::class)->findBy(['risque' => $idRisque]);
        $this->assertCount(3, $documents);
        foreach ($documents as $document) {
            $this->assertNotSame('', (string) $document->getNomFichierStocke(), 'Le binaire doit avoir suivi.');
            $this->assertSame($ent->getId(), $document->getEntreprise()?->getId(), 'Le scoping entreprise s’applique.');
        }
    }

    /**
     * 3a. UN NOM DE RATTACHEMENT FABRIQUÉ NE CHOISIT RIEN. `{parent}` vient de l'URL :
     * s'il n'était pas confronté à la carte des relations réelles de Document, une URL
     * écrite à la main déciderait elle-même où atterrit la pièce.
     */
    public function testUnRattachementInconnuEstRefuse(): void
    {
        [, , , $risque] = $this->seed();

        $this->client->request(
            'POST',
            sprintf('/admin/document/api/attacher/entreprise/%d', $risque->getId()),
            [],
            ['fichiers' => [$this->fichier('intrus.txt')]],
        );

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(0, (int) $this->em->getRepository(Document::class)->count([]));
    }

    /** 3b. UNE FICHE D'UN AUTRE CABINET EST INTROUVABLE — la réponse d'un id inexistant. */
    public function testUneFicheDUnAutreCabinetEstIntrouvable(): void
    {
        $this->seed();

        $autreProprio = (new Utilisateur())->setEmail('phpunit-docsattach-autre@test.local')->setNom('Autre');
        $autreProprio->setPassword('x');
        $this->em->persist($autreProprio);
        $autre = (new Entreprise())
            ->setNom(self::AUTRE)->setLicence('L2')->setAdresse('2 rue')->setTelephone('+243001')
            ->setRccm('R2')->setIdnat('I2')->setNumimpot('N2')->setUtilisateur($autreProprio);
        $this->em->persist($autre);
        $risqueAilleurs = (new Risque())
            ->setCode('RCA8')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setNomComplet('Risque du concurrent')->setImposable(true)->setEntreprise($autre);
        $this->em->persist($risqueAilleurs);
        $this->em->flush();
        $idAilleurs = $risqueAilleurs->getId();

        $this->client->request(
            'POST',
            sprintf('/admin/document/api/attacher/risque/%d', $idAilleurs),
            [],
            ['fichiers' => [$this->fichier('indiscret.txt')]],
        );

        $this->assertResponseStatusCodeSame(404);
        $this->assertCount(0, $this->em->getRepository(Document::class)->findBy(['risque' => $idAilleurs]));

        $this->em->createQuery('DELETE FROM App\Entity\Risque r WHERE r.id = :id')->setParameter('id', $idAilleurs)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Entreprise e WHERE e.nom = :n')->setParameter('n', self::AUTRE)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Utilisateur u WHERE u.email = :e')->setParameter('e', 'phpunit-docsattach-autre@test.local')->execute();
    }

    /**
     * 4. UN FORMAT REFUSÉ EST NOMMÉ, LES AUTRES PASSENT. Tout rejeter pour un intrus
     * ferait recommencer une manipulation déjà faite ; l'écarter en silence laisserait
     * croire le lot complet.
     */
    public function testUnFichierRefuseEstNommeSansBloquerLeLot(): void
    {
        [, , , $risque] = $this->seed();
        $idRisque = $risque->getId();

        $this->client->request(
            'POST',
            sprintf('/admin/document/api/attacher/risque/%d', $idRisque),
            [],
            ['fichiers' => [
                $this->fichier('valide.txt'),
                $this->fichier('script.exe'),
            ]],
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertSame(['valide.txt'], $data['crees']);
        $this->assertCount(1, $data['refuses']);
        $this->assertSame('script.exe', $data['refuses'][0]['nom']);
        $this->assertStringContainsString('exe', $data['refuses'][0]['motif'], 'Le motif doit NOMMER ce qui cloche.');

        $this->em->clear();
        $this->assertCount(1, $this->em->getRepository(Document::class)->findBy(['risque' => $idRisque]));
    }

    /** 5. LA BOÎTE S'OUVRE, et elle nomme la fiche que l'utilisateur a désignée. */
    public function testLaBoiteDAttachementNommeLaFicheVisee(): void
    {
        [, , , $risque] = $this->seed();

        $this->client->request('GET', sprintf('/admin/document/api/attacher/risque/%d', $risque->getId()));

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('RC Automobile attachable', $html);
        $this->assertStringContainsString('documents-attach-picker', $html, 'Le fragment doit embarquer son contrôleur.');
    }
}
