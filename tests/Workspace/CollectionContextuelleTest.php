<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * UN ONGLET CONTEXTUEL MONTRE SA COLLECTION DÈS SON PREMIER CHARGEMENT.
 *
 * Défaut constaté sur « Rétros agents » : une rétro sélectionnée, un clic sur l'onglet
 * « Justificatifs », et la liste s'affichait VIDE — alors qu'une pièce existe forcément,
 * puisqu'on ne peut pas enregistrer une rétro sans justificatif. Il fallait cliquer sur
 * « Réinitialiser » pour la voir apparaître.
 *
 * Ce que cet écart dit : les deux chemins ne sont pas les mêmes.
 *  — le premier chargement appelle l'URL de COLLECTION
 *    (`/admin/<entite>/api/{id}/{collection}/generic` → `handleCollectionApiRequest`,
 *    qui lit `$parent->getDocuments()`) ;
 *  — « Réinitialiser » passe par le moteur de recherche dynamique avec `parentContext`.
 *
 * Le second marchait, le premier non. Ce test tient le PREMIER, celui qu'aucun test ne
 * couvrait — d'où un onglet vide sur une donnée présente, sans le moindre message.
 */
class CollectionContextuelleTest extends WebTestCase
{
    private const ENT = 'PHPUnit-CollectionCtx SARL';
    private const OWNER = 'phpunit-collectionctx-owner@test.local';

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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);

        // Les binaires d'abord, et par leur VRAI nom : Vich renomme le fichier déposé.
        $dossier = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/documents';
        $stockes = $conn->fetchFirstColumn(
            'SELECT d.nom_fichier_stocke FROM document d JOIN entreprise e ON d.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENT],
        );
        foreach ($stockes as $nomStocke) {
            if ((string) $nomStocke !== '') {
                @unlink($dossier . '/' . $nomStocke);
            }
        }

        $tables = [
            'document', 'classeur', 'reversement_retro_agent', 'avenant', 'cotation',
            'piste', 'client', 'risque',
            'roles_en_production', 'roles_en_administration', 'roles_en_finance', 'invite',
        ];
        foreach ($tables as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER]);

        $this->em->clear();
    }

    private function fichier(string $nom): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'phpunit_ctx_');
        file_put_contents($chemin, 'bordereau de virement de test, assez long pour etre reconnu');
        $this->binaires[] = $chemin;

        return new UploadedFile($chemin, $nom, 'text/plain', null, true);
    }

    /** @return array{proprietaire: Invite, agent: Invite, avenants: array<int, Avenant>} */
    private function semer(): array
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

        $proprietaire = (new Invite())->setNom('Owner')->setProprietaire(true);
        $proprietaire->setUtilisateur($owner)->setEntreprise($ent);
        $this->em->persist($proprietaire);

        $agent = (new Invite())->setNom('Alice Apporteuse')->setProprietaire(false);
        $agent->setEntreprise($ent);
        $this->em->persist($agent);

        $risque = (new Risque())->setCode('CTX')->setNomComplet('Risque contextuel')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($ent);
        $this->em->persist($risque);

        $avenants = [];
        foreach ([1, 2] as $i) {
            $client = (new Client())->setNom('Client ' . $i)->setExonere(false);
            $client->setEntreprise($ent);
            $this->em->persist($client);

            $piste = (new Piste())->setNom('Affaire ' . $i)->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
                ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))
                ->setClient($client)->setRisque($risque);
            $piste->setEntreprise($ent)->setInvite($proprietaire);
            $this->em->persist($piste);

            $cotation = (new Cotation())->setNom('Cotation ' . $i)->setDuree(365);
            $cotation->setPiste($piste)->setEntreprise($ent);
            $this->em->persist($cotation);

            $avenant = (new Avenant())->setReferencePolice('POL-CTX-' . $i)->setNumero('0')
                ->setDescription('Police ' . $i)
                ->setStartingAt(new \DateTimeImmutable('-30 days'))
                ->setEndingAt(new \DateTimeImmutable('+335 days'));
            $avenant->setEntreprise($ent)->setInvite($proprietaire);
            $cotation->addAvenant($avenant);
            $this->em->persist($avenant);

            $avenants[] = $avenant;
        }

        $this->em->flush();
        $this->client->loginUser($owner);

        return ['proprietaire' => $proprietaire, 'agent' => $agent, 'avenants' => $avenants];
    }

    /**
     * Enregistre un versement (avec sa pièce, comme l'exige la règle) et rend l'id du
     * porteur du lot — celui qui garde le document.
     */
    private function verserAvecPiece(Invite $agent, array $avenants): int
    {
        $this->client->request(
            'POST',
            '/admin/retro-agent/' . $agent->getId() . '/reversement',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'reference' => 'VIR-CTX-1',
                'avecPiece' => true,
                'lignes' => [
                    ['avenantId' => $avenants[0]->getId(), 'montant' => 120.0],
                    ['avenantId' => $avenants[1]->getId(), 'montant' => 80.0],
                ],
            ]),
        );
        self::assertResponseIsSuccessful('Le versement doit s’enregistrer — sinon ce test ne prouve rien.');

        $lignes = $this->em->getRepository(ReversementRetroAgent::class)
            ->findBy(['reference' => 'VIR-CTX-1'], ['id' => 'ASC']);
        self::assertNotEmpty($lignes);

        return (int) $lignes[0]->getId();
    }

    private function attacher(int $reversementId, string $nomFichier): void
    {
        $this->client->request(
            'POST',
            '/admin/document/api/attacher/reversementRetroAgent/' . $reversementId,
            [],
            ['fichiers' => [$this->fichier($nomFichier)]],
        );
        self::assertResponseIsSuccessful('L’attachement doit réussir — sinon ce test ne prouve rien.');
    }

    /**
     * LE PREMIER CHARGEMENT DE L'ONGLET MONTRE LA PIÈCE.
     *
     * C'est le défaut, mot pour mot : la liste s'actualisait et n'affichait rien, jusqu'à
     * ce qu'on clique sur « Réinitialiser ». Exiger un geste de réinitialisation pour voir
     * une donnée présente, c'est laisser croire qu'elle n'existe pas.
     */
    public function testLOngletContextuelMontreSaPieceDesLePremierChargement(): void
    {
        $s = $this->semer();
        $porteurId = $this->verserAvecPiece($s['agent'], $s['avenants']);
        $this->attacher($porteurId, 'bordereau-ctx.txt');

        $this->em->clear();

        $this->client->request('GET', sprintf(
            '/admin/reversementretroagent/api/%d/documents/generic',
            $porteurId,
        ));

        self::assertResponseIsSuccessful('L’URL de collection doit répondre — un 404 se lit comme un onglet vide.');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString(
            'bordereau-ctx',
            $html,
            'La pièce doit apparaître AU PREMIER chargement de l’onglet, sans « Réinitialiser ».',
        );
    }

    /**
     * Et le compte affiché n'est pas zéro.
     *
     * Une liste peut contenir la ligne dans son HTML et annoncer « 0 affiché(s) » : c'est
     * le compteur que l'utilisateur lit, et il doit dire la vérité.
     */
    public function testLeCompteAfficheNEstPasZero(): void
    {
        $s = $this->semer();
        $porteurId = $this->verserAvecPiece($s['agent'], $s['avenants']);
        $this->attacher($porteurId, 'bordereau-compte.txt');

        $this->em->clear();

        $this->client->request('GET', sprintf(
            '/admin/reversementretroagent/api/%d/documents/generic',
            $porteurId,
        ));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        // Une ligne de liste porte son identifiant : c'est le marqueur que compte le
        // list-manager (`[data-item-id]`) pour publier « X affiché(s) ».
        self::assertStringContainsString(
            'data-item-id',
            $html,
            'La collection doit rendre au moins une LIGNE, pas seulement un tableau vide.',
        );
    }

    /**
     * UN MEMBRE NON PORTEUR DU LOT VOIT AUSSI LA PIÈCE.
     *
     * La lecture des justificatifs est lot-consciente : un bordereau couvre tout le
     * virement. L'onglet contextuel doit donc suivre la même règle que la colonne de la
     * liste, sans quoi deux surfaces diraient deux choses de la même pièce.
     */
    public function testUnMembreNonPorteurVoitLaPieceDuLot(): void
    {
        $s = $this->semer();
        $porteurId = $this->verserAvecPiece($s['agent'], $s['avenants']);
        $this->attacher($porteurId, 'bordereau-lot.txt');

        $this->em->clear();
        $lignes = $this->em->getRepository(ReversementRetroAgent::class)
            ->findBy(['reference' => 'VIR-CTX-1'], ['id' => 'ASC']);
        self::assertCount(2, $lignes);
        $autreId = (int) $lignes[1]->getId();
        self::assertNotSame($porteurId, $autreId);

        $this->client->request('GET', sprintf(
            '/admin/reversementretroagent/api/%d/documents/generic',
            $autreId,
        ));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'bordereau-lot',
            (string) $this->client->getResponse()->getContent(),
            'La pièce du virement doit être visible depuis CHAQUE ligne du lot.',
        );
    }
}
