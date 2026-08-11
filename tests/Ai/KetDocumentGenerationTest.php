<?php

namespace App\Tests\Ai;

use App\Ai\Document\DocumentEnAttente;
use App\Ai\Document\DocumentTarificateur;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\PreparerDocumentTool;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\Utilisateur;
use App\Repository\TokenConsumptionRepository;
use App\Token\TokenAccountService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * DU PLAN AU FICHIER : le parcours complet d'un document produit par Ket.
 *
 * Ce que ce test protège avant tout : LE BUDGET ANNONCÉ EST LE BUDGET DÉBITÉ. C'est
 * toute la promesse faite à l'utilisateur au moment où il clique « Valider et
 * produire ». Tout le reste — 402, 409, fail-closed — protège cette promesse.
 *
 * En test, AI_ENGINE=simulated : le moteur ne routera jamais vers l'outil (son
 * match() renvoie null, à dessein). On l'appelle donc DIRECTEMENT, puis on pose le
 * plan dans la meta et on frappe l'endpoint HTTP — même patron que
 * KetSaisieDepuisFichierTest.
 */
class KetDocumentGenerationTest extends WebTestCase
{
    private const ENT = 'PHPUnit KETDOC SARL';
    private const OWNER = 'phpunit-ketdoc-owner@test.local';
    private const GUEST = 'phpunit-ketdoc-guest@test.local';
    private const OTHER = 'phpunit-ketdoc-other@test.local';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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

    /**
     * Nettoyage en SQL brut, dans l'ordre des dépendances.
     *
     * `connected_to_id` d'abord, IMPÉRATIVEMENT : la FK utilisateur → entreprise
     * bloquerait sinon la suppression de l'entreprise. Et `assistant_document`
     * avant les messages, sinon sa FK retient tout le reste — un échec ici casse
     * le kernel des tests SUIVANTS.
     */
    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $emails = [self::OWNER, self::GUEST, self::OTHER];
        $noms = [self::ENT];

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:e)',
            ['e' => $emails], ['e' => ArrayParameterType::STRING],
        );

        foreach (['assistant_document', 'assistant_conversation_fichier', 'assistant_conversation_contexte', 'assistant_message'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN assistant_conversation c ON t.conversation_id = c.id
                 JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom IN (:n)",
                ['n' => $noms], ['n' => ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'DELETE t FROM assistant_conversation t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:n)',
            ['n' => $noms], ['n' => ArrayParameterType::STRING],
        );
        // Sans cette purge, nbConsommations() compterait les lignes d'une exécution
        // antérieure et les assertions « avant / après » deviendraient dépendantes
        // de l'ordre des tests.
        $conn->executeStatement(
            'DELETE tc FROM token_consumption tc LEFT JOIN utilisateur u ON tc.proprietaire_id = u.id WHERE u.email IN (:e)',
            ['e' => $emails], ['e' => ArrayParameterType::STRING],
        );
        foreach (['roles_en_administration', 'roles_en_production', 'roles_en_finance', 'roles_en_marketing', 'roles_en_sinistre'] as $table) {
            $conn->executeStatement(
                "DELETE r FROM {$table} r JOIN invite i ON r.invite_id = i.id JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom IN (:n)",
                ['n' => $noms], ['n' => ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom IN (:n)',
            ['n' => $noms], ['n' => ArrayParameterType::STRING],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (:n)', ['n' => $noms], ['n' => ArrayParameterType::STRING]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:e)', ['e' => $emails], ['e' => ArrayParameterType::STRING]);

        $this->em()->clear();
    }

    /** @return array{0:Entreprise,1:Invite,2:AssistantConversation,3:Utilisateur} */
    private function seed(bool $moduleIa = true, int $paidTokens = 1_000_000): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit Owner')->setVerified(true);
        $owner->setPassword('x');
        $owner->setPaidTokens($paidTokens);
        // Le solde disponible = prépayé + GRATUIT. Pour éprouver le 402, il faut
        // donc vider l'allocation gratuite ET ancrer sa fenêtre maintenant, sinon
        // ensureFreshWindow() la rechargerait aussitôt et le test passerait à côté
        // du cas qu'il vise.
        if ($paidTokens < 1000) {
            $owner->setFreeTokens(0);
            $owner->setFreeWindowStartedAt(new \DateTimeImmutable());
        }
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC-KETDOC')->setAdresse('1 rue du Document')
            ->setTelephone('+243000000000')->setRccm('RCCM-KETDOC')->setIdnat('IDNAT-KETDOC')
            ->setNumimpot('IMP-KETDOC')->setUtilisateur($owner);
        $em->persist($entreprise);

        $compteGuest = (new Utilisateur())->setEmail(self::GUEST)->setNom('PHPUnit Guest')->setVerified(true);
        $compteGuest->setPassword('x');
        $em->persist($compteGuest);

        $invite = (new Invite())->setNom('Collaborateur KETDOC')->setUtilisateur($compteGuest)
            ->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($invite);

        $roles = (new RolesEnAdministration())->setNom('Rôle module IA')->setEntreprise($entreprise)->setInvite($invite);
        $roles->setAccessAssistantIa($moduleIa ? [Invite::ACCESS_LECTURE] : []);
        $em->persist($roles);

        $conversation = (new AssistantConversation())->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($conversation);

        $compteGuest->setConnectedTo($entreprise);
        $em->flush();

        $this->client->loginUser($compteGuest);

        return [$entreprise, $invite, $conversation, $owner];
    }

    /** Les arguments d'un rapport complet — la structure imposée, remplie. */
    private function arguments(string $format = 'docx'): array
    {
        return [
            'titre'         => 'Commissions exigibles — exercice 2026',
            'problematique' => 'Quelles commissions sont exigibles, et pour quel montant ?',
            'introduction'  => "Une commission devient exigible dès que la prime a été réglée à l'assureur.",
            'definitions'   => [['terme' => 'Prime nette', 'explication' => 'Assiette de la commission.']],
            'sections'      => [[
                'titre' => 'Détail',
                'corps' => "| Client | Montant |\n| --- | ---: |\n| KIN AVIA | 12 345,67 |",
            ]],
            'conclusion'    => 'Total : 281 002,44 USD.',
            'format'        => $format,
        ];
    }

    /**
     * Appelle l'outil et pose le plan dans la meta d'un message assistant — ce que
     * ferait sendMessage() avec un moteur réel.
     *
     * @return array{0: AssistantMessage, 1: array<string, mixed>}
     */
    private function planPrepare(Entreprise $entreprise, Invite $invite, AssistantConversation $conversation, array $args): array
    {
        $resultat = static::getContainer()->get(PreparerDocumentTool::class)
            ->execute($args, new AiScope($entreprise, $invite, $conversation));

        self::assertTrue($resultat->data['pret'] ?? false, 'L’outil devait produire un plan prêt.');
        $plan = DocumentEnAttente::planStockable([$resultat->uiAction]);
        self::assertNotNull($plan, 'L’uiAction devait être stockable.');

        $message = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu('Voici le plan du document.')
            ->setMeta([DocumentEnAttente::CLE_PLAN => $plan]);
        $conversation->addMessage($message);
        $this->em()->flush();

        return [$message, $resultat->data['budget']];
    }

    private function produire(Entreprise $e, AssistantConversation $c, AssistantMessage $m, ?string $format = null): array
    {
        // L'EM est partagé entre le test et le contrôleur : sans ce clear(), la
        // collection des messages restée en mémoire empêcherait trouverMessage()
        // de voir le message qu'on vient de créer.
        $this->em()->clear();

        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/document/%d/%d/%d/produire', $e->getId(), $c->getId(), $m->getId()),
            [], [], ['CONTENT_TYPE' => 'application/json'],
            $format === null ? '{}' : json_encode(['format' => $format]),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?: [];
    }

    private function solde(Entreprise $entreprise): int
    {
        $this->em()->clear();
        $frais = $this->em()->getRepository(Entreprise::class)->find($entreprise->getId());

        return static::getContainer()->get(TokenAccountService::class)->availableFor($frais);
    }

    private function nbConsommations(): int
    {
        return count(static::getContainer()->get(TokenConsumptionRepository::class)
            ->findBy([], ['id' => 'DESC'], 500));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * LE TEST CENTRAL. Le nombre affiché sur la barre de décision et le nombre
     * retiré du portefeuille doivent être le même, à l'unité près.
     */
    public function testLeBudgetAnnonceEgaleLesTokensReellementDebites(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed();
        [$message, $budget] = $this->planPrepare($entreprise, $invite, $conversation, $this->arguments());

        $avant = $this->solde($entreprise);
        $reponse = $this->produire($entreprise, $conversation, $message);

        self::assertResponseIsSuccessful();
        self::assertTrue($reponse['success'] ?? false);

        $apres = $this->solde($entreprise);
        self::assertSame(
            $budget['coutEstime'],
            $avant - $apres,
            'Le budget annoncé dans le plan doit être EXACTEMENT ce qui est débité.',
        );
        self::assertSame($budget['coutEstime'], $reponse['budget']['cout']);
        self::assertSame($budget['coutEstime'], $reponse['document']['coutTokens']);
    }

    /** Le descriptif porte tout ce que le bouton affiche : nom, format, taille. */
    public function testLeDocumentProduitPorteNomFormatEtTaille(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed();
        [$message] = $this->planPrepare($entreprise, $invite, $conversation, $this->arguments());

        $document = $this->produire($entreprise, $conversation, $message)['document'] ?? [];

        self::assertSame('docx', $document['format']);
        self::assertSame('DOCX', $document['formatBadge']);
        self::assertSame('Word', $document['formatLibelle']);
        self::assertGreaterThan(0, $document['taille']);
        self::assertNotSame('', $document['tailleLisible']);
        // Nom ASSAINI : slug + date + extension, aucune donnée brute du modèle.
        self::assertMatchesRegularExpression('/^[a-z0-9-]+-\d{8}\.docx$/', $document['nom']);
        self::assertStringContainsString('commissions-exigibles', $document['nom']);
    }

    /** Chaque format débite son propre multiplicateur. */
    public function testChaqueFormatDebiteSonMultiplicateur(): void
    {
        $couts = [];
        foreach (['txt', 'docx', 'pdf'] as $format) {
            $this->cleanUp();
            [$entreprise, $invite, $conversation] = $this->seed();
            [$message] = $this->planPrepare($entreprise, $invite, $conversation, $this->arguments($format));

            $avant = $this->solde($entreprise);
            $this->produire($entreprise, $conversation, $message);
            self::assertResponseIsSuccessful();
            $couts[$format] = $avant - $this->solde($entreprise);
        }

        // txt ×1,0 < docx ×1,5 < pdf ×1,8, sur un contenu identique.
        self::assertLessThan($couts['docx'], $couts['txt']);
        self::assertLessThan($couts['pdf'], $couts['docx']);
    }

    /** Le journal de consommation nomme la pseudo-entité et facture le PROPRIÉTAIRE. */
    public function testLeJournalPorteLaPseudoEntiteEtLeProprietaire(): void
    {
        [$entreprise, $invite, $conversation, $owner] = $this->seed();
        [$message, $budget] = $this->planPrepare($entreprise, $invite, $conversation, $this->arguments());

        $this->produire($entreprise, $conversation, $message);
        self::assertResponseIsSuccessful();

        $ligne = static::getContainer()->get(TokenConsumptionRepository::class)
            ->findOneBy(['entiteNom' => TokenAccountService::ENTITE_DOCUMENT_IA], ['id' => 'DESC']);

        self::assertNotNull($ligne, 'Une ligne de journal « DocumentIa » doit exister.');
        self::assertSame('DocumentIa', $ligne->getEntiteNom());
        self::assertNotSame('Document', $ligne->getEntiteNom(), 'Ne pas confondre avec la GED.');
        self::assertSame('entree', $ligne->getSens());
        // Une production = UNE ligne, de poids égal au coût : le barème n'est pas
        // linéaire (part fixe + arrondi après multiplication).
        self::assertSame(1, $ligne->getNombre());
        self::assertSame($budget['coutEstime'], $ligne->getPoidsTotal());
        // Le propriétaire paie, quel que soit l'invité qui a validé.
        self::assertSame($owner->getId(), $ligne->getProprietaire()?->getId());
    }

    /** ANTI-REJEU : produire deux fois ne débite qu'une fois. */
    public function testUnSecondAppelRenvoieQuatreCentNeufSansRedebiter(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed();
        [$message] = $this->planPrepare($entreprise, $invite, $conversation, $this->arguments());

        $this->produire($entreprise, $conversation, $message);
        self::assertResponseIsSuccessful();
        $apresPremier = $this->solde($entreprise);
        $consoPremier = $this->nbConsommations();

        $this->produire($entreprise, $conversation, $message);
        self::assertResponseStatusCodeSame(409);

        // Le code HTTP n'est que la façade : ce qui compte est l'absence de débit.
        self::assertSame($apresPremier, $this->solde($entreprise));
        self::assertSame($consoPremier, $this->nbConsommations());
    }

    /** Un plan annulé ne peut plus être produit. */
    public function testUnPlanAnnuleNePeutPlusEtreProduit(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed();
        [$message] = $this->planPrepare($entreprise, $invite, $conversation, $this->arguments());

        $this->em()->clear();
        $this->client->request('POST', sprintf(
            '/admin/assistant-ia/api/document/%d/%d/%d/cancel',
            $entreprise->getId(), $conversation->getId(), $message->getId(),
        ));
        self::assertResponseIsSuccessful();

        $avant = $this->solde($entreprise);
        $this->produire($entreprise, $conversation, $message);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($avant, $this->solde($entreprise), 'Une annulation ne doit rien coûter.');
    }

    /** Solde insuffisant : 402, CTA d'achat, et RIEN n'est produit ni débité. */
    public function testSoldeInsuffisantRenvoieQuatreCentDeuxSansRienProduire(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed(paidTokens: 5);
        [$message] = $this->planPrepare($entreprise, $invite, $conversation, $this->arguments('pdf'));

        $avant = $this->solde($entreprise);
        $consoAvant = $this->nbConsommations();

        $reponse = $this->produire($entreprise, $conversation, $message);

        self::assertResponseStatusCodeSame(402);
        self::assertTrue($reponse['blocked'] ?? false);
        self::assertGreaterThan(0, $reponse['coutEstime']);
        self::assertStringContainsString('/admin/tokens/buy', $reponse['buyUrl']);
        self::assertSame($avant, $this->solde($entreprise), 'Un pré-vol ne coûte rien.');
        self::assertSame($consoAvant, $this->nbConsommations());
    }

    /** Module IA refusé : 403, avant tout traitement. */
    public function testModuleIaRefuseDonneQuatreCentTrois(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed(moduleIa: false);

        $message = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu('x')
            ->setMeta([DocumentEnAttente::CLE_PLAN => ['spec' => [], 'format' => 'docx']]);
        $conversation->addMessage($message);
        $this->em()->flush();

        $this->produire($entreprise, $conversation, $message);
        self::assertResponseStatusCodeSame(403);
    }

    /** Message sans plan de document : 404. */
    public function testUnMessageSansPlanDonneQuatreCentQuatre(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed();

        $message = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)->setContenu('Aucun plan ici.')->setMeta([]);
        $conversation->addMessage($message);
        $this->em()->flush();

        $this->produire($entreprise, $conversation, $message);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * FAIL-CLOSED du téléchargement : le document d'un autre invité, dans la même
     * entreprise, est inatteignable.
     */
    public function testLeTelechargementEstFailClosed(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed();
        [$message] = $this->planPrepare($entreprise, $invite, $conversation, $this->arguments());

        $document = $this->produire($entreprise, $conversation, $message)['document'] ?? [];
        self::assertNotEmpty($document['url'] ?? null);

        // Le binaire doit avoir été DÉPLACÉ par Vich : sans nom de stockage, la
        // route répondrait 404 alors même que la ligne existe.
        $this->em()->clear();
        $enBase = $this->em()->getRepository(\App\Entity\AssistantDocument::class)->find((int) $document['idDocument']);
        self::assertNotNull($enBase, 'La ligne du document doit exister.');
        self::assertNotNull($enBase->getNomFichierStocke(), 'Vich doit avoir déposé le binaire (nomFichierStocke).');

        // Le propriétaire du fil télécharge : 200 + pièce jointe.
        $this->em()->clear();
        $this->client->request('GET', $document['url']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'attachment',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );

        // Une conversation qui n'existe pas dans ce fil : 404, jamais le fichier.
        $this->em()->clear();
        $this->client->request('GET', sprintf(
            '/admin/assistant-ia/api/documents-ket/%d/%d/%d/download',
            $entreprise->getId(), $conversation->getId() + 99_999, (int) $document['idDocument'],
        ));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * LE VERROU COMMUN : tant qu'une décision attend, l'outil refuse d'en préparer
     * une seconde — et surtout, il n'émet AUCUNE barre.
     */
    public function testUneSeuleDecisionEnAttenteALaFois(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed();
        $this->planPrepare($entreprise, $invite, $conversation, $this->arguments());

        $second = static::getContainer()->get(PreparerDocumentTool::class)
            ->execute($this->arguments(), new AiScope($entreprise, $invite, $conversation));

        self::assertFalse($second->data['pret']);
        self::assertTrue($second->data['planEnAttente']);
        self::assertNull($second->uiAction, 'Un refus ne doit produire aucune barre de décision.');

        // L'échappatoire : remplacer, ce qui annule d'abord.
        $remplace = static::getContainer()->get(PreparerDocumentTool::class)
            ->execute($this->arguments() + ['remplacerPlanEnAttente' => true], new AiScope($entreprise, $invite, $conversation));

        self::assertTrue($remplace->data['pret']);
        self::assertNotNull($remplace->uiAction);
    }

    /**
     * STRUCTURE INCOMPLÈTE : l'outil NOMME ce qui manque au lieu de livrer un
     * document mutilé, et n'émet aucune barre.
     */
    public function testUneStructureIncompleteEstRefuseeEnNommantCeQuiManque(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed();

        $resultat = static::getContainer()->get(PreparerDocumentTool::class)->execute(
            ['titre' => 'Sans le reste'],
            new AiScope($entreprise, $invite, $conversation),
        );

        self::assertFalse($resultat->data['pret']);
        self::assertNull($resultat->uiAction);
        $manquants = implode(' | ', $resultat->data['manquants']);
        self::assertStringContainsString('problématique', $manquants);
        self::assertStringContainsString('introduction', $manquants);
        self::assertStringContainsString('conclusion', $manquants);
    }

    /**
     * sourceMessageId : le serveur reprend le texte EXACT d'un message du fil, sans
     * que le modèle ait à le recopier. C'est la parade au plafond de 4 096 jetons
     * de sortie — un gros tableau recopié serait tronqué en silence.
     */
    public function testSourceMessageIdRepreadLeTexteDuFilEtResteFailClosed(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed();

        $analyse = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu("| Client | Montant |\n| --- | ---: |\n| DAKOTA SARL | 99 999,00 |");
        $conversation->addMessage($analyse);
        $this->em()->flush();

        $tarificateur = static::getContainer()->get(DocumentTarificateur::class);
        $args = $this->arguments();
        $args['sections'] = [['titre' => 'Reprise', 'sourceMessageId' => $analyse->getId()]];

        $resultat = static::getContainer()->get(PreparerDocumentTool::class)
            ->execute($args, new AiScope($entreprise, $invite, $conversation));

        self::assertTrue($resultat->data['pret']);
        $spec = $resultat->uiAction['spec'];
        self::assertStringContainsString('DAKOTA SARL', $spec['sections'][0]['corps'],
            'Le contenu du message référencé doit être repris tel quel.');
        // Le contenu repris est FACTURÉ : il fait partie du document.
        self::assertGreaterThan(0, $tarificateur->chiffrer('DAKOTA SARL', 'docx')->cout);

        // FAIL-CLOSED : un id hors de cette conversation est ignoré, jamais lu.
        $args['sections'] = [['titre' => 'Ailleurs', 'sourceMessageId' => $analyse->getId() + 99_999]];
        $hors = static::getContainer()->get(PreparerDocumentTool::class)
            ->execute($args, new AiScope($entreprise, $invite, $conversation));

        self::assertFalse($hors->data['pret'], 'Sans section exploitable, le plan ne peut pas être prêt.');
    }

    /**
     * NON-RÉGRESSION du garde-fou anti-plan fantôme : un VRAI plan de document ne
     * doit jamais déclencher l'avertissement « aucun plan en attente ».
     */
    public function testUnPlanDeDocumentNeDeclenchePasLAvertissementDePlanFantome(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed();
        [$message] = $this->planPrepare($entreprise, $invite, $conversation, $this->arguments());

        self::assertTrue(DocumentEnAttente::estEnAttente($message->getMeta()));
        self::assertTrue(
            DocumentEnAttente::aUneDecisionEnAttente($conversation),
            'Le verrou commun doit voir le plan de document — sinon le garde-fou anti-fantôme '
            . 'se retournerait contre une vraie barre de décision.',
        );
    }
}
