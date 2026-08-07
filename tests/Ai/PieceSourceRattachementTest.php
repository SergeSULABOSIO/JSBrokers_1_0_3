<?php

namespace App\Tests\Ai;

use App\Ai\Fichier\PieceSourceRattachement;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SORT DE LA PIÈCE SOURCE lors d'une saisie depuis un fichier : la règle est
 * DÉRIVÉE (collection du formulaire → relation Document → rien), jamais câblée
 * entité par entité.
 *
 * WebTestCase et non KernelTestCase : FormTreeInspector CONSTRUIT les FormType,
 * et FormListenerFactory::setFiltreEntreprise() appelle getUser()->getConnectedTo().
 * Sans utilisateur authentifié porteur d'un connectedTo, collectionsEditables()
 * renvoie [] EN SILENCE — et tous les niveaux 1 basculeraient à tort en niveau 3.
 */
class PieceSourceRattachementTest extends WebTestCase
{
    private const ENT = 'PHPUnit-PieceSrc SARL';
    private const OWNER = 'phpunit-piecesrc-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private PieceSourceRattachement $rattachement;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->rattachement = static::getContainer()->get(PieceSourceRattachement::class);
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
        foreach (['roles_en_production', 'roles_en_administration'] as $table) {
            $conn->executeStatement("DELETE r FROM {$table} r JOIN entreprise e ON r.entreprise_id = e.id WHERE e.nom = :n", ['n' => self::ENT]);
        }
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    private function connecter(): void
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
    }

    /**
     * Niveau 1 — Cotation expose « documents » (allow_add) dans son formulaire :
     * la pièce devient un élément de CETTE collection, comme à l'écran.
     */
    public function testCotationRattacheParLaCollectionDuFormulaire(): void
    {
        $d = $this->rattachement->resoudre('Cotation', 'Propositions');

        $this->assertSame(PieceSourceRattachement::NIVEAU_COLLECTION, $d['niveau']);
        $this->assertTrue($d['rattachable']);
        $this->assertSame('documents', $d['collection']);
        $this->assertNull($d['avertissement'], 'Rattachement possible : aucun avertissement de perte.');

        $fragment = $this->rattachement->fragmentGabarit($d, '@fichier:7', 'proposition.pdf', '@socle');
        $this->assertSame('collections', $fragment['cible']);
        $this->assertSame('documents', $fragment['fragment']['collection']);
        $element = $fragment['fragment']['elements'][0];
        $this->assertSame('create', $element['op']);
        $this->assertSame(PieceSourceRattachement::ETAPE, $element['etape'], 'Étape nommée => décochable dans la barre.');
        $this->assertSame('proposition.pdf', $element['champs']['nom']);
        $this->assertSame('@fichier:7', $element['champs']['fichier']);
    }

    /** Les 12 entités dont le formulaire porte une collection « documents » sont au niveau 1. */
    public function testToutesLesEntitesAvecCollectionDocumentsSontAuNiveau1(): void
    {
        foreach (['Cotation', 'Avenant', 'Piste', 'Client', 'Tache', 'Partenaire', 'Fournisseur', 'Bordereau', 'Feedback', 'PieceSinistre', 'CompteBancaire', 'OffreIndemnisationSinistre'] as $entite) {
            $d = $this->rattachement->resoudre($entite);
            $this->assertSame(
                PieceSourceRattachement::NIVEAU_COLLECTION,
                $d['niveau'],
                sprintf('%s expose une collection « documents » : la pièce doit y entrer.', $entite),
            );
        }
    }

    /**
     * Niveau 2 — PaiementPrime n'édite pas de collection « documents », mais
     * Document pointe vers lui (inverse « preuves ») : opération de tête chaînée.
     */
    public function testPaiementPrimeRattacheParRelation(): void
    {
        $d = $this->rattachement->resoudre('PaiementPrime', 'Paiements de prime');

        $this->assertSame(PieceSourceRattachement::NIVEAU_RELATION, $d['niveau']);
        $this->assertTrue($d['rattachable']);
        $this->assertSame('paiementPrime', $d['champ']);
        $this->assertNull($d['avertissement']);

        $fragment = $this->rattachement->fragmentGabarit($d, '@fichier:3', 'recu.pdf', '@socle');
        $this->assertSame('operation', $fragment['cible']);
        $this->assertSame('Document', $fragment['fragment']['entite']);
        $this->assertSame('@socle', $fragment['fragment']['champs']['paiementPrime'], 'Chaînage par référence : l’id n’existe pas encore.');
        $this->assertSame('@fichier:3', $fragment['fragment']['champs']['fichier']);
    }

    /** Niveau 0 — l'entité EST un Document : la pièce va dans son propre champ. */
    public function testDocumentPorteLaPieceDansSonPropreChamp(): void
    {
        $d = $this->rattachement->resoudre('Document');

        $this->assertSame(PieceSourceRattachement::NIVEAU_CHAMP_PROPRE, $d['niveau']);
        $this->assertTrue($d['rattachable']);

        $fragment = $this->rattachement->fragmentGabarit($d, '@fichier:9', 'contrat.pdf', '@socle');
        $this->assertSame('champs', $fragment['cible']);
        $this->assertSame(['fichier' => '@fichier:9'], $fragment['fragment']);
    }

    /**
     * Niveau 3 — LE POINT SENSIBLE : aucun rattachement possible. L'utilisateur
     * DOIT être averti que son fichier ne sera pas conservé, et cet avertissement
     * est rédigé par le SERVEUR — pas laissé à la bonne volonté du modèle.
     */
    public function testEntiteSansRattachementAvertitDeLaPerteDuFichier(): void
    {
        $d = $this->rattachement->resoudre('Assureur', 'Assureurs');

        $this->assertSame(PieceSourceRattachement::NIVEAU_AUCUN, $d['niveau']);
        $this->assertFalse($d['rattachable']);
        $this->assertNotNull($d['avertissement'], 'Une perte de donnée silencieuse est inacceptable.');
        $this->assertStringContainsString('NE SERA PAS CONSERVÉ EN BASE', $d['avertissement']);
        $this->assertStringContainsString('Assureurs', $d['avertissement'], 'L’avertissement nomme la rubrique.');
        $this->assertStringContainsString('données extraites', $d['avertissement'], 'Il dit ce qui SERA enregistré.');

        $this->assertNull(
            $this->rattachement->fragmentGabarit($d, '@fichier:1', 'x.pdf', '@socle'),
            'Rien à classer : aucun fragment ne doit être produit.',
        );
    }

    /** Quelques rubriques réellement dépourvues de tout lien Document. */
    public function testAutresRubriquesSansLienDocument(): void
    {
        foreach (['Risque', 'Note', 'Tranche', 'Monnaie', 'Taxe'] as $entite) {
            $d = $this->rattachement->resoudre($entite);
            $this->assertFalse($d['rattachable'], sprintf('%s ne peut pas porter de document.', $entite));
            $this->assertNotNull($d['avertissement'], sprintf('%s doit avertir de la perte du fichier.', $entite));
        }
    }
}
