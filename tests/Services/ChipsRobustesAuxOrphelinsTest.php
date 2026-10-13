<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\CotationSouscriptionScope;
use App\Services\Search\PisteTransformationScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UNE FICHE ORPHELINE, AILLEURS, NE VIDE PAS LES CHIPS D'UN CABINET.
 *
 * Les chips « En cours » (Pistes) et « En attente » (Cotations) filtraient par
 * `NOT IN (SELECT IDENTITY(...) FROM Avenant …)` — une sous-requête bornée à aucun cabinet.
 * Or une police peut naître sans cotation (barre d'outils des Avenants) et une cotation sans
 * piste : les deux liens sont nullables. Une seule valeur NULL dans la liste, et
 * `x NOT IN (…, NULL)` vaut NULL pour toutes les lignes : les deux chips se vidaient, en
 * silence, dans TOUS les cabinets.
 *
 * Les orphelins sont donc semés dans un AUTRE cabinet que celui qu'on interroge : c'est
 * exactement la forme sous laquelle le défaut aurait frappé en production.
 */
class ChipsRobustesAuxOrphelinsTest extends KernelTestCase
{
    private const EMAIL = 'phpunit-chips-orphelins@test.local';
    private const CABINET = 'PHPUnit Chips Orphelins SARL';
    private const VOISIN = 'PHPUnit Chips Orphelins Voisin SARL';

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
        $noms = [self::CABINET, self::VOISIN];
        foreach (['avenant', 'cotation', 'piste', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (:noms)', ['noms' => $noms], ['noms' => ArrayParameterType::STRING]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
    }

    private function cabinet(string $nom, Utilisateur $owner): array
    {
        $em = $this->em();
        $entreprise = (new Entreprise())->setNom($nom)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000077')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($entreprise);
        $invite = (new Invite())->setNom('Proprio ' . $nom)->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        return [$entreprise, $invite];
    }

    private function piste(Entreprise $e, Invite $i, string $nom): Piste
    {
        $piste = (new Piste())->setNom($nom)->setTypeAvenant(0)->setDescriptionDuRisque('Risque')
            ->setExercice(2026)->setEntreprise($e)->setInvite($i);
        $this->em()->persist($piste);

        return $piste;
    }

    private function cotation(Entreprise $e, string $nom, ?Piste $piste): Cotation
    {
        $cotation = (new Cotation())->setNom($nom)->setDuree(12);
        $cotation->setPiste($piste)->setEntreprise($e);
        $this->em()->persist($cotation);

        return $cotation;
    }

    private function avenant(Entreprise $e, string $ref, ?Cotation $cotation): void
    {
        $avenant = (new Avenant())->setReferencePolice($ref)->setNumero('0')->setDescription($ref)
            ->setStartingAt(new \DateTimeImmutable('-10 days'))->setEndingAt(new \DateTimeImmutable('+355 days'))
            ->setCotation($cotation);
        $avenant->setEntreprise($e);
        $this->em()->persist($avenant);
    }

    /** @return list<string> les noms trouvés, triés */
    private function noms(string $classe, string $cle, string $statut, Entreprise $e): array
    {
        $resultat = static::getContainer()->get(JSBDynamicSearchService::class)
            ->search($classe, [$cle => $statut], $e, null, 1, 100);
        $noms = array_map(static fn (object $x): string => $x->getNom(), $resultat['data']);
        sort($noms);

        return $noms;
    }

    public function testUnePoliceSansCotationEtUneCotationSansPisteAilleursNeVidentRien(): void
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Orphelins')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        [$cabinet, $invite] = $this->cabinet(self::CABINET, $owner);
        [$voisin] = $this->cabinet(self::VOISIN, $owner);

        // Le cabinet interrogé : une piste en cours, une piste transformée, et une cotation
        // sans piste (non souscrite), qui doit être « en attente » comme le dit sa fiche.
        $enCours = $this->piste($cabinet, $invite, 'Piste en cours');
        $this->cotation($cabinet, 'Cotation en attente', $enCours);
        $transformee = $this->piste($cabinet, $invite, 'Piste transformee');
        $this->avenant($cabinet, 'POL-ORPH-1', $this->cotation($cabinet, 'Cotation souscrite', $transformee));
        $this->cotation($cabinet, 'Cotation sans piste', null);

        // AILLEURS : les deux orphelins qui mettaient un NULL dans les sous-requêtes.
        // - une police sans cotation : NULL dans la liste des cotations souscrites ;
        // - une police sur une cotation sans piste : NULL dans la liste des pistes souscrites.
        $this->avenant($voisin, 'POL-SANS-COTATION', null);
        $this->avenant($voisin, 'POL-COTATION-SANS-PISTE', $this->cotation($voisin, 'Cotation voisine sans piste', null));
        $em->flush();
        $em->clear();
        $cabinet = $em->find(Entreprise::class, $cabinet->getId());

        $cle = PisteTransformationScope::CRITERION_KEY;
        self::assertSame(['Piste en cours'], $this->noms(Piste::class, $cle, PisteTransformationScope::STATUT_EN_COURS, $cabinet), '« En cours » ne doit pas se vider.');
        self::assertSame(['Piste transformee'], $this->noms(Piste::class, $cle, PisteTransformationScope::STATUT_TRANSFORMEES, $cabinet));

        $cle = CotationSouscriptionScope::CRITERION_KEY;
        self::assertSame(
            ['Cotation en attente', 'Cotation sans piste'],
            $this->noms(Cotation::class, $cle, CotationSouscriptionScope::STATUT_EN_ATTENTE, $cabinet),
            '« En attente » ne doit pas se vider, et une cotation sans piste non souscrite y figure.',
        );
        self::assertSame(['Cotation souscrite'], $this->noms(Cotation::class, $cle, CotationSouscriptionScope::STATUT_SOUSCRITES, $cabinet));
        self::assertSame([], $this->noms(Cotation::class, $cle, CotationSouscriptionScope::STATUT_CADUQUES, $cabinet));
    }
}
