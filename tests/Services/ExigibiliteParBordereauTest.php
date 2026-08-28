<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Partenaire;
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
 * L'ARGENT RENTRÉ PAR UN BORDEREAU REND LA RÉTRO EXIGIBLE — AUSSI POUR LE PARTENAIRE.
 *
 * ── LE DÉFAUT QUE CE TEST FERME ─────────────────────────────────────────────────────
 * Deux mesures de « l'encaissé » coexistaient et ne comptaient pas la même chose :
 *
 *   — la colonne « Encaissée » de l'écran, et l'exigibilité de l'AGENT, prennent
 *     `max(articles, allouée)` — ce qu'un bordereau a réellement fait rentrer SUR CETTE
 *     ÉCHÉANCE, imputé de la plus ancienne à la plus récente ;
 *   — l'exigibilité du PARTENAIRE ne lisait que les ARTICLES de notes, avec pour seul
 *     repli un test au niveau de l'AVENANT (« son bordereau est-il intégralement soldé ? »).
 *
 * Conséquence, constatée à l'écran : une échéance soldée par un bordereau PARTIELLEMENT
 * réglé affichait « Encaissée 141,71 · Solde 0,00 », l'agent devenait exigible, et le
 * partenaire non. Le courtier ne payait pas quelqu'un qu'il devait payer, et rien ne le
 * signalait — ni erreur, ni avertissement : un zéro d'apparence normale.
 *
 * ── CE QUE LA FIXTURE REPRODUIT ─────────────────────────────────────────────────────
 * Un bordereau réclamant 200, réglé à 100 seulement. L'imputation la plus ancienne d'abord
 * couvre donc INTÉGRALEMENT la première échéance, et rien de la seconde. Le bordereau,
 * lui, n'est pas soldé : l'ancien repli ne pouvait pas jouer.
 */
class ExigibiliteParBordereauTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-exigibilite-bordereau@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Exigibilite Bordereau SARL';

    /** Commission totale de l'affaire, partagée en deux échéances de 50 %. */
    private const COMMISSION = 200.0;
    private const TAUX_PARTENAIRE = 30.0; // POINTS
    private const TAUX_AGENT = 20.0;      // POINTS

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
        $conn->executeStatement(
            'DELETE p FROM paiement p JOIN note n ON p.note_id = n.id
             JOIN entreprise e ON n.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach ([
            'note', 'bordereau', 'condition_partage', 'avenant', 'tranche',
            'revenu_pour_courtier', 'type_revenu', 'chargement_pour_prime',
            'cotation', 'piste', 'client', 'partenaire', 'risque', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    private function strategie(): TrancheIndicatorStrategy
    {
        return static::getContainer()->get(TrancheIndicatorStrategy::class);
    }

    private function tranche(int $id): Tranche
    {
        return $this->em()->getRepository(Tranche::class)->find($id);
    }

    /**
     * LA PREMIÈRE ÉCHÉANCE EST SOLDÉE PAR L'IMPUTATION : SA RÉTRO PARTENAIRE EST DUE.
     *
     * C'est le défaut, pris sur le fait. Avant le correctif, cette valeur était 0,00 —
     * alors que l'écran affichait, sur la même ligne, une commission encaissée et un solde
     * nul.
     */
    public function testLaRetroDuPartenaireDevientExigibleQuandLImputationSoldeLEcheance(): void
    {
        $ids = $this->semer();

        $encaissee = round(
            (float) $this->strategie()->calculate($this->tranche($ids['t1']))['montant_paye'],
            2,
        );
        self::assertGreaterThan(
            0.0,
            $encaissee,
            'La fixture doit reproduire une échéance encaissée par imputation de bordereau.',
        );

        $exigible = round(
            (float) $this->strategie()->calculate($this->tranche($ids['t1']))['retroCommissionExigible'],
            2,
        );
        self::assertGreaterThan(
            0.0,
            $exigible,
            'L’argent est rentré sur cette échéance : la rétro du partenaire DOIT être exigible.',
        );
    }

    /**
     * LES DEUX FAMILLES DISENT LA MÊME CHOSE SUR LA MÊME ÉCHÉANCE.
     *
     * C'est l'invariant qui manquait : l'agent devenait exigible là où le partenaire ne
     * l'était pas, sur exactement le même argent. Les montants diffèrent — les assiettes
     * ne sont pas les mêmes —, mais leur NAISSANCE est simultanée.
     */
    public function testLesDeuxFamillesDeviennentExigiblesEnsemble(): void
    {
        $ids = $this->semer();
        $indicateurs = $this->strategie()->calculate($this->tranche($ids['t1']));

        self::assertGreaterThan(0.0, round((float) $indicateurs['retroAgentExigible'], 2));
        self::assertGreaterThan(0.0, round((float) $indicateurs['retroCommissionExigible'], 2));
    }

    /**
     * ET L'ÉCHÉANCE QUE L'IMPUTATION N'A PAS ATTEINTE RESTE INEXIGIBLE.
     *
     * Sans cette moitié, le correctif pourrait n'être qu'un « tout devient exigible » —
     * ce qui ferait payer une dette qui n'est pas née. Le règlement s'arrête à la première
     * échéance : la seconde attend.
     */
    public function testLEcheanceNonAtteinteResteInexigible(): void
    {
        $ids = $this->semer();
        $indicateurs = $this->strategie()->calculate($this->tranche($ids['t2']));

        self::assertSame(0.0, round((float) $indicateurs['retroCommissionExigible'], 2));
        self::assertSame(0.0, round((float) $indicateurs['retroAgentExigible'], 2));
    }

    /**
     * Un bordereau réclamant 200 et réglé à 100 : l'imputation, de la plus ancienne à la
     * plus récente, solde la première échéance et n'atteint pas la seconde. Le bordereau
     * n'étant PAS soldé, l'ancien repli au niveau de l'avenant ne pouvait pas jouer.
     *
     * @return array{t1: int, t2: int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Exigibilite BRD')
            ->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')
            ->setAdresse('1 rue')->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')
            ->setUtilisateur($owner);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Propriétaire')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($invite);

        $agent = (new Invite())->setNom('Alice Apporteuse')->setProprietaire(false);
        $agent->setEntreprise($entreprise);
        $em->persist($agent);

        $risque = (new Risque())->setCode('BRD')->setNomComplet('Risque bordereau')->setDescription('BRD')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(false);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $partenaire = (new Partenaire())->setNom('SUNU Bordereau')->setPart(self::TAUX_PARTENAIRE);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $client = (new Client())->setNom('Client bordereau')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Affaire bordereau')->setTypeAvenant(0)
            ->setDescriptionDuRisque('BRD')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $piste->setPartenaire($partenaire);
        $em->persist($piste);

        // Une condition d'AGENT rattachée : les deux familles doivent naître ensemble.
        $conditionAgent = (new ConditionPartage())->setNom('Effort Alice')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(self::TAUX_AGENT)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($agent);
        $conditionAgent->setEntreprise($entreprise);
        $em->persist($conditionAgent);
        $piste->addConditionsPartageAgent($conditionAgent);

        $cotation = (new Cotation())->setNom('Cotation bordereau')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($entreprise);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime')->setMontantFlatExceptionel(2000.0);
        $chargement->setEntreprise($entreprise);
        $cotation->addChargement($chargement);
        $em->persist($chargement);

        // PARTAGEABLE : sans quoi le partenaire n'a aucune assiette et le test ne dirait rien.
        $typeRevenu = (new TypeRevenu())->setNom('Commission')->setMontantflat(self::COMMISSION)
            ->setShared(true)->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())->setNom('Revenu')->setTypeRevenu($typeRevenu);
        $revenu->setEntreprise($entreprise);
        // addRevenu() et non setCotation() seul : sinon la commission calculée retombe à 0.
        $cotation->addRevenu($revenu);
        $em->persist($revenu);

        $faireTranche = static function (string $nom, string $payable) use ($em, $entreprise, $cotation): Tranche {
            $t = (new Tranche())->setNom($nom)->setPourcentage(50.0)
                ->setPayableAt(new \DateTimeImmutable($payable))
                ->setEcheanceAt(new \DateTimeImmutable($payable));
            $t->setCotation($cotation)->setEntreprise($entreprise);
            $em->persist($t);

            return $t;
        };
        $t1 = $faireTranche('1re échéance', '-60 days');
        $t2 = $faireTranche('2e échéance', '-10 days');

        $avenant = (new Avenant())->setReferencePolice('POL-BRD')->setNumero('0')->setDescription('Police')
            ->setStartingAt(new \DateTimeImmutable('-60 days'))
            ->setEndingAt(new \DateTimeImmutable('+305 days'));
        $avenant->setEntreprise($entreprise)->setInvite($invite);
        // addAvenant() et non setCotation() seul : sinon la couverture bordereau ne voit
        // jamais l'avenant.
        $cotation->addAvenant($avenant);
        $em->persist($avenant);
        $em->flush();

        $bordereau = (new Bordereau())
            ->setType(Bordereau::TYPE_BOREDERAU_PRODUCTION)
            ->setNom('Bordereau partiel')->setReference('BRD-PARTIEL')
            ->setReceivedAt(new \DateTimeImmutable('-15 days'))
            ->setPeriodeDebut(new \DateTimeImmutable('-45 days'))
            ->setPeriodeFin(new \DateTimeImmutable('-15 days'))
            ->setMontantComHtPayableNow(self::COMMISSION)
            ->setMontantTaxePayableNow(0.0)
            ->setAnalysisResults([
                ['type' => 'match', 'row_index' => 0, 'reference_police' => 'POL-BRD', 'avenant_id' => $avenant->getId()],
            ]);
        $bordereau->setInvite($invite)->setEntreprise($entreprise);
        $em->persist($bordereau);

        $note = (new Note())->setNom('Note bordereau')->setReference('NOTE-BRD')
            ->setType(Note::TYPE_NOTE_DE_DEBIT)->setAddressedTo(Note::TO_ASSUREUR)
            ->setValidated(true)->setSignature('sig');
        $note->setEntreprise($entreprise)->setInvite($invite);
        // addNote() et non setBordereau() seul : sinon l'encaissement du bordereau est nul.
        $bordereau->addNote($note);
        $em->persist($note);

        // LA MOITIÉ SEULEMENT. Le bordereau n'est donc PAS soldé — l'ancien repli, qui
        // raisonnait au niveau de l'avenant, ne pouvait pas jouer — mais l'imputation, elle,
        // couvre intégralement la première échéance.
        $paiement = (new Paiement())->setMontant(self::COMMISSION / 2)
            ->setPaidAt(new \DateTimeImmutable('-1 day'))->setReference('PAY-BRD');
        $paiement->setEntreprise($entreprise);
        $note->addPaiement($paiement);
        $em->persist($paiement);

        $em->flush();
        $ids = ['t1' => (int) $t1->getId(), 't2' => (int) $t2->getId()];
        $em->clear();

        return $ids;
    }
}
