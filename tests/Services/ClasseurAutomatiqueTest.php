<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Utilisateur;
use App\Service\Document\ClasseurDuClient;
use App\Services\Search\PortefeuilleScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * TOUT CLIENT A SON CLASSEUR, ET TOUT DOCUMENT DE SON DOSSIER Y TOMBE — sur la vraie base.
 *
 * CE QU'UN TEST UNITAIRE NE PROUVERAIT PAS, et c'est l'essentiel ici. Le rangement est
 * posé au ras de Doctrine, dans `onFlush` : le seul moment où l'on peut faire naître un
 * classeur en même temps que le document qui s'y range. Or c'est un moment où Doctrine a
 * déjà fait son inventaire, et où une entité créée sans qu'on lui calcule son jeu de
 * changements N'EST PAS INSÉRÉE — silencieusement. Un doublon de mécanique
 * (`computeChangeSet` là où il fallait `recomputeSingleEntityChangeSet`, ou l'inverse)
 * laisse `classeur_id` à NULL sans lever la moindre erreur. Seul un aller-retour en base,
 * suivi d'une relecture, distingue « rangé » de « cru rangé ».
 *
 * LE CHEMIN LE PLUS LONG EST CELUI QU'ON TESTE. Un document rattaché à une POLICE ne
 * connaît pas son client : il faut remonter la cotation, puis la piste, puis le client —
 * quatre relations. C'est le cas qui casse quand les chemins divergent, et c'est aussi le
 * cas le plus fréquent en exploitation.
 */
class ClasseurAutomatiqueTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-classeur-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Classeur SARL';
    private const CLIENT_NOM = 'PHPUnit Client Kibali';

    protected function setUp(): void
    {
        static::bootKernel();
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

    private function service(): ClasseurDuClient
    {
        return static::getContainer()->get(ClasseurDuClient::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();

        // Enfants avant parents. Le classeur précède le client : sa clé étrangère est en
        // ON DELETE CASCADE, mais le supprimer d'abord rend le nettoyage indépendant de
        // cette contrainte — donc lisible.
        foreach (['document', 'classeur', 'avenant', 'cotation', 'piste', 'client', 'portefeuille', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER_EMAIL]);
    }

    /**
     * Un dossier réel : client → piste → cotation → police, dans un portefeuille.
     *
     * @return array{entreprise: Entreprise, invite: Invite, client: Client, piste: Piste, cotation: Cotation, avenant: Avenant}
     */
    private function seed(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)
            ->setNom('PHPUnit Classeur')
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)
            ->setLicence('LIC-CLA')
            ->setAdresse('1 rue des Classeurs')
            ->setTelephone('+243000000020')
            ->setRccm('RCCM-CLA')
            ->setIdnat('IDNAT-CLA')
            ->setNumimpot('IMP-CLA')
            ->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $invite = (new Invite())->setNom('Serge');
        $invite->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        $portefeuille = (new Portefeuille())->setNom('Portefeuille Classeur')->setGestionnaire($invite);
        $portefeuille->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($portefeuille);

        $client = (new Client())->setNom(self::CLIENT_NOM);
        $client->setPortefeuille($portefeuille)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Piste Classeur')
            ->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque de test classeur')
            ->setExercice(2026)
            ->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation Classeur')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($cotation);

        // Une police se désigne par sa référence, non par un nom.
        $avenant = (new Avenant())
            ->setReferencePolice('POL-CLA-1')
            ->setNumero('0')
            ->setDescription('Police de test classeur')
            ->setStartingAt(new \DateTimeImmutable('2026-03-01'))
            ->setEndingAt(new \DateTimeImmutable('2027-02-28'));
        $avenant->setCotation($cotation)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($avenant);

        $em->flush();

        return compact('entreprise', 'invite', 'client', 'piste', 'cotation', 'avenant');
    }

    private function document(string $nom, Entreprise $entreprise, Invite $invite): Document
    {
        $document = (new Document())->setNom($nom);
        $document->setEntreprise($entreprise)->setInvite($invite);

        return $document;
    }

    /**
     * LE CAS CENTRAL : un document rattaché à la POLICE atterrit dans le classeur du
     * client, quatre relations plus haut — et le classeur naît dans le même flush.
     *
     * La relecture par SQL n'est pas un excès de zèle : lire l'objet en mémoire dirait
     * « rangé » même si l'insertion n'avait pas eu lieu, puisque c'est nous qui venons de
     * poser la relation sur l'objet. La base est le seul témoin.
     */
    public function testUnDocumentDePoliceVaDansLeClasseurDuClient(): void
    {
        $seed = $this->seed();

        $document = $this->document('Contrat de la police', $seed['entreprise'], $seed['invite']);
        $document->setAvenant($seed['avenant']);
        $this->em()->persist($document);
        $this->em()->flush();

        $classeurId = $this->em()->getConnection()->fetchOne(
            'SELECT classeur_id FROM document WHERE id = :id',
            ['id' => $document->getId()],
        );

        self::assertNotNull($classeurId, 'Le document doit être rangé : sans cela, classeur_id reste NULL sans erreur.');
        self::assertNotFalse($classeurId);

        $classeur = $this->em()->getRepository(Classeur::class)->find((int) $classeurId);
        self::assertInstanceOf(Classeur::class, $classeur);
        self::assertSame(self::CLIENT_NOM, $classeur->getNom(), 'Le classeur porte le nom du client.');
        self::assertSame($seed['client']->getId(), $classeur->getClient()?->getId(), 'Et il lui est RELIÉ, pas seulement homonyme.');
        self::assertSame($seed['entreprise']->getId(), $classeur->getEntreprise()?->getId(), 'Le classeur reste dans le périmètre de l’entreprise.');
    }

    /**
     * UN SEUL CLASSEUR PAR CLIENT, quelle que soit la porte d'entrée.
     *
     * Trois documents arrivent par trois niveaux différents du même dossier — le client
     * lui-même, sa piste, sa police. S'ils produisaient trois classeurs, le dossier
     * serait éparpillé entre trois meubles et la fonction manquerait son objet.
     */
    public function testTroisNiveauxDuMemeDossierPartagentUnSeulClasseur(): void
    {
        $seed = $this->seed();
        $em = $this->em();

        $surClient = $this->document('Pièce du client', $seed['entreprise'], $seed['invite']);
        $surClient->setClient($seed['client']);
        $em->persist($surClient);

        $surPiste = $this->document('Pièce de la piste', $seed['entreprise'], $seed['invite']);
        $surPiste->setPiste($seed['piste']);
        $em->persist($surPiste);

        $surPolice = $this->document('Pièce de la police', $seed['entreprise'], $seed['invite']);
        $surPolice->setAvenant($seed['avenant']);
        $em->persist($surPolice);

        $em->flush();

        $ids = $em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT classeur_id FROM document WHERE id IN (:ids)',
            ['ids' => [$surClient->getId(), $surPiste->getId(), $surPolice->getId()]],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        self::assertCount(1, $ids, 'Les trois pièces du même client doivent partager UN classeur.');
        self::assertNotNull($ids[0]);

        $nombre = $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM classeur WHERE client_id = :id',
            ['id' => $seed['client']->getId()],
        );
        self::assertSame(1, (int) $nombre, 'Et il ne doit en exister qu’un en base, pas un par écriture.');
    }

    /**
     * UN CLASSEUR CHOISI À LA MAIN N'EST JAMAIS DÉFAIT.
     *
     * C'est la limite volontaire du rangement automatique : il comble un vide, il ne
     * corrige pas une décision. Déplacer d'office la pièce qu'un utilisateur vient de
     * classer dans « Contrats 2026 » défairait son travail sans le lui dire — et il ne le
     * découvrirait qu'en cherchant le document là où il l'avait mis.
     */
    public function testUnClasseurDejaChoisiEstRespecte(): void
    {
        $seed = $this->seed();
        $em = $this->em();

        $manuel = (new Classeur())->setNom('Contrats 2026')->setDescription('Rangé à la main');
        $manuel->setEntreprise($seed['entreprise'])->setInvite($seed['invite']);
        $em->persist($manuel);

        $document = $this->document('Pièce déjà classée', $seed['entreprise'], $seed['invite']);
        $document->setAvenant($seed['avenant'])->setClasseur($manuel);
        $em->persist($document);
        $em->flush();

        $classeurId = $em->getConnection()->fetchOne(
            'SELECT classeur_id FROM document WHERE id = :id',
            ['id' => $document->getId()],
        );
        self::assertSame($manuel->getId(), (int) $classeurId, 'Le classeur choisi par l’utilisateur doit rester.');

        // Le classeur du client existe — il naît avec le client — mais il doit être resté
        // VIDE : la pièce n'y a pas été aspirée au passage.
        $dansLeDossier = $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM document d JOIN classeur c ON d.classeur_id = c.id WHERE c.client_id = :id',
            ['id' => $seed['client']->getId()],
        );
        self::assertSame(0, (int) $dansLeDossier, 'Le dossier du client doit rester vide : rien n’a été déplacé.');
    }

    /**
     * UN DOCUMENT SANS CLIENT RESTE NON CLASSÉ.
     *
     * Toutes les pièces ne relèvent pas d'un dossier client : celle d'un bordereau, d'un
     * fournisseur, d'un compte bancaire n'appartient à personne. Lui inventer un classeur
     * la ferait apparaître dans le dossier d'un client au hasard — un faux rangement est
     * plus nuisible qu'une absence de rangement, parce qu'on lui fait confiance.
     */
    public function testUnDocumentSansClientResteNonClasse(): void
    {
        $seed = $this->seed();

        $document = $this->document('Pièce sans dossier', $seed['entreprise'], $seed['invite']);
        $this->em()->persist($document);
        $this->em()->flush();

        $classeurId = $this->em()->getConnection()->fetchOne(
            'SELECT classeur_id FROM document WHERE id = :id',
            ['id' => $document->getId()],
        );
        self::assertNull($classeurId === false ? null : $classeurId, 'Aucun classeur ne doit être inventé.');
    }

    /**
     * UNE PIÈCE RATTACHÉE APRÈS COUP REJOINT LE DOSSIER.
     *
     * Le cas est réel : une pièce est d'abord enregistrée seule, puis reliée à une police
     * lors d'une édition. Si seules les créations étaient traitées, elle resterait non
     * classée à vie — et l'utilisateur ne verrait jamais pourquoi celle-là manque au
     * dossier.
     */
    public function testUnRattachementPosteRieurRangeLaPiece(): void
    {
        $seed = $this->seed();
        $em = $this->em();

        $document = $this->document('Pièce rattachée après coup', $seed['entreprise'], $seed['invite']);
        $em->persist($document);
        $em->flush();

        self::assertNull($document->getClasseur(), 'Prérequis : elle part sans dossier.');

        $document->setAvenant($seed['avenant']);
        $em->flush();

        $classeurId = $em->getConnection()->fetchOne(
            'SELECT classeur_id FROM document WHERE id = :id',
            ['id' => $document->getId()],
        );
        self::assertNotEmpty($classeurId, 'La modification doit ranger la pièce, pas seulement la création.');
    }

    /**
     * LES CHEMINS VERS LE CLIENT SONT PRATICABLES — le contrat avec le périmètre.
     *
     * Les chemins ne sont pas écrits dans ce service : ils sont dérivés de ceux du
     * périmètre portefeuille, pour qu'il n'y ait qu'une vérité. Le revers, c'est qu'un
     * chemin devenu faux là-bas ne casse rien de visible ici : la remontée s'arrête, le
     * document reste non classé, et personne ne l'apprend. Ce test parcourt chaque chemin
     * sur le modèle et exige que chaque maillon existe.
     */
    public function testChaqueCheminVersLeClientEstPraticable(): void
    {
        $em = $this->em();
        $chemins = PortefeuilleScope::cheminsVersLeClient('Document');
        self::assertNotEmpty($chemins, 'Sans chemin, aucun document ne serait jamais rangé.');

        foreach ($chemins as $chemin) {
            $classe = Document::class;
            foreach (explode('.', $chemin) as $segment) {
                $meta = $em->getClassMetadata($classe);
                self::assertTrue(
                    $meta->hasAssociation($segment),
                    sprintf('Le chemin « %s » traverse « %s », qui n’existe pas sur %s.', $chemin, $segment, $classe),
                );
                self::assertTrue(
                    method_exists($classe, 'get' . ucfirst($segment)),
                    sprintf('Le chemin « %s » exige un accesseur get%s sur %s.', $chemin, ucfirst($segment), $classe),
                );
                $classe = $meta->getAssociationTargetClass($segment);
            }
            self::assertSame(
                Client::class,
                $classe,
                sprintf('Le chemin « %s » doit aboutir à un Client, il aboutit à %s.', $chemin, $classe),
            );
        }
    }

    /**
     * LE CLASSEUR NAÎT AVEC LE CLIENT, sans attendre sa première pièce.
     *
     * « Tout client a son classeur » n'est pas « tout client qui a reçu un document ». Un
     * dossier qui n'apparaît qu'au premier fichier laisse la liste des classeurs
     * incomplète, et fait croire que certains clients n'y ont pas droit.
     */
    public function testUnClientNeufRecoitSonClasseurImmediatement(): void
    {
        $seed = $this->seed();

        $nombre = $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM classeur WHERE client_id = :id',
            ['id' => $seed['client']->getId()],
        );

        self::assertSame(1, (int) $nombre, 'Le classeur doit exister dès la création du client, même vide.');
    }

    /**
     * LE CLASSEUR SUIT LE NOM DU CLIENT.
     *
     * Un client renommé ne doit ni recevoir un second classeur — le lien l'en empêche —
     * ni conserver un classeur à son ancien nom, ce qui ferait mentir l'écran tout en
     * gardant la relation juste.
     */
    public function testLeClasseurSuitLeRenommageDuClient(): void
    {
        $seed = $this->seed();

        $classeur = $this->service()->pour($seed['client']);
        $this->em()->flush();
        $idInitial = $classeur->getId();
        self::assertSame(self::CLIENT_NOM, $classeur->getNom());

        $seed['client']->setNom(self::CLIENT_NOM . ' SARL');
        $this->em()->flush();

        $rappele = $this->service()->pour($seed['client']);
        $this->em()->flush();

        self::assertSame($idInitial, $rappele->getId(), 'Le même classeur, jamais un second.');
        self::assertSame(self::CLIENT_NOM . ' SARL', $rappele->getNom(), 'Avec le nom à jour.');
    }
}
