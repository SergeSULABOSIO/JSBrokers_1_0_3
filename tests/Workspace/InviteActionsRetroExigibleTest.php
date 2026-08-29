<?php

namespace App\Tests\Workspace;

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
use App\Services\Canvas\Indicator\InviteIndicatorStrategy;
use App\Services\Canvas\Provider\Form\InviteFormCanvasProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * PAYER UN AGENT SE FAIT DEPUIS LA LISTE, PAS SEULEMENT DEPUIS SA FICHE.
 *
 * Les deux actions — « Voir le rapport de production » et « Signaler un reversement » —
 * étaient déclarées dans `attribute_actions`, que la barre d'outils ET le menu contextuel
 * savent pourtant afficher. Elles n'y paraissaient jamais.
 *
 * ── LA PANNE, ET POURQUOI ELLE ÉTAIT MUETTE ─────────────────────────────────────────
 * Leur condition portait sur une valeur calculée posée en propriété DYNAMIQUE. Une telle
 * propriété n'appartient à aucun groupe de sérialisation : elle est donc absente du
 * `data-entity` de la ligne. Côté navigateur, la condition s'évalue contre `undefined`,
 * la comparaison échoue, et l'action est filtrée — sans erreur, sans trace. Seule la fiche
 * ouverte, qui reçoit l'entité par un autre chemin, les montrait.
 *
 * Ce test verrouille donc le maillon invisible : le drapeau doit figurer dans la ligne
 * SÉRIALISÉE. C'est lui, et rien d'autre, qui rend les deux actions joignables depuis la
 * barre d'outils et le clic droit.
 *
 * ── ET IL DOIT ÊTRE EXIGEANT ────────────────────────────────────────────────────────
 * « Dû » ne suffit pas : les actions ne paraissent que si quelque chose est EXIGIBLE,
 * c'est-à-dire réclamable aujourd'hui — le cabinet a encaissé sa commission, la dette
 * envers l'agent est née. Ouvrir un sélecteur de reversement qui n'aurait aucune ligne à
 * proposer serait une impasse polie.
 */
class InviteActionsRetroExigibleTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-actions-retro@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit ActionsRetro SARL';

    private const COMMISSION = 1000.0;
    private const TAUX_AGENT = 15.0;

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
        foreach ([
            'paiement', 'article', 'note', 'avenant', 'revenu_pour_courtier',
            'chargement_pour_prime', 'tranche', 'cotation', 'condition_partage', 'piste',
            'client', 'assureur', 'risque', 'type_revenu', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Une affaire souscrite dont Alice est BÉNÉFICIAIRE.
     *
     * @param bool   $encaissee la commission est-elle perçue par le cabinet ? C'est ce qui
     *                          fait passer la rétro de « due » à « exigible »
     * @param string $porteur   'partagee' : condition rattachée à la piste (cas courant) ;
     *                          'propre'   : condition exceptionnelle, propre à l'affaire
     *
     * @return array{entrepriseId:int, agentId:int}
     */
    private function semer(bool $encaissee, string $porteur = 'partagee'): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('ActionsRetro')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $alice = (new Invite())->setNom('Alice')->setProprietaire(false);
        $alice->setEntreprise($entreprise);
        $em->persist($alice);

        $assureur = (new Assureur())->setNom('Assureur Actions')->setEmail('assureur-actions@test.local')
            ->setNumimpot('IMP-A')->setIdnat('IDNAT-A')->setRccm('RCCM-A');
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        $risque = (new Risque())->setCode('RC')->setNomComplet('Risque Actions')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client Actions')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste Actions')->setTypeAvenant(0)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($gestionnaire);
        $em->persist($piste);

        $condition = (new ConditionPartage())->setNom('Part d\'Alice')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(self::TAUX_AGENT)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($alice);
        $condition->setEntreprise($entreprise);
        // DEUX FAÇONS D'ÊTRE DÉSIGNÉ, et le moteur doit voir les deux.
        if ($porteur === 'propre') {
            $condition->setPiste($piste);
        } else {
            $piste->addConditionsPartageAgent($condition);
        }
        $em->persist($condition);

        $cotation = (new Cotation())->setNom('Cotation Actions')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($entreprise);
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

        $tranche = (new Tranche())->setNom('Tranche unique')->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('-30 days'))
            ->setEcheanceAt(new \DateTimeImmutable('+30 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);

        $avenant = (new Avenant())->setReferencePolice('POL-ACT')->setNumero('0')
            ->setDescription('Police actions')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))
            ->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        if ($encaissee) {
            // La commission est facturée à l'assureur ET réglée : la dette du cabinet
            // envers son agent naît à cet instant, pas avant.
            $note = (new Note())->setNom('Note commission Actions')->setType(0)
                ->setAddressedTo(Note::TO_ASSUREUR)->setReference('N-ACT-1')
                ->setValidated(true)->setSignature('')
                ->setAssureur($assureur);
            $note->setEntreprise($entreprise);
            $em->persist($note);

            $article = (new Article())->setNote($note)->setRevenuFacture($revenu)->setTranche($tranche);
            $article->setEntreprise($entreprise);
            $em->persist($article);

            $reglement = (new Paiement())->setMontant(self::COMMISSION)->setReference('ENC-ACT-1')
                ->setPaidAt(new \DateTimeImmutable('-5 days'))
                ->setNote($note);
            $reglement->setEntreprise($entreprise);
            $em->persist($reglement);
        }

        $em->flush();
        $ids = ['entrepriseId' => (int) $entreprise->getId(), 'agentId' => (int) $alice->getId()];
        $em->clear();

        return $ids;
    }

    /** @return array<string, mixed> */
    private function ficheDe(int $agentId): array
    {
        $agent = $this->em()->getRepository(Invite::class)->find($agentId);

        return static::getContainer()->get(InviteIndicatorStrategy::class)->calculate($agent);
    }

    // ===================== Le drapeau =====================

    public function testUnDuNonEncaisseNOuvreAucuneAction(): void
    {
        $ids = $this->semer(false);
        $fiche = $this->ficheDe($ids['agentId']);

        // La somme est DUE — elle figure sur sa ligne — mais rien n'est encore réclamable.
        self::assertGreaterThan(0.0, (float) $fiche['retroAgentDue']);
        self::assertSame(0.0, (float) $fiche['retroAgentExigible']);
        self::assertFalse($fiche['hasRetroAgentExigible'], 'Rien d\'encaissé : rien à verser.');
    }

    public function testUneFoisLaCommissionEncaisseeLesActionsSOuvrent(): void
    {
        $ids = $this->semer(true);
        $fiche = $this->ficheDe($ids['agentId']);

        // 15 % du reliquat — ici la commission pure entière, aucun intermédiaire externe.
        self::assertGreaterThan(0.0, (float) $fiche['retroAgentExigible']);
        self::assertTrue($fiche['hasRetroAgentExigible']);
    }

    // ===================== Le maillon invisible =====================

    public function testLeDrapeauVoyageJusquADansLaLigneSERIALISEE(): void
    {
        $ids = $this->semer(true);

        $agent = $this->em()->getRepository(Invite::class)->find($ids['agentId']);
        static::getContainer()->get(\App\Services\CanvasBuilder::class)->loadAllCalculatedValues($agent);

        /** @var SerializerInterface $serializer */
        $serializer = static::getContainer()->get(SerializerInterface::class);
        $ligne = json_decode(
            $serializer->serialize($agent, 'json', ['groups' => 'list:read', 'enable_max_depth' => true]),
            true,
        );

        // C'EST TOUT LE TEST. Le gabarit de ligne sérialise l'entité dans le groupe
        // « list:read » et le navigateur évalue la condition contre CE json. Une valeur
        // calculée qui n'y figure pas rend l'action invisible, en silence.
        self::assertArrayHasKey('hasRetroAgentExigible', $ligne);
        self::assertTrue($ligne['hasRetroAgentExigible']);
    }

    public function testLesDeuxActionsSontConditionneesParCeDrapeau(): void
    {
        $ids = $this->semer(true);
        $agent = $this->em()->getRepository(Invite::class)->find($ids['agentId']);

        $canvas = static::getContainer()->get(InviteFormCanvasProvider::class)
            ->getCanvas($agent, $ids['entrepriseId']);
        $actions = $canvas['parametres']['attribute_actions'] ?? [];

        // LES DEUX ACTIONS NE PARTAGENT PLUS LA MÊME RACINE D'URL, et c'est le seul
        // changement : le rapport de production est devenu la rubrique « Production
        // intermédiaires », le reversement est resté où il était. On les désigne donc par
        // leur ÉVÉNEMENT — ce qu'elles font — plutôt que par le chemin de leur route, qui
        // n'était qu'un raccourci commode.
        $retro = array_values(array_filter(
            $actions,
            static fn (array $a) => in_array(
                (string) ($a['event'] ?? ''),
                ['ui:production.rubrique-request', 'ui:retroagent.reversement-request'],
                true,
            ),
        ));

        self::assertCount(2, $retro, 'La production et le reversement, et eux seuls.');
        foreach ($retro as $action) {
            self::assertSame('hasRetroAgentExigible', $action['condition']['field'] ?? null);
            self::assertTrue($action['condition']['value'] ?? null);
        }
    }

    // ===================== Le trou que la condition aurait laissé =====================

    public function testUnAgentPAYEparUneConditionPROPREaLAffaireCompteAussi(): void
    {
        $ids = $this->semer(true, 'propre');

        $agent = $this->em()->getRepository(Invite::class)->find($ids['agentId']);
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($ids['entrepriseId']);
        $agregat = static::getContainer()->get(IndicatorCalculationHelper::class)
            ->getIndicateursGlobaux($entreprise, false, ['agentCible' => $agent]);

        // Une condition d'agent peut être PARTAGÉE (rattachée à plusieurs affaires) ou
        // PROPRE à une affaire. L'agrégat ne voyait que la première : la fiche d'un agent
        // rémunéré par la seconde annonçait « rien » pendant que son rapport de production
        // chiffrait la somme — et le bouton pour le payer restait caché.
        self::assertGreaterThan(0.0, (float) $agregat['retro_commission_agent']);
        self::assertGreaterThan(0.0, (float) $agregat['retro_commission_agent_exigible']);
        self::assertTrue($this->ficheDe($ids['agentId'])['hasRetroAgentExigible']);
    }
}
