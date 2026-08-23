<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Service\Retro\LotDeVersement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * LE JUSTIFICATIF D'UN VERSEMENT — un seul fichier, quel que soit le nombre d'affaires.
 *
 * Un reversement de rétrocommission est une sortie de fonds réelle. Deux exigences le
 * gouvernent, et ce test les tient toutes deux :
 *
 *  1. PAS DE VERSEMENT SANS PREUVE. Le refus tombe AVANT la moindre écriture : refuser
 *     après coup laisserait un décaissement enregistré sans justificatif, exactement ce
 *     que la règle interdit.
 *
 *  2. UN SEUL DOCUMENT EN BASE. Un virement en lot solde N affaires avec UN bordereau.
 *     Recopier le fichier sur les N lignes aurait été la solution facile ; la consigne est
 *     l'inverse — le document est persisté une fois, et la lecture du LOT le rend visible
 *     depuis chacune de ses lignes. C'est le porteur (le membre de plus petit id) qui le
 *     garde, et ce choix est déterministe, donc recalculable sans rien stocker de plus.
 */
class ReversementJustificatifTest extends WebTestCase
{
    private const ENT = 'PHPUnit-Justificatif SARL';
    private const OWNER = 'phpunit-justificatif-owner@test.local';

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
        // L'utilisateur pointe l'entreprise ACTIVE : sans rompre ce lien d'abord,
        // l'entreprise est indestructible d'une exécution à l'autre.
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);

        // LES BINAIRES D'ABORD, ET PAR LEUR VRAI NOM. Vich renomme le fichier déposé
        // (SmartUniqueNamer) : un balayage par préfixe ne les retrouverait pas, et chaque
        // exécution laisserait ses pièces dans public/uploads/documents.
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

        // L'ordre suit les dépendances : le classeur naît tout seul avec la première
        // pièce d'un client, l'oublier rendrait l'entreprise indestructible.
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

    /** Un vrai fichier temporaire, au format accepté. */
    private function fichier(string $nom): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'phpunit_just_');
        file_put_contents($chemin, 'bordereau de virement de test, assez long pour etre reconnu');
        $this->binaires[] = $chemin;

        return new UploadedFile($chemin, $nom, 'text/plain', null, true);
    }

    /**
     * Un agent et DEUX affaires : le minimum pour qu'un lot existe, donc pour que la
     * question « où va la pièce ? » se pose.
     *
     * @return array{agent: Invite, avenants: array<int, Avenant>}
     */
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

        $risque = (new Risque())->setCode('JUS')->setNomComplet('Risque justificatif')
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

            $avenant = (new Avenant())->setReferencePolice('POL-JUS-' . $i)->setNumero('0')
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

        return [
            'agent' => $agent,
            'avenants' => $avenants,
            'proprietaire' => $proprietaire,
            'entreprise' => $ent,
        ];
    }

    /** Enregistre un virement couvrant les deux affaires, et rend l'identifiant du porteur. */
    private function verser(Invite $agent, array $avenants, bool $avecPiece = true): array
    {
        $this->client->request(
            'POST',
            '/admin/retro-agent/' . $agent->getId() . '/reversement',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'reference' => 'VIR-JUST-1',
                'avecPiece' => $avecPiece,
                'lignes' => [
                    ['avenantId' => $avenants[0]->getId(), 'montant' => 120.0],
                    ['avenantId' => $avenants[1]->getId(), 'montant' => 80.0],
                ],
            ]),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * LA RUBRIQUE SE CHARGE VRAIMENT.
     *
     * Ce test existe parce qu'elle ne se chargeait pas : déclarée au menu, dans la carte
     * d'accès, avec ses trois canevas — et l'onglet répondait 404. Il manquait deux choses
     * qu'aucun test structurel ne pouvait voir : une action `index` sur le contrôleur, et
     * l'entrée correspondante dans la carte des composants du workspace. Le chargeur
     * traduisait ce 404 en panneau vide, sans un mot.
     *
     * On passe donc par l'URL que le navigateur appelle réellement.
     */
    public function testLaRubriqueSeChargeDansLeWorkspace(): void
    {
        $s = $this->semer();

        // AVEC AU MOINS UNE LIGNE. La première version de ce test chargeait une liste
        // VIDE : elle prouvait que la route répondait, jamais qu'une ligne savait se
        // rendre. Or c'est là que la rubrique tombait — le canevas y demandait
        // « agent.nom », que le rendu d'une ligne lit comme un nom de propriété et non
        // comme un chemin. Une liste vide ne peut pas révéler cela.
        $this->verser($s['agent'], $s['avenants']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf(
            '/espacedetravail/api/load-component/%d/%d?component=_view_manager_production.html.twig&entity=ReversementRetroAgent',
            $s['proprietaire']->getId(),
            $s['entreprise']->getId(),
        ));

        self::assertResponseIsSuccessful('La rubrique doit se charger, pas répondre 404 ni 500 en silence.');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Reversements de rétrocommission', $html);

        // Et la LIGNE dit ce qu'elle doit dire : le bénéficiaire, la police, le compte.
        self::assertStringContainsString('VIR-JUST-1', $html, 'La référence du virement doit paraître.');
        self::assertStringContainsString('Alice Apporteuse', $html, 'Le bénéficiaire doit paraître.');
        self::assertStringContainsString('POL-JUS-1', $html, 'La police réglée doit paraître.');
    }
    /**
     * LE VOLET A DISPARU — et le bouton du rapport mène désormais à la RUBRIQUE.
     *
     * Il y avait deux écrans pour la même donnée : un volet dédié et la rubrique. Deux
     * lectures à maintenir, deux endroits où corriger un bug. Ce test empêche le volet de
     * revenir par mégarde, et vérifie que le rapport porte de quoi ouvrir la rubrique
     * FILTRÉE sur son agent.
     */
    public function testLeVoletADisparuEtLeRapportOuvreLaRubrique(): void
    {
        $s = $this->semer();

        // L'ancienne route n'existe plus : un 404, pas un écran fantôme.
        $this->client->request('GET', '/admin/retro-agent/' . $s['agent']->getId() . '/versements');
        self::assertResponseStatusCodeSame(404);

        // Et le rapport porte l'agent, de quoi ouvrir la rubrique filtrée sur lui.
        $this->client->request('GET', '/admin/retro-agent/' . $s['agent']->getId() . '/rapport');
        self::assertResponseIsSuccessful();
        $html = json_decode((string) $this->client->getResponse()->getContent(), true)['html'];

        self::assertStringContainsString('Versements enregistrés', $html);
        self::assertStringContainsString('retro-agent-rapport-agent-id-value', $html);
        self::assertStringContainsString('Alice Apporteuse', $html, 'Le libellé du filtre doit être le nom de l’agent.');
    }

    /**
     * LA PREUVE EST CELLE DU VIREMENT, PAS DE LA LIGNE.
     *
     * Un bordereau couvre tout un lot. Sans cette règle, deux lignes sur trois d'un virement
     * groupé paraîtraient sans justificatif alors qu'elles sont couvertes — la dette de
     * preuve affichée serait fausse. C'est invisible sur un versement isolé : il faut un lot
     * de deux lignes dont UNE SEULE porte le fichier.
     */
    public function testLaLigneAnnonceLesPiecesDeSonVirement(): void
    {
        $s = $this->semer();
        $porteurId = $this->verser($s['agent'], $s['avenants'])['porteurId'];

        $this->client->request(
            'POST',
            '/admin/document/api/attacher/reversementRetroAgent/' . $porteurId,
            [],
            ['fichiers' => [$this->fichier('bordereau.txt')]],
        );
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $lot = static::getContainer()->get(LotDeVersement::class);
        $lignes = $this->em->getRepository(ReversementRetroAgent::class)
            ->findBy(['reference' => 'VIR-JUST-1'], ['id' => 'ASC']);
        self::assertCount(2, $lignes);

        // Préchargé comme en liste : c'est le chemin réel, et celui dont le coût est borné.
        $lot->prechargerJustificatifs($lignes);

        foreach ($lignes as $ligne) {
            self::assertSame(
                1,
                $lot->compteDeJustificatifs($ligne),
                'Chaque ligne du virement doit annoncer la pièce qui la couvre.',
            );
            self::assertSame('1 pièce', $lot->libelleJustificatif($ligne));
        }
    }

    /** Un versement sans pièce le dit — c'est la dette de preuve, rendue visible. */
    public function testUnVersementSansPieceLAnnonce(): void
    {
        $s = $this->semer();
        $this->verser($s['agent'], $s['avenants']);

        $this->em->clear();
        $lot = static::getContainer()->get(LotDeVersement::class);
        $lignes = $this->em->getRepository(ReversementRetroAgent::class)
            ->findBy(['reference' => 'VIR-JUST-1'], ['id' => 'ASC']);
        $lot->prechargerJustificatifs($lignes);

        self::assertSame(0, $lot->compteDeJustificatifs($lignes[0]));
        self::assertSame('Aucune pièce', $lot->libelleJustificatif($lignes[0]));
    }
    /** Sans pièce annoncée, rien ne s'écrit — et la réponse dit quoi faire. */
    public function testSansJustificatifRienNEstEcrit(): void
    {
        $s = $this->semer();

        $reponse = $this->verser($s['agent'], $s['avenants'], avecPiece: false);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('justificatif', $reponse['message'] ?? '');
        self::assertCount(
            0,
            $this->em->getRepository(ReversementRetroAgent::class)->findBy(['reference' => 'VIR-JUST-1']),
            'Une garde posée avant la boucle ne doit laisser aucune ligne derrière elle.',
        );
    }

    /** Le serveur désigne le PORTEUR du lot : le membre de plus petit id. */
    public function testLeServeurDesigneLePorteurDuLot(): void
    {
        $s = $this->semer();

        $reponse = $this->verser($s['agent'], $s['avenants']);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $reponse['crees']);

        $lignes = $this->em->getRepository(ReversementRetroAgent::class)
            ->findBy(['reference' => 'VIR-JUST-1'], ['id' => 'ASC']);
        self::assertCount(2, $lignes);
        self::assertSame(
            $lignes[0]->getId(),
            $reponse['porteurId'],
            'Le porteur doit être le premier membre du lot, sans quoi le client déposerait sa pièce ailleurs.',
        );
    }

    /**
     * LE TEST CENTRAL : deux affaires, un bordereau, UN SEUL Document — et il est visible
     * depuis les deux lignes du virement.
     */
    public function testUnSeulDocumentPourTouteLeVirementEtVisibleDesDeuxLignes(): void
    {
        $s = $this->semer();
        $porteurId = $this->verser($s['agent'], $s['avenants'])['porteurId'];

        // Le second temps du picker : le dépôt sur la route GÉNÉRIQUE, celle des fiches.
        $this->client->request(
            'POST',
            '/admin/document/api/attacher/reversementRetroAgent/' . $porteurId,
            [],
            ['fichiers' => [$this->fichier('bordereau.txt')]],
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data['crees']);

        $this->em->clear();

        // UN SEUL document en base, quoi qu'il arrive : c'est la consigne.
        $documents = $this->em->getRepository(Document::class)->findAll();
        $documents = array_values(array_filter(
            $documents,
            static fn (Document $d) => $d->getReversementRetroAgent() !== null,
        ));
        self::assertCount(1, $documents, 'Le bordereau ne doit être persisté qu\'UNE fois.');
        self::assertSame($porteurId, $documents[0]->getReversementRetroAgent()?->getId());
        self::assertNotSame('', (string) $documents[0]->getNomFichierStocke(), 'Le binaire doit avoir suivi.');

        // ET IL EST VISIBLE DEPUIS LES DEUX LIGNES. C'est ce qui rend le non-stockage
        // redondant acceptable : aucune ligne du virement ne paraît sans justificatif.
        $lot = static::getContainer()->get(LotDeVersement::class);
        $lignes = $this->em->getRepository(ReversementRetroAgent::class)
            ->findBy(['reference' => 'VIR-JUST-1'], ['id' => 'ASC']);

        self::assertSame($lot->cle($lignes[0]), $lot->cle($lignes[1]), 'Les deux lignes sont UN virement.');
        self::assertCount(
            1,
            $lot->documentsDuLot($lignes),
            'La lecture du lot doit rendre la pièce, d\'où qu\'elle vienne.',
        );
        self::assertSame(
            $porteurId,
            $lot->porteurParmi($lignes)?->getId(),
            'La règle du porteur doit être la même à la relecture qu\'à l\'écriture.',
        );
    }

    /**
     * Une pièce posée sur le SECOND membre — ce que fera Ket, qui attache au reversement
     * que l'utilisateur nomme — reste visible depuis le lot.
     *
     * Sans cette propriété, une lecture limitée au porteur aurait rendu invisibles toutes
     * les pièces classées par l'assistant.
     */
    public function testUnePieceSurUnMembreQuelconqueResteVisibleDuLot(): void
    {
        $s = $this->semer();
        $this->verser($s['agent'], $s['avenants']);

        $lignes = $this->em->getRepository(ReversementRetroAgent::class)
            ->findBy(['reference' => 'VIR-JUST-1'], ['id' => 'ASC']);
        $second = $lignes[1];

        $this->client->request(
            'POST',
            '/admin/document/api/attacher/reversementRetroAgent/' . $second->getId(),
            [],
            ['fichiers' => [$this->fichier('recu.txt')]],
        );
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $lot = static::getContainer()->get(LotDeVersement::class);
        $lignes = $this->em->getRepository(ReversementRetroAgent::class)
            ->findBy(['reference' => 'VIR-JUST-1'], ['id' => 'ASC']);

        self::assertCount(1, $lot->documentsDuLot($lignes));
    }
}
