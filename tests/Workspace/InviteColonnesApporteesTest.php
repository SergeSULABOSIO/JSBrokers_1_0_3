<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\InviteIndicatorStrategy;
use App\Services\Canvas\Provider\List\InviteListCanvasProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LES CHIFFRES D'UN INVITÉ SONT CEUX DE SON EFFORT COMMERCIAL, PAS DE SA GESTION.
 *
 * Toutes les colonnes numériques de la rubrique Invités — prime, commission, commission
 * pure, réserve, et les trois de rétrocommission — décrivent les affaires que l'invité a
 * APPORTÉES : celles où une condition de partage le désigne bénéficiaire. Jamais celles
 * qu'il suit comme gestionnaire de compte.
 *
 * ── POURQUOI CE TEST EXISTE ─────────────────────────────────────────────────────────
 * La première version mêlait les deux périmètres : quatre colonnes sur la production
 * GÉRÉE, trois sur la rétro PERÇUE. Un gestionnaire de compte affichait donc des millions
 * de prime — le résultat commercial de ses collègues — juste à côté d'une rétrocommission
 * à zéro. La ligne était illisible et, pire, flatteuse pour qui n'avait rien apporté.
 *
 * Le décor : Gaston GÈRE l'affaire, Alice l'a APPORTÉE. La ligne de Gaston doit être
 * entièrement à zéro, celle d'Alice porter toute la production.
 */
class InviteColonnesApporteesTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-invcol-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit InviteColonnes SARL';

    private const PRIME = 4000.0;
    private const COMMISSION = 800.0;
    private const TAUX_ALICE = 25.0;

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

    private function indicateurs(int $inviteId): array
    {
        $invite = $this->em()->getRepository(Invite::class)->find($inviteId);

        return static::getContainer()->get(InviteIndicatorStrategy::class)->calculate($invite);
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
            'condition_partage', 'avenant', 'revenu_pour_courtier', 'type_revenu',
            'chargement_pour_prime', 'cotation', 'piste', 'client', 'risque', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    public function testLApporteurPorteTouteLaProductionDeSonAffaire(): void
    {
        $ids = $this->semer();
        $chiffres = $this->indicateurs($ids['aliceId']);

        self::assertEqualsWithDelta(self::PRIME, $chiffres['primeTotale'], 0.01);
        self::assertEqualsWithDelta(self::COMMISSION, $chiffres['montantTTC'], 0.01);
        self::assertEqualsWithDelta(self::COMMISSION, $chiffres['montantPur'], 0.01);

        // 25 % de la commission pure lui reviennent ; la réserve est ce qui reste au cabinet.
        self::assertEqualsWithDelta(self::COMMISSION * 0.25, $chiffres['retroAgentDue'], 0.01);
        self::assertEqualsWithDelta(self::COMMISSION * 0.75, $chiffres['reserve'], 0.01);
        self::assertEqualsWithDelta(0.0, $chiffres['retroAgentPayee'], 0.01);
        self::assertEqualsWithDelta($chiffres['retroAgentDue'], $chiffres['retroAgentSolde'], 0.01);

        self::assertTrue($chiffres['hasRetroAgent'], 'Les actions rapport/reversement doivent être offertes.');
    }

    public function testLeGestionnaireQuiNaRienApporteAfficheZeroPARTOUT(): void
    {
        $ids = $this->semer();
        $chiffres = $this->indicateurs($ids['gastonId']);

        // Gaston gère l'affaire — c'est SA piste — mais il ne l'a pas apportée. Aucune
        // colonne ne doit lui attribuer le résultat commercial d'Alice.
        foreach ([
            'primeTotale', 'montantTTC', 'montantPur', 'reserve',
            'retroAgentDue', 'retroAgentPayee', 'retroAgentSolde',
        ] as $colonne) {
            self::assertEqualsWithDelta(
                0.0,
                $chiffres[$colonne],
                0.01,
                sprintf('« %s » doit être à zéro : Gaston gère, il n\'apporte pas.', $colonne),
            );
        }

        self::assertFalse($chiffres['hasRetroAgent']);
    }

    public function testLesSeptColonnesDeclareesSontToutesAlimentees(): void
    {
        $ids = $this->semer();
        $chiffres = $this->indicateurs($ids['aliceId']);

        $canvas = static::getContainer()->get(InviteListCanvasProvider::class)->getCanvas();
        $codes = array_column($canvas['colonnes_numeriques'], 'attribut_code');

        self::assertCount(7, $codes);
        foreach ($codes as $code) {
            // Le contrat de la liste : un `attribut_code` déclaré doit exister dans les
            // indicateurs, sinon la cellule affiche « — » sans que rien ne le signale.
            self::assertArrayHasKey(
                $code,
                $chiffres,
                sprintf('La colonne « %s » est déclarée mais rien ne l\'alimente.', $code),
            );
        }
    }

    /**
     * Une affaire souscrite : Gaston la gère (Piste::invite), Alice en est la
     * bénéficiaire (condition de partage rattachée à la piste).
     *
     * @return array{gastonId:int, aliceId:int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('InviteColonnes Owner')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $gaston = (new Invite())->setNom('Gaston le gestionnaire')->setProprietaire(true);
        $gaston->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gaston);

        $alice = (new Invite())->setNom('Alice l\'apporteuse')->setProprietaire(false);
        $alice->setEntreprise($entreprise);
        $em->persist($alice);

        $risque = (new Risque())->setCode('RI')->setNomComplet('Risque Invité')->setDescription('Risque')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client Invité')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste Invité')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque invité')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        // ⚠ Le GESTIONNAIRE, et lui seul, est l'invité de la piste.
        $piste->setEntreprise($entreprise)->setInvite($gaston);
        $em->persist($piste);

        $condition = (new ConditionPartage())->setNom('Prime apporteur Alice')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(self::TAUX_ALICE)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($alice);
        $condition->setEntreprise($entreprise);
        $em->persist($condition);
        $piste->addConditionsPartageAgent($condition);

        $cotation = (new Cotation())->setNom('Cotation Invité')->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime')->setMontantFlatExceptionel(self::PRIME);
        $chargement->setEntreprise($entreprise);
        $cotation->addChargement($chargement);
        $em->persist($chargement);

        $typeRevenu = (new TypeRevenu())->setNom('Commission')->setMontantflat(self::COMMISSION)
            ->setShared(false)->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())->setNom('Revenu')->setTypeRevenu($typeRevenu)->setCotation($cotation);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        $avenant = (new Avenant())->setReferencePolice('POL-INVCOL')->setNumero('0')
            ->setDescription('Police invité')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))
            ->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($gaston);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $em->flush();
        $ids = ['gastonId' => (int) $gaston->getId(), 'aliceId' => (int) $alice->getId()];
        $em->clear();

        return $ids;
    }
}
