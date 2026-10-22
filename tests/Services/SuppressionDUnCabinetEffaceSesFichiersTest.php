<?php

namespace App\Tests\Services;

use App\Entity\AssistantConversation;
use App\Entity\AssistantConversationFichier;
use App\Entity\AssistantDocument;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Services\ServiceSuppressionEntreprise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * SUPPRIMER UN CABINET EFFACE TOUS SES FICHIERS (chantier 19, décision de Serge) : un cabinet
 * de test reçoit un fichier à CHAQUE emplacement où l'application en écrit pour lui ; après
 * sa suppression, plus rien de lui ne reste sur le disque.
 */
final class SuppressionDUnCabinetEffaceSesFichiersTest extends KernelTestCase
{
    private const ENT = 'PHPUNIT Cabinet à effacer';
    private const EMAIL = 'phpunit-cabinet-efface@test.local';

    /** @var list<string> */
    private array $fichiers = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        foreach ($this->fichiers as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        $this->nettoyer();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function nettoyer(): void
    {
        $c = $this->em()->getConnection();
        $c->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::EMAIL]);
        foreach ($c->fetchFirstColumn('SELECT id FROM entreprise WHERE nom = :n', ['n' => self::ENT]) as $id) {
            $c->executeStatement('DELETE f FROM assistant_conversation_fichier f JOIN assistant_conversation c ON c.id = f.conversation_id WHERE c.entreprise_id = :i', ['i' => $id]);
            $c->executeStatement('DELETE m FROM assistant_message m JOIN assistant_conversation c ON c.id = m.conversation_id WHERE c.entreprise_id = :i', ['i' => $id]);
            foreach (['assistant_document', 'assistant_conversation', 'document', 'invite'] as $table) {
                $c->executeStatement("DELETE FROM {$table} WHERE entreprise_id = :i", ['i' => $id]);
            }
            $c->executeStatement('DELETE FROM entreprise WHERE id = :i', ['i' => $id]);
        }
        $c->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::EMAIL]);
    }

    private function deposer(string $dossier, string $nom): string
    {
        if (!is_dir($dossier)) {
            mkdir($dossier, 0777, true);
        }
        file_put_contents($chemin = $dossier . '/' . $nom, 'donnée du cabinet');
        $this->fichiers[] = $chemin;

        return $chemin;
    }

    public function testAucunFichierDuCabinetNeResteApresSaSuppression(): void
    {
        $em = $this->em();
        $parametre = static fn (string $nom): string => (string) static::getContainer()->getParameter($nom);
        $jeton = bin2hex(random_bytes(5));

        $u = (new Utilisateur())->setEmail(self::EMAIL)->setNom('PHPUnit')->setVerified(true)->setPassword('x');
        $em->persist($u);
        $e = (new Entreprise())->setNom(self::ENT)->setLicence('L')->setAdresse('A')->setTelephone('+243')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($u);
        $e->setThumbnail("logo-$jeton.png");
        $em->persist($e);
        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true)->setUtilisateur($u)->setEntreprise($e);
        $em->persist($invite);

        $piece = (new Document())->setNom('Pièce');
        $piece->setNomFichierStocke("piece-$jeton.pdf");
        $piece->setEntreprise($e);
        $em->persist($piece);

        $conversation = (new AssistantConversation())->setEntreprise($e)->setInvite($invite);
        $em->persist($conversation);
        $joint = new AssistantConversationFichier();
        $joint->setNomOriginal('joint.pdf');
        $joint->setNomFichierStocke("joint-$jeton.pdf");
        $joint->setConversation($conversation);
        $em->persist($joint);

        $message = (new \App\Entity\AssistantMessage())->setConversation($conversation)->setRole('assistant')->setContenu('Voici le rapport.');
        $conversation->addMessage($message);
        $em->persist($message);
        $produit = (new AssistantDocument())->setConversation($conversation)->setMessage($message)->setEntreprise($e)->setAuteur($u)
            ->setTitre('Rapport')->setNomFichier('rapport.docx')->setFormat('docx')->setMime('application/octet-stream')
            ->setTaille(1)->setCaracteres(1)->setPages(1)->setCoutTokens(0);
        $produit->setNomFichierStocke("produit-$jeton.docx");
        $em->persist($produit);
        $em->flush();
        $id = (int) $e->getId();
        $lignes = [
            'assistant_message' => (int) $message->getId(),
            'assistant_conversation_fichier' => (int) $joint->getId(),
            'assistant_conversation' => (int) $conversation->getId(),
            'assistant_document' => (int) $produit->getId(),
        ];

        $surLeDisque = [
            'pièce' => $this->deposer($parametre('app.dossier_des_pieces'), "piece-$jeton.pdf"),
            'logo' => $this->deposer($parametre('app.dossier_des_logos'), "logo-$jeton.png"),
            'fichier joint à Ket' => $this->deposer($parametre('app.dossier_des_fichiers_de_ket'), "joint-$jeton.pdf"),
            'document produit par Ket' => $this->deposer($parametre('app.dossier_des_documents_de_ket'), "produit-$jeton.docx"),
            'dépôt de l’échange' => $this->deposer($parametre('app.dossier_de_l_echange') . "/$id", 'depot.xlsx'),
            'export de l’échange' => $this->deposer($parametre('app.dossier_de_l_echange') . "/exports/$id", 'export.xlsx'),
            'cache de la voix' => $this->deposer($parametre('app.dossier_de_la_voix_de_ket') . "/$id", 'phrase.wav'),
        ];

        static::getContainer()->get(ServiceSuppressionEntreprise::class)->supprimer($em->find(Entreprise::class, $id));

        foreach ($surLeDisque as $quoi => $chemin) {
            self::assertFileDoesNotExist($chemin, "Le $quoi du cabinet supprimé est resté sur le disque.");
        }
        foreach (['app.dossier_de_l_echange' => "/$id", 'app.dossier_de_l_echange ' => "/exports/$id", 'app.dossier_de_la_voix_de_ket' => "/$id"] as $p => $suffixe) {
            self::assertDirectoryDoesNotExist($parametre(trim($p)) . $suffixe, 'le dossier du cabinet part entier');
        }

        // ET EN BASE : les lignes rattachées INDIRECTEMENT (par la conversation) partent aussi.
        $c = $em->getConnection();
        foreach ($lignes as $table => $idLigne) {
            self::assertSame(0, (int) $c->fetchOne("SELECT COUNT(*) FROM {$table} WHERE id = :i", ['i' => $idLigne]), "Ligne de {$table} restée en base.");
        }
    }

    /**
     * La purge DÉCOUVRE les tables rattachées indirectement (relations du modèle), au lieu d'en
     * tenir une liste : elle trouve au moins celles de l'inventaire du 2026-10-22, et jamais
     * `utilisateur` (un compte peut servir à d'autres cabinets — il est seulement détaché).
     */
    public function testLaPurgeDecouvreLesTablesRattacheesIndirectement(): void
    {
        $service = static::getContainer()->get(ServiceSuppressionEntreprise::class);
        $cartographier = new \ReflectionMethod($service, 'cartographierTables');
        [$tablesScopees] = $cartographier->invoke($service);
        $dependantes = $service->cartographierDependantes($tablesScopees);

        foreach (['assistant_message', 'assistant_tache', 'assistant_programme_etape', 'assistant_conversation_fichier', 'assistant_conversation_contexte', 'crm_ticket_feedback', 'operation'] as $table) {
            self::assertArrayHasKey($table, $dependantes, "{$table} n'est pas purgée avec son cabinet.");
        }
        self::assertArrayNotHasKey('utilisateur', $dependantes);
    }
}
