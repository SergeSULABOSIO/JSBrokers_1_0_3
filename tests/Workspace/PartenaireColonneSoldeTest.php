<?php

namespace App\Tests\Workspace;

use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
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
 * ── ET CE QU'IL FAUT PAYER MAINTENANT ───────────────────────────────────────────────
 * Le solde ne dit pas s'il faut virer aujourd'hui : une part de la dette n'est pas encore
 * réclamable, parce que le cabinet n'a pas encaissé la commission qui la justifie. Payer
 * dessus, c'est avancer sa trésorerie sur une créance non recouvrée. La colonne
 * « Rétro. Exigible » isole donc la part que l'argent RENTRÉ a fait naître — au prorata,
 * échéance par échéance (`App\Service\Partage\Exigibilite`).
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
            'paiement', 'article', 'note',
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

    // ===================== L'exigible : ce qu'il faut payer MAINTENANT ===============

    /**
     * RIEN D'ENCAISSÉ, RIEN D'EXIGIBLE — et pourtant la dette existe.
     *
     * C'est toute la raison d'être de la colonne : le solde affiche 200, l'exigible 0.
     * Les confondre ferait virer 200 sur une commission que le cabinet n'a pas perçue.
     */
    public function testSansCommissionEncaisseeRienNEstExigible(): void
    {
        $ids = $this->semer();
        $chiffres = $this->indicateurs($ids['partenaireId']);

        self::assertEqualsWithDelta(self::DUE, $chiffres['retroCommissionSolde'], 0.01);
        self::assertEqualsWithDelta(0.0, $chiffres['retroCommissionExigible'], 0.01);
    }

    /** Commission intégralement perçue : toute la dette devient réclamable. */
    public function testCommissionEncaisseeRendToutLeSoldeExigible(): void
    {
        $ids = $this->semer();
        $this->encaisserCommission($ids, self::COMMISSION);

        $chiffres = $this->indicateurs($ids['partenaireId']);

        self::assertEqualsWithDelta(self::DUE, $chiffres['retroCommissionExigible'], 0.01);
    }

    /**
     * AU PRORATA, ET NON TOUT-OU-RIEN : la moitié rentrée rend la moitié exigible.
     * L'ancienne règle exigeait l'encaissement intégral — un cabinet ayant perçu 50 %
     * gardait alors 100 % de la rétro.
     */
    public function testLExigibleSuitLEncaissementAuProrata(): void
    {
        $ids = $this->semer();
        $this->encaisserCommission($ids, self::COMMISSION / 2);

        $chiffres = $this->indicateurs($ids['partenaireId']);

        self::assertEqualsWithDelta(self::DUE / 2, $chiffres['retroCommissionExigible'], 0.01);
    }

    /** Ce qui est déjà parti ne reste pas exigible : le virement éteint sa part. */
    public function testLeVersementDeduitDeLExigible(): void
    {
        $ids = $this->semer();
        $this->encaisserCommission($ids, self::COMMISSION);
        $this->reverser($ids, 120.0);

        $chiffres = $this->indicateurs($ids['partenaireId']);

        self::assertEqualsWithDelta(self::DUE - 120.0, $chiffres['retroCommissionExigible'], 0.01);
    }

    /**
     * L'INVARIANT DE LECTURE DES DEUX COLONNES : l'exigible est une PART du solde.
     * Le voir dépasser signifierait qu'on réclame au cabinet plus qu'il ne doit.
     */
    public function testLExigibleNeDepasseJamaisLeSolde(): void
    {
        foreach ([0.0, self::COMMISSION / 4, self::COMMISSION] as $encaisse) {
            $ids = $this->semer();
            if ($encaisse > 0.0) {
                $this->encaisserCommission($ids, $encaisse);
            }

            $chiffres = $this->indicateurs($ids['partenaireId']);
            self::assertLessThanOrEqual(
                round($chiffres['retroCommissionSolde'], 2) + 0.01,
                round($chiffres['retroCommissionExigible'], 2),
                sprintf('Encaissé %s : l\'exigible dépasse le solde.', $encaisse),
            );

            $this->cleanUp();
        }
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

    /** L'exigible ferme la ligne : due ▸ payée ▸ reste ▸ reste RÉCLAMABLE. */
    public function testLaColonneExigibleEstDeclareeApresLeSolde(): void
    {
        $canvas = static::getContainer()->get(PartenaireListCanvasProvider::class)->getCanvas();
        $codes = array_column($canvas['colonnes_numeriques'], 'attribut_code');

        self::assertContains('retroCommissionExigible', $codes);
        self::assertGreaterThan(
            array_search('retroCommissionSolde', $codes, true),
            array_search('retroCommissionExigible', $codes, true),
        );

        $exigible = $canvas['colonnes_numeriques'][array_search('retroCommissionExigible', $codes, true)];
        self::assertSame('nombre', $exigible['attribut_type']);
        self::assertNotSame('%', $exigible['attribut_unité'], 'Un montant à virer, pas un taux.');
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

    /**
     * LE CABINET PERÇOIT SA COMMISSION : note à l'assureur, article sur l'échéance,
     * règlement. C'est cet argent RENTRÉ qui fait naître la dette envers le partenaire.
     */
    private function encaisserCommission(array $ids, float $montant): void
    {
        $em = $this->em();
        $entreprise = $em->getRepository(Entreprise::class)->find($ids['entrepriseId']);
        $tranche = $em->getRepository(Tranche::class)->find($ids['trancheId']);
        $cotation = $tranche->getCotation();

        $note = (new Note())->setNom('Note commission')->setType(0)
            ->setAddressedTo(Note::TO_ASSUREUR)->setReference('N-PARTCOL-1')
            ->setValidated(true)->setSignature('')
            ->setAssureur($cotation->getAssureur());
        $note->setEntreprise($entreprise);
        $em->persist($note);

        $article = (new Article())->setNote($note)->setRevenuFacture($cotation->getRevenus()->first())->setTranche($tranche);
        $article->setEntreprise($entreprise);
        $em->persist($article);

        $reglement = (new Paiement())->setMontant($montant)->setReference('ENC-PARTCOL-1')
            ->setPaidAt(new \DateTimeImmutable('-5 days'))->setNote($note);
        $reglement->setEntreprise($entreprise);
        $em->persist($reglement);

        $em->flush();
        $em->clear();
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
