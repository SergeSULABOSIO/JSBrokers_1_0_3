<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\CanvasBuilder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UNE PAGE DE PISTES COÛTE UN NOMBRE FIXE DE REQUÊTES.
 *
 * La Piste n'avait pas de branche dans CalculationProvider::batchPreload : elle tombait
 * dans le cas par défaut, qui ne la connaît pas. Chaque ligne relisait alors seule ses
 * cotations, leurs avenants, leurs chargements et leurs revenus — le coût d'une page
 * croissait avec le nombre de pistes affichées.
 *
 * Deux exigences, et la première compte autant que la seconde : le lot ne change AUCUN
 * chiffre (il ne groupe que la lecture), et son coût ne dépend plus du nombre de lignes.
 */
class PrechargementPisteTest extends KernelTestCase
{
    private const EMAIL = 'phpunit-prechargement-piste@test.local';
    private const CABINET = 'PHPUnit Prechargement Piste SARL';

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
        foreach (['avenant', 'chargement_pour_prime', 'cotation', 'piste', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:n)",
                ['n' => [self::CABINET]],
                ['n' => ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = ?', [self::CABINET]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
    }

    /** Douze pistes de trois sortes : sans prime, chiffrée, transformée. */
    private function semer(): int
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Prechargement')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000088')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $invite = (new Invite())->setNom('Proprio')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $em->persist($invite);

        for ($i = 1; $i <= 12; ++$i) {
            $piste = (new Piste())->setNom('Piste ' . $i)->setTypeAvenant(0)->setDescriptionDuRisque('Risque ' . $i)
                ->setExercice(2026)->setPrimePotentielle(1000.0 * $i)->setCommissionPotentielle(100.0 * $i);
            $piste->setEntreprise($cabinet)->setInvite($invite);
            $em->persist($piste);

            foreach ([1, 2] as $n) {
                $cotation = (new Cotation())->setNom("Cotation $i.$n")->setDuree(12);
                $cotation->setPiste($piste)->setEntreprise($cabinet);
                $em->persist($cotation);

                if ($i % 3 !== 0) {
                    $chargement = (new ChargementPourPrime())->setNom("Prime $i.$n")->setMontantFlatExceptionel(500.0 * $n);
                    $chargement->setCotation($cotation)->setEntreprise($cabinet);
                    $em->persist($chargement);
                }
                if ($i % 3 === 1 && $n === 1) {
                    $avenant = (new Avenant())->setReferencePolice("POL-$i")->setNumero('0')->setDescription("Police $i")
                        ->setStartingAt(new \DateTimeImmutable('-10 days'))->setEndingAt(new \DateTimeImmutable('+355 days'))
                        ->setCotation($cotation);
                    $avenant->setEntreprise($cabinet);
                    $em->persist($avenant);
                }
            }
        }
        $em->flush();

        return (int) $cabinet->getId();
    }

    /**
     * @return array{valeurs: array<int, array<string, mixed>>, requetes: int}
     */
    private function page(int $cabinet, int $taille, bool $avecLot): array
    {
        $em = $this->em();
        $em->clear();
        static::getContainer()->get(IndicatorCalculationHelper::class)->reset();
        $canvas = static::getContainer()->get(CanvasBuilder::class);
        $page = $em->getRepository(Piste::class)->findBy(['entreprise' => $cabinet], ['id' => 'ASC'], $taille);

        $logger = new class () implements \Doctrine\DBAL\Logging\SQLLogger {
            public int $nb = 0;

            public function startQuery($sql, ?array $params = null, ?array $types = null): void
            {
                ++$this->nb;
            }

            public function stopQuery(): void
            {
            }
        };
        $config = $em->getConnection()->getConfiguration();
        $precedent = $config->getSQLLogger();
        $config->setSQLLogger($logger);

        $valeurs = [];
        try {
            if ($avecLot) {
                $canvas->batchPreloadForCollection($page);
            }
            foreach ($page as $piste) {
                $canvas->loadAllCalculatedValues($piste);
                $lues = array_filter(get_object_vars($piste), static fn ($v) => is_scalar($v) || $v === null);
                ksort($lues);
                $valeurs[(int) $piste->getId()] = $lues;
            }
        } finally {
            $config->setSQLLogger($precedent);
        }

        return ['valeurs' => $valeurs, 'requetes' => $logger->nb];
    }

    public function testLeLotNeChangeAucunChiffre(): void
    {
        $cabinet = $this->semer();

        $sansLot = $this->page($cabinet, 12, false);
        $avecLot = $this->page($cabinet, 12, true);

        self::assertCount(12, $avecLot['valeurs']);
        self::assertSame($sansLot['valeurs'], $avecLot['valeurs'], 'Le préchargement a changé un indicateur de piste.');
    }

    public function testLeCoutDUnePageNeDependPlusDuNombreDePistes(): void
    {
        $cabinet = $this->semer();

        // UNE PAGE POUR RIEN, D'ABORD : le barème des taxes du cabinet se lit une fois, puis
        // reste en cache (ServiceTaxes::getTaxes). Un coût FIXE — que la première page
        // mesurée paierait seule, faisant croire à une différence entre 4 et 12 pistes.
        // (Il n'apparaissait pas avant le 2026-10-15 : sans utilisateur connecté, la taxe
        // ne se calculait pas du tout — le TTC valait le HT.)
        $this->page($cabinet, 4, true);

        $quatre = $this->page($cabinet, 4, true);
        $douze = $this->page($cabinet, 12, true);
        $douzeSansLot = $this->page($cabinet, 12, false);

        self::assertLessThan($douzeSansLot['requetes'], $douze['requetes'], sprintf(
            'Le lot ne fait rien gagner à la liste des Pistes (%d requêtes avec, %d sans).',
            $douze['requetes'],
            $douzeSansLot['requetes'],
        ));
        self::assertSame($quatre['requetes'], $douze['requetes'], sprintf(
            'Le coût d\'une page croît encore avec le nombre de pistes : %d requêtes pour 4, %d pour 12.',
            $quatre['requetes'],
            $douze['requetes'],
        ));
    }
}
