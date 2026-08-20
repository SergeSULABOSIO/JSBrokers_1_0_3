<?php

namespace App\Tests\Ai;

use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\Utilisateur;
use App\Services\Finance\VentilationFinanciereService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Couverture EXHAUSTIVE des 6 axes × 2 mesures de la ventilation, sur bdm_test :
 * un graphe complet et nommé (assureur, risque, client/assuré, portefeuille,
 * partenaire) porte une commission encaissée de 115 (TTC, taxe assureur 15 %
 * => HT 100) et un sinistre (payable 500 / payé 200 / solde 300). Chaque axe
 * doit restituer l'entité nommée avec le bon montant.
 *
 * Isolation : chaque test s'exécute dans une transaction annulée en tearDown
 * (aucun nettoyage manuel). em->clear() force des lectures À FROID (comme en
 * requête réelle) — indispensable car les collections d'une entité managée à
 * FK seule ne sont pas repeuplées par un fetch-join.
 */
class KetVentilationAxesE2ETest extends KernelTestCase
{
    private const ANNEE = 2026;

    private EntityManagerInterface $em;
    private VentilationFinanciereService $service;
    private int $entrepriseId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(VentilationFinanciereService::class);

        $this->em->getConnection()->beginTransaction();
        $this->entrepriseId = $this->seed();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    private function entreprise(): Entreprise
    {
        return $this->em->find(Entreprise::class, $this->entrepriseId);
    }

    private function seed(): int
    {
        $em = $this->em;

        $owner = (new Utilisateur())
            ->setEmail('phpunit-axes@test.local')
            ->setNom('PHPUnit Axes')
            ->setVerified(true)
            ->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom('PHPUnit Axes SARL')
            ->setLicence('LIC-AX')->setAdresse('1 rue Axes')->setTelephone('+243000000000')
            ->setRccm('RCCM-AX')->setIdnat('IDNAT-AX')->setNumimpot('IMP-AX')
            ->setUtilisateur($owner);
        $em->persist($entreprise);

        $ownerInvite = (new Invite())
            ->setNom('Administrateur')->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($ownerInvite);

        $assureur = (new Assureur())
            ->setNom('Assureur CAF')->setEmail('a@test.local')->setNumimpot('IMP-A')->setIdnat('IDNAT-A')->setRccm('RCCM-A')
            ->setEntreprise($entreprise);
        $em->persist($assureur);

        $portefeuille = (new Portefeuille())
            ->setNom('PF CAF')->setGestionnaire($ownerInvite)->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $partenaire = (new Partenaire())
            ->setNom('Partenaire CAF')->setPart(15.0)->setEntreprise($entreprise);
        $em->persist($partenaire);

        $client = (new Client())
            ->setNom('Assuré CAF')->setExonere(false)->setPortefeuille($portefeuille)->setEntreprise($entreprise);
        $client->addPartenaire($partenaire);
        $em->persist($client);

        $risque = (new Risque())
            ->setCode('INC')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setNomComplet('Incendie')
            ->setImposable(true)->setEntreprise($entreprise);
        $em->persist($risque);

        $piste = (new Piste())
            ->setNom('Piste CAF')->setTypeAvenant(0)->setDescriptionDuRisque('Test')->setExercice(self::ANNEE)
            ->setClient($client)->setRisque($risque)->setEntreprise($entreprise);
        $piste->setPartenaire($partenaire);
        $em->persist($piste);

        $cotation = (new Cotation())
            ->setNom('Cotation CAF')->setDuree(12)->setPiste($piste)->setAssureur($assureur)->setEntreprise($entreprise);
        $em->persist($cotation);

        $avenant = (new Avenant())
            ->setCotation($cotation)
            ->setStartingAt(new \DateTimeImmutable(self::ANNEE . '-01-01'))
            ->setEndingAt(new \DateTimeImmutable(self::ANNEE . '-12-31'))
            ->setDescription('Avenant CAF')->setReferencePolice('POL-CAF-1')->setEntreprise($entreprise);
        $em->persist($avenant);

        $revenu = (new RevenuPourCourtier())
            ->setNom('Rev CAF')->setCotation($cotation)->setEntreprise($entreprise);
        $em->persist($revenu);

        // Note de commission (adressée à l'assureur) rattachée à la cotation via un article.
        $note = (new Note())
            ->setNom('Note commission CAF')->setType(0)->setAddressedTo(Note::TO_ASSUREUR)
            ->setReference('N-CAF-1')->setValidated(true)->setSignature('')
            ->setAssureur($assureur)->setEntreprise($entreprise);
        $em->persist($note);

        $article = (new Article())
            ->setNote($note)->setRevenuFacture($revenu)->setEntreprise($entreprise);
        $em->persist($article);

        $encaissement = (new Paiement())
            ->setMontant(115.0)->setReference('ENC-CAF-1')
            ->setPaidAt(new \DateTimeImmutable(self::ANNEE . '-03-15 10:00:00'))
            ->setNote($note)->setEntreprise($entreprise);
        $em->persist($encaissement);

        // Volet SINISTRE : survenu en avril (payable 500), indemnité versée en mai (payé 200).
        $sinistre = (new NotificationSinistre())
            ->setAssureur($assureur)->setAssure($client)->setRisque($risque)
            ->setOccuredAt(new \DateTimeImmutable(self::ANNEE . '-04-10 09:00:00'))
            ->setEntreprise($entreprise);
        $em->persist($sinistre);

        $offre = (new OffreIndemnisationSinistre())
            ->setMontantPayable(500.0)->setBeneficiaire('Assuré CAF')
            ->setNotificationSinistre($sinistre)->setEntreprise($entreprise);
        $em->persist($offre);

        $indemnite = (new Paiement())
            ->setMontant(200.0)->setReference('IND-CAF-1')
            ->setPaidAt(new \DateTimeImmutable(self::ANNEE . '-05-20 10:00:00'))
            ->setOffreIndemnisationSinistre($offre)->setEntreprise($entreprise);
        $em->persist($indemnite);

        $em->flush();

        // Taxe assureur 15 % : colonne taxe.description NOT NULL non mappée (drift) => SQL direct.
        $em->getConnection()->insert('taxe', [
            'entreprise_id' => $entreprise->getId(),
            'code' => 'TAX-ASS', 'description' => 'Taxe assureur (test)',
            'redevable' => Taxe::REDEVABLE_ASSUREUR, 'taux_iard' => '15.00', 'taux_vie' => '0.00',
            'created_at' => '2026-01-01 00:00:00',
        ]);

        return $entreprise->getId();
    }

    /**
     * CHIFFRE D'AFFAIRES : chaque axe restitue l'entité nommée avec CA TTC 115 / HT 100.
     *
     * @dataProvider axesCa
     */
    public function testChiffreAffairesParAxe(string $dimension, string $libelleAttendu): void
    {
        $data = $this->service->chiffreAffaires($this->entreprise(), $dimension, self::ANNEE);

        $this->assertNotEmpty($data['lignes'], "CA/$dimension : aucune ligne");
        $this->assertSame($libelleAttendu, $data['lignes'][0]['libelle'], "CA/$dimension : libellé");
        $this->assertSame(115.0, $data['lignes'][0]['caTtc'], "CA/$dimension : TTC");
        $this->assertSame(100.0, $data['lignes'][0]['caHt'], "CA/$dimension : HT");
    }

    public static function axesCa(): array
    {
        return [
            'assureur'     => ['assureur', 'Assureur CAF'],
            'risque'       => ['risque', 'INC - Incendie'],
            'client'       => ['client', 'Assuré CAF'],
            'portefeuille' => ['portefeuille', 'PF CAF'],
            'partenaire'   => ['partenaire', 'Partenaire CAF'],
            'mois'         => ['mois', 'Mars'],
        ];
    }

    /**
     * SINISTRES (axes hors mois) : chaque axe restitue l'entité nommée avec
     * payable 500 / payé 200 / solde 300.
     *
     * @dataProvider axesSinistres
     */
    public function testSinistresParAxe(string $dimension, string $libelleAttendu): void
    {
        $data = $this->service->sinistres($this->entreprise(), $dimension, self::ANNEE);

        $this->assertNotEmpty($data['lignes'], "Sinistres/$dimension : aucune ligne");
        $ligne = $data['lignes'][0];
        $this->assertSame($libelleAttendu, $ligne['libelle'], "Sinistres/$dimension : libellé");
        $this->assertSame(500.0, $ligne['payable'], "Sinistres/$dimension : payable");
        $this->assertSame(200.0, $ligne['paye'], "Sinistres/$dimension : payé");
        $this->assertSame(300.0, $ligne['solde'], "Sinistres/$dimension : solde");
    }

    public static function axesSinistres(): array
    {
        return [
            'assureur'     => ['assureur', 'Assureur CAF'],
            'risque'       => ['risque', 'Incendie'],
            'client'       => ['client', 'Assuré CAF'],
            'portefeuille' => ['portefeuille', 'PF CAF'],
            'partenaire'   => ['partenaire', 'Partenaire CAF'],
        ];
    }

    /**
     * SINISTRES par MOIS : le payable est bucketé au mois de SURVENANCE (avril),
     * le payé au mois de DÉCAISSEMENT (mai) — deux lignes distinctes.
     */
    public function testSinistresParMois(): void
    {
        $data = $this->service->sinistres($this->entreprise(), 'mois', self::ANNEE);

        $parLibelle = [];
        foreach ($data['lignes'] as $l) {
            $parLibelle[$l['libelle']] = $l;
        }

        $this->assertArrayHasKey('Avril', $parLibelle, 'payable attendu au mois de survenance');
        $this->assertSame(500.0, $parLibelle['Avril']['payable']);
        $this->assertSame(0.0, $parLibelle['Avril']['paye']);

        $this->assertArrayHasKey('Mai', $parLibelle, 'payé attendu au mois de décaissement');
        $this->assertSame(200.0, $parLibelle['Mai']['paye']);
        $this->assertSame(0.0, $parLibelle['Mai']['payable']);

        // Totaux cohérents sur l'ensemble des mois.
        $this->assertSame(500.0, $data['totalPayable']);
        $this->assertSame(200.0, $data['totalPaye']);
        $this->assertSame(300.0, $data['totalSolde']);
    }
}
