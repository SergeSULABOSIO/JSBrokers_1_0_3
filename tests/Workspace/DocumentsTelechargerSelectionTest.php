<?php

namespace App\Tests\Workspace;

use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Services\Canvas\FormCanvasProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * TÉLÉCHARGER LA SÉLECTION depuis la rubrique Documents — de l'action déclarée au binaire.
 *
 * CE QUI EST VÉRIFIÉ, DANS L'ORDRE OÙ L'UTILISATEUR LE VIT :
 *  1. l'action est PROPOSÉE, et elle l'est aux deux surfaces à la fois — barre d'outils et
 *     clic droit lisent la même déclaration, la vérifier une fois les couvre toutes deux ;
 *  2. elle accepte la sélection MULTIPLE (`multi`), sans quoi il n'y aurait jamais
 *     d'archive : les deux surfaces masquent une action non-`multi` dès la deuxième ligne ;
 *  3. une pièce arrive telle quelle, sous son nom lisible ; plusieurs arrivent en ZIP ;
 *  4. et rien de tout cela ne franchit la frontière du cabinet.
 *
 * L'ARCHIVE EST RÉOUVERTE, jamais devinée d'après l'en-tête. Un ZIP peut être annoncé,
 * servi, et ne contenir qu'un seul des trois fichiers demandés — c'est précisément ce qui
 * arrive quand deux documents portent le même nom et que le second écrase le premier.
 * Seule la relecture de l'archive le dit.
 */
class DocumentsTelechargerSelectionTest extends WebTestCase
{
    private const ENT = 'PHPUnit-TelSel SARL';
    private const AUTRE = 'PHPUnit-TelSel Concurrente';
    private const OWNER = 'phpunit-telsel-owner@test.local';
    private const OWNER_AUTRE = 'phpunit-telsel-autre@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> binaires écrits sur le disque */
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

    private function dossierUploads(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/documents';
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $noms = [self::ENT, self::AUTRE];

        foreach (glob($this->dossierUploads() . '/phpunit-telsel-*') ?: [] as $orphelin) {
            @unlink($orphelin);
        }

        // Le document précède le classeur, qui précède le client : chacun référence le
        // suivant. Le classeur d'un client naît tout seul avec lui depuis le rangement
        // automatique — l'oublier rendrait l'entreprise indestructible.
        foreach (['document', 'classeur', 'client', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:e)', ['e' => [self::OWNER, self::OWNER_AUTRE]], ['e' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        $conn->executeStatement(
            'DELETE FROM entreprise WHERE nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:e)', ['e' => [self::OWNER, self::OWNER_AUTRE]], ['e' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        $this->em->clear();
    }

    /** Un vrai binaire sur le disque, non vide : 0 octet se confondrait avec « absent ». */
    private function ecrireBinaire(string $extension, int $octets = 256): string
    {
        $dossier = $this->dossierUploads();
        if (!is_dir($dossier)) {
            mkdir($dossier, 0o777, true);
        }
        $nomStocke = 'phpunit-telsel-' . bin2hex(random_bytes(6)) . '.' . $extension;
        file_put_contents($dossier . '/' . $nomStocke, str_repeat('x', $octets));
        $this->binaires[] = $dossier . '/' . $nomStocke;

        return $nomStocke;
    }

    private function entreprise(string $nom, Utilisateur $owner): Entreprise
    {
        $e = (new Entreprise())
            ->setNom($nom)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($e);

        return $e;
    }

    /**
     * Un client, son classeur (créé tout seul), et trois pièces — dont deux HOMONYMES.
     *
     * @return array{ent: Entreprise, inv: Invite, owner: Utilisateur, client: Client, ids: list<int>, sansFichier: int}
     */
    private function seed(): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true)->setPassword('x');
        $this->em->persist($owner);

        $ent = $this->entreprise(self::ENT, $owner);
        $owner->setConnectedTo($ent);

        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);

        $client = (new Client())->setNom('Client à télécharger');
        $client->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($client);

        $ids = [];
        foreach ([['Contrat signé', 'pdf'], ['Contrat signé', 'pdf'], ['Attestation', 'docx']] as [$nom, $ext]) {
            $doc = (new Document())->setNom($nom);
            $doc->setNomFichierStocke($this->ecrireBinaire($ext));
            $doc->setClient($client)->setEntreprise($ent)->setInvite($inv);
            $this->em->persist($doc);
            $ids[] = $doc;
        }

        // Une ligne en base SANS binaire : elle doit être écartée, jamais servie.
        $sansFichier = (new Document())->setNom('Pièce sans binaire');
        $sansFichier->setClient($client)->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($sansFichier);

        $this->em->flush();
        $this->client->loginUser($owner);

        return [
            'ent' => $ent, 'inv' => $inv, 'owner' => $owner, 'client' => $client,
            'ids' => array_map(static fn (Document $d): int => (int) $d->getId(), $ids),
            'sansFichier' => (int) $sansFichier->getId(),
        ];
    }

    /**
     * L'archive téléchargée, relue : nom de fichier => contenu.
     *
     * ON LIT `getInternalResponse()`, ni `getResponse()->getContent()` ni le fichier
     * pointé par la réponse : celle-ci est un BinaryFileResponse construit avec
     * `deleteFileAfterSend`, et le client de test l'a DÉJÀ envoyée pour en capturer la
     * sortie — le temporaire n'existe plus. Ce que le client a capturé est exactement ce
     * qu'un navigateur aurait reçu.
     */
    private function lireArchive(): array
    {
        $chemin = tempnam(sys_get_temp_dir(), 'phpunit_zip_');
        file_put_contents($chemin, (string) $this->client->getInternalResponse()->getContent());

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($chemin) === true, 'La réponse doit être une archive lisible.');

        $entrees = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $nom = (string) $zip->getNameIndex($i);
            $entrees[$nom] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($chemin);

        return $entrees;
    }

    /**
     * 1. L'ACTION EST PROPOSÉE, ET ELLE ACCEPTE PLUSIEURS LIGNES.
     *
     * `multi` n'est pas un détail : sans cette clé, la barre d'outils comme le menu
     * contextuel masquent l'entrée dès la deuxième ligne cochée. L'utilisateur pourrait
     * télécharger un document à la fois, et jamais l'archive qu'on lui promet.
     */
    public function testLaRubriqueProposeLeTelechargementEnSelectionMultiple(): void
    {
        $seed = $this->seed();

        $canvas = static::getContainer()->get(FormCanvasProvider::class)
            ->getCanvas(new Document(), (int) $seed['ent']->getId());

        $actions = $canvas['parametres']['attribute_actions'] ?? [];
        $telecharger = null;
        foreach ($actions as $action) {
            if (($action['event'] ?? null) === 'ui:documents.download-request') {
                $telecharger = $action;
            }
        }

        $this->assertNotNull($telecharger, 'La rubrique Documents doit proposer « Télécharger ».');
        $this->assertTrue($telecharger['multi'] ?? false, 'Sans « multi », l’entrée disparaît dès deux lignes cochées.');
        $this->assertSame('action:download', $telecharger['icon'] ?? null);
    }

    /**
     * 2. UNE SEULE PIÈCE ARRIVE TELLE QUELLE, sous son nom lisible.
     *
     * Pas d'archive pour un fichier : ce serait un clic et un dossier à ouvrir de plus
     * pour rien. Et le nom est celui du Document, pas le nom de stockage généré par Vich
     * — l'utilisateur reçoit ce que sa liste affiche.
     */
    public function testUneSeulePieceArriveSousSonNomLisible(): void
    {
        $seed = $this->seed();

        $this->client->request('GET', sprintf('/admin/document/api/telecharger?ids=%d', $seed['ids'][0]));

        $this->assertResponseIsSuccessful();
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        $this->assertStringContainsString('Contrat', $disposition, 'Le fichier doit porter le libellé du document.');
        $this->assertStringNotContainsString('.zip', $disposition, 'Une pièce unique ne s’empaquette pas.');
    }

    /**
     * 3. PLUSIEURS PIÈCES ARRIVENT EN ARCHIVE, et l'archive les contient VRAIMENT TOUTES.
     *
     * Les deux premières sont HOMONYMES exprès. Sans dé-doublonnage, la seconde écrase la
     * première dans le ZIP : l'utilisateur reçoit deux fichiers là où il en a demandé
     * trois, et rien ne le lui dit.
     */
    public function testPlusieursPiecesArriventEnArchiveComplete(): void
    {
        $seed = $this->seed();

        $this->client->request('GET', sprintf('/admin/document/api/telecharger?ids=%s', implode(',', $seed['ids'])));
        $this->assertResponseIsSuccessful();

        $entrees = $this->lireArchive();
        $this->assertCount(3, $entrees, 'Les trois pièces doivent être dans l’archive, homonymes comprises.');
        foreach ($entrees as $contenu) {
            $this->assertNotSame('', $contenu, 'Aucune entrée ne doit être vide.');
        }
    }

    /**
     * 4. L'ARCHIVE PORTE LE NOM DU DOSSIER quand la sélection n'en franchit qu'un.
     *
     * C'est ce que la demande exprimait par « le libellé de l'objet ». Un Document ne
     * portant qu'un fichier, plusieurs fichiers font plusieurs Documents — donc plusieurs
     * libellés. On prend celui qu'ils ont en commun : leur classeur, qui est justement ce
     * qui les réunit.
     */
    public function testLArchivePorteLeNomDuDossierCommun(): void
    {
        $seed = $this->seed();

        $this->client->request('GET', sprintf('/admin/document/api/telecharger?ids=%s', implode(',', $seed['ids'])));

        // L'en-tête porte DEUX noms : un repli ASCII, où les accents sont remplacés par
        // des soulignés, et la forme `filename*` en UTF-8 percent-encodé, qui est celle
        // que le navigateur retient. C'est donc celle-là qu'on lit — chercher le libellé
        // tel quel dans l'en-tête brut échouerait sur un nom accentué, alors même que
        // l'utilisateur reçoit le bon fichier.
        $disposition = rawurldecode((string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $this->assertStringContainsString(
            'Client à télécharger.zip',
            $disposition,
            'L’archive doit porter le nom du dossier dont les pièces proviennent.',
        );
    }

    /**
     * 5. UNE LIGNE SANS BINAIRE EST ÉCARTÉE, jamais servie.
     *
     * Elle existe en base et se coche comme les autres. La servir produirait un 404 au
     * clic — pire qu'une absence, parce qu'on a promis. Ici, elle disparaît de la
     * sélection et les deux autres passent en archive.
     */
    public function testUneLigneSansBinaireEstEcartee(): void
    {
        $seed = $this->seed();

        $this->client->request('GET', sprintf(
            '/admin/document/api/telecharger?ids=%d,%d,%d',
            $seed['ids'][0],
            $seed['sansFichier'],
            $seed['ids'][2],
        ));
        $this->assertResponseIsSuccessful();

        $this->assertCount(2, $this->lireArchive(), 'Seules les pièces réellement téléchargeables entrent dans l’archive.');
    }

    /**
     * 6. LES PIÈCES D'UN AUTRE CABINET N'ENTRENT PAS.
     *
     * Les identifiants viennent du navigateur : il suffirait d'en écrire d'autres à la
     * main. Chacun est re-résolu DANS l'entreprise de l'écran, et l'intrus est écarté en
     * silence — répondre « interdit » confirmerait son existence.
     */
    public function testUnePieceDUnAutreCabinetNEntrePasDansLArchive(): void
    {
        $seed = $this->seed();

        $autreOwner = (new Utilisateur())->setEmail(self::OWNER_AUTRE)->setNom('X')->setVerified(true)->setPassword('x');
        $this->em->persist($autreOwner);
        $autre = $this->entreprise(self::AUTRE, $autreOwner);
        $autreInvite = (new Invite())->setNom('Autre')->setUtilisateur($autreOwner)->setEntreprise($autre)->setProprietaire(true);
        $this->em->persist($autreInvite);

        $intrus = (new Document())->setNom('Pièce du concurrent');
        $intrus->setNomFichierStocke($this->ecrireBinaire('pdf'));
        $intrus->setEntreprise($autre)->setInvite($autreInvite);
        $this->em->persist($intrus);
        $this->em->flush();
        $idIntrus = (int) $intrus->getId();

        $this->client->request('GET', sprintf(
            '/admin/document/api/telecharger?ids=%d,%d,%d',
            $seed['ids'][0],
            $idIntrus,
            $seed['ids'][2],
        ));
        $this->assertResponseIsSuccessful();

        $entrees = $this->lireArchive();
        $this->assertCount(2, $entrees, 'La pièce du concurrent doit être écartée.');
        foreach (array_keys($entrees) as $nom) {
            $this->assertStringNotContainsString('concurrent', $nom);
        }

        $this->em->getConnection()->executeStatement('DELETE FROM document WHERE id = :id', ['id' => $idIntrus]);
    }

    /** 7. Sans identifiant, il n'y a rien à servir. */
    public function testSansIdentifiantCEstUn404(): void
    {
        $this->seed();

        $this->client->request('GET', '/admin/document/api/telecharger?ids=');
        $this->assertResponseStatusCodeSame(404);
    }
}
