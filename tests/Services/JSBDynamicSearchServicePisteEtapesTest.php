<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\PisteTransformationScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE CHIP ET LA LIGNE RANGENT CHAQUE PISTE DANS LA MÊME ÉTAPE.
 *
 * L'étape d'une piste n'est stockée nulle part : le chip la calcule en SQL
 * (JSBDynamicSearchService::predicatsDEtapePiste), la ligne en PHP
 * (PisteIndicatorStrategy → PisteTransformationScope::etapeDe). Deux écritures d'une même
 * règle ne restent d'accord que si un test les y oblige — sinon une piste « Offre chiffrée »
 * dans sa ligne disparaîtrait du chip « Offres chiffrées », sans un mot.
 *
 * Les cas limites sont ceux qui séparent les deux mondes : une somme nulle n'est pas une
 * prime, une remise peut ramener la somme à zéro, un dixième de centime n'est pas une
 * prime, et le demi-centime l'est (arrondi au centime, il vaut 0,01).
 */
class JSBDynamicSearchServicePisteEtapesTest extends KernelTestCase
{
    private const EMAIL = 'phpunit-piste-etapes@test.local';
    private const CABINET = 'PHPUnit Piste Etapes SARL';

    /** @var array<string, string> nom de la piste => étape attendue */
    private const ATTENDU = [
        'A coter' => PisteTransformationScope::ETAPE_A_COTER,
        'Cotation sans chargement' => PisteTransformationScope::ETAPE_EN_COTATION,
        'Prime nulle' => PisteTransformationScope::ETAPE_EN_COTATION,
        'Remise qui annule' => PisteTransformationScope::ETAPE_EN_COTATION,
        'Un dixieme de centime' => PisteTransformationScope::ETAPE_EN_COTATION,
        'Prime chiffree' => PisteTransformationScope::ETAPE_OFFRES_CHIFFREES,
        'Demi-centime sur la seconde cotation' => PisteTransformationScope::ETAPE_OFFRES_CHIFFREES,
        'Souscrite sans prime' => PisteTransformationScope::STATUT_TRANSFORMEES,
        'Souscrite et chiffree ailleurs' => PisteTransformationScope::STATUT_TRANSFORMEES,
    ];

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

    private function semer(): Entreprise
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Etapes')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000055')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $invite = (new Invite())->setNom('Proprio')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $em->persist($invite);

        $piste = function (string $nom) use ($em, $cabinet, $invite): Piste {
            $p = (new Piste())->setNom($nom)->setTypeAvenant(0)->setDescriptionDuRisque('Risque')->setExercice(2026);
            $p->setEntreprise($cabinet)->setInvite($invite);
            $em->persist($p);

            return $p;
        };
        $cotation = function (Piste $p, string $nom, array $montants = [], bool $souscrite = false) use ($em, $cabinet): void {
            $c = (new Cotation())->setNom($nom)->setDuree(12);
            $c->setPiste($p)->setEntreprise($cabinet);
            $em->persist($c);
            foreach ($montants as $i => $montant) {
                $ch = (new ChargementPourPrime())->setNom("$nom $i")->setMontantFlatExceptionel($montant);
                $ch->setCotation($c)->setEntreprise($cabinet);
                $em->persist($ch);
            }
            if ($souscrite) {
                $a = (new Avenant())->setReferencePolice('POL-' . $nom)->setNumero('0')->setDescription($nom)
                    ->setStartingAt(new \DateTimeImmutable('-10 days'))->setEndingAt(new \DateTimeImmutable('+355 days'))
                    ->setCotation($c);
                $a->setEntreprise($cabinet);
                $em->persist($a);
            }
        };

        $piste('A coter');
        $cotation($piste('Cotation sans chargement'), 'C1');
        $cotation($piste('Prime nulle'), 'C2', [0.0]);
        $cotation($piste('Remise qui annule'), 'C3', [100.0, -100.0]);
        $cotation($piste('Un dixieme de centime'), 'C4', [0.001]);
        $cotation($piste('Prime chiffree'), 'C5', [500.0]);
        $p = $piste('Demi-centime sur la seconde cotation');
        $cotation($p, 'C6a', [0.0]);
        $cotation($p, 'C6b', [0.005]);
        $cotation($piste('Souscrite sans prime'), 'C7', [], true);
        $p = $piste('Souscrite et chiffree ailleurs');
        $cotation($p, 'C8a', [], true);
        $cotation($p, 'C8b', [750.0]);

        $em->flush();
        $em->clear();

        return $em->find(Entreprise::class, $cabinet->getId());
    }

    /** @return list<string> les noms trouvés par le chip, triés */
    private function parLeChip(Entreprise $cabinet, string $valeur): array
    {
        $r = static::getContainer()->get(JSBDynamicSearchService::class)
            ->search(Piste::class, [PisteTransformationScope::CRITERION_KEY => $valeur], $cabinet, null, 1, 100);
        $noms = array_map(static fn (Piste $p): string => $p->getNom(), $r['data']);
        sort($noms);

        return $noms;
    }

    /** @return list<string> */
    private function attendus(string ...$etapes): array
    {
        $noms = array_keys(array_filter(self::ATTENDU, static fn (string $e): bool => in_array($e, $etapes, true)));
        sort($noms);

        return $noms;
    }

    public function testLaLigneRangeChaquePisteDansLEtapeAttendue(): void
    {
        $cabinet = $this->semer();
        $canvas = static::getContainer()->get(CanvasBuilder::class);

        $lues = [];
        foreach ($this->em()->getRepository(Piste::class)->findBy(['entreprise' => $cabinet]) as $piste) {
            $canvas->loadAllCalculatedValues($piste);
            $lues[$piste->getNom()] = $piste->etapePipe;
            self::assertSame(
                PisteTransformationScope::ETAPES[$piste->etapePipe]['singulier'],
                $piste->etapePipeLibelle,
            );
            self::assertSame('etape-' . $piste->etapePipe, $piste->etapePipeNiveau);
        }
        ksort($lues);
        $attendu = self::ATTENDU;
        ksort($attendu);

        self::assertSame($attendu, $lues);
    }

    public function testLeChipTrouveExactementLesPistesDeLEtape(): void
    {
        $cabinet = $this->semer();

        foreach (array_keys(PisteTransformationScope::ETAPES) as $etape) {
            self::assertSame(
                $this->attendus($etape),
                $this->parLeChip($cabinet, $etape),
                "Le chip « $etape » ne trouve pas les pistes que leur ligne range dans cette étape.",
            );
        }
    }

    public function testLesEtapesPartitionnentLaRubriqueEtEnCoursReunitLesTroisPremieres(): void
    {
        $cabinet = $this->semer();

        $toutes = [];
        foreach (array_keys(PisteTransformationScope::ETAPES) as $etape) {
            $toutes = [...$toutes, ...$this->parLeChip($cabinet, $etape)];
        }
        sort($toutes);
        $tout = array_keys(self::ATTENDU);
        sort($tout);
        self::assertSame($tout, $toutes, 'Chaque piste doit tomber dans UNE étape, et une seule.');

        self::assertSame(
            $this->attendus(
                PisteTransformationScope::ETAPE_A_COTER,
                PisteTransformationScope::ETAPE_EN_COTATION,
                PisteTransformationScope::ETAPE_OFFRES_CHIFFREES,
            ),
            $this->parLeChip($cabinet, PisteTransformationScope::STATUT_EN_COURS),
        );
    }

    public function testLaRegleEtLeSeuil(): void
    {
        self::assertSame(PisteTransformationScope::STATUT_TRANSFORMEES, PisteTransformationScope::etapeDe(true, false, true));
        self::assertSame(PisteTransformationScope::ETAPE_OFFRES_CHIFFREES, PisteTransformationScope::etapeDe(false, true, true));
        self::assertSame(PisteTransformationScope::ETAPE_EN_COTATION, PisteTransformationScope::etapeDe(false, false, true));
        self::assertSame(PisteTransformationScope::ETAPE_A_COTER, PisteTransformationScope::etapeDe(false, false, false));

        self::assertFalse(PisteTransformationScope::primeChiffree(0.0));
        self::assertFalse(PisteTransformationScope::primeChiffree(0.001));
        self::assertTrue(PisteTransformationScope::primeChiffree(0.005), 'Le demi-centime, arrondi, vaut 0,01.');
        self::assertFalse(PisteTransformationScope::primeChiffree(-50.0));

        self::assertSame(0, PisteTransformationScope::rang(PisteTransformationScope::ETAPE_A_COTER));
        self::assertSame(3, PisteTransformationScope::rang(PisteTransformationScope::STATUT_TRANSFORMEES));
        self::assertSame(-1, PisteTransformationScope::rang(PisteTransformationScope::STATUT_EN_COURS), '« En cours » n\'est pas une étape.');
    }

    public function testKetComprendLesEtapes(): void
    {
        self::assertSame(PisteTransformationScope::ETAPE_A_COTER, PisteTransformationScope::detecterDepuisTexte('combien de pistes a coter ?'));
        self::assertSame(PisteTransformationScope::ETAPE_EN_COTATION, PisteTransformationScope::detecterDepuisTexte('les pistes en cotation'));
        self::assertSame(PisteTransformationScope::ETAPE_OFFRES_CHIFFREES, PisteTransformationScope::detecterDepuisTexte('mes offres chiffrees'));
        // Non-régression des deux statuts d'origine.
        self::assertSame(PisteTransformationScope::STATUT_EN_COURS, PisteTransformationScope::detecterDepuisTexte('combien de pistes en cours'));
        self::assertSame(PisteTransformationScope::STATUT_TRANSFORMEES, PisteTransformationScope::detecterDepuisTexte('les pistes transformees'));

        $schema = PisteTransformationScope::proprieteSchema();
        self::assertSame(array_keys(PisteTransformationScope::VALEURS), $schema['enum']);
    }
}
