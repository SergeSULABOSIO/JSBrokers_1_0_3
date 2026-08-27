<?php

namespace App\Tests\Workspace;

use App\Comptabilite\CourtierEcritureComptableService;
use App\Comptabilite\PlanComptable;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA RÉTROCOMMISSION D'UN AGENT INTERNE EN COMPTABILITÉ OHADA.
 *
 * Deux exigences, dont la seconde n'est pas évidente :
 *
 *  1. LE BON COMPTE. D 6611 « Appointements, salaires et commissions » / C trésorerie, et
 *     non 632 « Rémunérations d'intermédiaires » qui sert aux partenaires EXTERNES. Ce
 *     n'est pas cosmétique : le préfixe décide de la ligne du compte de résultat et du
 *     TFR — Charges de personnel plutôt que Services extérieurs — donc de la lecture
 *     qu'un tiers fait de la structure de coûts du cabinet.
 *
 *  2. UN VERSEMENT RÉEL = UNE ÉCRITURE. Un virement couvrant trois affaires est saisi en
 *     trois LIGNES (pour que le solde reste exact affaire par affaire dans le rapport de
 *     production), mais il n'a quitté la banque qu'une fois. Trois écritures rendraient le
 *     journal irréconciliable avec le relevé bancaire. Elles sont donc regroupées sur leur
 *     `lotReference` — et un reversement isolé, dont la clé n'appartient qu'à lui, ne peut
 *     jamais être fondu dans le lot d'un autre.
 */
class ReversementRetroAgentComptaTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-retrocompta-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit RetroCompta SARL';
    private const EXERCICE = 2026;

    /** Trois lignes d'un même virement, et une quatrième isolée. */
    private const LOT = [120.0, 80.0, 50.0];
    private const ISOLE = 35.0;

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
        foreach ([
            'reversement_retro_agent', 'avenant', 'cotation', 'piste', 'client',
            // Le partenaire arrive avec les versements d'intermédiaires externes : l'oublier
            // rendrait l'entreprise indestructible d'une exécution à l'autre.
            'risque', 'partenaire', 'compte_bancaire', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    public function testLeLotNeProduitQuUneSeuleEcritureEquilibree(): void
    {
        $ids = $this->semer();
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($ids['entrepriseId']);

        $service = static::getContainer()->get(CourtierEcritureComptableService::class);
        $ecritures = array_values(array_filter(
            $service->ecritures($entreprise),
            static fn (array $e) => $e['type'] === 'retro_agent',
        ));

        self::assertCount(2, $ecritures, 'Trois lignes en lot + une isolée = DEUX écritures.');

        $parPiece = [];
        foreach ($ecritures as $ecriture) {
            $parPiece[$ecriture['piece']] = $ecriture;
        }

        self::assertArrayHasKey('VIR-LOT-001', $parPiece, 'La pièce du lot est sa référence de lot.');

        $lot = $parPiece['VIR-LOT-001'];
        $totalLot = array_sum(self::LOT);
        self::assertSame(round($totalLot, 2), round($lot['lignes'][0]['debit'], 2), 'Le débit du lot vaut la somme de ses lignes.');
        self::assertSame(round($totalLot, 2), round($lot['lignes'][1]['credit'], 2), 'L\'écriture est équilibrée.');
        self::assertStringContainsString('3 polices', $lot['libelle'], 'Le libellé annonce le nombre de polices couvertes.');
    }

    public function testLaChargeTombeEnComptePersonnelEtNonEnRemunerationDIntermediaires(): void
    {
        $ids = $this->semer();
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($ids['entrepriseId']);

        $service = static::getContainer()->get(CourtierEcritureComptableService::class);

        $comptesDebites = [];
        $comptesCredites = [];
        foreach ($service->ecritures($entreprise) as $ecriture) {
            if ($ecriture['type'] !== 'retro_agent') {
                continue;
            }
            foreach ($ecriture['lignes'] as $ligne) {
                if ($ligne['debit'] > 0) $comptesDebites[] = $ligne['compte'];
                if ($ligne['credit'] > 0) $comptesCredites[] = $ligne['compte'];
            }
        }

        self::assertSame(
            [PlanComptable::COMMISSIONS_PERSONNEL, PlanComptable::COMMISSIONS_PERSONNEL],
            $comptesDebites,
        );
        self::assertNotContains(
            PlanComptable::RETRO_COMMISSIONS,
            $comptesDebites,
            'Un salarié n\'est pas un intermédiaire externe.',
        );

        // Le lot passe par la BANQUE (un compte bancaire est renseigné), l'isolé par la CAISSE.
        self::assertContains(PlanComptable::BANQUES, $comptesCredites);
        self::assertContains(PlanComptable::CAISSE, $comptesCredites);
    }

    public function testLesEtatsRestentEquilibresEtLaChargeApparaitAuResultat(): void
    {
        $ids = $this->semer();
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($ids['entrepriseId']);

        $documents = static::getContainer()->get(CourtierEcritureComptableService::class)
            ->documents($entreprise, self::EXERCICE);

        $total = round(array_sum(self::LOT) + self::ISOLE, 2);

        self::assertEqualsWithDelta(
            $documents['journal']['totalDebit'],
            $documents['journal']['totalCredit'],
            0.01,
            'Le journal reste équilibré.',
        );
        self::assertEqualsWithDelta($total, $documents['resultat']['totalCharges'], 0.01);

        // TFR : la charge se range sous « Charges de personnel (66) », le préfixe de 6611.
        $personnel = null;
        foreach ($documents['tfr'] as $poste) {
            if (str_contains($poste['libelle'], 'Charges de personnel')) {
                $personnel = $poste;
            }
        }
        self::assertNotNull($personnel, 'Le TFR doit porter une ligne Charges de personnel.');
        self::assertEqualsWithDelta(-$total, $personnel['montant'], 0.01);
    }

    /**
     * Une entreprise sans autre mouvement comptable (ni capital, ni note, ni dépense) :
     * les seules écritures sont donc celles des reversements, ce qui rend les totaux
     * directement lisibles.
     *
     * @return array{entrepriseId:int}
     */
    /**
     * LE COMPTE DE CHARGE SUIT LE BÉNÉFICIAIRE, PAS L'ENREGISTREMENT.
     *
     * Les deux familles partagent la table depuis que le partenaire est réglé en clair : il
     * facture par sa note de débit, le cabinet lui reverse et garde la pièce. Elles ne
     * partagent PAS le compte — l'agent est un salarié (6611, Charges de personnel), le
     * partenaire un intermédiaire externe (632). Les confondre fausserait le résultat par
     * NATURE de charge, et ferait disparaître la piste comptable que la note de crédit du
     * partenaire portait jusqu'ici.
     */
    public function testLeCompteSuitLeBeneficiaire(): void
    {
        $ids = $this->semer();
        $montantPartenaire = 90.0;
        $this->verserAuPartenaire($ids, $montantPartenaire);

        $entreprise = $this->em()->getRepository(Entreprise::class)->find($ids['entrepriseId']);
        $service = static::getContainer()->get(CourtierEcritureComptableService::class);

        $partenaires = [];
        $agents = [];
        foreach ($service->ecritures($entreprise) as $ecriture) {
            if ($ecriture['type'] === 'retro_partenaire') {
                $partenaires[] = $ecriture;
            } elseif ($ecriture['type'] === 'retro_agent') {
                $agents[] = $ecriture;
            }
        }

        self::assertCount(1, $partenaires, 'Le versement au partenaire doit produire SON écriture.');
        $ecriture = $partenaires[0];

        self::assertSame(PlanComptable::RETRO_COMMISSIONS, $ecriture['lignes'][0]['compte']);
        self::assertSame($montantPartenaire, round($ecriture['lignes'][0]['debit'], 2));
        self::assertSame($montantPartenaire, round($ecriture['lignes'][1]['credit'], 2), 'L’écriture est équilibrée.');
        self::assertStringContainsString('partenaire', $ecriture['libelle']);
        self::assertStringContainsString('SUNU Compta', $ecriture['libelle'], 'Le libellé nomme le bénéficiaire.');

        // Et les écritures de l'agent n'ont pas bougé : deux, toujours en 6611.
        self::assertCount(2, $agents);
        foreach ($agents as $ecritureAgent) {
            self::assertSame(PlanComptable::COMMISSIONS_PERSONNEL, $ecritureAgent['lignes'][0]['compte']);
        }
    }

    /**
     * DEUX FAMILLES PARTAGEANT UNE RÉFÉRENCE DE LOT NE SE FONDENT PAS.
     *
     * Le regroupement se faisait sur la seule `lotReference` : deux versements portant par
     * accident la même — un agent et un partenaire — auraient été fondus dans UNE écriture,
     * donc imputés à un seul des deux comptes. Un lot est un virement à UN bénéficiaire.
     */
    public function testDeuxFamillesNeSeFondentPasDansUneEcriture(): void
    {
        $ids = $this->semer();
        // Le partenaire reçoit un versement portant la MÊME référence de lot que l'agent.
        $this->verserAuPartenaire($ids, 60.0, 'VIR-LOT-001');

        $entreprise = $this->em()->getRepository(Entreprise::class)->find($ids['entrepriseId']);
        $service = static::getContainer()->get(CourtierEcritureComptableService::class);

        $comptes = [];
        foreach ($service->ecritures($entreprise) as $ecriture) {
            if (!in_array($ecriture['type'], ['retro_agent', 'retro_partenaire'], true)) {
                continue;
            }
            $comptes[$ecriture['type']][] = $ecriture['lignes'][0]['compte'];
        }

        self::assertSame([PlanComptable::RETRO_COMMISSIONS], $comptes['retro_partenaire'] ?? []);
        self::assertSame(
            [PlanComptable::COMMISSIONS_PERSONNEL, PlanComptable::COMMISSIONS_PERSONNEL],
            $comptes['retro_agent'] ?? [],
            'Le lot de l’agent ne doit pas avoir absorbé la ligne du partenaire.',
        );
    }

    /** Enregistre un versement à un partenaire externe sur la première police semée. */
    private function verserAuPartenaire(array $ids, float $montant, ?string $lot = null): void
    {
        $em = $this->em();
        $entreprise = $em->getRepository(Entreprise::class)->find($ids['entrepriseId']);

        $partenaire = (new \App\Entity\Partenaire())->setNom('SUNU Compta')->setPart(20.0);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $avenant = $em->getRepository(Avenant::class)->findOneBy(['entreprise' => $entreprise], ['id' => 'ASC']);

        $reversement = (new ReversementRetroAgent())
            ->setPartenaire($partenaire)
            ->setAvenant($avenant)
            ->setMontant($montant)
            ->setPaidAt(new \DateTimeImmutable(self::EXERCICE . '-08-10'))
            ->setReference('RETRO-PART')
            ->setLotReference($lot);
        $reversement->setEntreprise($entreprise);
        $em->persist($reversement);
        $em->flush();
        $em->clear();
    }

    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('RetroCompta Owner')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        // Capital social laissé vide : aucune écriture fondatrice ne vient brouiller les totaux.
        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $agent = (new Invite())->setNom('Alice')->setProprietaire(false);
        $agent->setEntreprise($entreprise);
        $em->persist($agent);

        $banque = (new CompteBancaire())->setIntitule('Compte principal')->setNumero('0001')->setBanque('BCDC')->setCodeSwift('BCDCCDKI');
        $banque->setEntreprise($entreprise);
        $em->persist($banque);

        $risque = (new Risque())->setCode('RK')->setNomComplet('Risque Compta')->setDescription('Risque de la compta')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client Compta')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        // Une affaire par ligne du lot : le libellé doit annoncer « 3 polices ».
        $avenants = [];
        for ($i = 0; $i < count(self::LOT) + 1; ++$i) {
            $piste = (new Piste())->setNom('Piste Compta ' . $i)->setTypeAvenant(0)
                ->setDescriptionDuRisque('Risque compta')->setExercice(self::EXERCICE)
                ->setClient($client)->setRisque($risque);
            $piste->setEntreprise($entreprise)->setInvite($gestionnaire);
            $em->persist($piste);

            $cotation = (new Cotation())->setNom('Cotation Compta ' . $i)->setDuree(365);
            $cotation->setPiste($piste);
            $cotation->setEntreprise($entreprise);
            $em->persist($cotation);

            $avenant = (new Avenant())->setReferencePolice('POL-COMPTA-' . $i)->setNumero('0')
                ->setDescription('Police compta')
                ->setStartingAt(new \DateTimeImmutable(self::EXERCICE . '-02-01'))
                ->setEndingAt(new \DateTimeImmutable(self::EXERCICE . '-12-31'));
            $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
            $cotation->addAvenant($avenant);
            $em->persist($avenant);

            $avenants[] = $avenant;
        }

        // Les trois lignes du LOT : même référence de lot, même date, même compte bancaire.
        foreach (self::LOT as $index => $montant) {
            $reversement = (new ReversementRetroAgent())
                ->setAgent($agent)
                ->setAvenant($avenants[$index])
                ->setMontant($montant)
                ->setPaidAt(new \DateTimeImmutable(self::EXERCICE . '-06-15'))
                ->setReference('RETRO-' . $index)
                ->setLotReference('VIR-LOT-001')
                ->setCompteBancaire($banque);
            $reversement->setEntreprise($entreprise);
            $em->persist($reversement);
        }

        // Le reversement ISOLÉ : pas de lot, pas de compte bancaire (donc la caisse).
        $isole = (new ReversementRetroAgent())
            ->setAgent($agent)
            ->setAvenant($avenants[count(self::LOT)])
            ->setMontant(self::ISOLE)
            ->setPaidAt(new \DateTimeImmutable(self::EXERCICE . '-07-20'))
            ->setReference('RETRO-ISOLE');
        $isole->setEntreprise($entreprise);
        $em->persist($isole);

        $em->flush();
        $ids = ['entrepriseId' => (int) $entreprise->getId()];
        $em->clear();

        return $ids;
    }
}
