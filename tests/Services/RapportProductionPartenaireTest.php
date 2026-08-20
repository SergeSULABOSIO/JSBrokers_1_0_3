<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Repository\PartenaireRepository;
use App\Service\Retro\PartenaireRetro;
use App\Service\Retro\RapportProductionBuilder;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Canvas\Indicator\PartenaireIndicatorStrategy;
use App\Services\Canvas\Indicator\TrancheIndicatorStrategy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE RAPPORT DE PRODUCTION D'UN PARTENAIRE — celui qui n'existait pas.
 *
 * L'agent interne disposait d'un rapport ligne à ligne, d'un écran et d'un outil pour
 * l'assistant. Le partenaire externe, qui se sert pourtant AVANT lui sur la même
 * commission, n'avait rien : ses chiffres n'existaient qu'en agrégat sur sa fiche. On ne
 * pouvait donc ni lui dire sur QUELLE affaire il gagne quoi, ni justifier un montant qu'il
 * conteste.
 *
 * Ce test verrouille les trois propriétés qui rendent ce rapport digne de confiance :
 *
 *  1. LES DEUX CHEMINS D'INTERMÉDIATION. Une affaire désigne son intermédiaire, ou bien
 *     n'en désigne aucun et hérite de celui de son CLIENT. Les deux comptent, et une
 *     affaire qui appartient à un AUTRE intermédiaire ne compte pas — trancher soi-même
 *     aurait attribué à ce partenaire des affaires qui ne le paient pas.
 *
 *  2. LA MÊME SOMME QUE SA FICHE. Le total du rapport doit être, au centime, l'agrégat que
 *     PartenaireIndicatorStrategy affiche déjà à l'écran. Un écart ne serait découvert que
 *     par un intermédiaire contestant sa facture.
 *
 *  3. LA CHAÎNE DE CALCUL EST DITE. Assiette, taux, origine du taux : sans elles, un
 *     montant ne peut qu'être affirmé.
 */
class RapportProductionPartenaireTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-rapport-partenaire@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit RapportPartenaire SARL';
    private const COMMISSION = 1000.0;
    private const PART_PARTENAIRE = 20.0;

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
            'avenant', 'revenu_pour_courtier', 'chargement_pour_prime', 'cotation',
            'condition_partage', 'piste', 'client', 'partenaire', 'risque', 'type_revenu', 'invite',
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
     * Trois affaires : une désignant NOTRE intermédiaire, une sans intermédiaire propre
     * mais dont le CLIENT est le sien, une appartenant à un CONCURRENT.
     *
     * @return array{entrepriseId:int, partenaireId:int, concurrentId:int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Rapport')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($invite);

        $risque = (new Risque())->setCode('RC')->setNomComplet('Risque Rapport')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $partenaire = (new Partenaire())->setNom('SUNU Courtage')->setPart(self::PART_PARTENAIRE);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $concurrent = (new Partenaire())->setNom('Autre Courtage')->setPart(self::PART_PARTENAIRE);
        $concurrent->setEntreprise($entreprise);
        $em->persist($concurrent);

        $typeRevenu = (new TypeRevenu())->setNom('Commission')->setMontantflat(self::COMMISSION)
            ->setShared(true)->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $faire = function (string $nom, ?Partenaire $surLaPiste, ?Partenaire $surLeClient)
            use ($em, $entreprise, $invite, $risque, $typeRevenu): void {
            $client = (new Client())->setNom('Client ' . $nom)->setExonere(false);
            $client->setEntreprise($entreprise);
            if ($surLeClient !== null) {
                $client->addPartenaire($surLeClient);
            }
            $em->persist($client);

            $piste = (new Piste())->setNom('Piste ' . $nom)->setTypeAvenant(0)
                ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))
                ->setClient($client)->setRisque($risque);
            $piste->setEntreprise($entreprise)->setInvite($invite);
            if ($surLaPiste !== null) {
                $piste->setPartenaire($surLaPiste);
            }
            $em->persist($piste);

            $cotation = (new Cotation())->setNom('Cotation ' . $nom)->setDuree(365);
            $cotation->setPiste($piste)->setEntreprise($entreprise);
            $em->persist($cotation);

            $chargement = (new ChargementPourPrime())->setNom('Prime')->setMontantFlatExceptionel(5000.0);
            $chargement->setEntreprise($entreprise);
            $cotation->addChargement($chargement);
            $em->persist($chargement);

            $revenu = (new RevenuPourCourtier())->setNom('Revenu')->setTypeRevenu($typeRevenu)->setCotation($cotation);
            $revenu->setEntreprise($entreprise);
            $em->persist($revenu);

            $avenant = (new Avenant())->setReferencePolice('POL-' . $nom)->setNumero('0')
                ->setDescription('Police ' . $nom)
                ->setStartingAt(new \DateTimeImmutable('-30 days'))
                ->setEndingAt(new \DateTimeImmutable('+335 days'));
            $avenant->setEntreprise($entreprise)->setInvite($invite);
            $cotation->addAvenant($avenant);
            $em->persist($avenant);
        };

        $faire('Directe', $partenaire, null);
        $faire('ParLeClient', null, $partenaire);
        $faire('Concurrente', $concurrent, null);

        $em->flush();
        $ids = [
            'entrepriseId' => (int) $entreprise->getId(),
            'partenaireId' => (int) $partenaire->getId(),
            'concurrentId' => (int) $concurrent->getId(),
        ];
        $em->clear();

        return $ids;
    }

    /** @return array<string, mixed> */
    private function rapport(int $partenaireId, int $entrepriseId): array
    {
        $c = static::getContainer();
        $partenaire = $c->get(PartenaireRepository::class)->find($partenaireId);
        $beneficiaire = new PartenaireRetro(
            $partenaire,
            $c->get(IndicatorCalculationHelper::class),
            $c->get(TrancheIndicatorStrategy::class),
        );

        return $c->get(RapportProductionBuilder::class)->build(
            $beneficiaire,
            $c->get(EntityManagerInterface::class)->getRepository(Entreprise::class)->find($entrepriseId),
        );
    }

    public function testLesDeuxCheminsDIntermediationComptent(): void
    {
        $ids = $this->semer();
        $rapport = $this->rapport($ids['partenaireId'], $ids['entrepriseId']);

        $polices = array_map(static fn (array $l) => $l['reference'], $rapport['lignes']);
        sort($polices);

        // Celle qui le désigne, ET celle qui hérite de lui par son client.
        self::assertSame(['POL-Directe', 'POL-ParLeClient'], $polices);
    }

    public function testUneAffaireDUnAutreIntermediaireNEstPasComptee(): void
    {
        $ids = $this->semer();
        $rapport = $this->rapport($ids['partenaireId'], $ids['entrepriseId']);

        $polices = array_map(static fn (array $l) => $l['reference'], $rapport['lignes']);

        // Le point le plus coûteux à rater : facturer à un intermédiaire la production
        // d'un concurrent.
        self::assertNotContains('POL-Concurrente', $polices);
    }

    public function testChaqueLigneDitCeQueLeMoteurDit(): void
    {
        $ids = $this->semer();
        $rapport = $this->rapport($ids['partenaireId'], $ids['entrepriseId']);

        $c = static::getContainer();
        $helper = $c->get(IndicatorCalculationHelper::class);
        $partenaire = $c->get(PartenaireRepository::class)->find($ids['partenaireId']);

        self::assertNotEmpty($rapport['lignes']);
        foreach ($rapport['lignes'] as $ligne) {
            $attendu = $helper->getCotationMontantRetrocommissionsPayableParCourtier(
                $ligne['cotation'],
                $partenaire,
                -1,
            );
            self::assertEqualsWithDelta(round($attendu, 2), $ligne['due'], 0.01);
        }
    }

    public function testLeTotalEstCeluiDeSaFiche(): void
    {
        $ids = $this->semer();
        $rapport = $this->rapport($ids['partenaireId'], $ids['entrepriseId']);

        $c = static::getContainer();
        $partenaire = $c->get(PartenaireRepository::class)->find($ids['partenaireId']);
        $fiche = $c->get(PartenaireIndicatorStrategy::class)->calculate($partenaire);

        // Le rapport RACONTE ce que la fiche RÉSUME : les deux doivent dire la même somme,
        // sans quoi le courtier lirait deux vérités pour un même argent.
        self::assertEqualsWithDelta(
            (float) $fiche['retroCommission'],
            (float) $rapport['totaux']['due'],
            0.01,
        );
    }

    public function testLaChaineDeCalculEstDite(): void
    {
        $ids = $this->semer();
        $rapport = $this->rapport($ids['partenaireId'], $ids['entrepriseId']);
        $ligne = $rapport['lignes'][0];

        // Aucune condition de partage ici : c'est la part habituelle qui s'applique, et le
        // rapport doit le DIRE plutôt que de laisser deviner d'où sort le taux.
        self::assertNull($ligne['conditionNom']);
        self::assertSame('part habituelle du partenaire', $ligne['conditionOrigine']);
        // Le seuil ne se pose pas sans condition.
        self::assertNull($ligne['seuilFranchi']);

        // L'assiette est la commission partageable, et le montant s'y rapporte au taux.
        self::assertEqualsWithDelta((float) $ligne['partageable'], (float) $ligne['assiette'], 0.01);
        self::assertEqualsWithDelta(
            $ligne['assiette'] * (self::PART_PARTENAIRE / 100),
            (float) $ligne['due'],
            0.01,
        );
    }

    public function testRienNEstVerseNiExigibleSansNoteDeCredit(): void
    {
        $ids = $this->semer();
        $rapport = $this->rapport($ids['partenaireId'], $ids['entrepriseId']);

        // Le dû naît à la souscription ; le versé se lit sur les notes, et l'exigible
        // attend l'encaissement. Sans facture ni règlement, les deux valent zéro — le
        // « dû », lui, reste visible.
        self::assertGreaterThan(0.0, (float) $rapport['totaux']['due']);
        self::assertSame(0.0, (float) $rapport['totaux']['payee']);
        self::assertSame(0.0, (float) $rapport['totaux']['exigible']);
        self::assertEqualsWithDelta(
            (float) $rapport['totaux']['due'],
            (float) $rapport['totaux']['solde'],
            0.01,
        );
    }
}
