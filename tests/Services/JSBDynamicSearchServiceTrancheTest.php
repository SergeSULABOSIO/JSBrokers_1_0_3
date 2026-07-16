<?php

namespace App\Tests\Services;

use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\PaiementPrime;
use App\Entity\Piste;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
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

        // Enfants avant parents : signalements → tranches/chargements → cotations → pistes → invites.
        foreach (['paiement_prime', 'tranche', 'chargement_pour_prime', 'cotation', 'piste', 'invite'] as $table) {
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
        $echue = $this->makeTranche($cotation, $entreprise, 'Tranche échue', 0.5, new \DateTimeImmutable('-10 days'));
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
