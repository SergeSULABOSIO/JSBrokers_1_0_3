<?php

namespace App\Tests\Workspace;

use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\PartenaireIndicatorStrategy;
use App\Services\Canvas\Provider\List\PartenaireListCanvasProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CE QUI RESTE DÛ À CHAQUE INTERMÉDIAIRE, VISIBLE EN BOUT DE LIGNE.
 *
 * La rubrique Intermédiaires affichait ce que le partenaire a produit (assiette), ce qu'il
 * a gagné (rétro-commission) et ce qu'il a touché (rétro. payée) — mais jamais la
 * différence. Pour savoir ce que le cabinet lui doit ENCORE, il fallait ouvrir sa fiche et
 * soustraire de tête, ligne par ligne. La colonne « Rétro. Solde » ferme cet écart.
 *
 * ── DRY : AUCUNE SOUSTRACTION N'EST ÉCRITE DANS LA LISTE ────────────────────────────
 * `IndicatorCalculationHelper` publie déjà `retro_commission_partenaire_solde`, que
 * `PartenaireIndicatorStrategy` expose en `retroCommissionSolde`. La liste ne fait que le
 * DÉCLARER. Ce test tient donc l'identité solde = due − payée à la source, puis le contrat
 * de la liste : une colonne déclarée doit être alimentée, sinon la cellule affiche « — »
 * sans que rien ne le signale.
 */
class PartenaireColonneSoldeTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-partcol-owner@test.local';
    private const ENT = 'PHPUnit PartenaireColonne SARL';

    private const COMMISSION = 1000.0;
    private const PART = 20.0;
    private const DUE = self::COMMISSION * self::PART / 100; // 200

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

    private function indicateurs(int $partenaireId): array
    {
        $this->em()->clear();
        $partenaire = $this->em()->getRepository(Partenaire::class)->find($partenaireId);

        return static::getContainer()->get(PartenaireIndicatorStrategy::class)->calculate($partenaire);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        foreach ([
            'reversement_retro_agent', 'tranche', 'avenant', 'revenu_pour_courtier', 'type_revenu',
            'chargement_pour_prime', 'cotation', 'piste', 'client', 'assureur', 'partenaire',
            'risque', 'invite',
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

    // ===================== Le solde, à la source =====================

    /** Rien de reversé : le cabinet doit encore TOUTE la rétro-commission. */
    public function testSansReversementLeSoldeEstLaRetroEntiere(): void
    {
        $ids = $this->semer();
        $chiffres = $this->indicateurs($ids['partenaireId']);

        self::assertEqualsWithDelta(self::DUE, $chiffres['retroCommission'], 0.01);
        self::assertEqualsWithDelta(0.0, $chiffres['retroCommissionReversee'], 0.01);
        self::assertEqualsWithDelta(self::DUE, $chiffres['retroCommissionSolde'], 0.01);
    }

    /** Un versement partiel : le solde est ce qui reste, et rien d'autre. */
    public function testUnVersementPartielLaisseLeResteAuSolde(): void
    {
        $ids = $this->semer();
        $this->reverser($ids, 120.0);

        $chiffres = $this->indicateurs($ids['partenaireId']);

        self::assertEqualsWithDelta(120.0, $chiffres['retroCommissionReversee'], 0.01);
        self::assertEqualsWithDelta(self::DUE - 120.0, $chiffres['retroCommissionSolde'], 0.01);
    }

    /** Payé jusqu'au dernier franc : la ligne s'éteint à zéro. */
    public function testUneDetteSoldeeAfficheZero(): void
    {
        $ids = $this->semer();
        $this->reverser($ids, self::DUE);

        $chiffres = $this->indicateurs($ids['partenaireId']);

        self::assertEqualsWithDelta(0.0, $chiffres['retroCommissionSolde'], 0.01);
    }

    /**
     * L'IDENTITÉ QUI INTERDIT DE RECOPIER LA FORMULE AILLEURS : le solde EST la différence.
     * Si quelqu'un la réécrit un jour dans la liste, cette égalité le dira.
     */
    public function testLeSoldeEstExactementLaDifference(): void
    {
        $ids = $this->semer();
        $this->reverser($ids, 75.0);

        $chiffres = $this->indicateurs($ids['partenaireId']);

        self::assertEqualsWithDelta(
            $chiffres['retroCommission'] - $chiffres['retroCommissionReversee'],
            $chiffres['retroCommissionSolde'],
            0.01,
        );
    }

    // ===================== Le contrat de la liste =====================

    /** La colonne est bien DÉCLARÉE dans la rubrique, et lue comme un montant. */
    public function testLaColonneSoldeEstDeclareeDansLaRubrique(): void
    {
        $canvas = static::getContainer()->get(PartenaireListCanvasProvider::class)->getCanvas();
        $codes = array_column($canvas['colonnes_numeriques'], 'attribut_code');

        self::assertContains('retroCommissionSolde', $codes, 'La colonne du solde doit rester en bout de ligne.');

        // Elle vient APRÈS le payé : due ▸ payée ▸ reste. L'œil lit la soustraction.
        self::assertGreaterThan(
            array_search('retroCommissionReversee', $codes, true),
            array_search('retroCommissionSolde', $codes, true),
        );

        $solde = $canvas['colonnes_numeriques'][array_search('retroCommissionSolde', $codes, true)];
        self::assertSame('nombre', $solde['attribut_type']);
        self::assertNotSame('%', $solde['attribut_unité'], 'Un solde est un montant, pas un taux.');
    }

    /** Chaque colonne déclarée doit être alimentée, sinon la cellule affiche « — » en silence. */
    public function testToutesLesColonnesDeclareesSontAlimentees(): void
    {
        $ids = $this->semer();
        $chiffres = $this->indicateurs($ids['partenaireId']);

        $canvas = static::getContainer()->get(PartenaireListCanvasProvider::class)->getCanvas();
        foreach (array_column($canvas['colonnes_numeriques'], 'attribut_code') as $code) {
            self::assertArrayHasKey(
                $code,
                $chiffres,
                sprintf('La colonne « %s » est déclarée mais rien ne l\'alimente.', $code),
            );
        }
    }

    // ===================== Décor =====================

    /**
     * Une affaire souscrite, apportée par le partenaire, avec UNE échéance.
     *
     * @return array{entrepriseId:int, partenaireId:int, trancheId:int, avenantId:int, inviteId:int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('PartCol')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire PartCol')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $assureur = (new Assureur())->setNom('Assureur PartCol')->setEmail('assureur-partcol@test.local')
            ->setNumimpot('IMP')->setIdnat('IDNAT')->setRccm('RCCM');
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        $partenaire = (new Partenaire())->setNom('Apporteur PartCol')->setPart(self::PART);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $risque = (new Risque())->setCode('PC')->setNomComplet('Risque PartCol')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client PartCol')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Affaire PartCol')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($gestionnaire)->setPartenaire($partenaire);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation PartCol')->setDuree(365);
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

        $avenant = (new Avenant())->setReferencePolice('POL-PARTCOL')->setNumero('0')->setDescription('Police')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $em->flush();
        $ids = [
            'entrepriseId' => (int) $entreprise->getId(),
            'partenaireId' => (int) $partenaire->getId(),
            'trancheId'    => (int) $tranche->getId(),
            'avenantId'    => (int) $avenant->getId(),
            'inviteId'     => (int) $gestionnaire->getId(),
        ];
        $em->clear();

        return $ids;
    }

    /** Le cabinet reverse au partenaire, à la maille de l'échéance. */
    private function reverser(array $ids, float $montant): void
    {
        $em = $this->em();
        $r = (new ReversementRetroAgent())
            ->setPartenaire($em->getRepository(Partenaire::class)->find($ids['partenaireId']))
            ->setTranche($em->getRepository(Tranche::class)->find($ids['trancheId']))
            ->setAvenant($em->getRepository(Avenant::class)->find($ids['avenantId']))
            ->setMontant($montant)
            ->setPaidAt(new \DateTimeImmutable('-2 days'))
            ->setReference('VIR-PARTCOL');
        $r->setEntreprise($em->getRepository(Entreprise::class)->find($ids['entrepriseId']))
            ->setInvite($em->getRepository(Invite::class)->find($ids['inviteId']));
        $em->persist($r);
        $em->flush();
        $em->clear();
    }
}
