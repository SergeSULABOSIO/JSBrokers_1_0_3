<?php

namespace App\Tests\Workspace;

use App\Comptabilite\CourtierEcritureComptableService;
use App\Comptabilite\CourtierSuiviFiscalService;
use App\Comptabilite\PlanComptable;
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
 *    d'écritures — comptabilité à l'ENGAGEMENT : facturation d'une commission
 *    via bordereau, PUIS son encaissement partiel qui solde la créance,
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
            'autorite_fiscale', 'taxe', 'monnaie',
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
     *   FACTURATION de la note de bordereau, validée, émise le 15/02 (HT 1000 / taxe 160)
     *     → D 411 = 1160, C 706 = 1000, C 443 = 160.
     *   Encaissement partiel 580 de cette note → D 521 = 580, C 411 = 580.
     *     Il reste donc 580 en créances au bilan, et le produit ne dépend PAS du payé.
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
        // Les deux côtés de la relation (comme le CollectionType by_reference:false du formulaire).
        $taxeCourtier->addAutoriteFiscale($autoriteCourtier);
        $autoriteCourtier->setEntreprise($entreprise);
        $em->persist($autoriteCourtier);

        $taxeAssureur = (new Taxe())->setCode('TA-TEST')->setDescription('Taxe assureur test')
            ->setTauxIARD('16.00')->setTauxVIE('16.00')->setRedevable(Taxe::REDEVABLE_ASSUREUR);
        $taxeAssureur->setEntreprise($entreprise);
        $em->persist($taxeAssureur);
        $autoriteAssureur = new AutoriteFiscale();
        $autoriteAssureur->setNom('Autorité assureur')->setAbreviation('AA');
        $taxeAssureur->addAutoriteFiscale($autoriteAssureur);
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
            ->setValidated(true)->setSignature('sig-test')->setBordereau($bordereau)
            // LA DATE DE LA PIÈCE, qui date son écriture d'émission. `ValeursDeNaissance`
            // la pose sur toute note créée par l'application ; ici l'entité est montée à
            // la main, et sans elle l'écriture tomberait sur `createdAt` — c'est-à-dire
            // aujourd'hui, hors de l'exercice fictif de ce banc.
            ->setSentAt(new \DateTimeImmutable('2032-02-15'));
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

        // ── COMPTE DE RÉSULTAT — À L'ENGAGEMENT ─────────────────────────────────
        // Produits 1000 : le HT FACTURÉ, constaté à l'émission de la note. Il valait
        // 500 tant que le produit naissait de l'encaissement (la part HT des 580 reçus).
        // Charges 400, inchangées : 632 rétro 200 + 641 taxe courtier 50 + 62 loyer HT
        // 100 + 65 engagée 50 — les dettes, elles, restent sur l'encaissement.
        $this->assertEqualsWithDelta(1000.0, $documents['resultat']['totalProduits'], 0.01);
        $this->assertEqualsWithDelta(400.0, $documents['resultat']['totalCharges'], 0.01);
        $this->assertEqualsWithDelta(600.0, $documents['resultat']['resultat'], 0.01);

        // TFR : le résultat net est identique à celui du compte de résultat.
        $tfr = $documents['tfr'];
        $this->assertEqualsWithDelta(600.0, end($tfr)['montant'], 0.01, 'Le résultat net du TFR doit égaler celui du compte de résultat.');

        // ── BILAN : Actif = Passif, et la CRÉANCE y figure enfin ────────────────
        // Actif 5780 = trésorerie 5180 + créances 580 (1160 facturés − 580 encaissés)
        //            + TVA récupérable 20
        // Passif 5780 = capital 5000 + résultat 600 + fournisseurs 50 + TVA facturée 130.
        $actif = end($documents['bilan']['actif']);
        $passif = end($documents['bilan']['passif']);
        $this->assertEqualsWithDelta($actif['cloture'], $passif['cloture'], 0.01, 'TOTAL ACTIF doit égaler TOTAL PASSIF.');
        $this->assertEqualsWithDelta(5780.0, $actif['cloture'], 0.01);

        $creances = null;
        foreach ($documents['bilan']['actif'] as $poste) {
            if (str_starts_with($poste['libelle'], 'Créances')) {
                $creances = $poste['cloture'];
            }
        }
        $this->assertNotNull($creances, 'Le bilan doit porter un poste de créances : sans lui, une '
            . 'commission facturée et non encaissée n\'apparaîtrait nulle part.');
        $this->assertEqualsWithDelta(580.0, $creances, 0.01, 'Facturé 1160, encaissé 580 : il reste 580 dus.');

        // ── TFT : INCHANGÉ, et c'est juste ──────────────────────────────────────
        // Un tableau de flux est par nature sur encaissement : les écritures d'émission
        // ne touchent aucun compte de trésorerie, elles n'y entrent donc pas.
        // Encaissements 580, décaissements 400 (200+30+50+120), financement 5000,
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

    /**
     * ⚠ AUCUN COMPTE MOUVEMENTÉ NE DOIT ÉCHAPPER AUX ÉTATS.
     *
     * La balance se construit toute seule, de tous les comptes rencontrés. Le BILAN, lui,
     * énumère ses postes EN DUR : l'égalité Actif = Passif ne tient que parce que la liste
     * couvre exactement les comptes employés. Ajouter un compte sans l'y porter
     * déséquilibrerait le bilan EN SILENCE — sans erreur, sans exception, juste un total
     * faux, que personne ne saurait rapprocher de quoi que ce soit.
     *
     * C'est très exactement ce qui a failli arriver en introduisant le 411. Ce test est le
     * vrai correctif : le prochain compte ajouté fera échouer une assertion, au lieu de
     * fausser un état. Il ne vérifie pas un montant, il vérifie une COUVERTURE.
     */
    public function testAucunCompteNEchappeAuBilan(): void
    {
        ['entreprise' => $e] = $this->seed();
        /** @var CourtierEcritureComptableService $service */
        $service = static::getContainer()->get(CourtierEcritureComptableService::class);

        $documents = $service->documents($e, self::EXERCICE);

        // Les comptes que le bilan sait porter. Toute addition à `bilan()` se répercute ici.
        $portesParLeBilan = [
            PlanComptable::BANQUES,
            PlanComptable::CAISSE,
            PlanComptable::CLIENTS,
            PlanComptable::TVA_RECUPERABLE,
            PlanComptable::CAPITAL_SOCIAL,
            PlanComptable::FOURNISSEURS,
            PlanComptable::TVA_FACTUREE,
            PlanComptable::TVA_DUE,
        ];

        $orphelins = [];
        foreach ($documents['balance']['lignes'] as $ligne) {
            $compte = (string) $ligne['compte'];
            $classe = PlanComptable::classe($compte);
            // Les classes 6 et 7 partent au compte de résultat, dont le solde revient au
            // bilan par la ligne « Résultat de l'exercice » : elles sont couvertes.
            if ($classe === 6 || $classe === 7 || in_array($compte, $portesParLeBilan, true)) {
                continue;
            }
            $orphelins[] = $compte;
        }

        self::assertSame([], $orphelins, sprintf(
            'Ces comptes sont mouvementés mais n\'apparaissent ni au bilan ni au résultat : %s. '
            . 'Le total du bilan est donc faux, sans que rien ne le signale. Portez-les dans '
            . 'DocumentsComptablesBuilder::bilan(), et ajoutez-les à la liste de ce test.',
            implode(', ', $orphelins),
        ));

        // Et la conséquence, vérifiée : les deux colonnes s'équilibrent.
        $actif = end($documents['bilan']['actif']);
        $passif = end($documents['bilan']['passif']);
        self::assertEqualsWithDelta($actif['ouverture'], $passif['ouverture'], 0.01, 'Bilan d\'ouverture déséquilibré.');
        self::assertEqualsWithDelta($actif['cloture'], $passif['cloture'], 0.01, 'Bilan de clôture déséquilibré.');
    }

    public function testSuiviFiscalParRedevable(): void
    {
        ['entreprise' => $e] = $this->seed();
        /** @var CourtierSuiviFiscalService $service */
        $service = static::getContainer()->get(CourtierSuiviFiscalService::class);

        $suivi = $service->suivi($e, self::EXERCICE);

        // ── LA TAXE SUIT LE PRODUIT, donc la FACTURE ────────────────────────────
        // Bloc ASSUREUR : collecté 160 (la taxe FACTURÉE de la note ; c'était 80, la
        // part taxe des 580 encaissés), déductible 20 (TVA de la dépense payée), solde
        // payable 140, payé 30, solde dû 110.
        $t = $suivi['assureur']['totaux'];
        $this->assertEqualsWithDelta(160.0, $t['collectee'], 0.01);
        $this->assertEqualsWithDelta(20.0, $t['deductible'], 0.01);
        $this->assertEqualsWithDelta(140.0, $t['netDu'], 0.01);
        $this->assertEqualsWithDelta(30.0, $t['reverse'], 0.01);
        $this->assertEqualsWithDelta(110.0, $t['solde'], 0.01);

        // Bloc COURTIER : dû 100 (10 % du HT FACTURÉ 1000 ; c'était 50 sur le HT
        // encaissé), payé 50 (reversement 641), solde 50.
        $tc = $suivi['courtier']['totaux'];
        $this->assertEqualsWithDelta(100.0, $tc['du'], 0.01);
        $this->assertEqualsWithDelta(50.0, $tc['paye'], 0.01);
        $this->assertEqualsWithDelta(50.0, $tc['solde'], 0.01);

        // Fiches des taxes par redevable : nom/code, taux et autorités assujetties.
        $this->assertCount(1, $suivi['taxes']['assureur']);
        $this->assertSame('TA-TEST', $suivi['taxes']['assureur'][0]['code']);
        $this->assertEqualsWithDelta(16.0, $suivi['taxes']['assureur'][0]['tauxIARD'], 0.01);
        $this->assertSame(['AA — Autorité assureur'], $suivi['taxes']['assureur'][0]['autorites']);
        $this->assertCount(1, $suivi['taxes']['courtier']);
        $this->assertSame('TC-TEST', $suivi['taxes']['courtier'][0]['code']);
        $this->assertSame(['AC — Autorité courtier'], $suivi['taxes']['courtier'][0]['autorites']);
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
        // Actualisation : bouton manuel branché sur le contrôleur Stimulus + mention
        // de l'actualisation automatique (5 min, gérée par le même contrôleur).
        $this->assertStringContainsString('document-comptable#refresh', $html, 'Le bouton Actualiser doit être branché au contrôleur Stimulus.');
        $this->assertStringContainsString('auto toutes les 5 min', $html, "L'actualisation automatique doit être annoncée.");

        // Onglet bilan : totaux rendus.
        $this->client->request('GET', sprintf('/admin/document-comptable/workspace/%d?doc=bilan&exercice=%d', $e->getId(), self::EXERCICE));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('TOTAL ACTIF', (string) $this->client->getResponse()->getContent());

        // Onglet suivi fiscal : les deux blocs par redevable, les fiches des taxes
        // (code, taux, autorité assujettie) et le rappel du mode opératoire de reversement.
        $this->client->request('GET', sprintf('/admin/document-comptable/workspace/%d?doc=suivi-fiscal&exercice=%d', $e->getId(), self::EXERCICE));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Taxes collectées', $html);
        $this->assertStringContainsString('Taxes du courtier', $html);
        $this->assertStringContainsString('TA-TEST', $html, 'La fiche de la taxe assureur doit être affichée.');
        $this->assertStringContainsString('TC-TEST', $html, 'La fiche de la taxe courtier doit être affichée.');
        $this->assertStringContainsString('Autorité assureur', $html, "L'autorité assujettie doit être nommée.");
        $this->assertStringContainsString('Enregistrer un reversement', $html, 'Le mode opératoire du reversement doit être rappelé.');
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

    public function testCapitalSocialConvertiDeLaMonnaieLocaleVersAffichage(): void
    {
        ['entreprise' => $e] = $this->seed();
        $em = $this->em();

        // Paramètres monnaies du workspace : CDF = monnaie LOCALE (saisie, dont le
        // capital social), USD = monnaie d'AFFICHAGE (celle des documents comptables).
        // tauxusd = unités de la monnaie pour 1 USD (pivot).
        $cdf = new \App\Entity\Monnaie();
        $cdf->setNom('Franc congolais')->setCode('CDF')->setTauxusd('2800.00')
            ->setFonction(\App\Entity\Monnaie::FONCTION_SAISIE_UNIQUEMENT)->setLocale(true);
        $cdf->setEntreprise($e);
        $em->persist($cdf);

        $usd = new \App\Entity\Monnaie();
        $usd->setNom('Dollar américain')->setCode('USD')->setTauxusd('1.00')
            ->setFonction(\App\Entity\Monnaie::FONCTION_SAISIE_ET_AFFICHAGE)->setLocale(false);
        $usd->setEntreprise($e);
        $em->persist($usd);

        // Capital social saisi en monnaie locale : 2 800 000 CDF = 1 000 USD.
        $e->setCapitalSociale(2800000.0);
        $em->flush();

        /** @var CourtierEcritureComptableService $service */
        $service = static::getContainer()->get(CourtierEcritureComptableService::class);
        $documents = $service->documents($e, self::EXERCICE);

        // L'écriture fondatrice est convertie en monnaie d'affichage, libellé traçable.
        $capital = null;
        foreach ($documents['journal']['ecritures'] as $ecriture) {
            if ($ecriture['piece'] === 'CAPITAL') {
                $capital = $ecriture;
                break;
            }
        }
        $this->assertNotNull($capital, "L'écriture de capital doit exister.");
        $this->assertEqualsWithDelta(1000.0, $capital['lignes'][0]['debit'], 0.01, '2 800 000 CDF doivent devenir 1 000 USD.');
        $this->assertStringContainsString('CDF', $capital['libelle'], 'Le libellé doit tracer la monnaie d\'origine.');
        $this->assertStringContainsString('USD', $capital['libelle'], 'Le libellé doit tracer la monnaie de conversion.');

        // ⚠ ET LE TAUX EMPLOYÉ, sans quoi un cours mal saisi reste introuvable. Un cabinet
        // réel portait 0,04 au lieu de 2 800 : son capital de 4 000 000 pesait cent
        // millions dans la trésorerie, et rien à l'écran ne reliait ce chiffre au
        // paramètre qui l'avait produit.
        $this->assertStringContainsString(
            '2 800,00',
            $capital['libelle'],
            'Le libellé doit nommer le taux employé : c\'est le seul endroit où un cours aberrant se voit.',
        );

        // Le bilan porte le capital converti — et reste équilibré.
        $passif = $documents['bilan']['passif'];
        $this->assertEqualsWithDelta(1000.0, $passif[0]['cloture'], 0.01, 'Le poste Capital social du bilan doit être en monnaie d\'affichage.');
        $this->assertEqualsWithDelta(end($documents['bilan']['actif'])['cloture'], end($passif)['cloture'], 0.01);
    }

    public function testChaqueActualisationConsommeDesTokens(): void
    {
        ['entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        /** @var \App\Token\TokenAccountService $tokens */
        $tokens = static::getContainer()->get(\App\Token\TokenAccountService::class);
        /** @var \App\Token\ParametresTokenService $parametres */
        $parametres = static::getContainer()->get(\App\Token\ParametresTokenService::class);
        $poidsLecture = $parametres->readWeight(); // tarif EN VIGUEUR (plan éditable, repli constantes)

        $soldeAvant = $tokens->getBalance($this->user(self::OWNER_EMAIL))['total'];

        // Trois actualisations successives (chargement initial + bouton Actualiser /
        // auto-refresh passent tous par la même route) : chacune est métrée.
        $url = sprintf('/admin/document-comptable/workspace/%d?exercice=%d', $e->getId(), self::EXERCICE);
        for ($i = 0; $i < 3; $i++) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful();
        }

        $this->em()->clear();
        $soldeApres = $tokens->getBalance($this->user(self::OWNER_EMAIL))['total'];
        $this->assertSame(
            $soldeAvant - 3 * $poidsLecture,
            $soldeApres,
            'Chaque rechargement des documents comptables doit débiter le tarif de lecture en vigueur.'
        );

        // Journalisation : une ligne de consommation par actualisation, au nom de la pseudo-entité.
        $consommations = $this->em()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM token_consumption tc
             JOIN entreprise e ON tc.entreprise_id = e.id
             WHERE e.nom = :nom AND tc.entite_nom = 'DocumentComptable'",
            ['nom' => self::ENTREPRISE_NOM]
        );
        $this->assertSame(3, (int) $consommations, 'Chaque actualisation doit être journalisée dans les consommations.');
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
