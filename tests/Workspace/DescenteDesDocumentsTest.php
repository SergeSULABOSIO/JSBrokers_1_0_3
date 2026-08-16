<?php

namespace App\Tests\Workspace;

use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Service\Document\DescenteDesDocuments;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * « LES FICHIERS DU CLIENT JEAN DE DIEU » — c'est-à-dire TOUT le dossier.
 *
 * L'ANCIEN COMPORTEMENT, ET POURQUOI IL NE TENAIT PAS. La recherche filtrait Document
 * par un critère de rattachement : elle ne rendait donc que les pièces DIRECTEMENT
 * accrochées à la fiche nommée. Sur un client qui porte une police, elle répondait « un
 * seul fichier » — et l'utilisateur, qui savait qu'il y en avait d'autres plus bas,
 * devait les réclamer un par un, niveau par niveau, en corrigeant l'assistant à chaque
 * tour. Ce n'était pas une réponse fausse au sens strict : c'était une réponse
 * incomplète, présentée comme complète, ce qui est pire.
 *
 * LA RÈGLE VÉRIFIÉE ICI. On part de la fiche nommée et on descend, jamais l'inverse :
 *  - depuis le CLIENT   → client, pistes, cotations, polices ;
 *  - depuis la PISTE    → piste, cotations, polices, et RIEN du client ;
 *  - depuis la COTATION → cotation, polices ;
 *  - depuis la POLICE   → la police seule.
 *
 * Le sens de la descente n'est pas un détail de mise en œuvre : remonter ferait sortir
 * du dossier par le haut. Depuis une cotation on atteindrait le client, puis TOUTES ses
 * autres pistes — et « les fichiers de cette cotation » deviendrait « les fichiers de
 * tout le monde ».
 */
class DescenteDesDocumentsTest extends KernelTestCase
{
    private const ENT = 'PHPUnit-Descente SARL';
    private const OWNER = 'phpunit-descente-owner@test.local';

    private EntityManagerInterface $em;
    private DescenteDesDocuments $descente;

    protected function setUp(): void
    {
        static::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->descente = static::getContainer()->get(DescenteDesDocuments::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        foreach (['document', 'avenant', 'cotation', 'piste', 'assureur', 'risque', 'client'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        foreach (['roles_en_production', 'roles_en_administration', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /**
     * Un dossier complet, une pièce par niveau — c'est ce qui rend la descente lisible :
     * le nom du document dit à quel étage il se trouve.
     *
     * @return array{client: Client, piste: Piste, cotation: Cotation, avenant: Avenant}
     */
    private function seed(): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($ent);
        $owner->setConnectedTo($ent);

        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);

        $assureur = (new Assureur())->setNom('SUNU Descente')->setEmail('a@d.test')
            ->setNumimpot('N1')->setIdnat('I1')->setRccm('R1')->setEntreprise($ent);
        $this->em->persist($assureur);

        $risque = (new Risque())->setCode('DSC')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setNomComplet('Risque de descente')->setImposable(true)->setEntreprise($ent);
        $this->em->persist($risque);

        $client = (new Client())->setNom('Jean de Dieu')->setExonere(false)->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($client);

        $piste = (new Piste())->setNom('Piste de Jean')->setTypeAvenant(0)
            ->setDescriptionDuRisque('desc')->setExercice(2026)
            ->setClient($client)->setRisque($risque)->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation de Jean')->setDuree(12)
            ->setPiste($piste)->setAssureur($assureur)->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($cotation);

        $avenant = (new Avenant())
            ->setReferencePolice('POL-DESCENTE-1')
            ->setNumero('0')
            ->setDescription('Police de descente')
            ->setStartingAt(new \DateTimeImmutable('2026-03-01'))
            ->setEndingAt(new \DateTimeImmutable('2027-02-28'));
        $avenant->setCotation($cotation)->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($avenant);

        // UNE pièce par étage. Le côté propriétaire est le seul renseigné — comme dans
        // l'application : rien n'ajoute le document à la collection inverse tant que
        // l'entité n'a pas été rechargée. La descente doit donc INTERROGER la base, et
        // c'est précisément ce que ce jeu de test vérifie au passage.
        foreach ([
            ['Pièce du client', 'setClient', $client],
            ['Pièce de la piste', 'setPiste', $piste],
            ['Pièce de la cotation', 'setCotation', $cotation],
            ['Pièce de la police', 'setAvenant', $avenant],
        ] as [$nom, $setter, $parent]) {
            $document = (new Document())->setNom($nom);
            $document->{$setter}($parent);
            $document->setEntreprise($ent);
            $this->em->persist($document);
        }

        $this->em->flush();

        return ['client' => $client, 'piste' => $piste, 'cotation' => $cotation, 'avenant' => $avenant];
    }

    /** @return list<string> les noms des documents trouvés, triés pour la comparaison */
    private function noms(object $racine): array
    {
        $noms = array_map(
            static fn (array $t) => $t['document']->getNom(),
            $this->descente->depuis($racine)['documents'],
        );
        sort($noms);

        return $noms;
    }

    /** DEPUIS LE CLIENT : les quatre étages, sans exception. */
    public function testDepuisLeClientOnTrouveTousLesEtages(): void
    {
        $d = $this->seed();

        $this->assertSame(
            ['Pièce de la cotation', 'Pièce de la piste', 'Pièce de la police', 'Pièce du client'],
            $this->noms($d['client']),
        );
    }

    /** DEPUIS LA PISTE : la piste et ce qui pend dessous — jamais la pièce du client. */
    public function testDepuisLaPisteOnNeRemontePasAuClient(): void
    {
        $d = $this->seed();

        $this->assertSame(
            ['Pièce de la cotation', 'Pièce de la piste', 'Pièce de la police'],
            $this->noms($d['piste']),
        );
    }

    /** DEPUIS LA COTATION : la cotation et sa police. */
    public function testDepuisLaCotationOnTrouveLaCotationEtSesPolices(): void
    {
        $d = $this->seed();

        $this->assertSame(
            ['Pièce de la cotation', 'Pièce de la police'],
            $this->noms($d['cotation']),
        );
    }

    /** DEPUIS LA POLICE : elle seule. Le bas du dossier est bien le bas. */
    public function testDepuisLaPoliceOnNeTrouveQueLaSienne(): void
    {
        $d = $this->seed();

        $this->assertSame(['Pièce de la police'], $this->noms($d['avenant']));
    }

    /**
     * LE NIVEAU EST RENDU AVEC CHAQUE PIÈCE, dans le vocabulaire des écrans : c'est la
     * première question de qui retrouve un fichier dans une liste — d'où il sort.
     */
    public function testChaquePieceDitDeQuelNiveauElleSort(): void
    {
        $d = $this->seed();
        $libelles = static::getContainer()->get(\App\Service\Workspace\WorkspaceAccessResolver::class)->libellesEntites();

        $parNom = [];
        foreach ($this->descente->depuis($d['client'], $libelles)['documents'] as $trouve) {
            $parNom[$trouve['document']->getNom()] = $trouve;
        }

        $this->assertSame('Client', $parNom['Pièce du client']['entite']);
        $this->assertSame('Avenant', $parNom['Pièce de la police']['entite']);
        // Le niveau est l'INTITULÉ D'ÉCRAN, pas le nom technique : l'utilisateur ne
        // connaît pas « Avenant », il lit « Polices ».
        $this->assertSame(
            $libelles['Avenant'] ?? 'Avenant',
            $parNom['Pièce de la police']['niveau'],
        );
    }

    /**
     * LA DESCENTE EST BORNÉE, et le dit. Un graphe métier reboucle ; sans borne ni
     * plafond, une seule question parcourrait le portefeuille entier — et la réponse
     * n'arriverait jamais.
     */
    public function testLaDescenteEstBorneeEtLAnnonce(): void
    {
        $d = $this->seed();

        $resultat = $this->descente->depuis($d['client']);

        $this->assertFalse($resultat['tronque'], 'Un dossier de quatre étages ne tronque rien.');
        $this->assertLessThanOrEqual(DescenteDesDocuments::NOEUDS_MAX, $resultat['noeuds']);

        // Profondeur 0 : on ne récolte QUE la fiche nommée.
        $this->assertSame(
            ['Pièce du client'],
            array_map(
                static fn (array $t) => $t['document']->getNom(),
                $this->descente->depuis($d['client'], [], 0)['documents'],
            ),
        );
    }
}
