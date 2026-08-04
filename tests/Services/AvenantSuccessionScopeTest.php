<?php

namespace App\Tests\Services;

use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Services\AvenantRenouvellementResolver;
use App\Services\Canvas\Indicator\AvenantIndicatorStrategy;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\AvenantEcheanceScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UNE POLICE REPRISE SORT DU PIPELINE D'ÉCHÉANCE.
 *
 * La finalité du courtier est que l'assuré SOIT COUVERT. Quand une police échue a
 * été reprise par un avenant dérivé, elle l'est — sous le successeur. Réclamer son
 * renouvellement, c'est réclamer une action déjà faite.
 *
 * La règle vit en DEUX dialectes : PHP (AvenantRenouvellementResolver::estScellee,
 * qui sert le badge de ligne) et SQL (AvenantSuccessionScope::dqlSuccessionScellee,
 * qui sert le filtre et le comptage, où l'expression doit être en base sous peine
 * de fausser la pagination). Ces tests protègent, dans l'ordre :
 *
 *  1. l'ACCORD des deux dialectes : ils désignent exactement le même ensemble —
 *     c'est le seul garde-fou contre une divergence silencieuse ;
 *  2. la RÉCIPROQUE : un renouvellement amorcé SANS avenant reste visible, sinon on
 *     masquerait précisément le travail qui reste à faire ;
 *  3. le PIÈGE NULL : une police sans aucune piste dérivée reste dans sa fenêtre
 *     (un « NOT IN » sur une colonne nulle aurait vidé la rubrique en silence) ;
 *  4. le comptage filtre COMME la liste, sans quoi la pagination ment.
 */
class AvenantSuccessionScopeTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-avsuccession-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit AvSuccession SARL';

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

    private function resolver(): AvenantRenouvellementResolver
    {
        return static::getContainer()->get(AvenantRenouvellementResolver::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $nom = self::ENTREPRISE_NOM;

        // Le double lien Avenant ↔ Piste forme un cycle de FK : dissocier avant de supprimer.
        $conn->executeStatement('UPDATE avenant a JOIN entreprise e ON a.entreprise_id = e.id SET a.piste_de_renouvellement_id = NULL WHERE e.nom = :n', ['n' => $nom]);
        $conn->executeStatement('UPDATE piste p JOIN entreprise e ON p.entreprise_id = e.id SET p.avenant_de_base_id = NULL WHERE e.nom = :n', ['n' => $nom]);

        foreach (['avenant', 'cotation', 'piste', 'assureur', 'client', 'risque', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => $nom]
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => $nom]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    // ------------------------------------------------------------------ fixtures

    private Entreprise $entreprise;
    private Invite $invite;
    private Assureur $assureur;
    private Risque $risque;

    /**
     * Sept polices, une par SORT possible. Toutes échues sauf « anticipee », qui teste
     * le renouvellement préparé EN AVANCE — le cas vertueux qu'il serait absurde de
     * continuer à réclamer.
     *
     * @return array<string, int> nom du cas => id de l'avenant de base
     */
    private function seed(): array
    {
        $em = $this->em();

        $user = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit AvSuccession')->setVerified(true);
        $user->setPassword('irrelevant');
        $em->persist($user);

        $this->entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-SU')->setAdresse('1 rue des Suites')
            ->setTelephone('+243000000011')->setRccm('RCCM-SU')->setIdnat('IDNAT-SU')->setNumimpot('IMP-SU')
            ->setUtilisateur($user);
        $em->persist($this->entreprise);

        $this->invite = (new Invite())->setNom('Gestionnaire')->setUtilisateur($user)
            ->setEntreprise($this->entreprise)->setProprietaire(true);
        $em->persist($this->invite);

        $this->assureur = (new Assureur())->setNom('Assureur Succession')->setEmail('succ@assureur.test')
            ->setNumimpot('IMP-S')->setIdnat('IDNAT-S')->setRccm('RCCM-S');
        $this->assureur->setEntreprise($this->entreprise);
        $em->persist($this->assureur);

        $this->risque = (new Risque())->setNomComplet('Risque Succession')->setCode('SU-RQ')
            ->setDescription('Risque')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $this->risque->setEntreprise($this->entreprise);
        $this->risque->setInvite($this->invite);
        $em->persist($this->risque);

        $echu = new \DateTimeImmutable('-10 days');

        $renouvelee = $this->police('POL-RENOUVELEE', $echu);
        $this->deriver($renouvelee, Piste::AVENANT_RENOUVELLEMENT, avecAvenantIssu: true);

        $amorcee = $this->police('POL-AMORCEE', $echu);
        $this->deriver($amorcee, Piste::AVENANT_RENOUVELLEMENT, avecAvenantIssu: false);

        // Aucune piste dérivée : le cas du PIÈGE NULL.
        $sansSuite = $this->police('POL-SANS-SUITE', $echu);

        $resiliee = $this->police('POL-RESILIEE', $echu);
        $this->deriver($resiliee, Piste::AVENANT_RESILIATION, avecAvenantIssu: false);

        $prorogee = $this->police('POL-PROROGEE', $echu);
        $this->deriver($prorogee, Piste::AVENANT_PROROGATION, avecAvenantIssu: true);

        // Lien posé UNIQUEMENT côté piste : les deux sens doivent être lus.
        $lienMoitie = $this->police('POL-LIEN-MOITIE', $echu);
        $this->deriver($lienMoitie, Piste::AVENANT_RENOUVELLEMENT, avecAvenantIssu: true, lienSurAvenant: false);

        // Renouvellement ANTICIPÉ : échoit dans 10 jours, déjà repris.
        $anticipee = $this->police('POL-ANTICIPEE', new \DateTimeImmutable('+10 days'));
        $this->deriver($anticipee, Piste::AVENANT_RENOUVELLEMENT, avecAvenantIssu: true);

        // LE QUATRIÈME SORT — décision SANS mouvement ni successeur : le courtier a signalé
        // que la police n'aurait pas de suite. Aucune piste dérivée n'existe ici, ce qui en
        // fait aussi un second cas de PIÈGE NULL, du côté opposé.
        $marquee = $this->police('POL-MARQUEE', $echu);
        $marquee->setNonRenouvelable(true);
        $marquee->setNonRenouvelableMotif('Le client a vendu le véhicule.');
        $marquee->setNonRenouvelablePar($this->invite);

        // MARQUÉE EN PLEINE COUVERTURE : échoit dans 10 jours, mais on sait déjà qu'elle ne
        // sera pas reconduite. Le marquage n'attend pas l'échéance.
        $marqueeEnCours = $this->police('POL-MARQUEE-EN-COURS', new \DateTimeImmutable('+10 days'));
        $marqueeEnCours->setNonRenouvelable(true);
        $marqueeEnCours->setNonRenouvelableMotif('Le client cesse son activité en fin d’année.');
        $marqueeEnCours->setNonRenouvelablePar($this->invite);

        // MARQUAGE LEVÉ : la décision a été révisée, la police doit être redevenue une police
        // ORDINAIRE — donc réclamée comme n'importe quelle échue. La trace, elle, subsiste.
        $levee = $this->police('POL-LEVEE', $echu);
        $levee->setNonRenouvelable(true);
        $levee->setNonRenouvelableMotif('Le client annonçait son départ.');
        $levee->setNonRenouvelablePar($this->invite);
        $levee->setNonRenouvelable(false);

        $em->flush();

        return [
            'renouvelee'     => $renouvelee->getId(),
            'amorcee'        => $amorcee->getId(),
            'sansSuite'      => $sansSuite->getId(),
            'resiliee'       => $resiliee->getId(),
            'prorogee'       => $prorogee->getId(),
            'lienMoitie'     => $lienMoitie->getId(),
            'anticipee'      => $anticipee->getId(),
            'marquee'        => $marquee->getId(),
            'marqueeEnCours' => $marqueeEnCours->getId(),
            'levee'          => $levee->getId(),
        ];
    }

    /** Une police complète : client → piste → cotation → avenant. */
    private function police(string $ref, \DateTimeImmutable $endingAt): Avenant
    {
        $em = $this->em();

        $client = (new Client())->setNom('Client ' . $ref)->setExonere(false);
        $client->setEntreprise($this->entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste ' . $ref)->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Risque')->setExercice(2026)->setClient($client)->setRisque($this->risque);
        $piste->setEntreprise($this->entreprise)->setInvite($this->invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation ' . $ref)->setDuree(365)->setAssureur($this->assureur);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($this->entreprise);
        $em->persist($cotation);

        $avenant = (new Avenant())->setCotation($cotation)->setReferencePolice($ref)->setNumero('0')
            ->setDescription('Avenant ' . $ref)
            ->setStartingAt($endingAt->modify('-365 days'))->setEndingAt($endingAt);
        $avenant->setEntreprise($this->entreprise)->setInvite($this->invite);
        $em->persist($avenant);

        return $avenant;
    }

    /** Opportunité dérivée de la police, avec ou sans avenant issu. */
    private function deriver(
        Avenant $base,
        int $typeAvenant,
        bool $avecAvenantIssu,
        bool $lienSurAvenant = true,
    ): void {
        $em = $this->em();

        $derivee = (new Piste())->setNom('Mouvement ' . $base->getReferencePolice())
            ->setTypeAvenant($typeAvenant)->setDescriptionDuRisque('Risque')->setExercice(2026)
            ->setClient($base->getCotation()->getPiste()->getClient())->setRisque($this->risque);
        $derivee->setEntreprise($this->entreprise)->setInvite($this->invite);
        $derivee->setAvenantDeBase($base);
        $em->persist($derivee);
        if ($lienSurAvenant) {
            $base->setPisteDeRenouvellement($derivee);
        }

        if ($avecAvenantIssu) {
            $cotation = (new Cotation())->setNom('Cotation suite')->setDuree(365)->setAssureur($this->assureur);
            $cotation->setPiste($derivee);
            $cotation->setEntreprise($this->entreprise);
            $em->persist($cotation);

            $successeur = (new Avenant())->setCotation($cotation)
                ->setReferencePolice($base->getReferencePolice())->setNumero('1')
                ->setDescription('Successeur')
                ->setStartingAt(new \DateTimeImmutable('today'))
                ->setEndingAt(new \DateTimeImmutable('+1 year'));
            $successeur->setEntreprise($this->entreprise)->setInvite($this->invite);
            $em->persist($successeur);
        }
    }

    /** @return array<int, int> ids des avenants renvoyés par une fenêtre d'échéance. */
    private function fenetre(string $statut): array
    {
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($this->entreprise->getId());
        $resultat = $this->service()->search(
            Avenant::class,
            [AvenantEcheanceScope::CRITERION_KEY => $statut],
            $entreprise,
            null,
            1,
            100
        );
        $this->assertNull($resultat['status']['error'], 'La requête de fenêtre doit aboutir.');
        $this->assertSame(
            count($resultat['data']),
            $resultat['totalItems'],
            'Le COMPTAGE doit filtrer comme la LISTE, sinon la pagination ment.'
        );

        return array_map(static fn (Avenant $a) => $a->getId(), $resultat['data']);
    }

    // ------------------------------------------------------------------ tests

    /**
     * LE CAS DEMANDÉ. Ne restent dans « Échus » que les polices qui réclament encore
     * une action : renouvellement amorcé sans avenant, et aucune suite.
     */
    public function testSeulesLesPolicesQuiReclamentUneActionRestentDansLesEchus(): void
    {
        $s = $this->seed();

        $echus = $this->fenetre(AvenantEcheanceScope::STATUT_ECHUS);

        $this->assertEqualsCanonicalizing([$s['amorcee'], $s['sansSuite'], $s['levee']], $echus);
        $this->assertNotContains($s['renouvelee'], $echus, 'Reprise par un successeur : la couverture continue.');
        $this->assertNotContains($s['prorogee'], $echus, 'Prorogée : la couverture continue.');
        $this->assertNotContains($s['resiliee'], $echus, 'Résiliée : la décision est prise, rien à réclamer.');
        $this->assertNotContains($s['lienMoitie'], $echus, 'Lien à moitié posé : les DEUX sens sont lus.');
        $this->assertNotContains($s['marquee'], $echus, 'Signalée non renouvelable : le courtier a tranché.');
    }

    /**
     * LE QUATRIÈME SORT. Une police signalée non renouvelable quitte le pipeline sans
     * qu'aucune piste dérivée n'existe : la décision se lit sur la police elle-même.
     *
     * Et elle le quitte DÈS LE MARQUAGE, pas à l'échéance — c'est tout l'intérêt de pouvoir
     * le signaler en pleine couverture, dès que l'information arrive.
     */
    public function testUnePoliceSignaleeNonRenouvelableSortDuPipelineQuelleQueSoitSonEcheance(): void
    {
        $s = $this->seed();

        $this->assertNotContains($s['marquee'], $this->fenetre(AvenantEcheanceScope::STATUT_ECHUS));
        $this->assertNotContains(
            $s['marqueeEnCours'],
            $this->fenetre(AvenantEcheanceScope::STATUT_30J),
            'Marquée alors qu’elle couvre encore : elle sort aussi, sans attendre son terme.'
        );
    }

    /**
     * LE RETRAIT REND INTÉGRALEMENT LA POLICE AU PIPELINE — pendant exact du test
     * ci-dessus. Aucun code de restauration n'existe : le prédicat SQL ne lit que le
     * booléen. Ce test est ce qui prouve qu'il ne reste rien de coincé.
     *
     * Et la TRACE survit : effacer le motif à la levée supprimerait précisément ce que ce
     * dispositif existe pour garder.
     */
    public function testLeRetraitDuMarquageRendLaPoliceAuPipelineEtConserveLaTrace(): void
    {
        $s = $this->seed();
        $em = $this->em();

        $this->assertContains(
            $s['levee'],
            $this->fenetre(AvenantEcheanceScope::STATUT_ECHUS),
            'Marquage levé : la police est redevenue une échue ordinaire, à réclamer.'
        );

        $levee = $em->getRepository(Avenant::class)->find($s['levee']);
        $this->assertFalse($levee->isNonRenouvelable());
        $this->assertNotNull($levee->getNonRenouvelableLeveLe(), 'La levée est datée.');
        $this->assertSame('Le client annonçait son départ.', $levee->getNonRenouvelableMotif());
        $this->assertNotNull($levee->getNonRenouvelablePar(), 'L’auteur de la décision révisée reste connu.');
        $this->assertFalse($this->resolver()->estScellee($levee));

        // Re-marquer après une levée est un marquage NEUF : la date de levée est effacée.
        $levee->setNonRenouvelable(true);
        $this->assertNull($levee->getNonRenouvelableLeveLe());
    }

    /**
     * LE CINQUIÈME CHIP RASSEMBLE CE QUE LES QUATRE AUTRES ÉCARTENT.
     *
     * C'est le seul du lot qui INVERSE la règle du pipeline. Lui appliquer l'exclusion l'aurait
     * rendu vide par construction — et une page vide n'aurait dit à personne qu'il ne
     * s'agissait pas d'une absence de données. Ce test est la contrepartie exacte de
     * testUnePoliceSignaleeNonRenouvelableSortDuPipeline...
     */
    public function testLeChipNonRenouvelablesRassembleExactementLesPolicesMarquees(): void
    {
        $s = $this->seed();

        $marquees = $this->fenetre(AvenantEcheanceScope::STATUT_NON_RENOUVELABLES);

        // Les deux marquées, quelle que soit leur échéance : l'une est échue, l'autre couvre
        // encore pour 10 jours. Ce groupe ne borne AUCUNE date.
        $this->assertEqualsCanonicalizing([$s['marquee'], $s['marqueeEnCours']], $marquees);

        // Ni les échues ordinaires, ni les scellées par un successeur, ni celle dont le
        // marquage a été LEVÉ : le groupe suit le booléen, pas l'historique.
        $this->assertNotContains($s['amorcee'], $marquees);
        $this->assertNotContains($s['sansSuite'], $marquees);
        $this->assertNotContains($s['renouvelee'], $marquees);
        $this->assertNotContains($s['levee'], $marquees, 'Marquage levé : la police a quitté ce groupe.');
    }

    /**
     * LES CINQ CHIPS FORMENT UNE PARTITION SANS RECOUVREMENT NI TROU sur les polices dont on
     * a une échéance : chaque avenant apparaît dans EXACTEMENT un chip. Sans cette propriété,
     * un total lu dans un chip ne voudrait plus rien dire, et une police pourrait disparaître
     * de toutes les vues à la fois.
     */
    public function testChaqueAvenantApparaitDansExactementUnChip(): void
    {
        $s = $this->seed();

        $comptes = [];
        foreach ([
            AvenantEcheanceScope::STATUT_ECHUS,
            AvenantEcheanceScope::STATUT_30J,
            AvenantEcheanceScope::STATUT_31_60J,
            AvenantEcheanceScope::STATUT_60_PLUS,
            AvenantEcheanceScope::STATUT_NON_RENOUVELABLES,
        ] as $statut) {
            foreach ($this->fenetre($statut) as $id) {
                $comptes[$id] = ($comptes[$id] ?? 0) + 1;
            }
        }

        foreach ($comptes as $id => $nb) {
            $this->assertSame(1, $nb, sprintf('L’avenant #%d figure dans %d chips au lieu d’un seul.', $id, $nb));
        }

        // Et les marquées y sont bien, alors qu'elles ne sont dans AUCUNE fenêtre de dates.
        $this->assertArrayHasKey($s['marquee'], $comptes);
        $this->assertArrayHasKey($s['marqueeEnCours'], $comptes);
    }

    /**
     * PIÈGE NULL. Une police sans aucune piste dérivée doit rester visible. Un
     * « IDENTITY(e.pisteDeRenouvellement) NOT IN (…) » vaut NULL — donc faux — et
     * aurait vidé la rubrique en silence.
     */
    public function testUnePoliceSansPisteDeriveeResteDansSaFenetre(): void
    {
        $s = $this->seed();

        $this->assertContains($s['sansSuite'], $this->fenetre(AvenantEcheanceScope::STATUT_ECHUS));
    }

    /**
     * LA RÉCIPROQUE : masquer un renouvellement amorcé mais non abouti reviendrait à
     * cacher le travail qui reste à faire — le défaut inverse, tout aussi grave.
     */
    public function testUnRenouvellementAmorceSansAvenantResteVisible(): void
    {
        $s = $this->seed();

        $this->assertContains($s['amorcee'], $this->fenetre(AvenantEcheanceScope::STATUT_ECHUS));
    }

    /** Renouvellement ANTICIPÉ : la règle vaut pour les quatre fenêtres, pas seulement les échus. */
    public function testUnePoliceRepriseEnAvanceSortAussiDeLaFenetreSous30Jours(): void
    {
        $s = $this->seed();

        $this->assertNotContains($s['anticipee'], $this->fenetre(AvenantEcheanceScope::STATUT_30J));
    }

    /**
     * L'ACCORD DES DEUX DIALECTES — le test décisif. Pour chacune des sept polices,
     * « absente de sa fenêtre d'échéance » (face SQL) doit valoir exactement
     * « estScellee » (face PHP). Une divergence ici est invisible en production :
     * l'écran et l'assistante se contrediraient sans que rien n'échoue.
     */
    public function testLesDeuxDialectesDesignentLeMemeEnsemble(): void
    {
        $s = $this->seed();
        $em = $this->em();

        $echus = $this->fenetre(AvenantEcheanceScope::STATUT_ECHUS);
        $sous30 = $this->fenetre(AvenantEcheanceScope::STATUT_30J);
        $visibles = array_merge($echus, $sous30);

        foreach ($s as $cas => $id) {
            $avenant = $em->getRepository(Avenant::class)->find($id);
            $scellePhp = $this->resolver()->estScellee($avenant);
            $absentSql = !in_array($id, $visibles, true);

            $this->assertSame(
                $scellePhp,
                $absentSql,
                sprintf('Cas « %s » : la face PHP et la face SQL de la règle doivent coïncider.', $cas)
            );
        }
    }

    /**
     * Le badge de ligne suit la même règle : une police scellée n'affiche plus
     * « Expiré depuis N j » en rouge, sinon l'écran contredirait le filtre qui vient
     * de la retirer des échus.
     */
    public function testLeBadgeDeLigneDUnePoliceScelleeEstNeutre(): void
    {
        $s = $this->seed();
        $em = $this->em();
        /** @var AvenantIndicatorStrategy $strategie */
        $strategie = static::getContainer()->get(AvenantIndicatorStrategy::class);

        $reprise = $strategie->calculate($em->getRepository(Avenant::class)->find($s['renouvelee']));
        $this->assertStringStartsWith('Reprise', (string) $reprise['urgenceEcheance']);
        $this->assertSame('reglee', $reprise['urgenceEcheanceNiveau']);

        $resiliee = $strategie->calculate($em->getRepository(Avenant::class)->find($s['resiliee']));
        $this->assertStringStartsWith('Résiliée', (string) $resiliee['urgenceEcheance']);

        // Celle qui réclame encore une action garde son badge d'urgence rouge.
        $amorcee = $strategie->calculate($em->getRepository(Avenant::class)->find($s['amorcee']));
        $this->assertStringStartsWith('Expiré depuis', (string) $amorcee['urgenceEcheance']);
        $this->assertSame('critique', $amorcee['urgenceEcheanceNiveau']);
    }

    /**
     * LE BADGE EST LE SEUL ENDROIT OÙ UNE POLICE MARQUÉE RESTE REPÉRABLE, puisque le
     * pipeline l'a écartée de tous les chips sauf « Toutes ». Il porte donc la décision,
     * et l'alarme rouge « Expiré depuis N j » s'efface : ce n'est pas un retard.
     */
    public function testUnePoliceMarqueePorteSonBadgeDedieEtPerdLAlarmeDeRetard(): void
    {
        $s = $this->seed();
        $em = $this->em();
        /** @var AvenantIndicatorStrategy $strategie */
        $strategie = static::getContainer()->get(AvenantIndicatorStrategy::class);

        $marquee = $strategie->calculate($em->getRepository(Avenant::class)->find($s['marquee']));
        $this->assertSame('Non renouvelable', $marquee['nonRenouvelableBadge']);
        $this->assertSame('faible', $marquee['nonRenouvelableNiveau']);
        $this->assertStringContainsString('vendu le véhicule', (string) $marquee['nonRenouvelableDetail']);
        $this->assertNull($marquee['urgenceEcheance'], 'Une décision n’est pas un retard : pas d’alarme rouge.');

        // Marquage levé : plus de badge, mais l'historique reste lisible dans la fiche.
        $levee = $strategie->calculate($em->getRepository(Avenant::class)->find($s['levee']));
        $this->assertNull($levee['nonRenouvelableBadge']);
        $this->assertNull($levee['nonRenouvelableDetail']);
        $this->assertStringContainsString('levé', (string) $levee['nonRenouvelableHistorique']);
        $this->assertStringStartsWith('Expiré depuis', (string) $levee['urgenceEcheance']);
    }

    /**
     * LE CODE SUIT LA COUVERTURE, PAS LA DÉCISION. Une police marquée qui couvre encore
     * garde RUNNING : poser LOST ferait mentir le badge des listes et le tableau de bord,
     * qui dérivent tous de ce code. C'est le marquage qui scelle le sort, pas le code —
     * d'où estScellePour(), qui prend l'avenant.
     */
    public function testLeStatutDUnePoliceMarqueeDependDeSonEcheanceEtNonDeLaDecision(): void
    {
        $s = $this->seed();
        $em = $this->em();

        $enCours = $em->getRepository(Avenant::class)->find($s['marqueeEnCours']);
        $this->assertSame(Avenant::RENEWAL_STATUS_RUNNING, $this->resolver()->code($enCours));
        $this->assertStringContainsString('Couverture EN COURS', $this->resolver()->phrase($enCours));
        $this->assertStringContainsString('NE SERA PAS renouvelée', $this->resolver()->phrase($enCours));
        $this->assertTrue($this->resolver()->estScellee($enCours), 'Marquée : scellée malgré le code RUNNING.');

        $echue = $em->getRepository(Avenant::class)->find($s['marquee']);
        $this->assertSame(Avenant::RENEWAL_STATUS_LOST, $this->resolver()->code($echue));
        $this->assertStringContainsString('vendu le véhicule', $this->resolver()->phrase($echue));
        // La phrase doit dire ce qui reste vrai : le dossier n'est pas clos.
        $this->assertStringContainsString('recouvrement', $this->resolver()->phrase($echue));
    }
}
