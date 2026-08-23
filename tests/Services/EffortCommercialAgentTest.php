<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use App\Service\Partage\EffortCommercialAgent;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * « CETTE AFFAIRE EST-ELLE L'EFFORT D'UN AGENT, ET PEUT-ON Y TOUCHER ? »
 *
 * Le rattachement d'une condition d'agent peut désormais s'ordonner depuis un avenant, une
 * tranche ou une proposition — mais il s'écrit toujours sur la PISTE. Quatre propriétés
 * gouvernent ce déplacement, et aucune n'est évidente :
 *
 *  1. LA REMONTÉE D'ARBRE. Un seul endroit sait aller de n'importe quel objet à son
 *     affaire ; s'il se trompait, on écrirait la condition sur la mauvaise piste.
 *  2. LES DEUX CANAUX. Une condition d'agent peut être RÉUTILISABLE (collection partagée)
 *     ou EXCEPTIONNELLE (propre à la piste). N'en lire qu'une laisserait rattacher un
 *     second bénéficiaire à une affaire qui n'en veut qu'un.
 *  3. UNE AFFAIRE, UN AGENT. Le refus doit NOMMER l'affaire et l'agent en place, sinon
 *     l'utilisateur ne sait pas quoi détacher.
 *  4. UN VERSEMENT SCELLE. Après un virement, plus de détachement — donc plus de
 *     changement d'agent. Le refus doit dire combien a déjà été reçu : « opération
 *     impossible » laisse chercher.
 */
class EffortCommercialAgentTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-effort-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Effort SARL';

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

    private function service(): EffortCommercialAgent
    {
        return static::getContainer()->get(EffortCommercialAgent::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement(
            'DELETE pcp FROM piste_condition_partage pcp JOIN piste p ON pcp.piste_id = p.id
             JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach ([
            'reversement_retro_agent', 'condition_partage', 'avenant', 'tranche',
            'cotation', 'piste', 'client', 'risque', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    /**
     * Une affaire complète, et son arbre : piste → cotation → avenant, plus une tranche.
     *
     * @return array{piste: Piste, cotation: Cotation, avenant: Avenant, tranche: Tranche, agent: Invite, entreprise: Entreprise}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Effort Owner')
            ->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $ent->setUtilisateur($owner);
        $em->persist($ent);

        $proprietaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $proprietaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($proprietaire);

        $agent = (new Invite())->setNom('Alice')->setProprietaire(false);
        $agent->setEntreprise($ent);
        $em->persist($agent);

        $risque = (new Risque())->setCode('EFF')->setNomComplet('Risque effort')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($ent);
        $em->persist($risque);

        $client = (new Client())->setNom('Client Effort')->setExonere(false);
        $client->setEntreprise($ent);
        $em->persist($client);

        $piste = (new Piste())->setNom('Affaire Effort')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($ent)->setInvite($proprietaire);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation Effort')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($ent);
        $em->persist($cotation);

        $avenant = (new Avenant())->setReferencePolice('POL-EFF-1')->setNumero('0')
            ->setDescription('Police Effort')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))
            ->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($ent)->setInvite($proprietaire);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $tranche = (new Tranche())->setNom('Tranche Effort')->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('-10 days'))
            ->setEcheanceAt(new \DateTimeImmutable('+20 days'));
        $tranche->setCotation($cotation)->setEntreprise($ent);
        $em->persist($tranche);

        $em->flush();

        return [
            'piste' => $piste,
            'cotation' => $cotation,
            'avenant' => $avenant,
            'tranche' => $tranche,
            'agent' => $agent,
            'entreprise' => $ent,
        ];
    }

    /** Une condition d'agent, rattachée par le canal demandé. */
    private function conditionPour(Invite $agent, Entreprise $ent, Piste $piste, string $canal): ConditionPartage
    {
        $condition = (new ConditionPartage())->setNom('Prime ' . $agent->getNom())
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)->setTaux(10.0)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)->setAgent($agent);
        $condition->setEntreprise($ent);
        $this->em()->persist($condition);

        if ($canal === 'partagee') {
            $piste->addConditionsPartageAgent($condition);
        } else {
            $piste->addConditionsPartageExceptionnelle($condition);
        }
        $this->em()->flush();

        return $condition;
    }

    // ===================== 1. La remontée d'arbre =====================

    /**
     * DEPUIS LES QUATRE ÉCRANS, LA MÊME AFFAIRE.
     *
     * C'est la propriété qui rend le lot possible : on ordonne depuis où l'on travaille, on
     * écrit là où le métier l'exige. Une erreur ici poserait la condition sur la mauvaise
     * affaire — et personne ne le verrait avant que l'agent réclame sa part.
     */
    public function testLArbreRemonteToujoursALaMemeAffaire(): void
    {
        $s = $this->semer();
        $service = $this->service();
        $attendu = $s['piste']->getId();

        foreach (['piste', 'cotation', 'avenant', 'tranche'] as $depuis) {
            self::assertSame(
                $attendu,
                $service->piste($s[$depuis])?->getId(),
                "Depuis {$depuis}, on doit retrouver la même affaire.",
            );
        }
    }

    /** Un objet qu'on n'a pas nommé ne remonte à rien : on ne devine pas un arbre. */
    public function testUnObjetInconnuNeRemonteARien(): void
    {
        $s = $this->semer();

        self::assertNull($this->service()->piste($s['agent']), 'Un invité n\'est pas dans l\'arbre d\'une affaire.');
        self::assertNull($this->service()->piste(null));
    }

    // ===================== 2. Les deux canaux =====================

    /**
     * LES DEUX CANAUX COMPTENT, ET C'EST TOUT L'ENJEU DU GATING.
     *
     * Une condition créée dans les « conditions spéciales » d'une piste lie un agent à cette
     * affaire aussi sûrement qu'une condition réutilisable. Ne lire que la collection
     * partagée laisserait rattacher un second bénéficiaire à une affaire déjà prise.
     */
    public function testLesDeuxCanauxRendentLAffairePrise(): void
    {
        foreach (['partagee', 'exceptionnelle'] as $canal) {
            $s = $this->semer();
            $this->conditionPour($s['agent'], $s['entreprise'], $s['piste'], $canal);

            $service = $this->service();
            self::assertNotNull($service->condition($s['piste']), "Canal {$canal} : la condition doit être vue.");
            self::assertSame('Alice', $service->agent($s['piste'])?->getNom());
            self::assertSame('Effort commercial : Alice', $service->libelle($s['piste']));
            self::assertNotNull(
                $service->refusDeRattachement($s['piste']),
                "Canal {$canal} : un second rattachement doit être refusé.",
            );

            $this->cleanUp();
        }
    }

    /** Sans condition, l'affaire est celle du cabinet : rien à dire sur la ligne. */
    public function testSansConditionLaLigneResteMuette(): void
    {
        $s = $this->semer();

        self::assertNull($this->service()->condition($s['piste']));
        self::assertNull($this->service()->agent($s['piste']));
        self::assertNull($this->service()->libelle($s['piste']), 'Le cas normal ne doit pas faire de bruit.');
        self::assertNull($this->service()->refusDeRattachement($s['piste']), 'Rien n\'empêche de rattacher.');
    }

    // ===================== 3. Une affaire, un agent =====================

    /** Le refus NOMME l'affaire, l'agent en place et la condition : sinon on ne sait rien détacher. */
    public function testLeRefusDeRattachementNommeCeQuiBloque(): void
    {
        $s = $this->semer();
        $this->conditionPour($s['agent'], $s['entreprise'], $s['piste'], 'partagee');

        $motif = $this->service()->refusDeRattachement($s['piste']);

        self::assertNotNull($motif);
        self::assertStringContainsString('Affaire Effort', $motif);
        self::assertStringContainsString('Alice', $motif);
        self::assertStringContainsString('Prime Alice', $motif);
        self::assertStringContainsString('détachez', mb_strtolower($motif, 'UTF-8'));
    }

    /**
     * LE LOT EST TOUT OU RIEN, et le refus nomme les fautifs.
     *
     * Appliquer le reste serait pire : l'utilisateur croirait avoir tout couvert, et
     * l'affaire oubliée ne se signalerait jamais.
     */
    public function testUnLotEstRefuseEnEntierDesQuUneAffaireEstPrise(): void
    {
        $s = $this->semer();
        $libre = (new Piste())->setNom('Affaire Libre')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))
            ->setClient($s['piste']->getClient())->setRisque($s['piste']->getRisque());
        $libre->setEntreprise($s['entreprise'])->setInvite($s['piste']->getInvite());
        $this->em()->persist($libre);
        $this->em()->flush();

        // Deux libres : rien ne s'oppose.
        self::assertNull($this->service()->refusDuLot([$s['piste'], $libre]));

        // L'une des deux devient prise : le lot entier tombe, et elle est nommée.
        $this->conditionPour($s['agent'], $s['entreprise'], $s['piste'], 'partagee');
        $motif = $this->service()->refusDuLot([$s['piste'], $libre]);

        self::assertNotNull($motif);
        self::assertStringContainsString('Affaire Effort', $motif);
        self::assertStringNotContainsString('Affaire Libre', $motif, 'Seules les affaires PRISES sont nommées.');
        self::assertStringContainsString('Rien n\'a été rattaché', $motif);
    }

    /** Un lot vide n'est pas un lot valide : la sélection n'a rien donné, et on le dit. */
    public function testUnLotVideEstRefuse(): void
    {
        self::assertNotNull($this->service()->refusDuLot([]));
    }

    // ===================== 4. Un versement scelle l'affaire =====================

    /** Tant que rien n'est versé, le détachement est libre. */
    public function testSansVersementLeDetachementEstPermis(): void
    {
        $s = $this->semer();
        $this->conditionPour($s['agent'], $s['entreprise'], $s['piste'], 'partagee');

        self::assertNull($this->service()->refusDeDetachement($s['piste']));
    }

    /**
     * APRÈS UN VERSEMENT, C'EST SCELLÉ — et le refus dit combien a été reçu.
     *
     * C'est la règle la plus coûteuse à découvrir sur le tard : elle ferme aussi le
     * changement d'agent, puisque changer suppose de détacher.
     */
    public function testApresUnVersementLeDetachementEstRefuseEnChiffres(): void
    {
        $s = $this->semer();
        $this->conditionPour($s['agent'], $s['entreprise'], $s['piste'], 'partagee');

        $reversement = (new ReversementRetroAgent())
            ->setAgent($s['agent'])->setAvenant($s['avenant'])->setMontant(154.19)
            ->setPaidAt(new \DateTimeImmutable('-2 days'))->setReference('VIR-EFF-1');
        $reversement->setEntreprise($s['entreprise'])->setInvite($s['piste']->getInvite());
        $this->em()->persist($reversement);
        $this->em()->flush();

        self::assertEqualsWithDelta(
            154.19,
            $this->service()->montantDejaReverse($s['piste'], $s['agent']),
            0.001,
            'Le total remonte par la cotation, sans charger les avenants.',
        );

        $motif = $this->service()->refusDeDetachement($s['piste']);
        self::assertNotNull($motif);
        self::assertStringContainsString('Alice', $motif);
        self::assertStringContainsString('154,19', $motif);
        self::assertStringContainsString('remplacé par un autre agent', $motif, 'Le refus doit fermer aussi le changement.');
    }

    /** Détacher ce qui n'existe pas se dit, plutôt que de réussir silencieusement. */
    public function testDetacherSansConditionEstRefuseEnLeDisant(): void
    {
        $s = $this->semer();

        $motif = $this->service()->refusDeDetachement($s['piste']);
        self::assertNotNull($motif);
        self::assertStringContainsString('aucun agent', $motif);
    }

    // ===================== 5. Le test croisé =====================

    /**
     * LE SERVICE ET LE DÉCOMPTE VOIENT LE MÊME RATTACHEMENT.
     *
     * Deux lectures des mêmes canaux finiraient par diverger : l'une gouvernerait le geste,
     * l'autre paierait — et un agent serait rattaché sans être payé, ou l'inverse. Ce test
     * les confronte sur les DEUX canaux.
     */
    public function testLeServiceEtLeDecompteNeDivergentPas(): void
    {
        foreach (['partagee', 'exceptionnelle'] as $canal) {
            $s = $this->semer();
            $this->conditionPour($s['agent'], $s['entreprise'], $s['piste'], $canal);

            $duService = $this->service()->condition($s['piste'])?->getId();

            /** @var IndicatorCalculationHelper $helper */
            $helper = static::getContainer()->get(IndicatorCalculationHelper::class);
            $duDecompte = $helper->getCotationConditionsAgent($s['cotation'], $s['agent']);

            self::assertCount(1, $duDecompte, "Canal {$canal} : le décompte doit voir la condition.");
            self::assertSame(
                $duService,
                reset($duDecompte)->getId(),
                "Canal {$canal} : les deux lectures doivent désigner la MÊME condition.",
            );

            $this->cleanUp();
        }
    }
}
