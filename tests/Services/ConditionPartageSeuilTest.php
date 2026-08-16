<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE SEUIL D'UNE CONDITION DE PARTAGE DOIT SE COMPARER À QUELQUE CHOSE.
 *
 * Une condition de partage module la rétrocommission d'un partenaire selon une FORMULE
 * (« assiette ≥ seuil », « assiette < seuil », « sans seuil ») appliquée à une UNITÉ DE
 * MESURE : la somme de commission pure du risque, du client, ou du partenaire.
 *
 * Cette unité était lue dans un tableau que `precomputeCommissionSums()` renvoyait
 * TOUJOURS vide — elle valait donc invariablement zéro. La panne était discrète parce
 * qu'elle n'était pas symétrique : « assiette < seuil » se vérifiait TOUJOURS (zéro est
 * inférieur à tout seuil positif) et « assiette ≥ seuil » JAMAIS. Une condition écrite
 * pour récompenser un gros portefeuille ne se déclenchait donc jamais, et son inverse se
 * déclenchait toujours.
 *
 * La règle rétablie n'est pas inventée : elle reprend celle de l'implémentation d'origine
 * (Constante::appliquerConditions) — même entreprise, même exercice, même partenaire.
 */
class ConditionPartageSeuilTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-seuil-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Seuil SARL';

    /** Prime et commission d'UNE cotation : deux cotations franchissent 300, une seule non. */
    private const COMMISSION_PAR_COTATION = 200.0;
    private const SEUIL = 300.0;

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

    private function helper(): IndicatorCalculationHelper
    {
        return static::getContainer()->get(IndicatorCalculationHelper::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement(
            'DELETE pp FROM piste_partenaire pp JOIN piste p ON pp.piste_id = p.id
             JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach ([
            'condition_partage', 'avenant', 'revenu_pour_courtier', 'type_revenu',
            'chargement_pour_prime', 'cotation', 'piste', 'client', 'partenaire', 'risque', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    /**
     * Un client, $nbCotations polices souscrites, un partenaire sur chaque piste, et une
     * condition de partage à seuil portée par le partenaire.
     *
     * @return array{entrepriseId: int, conditionId: int, revenuIds: int[]}
     */
    private function semer(int $nbCotations, int $formule, bool $revenuPartageable = true, string $porteur = 'partenaire'): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Seuil Owner')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($invite);

        $risque = (new Risque())->setCode('RS')->setNomComplet('Risque Seuil')->setDescription('Risque du seuil')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $partenaire = (new Partenaire())->setNom('Partenaire Seuil')->setPart(10.0);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $client = (new Client())->setNom('Client Seuil')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        // La condition porte un taux DIFFÉRENT de la part par défaut du partenaire (10 %),
        // pour que son application se lise sans ambiguïté dans le montant obtenu.
        $fabriquerCondition = function (?Piste $piste) use ($em, $entreprise, $partenaire, $formule): ConditionPartage {
            $condition = (new ConditionPartage())->setNom('Condition Seuil')
                ->setFormule($formule)
                ->setSeuil(self::SEUIL)
                ->setTaux(self::TAUX_CONDITION)
                ->setUniteMesure(ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT)
                ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES);
            // Portée par la PISTE ou par le PARTENAIRE : ce sont les deux rattachements
            // possibles, et leur ordre de priorité est justement ce qui se teste ici.
            $piste === null ? $condition->setPartenaire($partenaire) : $condition->setPiste($piste);
            $condition->setEntreprise($entreprise);
            $em->persist($condition);

            return $condition;
        };

        $condition = $porteur === 'partenaire' ? $fabriquerCondition(null) : null;

        $revenus = [];
        for ($i = 0; $i < $nbCotations; ++$i) {
            $piste = (new Piste())->setNom('Piste Seuil ' . $i)->setTypeAvenant(0)
                ->setDescriptionDuRisque('Risque seuil')->setExercice((int) date('Y'))
                ->setClient($client)->setRisque($risque);
            $piste->setEntreprise($entreprise)->setInvite($invite);
            $piste->addPartenaire($partenaire);
            $em->persist($piste);

            $cotation = (new Cotation())->setNom('Cotation Seuil ' . $i)->setDuree(365);
            $cotation->setPiste($piste);
            $cotation->setEntreprise($entreprise);
            $em->persist($cotation);

            $chargement = (new ChargementPourPrime())->setNom('Prime ' . $i)->setMontantFlatExceptionel(1000.0);
            $chargement->setEntreprise($entreprise);
            $cotation->addChargement($chargement);
            $em->persist($chargement);

            $typeRevenu = (new TypeRevenu())->setNom('Commission ' . $i)
                ->setMontantflat(self::COMMISSION_PAR_COTATION)
                ->setShared($revenuPartageable)->setMultipayments(true)
                ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
            $typeRevenu->setEntreprise($entreprise);
            $em->persist($typeRevenu);

            $revenu = (new RevenuPourCourtier())->setNom('Revenu ' . $i)->setTypeRevenu($typeRevenu)->setCotation($cotation);
            $revenu->setEntreprise($entreprise);
            $em->persist($revenu);

            // La police, sans quoi la cotation reste une proposition et n'est pas agrégée.
            $avenant = (new Avenant())->setReferencePolice('POL-SEUIL-' . $i)->setNumero('0')
                ->setDescription('Police seuil')
                ->setStartingAt(new \DateTimeImmutable('-30 days'))
                ->setEndingAt(new \DateTimeImmutable('+335 days'));
            $avenant->setEntreprise($entreprise)->setInvite($invite);
            $cotation->addAvenant($avenant);
            $em->persist($avenant);

            $revenus[] = $revenu;
        }

        $em->flush();
        $ids = [
            'entrepriseId' => (int) $entreprise->getId(),
            'conditionId'  => (int) $condition->getId(),
            'revenuIds'    => array_map(static fn (RevenuPourCourtier $r) => (int) $r->getId(), $revenus),
        ];
        $em->clear();

        return $ids;
    }

    private function montantDeLaCondition(array $ids): float
    {
        $em = $this->em();
        $condition = $em->getRepository(ConditionPartage::class)->find($ids['conditionId']);

        $montant = 0.0;
        foreach ($ids['revenuIds'] as $revenuId) {
            $montant += $this->helper()->applyRevenuConditionsSpeciales(
                $condition,
                $em->getRepository(RevenuPourCourtier::class)->find($revenuId),
                -1,
            );
        }

        return $montant;
    }

    public function testLeSeuilAtteintDeclencheEnfinLaCondition(): void
    {
        // Deux cotations à 200 de commission : l'unité de mesure (somme de commission pure
        // du client) dépasse le seuil de 300. La condition DOIT s'appliquer.
        $ids = $this->semer(2, ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL);

        $this->assertGreaterThan(
            0.0,
            $this->montantDeLaCondition($ids),
            'Une condition « assiette ≥ seuil » dont le seuil est franchi doit produire une '
            . 'rétrocommission. Tant que l\'unité de mesure valait zéro, elle ne se déclenchait JAMAIS.',
        );
    }

    public function testLeSeuilNonAtteintNeDeclenchePas(): void
    {
        // Une seule cotation à 200 : sous le seuil de 300, la condition ne doit rien produire.
        $ids = $this->semer(1, ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL);

        $this->assertSame(
            0.0,
            $this->montantDeLaCondition($ids),
            'Sous le seuil, une condition « assiette ≥ seuil » ne doit rien produire.',
        );
    }

    public function testLaFormuleInverseCesseDeToujoursSappliquer(): void
    {
        // Le pendant du premier cas : « assiette < seuil » se vérifiait toujours, puisque
        // l'unité de mesure valait zéro. Avec deux cotations au-dessus du seuil, elle ne
        // doit désormais PLUS s'appliquer.
        $ids = $this->semer(2, ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL);

        $this->assertSame(
            0.0,
            $this->montantDeLaCondition($ids),
            'Au-dessus du seuil, une condition « assiette < seuil » ne doit plus s\'appliquer — '
            . 'elle s\'appliquait systématiquement quand l\'unité de mesure valait zéro.',
        );
    }

    public function testUnRevenuNonPartageableNAlimentePasLaRetrocommission(): void
    {
        // « Non partageable » veut dire ce qu'il dit. Le filtre était écrit dans l'appel
        // (getRevenuMontantPure($revenu, $addressedTo, true)) mais la méthode ne déclarait
        // qu'un paramètre : PHP acceptait les arguments en trop et les jetait.
        $ids = $this->semer(2, ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL, false);

        $this->assertSame(
            0.0,
            $this->montantDeLaCondition($ids),
            'Un revenu non partageable ne doit alimenter aucune rétrocommission de partenaire.',
        );
    }

    public function testGetRevenuMontantPureHonoreSesDeuxFiltres(): void
    {
        $ids = $this->semer(1, ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL, false);
        $revenu = $this->em()->getRepository(RevenuPourCourtier::class)->find($ids['revenuIds'][0]);

        $sansFiltre = $this->helper()->getRevenuMontantPure($revenu);
        $this->assertGreaterThan(0.0, $sansFiltre, 'Sans filtre, le montant pur reste celui d\'avant.');

        $this->assertSame(
            0.0,
            $this->helper()->getRevenuMontantPure($revenu, -1, true),
            'Restreint aux revenus partageables, un revenu non partageable vaut zéro.',
        );

        // Le revenu semé est dû par l'ASSUREUR : le filtrer sur le redevable CLIENT l'exclut.
        $this->assertSame(
            0.0,
            $this->helper()->getRevenuMontantPure($revenu, TypeRevenu::REDEVABLE_CLIENT, false),
            'Restreint à un autre redevable, le revenu ne compte pas.',
        );
        $this->assertSame(
            $sansFiltre,
            $this->helper()->getRevenuMontantPure($revenu, TypeRevenu::REDEVABLE_ASSUREUR, false),
            'Restreint à son propre redevable, le revenu compte intégralement.',
        );
    }
}
