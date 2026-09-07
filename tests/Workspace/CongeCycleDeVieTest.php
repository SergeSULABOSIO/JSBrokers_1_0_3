<?php

namespace App\Tests\Workspace;

use App\Entity\DemandeConge;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\MouvementConge;
use App\Entity\RolesEnAdministration;
use App\Entity\TypeAbsence;
use App\Entity\Utilisateur;
use App\Repository\HistoriqueDemandeRepository;
use App\Service\Conge\CalculateurSolde;
use App\Service\Conge\CongeTransitionException;
use App\Service\Conge\DemandeCongeWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE CYCLE DE VIE D'UNE DEMANDE, ET CE QU'IL FAIT AU COMPTEUR.
 *
 * Ce test rejoue les scénarios de recette 1, 2, 3, 7, 8, 9, 9 bis, 11 et 12. Il pilote le
 * workflow directement : c'est LE service que l'écran et l'assistant appellent tous les
 * deux, et donc le seul endroit où ces règles puissent être vérifiées une fois pour les
 * deux canaux.
 *
 * Le point le plus important n'est pas qu'une approbation décrémente le solde — c'est que
 * l'ENGAGÉ existe. Sans lui, un agent pose deux fois les mêmes jours en enchaînant deux
 * demandes avant toute décision, les deux passent, et le compteur ne devient faux qu'à la
 * seconde approbation.
 */
class CongeCycleDeVieTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-conge-cycle@test.local';
    private const ENT = 'PHPUnit Congés Cycle SARL';

    /**
     * ⚠ LES PÉRIODES DE CE TEST SE CALCULENT, ELLES NE SONT PLUS ÉCRITES.
     *
     * Elles étaient figées au 14 septembre 2026, calées sur un « aujourd'hui » supposé du
     * 9 septembre. Le test a donc marché jusqu'au 6 septembre 2026, puis a échoué pour
     * toujours : passé cette date, il ne restait plus les cinq jours ouvrables de préavis
     * que `DemandeCongeValidator` exige — « Préavis insuffisant : 5 jour(s) ouvrable(s)
     * sont demandés, il en reste 4 ». Cinq erreurs, sur une règle métier intacte.
     *
     * ⚠ ET LE PIÈGE EST PIRE QU'UNE SIMPLE PÉREMPTION : un test daté ne se contente pas
     * de casser, il casse LOIN de sa cause. Rien dans le message n'indique que la faute
     * est dans le calendrier du test et non dans le préavis lui-même.
     *
     * L'ANCRE : le premier lundi de février de l'année SUIVANTE. Trois raisons, et chacune
     * élimine un mode d'échec :
     *
     *  1. toujours dans le futur, quel que soit le jour où l'on exécute — le préavis est
     *     largement satisfait, y compris un 31 décembre ;
     *  2. onze mois de marge avant le bord de l'exercice, là où le scénario le plus long
     *     enchaîne deux périodes de quinze jours ouvrables. Ancrer « dans six semaines »
     *     aurait fait chevaucher le 31 décembre pour toute exécution de fin d'année, et
     *     l'exercice d'imputation serait devenu ambigu ;
     *  3. un lundi, parce que tous les décomptes du test comptent des semaines
     *     ouvrables pleines.
     */
    private function lundi(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('first monday of february %d', (int) date('Y') + 1));
    }

    /** Le vendredi de la même semaine : cinq jours ouvrables. */
    private function vendredi(): \DateTimeImmutable
    {
        return $this->lundi()->modify('+4 days');
    }

    /** Une date de la semaine d'ancrage, au format attendu par `creer()`. */
    private function jour(string $decalage = ''): string
    {
        $date = $this->lundi();

        return ($decalage === '' ? $date : $date->modify($decalage))->format('Y-m-d');
    }

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

    private function workflow(): DemandeCongeWorkflow
    {
        return static::getContainer()->get(DemandeCongeWorkflow::class);
    }

    private function soldes(): CalculateurSolde
    {
        return static::getContainer()->get(CalculateurSolde::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        foreach ([
            'mouvement_conge', 'historique_demande', 'demande_conge', 'regime_travail',
            'jour_ferie', 'type_absence', 'roles_en_administration', 'invite',
        ] as $table) {
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
     * Un cabinet : un propriétaire valideur, un agent doté de 20 jours, et deux types
     * d'absence — un décompté, un non décompté.
     *
     * @return array{entreprise: Entreprise, valideur: Invite, agent: Invite, ca: TypeAbsence, maladie: TypeAbsence}
     */
    private function semer(float $dotation = 20.0): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Cycle')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $valideur = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $valideur->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($valideur);

        $agent = (new Invite())->setNom('Alice Mukendi')->setProprietaire(false);
        $agent->setEntreprise($ent);
        $em->persist($agent);

        // L'agent peut lire et poser, jamais valider : c'est l'attribution d'office.
        $roles = (new RolesEnAdministration())->setNom('Congés');
        $roles->setAccessConge([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);
        $roles->setInvite($agent)->setEntreprise($ent);
        $em->persist($roles);

        $ca = (new TypeAbsence())->setCode(TypeAbsence::CODE_CONGE_ANNUEL)->setLibelle('Congé annuel')
            ->setDecompte(true)->setJustificatifRequis(false)->setAutoriseDemiJournee(true)->setActif(true);
        $ca->setEntreprise($ent);
        $em->persist($ca);

        $maladie = (new TypeAbsence())->setCode(TypeAbsence::CODE_MALADIE)->setLibelle('Maladie')
            ->setDecompte(false)->setJustificatifRequis(false)->setAutoriseDemiJournee(false)->setActif(true);
        $maladie->setEntreprise($ent);
        $em->persist($maladie);

        $em->flush();

        $this->crediter($agent, $ca, $dotation, $ent);

        return ['entreprise' => $ent, 'valideur' => $valideur, 'agent' => $agent, 'ca' => $ca, 'maladie' => $maladie];
    }

    private function crediter(Invite $agent, TypeAbsence $type, float $jours, Entreprise $ent, string $nature = MouvementConge::NATURE_DOTATION): void
    {
        $mouvement = (new MouvementConge())
            ->setAgent($agent)
            ->setExercice($this->exercice())
            ->setTypeAbsence($type)
            ->setNature($nature)
            ->setQuantite(number_format($jours, 1, '.', ''));
        $mouvement->setEntreprise($ent);
        $this->em()->persist($mouvement);
        $this->em()->flush();
    }

    private function creer(array $s, string $debut, string $fin, ?TypeAbsence $type = null): DemandeConge
    {
        $demande = new DemandeConge();
        $demande->setAgent($s['agent']);
        $demande->setTypeAbsence($type ?? $s['ca']);
        $demande->setDateDebut(new \DateTimeImmutable($debut));
        $demande->setDateFin(new \DateTimeImmutable($fin));
        $demande->setEntreprise($s['entreprise']);
        $this->em()->persist($demande);

        return $demande;
    }

    private function exercice(): int
    {
        return (int) $this->lundi()->format('Y');
    }

    // ═══════════ Scénario 1 : l'engagé retient les jours dès la soumission ═══════════

    public function testUneDemandeSoumiseRetientLesJoursDesLEngagement(): void
    {
        $s = $this->semer(20.0);
        $demande = $this->creer($s, $this->jour(), $this->jour('+4 days'));

        $this->workflow()->soumettre($demande, $s['agent']);
        $this->em()->flush();

        $solde = $this->soldes()->pour($s['agent'], $this->exercice());

        self::assertSame(5.0, $demande->nbJoursFloat(), 'Une semaine complète vaut cinq jours ouvrables.');
        self::assertSame(20.0, $solde->acquis);
        self::assertSame(0.0, $solde->consomme, 'Rien n\'est consommé tant que rien n\'est décidé.');
        self::assertSame(5.0, $solde->engage);
        self::assertSame(15.0, $solde->disponible(), 'Le disponible tombe DÈS la soumission.');
    }

    public function testApresApprobationLeDisponibleNeBougePlus(): void
    {
        $s = $this->semer(20.0);
        $demande = $this->creer($s, $this->jour(), $this->jour('+4 days'));

        $this->workflow()->soumettre($demande, $s['agent']);
        $this->em()->flush();

        $this->workflow()->decider($demande, $s['valideur'], DemandeCongeWorkflow::DECISION_APPROUVER);
        $this->em()->flush();

        $solde = $this->soldes()->pour($s['agent'], $this->exercice());

        self::assertSame(5.0, $solde->consomme, "L'approbation déplace les jours vers le consommé.");
        self::assertSame(0.0, $solde->engage);
        self::assertSame(
            15.0,
            $solde->disponible(),
            "Le disponible ne bouge pas à l'approbation : les jours étaient déjà retenus.",
        );
    }

    // ═══════════ Scénario 2 : le refus rend les jours ═══════════

    public function testUnRefusRendLesJoursEngages(): void
    {
        $s = $this->semer(20.0);
        $demande = $this->creer($s, $this->jour(), $this->jour('+4 days'));

        $this->workflow()->soumettre($demande, $s['agent']);
        $this->em()->flush();

        $this->workflow()->decider($demande, $s['valideur'], DemandeCongeWorkflow::DECISION_REFUSER, 'Période de clôture.');
        $this->em()->flush();

        $solde = $this->soldes()->pour($s['agent'], $this->exercice());

        self::assertSame(20.0, $solde->disponible(), 'Un refus rend intégralement les jours.');
        self::assertSame(DemandeConge::STATUT_REFUSEE, $demande->getStatut());
        self::assertSame('Période de clôture.', $demande->getCommentaireDecision());
    }

    // ═══════════ Scénario 3 : deux demandes sur le même solde ═══════════

    /**
     * LE CŒUR DU CONTRÔLE DE SOLDE. Sur l'acquis, les deux demandes passeraient et le
     * compteur ne deviendrait faux qu'à la seconde approbation — c'est-à-dire trop tard.
     */
    public function testDeuxDemandesEnchaineesNePeuventPasDoublerLeSolde(): void
    {
        $s = $this->semer(15.0);

        // Du lundi au vendredi de la troisième semaine : quinze jours ouvrables pleins.
        $premiere = $this->creer($s, $this->jour(), $this->jour('+18 days'));
        $this->workflow()->soumettre($premiere, $s['agent']);
        $this->em()->flush();

        self::assertSame(15.0, $premiere->nbJoursFloat());
        self::assertSame(0.0, $this->soldes()->pour($s['agent'], $this->exercice())->disponible());

        // Quinze jours de plus, quatre semaines après la première période : assez loin pour
        // qu'aucun chevauchement ne brouille le motif du refus, qui doit porter sur le
        // SOLDE et non sur les dates.
        $seconde = $this->creer($s, $this->jour('+7 weeks'), $this->jour('+7 weeks +18 days'));

        $this->expectException(CongeTransitionException::class);
        $this->workflow()->soumettre($seconde, $s['agent']);
    }

    public function testLeRefusDeSoldeNommeLesChiffres(): void
    {
        $s = $this->semer(3.0);
        $demande = $this->creer($s, $this->jour(), $this->jour('+4 days'));

        try {
            $this->workflow()->soumettre($demande, $s['agent']);
            self::fail('Une demande de 5 jours sur un solde de 3 doit être refusée.');
        } catch (CongeTransitionException $e) {
            self::assertStringContainsString('Solde insuffisant', implode(' ', $e->violations));
            self::assertStringContainsString('3', implode(' ', $e->violations));
        }
    }

    // ═══════════ Scénario 11 : un type non décompté ne touche pas au compteur ═══════════

    public function testUnTypeNonDecompteNeToucheJamaisAuCompteur(): void
    {
        $s = $this->semer(20.0);
        $demande = $this->creer($s, $this->jour(), $this->jour('+4 days'), $s['maladie']);

        $this->workflow()->soumettre($demande, $s['agent']);
        $this->em()->flush();
        $this->workflow()->decider($demande, $s['valideur'], DemandeCongeWorkflow::DECISION_APPROUVER);
        $this->em()->flush();

        $solde = $this->soldes()->pour($s['agent'], $this->exercice());

        self::assertSame(20.0, $solde->disponible(), 'Un arrêt maladie ne coûte pas de congé annuel.');
        self::assertSame(0.0, $solde->consomme);
        self::assertSame(0.0, $solde->engage);
        self::assertSame(
            DemandeConge::STATUT_APPROUVEE,
            $demande->getStatut(),
            'La demande est bien enregistrée : elle apparaît au calendrier et à l\'historique.',
        );
    }

    // ═══════════ Scénarios 7 et 8 : l'annulation ═══════════

    public function testLAgentAnnuleSaDemandeAvantLeDebutEtRecupereSesJours(): void
    {
        $s = $this->semer(20.0);
        // Très à venir, et sur une semaine que nul autre scénario n'occupe.
        $demande = $this->creer($s, $this->jour('+11 weeks'), $this->jour('+11 weeks +4 days'));

        $this->workflow()->soumettre($demande, $s['agent']);
        $this->em()->flush();
        $this->workflow()->decider($demande, $s['valideur'], DemandeCongeWorkflow::DECISION_APPROUVER);
        $this->em()->flush();

        $this->workflow()->annuler($demande, $s['agent']);
        $this->em()->flush();

        self::assertSame(DemandeConge::STATUT_ANNULEE, $demande->getStatut());
        self::assertSame(
            20.0,
            $this->soldes()->pour($s['agent'], $this->exercice())->disponible(),
            'Le recrédit est immédiat.',
        );
    }

    /**
     * Une absence DÉJÀ COMMENCÉE ne s'annule ni par l'agent, ni sans explication : c'est
     * une ligne que quelqu'un devra relire dans six mois.
     */
    public function testUneAbsenceCommenceeNeSAnnulePasSansValideurNiSansMotif(): void
    {
        $s = $this->semer(20.0);
        // ⚠ CETTE DATE RESTE FIGÉE, ET DOIT LE RESTER. Le scénario exige une période
        // RÉVOLUE : la dériver du jour d'exécution en ferait, un jour, une période à
        // venir, et le test cesserait de vérifier ce qu'il annonce sans échouer pour
        // autant. Une date du passé est intemporellement passée.
        $demande = $this->creer($s, '2020-01-06', '2020-01-10');

        $demande->setStatut(DemandeConge::STATUT_APPROUVEE);
        $demande->setNbJours('5.0');
        $this->em()->flush();

        $violationsAgent = $this->workflow()->verifierAnnulation($demande, $s['agent'], 'peu importe');
        self::assertNotSame([], $violationsAgent, "L'agent ne peut plus annuler seul une absence commencée.");

        $violationsSansMotif = $this->workflow()->verifierAnnulation($demande, $s['valideur'], null);
        self::assertStringContainsString('motif', mb_strtolower(implode(' ', $violationsSansMotif)));

        $avecMotif = $this->workflow()->verifierAnnulation($demande, $s['valideur'], 'Rappel en urgence sur un sinistre.');
        self::assertSame([], $avecMotif, 'Avec un motif, le valideur peut annuler.');
    }

    // ═══════════ Scénario 9 : nul ne valide sa propre demande ═══════════

    public function testNulNeValideSaPropreDemande(): void
    {
        $s = $this->semer(20.0);

        // Une demande DU VALIDEUR, dans un cabinet qui compte un second valideur.
        $second = (new Invite())->setNom('Second Valideur')->setProprietaire(false);
        $second->setEntreprise($s['entreprise']);
        $roles = (new RolesEnAdministration())->setNom('Validation');
        $roles->setAccessConge([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE, Invite::ACCESS_MODIFICATION]);
        $roles->setInvite($second)->setEntreprise($s['entreprise']);
        $this->em()->persist($second);
        $this->em()->persist($roles);
        $this->em()->flush();
        // Sans ce refresh, la collection de rôles déjà chargée reste vide et le second
        // valideur n'en serait pas un — le test mesurerait alors son propre montage.
        $this->em()->refresh($second);

        $demande = new DemandeConge();
        $demande->setAgent($s['valideur'])->setTypeAbsence($s['ca']);
        $demande->setDateDebut($this->lundi());
        $demande->setDateFin($this->vendredi());
        $demande->setEntreprise($s['entreprise']);
        $demande->setStatut(DemandeConge::STATUT_SOUMISE);
        $demande->setNbJours('5.0');
        $this->em()->persist($demande);
        $this->em()->flush();

        $violations = $this->workflow()->verifierDecision($demande, $s['valideur'], DemandeCongeWorkflow::DECISION_APPROUVER);
        self::assertNotSame([], $violations, 'Le propriétaire ne décide pas de sa propre demande.');
        self::assertStringContainsString('votre propre demande', implode(' ', $violations));

        // Le second valideur, lui, le peut : la demande est dans SA file.
        self::assertSame([], $this->workflow()->verifierDecision($demande, $second, DemandeCongeWorkflow::DECISION_APPROUVER));
    }

    // ═══════════ Scénario 9 bis : le seul valideur s'auto-approuve ═══════════

    /**
     * Sans cette règle, la demande du propriétaire attendrait indéfiniment quelqu'un qui
     * n'existe pas. Elle est donc approuvée d'emblée — et la mention « auto-approuvée »
     * l'accompagne, parce que ce n'est pas une validation ordinaire.
     */
    public function testLeSeulValideurDuCabinetVoitSaDemandeAutoApprouvee(): void
    {
        $s = $this->semer(20.0);
        $this->crediter($s['valideur'], $s['ca'], 20.0, $s['entreprise']);

        $demande = new DemandeConge();
        $demande->setAgent($s['valideur'])->setTypeAbsence($s['ca']);
        $demande->setDateDebut($this->lundi());
        $demande->setDateFin($this->vendredi());
        $demande->setEntreprise($s['entreprise']);
        $this->em()->persist($demande);

        $historique = $this->workflow()->soumettre($demande, $s['valideur']);
        $this->em()->flush();

        self::assertSame(DemandeConge::STATUT_APPROUVEE, $demande->getStatut());
        self::assertTrue($historique->isAutoApprouvee(), 'La mention doit être portée par la trace.');
        self::assertSame(
            15.0,
            $this->soldes()->pour($s['valideur'], $this->exercice())->disponible(),
            'Une auto-approbation décompte comme toute approbation.',
        );
    }

    // ═══════════ Traçabilité (RG-11) et immuabilité (RG-05) ═══════════

    public function testChaqueTransitionEcritUneLigneDHistorique(): void
    {
        $s = $this->semer(20.0);
        $demande = $this->creer($s, $this->jour('+12 weeks'), $this->jour('+12 weeks +4 days'));

        $this->workflow()->soumettre($demande, $s['agent']);
        $this->em()->flush();
        $this->workflow()->decider($demande, $s['valideur'], DemandeCongeWorkflow::DECISION_APPROUVER, 'Bon congé.');
        $this->em()->flush();
        $this->workflow()->annuler($demande, $s['valideur'], 'Le client a avancé la réunion.');
        $this->em()->flush();

        /** @var HistoriqueDemandeRepository $repo */
        $repo = static::getContainer()->get(HistoriqueDemandeRepository::class);
        $fil = $repo->filDe($demande);

        self::assertCount(3, $fil, 'Soumission, décision, annulation : trois transitions, trois lignes.');
        self::assertSame(DemandeConge::STATUT_SOUMISE, $fil[0]->getStatutApres());
        self::assertSame(DemandeConge::STATUT_APPROUVEE, $fil[1]->getStatutApres());
        self::assertSame(DemandeConge::STATUT_ANNULEE, $fil[2]->getStatutApres());
        self::assertSame($s['valideur']->getId(), $fil[2]->getAuteur()?->getId());
    }

    /**
     * L'ANNULATION N'EFFACE PAS LA PRISE : elle écrit un mouvement INVERSE. Le journal
     * garde donc les deux, et le solde revient sans qu'aucune ligne n'ait été retouchée.
     */
    public function testLAnnulationEcritUnMouvementInverseEtNEffaceRien(): void
    {
        $s = $this->semer(20.0);
        $demande = $this->creer($s, $this->jour('+13 weeks'), $this->jour('+13 weeks +4 days'));

        $this->workflow()->soumettre($demande, $s['agent']);
        $this->em()->flush();
        $this->workflow()->decider($demande, $s['valideur'], DemandeCongeWorkflow::DECISION_APPROUVER);
        $this->em()->flush();
        $this->workflow()->annuler($demande, $s['agent']);
        $this->em()->flush();

        $mouvements = $this->em()->getRepository(MouvementConge::class)
            ->findBy(['demande' => $demande], ['id' => 'ASC']);

        self::assertCount(2, $mouvements, 'Une prise, puis son inverse — jamais une suppression.');
        self::assertSame(MouvementConge::NATURE_PRISE, $mouvements[0]->getNature());
        self::assertSame(-5.0, $mouvements[0]->quantiteFloat());
        self::assertSame(MouvementConge::NATURE_ANNULATION, $mouvements[1]->getNature());
        self::assertSame(5.0, $mouvements[1]->quantiteFloat());
    }

    /**
     * Scénario 12 : un ajustement laisse sa trace, avec son auteur et son motif.
     *
     * L'ajustement n'a pas encore d'écran (lot 3), mais le journal le porte déjà — c'est
     * lui qui fait foi, et il doit être juste avant que quiconque l'alimente.
     */
    public function testUnAjustementSeLitDansLeSolde(): void
    {
        $s = $this->semer(20.0);
        $this->crediter($s['agent'], $s['ca'], -2.0, $s['entreprise'], MouvementConge::NATURE_AJUSTEMENT);

        $solde = $this->soldes()->pour($s['agent'], $this->exercice());

        self::assertSame(18.0, $solde->acquis, "L'ajustement entre dans l'acquis, avec son signe.");
        self::assertSame(18.0, $solde->disponible());
    }

    /** Le report se lit à part, dans l'acquis mais identifiable (RG-08). */
    public function testLeReportSeLitDansLAcquisEtSeDetaille(): void
    {
        $s = $this->semer(20.0);
        $this->crediter($s['agent'], $s['ca'], 4.5, $s['entreprise'], MouvementConge::NATURE_REPORT);

        $solde = $this->soldes()->pour($s['agent'], $this->exercice());

        self::assertSame(24.5, $solde->acquis);
        self::assertSame(4.5, $solde->dontReport, 'Le détail « dont report N-1 » doit rester lisible.');
    }

    /** Une double approbation ne décompte pas deux fois (double clic, message rejoué). */
    public function testUneDoubleApprobationNeDecomptePasDeuxFois(): void
    {
        $s = $this->semer(20.0);
        $demande = $this->creer($s, $this->jour('+14 weeks'), $this->jour('+14 weeks +4 days'));

        $this->workflow()->soumettre($demande, $s['agent']);
        $this->em()->flush();
        $this->workflow()->decider($demande, $s['valideur'], DemandeCongeWorkflow::DECISION_APPROUVER);
        $this->em()->flush();

        // Le second geste est refusé par le workflow : la demande n'est plus soumise.
        $violations = $this->workflow()->verifierDecision($demande, $s['valideur'], DemandeCongeWorkflow::DECISION_APPROUVER);
        self::assertNotSame([], $violations);

        self::assertCount(
            1,
            $this->em()->getRepository(MouvementConge::class)->findBy(['demande' => $demande, 'nature' => MouvementConge::NATURE_PRISE]),
            'Un seul mouvement de prise, quoi qu\'il arrive.',
        );
    }
}
