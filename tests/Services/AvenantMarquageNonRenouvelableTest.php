<?php

namespace App\Tests\Services;

use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use App\Service\Soa\SoaContextBuilder;
use App\Services\Avenant\MarquageNonRenouvelableService;
use App\Services\DashboardDataProvider;
use App\Services\Search\TranchePaiementScope;
use App\Services\Tranche\TranchePaiementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * MARQUER « NON RENOUVELABLE » RETIRE LA POLICE DU PIPELINE DE RENOUVELLEMENT, ET DE RIEN
 * D'AUTRE.
 *
 * C'est l'invariant le plus coûteux à violer de toute la fonctionnalité : une police
 * écartée des échéances dont on cesserait aussi de réclamer la prime, les commissions,
 * les taxes ou les rétrocommissions ferait perdre de l'argent DÉJÀ GAGNÉ, en silence, et
 * pour toujours — personne ne va chercher ce qu'on ne lui montre plus.
 *
 * Ces tests protègent, dans l'ordre :
 *
 *  1. le RECOUVREMENT : une tranche impayée reste réclamée après le marquage ;
 *  2. la COUVERTURE : une police marquée en cours de validité reste ACTIVE et sa prime
 *     reste dans les totaux — ce marquage n'est pas une résiliation ;
 *  3. le RETRAIT : la police revient intégralement, et la trace de la décision survit ;
 *  4. l'ÉTANCHÉITÉ CLIENT : le motif est une note interne, jamais rendue dans le relevé
 *     de compte servi au client par lien public.
 *
 * Le pipeline lui-même (chips, dashboard, vigie, boussole) est couvert par
 * AvenantSuccessionScopeTest, qui confronte les deux dialectes de la règle.
 */
class AvenantMarquageNonRenouvelableTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-marquagenr-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit MarquageNR SARL';

    /** Chaîne cherchée dans le HTML du relevé client : elle ne doit JAMAIS y apparaître. */
    private const MOTIF_INTERNE = 'PHPUnit-MOTIF-INTERNE le client paie toujours en retard';

    private Entreprise $entreprise;
    private Invite $invite;
    private Assureur $assureur;
    private Risque $risque;

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

    private function marquage(): MarquageNonRenouvelableService
    {
        return static::getContainer()->get(MarquageNonRenouvelableService::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $nom = self::ENTREPRISE_NOM;

        // Le double lien Avenant ↔ Piste forme un cycle de FK : dissocier avant de supprimer.
        $conn->executeStatement('UPDATE avenant a JOIN entreprise e ON a.entreprise_id = e.id SET a.piste_de_renouvellement_id = NULL WHERE e.nom = :n', ['n' => $nom]);
        $conn->executeStatement('UPDATE piste p JOIN entreprise e ON p.entreprise_id = e.id SET p.avenant_de_base_id = NULL WHERE e.nom = :n', ['n' => $nom]);
        // L'auteur du marquage pointe vers un invité : couper avant de supprimer les invités.
        $conn->executeStatement('UPDATE avenant a JOIN entreprise e ON a.entreprise_id = e.id SET a.non_renouvelable_par_id = NULL WHERE e.nom = :n', ['n' => $nom]);

        $conn->executeStatement('DELETE t FROM chargement_pour_prime t JOIN cotation c ON t.cotation_id = c.id JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => $nom]);
        foreach (['tranche', 'avenant', 'cotation', 'piste', 'chargement', 'assureur', 'client', 'risque', 'invite'] as $table) {
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

    /**
     * Deux polices porteuses d'une PRIME RÉELLE et d'une tranche impayée : sans montant, le
     * test du recouvrement passerait pour de mauvaises raisons.
     *
     * @return array{echue: int, enCours: int}
     */
    private function seed(): array
    {
        $em = $this->em();

        $user = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit MarquageNR')->setVerified(true);
        $user->setPassword('irrelevant');
        $em->persist($user);

        $this->entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-NR')->setAdresse('1 rue du Marquage')
            ->setTelephone('+243000000012')->setRccm('RCCM-NR')->setIdnat('IDNAT-NR')->setNumimpot('IMP-NR')
            ->setUtilisateur($user);
        $em->persist($this->entreprise);

        $this->invite = (new Invite())->setNom('Jean Kabila')->setUtilisateur($user)
            ->setEntreprise($this->entreprise)->setProprietaire(true);
        $em->persist($this->invite);

        $this->assureur = (new Assureur())->setNom('Assureur NR')->setEmail('nr@assureur.test')
            ->setNumimpot('IMP-N')->setIdnat('IDNAT-N')->setRccm('RCCM-N');
        $this->assureur->setEntreprise($this->entreprise);
        $em->persist($this->assureur);

        $this->risque = (new Risque())->setNomComplet('Risque NR')->setCode('NR-RQ')
            ->setDescription('Risque')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $this->risque->setEntreprise($this->entreprise)->setInvite($this->invite);
        $em->persist($this->risque);

        // Le TYPE « Prime nette » est l'assiette : sans lui, le chargement ne compte pas dans
        // la prime payable et la tranche serait « ni prime ni commission » — donc absente de
        // tout suivi d'impayés, et le test passerait pour de mauvaises raisons.
        $chargement = (new Chargement())->setNom('Prime nette')->setDescription('Assiette');
        $chargement->setEntreprise($this->entreprise);
        $em->persist($chargement);

        $echue   = $this->police('POL-NR-ECHUE', new \DateTimeImmutable('-10 days'), $chargement);
        $enCours = $this->police('POL-NR-EN-COURS', new \DateTimeImmutable('+90 days'), $chargement);

        $em->flush();
        $ids = ['echue' => $echue->getId(), 'enCours' => $enCours->getId()];

        // VIDER L'UNITÉ DE TRAVAIL avant de lire. Plusieurs setters du domaine sont
        // UNIDIRECTIONNELS (Piste::setClient, Cotation::setPiste…) : les collections
        // inverses restent vides en mémoire même après flush, et des services qui les
        // parcourent — le relevé de compte, par exemple — verraient un client sans
        // aucune police. Relire depuis la base met le test dans l'état d'une vraie requête.
        $em->clear();
        $this->entreprise = $em->getRepository(Entreprise::class)->find($this->entreprise->getId());
        $this->invite     = $em->getRepository(Invite::class)->find($this->invite->getId());

        return $ids;
    }

    /** Une police complète, avec prime de 1 000 et une tranche unique NON payée. */
    private function police(string $ref, \DateTimeImmutable $endingAt, Chargement $chargement): Avenant
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

        // setCotation() sur le chargement (et non addChargement) : c'est le côté PROPRIÉTAIRE
        // de la relation, seul à persister le lien — sans quoi la prime resterait à zéro et
        // la tranche « ni prime ni commission », donc invisible de tout suivi d'impayés.
        $cpp = (new ChargementPourPrime())->setNom('Prime ' . $ref)
            ->setMontantFlatExceptionel(1000.0)->setType($chargement);
        $cpp->setEntreprise($this->entreprise);
        $cotation->addChargement($cpp);
        $em->persist($cpp);

        // Tranche échue et jamais réglée : c'est elle qui doit rester réclamée.
        $tranche = (new Tranche())->setNom('Tranche unique ' . $ref)->setPourcentage(100)
            ->setPayableAt(new \DateTimeImmutable('-60 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-30 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($this->entreprise);
        $em->persist($tranche);

        // GOTCHA : Avenant::setCotation() est UNIDIRECTIONNEL ; le helper bidirectionnel est
        // Cotation::addAvenant(). Sans lui, $cotation->getAvenants() reste vide en mémoire,
        // la cotation n'est pas « bound » — et le statut de paiement d'une tranche vaut alors
        // 'N/A' (une proposition non validée n'est qu'un projet), ce qui la sortirait de tout
        // suivi d'impayés pour une raison qui n'a rien à voir avec le marquage.
        $avenant = (new Avenant())->setReferencePolice($ref)->setNumero('0')
            ->setDescription('Avenant ' . $ref)
            ->setStartingAt($endingAt->modify('-365 days'))->setEndingAt($endingAt);
        $avenant->setEntreprise($this->entreprise)->setInvite($this->invite);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        return $avenant;
    }

    private function avenant(int $id): Avenant
    {
        return $this->em()->getRepository(Avenant::class)->find($id);
    }

    /** @return array<int, int> ids des avenants dont une tranche est réclamée comme impayée. */
    private function avenantsAvecImpayes(): array
    {
        /** @var TranchePaiementService $service */
        $service = static::getContainer()->get(TranchePaiementService::class);
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($this->entreprise->getId());

        $resultat = $service->lister(
            $entreprise,
            [TranchePaiementScope::AXE_PRIME => TranchePaiementScope::IMPAYEE],
            null,
            null,
            1,
            100,
        );

        $ids = [];
        foreach ($resultat['items'] ?? [] as $tranche) {
            foreach ($tranche->getCotation()?->getAvenants() ?? [] as $avenant) {
                $ids[] = $avenant->getId();
            }
        }

        return $ids;
    }

    // ------------------------------------------------------------------ tests

    /**
     * L'INVARIANT. Une police signalée non renouvelable quitte le suivi des échéances, mais
     * sa prime impayée reste réclamée. Les deux affirmations sont vérifiées dans le MÊME
     * test : c'est leur coexistence qui est la promesse faite à l'utilisateur.
     */
    public function testUnePoliceMarqueeQuitteLesEcheancesMaisResteReclameeAuRecouvrement(): void
    {
        $s = $this->seed();
        /** @var DashboardDataProvider $dashboard */
        $dashboard = static::getContainer()->get(DashboardDataProvider::class);
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($this->entreprise->getId());

        $avantEcheances = array_map(
            static fn (Avenant $a) => $a->getId(),
            $dashboard->getAllRenouvellements($entreprise, 365)
        );
        $this->assertContains($s['echue'], $avantEcheances, 'Point de départ : la police est bien réclamée.');
        $this->assertContains($s['echue'], $this->avenantsAvecImpayes(), 'Point de départ : sa prime est due.');

        $this->marquage()->marquer($this->avenant($s['echue']), 'Le client a vendu le véhicule.', $this->invite);
        $this->em()->flush();
        $this->em()->clear();

        $entreprise = $this->em()->getRepository(Entreprise::class)->find($this->entreprise->getId());
        $apresEcheances = array_map(
            static fn (Avenant $a) => $a->getId(),
            $dashboard->getAllRenouvellements($entreprise, 365)
        );
        $this->assertNotContains($s['echue'], $apresEcheances, 'Marquée : plus réclamée comme renouvellement.');

        $this->assertContains(
            $s['echue'],
            $this->avenantsAvecImpayes(),
            'LE POINT CRITIQUE : la prime due reste réclamée. Une police écartée des échéances dont on '
            . 'cesserait de réclamer l’argent ferait perdre une recette déjà gagnée, en silence.'
        );
    }

    /**
     * LE SERVICE ÉNONCE CE QUI RESTE DÛ, pour que ni le picker ni l'assistante ne laissent
     * croire que le dossier est clos. C'est ce texte qui s'affiche AVANT confirmation.
     */
    public function testLeServiceAvertitDeCeQuiResteARecouvrer(): void
    {
        $s = $this->seed();

        $avertissements = $this->marquage()->avertissements($this->avenant($s['echue']));

        $this->assertNotEmpty($avertissements, 'Une prime impayée doit produire un avertissement.');
        $this->assertStringContainsString('prime', implode(' ', $avertissements));
        $this->assertStringContainsString('reste actif', implode(' ', $avertissements));
    }

    /**
     * CE MARQUAGE N'EST PAS UNE RÉSILIATION. Une police marquée qui n'a pas atteint son
     * terme couvre toujours l'assuré : renewalStatus n'est pas touché et elle reste comptée
     * parmi les polices actives, donc sa prime reste dans les totaux.
     */
    public function testUnePoliceMarqueeEnCoursDeCouvertureResteActive(): void
    {
        $s = $this->seed();
        /** @var DashboardDataProvider $dashboard */
        $dashboard = static::getContainer()->get(DashboardDataProvider::class);
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($this->entreprise->getId());

        $activesAvant = $dashboard->getPoliciesActives($entreprise);

        $this->marquage()->marquer($this->avenant($s['enCours']), 'Le client cesse son activité.', $this->invite);
        $this->em()->flush();
        $this->em()->clear();

        $entreprise = $this->em()->getRepository(Entreprise::class)->find($this->entreprise->getId());
        $this->assertSame(
            $activesAvant,
            $dashboard->getPoliciesActives($entreprise),
            'Le marquage annonce l’absence de SUITE ; il n’interrompt pas la couverture en cours.'
        );
        $this->assertSame(
            Avenant::RENEWAL_STATUS_RUNNING,
            $this->avenant($s['enCours'])->getRenewalStatus(),
            'renewalStatus pilote les polices actives et les totaux de primes : il ne doit pas bouger.'
        );
    }

    /**
     * LE MOTIF S'AFFINE SANS ÉCRASER LA DÉCISION. Entre le jour où le client annonce et
     * l'échéance, l'explication se précise ; la corriger ne doit pas déplacer la date de la
     * décision ni changer son auteur.
     */
    public function testCorrigerLeMotifNeDeplacePasLaDecision(): void
    {
        $s = $this->seed();
        $avenant = $this->avenant($s['echue']);

        $this->marquage()->marquer($avenant, 'Motif initial.', $this->invite);
        $this->em()->flush();
        $decideeLe = $avenant->getNonRenouvelableLe();

        $this->marquage()->modifierMotif($avenant, 'Motif précisé après échange avec le client.');
        $this->em()->flush();

        $this->assertSame('Motif précisé après échange avec le client.', $avenant->getNonRenouvelableMotif());
        $this->assertEquals($decideeLe, $avenant->getNonRenouvelableLe());
        $this->assertSame($this->invite->getId(), $avenant->getNonRenouvelablePar()?->getId());
    }

    /**
     * LE MOTIF EST OBLIGATOIRE. Un marquage sans raison est un trou dans le pipeline que
     * personne ne saura interpréter dans six mois — c'est la note, pas le drapeau, qui a de
     * la valeur.
     */
    public function testUnMarquageSansMotifEstRefuse(): void
    {
        $s = $this->seed();

        $this->expectException(\InvalidArgumentException::class);
        $this->marquage()->marquer($this->avenant($s['echue']), "   \n ", $this->invite);
    }

    /**
     * LE RETRAIT REND LA POLICE AU PIPELINE et CONSERVE la trace. Effacer le motif à la
     * levée supprimerait exactement ce que ce dispositif existe pour garder.
     */
    public function testLeRetraitRendLaPoliceAuxEcheancesSansEffacerLaTrace(): void
    {
        $s = $this->seed();
        /** @var DashboardDataProvider $dashboard */
        $dashboard = static::getContainer()->get(DashboardDataProvider::class);
        $avenant = $this->avenant($s['echue']);

        $this->marquage()->marquer($avenant, 'Le client annonçait son départ.', $this->invite);
        $this->em()->flush();
        $this->marquage()->lever($avenant);
        $this->em()->flush();
        $this->em()->clear();

        $entreprise = $this->em()->getRepository(Entreprise::class)->find($this->entreprise->getId());
        $this->assertContains(
            $s['echue'],
            array_map(static fn (Avenant $a) => $a->getId(), $dashboard->getAllRenouvellements($entreprise, 365)),
            'Marquage levé : la police est de nouveau réclamée, sans aucun code de restauration.'
        );

        $releve = $this->avenant($s['echue']);
        $this->assertFalse($releve->isNonRenouvelable());
        $this->assertNotNull($releve->getNonRenouvelableLeveLe());
        $this->assertSame('Le client annonçait son départ.', $releve->getNonRenouvelableMotif());
        $this->assertNotNull($releve->getNonRenouvelablePar());
    }

    /**
     * ÉTANCHÉITÉ CLIENT. Le relevé de compte (SOA) est servi au client par un lien public,
     * et il rend déjà les avenants avec leur statut. Le motif, lui, est une note INTERNE
     * (« part à la concurrence », « paie toujours en retard ») : l'envoyer serait une fuite,
     * et potentiellement une perte de client.
     *
     * Ce test existe pour l'itération future qui voudra « compléter le tableau », pas pour
     * aujourd'hui : c'est exactement le genre de champ qu'on ajoute sans y penser.
     */
    public function testLeMotifNApparaitJamaisDansLeReleveServiAuClient(): void
    {
        $s = $this->seed();
        $avenant = $this->avenant($s['echue']);
        $this->marquage()->marquer($avenant, self::MOTIF_INTERNE, $this->invite);
        $this->em()->flush();

        $client = $avenant->getCotation()?->getPiste()?->getClient();
        $this->assertNotNull($client);

        // Le VRAI contexte du relevé, en vue CLIENT — celui que sert /soa/{token}. Rendre un
        // contexte fabriqué à la main ne prouverait rien : c'est justement ce que le
        // constructeur y met qui doit être surveillé.
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($this->entreprise->getId());
        $contexte = static::getContainer()->get(SoaContextBuilder::class)
            ->build($client, $entreprise, $this->invite, vueClient: true);

        $this->assertNotEmpty($contexte['polices'] ?? [], 'La police doit bien figurer au relevé.');

        $html = static::getContainer()->get('twig')->render('admin/soa/_soa_sections.html.twig', $contexte);

        $this->assertStringNotContainsString(self::MOTIF_INTERNE, $html, 'Le motif est une note INTERNE.');
        $this->assertStringNotContainsString('Non renouvelable', $html, 'Le badge non plus ne sort pas.');
        $this->assertStringNotContainsString('Jean Kabila', $html, "L'auteur de la décision non plus.");
    }
}
