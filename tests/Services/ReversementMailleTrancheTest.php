<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use App\Repository\ReversementRetroAgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA MAILLE D'UN REVERSEMENT : LA TRANCHE.
 *
 * La prime ET la commission se paient par tranche. C'est donc à ce rythme que
 * l'intermédiaire — agent interne ou partenaire externe — est rémunéré, et c'est à cette
 * maille que le règlement doit s'enregistrer.
 *
 * Jusqu'ici le versé était accroché à l'AVENANT tandis que le dû était déjà proratisé par
 * tranche (`TrancheIndicatorStrategy::retroAgentDue`) : dû et payé ne se comparaient jamais
 * à la même maille, et la colonne « rétro reversée » d'une tranche était indérivable. Un
 * commentaire du code défendait même cette situation — « un reversement est un fait rattaché
 * à un AVENANT, pas une grandeur qu'on découpe ».
 *
 * ── CE QUE CE TEST TIENT, ET CE QU'IL NE TIENT PAS ──────────────────────────────────
 *
 * Il tient l'ENREGISTREMENT et la LECTURE de la nouvelle maille, sans rien changer d'autre :
 * les deux liens coexistent, `tranche` dit QUAND et `avenant` dit SUR QUOI. Aucune règle
 * d'exigibilité, aucune source de montant n'est touchée ici.
 *
 * Il tient AUSSI le piège que ce changement ouvre : `avenant` étant devenu nullable, toute
 * jointure INTERNE sur lui écarterait silencieusement une ligne — donc, pour la génération
 * des écritures comptables, un décaissement réel sans écriture. C'est le genre de perte
 * qu'aucune erreur ne signale.
 */
class ReversementMailleTrancheTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-maille-tranche@test.local';
    private const ENT = 'PHPUnit Maille Tranche SARL';

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

    private function depot(): ReversementRetroAgentRepository
    {
        return static::getContainer()->get(ReversementRetroAgentRepository::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        foreach (['reversement_retro_agent', 'tranche', 'avenant', 'cotation', 'piste', 'client', 'risque', 'invite'] as $table) {
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
     * Une affaire, DEUX tranches, un agent. De quoi distinguer une maille d'une autre —
     * une seule tranche ne prouverait rien.
     *
     * @return array{agent: Invite, avenant: Avenant, t1: Tranche, t2: Tranche, entreprise: Entreprise}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Maille')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $proprietaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $proprietaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($proprietaire);

        $agent = (new Invite())->setNom('Alice')->setProprietaire(false);
        $agent->setEntreprise($ent);
        $em->persist($agent);

        $risque = (new Risque())->setCode('MAI')->setNomComplet('Risque maille')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($ent);
        $em->persist($risque);

        $client = (new Client())->setNom('Client maille')->setExonere(false);
        $client->setEntreprise($ent);
        $em->persist($client);

        $piste = (new Piste())->setNom('Affaire maille')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))->setClient($client)->setRisque($risque);
        $piste->setEntreprise($ent)->setInvite($proprietaire);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation maille')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($ent);
        $em->persist($cotation);

        $avenant = (new Avenant())->setReferencePolice('POL-MAILLE')->setNumero('0')->setDescription('Police')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($ent)->setInvite($proprietaire);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $faireTranche = function (string $nom, float $part) use ($em, $ent, $cotation): Tranche {
            $t = (new Tranche())->setNom($nom)->setPourcentage($part)
                ->setPayableAt(new \DateTimeImmutable('+30 days'))
                ->setEcheanceAt(new \DateTimeImmutable('+30 days'));
            $t->setCotation($cotation)->setEntreprise($ent);
            $em->persist($t);

            return $t;
        };

        $t1 = $faireTranche('1re échéance', 60.0);
        $t2 = $faireTranche('2e échéance', 40.0);

        $em->flush();

        return ['agent' => $agent, 'avenant' => $avenant, 't1' => $t1, 't2' => $t2, 'entreprise' => $ent];
    }

    private function verser(array $s, ?Tranche $tranche, ?Avenant $avenant, float $montant, string $ref): ReversementRetroAgent
    {
        $r = (new ReversementRetroAgent())->setAgent($s['agent'])->setMontant($montant)
            ->setPaidAt(new \DateTimeImmutable('now'))->setReference($ref);
        $r->setTranche($tranche)->setAvenant($avenant);
        $r->setEntreprise($s['entreprise'])->setInvite($s['agent']);
        $this->em()->persist($r);
        $this->em()->flush();

        return $r;
    }

    // ===================== 1. Les deux liens =====================

    /** La tranche dit QUAND, l'avenant dit SUR QUOI. Un reversement porte les deux. */
    public function testUnReversementPorteSaTrancheEtSonAffaire(): void
    {
        $s = $this->semer();
        $r = $this->verser($s, $s['t1'], $s['avenant'], 120.0, 'VIR-1');

        self::assertSame($s['t1']->getId(), $r->getTranche()?->getId());
        self::assertSame($s['avenant']->getId(), $r->getAvenant()?->getId());
    }

    /**
     * L'INVARIANT : la tranche et l'avenant relèvent de la MÊME cotation.
     *
     * Tranche et Avenant sont tous deux enfants de Cotation — rien dans le schéma
     * n'empêche de les prendre dans deux affaires différentes. Le versement porterait
     * alors sur une affaire et s'imputerait à l'échéance d'une autre.
     */
    public function testLaTrancheEtLAffaireRelevantDeLaMemeCotation(): void
    {
        $s = $this->semer();
        $r = $this->verser($s, $s['t1'], $s['avenant'], 120.0, 'VIR-1');

        self::assertSame(
            $r->getTranche()?->getCotation()?->getId(),
            $r->getAvenant()?->getCotation()?->getId(),
            'Une tranche et un avenant de deux affaires différentes rendraient le versement incohérent.',
        );
    }

    /** La cotation se lit quelle que soit la maille renseignée — un seul point d'accès. */
    public function testLaCotationSeLitDepuisLaTrancheOuDepuisLAffaire(): void
    {
        $s = $this->semer();
        $cotationId = $s['avenant']->getCotation()?->getId();

        $parTranche = $this->verser($s, $s['t1'], null, 50.0, 'VIR-T');
        $parAvenant = $this->verser($s, null, $s['avenant'], 50.0, 'VIR-A');

        self::assertSame($cotationId, $parTranche->getCotation()?->getId());
        self::assertSame($cotationId, $parAvenant->getCotation()?->getId(), 'Le repli sur l’avenant doit tenir.');
    }

    // ===================== 2. La lecture par tranche =====================

    /** Chaque tranche reçoit ce qui lui a été versé, et rien de la voisine. */
    public function testLeVerseSeLitTrancheParTranche(): void
    {
        $s = $this->semer();
        $this->verser($s, $s['t1'], $s['avenant'], 120.0, 'VIR-1');
        $this->verser($s, $s['t1'], $s['avenant'], 30.0, 'VIR-2');
        $this->verser($s, $s['t2'], $s['avenant'], 80.0, 'VIR-3');

        $totaux = $this->depot()->totauxParTranche($s['agent'], [$s['t1'], $s['t2']]);

        self::assertEqualsWithDelta(150.0, $totaux[$s['t1']->getId()] ?? 0.0, 0.01);
        self::assertEqualsWithDelta(80.0, $totaux[$s['t2']->getId()] ?? 0.0, 0.01);
    }

    /**
     * LES DEUX LECTURES VOIENT LE MÊME ARGENT SANS LE COMPTER DEUX FOIS.
     *
     * `totauxParTranche` s'ajoute à `totauxParAvenant` sans le remplacer : le rapport de
     * production raisonne par affaire et continue de s'appuyer sur la seconde.
     */
    public function testLesDeuxLecturesNeSeContredisentPas(): void
    {
        $s = $this->semer();
        $this->verser($s, $s['t1'], $s['avenant'], 120.0, 'VIR-1');
        $this->verser($s, $s['t2'], $s['avenant'], 80.0, 'VIR-2');

        $parTranche = $this->depot()->totauxParTranche($s['agent'], [$s['t1'], $s['t2']]);
        $parAvenant = $this->depot()->totauxParAvenant($s['agent'], [$s['avenant']]);

        self::assertEqualsWithDelta(200.0, array_sum($parTranche), 0.01);
        self::assertEqualsWithDelta(200.0, $parAvenant[$s['avenant']->getId()] ?? 0.0, 0.01);
    }

    /**
     * Une ligne ANTÉRIEURE à ce lot n'a pas de tranche : elle ne fausse pas la lecture par
     * tranche, et reste comptée par son affaire. C'était déjà le cas — le versé n'était
     * alors attribuable à aucune tranche.
     */
    public function testUneLigneSansTrancheResteCompteeParSonAffaire(): void
    {
        $s = $this->semer();
        $this->verser($s, null, $s['avenant'], 90.0, 'VIR-LEGACY');

        self::assertSame([], $this->depot()->totauxParTranche($s['agent'], [$s['t1'], $s['t2']]));
        self::assertEqualsWithDelta(
            90.0,
            $this->depot()->totauxParAvenant($s['agent'], [$s['avenant']])[$s['avenant']->getId()] ?? 0.0,
            0.01,
        );
    }

    // ===================== 3. Le piège de l'avenant nullable =====================

    /**
     * UNE LIGNE SANS AVENANT NE DISPARAÎT PAS DE LA COMPTABILITÉ.
     *
     * `avenant` est devenu nullable. Les lectures qui le joignaient en jointure INTERNE
     * écarteraient donc cette ligne sans rien dire — et `findChronologiqueForEntreprise`
     * alimente la génération des écritures : un décaissement réel se retrouverait sans
     * écriture comptable, ce qu'aucune erreur ne signalerait.
     */
    public function testUneLigneSansAffaireResteVisibleDeLaComptabilite(): void
    {
        $s = $this->semer();
        $this->verser($s, $s['t1'], null, 75.0, 'VIR-SANS-AFFAIRE');

        $lignes = $this->depot()->findChronologiqueForEntreprise((int) $s['entreprise']->getId());

        self::assertCount(1, $lignes, 'La ligne sans avenant doit être rendue, pas écartée en silence.');
        self::assertEqualsWithDelta(75.0, (float) $lignes[0]->getMontant(), 0.01);
    }

    /** Et elle reste visible de la liste des versements de l'agent, pour la même raison. */
    public function testUneLigneSansAffaireResteVisibleDeSonAgent(): void
    {
        $s = $this->semer();
        $this->verser($s, $s['t1'], null, 75.0, 'VIR-SANS-AFFAIRE');

        self::assertCount(1, $this->depot()->findPourAgent($s['agent'], $s['entreprise']));
    }
}
