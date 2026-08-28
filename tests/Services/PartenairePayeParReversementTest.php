<?php

namespace App\Tests\Services;

use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CE QUI A ÉTÉ REVERSÉ À UN PARTENAIRE SE LIT SUR LES REVERSEMENTS.
 *
 * ── LA BASCULE DE SOURCE, ET LE PIÈGE QU'ELLE ÉVITE ─────────────────────────────────
 *
 * Le « payé » d'un partenaire se DÉDUISAIT du prorata des règlements d'une note de crédit
 * (`Article → Note → paiements`) : aucun enregistrement de versement n'existait pour lui.
 * Il facture désormais le cabinet par SA note de débit, le cabinet lui reverse et garde la
 * pièce — exactement comme pour un agent interne.
 *
 * LE PIÈGE : si les deux sources étaient additionnées, une affaire portant à la fois une
 * note réglée et un reversement compterait le MÊME euro deux fois. Rien à l'écran ne le
 * dirait, et le solde d'un intermédiaire paraîtrait éteint alors qu'il resterait dû. Ce
 * test tient donc les deux moitiés : le reversement compte, la note ne compte PLUS.
 *
 * ⚠ CE QUI NE CHANGE PAS : le DÛ. `...PayableParCourtier()` (sans « Payee ») garde son
 * assiette, son taux et son seuil — la vérification est faite ici même.
 */
class PartenairePayeParReversementTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-paye-reversement@test.local';
    private const ENT = 'PHPUnit Paye Reversement SARL';

    private const COMMISSION = 1000.0;
    private const PART_PARTENAIRE = 20.0;

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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $tables = [
            'paiement', 'article', 'note', 'reversement_retro_agent', 'tranche', 'avenant',
            'revenu_pour_courtier', 'type_revenu', 'chargement_pour_prime', 'cotation',
            'piste', 'client', 'assureur', 'partenaire', 'risque', 'invite',
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
     * Une affaire apportée par un partenaire, avec UNE échéance.
     *
     * @return array{entrepriseId:int, partenaireId:int, cotationId:int, trancheId:int, avenantId:int, inviteId:int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Paye')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire Paye')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $assureur = (new Assureur())->setNom('Assureur Paye')->setEmail('assureur-paye@test.local')
            ->setNumimpot('IMP-P')->setIdnat('IDNAT-P')->setRccm('RCCM-P');
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        $partenaire = (new Partenaire())->setNom('SUNU Courtage')->setPart(self::PART_PARTENAIRE);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $risque = (new Risque())->setCode('PAY')->setNomComplet('Risque paye')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client paye')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Affaire paye')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($gestionnaire)->setPartenaire($partenaire);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation paye')->setDuree(365);
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

        $tranche = (new Tranche())->setNom('Échéance unique')->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('-30 days'))
            ->setEcheanceAt(new \DateTimeImmutable('+30 days'));
        $tranche->setCotation($cotation)->setEntreprise($entreprise);
        $em->persist($tranche);

        $avenant = (new Avenant())->setReferencePolice('POL-PAY')->setNumero('0')->setDescription('Police')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $em->flush();
        $ids = [
            'entrepriseId' => (int) $entreprise->getId(),
            'partenaireId' => (int) $partenaire->getId(),
            'cotationId'   => (int) $cotation->getId(),
            'trancheId'    => (int) $tranche->getId(),
            'avenantId'    => (int) $avenant->getId(),
            'inviteId'     => (int) $gestionnaire->getId(),
        ];
        $em->clear();

        return $ids;
    }

    /** Enregistre un reversement au partenaire, à la maille de l'échéance. */
    private function reverser(array $ids, float $montant): void
    {
        $em = $this->em();
        $r = (new ReversementRetroAgent())
            ->setPartenaire($em->getRepository(Partenaire::class)->find($ids['partenaireId']))
            ->setTranche($em->getRepository(Tranche::class)->find($ids['trancheId']))
            ->setAvenant($em->getRepository(Avenant::class)->find($ids['avenantId']))
            ->setMontant($montant)
            ->setPaidAt(new \DateTimeImmutable('-2 days'))
            ->setReference('VIR-PART-1');
        $r->setEntreprise($em->getRepository(Entreprise::class)->find($ids['entrepriseId']))
            ->setInvite($em->getRepository(Invite::class)->find($ids['inviteId']));
        $em->persist($r);
        $em->flush();
        $em->clear();
    }

    /**
     * Facture la rétro par une NOTE DE CRÉDIT au partenaire, et la règle : le circuit
     * d'AVANT, qui ne doit plus compter.
     */
    private function facturerParNoteDeCredit(array $ids, float $montant): void
    {
        $em = $this->em();
        $entreprise = $em->getRepository(Entreprise::class)->find($ids['entrepriseId']);
        $partenaire = $em->getRepository(Partenaire::class)->find($ids['partenaireId']);
        $tranche = $em->getRepository(Tranche::class)->find($ids['trancheId']);
        $cotation = $em->getRepository(Cotation::class)->find($ids['cotationId']);
        $revenu = $cotation->getRevenus()->first();

        $note = (new Note())->setNom('Note rétro partenaire')->setType(Note::TYPE_NOTE_DE_CREDIT)
            ->setAddressedTo(Note::TO_PARTENAIRE)->setReference('NC-PART-1')
            ->setValidated(true)->setSignature('')
            ->setPartenaire($partenaire);
        $note->setEntreprise($entreprise);
        $em->persist($note);

        $article = (new Article())->setNote($note)->setRevenuFacture($revenu)->setTranche($tranche);
        $article->setEntreprise($entreprise);
        $em->persist($article);

        $reglement = (new Paiement())->setMontant($montant)->setReference('REG-PART-1')
            ->setPaidAt(new \DateTimeImmutable('-1 day'))->setNote($note);
        $reglement->setEntreprise($entreprise);
        $em->persist($reglement);

        $em->flush();
        $em->clear();
    }

    private function cotation(int $id): Cotation
    {
        return $this->em()->getRepository(Cotation::class)->find($id);
    }

    private function payee(array $ids): float
    {
        return $this->helper()->getCotationMontantRetrocommissionsPayableParCourtierPayee(
            $this->cotation($ids['cotationId']),
            $this->em()->getRepository(Partenaire::class)->find($ids['partenaireId']),
        );
    }

    // ===================== Le dû, intact =====================

    /** Le DÛ ne bouge pas : 20 % de la commission pure, comme avant ce lot. */
    public function testLeDuResteInchange(): void
    {
        $ids = $this->semer();

        self::assertEqualsWithDelta(
            self::COMMISSION * self::PART_PARTENAIRE / 100,
            $this->helper()->getCotationMontantRetrocommissionsPayableParCourtier(
                $this->cotation($ids['cotationId']),
                null,
                -1,
            ),
            0.01,
        );
    }

    // ===================== La nouvelle source =====================

    /** Sans reversement, rien n'est payé. */
    public function testSansReversementRienNEstPaye(): void
    {
        $ids = $this->semer();

        self::assertEqualsWithDelta(0.0, $this->payee($ids), 0.01);
    }

    /** LE REVERSEMENT COMPTE : c'est désormais la source du payé. */
    public function testLeReversementEstLaSourceDuPaye(): void
    {
        $ids = $this->semer();
        $this->reverser($ids, 120.0);

        self::assertEqualsWithDelta(120.0, $this->payee($ids), 0.01);
    }

    // ===================== L'ancienne source ne compte plus =====================

    /**
     * UNE NOTE DE CRÉDIT RÉGLÉE NE COMPTE PLUS — c'est la moitié du test qui évite le
     * double compte.
     *
     * Si les deux sources étaient additionnées, ce même euro serait compté deux fois et le
     * solde du partenaire paraîtrait éteint alors qu'il resterait dû.
     */
    public function testUneNoteDeCreditRegleeNeCompteplus(): void
    {
        $ids = $this->semer();
        $this->facturerParNoteDeCredit($ids, 200.0);

        self::assertEqualsWithDelta(
            0.0,
            $this->payee($ids),
            0.01,
            'Le circuit note de crédit est retiré : il ne doit plus alimenter le payé.',
        );
    }

    /** Et les deux ensemble ne comptent qu'UNE fois : celle du reversement. */
    public function testLesDeuxEnsembleNeComptentQuUneFois(): void
    {
        $ids = $this->semer();
        $this->facturerParNoteDeCredit($ids, 200.0);
        $this->reverser($ids, 120.0);

        self::assertEqualsWithDelta(
            120.0,
            $this->payee($ids),
            0.01,
            'Additionner les deux sources compterait le même euro deux fois.',
        );
    }

    // ===================== Un reversement d'agent n'est pas celui d'un partenaire =====

    /**
     * LE VERSEMENT D'UN AGENT NE SOLDE PAS LA DETTE D'UN PARTENAIRE.
     *
     * Les deux familles partagent la table ; elles ne partagent ni la dette, ni le compte
     * comptable (6611 contre 632). Confondre les deux ferait apparaître comme payée une
     * rétro que le partenaire attend toujours.
     */
    public function testUnVersementDAgentNeCompteJamaisPourUnPartenaire(): void
    {
        $ids = $this->semer();
        $em = $this->em();

        $r = (new ReversementRetroAgent())
            ->setAgent($em->getRepository(Invite::class)->find($ids['inviteId']))
            ->setTranche($em->getRepository(Tranche::class)->find($ids['trancheId']))
            ->setMontant(500.0)->setPaidAt(new \DateTimeImmutable('now'))->setReference('VIR-AGENT');
        $r->setEntreprise($em->getRepository(Entreprise::class)->find($ids['entrepriseId']))
            ->setInvite($em->getRepository(Invite::class)->find($ids['inviteId']));
        $em->persist($r);
        $em->flush();
        $em->clear();

        self::assertEqualsWithDelta(0.0, $this->payee($ids), 0.01);
    }

    /**
     * ET LA RÉCIPROQUE, qui manquait : UN VERSEMENT DE PARTENAIRE NE COMPTE PAS POUR LES AGENTS.
     *
     * La garde n'existait que d'un côté. `getTrancheMontantRetroAgentReversee()` et sa
     * jumelle par avenant filtraient sur l'agent CIBLE — donc, quand aucun agent n'était
     * ciblé (toutes les vues du cabinet : la fiche d'une échéance, la liste, les agrégats),
     * elles additionnaient AUSSI les reversements de partenaires. Le versé des agents
     * paraissait plus élevé qu'il ne l'était, leur solde plus bas, et rien ne le disait.
     */
    public function testUnVersementDePartenaireNeCompteJamaisPourLesAgents(): void
    {
        $ids = $this->semer();
        $this->reverser($ids, 400.0);

        $em = $this->em();
        $tranche = $em->getRepository(Tranche::class)->find($ids['trancheId']);
        $avenant = $em->getRepository(Avenant::class)->find($ids['avenantId']);

        // Sans agent ciblé — le cas de toutes les vues du cabinet.
        self::assertEqualsWithDelta(
            0.0,
            $this->helper()->getTrancheMontantRetroAgentReversee($tranche),
            0.01,
            'Le versé des AGENTS ne doit rien devoir à un versement de partenaire.',
        );
        self::assertEqualsWithDelta(
            0.0,
            $this->helper()->getAvenantMontantRetroAgentReversee($avenant),
            0.01,
        );

        // Et le partenaire, lui, le voit bien : la garde n'a pas éteint la bonne lecture.
        self::assertEqualsWithDelta(400.0, $this->payee($ids), 0.01);
    }
}