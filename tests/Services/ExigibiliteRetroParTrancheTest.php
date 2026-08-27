<?php

namespace App\Tests\Services;

use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Canvas\Indicator\TrancheIndicatorStrategy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'AGENT DEVIENT EXIGIBLE AU RYTHME DES TRANCHES, comme le partenaire.
 *
 * ── CE QUI CHANGE, ET POURQUOI C'EST DIT ICI ────────────────────────────────────────
 *
 * L'exigibilité de l'agent était TOUT-OU-RIEN au niveau de la cotation :
 * `getAvenantRetroAgentExigible()` ne rendait le solde que si `encaissee >= due`, la due
 * étant la commission de l'AFFAIRE ENTIÈRE. Un cabinet ayant encaissé la commission de la
 * première échéance ne devait donc rien à son agent — alors que l'argent était là.
 *
 * Le partenaire, lui, suivait déjà le rythme des tranches
 * (`TrancheIndicatorStrategy::retroCommissionExigible`). Deux familles, deux règles, sur le
 * même argent.
 *
 * C'est une modification d'une règle d'exigibilité, décidée explicitement : des montants
 * aujourd'hui à 0 deviennent réclamables. Ce test EXISTE POUR MESURER CET ÉCART — il
 * échouait avant le changement en annonçant 0 là où il attend désormais la part de la
 * tranche encaissée.
 *
 * ⚠ CE QUI NE CHANGE PAS : le DÛ. Assiette, taux, prorata de tranche sont intacts, et le
 * témoin d'arithmétique de `RetroCommissionAgentTest` le vérifie au centime.
 */
class ExigibiliteRetroParTrancheTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-exigibilite-tranche@test.local';
    private const ENT = 'PHPUnit Exigibilite Tranche SARL';

    /** Commission de l'affaire entière. */
    private const COMMISSION = 1000.0;
    /** Taux de l'agent, en POINTS. */
    private const TAUX_AGENT = 10.0;
    /** La première échéance porte 60 % de l'affaire, la seconde 40 %. */
    private const PART_T1 = 60.0;
    private const PART_T2 = 40.0;

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

    private function helper(): IndicatorCalculationHelper
    {
        return static::getContainer()->get(IndicatorCalculationHelper::class);
    }

    private function strategieTranche(): TrancheIndicatorStrategy
    {
        return static::getContainer()->get(TrancheIndicatorStrategy::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $tables = [
            'paiement', 'article', 'note', 'reversement_retro_agent', 'tranche', 'avenant',
            'revenu_pour_courtier', 'type_revenu', 'chargement_pour_prime', 'cotation',
            'condition_partage', 'piste', 'client', 'assureur', 'risque', 'invite',
        ];
        foreach ($tables as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Une affaire, DEUX échéances, et la commission de la PREMIÈRE seulement encaissée.
     *
     * C'est le cas qui distingue les deux règles : sous l'ancienne, rien n'est exigible
     * puisque l'affaire n'est pas entièrement encaissée ; sous la nouvelle, la part de la
     * première échéance l'est.
     *
     * @return array{entrepriseId:int, agentId:int, avenantId:int, t1Id:int, t2Id:int}
     */
    private function semer(bool $encaisserT1 = true): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Exigibilite')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $alice = (new Invite())->setNom('Alice')->setProprietaire(false);
        $alice->setEntreprise($entreprise);
        $em->persist($alice);

        $assureur = (new Assureur())->setNom('Assureur Exigibilite')
            ->setEmail('assureur-exigibilite@test.local')
            ->setNumimpot('IMP-E')->setIdnat('IDNAT-E')->setRccm('RCCM-E');
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        $risque = (new Risque())->setCode('EXI')->setNomComplet('Risque exigibilite')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client exigibilite')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Affaire exigibilite')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($gestionnaire);
        $em->persist($piste);

        $condition = (new ConditionPartage())->setNom('Prime Alice')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(self::TAUX_AGENT)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($alice);
        $condition->setEntreprise($entreprise);
        $em->persist($condition);
        $piste->addConditionsPartageAgent($condition);

        $cotation = (new Cotation())->setNom('Cotation exigibilite')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($entreprise)->setAssureur($assureur);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime')->setMontantFlatExceptionel(5000.0);
        $chargement->setEntreprise($entreprise);
        $cotation->addChargement($chargement);
        $em->persist($chargement);

        $typeRevenu = (new TypeRevenu())->setNom('Commission')->setMontantflat(self::COMMISSION)
            ->setShared(true)->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())->setNom('Revenu')->setTypeRevenu($typeRevenu)->setCotation($cotation);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        $faireTranche = function (string $nom, float $part) use ($em, $entreprise, $cotation): Tranche {
            $t = (new Tranche())->setNom($nom)->setPourcentage($part)
                ->setPayableAt(new \DateTimeImmutable('-30 days'))
                ->setEcheanceAt(new \DateTimeImmutable('+30 days'));
            $t->setCotation($cotation)->setEntreprise($entreprise);
            $em->persist($t);

            return $t;
        };
        $t1 = $faireTranche('1re échéance', self::PART_T1);
        $t2 = $faireTranche('2e échéance', self::PART_T2);

        $avenant = (new Avenant())->setReferencePolice('POL-EXI')->setNumero('0')->setDescription('Police')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        if ($encaisserT1) {
            // La commission de la PREMIÈRE échéance seulement : facturée à l'assureur et
            // réglée. L'affaire, elle, reste partiellement encaissée.
            $note = (new Note())->setNom('Note commission T1')->setType(0)
                ->setAddressedTo(Note::TO_ASSUREUR)->setReference('N-EXI-1')
                ->setValidated(true)->setSignature('')
                ->setAssureur($assureur);
            $note->setEntreprise($entreprise);
            $em->persist($note);

            $article = (new Article())->setNote($note)->setRevenuFacture($revenu)->setTranche($t1);
            $article->setEntreprise($entreprise);
            $em->persist($article);

            $reglement = (new Paiement())->setMontant(self::COMMISSION * self::PART_T1 / 100)
                ->setReference('ENC-EXI-1')->setPaidAt(new \DateTimeImmutable('-5 days'))
                ->setNote($note);
            $reglement->setEntreprise($entreprise);
            $em->persist($reglement);
        }

        $em->flush();
        $ids = [
            'entrepriseId' => (int) $entreprise->getId(),
            'agentId'      => (int) $alice->getId(),
            'avenantId'    => (int) $avenant->getId(),
            't1Id'         => (int) $t1->getId(),
            't2Id'         => (int) $t2->getId(),
        ];
        $em->clear();

        return $ids;
    }

    private function tranche(int $id): Tranche
    {
        return $this->em()->getRepository(Tranche::class)->find($id);
    }

    /** La part de rétro agent portée par une échéance : le DÛ, qui ne change pas. */
    private function dueDe(int $trancheId): float
    {
        return (float) ($this->strategieTranche()->calculate($this->tranche($trancheId))['retroAgentDue'] ?? 0.0);
    }

    private function exigibleDe(int $trancheId): float
    {
        return (float) ($this->strategieTranche()->calculate($this->tranche($trancheId))['retroAgentExigible'] ?? 0.0);
    }

    // ===================== Le dû, inchangé =====================

    /**
     * Le DÛ se répartit sur les échéances au prorata déjà déclaré. On l'épingle ici parce
     * que tout le reste s'y compare : si cette répartition bougeait, l'exigibilité
     * paraîtrait fausse alors que la faute serait ailleurs.
     */
    public function testLeDuSeRepartitSurLesEcheances(): void
    {
        $ids = $this->semer();
        $retroTotale = self::COMMISSION * self::TAUX_AGENT / 100;

        self::assertEqualsWithDelta($retroTotale * self::PART_T1 / 100, $this->dueDe($ids['t1Id']), 0.01);
        self::assertEqualsWithDelta($retroTotale * self::PART_T2 / 100, $this->dueDe($ids['t2Id']), 0.01);
    }

    // ===================== L'exigibilité, au rythme des tranches =====================

    /**
     * L'ÉCART MESURÉ : la première échéance encaissée rend SA part exigible.
     *
     * Sous l'ancienne règle, ce montant valait 0 — l'affaire n'étant pas entièrement
     * encaissée, l'agent ne pouvait rien réclamer bien que l'argent fût là.
     */
    public function testLEcheanceEncaisseeRendSaPartExigible(): void
    {
        $ids = $this->semer();

        self::assertEqualsWithDelta(
            $this->dueDe($ids['t1Id']),
            $this->exigibleDe($ids['t1Id']),
            0.01,
            'La commission de cette échéance est encaissée : la part de l’agent est réclamable.',
        );
    }

    /** Et l'échéance NON encaissée ne l'est pas : le rythme est bien celui des tranches. */
    public function testLEcheanceNonEncaisseeNEstPasExigible(): void
    {
        $ids = $this->semer();

        self::assertGreaterThan(0.0, $this->dueDe($ids['t2Id']), 'Cette échéance doit bien porter un dû.');
        self::assertEqualsWithDelta(0.0, $this->exigibleDe($ids['t2Id']), 0.01);
    }

    /** Rien d'encaissé, rien d'exigible — la règle antérieure, qui reste vraie. */
    public function testSansAucunEncaissementRienNEstExigible(): void
    {
        $ids = $this->semer(encaisserT1: false);

        self::assertEqualsWithDelta(0.0, $this->exigibleDe($ids['t1Id']), 0.01);
        self::assertEqualsWithDelta(0.0, $this->exigibleDe($ids['t2Id']), 0.01);
    }

    /**
     * L'EXIGIBLE DE L'AFFAIRE EST LA SOMME DE SES ÉCHÉANCES.
     *
     * C'est ce que lit le rapport de production, dont les lignes restent par affaire. Une
     * somme, jamais un prorata : le prorata a déjà eu lieu, à la maille de la tranche.
     */
    public function testLExigibleDeLAffaireEstLaSommeDeSesEcheances(): void
    {
        $ids = $this->semer();
        $avenant = $this->em()->getRepository(Avenant::class)->find($ids['avenantId']);
        $agent = $this->em()->getRepository(Invite::class)->find($ids['agentId']);

        self::assertEqualsWithDelta(
            $this->exigibleDe($ids['t1Id']) + $this->exigibleDe($ids['t2Id']),
            $this->helper()->getAvenantRetroAgentExigible($avenant, $agent),
            0.01,
            'L’affaire ne peut pas dire autre chose que la somme de ses échéances.',
        );
    }
}
