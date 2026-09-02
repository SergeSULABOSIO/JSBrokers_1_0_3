<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\MouvementConge;
use App\Entity\TypeAbsence;
use App\Entity\Utilisateur;
use App\Repository\MouvementCongeRepository;
use App\Service\Conge\CalculateurSolde;
use App\Service\Conge\CompteursExport;
use App\Service\Conge\CongeParametres;
use App\Service\Conge\CongeTransitionException;
use App\Service\Conge\GrilleDesCompteurs;
use App\Service\Conge\MouvementDuCompteur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * LES COMPTEURS : LA GRILLE, L'AJUSTEMENT, L'OUVERTURE D'EXERCICE ET LA SORTIE.
 *
 * Lot 3 de la recette. Ce que ces tests protègent tient en une phrase : **un compteur de
 * congés qu'on ne sait pas expliquer est un compteur qu'on cesse de croire.** Trois
 * dangers concrets, chacun couvert ici :
 *
 * 1. **Une ouverture d'exercice rejouée doublerait le droit de chacun**, en silence, et
 *    personne ne s'en apercevrait avant que quelqu'un prenne des jours qu'il n'a pas.
 * 2. **Reporter après avoir doté** reporterait un reliquat déjà gonflé de la dotation de
 *    l'année qui s'ouvre — l'ordre des deux écritures est donc une règle, pas un détail.
 * 3. **Un aperçu de sortie qui écrirait** ferait du simple fait de regarder un acte de
 *    gestion, sur des jours qu'un solde de tout compte finit par payer.
 */
class CongeCompteursTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-conge-compteurs@test.local';
    private const ENT = 'PHPUnit Congés Compteurs SARL';

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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        // L'invité se pointe lui-même (manager) : on dénoue avant de supprimer, sinon la
        // contrainte se plaint et le test se met à parler d'autre chose.
        $conn->executeStatement(
            'UPDATE invite i JOIN entreprise e ON i.entreprise_id = e.id SET i.manager_id = NULL WHERE e.nom = :nom',
            ['nom' => self::ENT],
        );

        // TAXE ET AUTORITÉ FISCALE SE POINTENT MUTUELLEMENT : aucun ordre de suppression
        // ne peut les départager. On casse le cycle d'abord.
        $conn->executeStatement(
            'UPDATE autorite_fiscale a JOIN entreprise e ON a.entreprise_id = e.id SET a.taxe_id = NULL WHERE e.nom = :nom',
            ['nom' => self::ENT],
        );

        // La liste couvre AUSSI ce que sème le provisionnement complet (risques, monnaies,
        // taxes) : `app:conges:provisionner` appelle l'initialisation du cabinet entière,
        // et un seul enfant oublié fait échouer la purge sur une contrainte — le test se
        // met alors à parler d'autre chose que de ce qu'il mesure.
        foreach ([
            'mouvement_conge', 'historique_demande', 'demande_conge', 'regime_travail',
            'jour_ferie', 'type_absence', 'periode_blocage', 'parametres_conge',
            'roles_en_administration',
            'type_revenu', 'chargement', 'taxe', 'autorite_fiscale',
            'risque', 'groupe', 'monnaie', 'invite',
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
     * Un cabinet minimal : un propriétaire, deux collaborateurs, un type « Congé annuel ».
     *
     * On ne passe pas par le provisionnement complet : il dote déjà tout le monde sur
     * l'exercice courant, ce qui masquerait ce que ces tests veulent voir écrire.
     *
     * @return array{entreprise: Entreprise, patron: Invite, alice: Invite, bob: Invite, type: TypeAbsence}
     */
    private function cabinet(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Compteurs')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $patron = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $patron->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($patron);

        $alice = (new Invite())->setNom('Alice Dupont');
        $alice->setEntreprise($ent);
        $em->persist($alice);

        $bob = (new Invite())->setNom('Bob Martin');
        $bob->setEntreprise($ent);
        $em->persist($bob);

        $type = (new TypeAbsence())
            ->setCode(TypeAbsence::CODE_CONGE_ANNUEL)
            ->setLibelle('Congé annuel')
            ->setDecompte(true)
            ->setActif(true);
        $type->setEntreprise($ent);
        $em->persist($type);

        $em->flush();

        // ON ANTIDATE L'ARRIVÉE DES COLLABORATEURS, en SQL.
        //
        // `createdAt` est posé par AuditableTrait au moment du flush : sans cela, tout le
        // monde « arrive » aujourd'hui, et un décompte de sortie daté d'un mois antérieur
        // rendrait zéro — un départ avant l'arrivée. Le prorata de sortie lit cette date ;
        // le trait ne la laisse pas écrire par l'ORM, d'où le passage direct.
        $em->getConnection()->executeStatement(
            'UPDATE invite SET created_at = :d WHERE entreprise_id = :e',
            ['d' => '2025-01-06 08:00:00', 'e' => $ent->getId()],
        );
        foreach ([$patron, $alice, $bob] as $membre) {
            $em->refresh($membre);
        }
        $em->refresh($ent);

        return ['entreprise' => $ent, 'patron' => $patron, 'alice' => $alice, 'bob' => $bob, 'type' => $type];
    }

    private function mouvement(Invite $agent, TypeAbsence $type, int $exercice, string $nature, float $q): MouvementConge
    {
        $m = new MouvementConge();
        $m->setAgent($agent)->setExercice($exercice)->setTypeAbsence($type)->setNature($nature)
            ->setQuantite(number_format($q, 1, '.', ''))->setCommentaire('semis de test');
        $m->setEntreprise($agent->getEntreprise());
        $this->em()->persist($m);

        return $m;
    }

    // ═══════════════════════════════ La grille ══════════════════════════════════════

    /**
     * La grille montre TOUT LE MONDE, y compris qui n'a aucun mouvement.
     *
     * C'est la différence avec le calendrier, qui ne liste que les absents : ici, un
     * collaborateur à zéro est une information — c'est peut-être quelqu'un qu'on a oublié
     * de doter.
     */
    public function testLaGrilleListeTousLesCollaborateursMemeCeuxSansMouvement(): void
    {
        $c = $this->cabinet();
        $this->mouvement($c['alice'], $c['type'], 2026, MouvementConge::NATURE_DOTATION, 26.0);
        $this->em()->flush();

        $grille = static::getContainer()->get(GrilleDesCompteurs::class)->pour($c['entreprise'], 2026);

        self::assertCount(3, $grille['lignes'], 'Le propriétaire et les deux invités doivent tous figurer.');

        $noms = array_column($grille['lignes'], 'agent');
        self::assertSame(['Alice Dupont', 'Bob Martin', 'Le Patron'], $noms, 'La grille est triée par nom.');

        $bob = $grille['lignes'][1];
        self::assertSame(0.0, $bob['acquis']);
        self::assertSame(0.0, $bob['disponible'], "Bob n'a jamais été doté : il doit apparaître à zéro, pas disparaître.");
    }

    /**
     * Les totaux de pied de grille sont la somme des lignes affichées — pas un agrégat
     * calculé à part, qui finirait par contredire ce que l'écran montre.
     */
    public function testLesTotauxSontLaSommeDesLignes(): void
    {
        $c = $this->cabinet();
        $this->mouvement($c['alice'], $c['type'], 2026, MouvementConge::NATURE_DOTATION, 26.0);
        $this->mouvement($c['alice'], $c['type'], 2026, MouvementConge::NATURE_PRISE, -4.0);
        $this->mouvement($c['bob'], $c['type'], 2026, MouvementConge::NATURE_DOTATION, 20.0);
        $this->em()->flush();

        $grille = static::getContainer()->get(GrilleDesCompteurs::class)->pour($c['entreprise'], 2026);

        self::assertSame(46.0, $grille['totaux']['acquis']);
        self::assertSame(4.0, $grille['totaux']['consomme']);
        self::assertSame(
            array_sum(array_column($grille['lignes'], 'disponible')),
            $grille['totaux']['disponible'],
        );
    }

    /**
     * L'ALERTE DE REPORT : au-delà du seuil du cabinet, la ligne est signalée.
     *
     * Sans seuil réglé, le défaut vaut deux fois la dotation — un solde qui atteint deux
     * années entières est une dette qui a grossi sans que personne ne la regarde.
     */
    public function testUnSoldeAuDelaDuSeuilEstSignale(): void
    {
        $c = $this->cabinet();
        $this->mouvement($c['alice'], $c['type'], 2026, MouvementConge::NATURE_DOTATION, 60.0);
        $this->mouvement($c['bob'], $c['type'], 2026, MouvementConge::NATURE_DOTATION, 10.0);
        $this->em()->flush();

        $grille = static::getContainer()->get(GrilleDesCompteurs::class)->pour($c['entreprise'], 2026);
        $parNom = array_column($grille['lignes'], null, 'agent');

        self::assertGreaterThan(0.0, $grille['seuilReport']);
        self::assertTrue($parNom['Alice Dupont']['alerte'], '60 j dépassent deux fois la dotation de 26 j.');
        self::assertFalse($parNom['Bob Martin']['alerte']);
    }

    /** Un identifiant venu d'une URL ne traverse pas les cabinets. */
    public function testUnAgentDUnAutreCabinetNEstPasResolu(): void
    {
        $c = $this->cabinet();
        $grille = static::getContainer()->get(GrilleDesCompteurs::class);

        self::assertNotNull($grille->agentDuCabinet($c['entreprise'], (int) $c['alice']->getId()));
        self::assertNull(
            $grille->agentDuCabinet($c['entreprise'], 999_999_999),
            "Un identifiant inconnu ne doit pas être résolu — c'est la porte des deux gestes qui écrivent.",
        );
    }

    // ═════════════════════════════ L'ajustement ═════════════════════════════════════

    /**
     * LE MOTIF EST OBLIGATOIRE. Ce n'est pas une politesse : un chiffre qui apparaît dans
     * un journal sans explication fera douter de tout le reste, des mois plus tard.
     */
    public function testUnAjustementSansMotifEstRefuse(): void
    {
        $c = $this->cabinet();

        try {
            static::getContainer()->get(MouvementDuCompteur::class)
                ->ajuster($c['alice'], 2026, 3.0, '   ', $c['patron']);
            self::fail("Un ajustement sans motif aurait dû être refusé.");
        } catch (CongeTransitionException $e) {
            self::assertNotEmpty($e->violations);
            self::assertStringContainsStringIgnoringCase('motif', implode(' ', $e->violations));
        }
    }

    /** Une ligne à zéro jour n'apprend rien à personne : elle est refusée. */
    public function testUnAjustementDeZeroJourEstRefuse(): void
    {
        $c = $this->cabinet();

        $violations = static::getContainer()->get(MouvementDuCompteur::class)
            ->verifierAjustement(0.0, 'Régularisation');

        self::assertNotEmpty($violations);
    }

    /**
     * UN AJUSTEMENT EST IMMUABLE : on le corrige par un second, en sens inverse, motivé.
     *
     * Le compteur revient à sa valeur d'origine ET le journal garde les deux lignes.
     * C'est exactement ce qui permet, six mois plus tard, d'expliquer un solde qui a
     * bougé deux fois.
     */
    public function testUnAjustementSeCorrigeParUnAjustementInverseEtLesDeuxRestent(): void
    {
        $c = $this->cabinet();
        $this->mouvement($c['alice'], $c['type'], 2026, MouvementConge::NATURE_DOTATION, 26.0);
        $this->em()->flush();

        $compteur = static::getContainer()->get(MouvementDuCompteur::class);
        $compteur->ajuster($c['alice'], 2026, 3.0, 'Jours offerts pour ancienneté', $c['patron']);
        $this->em()->flush();

        $solde = static::getContainer()->get(CalculateurSolde::class);
        self::assertSame(29.0, $solde->pour($c['alice'], 2026)->disponible());

        $compteur->ajuster($c['alice'], 2026, -3.0, 'Erreur de saisie : reprise des 3 jours', $c['patron']);
        $this->em()->flush();
        $this->em()->clear();

        $alice = $this->em()->getRepository(Invite::class)->find($c['alice']->getId());
        self::assertSame(26.0, $solde->pour($alice, 2026)->disponible(), 'Le compteur revient à son état initial.');

        $journal = static::getContainer()->get(MouvementCongeRepository::class)->journalDe($alice, 2026);
        $ajustements = array_filter(
            $journal,
            static fn (MouvementConge $m) => $m->getNature() === MouvementConge::NATURE_AJUSTEMENT,
        );
        self::assertCount(2, $ajustements, 'Les DEUX ajustements restent au journal : rien n\'est réécrit.');
    }

    // ═══════════════════════ L'ouverture d'exercice ═════════════════════════════════

    private function commandeOuverture(): CommandTester
    {
        $application = new Application(static::$kernel);

        return new CommandTester($application->find('app:conges:ouvrir-exercice'));
    }

    /** Sans `--force`, rien n'est écrit : sur des droits à congé, on regarde avant. */
    public function testSansForceLOuvertureNEcritRien(): void
    {
        $c = $this->cabinet();
        $tester = $this->commandeOuverture();
        $tester->execute(['annee' => 2027, '--entreprise' => (string) $c['entreprise']->getId()]);

        $this->em()->clear();
        self::assertSame(0, $this->compterMouvements($c['entreprise'], 2027));
        self::assertStringContainsString('À appliquer', $tester->getDisplay());
    }

    /**
     * LE REPORT EST LU AVANT QUE LA DOTATION NE LE GONFLE.
     *
     * Alice finit 2026 avec 6 jours ; l'ouverture de 2027 lui écrit un report de 6 j —
     * pas de 32. Inverser l'ordre des deux écritures reporterait un reliquat déjà gonflé
     * de la dotation de l'année qui s'ouvre, et le droit enflerait d'année en année.
     */
    public function testLeReportPrecedeLaDotationEtNEstPasGonfleParElle(): void
    {
        $c = $this->cabinet();
        $this->mouvement($c['alice'], $c['type'], 2026, MouvementConge::NATURE_DOTATION, 26.0);
        $this->mouvement($c['alice'], $c['type'], 2026, MouvementConge::NATURE_PRISE, -20.0);
        $this->em()->flush();

        $this->commandeOuverture()->execute([
            'annee' => 2027,
            '--entreprise' => (string) $c['entreprise']->getId(),
            '--force' => true,
        ]);
        $this->em()->clear();

        $alice = $this->em()->getRepository(Invite::class)->find($c['alice']->getId());
        $totaux = static::getContainer()->get(MouvementCongeRepository::class)->totauxParNature($alice, 2027);

        self::assertSame(6.0, $totaux[MouvementConge::NATURE_REPORT] ?? 0.0, 'Le reliquat de 2026 était de 6 jours.');
        self::assertSame(
            CongeParametres::DOTATION_ANNUELLE_DEFAUT,
            $totaux[MouvementConge::NATURE_DOTATION] ?? 0.0,
        );
    }

    /**
     * L'IDEMPOTENCE, LA GARDE VITALE.
     *
     * Une ouverture rejouée doublerait le droit de chacun, en silence, et personne ne
     * s'en apercevrait avant que quelqu'un prenne des jours qu'il n'a pas.
     */
    public function testUneOuvertureRejoueeNEcritRienDeNouveau(): void
    {
        $c = $this->cabinet();
        $this->mouvement($c['alice'], $c['type'], 2026, MouvementConge::NATURE_DOTATION, 26.0);
        $this->em()->flush();

        $arguments = ['annee' => 2027, '--entreprise' => (string) $c['entreprise']->getId(), '--force' => true];

        $this->commandeOuverture()->execute($arguments);
        $this->em()->clear();
        $apresLaPremiere = $this->compterMouvements($c['entreprise'], 2027);
        self::assertGreaterThan(0, $apresLaPremiere);

        $rejeu = $this->commandeOuverture();
        $rejeu->execute($arguments);
        $this->em()->clear();

        self::assertSame($apresLaPremiere, $this->compterMouvements($c['entreprise'], 2027), 'Le rejeu ne doit rien ajouter.');
        self::assertStringContainsString('0 report(s)', $rejeu->getDisplay());
        self::assertStringContainsString('0 dotation(s)', $rejeu->getDisplay());
    }

    /**
     * Report et dotation sont vérifiés SÉPARÉMENT : une exécution interrompue entre les
     * deux doit pouvoir être reprise sans redoubler ce qui est déjà écrit.
     */
    public function testUneOuvertureInterrompueApresLeReportEcritLaDotationManquante(): void
    {
        $c = $this->cabinet();
        $this->mouvement($c['alice'], $c['type'], 2026, MouvementConge::NATURE_DOTATION, 26.0);
        // Le report est déjà là — comme après une exécution coupée en deux.
        $this->mouvement($c['alice'], $c['type'], 2027, MouvementConge::NATURE_REPORT, 26.0);
        $this->em()->flush();

        $this->commandeOuverture()->execute([
            'annee' => 2027,
            '--entreprise' => (string) $c['entreprise']->getId(),
            '--force' => true,
        ]);
        $this->em()->clear();

        $alice = $this->em()->getRepository(Invite::class)->find($c['alice']->getId());
        $totaux = static::getContainer()->get(MouvementCongeRepository::class)->totauxParNature($alice, 2027);

        self::assertSame(26.0, $totaux[MouvementConge::NATURE_REPORT] ?? 0.0, 'Le report existant ne doit pas être redoublé.');
        self::assertSame(26.0, $totaux[MouvementConge::NATURE_DOTATION] ?? 0.0, 'La dotation manquante, elle, est écrite.');
    }

    private function compterMouvements(Entreprise $entreprise, int $exercice): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM mouvement_conge WHERE entreprise_id = :e AND exercice = :x',
            ['e' => $entreprise->getId(), 'x' => $exercice],
        );
    }

    // ═══════════════ Le rattrapage de la dotation de démarrage ══════════════════════

    private function commandeProvisionnement(): CommandTester
    {
        $application = new Application(static::$kernel);

        return new CommandTester($application->find('app:conges:provisionner'));
    }

    /**
     * UN COMPTEUR DOTÉ AU PRORATA EST RAMENÉ À L'ANNÉE PLEINE.
     *
     * La dotation de démarrage se calculait sur la date de création de la fiche d'invité,
     * qui dit quand le collaborateur a été SAISI dans le logiciel et non quand il est
     * arrivé dans le cabinet. Un cabinet ayant adopté le module en avril avait tout son
     * personnel à neuf mois sur douze. Le complément est un mouvement DE PLUS, motivé :
     * la dotation d'origine reste au journal, et c'est ce qui permettra d'expliquer, plus
     * tard, pourquoi le compteur a bougé un jour.
     */
    public function testUneDotationProratiseeEstRamèneeALAnneePleine(): void
    {
        $c = $this->cabinet();
        $exercice = (int) (new \DateTimeImmutable('now'))->format('Y');
        // 19,5 j : ce que l'ancien prorata donnait à une fiche créée en avril.
        $this->mouvement($c['alice'], $c['type'], $exercice, MouvementConge::NATURE_DOTATION, 19.5);
        $this->em()->flush();

        $this->commandeProvisionnement()->execute([
            '--entreprise' => (string) $c['entreprise']->getId(),
            '--force' => true,
        ]);
        $this->em()->clear();

        $alice = $this->em()->getRepository(Invite::class)->find($c['alice']->getId());

        self::assertSame(
            CongeParametres::DOTATION_ANNUELLE_DEFAUT,
            static::getContainer()->get(CalculateurSolde::class)->pour($alice, $exercice)->disponible(),
        );

        $totaux = static::getContainer()->get(MouvementCongeRepository::class)->totauxParNature($alice, $exercice);
        self::assertSame(19.5, $totaux[MouvementConge::NATURE_DOTATION] ?? 0.0, "La dotation d'origine reste intacte.");
        self::assertSame(6.5, $totaux[MouvementConge::NATURE_AJUSTEMENT] ?? 0.0);
    }

    /**
     * LE RATTRAPAGE NE DÉFAIT PAS LA MAIN DU VALIDEUR.
     *
     * Une garde fondée sur « le solde vaut-il la dotation ? » rendrait à chaque exécution
     * les jours qu'un valideur a légitimement retirés. Elle est donc fondée sur le
     * marqueur du rattrapage : une fois posé, il ne se repose jamais.
     */
    public function testLeRattrapageNeSeRejoueNiNAnnuleUnAjustementDuValideur(): void
    {
        $c = $this->cabinet();
        $exercice = (int) (new \DateTimeImmutable('now'))->format('Y');
        $this->mouvement($c['alice'], $c['type'], $exercice, MouvementConge::NATURE_DOTATION, 19.5);
        $this->em()->flush();

        $arguments = ['--entreprise' => (string) $c['entreprise']->getId(), '--force' => true];
        $this->commandeProvisionnement()->execute($arguments);
        $this->em()->clear();

        // Le valideur retire ensuite 4 jours, en connaissance de cause.
        $alice = $this->em()->getRepository(Invite::class)->find($c['alice']->getId());
        $patron = $this->em()->getRepository(Invite::class)->find($c['patron']->getId());
        static::getContainer()->get(MouvementDuCompteur::class)
            ->ajuster($alice, $exercice, -4.0, 'Jours pris en 2025 non déclarés', $patron);
        $this->em()->flush();
        $this->em()->clear();

        $rejeu = $this->commandeProvisionnement();
        $rejeu->execute($arguments);
        $this->em()->clear();

        $alice = $this->em()->getRepository(Invite::class)->find($c['alice']->getId());
        self::assertSame(
            22.0,
            static::getContainer()->get(CalculateurSolde::class)->pour($alice, $exercice)->disponible(),
            '19,5 + 6,5 de rattrapage − 4 retirés par le valideur : le rejeu ne rend pas les 4 jours.',
        );
        self::assertStringContainsString('0 rattrapage(s)', $rejeu->getDisplay());
    }

    /**
     * LA REPRISE DES DÉCOMPTES ÉCRITS AVANT LA CORRECTION DE LA RÈGLE.
     *
     * Le nombre de jours n'était calculé qu'à la soumission : une demande passée du 2 au 2
     * septembre au 2 au 3 continuait d'annoncer « 1 j » à côté de sa nouvelle période. La
     * règle est corrigée, mais une règle neuve ne réécrit pas le passé — les lignes déjà
     * en base gardent leur chiffre tant que personne ne les touche.
     */
    public function testLaRepriseCorrigeUnDecompteDevenuFaux(): void
    {
        $c = $this->cabinet();

        // Du mercredi 2 au jeudi 3 septembre : deux jours ouvrables, un seul enregistré.
        $demande = new \App\Entity\DemandeConge();
        $demande->setAgent($c['alice'])->setTypeAbsence($c['type']);
        $demande->setDateDebut(new \DateTimeImmutable('2026-09-02'));
        $demande->setDateFin(new \DateTimeImmutable('2026-09-03'));
        $demande->setStatut(\App\Entity\DemandeConge::STATUT_SOUMISE);
        $demande->setNbJours('1.0');
        $demande->setEntreprise($c['entreprise']);
        $this->em()->persist($demande);
        $this->em()->flush();

        // Une demande DÉCIDÉE, elle, garde son décompte : c'est lui qui a produit le
        // mouvement de compteur, et le recalculer ferait diverger le solde de sa trace.
        $decidee = new \App\Entity\DemandeConge();
        $decidee->setAgent($c['bob'])->setTypeAbsence($c['type']);
        $decidee->setDateDebut(new \DateTimeImmutable('2026-09-02'));
        $decidee->setDateFin(new \DateTimeImmutable('2026-09-03'));
        $decidee->setStatut(\App\Entity\DemandeConge::STATUT_APPROUVEE);
        $decidee->setNbJours('1.0');
        $decidee->setEntreprise($c['entreprise']);
        $this->em()->persist($decidee);
        $this->em()->flush();

        $arguments = ['--entreprise' => (string) $c['entreprise']->getId(), '--force' => true];
        $this->commandeProvisionnement()->execute($arguments);
        $this->em()->clear();

        $repo = $this->em()->getRepository(\App\Entity\DemandeConge::class);
        self::assertSame(
            2.0,
            (float) $repo->find($demande->getId())->getNbJours(),
            'Du mercredi 2 au jeudi 3, il y a deux jours ouvrables.',
        );
        self::assertSame(
            1.0,
            (float) $repo->find($decidee->getId())->getNbJours(),
            "Une demande approuvée garde son décompte : il a produit le mouvement de compteur.",
        );

        $rejeu = $this->commandeProvisionnement();
        $rejeu->execute($arguments);
        self::assertStringContainsString('0 décompte(s) corrigé(s)', $rejeu->getDisplay());
    }

    // ══════════════════════════ Le décompte de sortie ═══════════════════════════════

    /**
     * L'APERÇU N'ÉCRIT RIEN. Sans cela, le simple fait de regarder deviendrait un acte de
     * gestion, sur des jours qu'un solde de tout compte finit par payer.
     */
    public function testLApercuDeSortieNEcritAucunMouvement(): void
    {
        $c = $this->cabinet();
        $this->mouvement($c['alice'], $c['type'], 2026, MouvementConge::NATURE_DOTATION, 26.0);
        $this->em()->flush();

        $decompte = static::getContainer()->get(MouvementDuCompteur::class)->regulariserLaSortie(
            $c['alice'],
            new \DateTimeImmutable('2026-06-30'),
            $c['patron'],
            ecrire: false,
        );
        $this->em()->flush();

        self::assertNull($decompte['mouvement']);
        self::assertSame(
            1,
            $this->compterMouvements($c['entreprise'], 2026),
            "Seule la dotation semée doit exister : l'aperçu n'écrit pas.",
        );
    }

    /**
     * LE PRORATA DE SORTIE, et ce qu'il veut dire.
     *
     * Alice a reçu 26 j pour l'année entière et part au 30 juin : six mois de présence,
     * soit 13 j de droit. Les 13 j crédités en trop sont repris — et le solde final dit
     * qui doit quoi à qui.
     */
    public function testLeDecompteDeSortieRameneLaDotationAuProrataDesMoisDePresence(): void
    {
        $c = $this->cabinet();
        $this->mouvement($c['alice'], $c['type'], 2026, MouvementConge::NATURE_DOTATION, 26.0);
        $this->mouvement($c['alice'], $c['type'], 2026, MouvementConge::NATURE_PRISE, -5.0);
        $this->em()->flush();

        $decompte = static::getContainer()->get(MouvementDuCompteur::class)->regulariserLaSortie(
            $c['alice'],
            new \DateTimeImmutable('2026-06-30'),
            $c['patron'],
        );
        $this->em()->flush();

        self::assertSame(26.0, $decompte['dotationInitiale']);
        self::assertSame(13.0, $decompte['dotationProratisee'], 'Six mois de présence sur douze.');
        self::assertSame(-13.0, $decompte['regularisation']);
        self::assertSame(21.0, $decompte['soldeAvant']);
        self::assertSame(8.0, $decompte['soldeFinal'], 'Le cabinet lui doit encore 8 jours.');
        self::assertNotNull($decompte['mouvement']);
    }

    /**
     * LA RÉGULARISATION NE SUPPRIME RIEN : la dotation initiale reste au journal.
     *
     * C'est elle qui permettra d'expliquer, plus tard, pourquoi le solde final n'est pas
     * celui qu'on avait accordé en janvier.
     */
    public function testLaRegularisationSAjouteAuJournalSansEffacerLaDotation(): void
    {
        $c = $this->cabinet();
        $this->mouvement($c['alice'], $c['type'], 2026, MouvementConge::NATURE_DOTATION, 26.0);
        $this->em()->flush();

        static::getContainer()->get(MouvementDuCompteur::class)->regulariserLaSortie(
            $c['alice'],
            new \DateTimeImmutable('2026-03-31'),
            $c['patron'],
        );
        $this->em()->flush();
        $this->em()->clear();

        $alice = $this->em()->getRepository(Invite::class)->find($c['alice']->getId());
        $totaux = static::getContainer()->get(MouvementCongeRepository::class)->totauxParNature($alice, 2026);

        self::assertSame(26.0, $totaux[MouvementConge::NATURE_DOTATION] ?? 0.0, 'La dotation reste intacte.');
        self::assertSame(-19.5, $totaux[MouvementConge::NATURE_REGULARISATION_SORTIE] ?? 0.0);
        self::assertSame(6.5, static::getContainer()->get(CalculateurSolde::class)->pour($alice, 2026)->disponible());
    }

    /**
     * Quelqu'un arrivé ET parti dans le même exercice n'a droit qu'à l'intervalle : mars
     * à juin, et non mars à décembre. C'est la seconde formule de prorata, celle qui
     * borne des deux côtés.
     */
    public function testUneEntreeEtUneSortieDansLeMemeExerciceNeComptentQueLIntervalle(): void
    {
        self::assertSame(
            9.0,
            CongeParametres::dotationAuProrataDeSortie(
                26.0,
                new \DateTimeImmutable('2026-03-17'),
                new \DateTimeImmutable('2026-06-03'),
                2026,
            ),
            'Mars à juin inclus = 4 mois sur 12, arrondis au demi-jour supérieur.',
        );
    }

    // ═══════════════════════════════ L'export ═══════════════════════════════════════

    /**
     * L'export produit bien un classeur, avec l'en-tête de téléchargement qui va avec.
     *
     * On ne relit pas le binaire : ce qui compte ici est qu'un valideur reparte avec un
     * fichier et non avec une page d'erreur.
     */
    public function testLExportProduitUnClasseurTelechargeable(): void
    {
        $c = $this->cabinet();
        $this->mouvement($c['alice'], $c['type'], 2026, MouvementConge::NATURE_DOTATION, 26.0);
        $this->em()->flush();

        $grille = static::getContainer()->get(GrilleDesCompteurs::class)->pour($c['entreprise'], 2026);
        $reponse = static::getContainer()->get(CompteursExport::class)->classeur($grille, self::ENT);

        self::assertSame(200, $reponse->getStatusCode());
        self::assertStringContainsString('spreadsheet', (string) $reponse->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $reponse->headers->get('Content-Disposition'));

        ob_start();
        $reponse->sendContent();
        $contenu = (string) ob_get_clean();

        self::assertNotSame('', $contenu, 'Le classeur ne doit pas être vide.');
    }
}
