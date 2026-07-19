<?php

namespace App\Tests\Services;

use App\Entity\Article;
use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\PaiementPrime;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\TranchePaiementScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Moteur de recherche, critère synthétique « Statut de paiement » (Tranche) :
 * bascule sur le chemin in-memory (filtre par statut calculé + tri par urgence +
 * pagination), scoping entreprise conservé (AuditableTrait), non-régression du
 * chemin standard (ordre id DESC) quand le critère est absent.
 */
class JSBDynamicSearchServiceTrancheTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-tranchepaie-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit TranchePaie SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit TranchePaie Autre SARL';

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
        // tranches/chargements → cotations → pistes → invites.
        foreach (['paiement', 'article', 'note', 'bordereau', 'avenant', 'revenu_pour_courtier', 'type_revenu', 'paiement_prime', 'tranche', 'chargement_pour_prime', 'cotation', 'piste', 'invite'] as $table) {
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
     * Une cotation avec une prime client réelle (ChargementPourPrime), condition
     * pour que les tranches aient un statut calculable (sinon « N/A »).
     */
    private function makeCotationAvecPrime(Entreprise $entreprise, Invite $invite, string $nom, float $prime): Cotation
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
            [TranchePaiementScope::CRITERION_KEY => 'impayees'],
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

        $echues = $this->service()->search(
            Tranche::class,
            [TranchePaiementScope::CRITERION_KEY => ['operator' => '=', 'value' => 'echues', 'label' => 'Échues']],
            $entreprise,
        );
        $this->assertSame(1, $echues['totalItems']);
        $this->assertSame($echue->getId(), $echues['data'][0]->getId());

        $payees = $this->service()->search(
            Tranche::class,
            [TranchePaiementScope::CRITERION_KEY => 'payees'],
            $entreprise,
        );
        $this->assertSame(0, $payees['totalItems'], 'Aucun encaissement : rien n\'est payé.');
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
            [TranchePaiementScope::CRITERION_KEY => 'impayees'],
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
            [TranchePaiementScope::CRITERION_KEY => 'payees'],
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
        $payees = $this->service()->search(Tranche::class, [TranchePaiementScope::CRITERION_KEY => 'payees'], $entreprise);
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

        $payees = $this->service()->search(Tranche::class, [TranchePaiementScope::CRITERION_KEY => 'payees'], $entreprise);
        $this->assertSame(
            [$echueId],
            array_map(static fn (Tranche $t) => $t->getId(), $payees['data']),
            'Commission encaissée via bordereau : la tranche sort des impayées sans signalement manuel.'
        );
        $this->assertSame('Payée', $payees['data'][0]->statutPaiement);

        // L'autre tranche (aucune note, aucun encaissement) reste impayée.
        $impayees = $this->service()->search(Tranche::class, [TranchePaiementScope::CRITERION_KEY => 'impayees'], $entreprise);
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

        $exigibles = $this->service()->search(Tranche::class, [TranchePaiementScope::CRITERION_KEY => 'commission_exigible'], $entreprise);
        $idsExigibles = array_map(static fn (Tranche $t) => $t->getId(), $exigibles['data']);
        $this->assertContains($echueId, $idsExigibles, 'Prime détenue par l\'assureur → commission à réclamer.');
        $this->assertSame('Prime payée, commission due', $exigibles['data'][0]->statutPaiement);

        $payees = $this->service()->search(Tranche::class, [TranchePaiementScope::CRITERION_KEY => 'payees'], $entreprise);
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
        $payees = $this->service()->search(Tranche::class, [TranchePaiementScope::CRITERION_KEY => 'payees'], $entreprise);
        $idsPayees = array_map(static fn (Tranche $t) => $t->getId(), $payees['data']);
        $this->assertContains($echueId, $idsPayees, 'Bordereau soldé sans articles : la tranche doit être payée.');
        $this->assertContains($aEchoirId, $idsPayees, 'Toutes les tranches de l\'avenant attesté sont couvertes.');

        $impayees = $this->service()->search(Tranche::class, [TranchePaiementScope::CRITERION_KEY => 'impayees'], $entreprise);
        $this->assertSame(0, $impayees['totalItems'], 'Plus aucun reste dû : ni prime (attestée) ni commission (bordereau soldé).');

        $exigibles = $this->service()->search(Tranche::class, [TranchePaiementScope::CRITERION_KEY => 'commission_exigible'], $entreprise);
        $this->assertSame(0, $exigibles['totalItems'], 'Commission encaissée : l\'exigibilité s\'éteint.');
    }

    public function testStatutInvalideRetombeSurCheminStandard(): void
    {
        ['entreprise' => $entreprise] = $this->seed();

        $resultat = $this->service()->search(
            Tranche::class,
            [TranchePaiementScope::CRITERION_KEY => 'valeur-inconnue'],
            $entreprise,
        );

        $this->assertNull($resultat['status']['error']);
        $this->assertSame(2, $resultat['totalItems'], 'Critère retiré, recherche standard scopée.');
    }
}
