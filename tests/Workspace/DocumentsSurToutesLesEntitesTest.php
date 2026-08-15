<?php

namespace App\Tests\Workspace;

use App\Ai\Fichier\PieceSourceRattachement;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Service\Workspace\FormTreeInspector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * « TOUTE ENTITÉ MÉTIER PEUT PORTER UNE COLLECTION DE DOCUMENTS » — la promesse, et sa
 * vérification DÉRIVÉE du code plutôt que récitée.
 *
 * POURQUOI UN TEST DE PARITÉ, ET PAS QUELQUES CAS. Une pièce jointe rattachable « à
 * peu près partout » ne vaut rien : c'est justement l'entité oubliée qui perdra le
 * fichier, et personne ne s'en apercevra avant qu'un courtier cherche un contrat qu'il
 * croit avoir versé. La promesse ne tient que si elle est vérifiable d'un bloc, sur la
 * liste réelle des entités — d'où une parité, entité par entité, entre trois choses qui
 * doivent rester d'accord :
 *
 *   1. l'entité expose une collection Doctrine de Documents ;
 *   2. Document porte la relation inverse correspondante ;
 *   3. le FORMULAIRE l'expose, sans quoi il n'y a pas de widget à l'écran — et une
 *      collection qu'on ne peut pas manipuler n'est pas une collection pour
 *      l'utilisateur.
 *
 * ⚠️ WebTestCase, et ce n'est pas un confort : FormTreeInspector CONSTRUIT les FormType,
 * et FormListenerFactory y lit getUser()->getConnectedTo(). Sans utilisateur
 * authentifié porteur d'un connectedTo, collectionsEditables() rend une liste VIDE en
 * SILENCE — et ce test passerait au vert en ne vérifiant rien.
 */
class DocumentsSurToutesLesEntitesTest extends WebTestCase
{
    private const ENT = 'PHPUnit-DocsPartout SARL';
    private const OWNER = 'phpunit-docspartout-owner@test.local';

    /**
     * LES ENTITÉS MÉTIER qui doivent pouvoir porter des pièces, et le nom de leur
     * collection. Cette liste est le CONTRAT : y ajouter une entité est un acte
     * délibéré, et la retirer d'un formulaire fait échouer ce test.
     *
     * Paiement et PaiementPrime nomment leur collection « preuves » : sur eux, un
     * document n'est pas une pièce du dossier mais la PREUVE d'un règlement, et c'est
     * le mot que le formulaire montre à l'utilisateur. On ne l'uniformise pas.
     *
     * Entreprise n'y figure pas : la fiche du cabinet ne passe pas par le canevas de
     * formulaire du workspace, elle n'a donc aucun endroit où poser le widget. Sa
     * relation existe bien en base (entrepriseRattachee), et l'assistant peut y
     * classer un fichier — mais promettre ici un widget qui n'existe pas serait un
     * mensonge de plus, pas un test.
     *
     * @var array<string, string>
     */
    private const ATTENDUES = [
        // Les quinze historiques.
        'Avenant' => 'documents', 'Bordereau' => 'documents', 'Client' => 'documents',
        'CompteBancaire' => 'documents', 'Cotation' => 'documents', 'Feedback' => 'documents',
        'Fournisseur' => 'documents', 'OffreIndemnisationSinistre' => 'documents',
        'Paiement' => 'preuves', 'PaiementPrime' => 'preuves', 'Partenaire' => 'documents',
        'PieceSinistre' => 'documents', 'Piste' => 'documents', 'Tache' => 'documents',
        // Celles que ce lot ouvre.
        'Assureur' => 'documents', 'AutoriteFiscale' => 'documents',
        'ChargeCourtier' => 'documents', 'Chargement' => 'documents',
        'ChargementPourPrime' => 'documents', 'ConditionPartage' => 'documents',
        'Contact' => 'documents', 'DepenseCourtier' => 'documents', 'Groupe' => 'documents',
        'Invite' => 'documents', 'ModelePieceSinistre' => 'documents', 'Monnaie' => 'documents',
        'Note' => 'documents', 'NotificationSinistre' => 'documents', 'Operation' => 'documents',
        'Portefeuille' => 'documents', 'RevenuPourCourtier' => 'documents', 'Risque' => 'documents',
        'Taxe' => 'documents', 'Tranche' => 'documents', 'TypeRevenu' => 'documents',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();
        $this->connecter();
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
        // Document AVANT Risque (clé étrangère), Risque avant Invite/Entreprise.
        $conn->executeStatement('DELETE d FROM document d JOIN entreprise e ON d.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE r FROM risque r JOIN entreprise e ON r.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        foreach (['roles_en_production', 'roles_en_administration'] as $table) {
            $conn->executeStatement("DELETE r FROM {$table} r JOIN entreprise e ON r.entreprise_id = e.id WHERE e.nom = :n", ['n' => self::ENT]);
        }
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** @return array{0:Entreprise,1:Invite,2:Utilisateur} */
    private function connecter(): array
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
        $this->em->flush();

        $this->client->loginUser($owner);

        return [$ent, $inv, $owner];
    }

    /**
     * 1. LA COLLECTION EXISTE EN BASE, et Document porte la relation inverse.
     *
     * On lit les métadonnées Doctrine, jamais les fichiers : une collection déclarée
     * mais mal reliée (mappedBy erroné, cible qui n'est pas Document) échouerait ici,
     * là où un grep serait passé à côté.
     */
    public function testChaqueEntiteMetierPorteUneCollectionDeDocuments(): void
    {
        $manquantes = [];
        foreach (self::ATTENDUES as $entite => $collection) {
            $meta = $this->em->getClassMetadata('App\\Entity\\' . $entite);
            if (!$meta->hasAssociation($collection)) {
                $manquantes[] = sprintf('%s : aucune collection « %s »', $entite, $collection);
                continue;
            }
            $mapping = $meta->getAssociationMapping($collection);
            if (($mapping['targetEntity'] ?? null) !== Document::class) {
                $manquantes[] = sprintf('%s.%s ne pointe pas sur Document', $entite, $collection);
                continue;
            }
            $mappedBy = $mapping['mappedBy'] ?? null;
            if (!is_string($mappedBy) || $mappedBy === '') {
                $manquantes[] = sprintf('%s.%s n’est pas le côté inverse d’une relation', $entite, $collection);
                continue;
            }
            // La relation inverse doit exister SUR Document, et pointer en retour.
            $metaDoc = $this->em->getClassMetadata(Document::class);
            if (!$metaDoc->hasAssociation($mappedBy)) {
                $manquantes[] = sprintf('Document ne porte pas « %s » (attendu par %s)', $mappedBy, $entite);
            }
        }

        $this->assertSame([], $manquantes, "Ces entités ne peuvent pas porter de document :\n" . implode("\n", $manquantes));
    }

    /**
     * 2. LE FORMULAIRE L'EXPOSE — sans quoi il n'y a pas de widget à l'écran, et la
     * collection n'existe que pour le code.
     */
    public function testChaqueFormulaireExposeSaCollectionDeDocuments(): void
    {
        $inspector = static::getContainer()->get(FormTreeInspector::class);

        $manquants = [];
        foreach (self::ATTENDUES as $entite => $collection) {
            $editable = $inspector->collectionEditable($entite, $collection);
            if ($editable === null) {
                $manquants[] = sprintf('%s : « %s » absente du formulaire', $entite, $collection);
                continue;
            }
            if ($editable->childShortName !== 'Document') {
                $manquants[] = sprintf('%s.%s n’édite pas des Documents', $entite, $collection);
                continue;
            }
            if (!$editable->allowAdd) {
                $manquants[] = sprintf('%s.%s n’autorise pas l’ajout', $entite, $collection);
            }
        }

        $this->assertSame([], $manquants, "Collections non manipulables à l’écran :\n" . implode("\n", $manquants));
    }

    /**
     * 3. LA CONSÉQUENCE POUR L'ASSISTANT : plus aucune entité métier ne retombe sur
     * l'avertissement « le fichier ne sera pas conservé ». C'est le défaut que ce lot
     * supprime, énoncé du point de vue de l'utilisateur.
     */
    public function testAucuneEntiteMetierNePerdPlusSaPieceJointe(): void
    {
        $rattachement = static::getContainer()->get(PieceSourceRattachement::class);

        $perdues = [];
        foreach (self::ATTENDUES as $entite => $collection) {
            $descripteur = $rattachement->resoudre($entite);
            if (!$descripteur['rattachable']) {
                $perdues[] = $entite;
                continue;
            }
            // La règle cherche une collection nommée « documents ». Paiement et
            // PaiementPrime nomment la leur « preuves » : ils n'atteignent donc pas le
            // niveau 1, et retombent — correctement — sur la relation typée que
            // Document porte vers eux. La pièce est conservée dans les deux cas ; seul
            // le CHEMIN diffère, et c'est le vocabulaire de l'écran qui le décide.
            $attendu = $collection === 'documents'
                ? PieceSourceRattachement::NIVEAU_COLLECTION
                : PieceSourceRattachement::NIVEAU_RELATION;
            if ($descripteur['niveau'] !== $attendu) {
                $perdues[] = sprintf('%s : niveau %d, attendu %d', $entite, $descripteur['niveau'], $attendu);
            }
        }

        $this->assertSame([], $perdues, "Ces entités perdraient encore la pièce source :\n" . implode("\n", $perdues));
    }

    /**
     * 4. ET CELA MARCHE VRAIMENT, jusqu'à la base : un document ajouté à la collection
     * d'un Risque — entité qui ne pouvait rien porter hier — s'y retrouve, et disparaît
     * avec lui.
     *
     * La cascade est celle d'Avenant (`cascade: ['persist','remove']` + orphanRemoval) :
     * supprimer la fiche emporte ses pièces, plutôt que de les laisser orphelines dans
     * la rubrique Documents.
     */
    public function testUnDocumentSuitSaFicheJusquASaSuppression(): void
    {
        [$ent] = $this->connecterOuReprendre();

        $risque = (new Risque())
            ->setCode('RCA9')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setNomComplet('RC Automobile universelle')->setImposable(true)->setEntreprise($ent);
        $this->em->persist($risque);

        $document = (new Document())->setNom('Note technique du risque');
        $document->setEntreprise($ent);
        $risque->addDocument($document);
        $this->em->persist($document);
        $this->em->flush();

        $idRisque = $risque->getId();
        $idDocument = $document->getId();
        $this->em->clear();

        // Le lien tient dans les DEUX sens : la collection le contient, et le document
        // sait d'où il vient.
        $risque = $this->em->getRepository(Risque::class)->find($idRisque);
        $this->assertCount(1, $risque->getDocuments());
        $this->assertSame($idDocument, $risque->getDocuments()->first()->getId());
        $this->assertSame($idRisque, $risque->getDocuments()->first()->getRisque()?->getId());

        $this->em->remove($risque);
        $this->em->flush();
        $this->em->clear();

        $this->assertNull(
            $this->em->getRepository(Document::class)->find($idDocument),
            'Un document survivant à sa fiche serait une pièce sans contexte dans la rubrique Documents.',
        );
    }

    /** L'entreprise du jeu de test, rechargée après un éventuel em->clear(). */
    private function connecterOuReprendre(): array
    {
        $ent = $this->em->getRepository(Entreprise::class)->findOneBy(['nom' => self::ENT]);
        $this->assertNotNull($ent, 'Le jeu de test doit porter son entreprise.');

        return [$ent];
    }
}
