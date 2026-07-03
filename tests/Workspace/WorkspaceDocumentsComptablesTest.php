<?php

namespace App\Tests\Workspace;

use App\Comptabilite\CourtierEcritureComptableService;
use App\Comptabilite\CourtierSuiviFiscalService;
use App\Entity\AutoriteFiscale;
use App\Entity\Bordereau;
use App\Entity\ChargeCourtier;
use App\Entity\CompteBancaire;
use App\Entity\Depense;
use App\Entity\DepenseCourtier;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Entity\RolesEnFinance;
use App\Entity\Taxe;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels des documents comptables OHADA du COURTIER (workspace) :
 *  - invariants comptables (partie double, actif = passif, résultat cohérent, TFT
 *    réconciliée) sur un jeu d'opérations couvrant tous les cas du schéma
 *    d'écritures (encaissement de commission via bordereau avec paiement partiel,
 *    rétro-commission, reversements de taxes assureur ET courtier, dépenses
 *    payée/engagée/annulée, capital social, paiement de sinistre EXCLU) ;
 *  - suivi fiscal (collecté / déductible / payable / payé / soldes, par redevable) ;
 *  - gating par rôle (RolesEnFinance::accessDocumentComptable, fail-closed) sur le
 *    composant, l'export ET le menu du workspace ;
 *  - export Excel (unitaire, suivi fiscal et classeur complet).
 * Chaque test crée ses données et les nettoie ensuite (exercice isolé : 2032).
 */
class WorkspaceDocumentsComptablesTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-doccpt-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-doccpt-guest@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit Compta SARL';
    private const DENIED_MARKER = 'jsb-access-denied';
    private const EXERCICE = 2032;

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

    private function makeUser(string $email): Utilisateur
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new Utilisateur();
        $user->setEmail($email);
        $user->setNom('PHPUnit');
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);

        return $user;
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL];

        $conn->executeStatement(
            "UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        // Enfants d'abord (FK), tous scopés par l'entreprise de test (AuditableTrait).
        foreach ([
            'paiement', 'note', 'bordereau', 'offre_indemnisation_sinistre',
            'depense_courtier', 'charge_courtier', 'compte_bancaire',
            'autorite_fiscale', 'taxe',
            'roles_en_finance', 'roles_en_marketing', 'roles_en_production',
            'roles_en_sinistre', 'roles_en_administration',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN entreprise e ON t.entreprise_id = e.id
                 WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM]
            );
        }
        $conn->executeStatement(
            "DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email IN (:emails) OR e.nom = :nom",
            ['emails' => $emails, 'nom' => self::ENTREPRISE_NOM],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        // token_consumption : FK entreprise/utilisateur en ON DELETE CASCADE — rien à faire.
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement(
            "DELETE FROM utilisateur WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    /**
     * Jeu d'opérations complet de l'exercice 2032. Montants choisis pour des
     * attendus lisibles (cf. assertions) :
     *   Capital 5000 (D 521 / C 101, daté du 1ᵉʳ paiement).
     *   Encaissement partiel 580 sur note de bordereau (HT 1000 / taxe 160)
     *     → prorata : 706 = 500, 443 = 80, 521 = 580.
     *   Rétro-commission partenaire payée 200 (sans compte) → D 632 / C 571.
     *   Reversement taxe ASSUREUR 30 → D 443 / C 571 (trésorerie seule).
     *   Reversement taxe COURTIER 50 → D 641 / C 571 (charge : trésorerie + résultat).
     *   Dépense PAYÉE banque TTC 120, TVA 20 % → D 62 (100) + D 445 (20) / C 521 (120).
     *   Dépense ENGAGÉE TTC 50 → D 65 (50) / C 401 (50).
     *   Dépense ANNULÉE TTC 999 → absente de tout document.
     *   Paiement de SINISTRE 777 → exclu (aucun impact).
     *
     * @return array{owner: Invite, guest: Invite, entreprise: Entreprise}
     */
    private function seed(bool $guestCanReadDocuments = false): array
    {
        $em = $this->em();

        $ownerUser = $this->makeUser(self::OWNER_EMAIL);
        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-TEST');
        $entreprise->setAdresse('1 rue du Test');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-TEST');
        $entreprise->setIdnat('IDNAT-TEST');
        $entreprise->setNumimpot('IMP-TEST');
        $entreprise->setCapitalSociale(5000.0);
        $entreprise->setUtilisateur($ownerUser);
        $ownerUser->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $ownerInvite = new Invite();
        $ownerInvite->setNom('Administrateur');
        $ownerInvite->setUtilisateur($ownerUser);
        $ownerInvite->setEntreprise($entreprise);
        $ownerInvite->setProprietaire(true);
        $em->persist($ownerInvite);

        $guestUser = $this->makeUser(self::GUEST_EMAIL);
        $guestUser->setConnectedTo($entreprise);
        $guestInvite = new Invite();
        $guestInvite->setNom('Collaborateur restreint');
        $guestInvite->setUtilisateur($guestUser);
        $guestInvite->setEntreprise($entreprise);
        $guestInvite->setProprietaire(false);
        $em->persist($guestInvite);

        if ($guestCanReadDocuments) {
            $role = new RolesEnFinance();
            $role->setNom('Rôle documents comptables');
            $role->setAccessDocumentComptable([Invite::ACCESS_LECTURE]);
            $role->setEntreprise($entreprise);
            $guestInvite->addRolesEnFinance($role);
            $em->persist($role);
        }

        // --- Taxes + autorités fiscales (référentiel : redevables distincts). ---
        $taxeCourtier = (new Taxe())->setCode('TC-TEST')->setDescription('Taxe courtier test')
            ->setTauxIARD('10.00')->setTauxVIE('10.00')->setRedevable(Taxe::REDEVABLE_COURTIER);
        $taxeCourtier->setEntreprise($entreprise);
        $em->persist($taxeCourtier);
        $autoriteCourtier = new AutoriteFiscale();
        $autoriteCourtier->setNom('Autorité courtier')->setAbreviation('AC');
        $autoriteCourtier->setTaxe($taxeCourtier);
        $autoriteCourtier->setEntreprise($entreprise);
        $em->persist($autoriteCourtier);

        $taxeAssureur = (new Taxe())->setCode('TA-TEST')->setDescription('Taxe assureur test')
            ->setTauxIARD('16.00')->setTauxVIE('16.00')->setRedevable(Taxe::REDEVABLE_ASSUREUR);
        $taxeAssureur->setEntreprise($entreprise);
        $em->persist($taxeAssureur);
        $autoriteAssureur = new AutoriteFiscale();
        $autoriteAssureur->setNom('Autorité assureur')->setAbreviation('AA');
        $autoriteAssureur->setTaxe($taxeAssureur);
        $autoriteAssureur->setEntreprise($entreprise);
        $em->persist($autoriteAssureur);

        // --- Compte bancaire de réception (521). ---
        $compte = new CompteBancaire();
        $compte->setIntitule('Compte test')->setNumero('CD00-TEST')->setBanque('Banque Test')->setCodeSwift('TESTCDKI');
        $compte->setEntreprise($entreprise);
        $em->persist($compte);

        // --- Encaissement de commission : bordereau → note de débit → paiement partiel. ---
        $bordereau = new Bordereau();
        $bordereau->setType(0)->setNom('Bordereau test')->setReference('BRD-PHPUNIT-2032')
            ->setReceivedAt(new \DateTimeImmutable('2032-02-01'))
            ->setPeriodeDebut(new \DateTimeImmutable('2032-01-01'))
            ->setPeriodeFin(new \DateTimeImmutable('2032-01-31'))
            ->setMontantComHtPayableNow(1000.0)
            ->setMontantTaxePayableNow(160.0)
            ->setInvite($ownerInvite)
            ->setEntreprise($entreprise);
        $em->persist($bordereau);

        $noteDebit = new Note();
        $noteDebit->setNom('Facture bordereau test')->setReference('FACT-BRD-PHPUNIT')
            ->setType(Note::TYPE_NOTE_DE_DEBIT)->setAddressedTo(Note::TO_ASSUREUR)
            ->setValidated(true)->setSignature('sig-test')->setBordereau($bordereau);
        $noteDebit->setEntreprise($entreprise);
        $noteDebit->setInvite($ownerInvite);
        $em->persist($noteDebit);

        $paiementCommission = new Paiement();
        $paiementCommission->setMontant(580.0)->setPaidAt(new \DateTimeImmutable('2032-03-10'))
            ->setReference('PAY-COM-580')->setNote($noteDebit)->setCompteBancaire($compte);
        $paiementCommission->setEntreprise($entreprise);
        $em->persist($paiementCommission);

        // --- Rétro-commission partenaire (note de crédit payée, sans compte → caisse). ---
        $noteRetro = new Note();
        $noteRetro->setNom('Rétro-commission test')->setReference('RETRO-PHPUNIT')
            ->setType(Note::TYPE_NOTE_DE_CREDIT)->setAddressedTo(Note::TO_PARTENAIRE)
            ->setValidated(true)->setSignature('sig-test');
        $noteRetro->setEntreprise($entreprise);
        $noteRetro->setInvite($ownerInvite);
        $em->persist($noteRetro);
        $paiementRetro = new Paiement();
        $paiementRetro->setMontant(200.0)->setPaidAt(new \DateTimeImmutable('2032-04-15'))
            ->setReference('PAY-RETRO-200')->setNote($noteRetro);
        $paiementRetro->setEntreprise($entreprise);
        $em->persist($paiementRetro);

        // --- Reversement de taxe ASSUREUR (collectée) : trésorerie seule. ---
        $noteRevAssureur = new Note();
        $noteRevAssureur->setNom('Reversement taxe assureur')->setReference('REV-TA-PHPUNIT')
            ->setType(Note::TYPE_NOTE_DE_CREDIT)->setAddressedTo(Note::TO_AUTORITE_FISCALE)
            ->setValidated(true)->setSignature('sig-test')->setAutoritefiscale($autoriteAssureur);
        $noteRevAssureur->setEntreprise($entreprise);
        $noteRevAssureur->setInvite($ownerInvite);
        $em->persist($noteRevAssureur);
        $paiementRevAssureur = new Paiement();
        $paiementRevAssureur->setMontant(30.0)->setPaidAt(new \DateTimeImmutable('2032-05-20'))
            ->setReference('PAY-REV-TA-30')->setNote($noteRevAssureur);
        $paiementRevAssureur->setEntreprise($entreprise);
        $em->persist($paiementRevAssureur);

        // --- Reversement de taxe COURTIER : charge (641). ---
        $noteRevCourtier = new Note();
        $noteRevCourtier->setNom('Reversement taxe courtier')->setReference('REV-TC-PHPUNIT')
            ->setType(Note::TYPE_NOTE_DE_CREDIT)->setAddressedTo(Note::TO_AUTORITE_FISCALE)
            ->setValidated(true)->setSignature('sig-test')->setAutoritefiscale($autoriteCourtier);
        $noteRevCourtier->setEntreprise($entreprise);
        $noteRevCourtier->setInvite($ownerInvite);
        $em->persist($noteRevCourtier);
        $paiementRevCourtier = new Paiement();
        $paiementRevCourtier->setMontant(50.0)->setPaidAt(new \DateTimeImmutable('2032-06-25'))
            ->setReference('PAY-REV-TC-50')->setNote($noteRevCourtier);
        $paiementRevCourtier->setEntreprise($entreprise);
        $em->persist($paiementRevCourtier);

        // --- Dépenses du cabinet : payée (banque), engagée, annulée. ---
        $charge62 = new ChargeCourtier();
        $charge62->setCode('LOYER')->setLibelle('Loyer du bureau')->setCompteOhada('62');
        $charge62->setEntreprise($entreprise);
        $em->persist($charge62);
        $charge65 = new ChargeCourtier();
        $charge65->setCode('DIVERS')->setLibelle('Autres charges')->setCompteOhada('65');
        $charge65->setEntreprise($entreprise);
        $em->persist($charge65);

        $depensePayee = new DepenseCourtier();
        $depensePayee->setCharge($charge62)->setDateDepense(new \DateTimeImmutable('2032-04-05'))
            ->setMontant('120.00')->setTauxTva('20.00')->setMoyenPaiement(Depense::MOYEN_BANQUE)
            ->setStatut(Depense::STATUT_PAYEE)->setReference('DEP-PAYEE-120');
        $depensePayee->setEntreprise($entreprise);
        $em->persist($depensePayee);

        $depenseEngagee = new DepenseCourtier();
        $depenseEngagee->setCharge($charge65)->setDateDepense(new \DateTimeImmutable('2032-05-01'))
            ->setMontant('50.00')->setTauxTva('0.00')->setStatut(Depense::STATUT_ENGAGEE)
            ->setReference('DEP-ENGAGEE-50');
        $depenseEngagee->setEntreprise($entreprise);
        $em->persist($depenseEngagee);

        $depenseAnnulee = new DepenseCourtier();
        $depenseAnnulee->setCharge($charge65)->setDateDepense(new \DateTimeImmutable('2032-05-02'))
            ->setMontant('999.00')->setStatut(Depense::STATUT_ANNULEE)
            ->setReference('DEP-ANNULEE-999');
        $depenseAnnulee->setEntreprise($entreprise);
        $em->persist($depenseAnnulee);

        // --- Paiement de SINISTRE (sans note) : exclu de la comptabilité du courtier. ---
        $offre = new OffreIndemnisationSinistre();
        $offre->setMontantPayable(777.0)->setBeneficiaire('Sinistré test');
        $offre->setEntreprise($entreprise);
        $em->persist($offre);
        $paiementSinistre = new Paiement();
        $paiementSinistre->setMontant(777.0)->setPaidAt(new \DateTimeImmutable('2032-07-07'))
            ->setReference('SIN-EXCLU-777')->setOffreIndemnisationSinistre($offre);
        $paiementSinistre->setEntreprise($entreprise);
        $em->persist($paiementSinistre);

        $em->flush();

        return ['owner' => $ownerInvite, 'guest' => $guestInvite, 'entreprise' => $entreprise];
    }

    public function testDocumentsInvariantsEtMontants(): void
    {
        ['entreprise' => $e] = $this->seed();
        /** @var CourtierEcritureComptableService $service */
        $service = static::getContainer()->get(CourtierEcritureComptableService::class);

        $documents = $service->documents($e, self::EXERCICE);

        // Partie double : Σ débits = Σ crédits (journal ET balance).
        $this->assertEqualsWithDelta($documents['journal']['totalDebit'], $documents['journal']['totalCredit'], 0.01, 'Le journal doit être équilibré.');
        $this->assertEqualsWithDelta($documents['balance']['totaux']['mvtD'], $documents['balance']['totaux']['mvtC'], 0.01, 'La balance doit être équilibrée.');

        // Compte de résultat : produits 500 (part HT proratisée), charges 400
        // (632 rétro 200 + 641 taxe courtier 50 + 62 loyer HT 100 + 65 engagée 50).
        $this->assertEqualsWithDelta(500.0, $documents['resultat']['totalProduits'], 0.01);
        $this->assertEqualsWithDelta(400.0, $documents['resultat']['totalCharges'], 0.01);
        $this->assertEqualsWithDelta(100.0, $documents['resultat']['resultat'], 0.01);

        // TFR : le résultat net est identique à celui du compte de résultat.
        $tfr = $documents['tfr'];
        $this->assertEqualsWithDelta(100.0, end($tfr)['montant'], 0.01, 'Le résultat net du TFR doit égaler celui du compte de résultat.');

        // Bilan : Actif = Passif (ouverture ET clôture), total attendu 5200
        // (trésorerie 5180 + TVA récupérable 20 = capital 5000 + résultat 100 + 401 50 + 443 50).
        $actif = end($documents['bilan']['actif']);
        $passif = end($documents['bilan']['passif']);
        $this->assertEqualsWithDelta($actif['cloture'], $passif['cloture'], 0.01, 'TOTAL ACTIF doit égaler TOTAL PASSIF.');
        $this->assertEqualsWithDelta(5200.0, $actif['cloture'], 0.01);

        // TFT : encaissements 580, décaissements 400 (200+30+50+120), financement 5000,
        // clôture 5180 — réconciliée avec la trésorerie du bilan.
        $tft = $documents['tft'];
        $this->assertEqualsWithDelta(580.0, $tft['encaissements'], 0.01);
        $this->assertEqualsWithDelta(400.0, $tft['decaissements'], 0.01);
        $this->assertEqualsWithDelta(5000.0, $tft['fluxFinancement'], 0.01);
        $this->assertEqualsWithDelta(5180.0, $tft['cloture'], 0.01);
        $this->assertEqualsWithDelta($tft['ouverture'] + $tft['variation'], $tft['cloture'], 0.01, 'Le TFT doit se réconcilier.');

        // Exclusions : paiement de sinistre et dépense annulée absents du journal.
        $pieces = [];
        foreach ($documents['journal']['ecritures'] as $ecriture) {
            $pieces[] = $ecriture['piece'];
        }
        $this->assertNotContains('SIN-EXCLU-777', $pieces, 'Un paiement de sinistre ne doit jamais impacter la comptabilité du courtier.');
        $this->assertNotContains('DEP-ANNULEE-999', $pieces, 'Une dépense annulée doit être exclue.');
        $this->assertContains('CAPITAL', $pieces, 'L\'écriture fondatrice de capital doit être présente.');
    }

    public function testSuiviFiscalParRedevable(): void
    {
        ['entreprise' => $e] = $this->seed();
        /** @var CourtierSuiviFiscalService $service */
        $service = static::getContainer()->get(CourtierSuiviFiscalService::class);

        $suivi = $service->suivi($e, self::EXERCICE);

        // Bloc ASSUREUR : collecté 80 (part taxe de l'encaissement), déductible 20
        // (TVA de la dépense payée), solde payable 60, payé 30, solde dû 30.
        $t = $suivi['assureur']['totaux'];
        $this->assertEqualsWithDelta(80.0, $t['collectee'], 0.01);
        $this->assertEqualsWithDelta(20.0, $t['deductible'], 0.01);
        $this->assertEqualsWithDelta(60.0, $t['netDu'], 0.01);
        $this->assertEqualsWithDelta(30.0, $t['reverse'], 0.01);
        $this->assertEqualsWithDelta(30.0, $t['solde'], 0.01);

        // Bloc COURTIER : dû 50 (10 % du HT encaissé 500), payé 50 (reversement 641), solde 0.
        $tc = $suivi['courtier']['totaux'];
        $this->assertEqualsWithDelta(50.0, $tc['du'], 0.01);
        $this->assertEqualsWithDelta(50.0, $tc['paye'], 0.01);
        $this->assertEqualsWithDelta(0.0, $tc['solde'], 0.01);
    }

    public function testComposantAccessibleAuProprietaire(): void
    {
        ['entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf('/admin/document-comptable/workspace/%d?exercice=%d', $e->getId(), self::EXERCICE));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString(self::DENIED_MARKER, $html);
        $this->assertStringContainsString('Documents comptables', $html);
        $this->assertStringContainsString('Suivi fiscal', $html, 'Le 8ᵉ onglet Suivi fiscal doit être proposé.');

        // Onglet bilan : totaux rendus.
        $this->client->request('GET', sprintf('/admin/document-comptable/workspace/%d?doc=bilan&exercice=%d', $e->getId(), self::EXERCICE));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('TOTAL ACTIF', (string) $this->client->getResponse()->getContent());

        // Onglet suivi fiscal : les deux blocs par redevable.
        $this->client->request('GET', sprintf('/admin/document-comptable/workspace/%d?doc=suivi-fiscal&exercice=%d', $e->getId(), self::EXERCICE));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Taxes collectées', $html);
        $this->assertStringContainsString('Taxes du courtier', $html);
    }

    public function testGatingInviteSansDroit(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed(false);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        // Composant : panneau « Accès restreint » (fail-closed).
        $this->client->request('GET', sprintf('/admin/document-comptable/workspace/%d', $e->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(self::DENIED_MARKER, (string) $this->client->getResponse()->getContent());

        // Export : 403 brut.
        $this->client->request('GET', sprintf('/admin/document-comptable/export/%d?doc=journal&exercice=%d', $e->getId(), self::EXERCICE));
        $this->assertResponseStatusCodeSame(403, "L'export doit être refusé hors périmètre.");

        // Menu du workspace : la rubrique disparaît pour l'invité sans droit.
        $this->client->request('GET', sprintf('/espacedetravail/%d/%d', $guest->getId(), $e->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString(
            'entity-name-param="DocumentComptable"',
            (string) $this->client->getResponse()->getContent(),
            'La rubrique Documents comptables doit être filtrée du menu (fail-closed).'
        );
    }

    public function testInviteAvecLectureConsulteEtExporte(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed(true);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        // Lecture = consultation…
        $this->client->request('GET', sprintf('/admin/document-comptable/workspace/%d?exercice=%d', $e->getId(), self::EXERCICE));
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString(self::DENIED_MARKER, (string) $this->client->getResponse()->getContent());

        // …ET export (décision produit).
        $this->client->request('GET', sprintf('/admin/document-comptable/export/%d?doc=journal&exercice=%d', $e->getId(), self::EXERCICE));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('spreadsheetml', (string) $this->client->getResponse()->headers->get('Content-Type'));

        // Menu : la rubrique est visible.
        $this->client->request('GET', sprintf('/espacedetravail/%d/%d', $guest->getId(), $e->getId()));
        $this->assertStringContainsString('entity-name-param="DocumentComptable"', (string) $this->client->getResponse()->getContent());
    }

    public function testExportsUnitaireCompletEtSuiviFiscal(): void
    {
        ['entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        foreach (['bilan', 'suivi-fiscal', 'all'] as $doc) {
            $this->client->request('GET', sprintf('/admin/document-comptable/export/%d?doc=%s&exercice=%d', $e->getId(), $doc, self::EXERCICE));
            $this->assertResponseIsSuccessful(sprintf('L\'export « %s » doit réussir.', $doc));
            $this->assertStringContainsString('spreadsheetml', (string) $this->client->getResponse()->headers->get('Content-Type'));
        }
    }
}
