<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\AnalysePortefeuilleTool;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Assureur;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\Utilisateur;
use App\Services\Finance\VentilationFinanciereService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * E2E applicatif du CA mensuel de Ket : de bout en bout à travers le VRAI
 * endpoint de chat (contrôleur -> AiEngineResolver -> moteur simulé -> outil
 * analyse_portefeuille -> BDD bdm_test), on vérifie que « chiffre d'affaires
 * ventilé par mois » restitue les commissions ENCAISSÉES (HT = CA comptable et
 * TTC = cash), de façon concise. On vérifie aussi l'outil directement sur la
 * vraie base (agrégation réelle des Paiement sur notes de commission).
 *
 * Règle métier : CA du courtier = somme des commissions encaissées. Un
 * encaissement TTC de 115 avec une taxe assureur de 15 % => HT = 100.
 */
class KetFinanceMensuelE2ETest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-cafmois-owner@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit CAF Mensuel SARL';
    private const ANNEE = 2026;

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

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL];
        $noms = [self::ENTREPRISE_NOM];
        $strList = ArrayParameterType::STRING;

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails], ['emails' => $strList],
        );
        $conn->executeStatement(
            "DELETE m FROM assistant_message m
             JOIN assistant_conversation c ON m.conversation_id = c.id
             JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom IN (:noms)",
            ['noms' => $noms], ['noms' => $strList],
        );
        // Ordre des clés étrangères : paiement -> offre -> notif -> note -> risque/client -> taxe/assureur.
        foreach ([
            'assistant_conversation', 'paiement', 'offre_indemnisation_sinistre',
            'notification_sinistre', 'note', 'risque', 'client', 'taxe', 'assureur',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms], ['noms' => $strList],
            );
        }
        $conn->executeStatement(
            'DELETE tc FROM token_consumption tc LEFT JOIN utilisateur u ON tc.proprietaire_id = u.id WHERE u.email IN (:emails)',
            ['emails' => $emails], ['emails' => $strList],
        );
        $conn->executeStatement(
            "DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email IN (:emails) OR e.nom IN (:noms)",
            ['emails' => $emails, 'noms' => $noms], ['emails' => $strList, 'noms' => $strList],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (:noms)', ['noms' => $noms], ['noms' => $strList]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:emails)', ['emails' => $emails], ['emails' => $strList]);
    }

    /**
     * Propriétaire (accès total, bypass du resolver) + une entreprise avec une
     * taxe assureur de 15 % et un encaissement de commission de 115 (TTC) en
     * mars de l'année de test, sur une note adressée à l'assureur.
     *
     * @return array{owner: Invite, entreprise: Entreprise}
     */
    private function seed(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $owner = new Utilisateur();
        $owner->setEmail(self::OWNER_EMAIL);
        $owner->setNom('PHPUnit CAF');
        $owner->setVerified(true);
        $owner->setPassword($hasher->hashPassword($owner, self::PASSWORD));
        $owner->setPaidTokens(1_000_000); // l'assistant est réservé aux comptes payants
        $em->persist($owner);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-CAF');
        $entreprise->setAdresse('1 rue du CA');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-CAF');
        $entreprise->setIdnat('IDNAT-CAF');
        $entreprise->setNumimpot('IMP-CAF');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $ownerInvite = new Invite();
        $ownerInvite->setNom('Administrateur');
        $ownerInvite->setUtilisateur($owner);
        $ownerInvite->setEntreprise($entreprise);
        $ownerInvite->setProprietaire(true);
        $em->persist($ownerInvite);

        $assureur = new Assureur();
        $assureur->setNom('Assureur CAF');
        $assureur->setEmail('assureur-caf@test.local');
        $assureur->setNumimpot('IMP-ASS');
        $assureur->setIdnat('IDNAT-ASS');
        $assureur->setRccm('RCCM-ASS');
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        // Note de commission (adressée à l'assureur) + son encaissement.
        $note = new Note();
        $note->setNom('Note commission CAF');
        $note->setType(0);
        $note->setAddressedTo(Note::TO_ASSUREUR);
        $note->setReference('N-CAF-1');
        $note->setValidated(true);
        $note->setSignature('');
        $note->setAssureur($assureur);
        $note->setEntreprise($entreprise);
        $em->persist($note);

        $paiement = new Paiement();
        $paiement->setMontant(115.0);
        $paiement->setReference('ENC-CAF-1');
        $paiement->setPaidAt(new \DateTimeImmutable(sprintf('%d-03-15 10:00:00', self::ANNEE)));
        $paiement->setNote($note);
        $paiement->setEntreprise($entreprise);
        $em->persist($paiement);

        // ── Volet SINISTRE : risque « Incendie », indemnisation payable 500, payé 200 (solde 300) ──
        $assure = new Client();
        $assure->setNom('Assuré CAF');
        $assure->setExonere(false);
        $assure->setEntreprise($entreprise);
        $em->persist($assure);

        $risque = new Risque();
        $risque->setCode('INC');
        $risque->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE);
        $risque->setNomComplet('Incendie');
        $risque->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $sinistre = new NotificationSinistre();
        $sinistre->setAssureur($assureur);
        $sinistre->setAssure($assure);
        $sinistre->setRisque($risque);
        $sinistre->setOccuredAt(new \DateTimeImmutable(sprintf('%d-04-10 09:00:00', self::ANNEE)));
        $sinistre->setEntreprise($entreprise);
        $em->persist($sinistre);

        $offre = new OffreIndemnisationSinistre();
        $offre->setMontantPayable(500.0);
        $offre->setBeneficiaire('Assuré CAF');
        $offre->setNotificationSinistre($sinistre);
        $offre->setEntreprise($entreprise);
        $em->persist($offre);

        $indemnite = new Paiement();
        $indemnite->setMontant(200.0);
        $indemnite->setReference('IND-CAF-1');
        $indemnite->setPaidAt(new \DateTimeImmutable(sprintf('%d-05-20 10:00:00', self::ANNEE)));
        $indemnite->setOffreIndemnisationSinistre($offre);
        $indemnite->setEntreprise($entreprise);
        $em->persist($indemnite);

        $em->flush();

        // Taxe assureur 15 % (sert au calcul HT = TTC / 1,15). La colonne
        // `taxe.description` de bdm_test est NOT NULL mais NON mappée par l'entité
        // (drift de schéma) : insertion SQL directe pour la renseigner.
        $em->getConnection()->insert('taxe', [
            'entreprise_id' => $entreprise->getId(),
            'code'          => 'TAX-ASS',
            'description'   => 'Taxe assureur (test)',
            'redevable'     => Taxe::REDEVABLE_ASSUREUR,
            'taux_iard'     => '15.00',
            'taux_vie'      => '0.00',
            'created_at'    => '2026-01-01 00:00:00',
        ]);

        return ['owner' => $ownerInvite, 'entreprise' => $entreprise];
    }

    private function makeConversation(Entreprise $entreprise, Invite $invite): AssistantConversation
    {
        $conversation = (new AssistantConversation())->setEntreprise($entreprise)->setInvite($invite);
        $this->em()->persist($conversation);
        $this->em()->flush();

        return $conversation;
    }

    private function jsonResponse(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * L'OUTIL, sur la vraie base : le CA mensuel agrège les commissions
     * encaissées (Paiement sur notes de commission) et sépare HT / TTC.
     */
    public function testOutilCaMensuelAgregeLesCommissionsEncaissees(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $tool = static::getContainer()->get(AnalysePortefeuilleTool::class);
        $scope = new AiScope($e, $owner);

        $result = $tool->execute(['analyse' => 'chiffre_affaires_mensuel', 'annee' => self::ANNEE], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(self::ANNEE, $result->data['annee']);
        // Mars : cash TTC = 115 ; CA HT = 115 / 1,15 = 100.
        $this->assertSame(115.0, $result->data['commissionEncaisseeTtc'][3]);
        $this->assertSame(100.0, $result->data['commissionEncaisseeHt'][3]);
        // Janvier : rien.
        $this->assertSame(0.0, $result->data['commissionEncaisseeTtc'][1]);
        $this->assertSame(115.0, $result->data['totalTtc']);
        $this->assertSame(100.0, $result->data['totalHt']);
    }

    /** L'OUTIL, sur la vraie base : CA ventilé par assureur (HT 100 / TTC 115). */
    public function testVentilationCaParAssureur(): void
    {
        ['entreprise' => $e] = $this->seed();
        $service = static::getContainer()->get(VentilationFinanciereService::class);

        $data = $service->chiffreAffaires($e, 'assureur', self::ANNEE);

        $this->assertSame('assureur', $data['dimension']);
        $this->assertNotEmpty($data['lignes']);
        $this->assertSame('Assureur CAF', $data['lignes'][0]['libelle']);
        $this->assertSame(115.0, $data['lignes'][0]['caTtc']);
        $this->assertSame(100.0, $data['lignes'][0]['caHt']);
    }

    /** L'OUTIL, sur la vraie base : sinistres par risque (payable 500 / payé 200 / solde 300). */
    public function testVentilationSinistresParRisque(): void
    {
        ['entreprise' => $e] = $this->seed();
        $service = static::getContainer()->get(VentilationFinanciereService::class);

        $data = $service->sinistres($e, 'risque', self::ANNEE);

        $this->assertSame('sinistres', $data['mesure']);
        $this->assertNotEmpty($data['lignes']);
        $ligne = $data['lignes'][0];
        $this->assertSame('Incendie', $ligne['libelle']);
        $this->assertSame(500.0, $ligne['payable']);
        $this->assertSame(200.0, $ligne['paye']);
        $this->assertSame(300.0, $ligne['solde']);
        $this->assertSame(300.0, $data['totalSolde']);
    }

    /** DE BOUT EN BOUT via le chat : « CA par assureur » routé et rendu. */
    public function testChatCaParAssureur(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $owner);

        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/messages/%d/%d', $e->getId(), $conversation->getId()),
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['contenu' => 'Chiffre d\'affaires par assureur en ' . self::ANNEE]),
        );
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        $this->assertFalse($data['assistant']['refus']);
        $this->assertStringContainsString('Assureur CAF', $data['assistant']['contenu']);

        $meta = $this->em()->getRepository(AssistantMessage::class)
            ->findOneBy(['role' => AssistantMessage::ROLE_ASSISTANT], ['id' => 'DESC'])
            ->getMeta();
        $this->assertSame('analyse_portefeuille', $meta['tool']);
    }

    /**
     * DE BOUT EN BOUT via le chat : « CA ventilé par mois » est routé vers
     * chiffre_affaires_mensuel, la réponse cite le CA (HT et TTC) et reste
     * concise — plus de confusion ni d'excuse.
     */
    public function testChatCaVentileParMois(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $owner);

        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/messages/%d/%d', $e->getId(), $conversation->getId()),
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['contenu' => 'Affiche le chiffre d\'affaires ventilé par mois en ' . self::ANNEE]),
        );
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        $this->assertFalse($data['assistant']['refus']);
        $contenu = $data['assistant']['contenu'];
        $this->assertStringContainsString("Chiffre d'affaires", $contenu);
        $this->assertStringContainsString('HT 100,00', $contenu);
        $this->assertStringContainsString('TTC 115,00', $contenu);

        // Routage réel vers le bon outil (traçabilité meta).
        $meta = $this->em()->getRepository(AssistantMessage::class)
            ->findOneBy(['role' => AssistantMessage::ROLE_ASSISTANT], ['id' => 'DESC'])
            ->getMeta();
        $this->assertSame('analyse_portefeuille', $meta['tool']);

        // Concision : réponse courte, sans excuse ni télescopage de notions.
        $this->assertLessThanOrEqual(600, mb_strlen($contenu), 'La réponse CA mensuel doit rester concise.');
        $this->assertStringNotContainsStringIgnoringCase('excuse', $contenu);
        $this->assertStringNotContainsStringIgnoringCase('production encaissée', $contenu);
    }
}
