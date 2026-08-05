<?php

namespace App\Tests\Ai;

use App\Ai\Boussole\BoussoleService;
use App\Ai\Boussole\PlanDuJourService;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\SuiviImpayesTool;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\PaiementPrime;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\RevenuPourCourtier;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * RÉGRESSION DE L'INCIDENT DU 2026-08-05, reproduit sur la VRAIE base.
 *
 * L'utilisateur demande « les avenants à primes dues et impayées ». Ket annonce 5 lignes,
 * puis affirme au message suivant qu'une SEULE est « réellement impayée (solde de prime
 * > 0) », les autres ayant « un solde de prime de 0,00 USD » — puis, corrigée, redonne les
 * 5 avec des montants inventés. Les deux réponses étaient « vraies » sous deux lectures du
 * même mot : le filtre « impayées » signifiait « prime ET/OU commission », deux dettes de
 * débiteurs différents.
 *
 * Le semis reproduit exactement cette configuration : UNE tranche dont l'assuré doit encore
 * sa prime, QUATRE dont la prime est soldée mais dont l'assureur doit encore la commission.
 * Chaque assertion fixe une des affirmations que Ket n'arrivait pas à tenir d'un tour à
 * l'autre — et la dernière énonce formellement ce qu'elle ne savait pas dire : les deux
 * ensembles sont disjoints, et leur réunion fait cinq.
 */
class KetPrimesDuesE2ETest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-primesdues-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit PrimesDues SARL';

    private const PRIME = 1000.0;
    private const COMMISSION = 200.0;
    /** Part de prime déjà réglée sur la SEULE tranche encore débitrice. */
    private const PRIME_REGLEE_PARTIELLE = 400.0;

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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();

        // GOTCHA — le double lien Piste::avenantDeBase ⇄ Avenant::pisteDeRenouvellement est
        // un cycle de clés étrangères : il faut le DISSOCIER avant tout DELETE.
        foreach ([
            'UPDATE piste p JOIN entreprise e ON p.entreprise_id = e.id SET p.avenant_de_base_id = NULL WHERE e.nom = :nom',
            'UPDATE avenant a JOIN entreprise e ON a.entreprise_id = e.id SET a.piste_de_renouvellement_id = NULL WHERE e.nom = :nom',
        ] as $dissociation) {
            $conn->executeStatement($dissociation, ['nom' => self::ENTREPRISE_NOM]);
        }

        // Enfants avant parents.
        foreach ([
            'paiement_prime', 'avenant', 'revenu_pour_courtier', 'type_revenu', 'tranche',
            'chargement_pour_prime', 'cotation', 'piste', 'client', 'portefeuille', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM]
            );
        }
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    /**
     * Le portefeuille du gestionnaire porte les 5 tranches de l'incident ; un portefeuille
     * VOISIN en porte une sixième, également à prime due. Sans cette dernière, le test ne
     * distinguerait pas un outil scopé d'un outil qui ne l'est pas.
     *
     * @return array{entreprise: Entreprise, gestionnaire: Invite, soldePrimeDu: float}
     */
    private function seed(): array
    {
        $em = $this->em();

        $ownerUser = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)
            ->setNom('PHPUnit PrimesDues')
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($ownerUser);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)
            ->setLicence('LIC-PD')
            ->setAdresse('1 rue des Primes Dues')
            ->setTelephone('+243000000003')
            ->setRccm('RCCM-PD')
            ->setIdnat('IDNAT-PD')
            ->setNumimpot('IMP-PD')
            ->setUtilisateur($ownerUser);
        $em->persist($entreprise);
        $ownerUser->setConnectedTo($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire PrimesDues');
        $gestionnaire->setUtilisateur($ownerUser)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($gestionnaire);

        $voisin = (new Invite())->setNom('Gestionnaire voisin');
        $voisin->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($voisin);

        $portefeuille = (new Portefeuille())->setNom('Portefeuille PrimesDues')->setGestionnaire($gestionnaire);
        $portefeuille->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $portefeuilleVoisin = (new Portefeuille())->setNom('Portefeuille voisin')->setGestionnaire($voisin);
        $portefeuilleVoisin->setEntreprise($entreprise);
        $em->persist($portefeuilleVoisin);

        // 1) LA tranche dont l'assuré doit encore sa prime (règlement partiel).
        $due = $this->makeChaine($entreprise, $gestionnaire, $portefeuille, 'Prime due', -105);
        $this->makeSignalement($entreprise, $due, 'PD-PARTIEL', self::PRIME_REGLEE_PARTIELLE, '-30 days');

        // 2) Quatre tranches à prime SOLDÉE mais commission encore due par l'assureur :
        //    exactement les lignes qui remontaient comme « impayées » avec un solde de
        //    prime de 0,00 — l'ambiguïté que le découpage par axes rend impossible.
        foreach ([-77, -51, -35, -28] as $i => $retard) {
            $soldee = $this->makeChaine($entreprise, $gestionnaire, $portefeuille, 'Commission due ' . ($i + 1), $retard);
            $this->makeSignalement($entreprise, $soldee, 'PD-SOLDE-' . ($i + 1), self::PRIME, '-20 days');
        }

        // 3) Hors périmètre : prime due elle aussi, mais chez le voisin.
        $this->makeChaine($entreprise, $voisin, $portefeuilleVoisin, 'Voisine', -60);

        $em->flush();
        $em->clear(); // EM partagé : on repart d'entités fraîches.

        return [
            'entreprise' => $em->getRepository(Entreprise::class)->find($entreprise->getId()),
            'gestionnaire' => $em->getRepository(Invite::class)->find($gestionnaire->getId()),
            'soldePrimeDu' => self::PRIME - self::PRIME_REGLEE_PARTIELLE,
        ];
    }

    /**
     * Client → piste → cotation (prime + commission assureur) → avenant (la police, sans
     * quoi la tranche reste un PROJET non suivi) → tranche à 100 %, échue.
     */
    private function makeChaine(Entreprise $entreprise, Invite $invite, Portefeuille $portefeuille, string $suffixe, int $joursRetard): Tranche
    {
        $em = $this->em();

        $client = (new Client())->setNom('Client ' . $suffixe)->setExonere(false);
        $client->setEntreprise($entreprise)->setPortefeuille($portefeuille);
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Piste ' . $suffixe)
            ->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque de test primes dues')
            ->setExercice(2026)
            ->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation ' . $suffixe)->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        // addChargement() (et pas seulement setCotation()) synchronise la collection
        // inverse en mémoire : sans elle la prime calculée reste 0 → statut « N/A ».
        $chargement = (new ChargementPourPrime())
            ->setNom('Prime ' . $suffixe)
            ->setMontantFlatExceptionel(self::PRIME);
        $chargement->setEntreprise($entreprise);
        $cotation->addChargement($chargement);
        $em->persist($chargement);

        // Commission due par l'ASSUREUR : c'est elle qui crée la SECONDE dette, celle
        // dont le débiteur n'est pas l'assuré.
        $typeRevenu = (new TypeRevenu())
            ->setNom('Commission ' . $suffixe)
            ->setMontantflat(self::COMMISSION)
            ->setShared(false)
            ->setMultipayments(true)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())
            ->setNom('Revenu ' . $suffixe)
            ->setTypeRevenu($typeRevenu)
            ->setCotation($cotation);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        // La police : sans avenant, la cotation reste une proposition et ses tranches
        // sortent de tout suivi (règle isBound), quel que soit le retard.
        $avenant = (new Avenant())
            ->setReferencePolice('POL-' . $suffixe)
            ->setNumero('0')
            ->setDescription('Police de test primes dues')
            ->setStartingAt(new \DateTimeImmutable('-1 year'))
            ->setEndingAt(new \DateTimeImmutable('+30 days'));
        $avenant->setCotation($cotation);
        $avenant->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($avenant);

        $tranche = (new Tranche())
            ->setNom('Tranche ' . $suffixe)
            ->setPourcentage(100.0) // 100 % en POINTS (convention pourcentage, pas fraction)
            ->setPayableAt(new \DateTimeImmutable($joursRetard . ' days'))
            ->setEcheanceAt(new \DateTimeImmutable($joursRetard . ' days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);

        return $tranche;
    }

    private function makeSignalement(Entreprise $entreprise, Tranche $tranche, string $reference, float $montant, string $quand): void
    {
        $paiement = (new PaiementPrime())
            ->setReference($reference)
            ->setMontant($montant)
            ->setPaidAt(new \DateTimeImmutable($quand))
            ->setDescription('Avis de règlement ' . $reference)
            ->setTranche($tranche);
        $paiement->setEntreprise($entreprise);
        $this->em()->persist($paiement);
    }

    private function outil(): SuiviImpayesTool
    {
        return static::getContainer()->get(SuiviImpayesTool::class);
    }

    // ------------------------------------------------------------------ tests

    /**
     * LA question de l'incident, telle qu'elle aurait dû se poser : « quelles primes sont
     * encore dues ? ». Une seule réponse possible, et elle ne bouge plus d'un tour à
     * l'autre parce qu'aucun autre filtre ne peut prétendre au même nom.
     */
    public function testPrimesDuesNeCompteQueLaDetteDeLAssure(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $invite, 'soldePrimeDu' => $solde] = $this->seed();
        $scope = new AiScope($entreprise, $invite);

        $result = $this->outil()->execute(['axes' => ['prime' => 'impayee']], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(1, $result->data['total'], 'Une seule tranche a une prime encore due.');
        $this->assertSame('Prime impayée', $result->data['filtre']);
        $this->assertSame('Portefeuille PrimesDues', $result->data['perimetre']);

        $ligne = $result->data['lignes'][0];
        $this->assertSame('Tranche Prime due', $ligne['tranche']);
        $this->assertSame('prime+commission', $ligne['dette'], 'Cette tranche doit les DEUX dettes.');
        $this->assertEqualsWithDelta($solde, $ligne['soldePrime'], 0.01);
        $this->assertEqualsWithDelta($solde, $result->data['totaux']['totalSoldePrime'], 0.01);

        // La tranche du portefeuille voisin, pourtant à prime due, reste hors périmètre.
        $this->assertNotContains(
            'Tranche Voisine',
            array_column($result->data['lignes'], 'tranche'),
        );
    }

    /**
     * Les QUATRE lignes que Ket présentait comme des « primes impayées » : leur solde de
     * prime est bel et bien nul, et la dette qui reste est celle de l'ASSUREUR. C'est
     * l'affirmation qu'elle a faite au deuxième message — juste, mais inexploitable tant
     * qu'aucun filtre ne permettait de la demander.
     */
    public function testCommissionsExigiblesOntUnSoldeDePrimeNul(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $invite] = $this->seed();
        $scope = new AiScope($entreprise, $invite);

        $result = $this->outil()->execute(
            ['axes' => ['prime' => 'payee', 'commission' => 'impayee']],
            $scope,
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(4, $result->data['total']);
        $this->assertSame('Prime payée · Commission impayée', $result->data['filtre']);

        foreach ($result->data['lignes'] as $ligne) {
            $this->assertSame(0.0, $ligne['soldePrime'], 'Prime SOLDÉE sur ces quatre lignes.');
            $this->assertGreaterThan(0.0, $ligne['soldeCommission']);
            $this->assertSame('commission', $ligne['dette'], 'La dette restante est celle de l\'assureur.');
        }
    }

    /**
     * L'ÉNONCÉ FORMEL de ce que Ket n'arrivait pas à dire : les deux ensembles sont
     * DISJOINTS, leur réunion fait cinq, et le « 5 » comme le « 1 » étaient donc tous deux
     * exacts — sur deux questions différentes. La répartition livre les deux comptes d'un
     * coup, avec leur débiteur, dès qu'aucune dette n'est ciblée.
     */
    public function testLesDeuxDettesSontDisjointesEtLeurReunionFaitCinq(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $invite] = $this->seed();
        $scope = new AiScope($entreprise, $invite);
        $outil = $this->outil();

        $idsDe = static fn (AiToolResult $r): array => array_column($r->data['lignes'], 'id');

        $primesDues = $idsDe($outil->execute(['axes' => ['prime' => 'impayee']], $scope));
        $commissionsSeules = $idsDe($outil->execute(
            ['axes' => ['prime' => 'payee', 'commission' => 'impayee']],
            $scope,
        ));

        $this->assertSame([], array_intersect($primesDues, $commissionsSeules), 'Ensembles DISJOINTS.');
        $this->assertCount(5, array_merge($primesDues, $commissionsSeules), 'Leur réunion : les 5 lignes de l\'incident.');

        // Sans axe de dette, la sortie porte les deux comptes nommés : c'est ce bloc qui
        // remplace le « 5 » ambigu par une phrase que l'utilisateur peut trancher.
        $sansAxe = $outil->execute([], $scope);
        $this->assertSame(5, $sansAxe->data['total']);
        $repartition = $sansAxe->data['repartition'];
        $this->assertSame(1, $repartition['primeImpayee']['nb']);
        $this->assertSame('l\'assuré', $repartition['primeImpayee']['debiteur']);
        $this->assertSame(5, $repartition['commissionImpayee']['nb'], 'Les 5 doivent encore leur commission.');
        $this->assertSame('l\'assureur', $repartition['commissionImpayee']['debiteur']);
        $this->assertStringContainsString('CHEVAUCHENT', $repartition['rappel']);
    }

    /**
     * LA CAUSE DU TROISIÈME MESSAGE. La boussole affichait « 5 primes exigibles impayées »
     * tout en valorisant le seul solde de PRIME : compte et montant ne portaient pas sur la
     * même dette, et la règle « AUTORITÉ DES COMPTES » du prompt ramenait Ket au 5 — qu'elle
     * garnissait alors de montants inventés. L'invariant est désormais testable : le compte
     * et le montant d'un axe portent sur la même dette.
     */
    public function testBoussoleCompteEtMontantPortentSurLaMemeDette(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $invite, 'soldePrimeDu' => $solde] = $this->seed();

        /** @var BoussoleService $boussole */
        $boussole = static::getContainer()->get(BoussoleService::class);
        $items = $boussole->etat($entreprise, $invite)['items'];
        $parAxe = array_column($items, null, 'axe');

        $primes = $parAxe['primes_impayees'] ?? null;
        $this->assertNotNull($primes, 'L\'axe des primes doit être présent.');
        $this->assertSame(1, $primes['compte'], 'Une seule prime encore due par un client.');
        $this->assertEqualsWithDelta($solde, $primes['montant'], 0.01, 'Le montant doit être celui de CETTE prime.');
        $this->assertStringContainsString('due(s) par les clients', $primes['libelle']);

        // Les commissions ne disparaissent pas : elles ont leur propre axe, avec leur
        // propre débiteur. Aucune dette n'est perdue par le découpage.
        $commissions = $parAxe['commissions'] ?? null;
        $this->assertNotNull($commissions);
        $this->assertSame(4, $commissions['compte'], 'Quatre commissions exigibles auprès de l\'assureur.');
    }

    /**
     * Le programme du jour titre « Primes à encaisser — dues par les clients ». Il était
     * alimenté par le filtre ambigu : le titre promettait des primes, le compte en livrait
     * cinq dont quatre soldées. C'est la bulle que Ket lit à l'ouverture, donc le premier
     * chiffre qu'elle reprend.
     */
    public function testProgrammeDuJourNeCompteQueLesPrimesReellementDues(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $invite, 'soldePrimeDu' => $solde] = $this->seed();

        /** @var PlanDuJourService $planDuJour */
        $planDuJour = static::getContainer()->get(PlanDuJourService::class);
        $sections = array_column($planDuJour->plan($entreprise, $invite)['sections'], null, 'cle');

        $primes = $sections['primes_dues'] ?? null;
        $this->assertNotNull($primes, 'La section des primes dues doit être présente.');
        $this->assertSame(1, $primes['compte']);
        $this->assertEqualsWithDelta($solde, $primes['montant'], 0.01);
        $this->assertSame('Tranche Prime due', $primes['lignes'][0]['libelle']);

        $commissions = $sections['commissions_a_facturer'] ?? null;
        $this->assertNotNull($commissions);
        $this->assertSame(4, $commissions['compte']);
    }
}
