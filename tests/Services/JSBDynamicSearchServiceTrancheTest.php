<?php

namespace App\Tests\Services;

use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\PaiementPrime;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\TranchePaiementScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Moteur de recherche, critères synthétiques « Paiement » (Tranche) : bascule sur le
 * chemin in-memory (filtre par AXES calculés + tri par urgence + pagination), cumul de
 * plusieurs axes en ET, scoping entreprise conservé (AuditableTrait), non-régression du
 * chemin standard (ordre id DESC) quand aucun axe n'est présent.
 */
class JSBDynamicSearchServiceTrancheTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-tranchepaie-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit TranchePaie SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit TranchePaie Autre SARL';

    /** Prime encore due par l'assuré. */
    private const PRIME_DUE = [TranchePaiementScope::AXE_PRIME => TranchePaiementScope::IMPAYEE];
    /** Prime ET commission soldées : plus rien à recouvrer. */
    private const TOUT_SOLDE = [
        TranchePaiementScope::AXE_PRIME => TranchePaiementScope::PAYEE,
        TranchePaiementScope::AXE_COMMISSION => TranchePaiementScope::PAYEE,
    ];
    /** Commission exigible = prime payée par l'assuré, commission encore due au courtier. */
    private const COMMISSION_EXIGIBLE = [
        TranchePaiementScope::AXE_PRIME => TranchePaiementScope::PAYEE,
        TranchePaiementScope::AXE_COMMISSION => TranchePaiementScope::IMPAYEE,
    ];

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

    private function service(): JSBDynamicSearchService
    {
        return static::getContainer()->get(JSBDynamicSearchService::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_B_NOM];
        $emails = [self::OWNER_EMAIL];

        // Enfants avant parents : paiements/articles → notes/revenus → signalements →
        // tranches/chargements → cotations → pistes → assureurs/clients/risques → invites.
        foreach (['paiement', 'article', 'note', 'bordereau', 'avenant', 'revenu_pour_courtier', 'type_revenu', 'paiement_prime', 'tranche', 'chargement_pour_prime', 'cotation', 'piste', 'assureur', 'client', 'risque', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }
        $conn->executeStatement(
            "DELETE FROM entreprise WHERE nom IN (:noms)",
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $conn->executeStatement(
            "DELETE FROM utilisateur WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    private function makeEntreprise(string $nom, Utilisateur $owner): Entreprise
    {
        $entreprise = new Entreprise();
        $entreprise->setNom($nom);
        $entreprise->setLicence('LIC-TP');
        $entreprise->setAdresse('1 rue des Tranches');
        $entreprise->setTelephone('+243000000001');
        $entreprise->setRccm('RCCM-TP');
        $entreprise->setIdnat('IDNAT-TP');
        $entreprise->setNumimpot('IMP-TP');
        $entreprise->setUtilisateur($owner);
        $this->em()->persist($entreprise);

        return $entreprise;
    }

    /**
     * Une cotation VALIDÉE (proposition acceptée par le client → avenant) avec une prime
     * client réelle (ChargementPourPrime). Double condition pour que les tranches soient
     * réellement SUIVIES avec un statut calculable (sinon « N/A ») : sans avenant, la
     * cotation reste un simple projet et ses tranches ne font l'objet d'aucun suivi de
     * recouvrement, même échéance dépassée (règle métier — cf. TrancheIndicatorStrategy).
     */
    private function makeCotationAvecPrime(Entreprise $entreprise, Invite $invite, string $nom, float $prime, bool $valide = true): Cotation
    {
        $em = $this->em();

        $piste = (new Piste())
            ->setNom('Piste ' . $nom)
            ->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque de test paiements')
            ->setExercice(2026)
            ->setEntreprise($entreprise)
            ->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom($nom)->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())
            ->setNom('Prime ' . $nom)
            ->setMontantFlatExceptionel($prime)
            ->setCotation($cotation);
        $chargement->setEntreprise($entreprise);
        $em->persist($chargement);

        // Proposition VALIDÉE : un avenant matérialise le contrat, ce qui déclenche le suivi
        // des tranches. ($valide = false → proposition restée à l'état de projet, tranches non
        // suivies.) (Les tests « bordereau » ajoutent en plus leur propre avenant référencé
        // dans l'analyse ; un avenant supplémentaire ici reste sans effet sur leur
        // réconciliation, qui cible un avenant_id précis.)
        if ($valide) {
            $avenant = (new Avenant())
                ->setReferencePolice('POL-' . $nom)
                ->setNumero('0')
                ->setDescription('Avenant de validation (test)')
                ->setStartingAt(new \DateTimeImmutable('-60 days'))
                ->setEndingAt(new \DateTimeImmutable('+305 days'));
            $avenant->setCotation($cotation);
            $avenant->setEntreprise($entreprise);
            $avenant->setInvite($invite);
            $em->persist($avenant);
        }

        return $cotation;
    }

    private function makeTranche(Cotation $cotation, Entreprise $entreprise, string $nom, float $pourcentage, ?\DateTimeImmutable $echeance): Tranche
    {
        $tranche = (new Tranche())
            ->setNom($nom)
            ->setPourcentage($pourcentage)
            ->setPayableAt(new \DateTimeImmutable('-60 days'))
            ->setEcheanceAt($echeance);
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $this->em()->persist($tranche);

        return $tranche;
    }

    /**
     * Entreprise A : 2 tranches impayées (échue à -10 j / à échoir à +10 j) sur une
     * cotation à prime 1000. Entreprise B : 1 tranche impayée échue (contrôle du
     * scoping — elle ne doit jamais remonter dans les recherches de A).
     *
     * @return array{entreprise: Entreprise, echue: Tranche, aEchoir: Tranche, etrangere: Tranche}
     */
    private function seed(): array
    {
        $em = $this->em();

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER_EMAIL);
        $ownerUser->setNom('PHPUnit TranchePaie');
        $ownerUser->setVerified(true);
        $ownerUser->setPassword('irrelevant');
        $em->persist($ownerUser);

        $entreprise = $this->makeEntreprise(self::ENTREPRISE_NOM, $ownerUser);
        $owner = new Invite();
        $owner->setNom('Propriétaire');
        $owner->setUtilisateur($ownerUser);
        $owner->setEntreprise($entreprise);
        $owner->setProprietaire(true);
        $em->persist($owner);

        $cotation = $this->makeCotationAvecPrime($entreprise, $owner, 'Cotation Paiements A', 1000.0);
        // Pourcentage stocké en POINTS (50 = 50 %, cas des imports bordereau — tranche 71) :
        // toute la chaîne doit normaliser via getTrancheTauxFactor, jamais le brut.
        $echue = $this->makeTranche($cotation, $entreprise, 'Tranche échue', 50, new \DateTimeImmutable('-10 days'));
        $aEchoir = $this->makeTranche($cotation, $entreprise, 'Tranche à échoir', 0.5, new \DateTimeImmutable('+10 days'));

        $entrepriseB = $this->makeEntreprise(self::ENTREPRISE_B_NOM, $ownerUser);
        $ownerB = new Invite();
        $ownerB->setNom('Propriétaire B');
        $ownerB->setUtilisateur($ownerUser);
        $ownerB->setEntreprise($entrepriseB);
        $ownerB->setProprietaire(true);
        $em->persist($ownerB);
        $cotationB = $this->makeCotationAvecPrime($entrepriseB, $ownerB, 'Cotation Paiements B', 800.0);
        $etrangere = $this->makeTranche($cotationB, $entrepriseB, 'Tranche étrangère', 1.0, new \DateTimeImmutable('-5 days'));

        $em->flush();
        // EM partagé entre seed et moteur : on repart d'entités fraîches.
        $em->clear();

        return [
            'entreprise' => $this->em()->getRepository(Entreprise::class)->find($entreprise->getId()),
            'echue'      => $this->em()->getRepository(Tranche::class)->find($echue->getId()),
            'aEchoir'    => $this->em()->getRepository(Tranche::class)->find($aEchoir->getId()),
            'etrangere'  => $this->em()->getRepository(Tranche::class)->find($etrangere->getId()),
        ];
    }

    public function testFiltreImpayeesTrieParUrgenceEtScopeEntreprise(): void
    {
        ['entreprise' => $entreprise, 'echue' => $echue, 'aEchoir' => $aEchoir, 'etrangere' => $etrangere] = $this->seed();

        $resultat = $this->service()->search(
            Tranche::class,
            self::PRIME_DUE,
            $entreprise,
        );

        $this->assertNull($resultat['status']['error']);
        $this->assertSame(2, $resultat['totalItems']);
        $ids = array_map(static fn (Tranche $t) => $t->getId(), $resultat['data']);
        $this->assertSame([$echue->getId(), $aEchoir->getId()], $ids, 'Échue d\'abord (urgence), jamais la tranche de l\'autre entreprise.');
        $this->assertNotContains($etrangere->getId(), $ids);

        // Les indicateurs calculés sont posés (statut + urgence pour le badge).
        $this->assertSame('Non payée', $resultat['data'][0]->statutPaiement);
        $this->assertSame('critique', $resultat['data'][0]->urgenceNiveau, 'Échéance dépassée : retard avéré.');
        $this->assertSame('moderee', $resultat['data'][1]->urgenceNiveau, 'Échéance à J+10 (entre 8 et 30 jours) : urgence modérée.');
    }

    public function testFiltreEchuesEtPayees(): void
    {
        ['entreprise' => $entreprise, 'echue' => $echue] = $this->seed();

        // Cumul de deux axes en ET (prime due + échue), chacun sous sa forme enveloppée
        // telle que la produisent les chips : c'est le format réel du Cerveau.
        $echues = $this->service()->search(
            Tranche::class,
            [
                TranchePaiementScope::AXE_PRIME => ['operator' => '=', 'value' => TranchePaiementScope::IMPAYEE, 'label' => 'Prime impayée'],
                TranchePaiementScope::AXE_ECHEANCE => ['operator' => '=', 'value' => TranchePaiementScope::ECHUE, 'label' => 'Échues'],
            ],
            $entreprise,
        );
        $this->assertSame(1, $echues['totalItems']);
        $this->assertSame($echue->getId(), $echues['data'][0]->getId());

        $payees = $this->service()->search(
            Tranche::class,
            self::TOUT_SOLDE,
            $entreprise,
        );
        $this->assertSame(0, $payees['totalItems'], 'Aucun encaissement : rien n\'est payé.');
    }

    public function testTrancheDePropositionNonValideeNestPasSuivie(): void
    {
        // Règle métier : une tranche liée à une proposition NON validée (aucun avenant) reste
        // un simple PROJET — elle ne compte pas et ne fait l'objet d'AUCUN suivi, même échéance
        // dépassée. Le suivi ne démarre qu'à la validation (avenant), quand le client choisit
        // la proposition qui concrétise la police.
        ['entreprise' => $entreprise, 'echue' => $echue, 'aEchoir' => $aEchoir] = $this->seed();
        $em = $this->em();
        $invite = $em->getRepository(Invite::class)->findOneBy(['entreprise' => $entreprise]);

        // Proposition NON validée (pas d'avenant) + une tranche largement échue.
        $projet = $this->makeCotationAvecPrime($entreprise, $invite, 'Projet non validé', 1000.0, false);
        $trancheProjet = $this->makeTranche($projet, $entreprise, 'Tranche projet échue', 100, new \DateTimeImmutable('-30 days'));
        $em->flush();
        $projetId = $trancheProjet->getId();
        $em->clear();

        $entreprise = $em->getRepository(Entreprise::class)->find($entreprise->getId());

        // La tranche du projet n'apparaît sous AUCUNE combinaison d'axes : la garde
        // « N/A » (cotation sans avenant) précède tous les prédicats d'axe.
        $combinaisons = [
            'prime due' => self::PRIME_DUE,
            'prime due et échue' => self::PRIME_DUE + [TranchePaiementScope::AXE_ECHEANCE => TranchePaiementScope::ECHUE],
            'commission exigible' => self::COMMISSION_EXIGIBLE,
            'rétro à payer' => [TranchePaiementScope::AXE_RETRO => TranchePaiementScope::IMPAYEE],
            'tout soldé' => self::TOUT_SOLDE,
        ];
        foreach ($combinaisons as $libelle => $axes) {
            $resultat = $this->service()->search(Tranche::class, $axes, $entreprise);
            $ids = array_map(static fn (Tranche $t) => $t->getId(), $resultat['data']);
            $this->assertNotContains($projetId, $ids, "La tranche d'un projet ne doit pas être « {$libelle} ».");
        }

        // Les impayées restent EXACTEMENT les 2 tranches de la cotation validée.
        $impayees = $this->service()->search(Tranche::class, self::PRIME_DUE, $entreprise);
        $this->assertEqualsCanonicalizing(
            [$echue->getId(), $aEchoir->getId()],
            array_map(static fn (Tranche $t) => $t->getId(), $impayees['data']),
        );

        // Et son statut calculé est bien « N/A » (aucun badge de suivi), commission non exigible.
        $trancheFraiche = $em->getRepository(Tranche::class)->find($projetId);
        static::getContainer()->get(\App\Services\CanvasBuilder::class)->loadAllCalculatedValues($trancheFraiche);
        $this->assertSame('N/A', $trancheFraiche->statutPaiement);
        $this->assertSame(0.0, $trancheFraiche->commissionExigible ?? 0.0);
    }

    public function testSansCritereCheminStandardInchange(): void
    {
        ['entreprise' => $entreprise, 'echue' => $echue, 'aEchoir' => $aEchoir] = $this->seed();

        $resultat = $this->service()->search(Tranche::class, [], $entreprise);

        $this->assertSame(2, $resultat['totalItems'], 'Scoping entreprise (AuditableTrait) toujours appliqué.');
        $ids = array_map(static fn (Tranche $t) => $t->getId(), $resultat['data']);
        $this->assertSame([max($echue->getId(), $aEchoir->getId()), min($echue->getId(), $aEchoir->getId())], $ids, 'Ordre standard id DESC conservé.');
    }

    public function testSignalementPaiementPrimeRendLaTranchePayee(): void
    {
        ['entreprise' => $entreprise, 'echue' => $echue, 'aEchoir' => $aEchoir] = $this->seed();

        // Le courtier signale le paiement intégral de la prime de la tranche échue
        // (500 = 50 % de 1000) : trace déclarative, AUCUN Paiement/Note créé.
        $signalement = (new PaiementPrime())
            ->setTranche($echue)
            ->setPaidAt(new \DateTimeImmutable('-2 days'))
            ->setMontant(500.0)
            ->setReference('PRIME-TEST-1');
        $signalement->setEntreprise($entreprise);
        $this->em()->persist($signalement);
        $this->em()->flush();
        $this->em()->clear();
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($entreprise->getId());

        // La tranche signalée sort des impayées (pas de commission configurée → « Payée »)…
        $impayees = $this->service()->search(
            Tranche::class,
            self::PRIME_DUE,
            $entreprise,
        );
        $this->assertSame(
            [$aEchoir->getId()],
            array_map(static fn (Tranche $t) => $t->getId(), $impayees['data']),
            'La tranche dont la prime est signalée payée ne doit plus être impayée.'
        );

        // …et remonte sous « Payées », avec la prime déclarée visible.
        $payees = $this->service()->search(
            Tranche::class,
            self::TOUT_SOLDE,
            $entreprise,
        );
        $this->assertSame(1, $payees['totalItems']);
        $this->assertSame($echue->getId(), $payees['data'][0]->getId());
        $this->assertSame('Payée', $payees['data'][0]->statutPaiement);
        $this->assertSame(500.0, $payees['data'][0]->primeDeclareePayee);
        $this->assertSame('reglee', $payees['data'][0]->urgenceNiveau);

        // Le moteur d'INDICATEURS GLOBAUX (chemin de l'outil IA indicateur_calcule et
        // de la colonne de visualisation) voit aussi le paiement signalé : ciblé sur
        // la tranche, « prime payée » = 500 et « solde de prime » = 0 (le compteur
        // restait figé à zéro — solde toujours égal à la prime — avant correctif).
        $helper = static::getContainer()->get(\App\Services\Canvas\Indicator\IndicatorCalculationHelper::class);
        $trancheFraiche = $this->em()->getRepository(Tranche::class)->find($echue->getId());
        $stats = $helper->getIndicateursGlobaux($entreprise, false, ['trancheCible' => $trancheFraiche]);
        $this->assertEqualsWithDelta(500.0, $stats['prime_totale'], 0.01);
        $this->assertEqualsWithDelta(500.0, $stats['prime_totale_payee'], 0.01, 'Le paiement signalé doit compter comme prime payée.');
        $this->assertEqualsWithDelta(0.0, $stats['prime_totale_solde'], 0.01, 'Prime intégralement signalée payée : plus de solde.');

        // Niveau entreprise : la prime payée agrégée reflète aussi le signalement.
        $statsEntreprise = $helper->getIndicateursGlobaux($entreprise, false, []);
        $this->assertEqualsWithDelta(500.0, $statsEntreprise['prime_totale_payee'], 0.01);
        $this->assertEqualsWithDelta(500.0, $statsEntreprise['prime_totale_solde'], 0.01, '1000 de prime, 500 signalés payés : solde 500.');

        // Tranche à MONTANT FIXE (pourcentage null) : la part = montantFlat / prime de
        // la cotation — l'ancien code ne réduisait pas du tout (prime totale de la
        // tranche = prime de toute la cotation, ex. réponse fantaisiste de Ket).
        $flat = (new Tranche())
            ->setNom('Tranche montant fixe')
            ->setMontantFlat(200.0)
            ->setPayableAt(new \DateTimeImmutable('-5 days'))
            ->setEcheanceAt(new \DateTimeImmutable('+15 days'));
        $flat->setCotation($this->em()->getRepository(\App\Entity\Cotation::class)->find($trancheFraiche->getCotation()->getId()));
        $flat->setEntreprise($entreprise);
        $this->em()->persist($flat);
        $this->em()->flush();

        $statsFlat = $helper->getIndicateursGlobaux($entreprise, false, ['trancheCible' => $flat]);
        $this->assertEqualsWithDelta(200.0, $statsFlat['prime_totale'], 0.01, 'Part de la tranche fixe = 200/1000 de la prime de cotation.');
        $this->assertEqualsWithDelta(0.0, $statsFlat['prime_totale_payee'], 0.01, 'Aucun paiement sur CETTE tranche.');
    }

    public function testCommissionEncaisseeViaBordereauInferePrimePayee(): void
    {
        ['entreprise' => $entreprise, 'echue' => $echue, 'aEchoir' => $aEchoir] = $this->seed();
        $em = $this->em();
        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);
        $invite = $em->getRepository(Invite::class)->findOneBy(['entreprise' => $entreprise]);

        // Commission due par l'ASSUREUR sur la cotation, facturée via une note de
        // débit (circuit bordereau de production) dont l'article couvre la tranche
        // échue (50 %). AUCUN paiement de prime signalé sur cette tranche.
        $typeRevenu = (new TypeRevenu())
            ->setNom('Commission bordereau test')
            ->setMontantflat(200.0)
            ->setShared(false)
            ->setMultipayments(true)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())
            ->setNom('Revenu bordereau test')
            ->setTypeRevenu($typeRevenu)
            ->setCotation($echue->getCotation());
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        // Note LIÉE À UN BORDEREAU (circuit réel « facturation depuis bordereau
        // validé ») : dès qu'elle a des articles, tout le calcul passe par eux —
        // les montants « payable now » du bordereau ne sont qu'un repli d'affichage.
        $bordereau = new Bordereau();
        $bordereau->setType(0)->setNom('Bordereau production test')->setReference('BRD-TP-2026')
            ->setReceivedAt(new \DateTimeImmutable('-20 days'))
            ->setPeriodeDebut(new \DateTimeImmutable('-50 days'))
            ->setPeriodeFin(new \DateTimeImmutable('-20 days'))
            ->setMontantComHtPayableNow(999999.0)
            ->setMontantTaxePayableNow(999.0)
            ->setInvite($invite)
            ->setEntreprise($entreprise);
        $em->persist($bordereau);

        $note = new Note();
        $note->setNom('Note bordereau test')->setReference('NOTE-BRD-TP')
            ->setType(Note::TYPE_NOTE_DE_DEBIT)->setAddressedTo(Note::TO_ASSUREUR)
            ->setValidated(true)->setSignature('sig-test')->setBordereau($bordereau);
        $note->setEntreprise($entreprise);
        $note->setInvite($invite);
        $em->persist($note);

        $article = (new Article())->setQuantite(1.0)->setRevenuFacture($revenu);
        $article->setEntreprise($entreprise);
        $note->addArticle($article);
        $echue->addArticle($article);
        $em->persist($article);

        $em->flush();
        $noteId = $note->getId();
        $echueId = $echue->getId();
        $aEchoirId = $aEchoir->getId();
        $em->clear();

        // Montant payable de la note calculé par le moteur lui-même (taxes comprises) :
        // le test ne dépend pas du référentiel de taxes présent en base.
        $note = $em->getRepository(Note::class)->find($noteId);
        $notePayable = $helper->getNoteMontantPayable($note);
        $this->assertGreaterThan(0.0, $notePayable);

        // 1) Encaissement PARTIEL de la note : pas d'inférence, la tranche reste impayée.
        $entreprise = $em->getRepository(Entreprise::class)->find($entreprise->getId());
        $partiel = round($notePayable / 2, 2);
        $paiementPartiel = new Paiement();
        $paiementPartiel->setMontant($partiel)->setPaidAt(new \DateTimeImmutable('-3 days'))
            ->setReference('PAY-BRD-PARTIEL')->setNote($note);
        $paiementPartiel->setEntreprise($entreprise);
        $em->persist($paiementPartiel);
        $em->flush();
        $em->clear();

        $entreprise = $em->getRepository(Entreprise::class)->find($entreprise->getId());
        $payees = $this->service()->search(Tranche::class, self::TOUT_SOLDE, $entreprise);
        $this->assertSame(0, $payees['totalItems'], 'Note assureur partiellement encaissée : la prime ne doit PAS être réputée payée.');
        $this->assertFalse(
            $helper->isTrancheCommissionAssureurSoldee($em->getRepository(Tranche::class)->find($echueId)),
        );

        // 2) Solde de la note : commission intégralement reversée par l'assureur →
        //    il détenait la prime → prime réputée payée, la tranche devient « Payée ».
        $note = $em->getRepository(Note::class)->find($noteId);
        $paiementSolde = new Paiement();
        $paiementSolde->setMontant(round($notePayable - $partiel, 2))->setPaidAt(new \DateTimeImmutable('-1 day'))
            ->setReference('PAY-BRD-SOLDE')->setNote($note);
        $paiementSolde->setEntreprise($entreprise);
        $em->persist($paiementSolde);
        $em->flush();
        $em->clear();

        $entreprise = $em->getRepository(Entreprise::class)->find($entreprise->getId());
        $trancheFraiche = $em->getRepository(Tranche::class)->find($echueId);
        $this->assertTrue($helper->isTrancheCommissionAssureurSoldee($trancheFraiche));
        $this->assertEqualsWithDelta(
            500.0,
            $helper->getTranchePrimePayee($trancheFraiche),
            0.01,
            'Prime réputée payée = prime de la tranche (50 % de 1000), sans PaiementPrime.'
        );
        $this->assertEqualsWithDelta(0.0, $helper->getTranchePrimeDeclareePayee($trancheFraiche), 0.001, 'Fait dérivé : aucun signalement créé.');

        $payees = $this->service()->search(Tranche::class, self::TOUT_SOLDE, $entreprise);
        $this->assertSame(
            [$echueId],
            array_map(static fn (Tranche $t) => $t->getId(), $payees['data']),
            'Commission encaissée via bordereau : la tranche sort des impayées sans signalement manuel.'
        );
        $this->assertSame('Payée', $payees['data'][0]->statutPaiement);

        // L'autre tranche (aucune note, aucun encaissement) reste impayée.
        $impayees = $this->service()->search(Tranche::class, self::PRIME_DUE, $entreprise);
        $this->assertSame([$aEchoirId], array_map(static fn (Tranche $t) => $t->getId(), $impayees['data']));
    }

    public function testBordereauSansArticlesCouvreLesTranches(): void
    {
        ['entreprise' => $entreprise, 'echue' => $echue, 'aEchoir' => $aEchoir] = $this->seed();
        $em = $this->em();
        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);
        $invite = $em->getRepository(Invite::class)->findOneBy(['entreprise' => $entreprise]);

        // Commission due par l'assureur sur la cotation (aucune note, aucun article).
        $typeRevenu = (new TypeRevenu())
            ->setNom('Commission bordereau sans articles')
            ->setMontantflat(200.0)
            ->setShared(false)
            ->setMultipayments(true)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);
        $revenu = (new RevenuPourCourtier())
            ->setNom('Revenu bordereau sans articles')
            ->setTypeRevenu($typeRevenu)
            ->setCotation($echue->getCotation());
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        // Avenant de la cotation + bordereau de production dont la ligne RÉCONCILIÉE
        // (« match ») atteste que l'assureur a encaissé la prime de cet avenant.
        $avenant = new Avenant();
        $avenant->setCotation($echue->getCotation());
        $avenant->setReferencePolice('POL-BRD-TP');
        $avenant->setNumero('0');
        $avenant->setDescription('Avenant importé depuis bordereau BRD-SANS-ART');
        $avenant->setStartingAt(new \DateTimeImmutable('-60 days'));
        $avenant->setEndingAt(new \DateTimeImmutable('+305 days'));
        $avenant->setEntreprise($entreprise);
        $avenant->setInvite($invite);
        $em->persist($avenant);
        $em->flush();

        $bordereau = new Bordereau();
        $bordereau->setType(Bordereau::TYPE_BOREDERAU_PRODUCTION)
            ->setNom('Bordereau sans articles')->setReference('BRD-SANS-ART')
            ->setReceivedAt(new \DateTimeImmutable('-15 days'))
            ->setPeriodeDebut(new \DateTimeImmutable('-45 days'))
            ->setPeriodeFin(new \DateTimeImmutable('-15 days'))
            ->setMontantComHtPayableNow(200.0)
            ->setMontantTaxePayableNow(0.0)
            ->setAnalysisResults([
                ['type' => 'match', 'row_index' => 0, 'reference_police' => 'POL-BRD-TP', 'avenant_id' => $avenant->getId()],
            ])
            ->setInvite($invite)
            ->setEntreprise($entreprise);
        $em->persist($bordereau);
        $em->flush();
        $echueId = $echue->getId();
        $aEchoirId = $aEchoir->getId();
        $bordereauId = $bordereau->getId();
        $em->clear();
        $helper->reset();

        // 1) Ligne réconciliée, bordereau PAS encore encaissé : prime réputée payée
        //    (détenue par l'assureur) → commission exigible, mais tranche encore impayée.
        $entreprise = $em->getRepository(Entreprise::class)->find($entreprise->getId());
        $trancheFraiche = $em->getRepository(Tranche::class)->find($echueId);
        $this->assertEqualsWithDelta(500.0, $helper->getTranchePrimePayee($trancheFraiche), 0.01, 'Le bordereau atteste la prime sans article ni signalement.');

        $exigibles = $this->service()->search(Tranche::class, self::COMMISSION_EXIGIBLE, $entreprise);
        $idsExigibles = array_map(static fn (Tranche $t) => $t->getId(), $exigibles['data']);
        $this->assertContains($echueId, $idsExigibles, 'Prime détenue par l\'assureur → commission à réclamer.');
        $this->assertSame('Prime payée, commission due', $exigibles['data'][0]->statutPaiement);

        $payees = $this->service()->search(Tranche::class, self::TOUT_SOLDE, $entreprise);
        $this->assertSame(0, $payees['totalItems'], 'Commission pas encore reversée : rien n\'est soldé.');

        // 2) Note liée au bordereau SANS AUCUN ARTICLE, intégralement payée : le
        //    bordereau est soldé → commission des tranches réputée encaissée → payées.
        $bordereau = $em->getRepository(Bordereau::class)->find($bordereauId);
        $invite = $em->getRepository(Invite::class)->findOneBy(['entreprise' => $entreprise]);
        $note = new Note();
        $note->setNom('Note bordereau sans articles')->setReference('NOTE-BRD-SANS-ART')
            ->setType(Note::TYPE_NOTE_DE_DEBIT)->setAddressedTo(Note::TO_ASSUREUR)
            ->setValidated(true)->setSignature('sig-test')->setBordereau($bordereau);
        $note->setEntreprise($entreprise);
        $note->setInvite($invite);
        $em->persist($note);
        $paiement = new Paiement();
        $paiement->setMontant(200.0)->setPaidAt(new \DateTimeImmutable('-1 day'))
            ->setReference('PAY-BRD-SANS-ART')->setNote($note);
        $paiement->setEntreprise($entreprise);
        $em->persist($paiement);
        $em->flush();
        $em->clear();
        $helper->reset();

        $entreprise = $em->getRepository(Entreprise::class)->find($entreprise->getId());
        $payees = $this->service()->search(Tranche::class, self::TOUT_SOLDE, $entreprise);
        $idsPayees = array_map(static fn (Tranche $t) => $t->getId(), $payees['data']);
        $this->assertContains($echueId, $idsPayees, 'Bordereau soldé sans articles : la tranche doit être payée.');
        $this->assertContains($aEchoirId, $idsPayees, 'Toutes les tranches de l\'avenant attesté sont couvertes.');

        $impayees = $this->service()->search(Tranche::class, self::PRIME_DUE, $entreprise);
        $this->assertSame(0, $impayees['totalItems'], 'Plus aucun reste dû : ni prime (attestée) ni commission (bordereau soldé).');

        $exigibles = $this->service()->search(Tranche::class, self::COMMISSION_EXIGIBLE, $entreprise);
        $this->assertSame(0, $exigibles['totalItems'], 'Commission encaissée : l\'exigibilité s\'éteint.');
    }

    /**
     * Un bordereau de production réconcilie SOUVENT plusieurs avenants (parfois des
     * dizaines), et son encaissement est SOUVENT partiel. Ses lignes ne portent AUCUN
     * montant par police : l'affectation n'est pas déductible de la donnée, c'est une
     * RÈGLE. Trois ont été essayées, deux sont fausses :
     *
     *  - Créditer le montant PLEIN à chaque avenant réconcilié dès que le bordereau est
     *    soldé : un paiement réel unique de 75 908 $ produisait 166 463 $ encaissés.
     *  - N'admettre que le solde INTÉGRAL : un bordereau encaissé en partie ne créditait
     *    plus RIEN, et l'argent bien réel disparaissait de la colonne « Reste commission »,
     *    de la barre des totaux, du chiffre d'affaires et des réponses de l'assistant.
     *  - Répartir au PRORATA : le total redevenait juste, mais aucune tranche n'était
     *    JAMAIS soldée tant que le bordereau ne l'était pas — le filtre « commission
     *    payée » ne pouvait alors structurellement rien renvoyer.
     *
     * La règle retenue est l'IMPUTATION SUR LES PLUS ANCIENNES (droit commun, et façon
     * dont un courtier pointe un bordereau) : le règlement solde intégralement la tranche
     * dont l'échéance est la plus ancienne, puis la suivante, jusqu'à épuisement. Ce test
     * fixe les deux bornes : des tranches réellement soldées, et un agrégat qui ne dépasse
     * JAMAIS ce qui a été encaissé.
     *
     * Le seuil se mesure en outre contre le dû RÉEL des tranches couvertes, jamais contre
     * le champ auto-déclaré montantComHtPayableNow — qui peut sous-évaluer ce total si le
     * bordereau a accumulé des réconciliations sans être remis à jour (c'est le cas monté
     * ici : 500 déclaré pour 1000 réellement dus).
     */
    public function testEncaissementPartielEstImputeSurLesPlusAnciennes(): void
    {
        $em = $this->em();
        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);

        // Deux cotations INDÉPENDANTES (deux polices distinctes), chacune avec une
        // commission ASSUREUR de 500 (agrégat réel = 1000), réconciliées par le MÊME
        // bordereau. Mais Bordereau.montantComHtPayableNow est SOUS-ÉVALUÉ à 500 (ne
        // reflète qu'une seule des deux lignes, cas réel d'un champ non remis à jour).
        ['entreprise' => $entreprise, 'echue' => $trancheA] = $this->seed();
        $invite = $em->getRepository(Invite::class)->findOneBy(['entreprise' => $entreprise]);

        $piste2 = (new Piste())->setNom('Piste multi-avenants B')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque B')->setExercice(2026)
            ->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste2);
        $cotationB = (new Cotation())->setNom('Cotation multi-avenants B')->setDuree(365);
        $cotationB->setPiste($piste2);
        $cotationB->setEntreprise($entreprise);
        $em->persist($cotationB);
        $trancheB = (new Tranche())->setNom('Tranche multi-avenants B')->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('-30 days'))->setEcheanceAt(new \DateTimeImmutable('+30 days'));
        $trancheB->setCotation($cotationB);
        $trancheB->setEntreprise($entreprise);
        $em->persist($trancheB);

        foreach ([['cotation' => $trancheA->getCotation(), 'suffixe' => 'A'], ['cotation' => $cotationB, 'suffixe' => 'B']] as ['cotation' => $cotation, 'suffixe' => $suffixe]) {
            $typeRevenu = (new TypeRevenu())->setNom('Commission multi ' . $suffixe)
                ->setMontantflat(500.0)->setShared(false)->setMultipayments(true)
                ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
            $typeRevenu->setEntreprise($entreprise);
            $em->persist($typeRevenu);
            $revenu = (new RevenuPourCourtier())->setNom('Revenu multi ' . $suffixe)->setTypeRevenu($typeRevenu)->setCotation($cotation);
            $revenu->setEntreprise($entreprise);
            $em->persist($revenu);
        }

        $avenantA = new Avenant();
        $avenantA->setCotation($trancheA->getCotation())->setReferencePolice('POL-MULTI-A')->setNumero('0')
            ->setDescription('Avenant A')->setStartingAt(new \DateTimeImmutable('-60 days'))->setEndingAt(new \DateTimeImmutable('+305 days'));
        $avenantA->setEntreprise($entreprise);
        $avenantA->setInvite($invite);
        $em->persist($avenantA);

        $avenantB = new Avenant();
        $avenantB->setCotation($cotationB)->setReferencePolice('POL-MULTI-B')->setNumero('0')
            ->setDescription('Avenant B')->setStartingAt(new \DateTimeImmutable('-60 days'))->setEndingAt(new \DateTimeImmutable('+305 days'));
        $avenantB->setEntreprise($entreprise);
        $avenantB->setInvite($invite);
        $em->persist($avenantB);
        $em->flush();

        $bordereau = new Bordereau();
        $bordereau->setType(Bordereau::TYPE_BOREDERAU_PRODUCTION)
            ->setNom('Bordereau multi-avenants')->setReference('BRD-MULTI')
            ->setReceivedAt(new \DateTimeImmutable('-15 days'))
            ->setPeriodeDebut(new \DateTimeImmutable('-45 days'))
            ->setPeriodeFin(new \DateTimeImmutable('-15 days'))
            // Sous-évalué : ne reflète que l'avenant A (500), pas l'agrégat réel (1000).
            ->setMontantComHtPayableNow(500.0)
            ->setMontantTaxePayableNow(0.0)
            ->setAnalysisResults([
                ['type' => 'match', 'row_index' => 0, 'reference_police' => 'POL-MULTI-A', 'avenant_id' => $avenantA->getId()],
                ['type' => 'match', 'row_index' => 1, 'reference_police' => 'POL-MULTI-B', 'avenant_id' => $avenantB->getId()],
            ])
            ->setInvite($invite)
            ->setEntreprise($entreprise);
        $em->persist($bordereau);
        $em->flush();

        $trancheAId = $trancheA->getId();
        $trancheBId = $trancheB->getId();
        $bordereauId = $bordereau->getId();
        $entrepriseId = $entreprise->getId();
        $em->clear();
        $helper->reset();

        // 1) Paiement UNIQUE de 500 (= le champ sous-évalué, PAS l'agrégat réel de 1000) :
        //    le règlement descend de la tranche la plus ancienne vers la plus récente et
        //    s'arrête quand il est épuisé. Ni le montant plein partout (premier bug), ni
        //    zéro partout (deuxième), ni la moitié partout (troisième).
        $entreprise = $em->getRepository(Entreprise::class)->find($entrepriseId);
        $bordereau = $em->getRepository(Bordereau::class)->find($bordereauId);
        $invite = $em->getRepository(Invite::class)->findOneBy(['entreprise' => $entreprise]);
        $note = new Note();
        $note->setNom('Note multi-avenants')->setReference('NOTE-MULTI')
            ->setType(Note::TYPE_NOTE_DE_DEBIT)->setAddressedTo(Note::TO_ASSUREUR)
            ->setValidated(true)->setSignature('sig-test')->setBordereau($bordereau);
        $note->setEntreprise($entreprise);
        $note->setInvite($invite);
        $em->persist($note);
        $paiementPartiel = new Paiement();
        $paiementPartiel->setMontant(500.0)->setPaidAt(new \DateTimeImmutable('-2 days'))
            ->setReference('PAY-MULTI-1')->setNote($note);
        $paiementPartiel->setEntreprise($entreprise);
        $em->persist($paiementPartiel);
        $em->flush();
        $em->clear();
        $helper->reset();

        $trancheAFraiche = $em->getRepository(Tranche::class)->find($trancheAId);
        $trancheBFraiche = $em->getRepository(Tranche::class)->find($trancheBId);
        // trancheA est la PLUS ANCIENNE (échéance -10 j) et porte 50 % de sa cotation :
        // commission due 250, intégralement soldée par le règlement.
        $this->assertEqualsWithDelta(
            250.0,
            $helper->getTrancheMontantCommissionEncaissee($trancheAFraiche),
            0.01,
            'La tranche la plus ancienne est SOLDÉE la première, pas créditée d\'une fraction.'
        );
        // trancheB, la plus récente (+30 j), ne reçoit que le reliquat : 500 encaissés
        // − 250 (trancheA) − 2,50 (la tranche à échoir de la même cotation, 0,5 %).
        $this->assertEqualsWithDelta(
            247.5,
            $helper->getTrancheMontantCommissionEncaissee($trancheBFraiche),
            0.01,
            'La dernière servie ne reçoit que ce qui reste.'
        );

        // LA BORNE HAUTE, celle du premier bug : l'agrégat de ce qui est réputé encaissé
        // sur TOUTES les tranches des cotations réconciliées ne dépasse jamais l'argent
        // réellement reçu (500). Sans elle, 500 de vrai argent en produisaient 1000.
        $totalInfere = 0.0;
        foreach ($em->getRepository(Tranche::class)->findBy(['entreprise' => $entreprise]) as $t) {
            $totalInfere += $helper->getTrancheMontantCommissionEncaissee($t);
        }
        $this->assertLessThanOrEqual(
            500.0 + 0.01,
            $totalInfere,
            'Un paiement de 500 ne peut JAMAIS produire plus de 500 de commission encaissée inférée.'
        );

        // LES TROIS CHIPS DEVIENNENT TOUS UTILES, ce qui était l'objet du correctif : un
        // encaissement partiel produit des tranches SOLDÉES (les plus anciennes), une
        // tranche à cheval, et des tranches encore intactes. Avec un taux uniforme,
        // « Commission payée » ne pouvait structurellement rien renvoyer.
        $idsDe = fn (string $valeur): array => array_map(
            static fn (Tranche $t) => $t->getId(),
            $this->service()->search(Tranche::class, [TranchePaiementScope::AXE_COMMISSION => $valeur], $entreprise)['data'],
        );

        $this->assertContains(
            $trancheAId,
            $idsDe(TranchePaiementScope::PAYEE),
            'La plus ancienne est soldée : le chip « Commission payée » n\'est plus vide.'
        );
        $this->assertContains(
            $trancheBId,
            $idsDe(TranchePaiementScope::PARTIELLE),
            'Celle qui n\'a reçu que le reliquat est « partiellement encaissée ».'
        );
        $this->assertNotContains($trancheBId, $idsDe(TranchePaiementScope::PAYEE));

        // 2) Complément à 1000 (agrégat réel des deux avenants) : les deux tranches
        //    deviennent réputées encaissées, chacune à hauteur de SA propre part —
        //    jamais la totalité de l'agrégat du bordereau sur chacune.
        $entreprise = $em->getRepository(Entreprise::class)->find($entrepriseId);
        $note = $em->getRepository(Note::class)->findOneBy(['reference' => 'NOTE-MULTI']);
        $paiementSolde = new Paiement();
        $paiementSolde->setMontant(500.0)->setPaidAt(new \DateTimeImmutable('-1 day'))
            ->setReference('PAY-MULTI-2')->setNote($note);
        $paiementSolde->setEntreprise($entreprise);
        $em->persist($paiementSolde);
        $em->flush();
        $em->clear();
        $helper->reset();

        $trancheAFraiche = $em->getRepository(Tranche::class)->find($trancheAId);
        $trancheBFraiche = $em->getRepository(Tranche::class)->find($trancheBId);
        // trancheA (= « échue » du seed commun) ne représente que 50 % de sa cotation :
        // sa part de commission due est 500 x 50 % = 250. trancheB représente 100 % de
        // la sienne : sa part est 500 (pleine). Somme des deux = 750 ≠ 1000 (le solde
        // rétrocommission éventuel mis à part) — preuve que rien n'est jamais compté en
        // double : chaque tranche ne reçoit QUE sa propre part du dû réel, jamais la
        // totalité de l'agrégat du bordereau.
        $this->assertEqualsWithDelta(250.0, $helper->getTrancheMontantCommissionEncaissee($trancheAFraiche), 0.01, 'Bordereau intégralement soldé (1000 encaissé = 1000 dû) : commission A réputée encaissée à hauteur de SA part (50 % de 500).');
        $this->assertEqualsWithDelta(500.0, $helper->getTrancheMontantCommissionEncaissee($trancheBFraiche), 0.01, 'Commission B réputée encaissée à hauteur de SA part (100 % de 500).');
    }

    public function testValeurDAxeInvalideRetombeSurCheminStandard(): void
    {
        ['entreprise' => $entreprise] = $this->seed();

        // Valeur hors énumération : la clé d'axe est retirée (elle n'est pas filtrable en
        // SQL) et la recherche standard reprend, scopée. Surtout : aucun filtre fantôme.
        $resultat = $this->service()->search(
            Tranche::class,
            [TranchePaiementScope::AXE_PRIME => 'valeur-inconnue'],
            $entreprise,
        );

        $this->assertNull($resultat['status']['error']);
        $this->assertSame(2, $resultat['totalItems'], 'Critère retiré, recherche standard scopée.');
    }

    /**
     * Barre de recherche Tranches : filtrer par assureur / assuré (client) / risque
     * (chemins `cotation.assureur`, `cotation.piste.client`, `cotation.piste.risque`),
     * en plus de tout autre critère déjà actif. Vérifie le filtrage par identité
     * (sélecteur autocomplété), le repli texte (LIKE), la composition ET entre
     * plusieurs de ces critères, et la composition avec le critère synthétique
     * « Statut de paiement » (chip déjà en place sur la rubrique).
     */
    public function testFiltreParAssureurClientRisqueSeComposeAvecAutresCriteres(): void
    {
        ['entreprise' => $entreprise, 'echue' => $echue, 'aEchoir' => $aEchoir, 'etrangere' => $etrangere] = $this->seed();
        $em = $this->em();
        $invite = $em->getRepository(Invite::class)->findOneBy(['entreprise' => $entreprise]);

        $assureurA = (new Assureur())->setNom('Assureur A')->setEmail('assureur-a@test.local')
            ->setNumimpot('IMP-A')->setIdnat('IDNAT-A')->setRccm('RCCM-A');
        $assureurA->setEntreprise($entreprise);
        $em->persist($assureurA);

        $assureurB = (new Assureur())->setNom('Assureur B')->setEmail('assureur-b@test.local')
            ->setNumimpot('IMP-B')->setIdnat('IDNAT-B')->setRccm('RCCM-B');
        $assureurB->setEntreprise($entreprise);
        $em->persist($assureurB);

        $clientA = (new Client())->setNom('Client Assuré A')->setExonere(false);
        $clientA->setEntreprise($entreprise);
        $em->persist($clientA);

        $clientB = (new Client())->setNom('Client Assuré B')->setExonere(false);
        $clientB->setEntreprise($entreprise);
        $em->persist($clientB);

        $risqueA = (new Risque())->setNomComplet('Risque Incendie A')->setCode('RQ-A')
            ->setDescription('Risque A')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risqueA->setEntreprise($entreprise);
        $risqueA->setInvite($invite);
        $em->persist($risqueA);

        $risqueB = (new Risque())->setNomComplet('Risque Auto B')->setCode('RQ-B')
            ->setDescription('Risque B')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risqueB->setEntreprise($entreprise);
        $risqueB->setInvite($invite);
        $em->persist($risqueB);

        // La cotation du seed (porteuse de « echue » et « aEchoir ») est rattachée à
        // l'assureur/client/risque « A ».
        $cotationA = $em->getRepository(Cotation::class)->find($echue->getCotation()->getId());
        $cotationA->setAssureur($assureurA);
        $cotationA->getPiste()->setClient($clientA)->setRisque($risqueA);

        // Deuxième cotation, même entreprise, rattachée à l'assureur/client/risque « B »,
        // avec sa propre tranche impayée : témoin pour prouver l'isolation des filtres.
        $cotationB = $this->makeCotationAvecPrime($entreprise, $invite, 'Cotation Filtre B', 600.0);
        $cotationB->setAssureur($assureurB);
        $cotationB->getPiste()->setClient($clientB)->setRisque($risqueB);
        $trancheB = $this->makeTranche($cotationB, $entreprise, 'Tranche B', 100, new \DateTimeImmutable('-3 days'));

        $em->flush();
        $em->clear();

        $entreprise = $em->getRepository(Entreprise::class)->find($entreprise->getId());
        $echueId = $echue->getId();
        $aEchoirId = $aEchoir->getId();
        $trancheBId = $trancheB->getId();
        $etrangereId = $etrangere->getId();
        $assureurAId = $assureurA->getId();
        $assureurBId = $assureurB->getId();
        $clientAId = $clientA->getId();
        $clientBId = $clientB->getId();
        $risqueAId = $risqueA->getId();

        $idsOf = static fn (array $resultat): array => array_map(static fn (Tranche $t) => $t->getId(), $resultat['data']);

        // 1) Filtrage par identité (sélecteur autocomplété) sur chacun des 3 critères :
        // seules les tranches de la cotation « A » remontent, jamais celle de « B », ni
        // la tranche « étrangère » (autre entreprise, scoping AuditableTrait inchangé).
        foreach ([
            'cotation.assureur' => $assureurAId,
            'cotation.piste.client' => $clientAId,
            'cotation.piste.risque' => $risqueAId,
        ] as $champ => $id) {
            $resultat = $this->service()->search(Tranche::class, [$champ => ['operator' => '=', 'value' => $id]], $entreprise);
            $this->assertNull($resultat['status']['error'], "Champ {$champ}");
            $this->assertEqualsCanonicalizing([$echueId, $aEchoirId], $idsOf($resultat), "Filtrage par {$champ} (identité)");
            $this->assertNotContains($trancheBId, $idsOf($resultat));
            $this->assertNotContains($etrangereId, $idsOf($resultat));
        }

        // 2) Repli texte (LIKE sur le champ d'affichage), utilisé par la recherche
        // simple ou en absence de sélection via l'autocomplete.
        $parNom = $this->service()->search(
            Tranche::class,
            ['cotation.assureur' => ['operator' => 'LIKE', 'value' => 'Assureur A', 'targetField' => 'nom']],
            $entreprise,
        );
        $this->assertEqualsCanonicalizing([$echueId, $aEchoirId], $idsOf($parNom));

        $parNomB = $this->service()->search(
            Tranche::class,
            ['cotation.assureur' => ['operator' => 'LIKE', 'value' => 'Assureur B', 'targetField' => 'nom']],
            $entreprise,
        );
        $this->assertSame([$trancheBId], $idsOf($parNomB));

        // 3) Composition ET entre deux critères de relation : un assureur de la
        // cotation A combiné à un client de la cotation B ne peut rien matcher — la
        // preuve que les critères s'ADDITIONNENT (ET), jamais un OR silencieux.
        $contradictoire = $this->service()->search(
            Tranche::class,
            [
                'cotation.assureur' => ['operator' => '=', 'value' => $assureurAId],
                'cotation.piste.client' => ['operator' => '=', 'value' => $clientBId],
            ],
            $entreprise,
        );
        $this->assertSame(0, $contradictoire['totalItems'], 'Critères contradictoires (A ∩ B) : aucune tranche.');

        // 4) Composition avec le critère synthétique « Statut de paiement » (chip déjà
        // actif sur la rubrique) : le filtre assureur s'ajoute à l'intérieur du chemin
        // spécial in-memory de TranchePaiementScope, sans l'écraser.
        $impayeesAssureurA = $this->service()->search(
            Tranche::class,
            [
                TranchePaiementScope::AXE_PRIME => TranchePaiementScope::IMPAYEE,
                'cotation.assureur' => ['operator' => '=', 'value' => $assureurAId],
            ],
            $entreprise,
        );
        $this->assertEqualsCanonicalizing([$echueId, $aEchoirId], $idsOf($impayeesAssureurA));

        $impayeesAssureurB = $this->service()->search(
            Tranche::class,
            [
                TranchePaiementScope::AXE_PRIME => TranchePaiementScope::IMPAYEE,
                'cotation.assureur' => ['operator' => '=', 'value' => $assureurBId],
            ],
            $entreprise,
        );
        $this->assertSame([$trancheBId], $idsOf($impayeesAssureurB), 'Statut de paiement + assureur : intersection correcte.');
    }
}
