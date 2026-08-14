<?php

namespace App\Tests\Ai;

use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'ARCHIVE ZIP DE DOCUMENTS, éprouvée en la ROUVRANT.
 *
 * POURQUOI ROUVRIR L'ARCHIVE. Vérifier un code 200 et un en-tête `application/zip` ne
 * prouve rien : une archive vide, une archive où deux fichiers homonymes se sont
 * écrasés, ou une archive contenant un document d'une autre entreprise renvoient toutes
 * exactement la même réponse HTTP. Le seul test qui vaille consiste donc à récupérer les
 * octets, à les rouvrir avec ZipArchive, et à regarder ce qu'il y a dedans.
 *
 * CE QUI SE JOUE ICI EN PLUS DU CONFORT. La route reçoit une LISTE D'IDENTIFIANTS dans
 * l'URL, c'est-à-dire la partie de la requête que l'utilisateur contrôle entièrement.
 * Chaque identifiant est donc re-résolu dans le périmètre de l'entreprise, et un
 * identifiant étranger est écarté SANS le dire : refuser bruyamment ferait de la route
 * un oracle d'existence, où l'on apprendrait par balayage ce qui existe ailleurs.
 */
class AssistantIaDocumentZipTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-zipdoc-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-zipdoc-guest@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit ZipDoc SARL';
    private const ENTREPRISE_AUTRE = 'PHPUnit ZipDoc Voisine';
    private const AUTRE_EMAIL = 'phpunit-zipdoc-autre@test.local';
    private const PASSWORD = 'Test1234!';

    private KernelBrowser $client;

    /** @var list<string> */
    private array $fichiersEcrits = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        foreach ($this->fichiersEcrits as $chemin) {
            @unlink($chemin);
        }
        $this->fichiersEcrits = [];
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email): ?Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function dossierUploads(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/documents';
    }

    /**
     * Balaie les binaires d'une exécution précédente : un échec dans setUp() empêche
     * tearDown(), et les fichiers survivraient. Préfixe de CE test uniquement.
     */
    private function balayerBinairesOrphelins(): void
    {
        foreach (glob($this->dossierUploads() . '/phpunit-zipdoc-*') ?: [] as $orphelin) {
            @unlink($orphelin);
        }
    }

    private function cleanUp(): void
    {
        $this->balayerBinairesOrphelins();

        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_AUTRE];
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL, self::AUTRE_EMAIL];

        foreach (['document', 'roles_en_administration', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM entreprise WHERE nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    private function makeUser(string $email): Utilisateur
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new Utilisateur())->setEmail($email)->setNom('PHPUnit ZipDoc')->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);

        return $user;
    }

    private function ecrireBinaire(string $extension, string $contenu): string
    {
        $dossier = $this->dossierUploads();
        if (!is_dir($dossier)) {
            mkdir($dossier, 0o777, true);
        }
        $nomStocke = 'phpunit-zipdoc-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $chemin = $dossier . '/' . $nomStocke;
        file_put_contents($chemin, $contenu);
        $this->fichiersEcrits[] = $chemin;

        return $nomStocke;
    }

    private function document(Entreprise $entreprise, Invite $invite, string $nom, string $contenu, bool $avecBinaire = true): Document
    {
        $doc = (new Document())->setNom($nom);
        $doc->setNomFichierStocke($avecBinaire ? $this->ecrireBinaire('pdf', $contenu) : 'phpunit-zipdoc-absent.pdf');
        $doc->setEntreprise($entreprise)->setInvite($invite);
        $this->em()->persist($doc);

        return $doc;
    }

    /**
     * DEUX DOCUMENTS HOMONYMES dans l'entreprise, un document d'une entreprise voisine,
     * et un invité SANS droit de lecture sur Document — chacun couvre une des règles.
     *
     * @return array{
     *     entreprise: Entreprise,
     *     ids: list<int>,
     *     idAutre: int,
     *     idSansFichier: int
     * }
     */
    private function seed(): array
    {
        $em = $this->em();

        $owner = $this->makeUser(self::OWNER_EMAIL);
        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-ZIPD')->setAdresse('1 rue Zippée')
            ->setTelephone('+243000000020')->setRccm('RCCM-ZIPD')->setIdnat('IDNAT-ZIPD')
            ->setNumimpot('IMP-ZIPD')->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $ownerInvite = (new Invite())->setNom('Propriétaire');
        $ownerInvite->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($ownerInvite);

        // Invité du module IA, mais SANS lecture sur Document : le module ouvert ne
        // vaut pas droit sur la donnée.
        $guest = $this->makeUser(self::GUEST_EMAIL);
        $guest->setConnectedTo($entreprise);
        $guestInvite = (new Invite())->setNom('Collaborateur');
        $guestInvite->setUtilisateur($guest)->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($guestInvite);

        $role = (new RolesEnAdministration())->setNom('Rôle IA sans documents');
        $role->setAccessAssistantIa([Invite::ACCESS_LECTURE]);
        $role->setAccessDocument([]);
        $role->setEntreprise($entreprise);
        $guestInvite->addRolesEnAdministration($role);
        $em->persist($role);

        // DEUX DOCUMENTS DE MÊME NOM : sans dé-doublonnage, le second écraserait le
        // premier dans l'archive et l'utilisateur recevrait un fichier de moins.
        $a = $this->document($entreprise, $ownerInvite, 'Contrat', 'PREMIER CONTRAT');
        $b = $this->document($entreprise, $ownerInvite, 'Contrat', 'SECOND CONTRAT');
        $c = $this->document($entreprise, $ownerInvite, 'Annexe', 'ANNEXE');
        $sansFichier = $this->document($entreprise, $ownerInvite, 'Promise', '', false);

        // ── Entreprise voisine ──
        $autreUser = $this->makeUser(self::AUTRE_EMAIL);
        $autreEntreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_AUTRE)->setLicence('LIC-ZIPD2')->setAdresse('2 rue Voisine')
            ->setTelephone('+243000000021')->setRccm('RCCM-ZIPD2')->setIdnat('IDNAT-ZIPD2')
            ->setNumimpot('IMP-ZIPD2')->setUtilisateur($autreUser);
        $em->persist($autreEntreprise);
        $autreInvite = (new Invite())->setNom('Voisin');
        $autreInvite->setUtilisateur($autreUser)->setEntreprise($autreEntreprise)->setProprietaire(true);
        $em->persist($autreInvite);
        $autre = $this->document($autreEntreprise, $autreInvite, 'Secret du voisin', 'CONFIDENTIEL');

        $em->flush();

        return [
            'entreprise'    => $entreprise,
            'ids'           => [(int) $a->getId(), (int) $b->getId(), (int) $c->getId()],
            'idAutre'       => (int) $autre->getId(),
            'idSansFichier' => (int) $sansFichier->getId(),
        ];
    }

    private function url(Entreprise $entreprise, array $ids): string
    {
        return sprintf('/admin/assistant-ia/api/documents/%d/zip?ids=%s', $entreprise->getId(), implode(',', $ids));
    }

    /**
     * Les octets réellement reçus.
     *
     * PAS `getResponse()->getContent()`, et pas non plus un second `sendContent()` :
     * la réponse est un BinaryFileResponse construit avec `deleteFileAfterSend`, et
     * HttpKernelBrowser l'a DÉJÀ envoyée pour en capturer la sortie — le temporaire
     * n'existe donc plus. Ce que le client a capturé vit dans la réponse BrowserKit,
     * et c'est exactement ce qu'un navigateur aurait téléchargé.
     */
    private function octetsDeLaReponse(): string
    {
        return (string) $this->client->getInternalResponse()->getContent();
    }

    /** @return array<string, string> nom d'entrée => contenu */
    private function contenuDeLArchive(string $octets): array
    {
        $chemin = tempnam(sys_get_temp_dir(), 'phpunit_zip_');
        file_put_contents($chemin, $octets);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($chemin) === true, "L'archive téléchargée doit être un ZIP valide.");

        $entrees = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $nom = (string) $zip->getNameIndex($i);
            $entrees[$nom] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($chemin);

        return $entrees;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * LE CAS NOMINAL, prouvé de l'intérieur : trois documents demandés, trois entrées
     * dans l'archive, chacune avec son VRAI contenu et un nom LISIBLE (le libellé du
     * document, pas le nom de stockage Vich).
     */
    public function testLArchiveContientLesDocumentsAvecUnNomLisible(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', $this->url($seed['entreprise'], $seed['ids']));
        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $this->client->getResponse()->headers->get('Content-Type'));

        $entrees = $this->contenuDeLArchive($this->octetsDeLaReponse());

        self::assertCount(3, $entrees, 'Trois documents demandés, trois entrées.');
        self::assertArrayHasKey('Annexe.pdf', $entrees, 'Le libellé du document, avec son extension réelle.');
        self::assertSame('ANNEXE', $entrees['Annexe.pdf'], 'Et le vrai binaire, pas un fichier vide.');
    }

    /**
     * DEUX DOCUMENTS HOMONYMES ne s'écrasent pas. Sans dé-doublonnage, l'utilisateur
     * recevrait deux fichiers pour trois demandés — sans qu'aucune erreur ne le dise.
     */
    public function testDeuxDocumentsHomonymesSontDeDoublonnes(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', $this->url($seed['entreprise'], $seed['ids']));
        $entrees = $this->contenuDeLArchive($this->octetsDeLaReponse());

        self::assertArrayHasKey('Contrat.pdf', $entrees);
        self::assertArrayHasKey('Contrat (2).pdf', $entrees, 'Le second homonyme reçoit un suffixe, il n’écrase pas le premier.');
        self::assertSame('PREMIER CONTRAT', $entrees['Contrat.pdf']);
        self::assertSame('SECOND CONTRAT', $entrees['Contrat (2).pdf']);
    }

    /**
     * UN IDENTIFIANT D'UNE AUTRE ENTREPRISE EST ÉCARTÉ EN SILENCE : l'archive se
     * construit sans lui, et la réponse ne dit pas s'il existait. C'est ce qui empêche
     * la route de servir d'oracle.
     */
    public function testUnDocumentDUneAutreEntrepriseNEntrePasDansLArchive(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', $this->url($seed['entreprise'], [$seed['ids'][2], $seed['idAutre']]));
        self::assertResponseIsSuccessful();

        $entrees = $this->contenuDeLArchive($this->octetsDeLaReponse());

        self::assertCount(1, $entrees, 'Seul le document de l’entreprise entre dans l’archive.');
        self::assertArrayHasKey('Annexe.pdf', $entrees);
        self::assertStringNotContainsString('CONFIDENTIEL', implode('', $entrees));
    }

    /** Un document sans binaire sur le disque n'ajoute pas d'entrée vide. */
    public function testUnDocumentSansBinaireEstIgnore(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', $this->url($seed['entreprise'], [$seed['ids'][2], $seed['idSansFichier']]));
        self::assertResponseIsSuccessful();

        self::assertCount(1, $this->contenuDeLArchive($this->octetsDeLaReponse()));
    }

    /** Quand plus rien ne survit au filtrage, c'est 404 — pas une archive vide. */
    public function testAucunDocumentValideDonneUn404(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', $this->url($seed['entreprise'], [$seed['idAutre']]));

        self::assertResponseStatusCodeSame(404);
    }

    /** Une demande sans identifiant n'est pas une demande. */
    public function testSansIdentifiantCEstUn404(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf('/admin/assistant-ia/api/documents/%d/zip', $seed['entreprise']->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * FAIL-CLOSED : le module IA ouvert ne vaut pas droit de lecture sur les documents.
     * L'invité y accède au chat, pas aux fichiers de la base.
     */
    public function testSansDroitDeLectureSurDocumentCEstRefuse(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->client->request('GET', $this->url($seed['entreprise'], $seed['ids']));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * LA BORNE DE NOMBRE EST DITE, pas subie. Au-delà du plafond, la réponse explique —
     * plutôt que de laisser le worker compresser jusqu'à expiration du délai.
     */
    public function testAuDelaDuPlafondLaReponseLeDit(): void
    {
        $seed = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', $this->url($seed['entreprise'], range(1, 51)));

        self::assertResponseStatusCodeSame(413);
        self::assertStringContainsString('maximum', $this->client->getResponse()->getContent());
    }
}
