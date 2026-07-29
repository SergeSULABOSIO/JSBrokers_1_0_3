<?php

namespace App\Tests\Services;

use App\Entity\Assureur;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Services\Canvas\SearchCanvasProvider;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Recherche avancée de la rubrique Propositions (Cotation) : critères de relation additionnels
 * « selon le client », « selon le risque » et « selon l'assureur ». Le client et le risque ne
 * sont pas des relations directes de Cotation — ils s'atteignent via la piste (piste.client,
 * piste.risque) — mais le moteur traverse les chemins de relation (SOUS-CAS 2.1). L'assureur est
 * une relation directe. On vérifie que chaque filtre isole exactement les cotations attendues et
 * que le canevas de recherche expose bien les trois critères (rendus en sélecteurs autocomplétés).
 */
class JSBDynamicSearchServiceCotationRelationsTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-cotrel-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit CotRel SARL';

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

    private function service(): JSBDynamicSearchService
    {
        return static::getContainer()->get(JSBDynamicSearchService::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        foreach (['cotation', 'piste', 'client', 'risque', 'assureur', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM]
            );
        }
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :email", ['email' => self::OWNER_EMAIL]);
    }

    private function makeEntreprise(Utilisateur $owner): Entreprise
    {
        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-CR')->setAdresse('1 rue des Relations')
            ->setTelephone('+243000000021')->setRccm('RCCM-CR')->setIdnat('IDNAT-CR')->setNumimpot('IMP-CR');
        $entreprise->setUtilisateur($owner);
        $this->em()->persist($entreprise);

        return $entreprise;
    }

    /**
     * Plan croisé prouvant l'indépendance des trois filtres :
     *  - Piste P1 : client C1, risque R1 ; Piste P2 : client C2, risque R2.
     *  - cot1 sur P1 (assureur A1), cot2 sur P1 (assureur A2), cot3 sur P2 (assureur A1).
     * Donc : client C1 → {cot1, cot2} ; risque R2 → {cot3} ; assureur A1 → {cot1, cot3} ; etc.
     *
     * @return array<string, int>
     */
    private function seed(): array
    {
        $em = $this->em();

        $owner = new Utilisateur();
        $owner->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit CotRel')->setVerified(true)->setPassword('irrelevant');
        $em->persist($owner);

        $entreprise = $this->makeEntreprise($owner);
        $invite = (new Invite())->setNom('Gestionnaire CR')->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        $client1 = (new Client())->setNom('Client Un')->setExonere(false);
        $client1->setEntreprise($entreprise);
        $em->persist($client1);
        $client2 = (new Client())->setNom('Client Deux')->setExonere(false);
        $client2->setEntreprise($entreprise);
        $em->persist($client2);

        $risque1 = (new Risque())->setNomComplet('Risque Un')->setCode('R1')->setDescription('Risque Un')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque1->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($risque1);
        $risque2 = (new Risque())->setNomComplet('Risque Deux')->setCode('R2')->setDescription('Risque Deux')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque2->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($risque2);

        $assureur1 = (new Assureur())->setNom('Assureur Un')->setEmail('assureur-un@test.local')
            ->setNumimpot('IMP-A1')->setIdnat('IDNAT-A1')->setRccm('RCCM-A1');
        $assureur1->setEntreprise($entreprise);
        $em->persist($assureur1);
        $assureur2 = (new Assureur())->setNom('Assureur Deux')->setEmail('assureur-deux@test.local')
            ->setNumimpot('IMP-A2')->setIdnat('IDNAT-A2')->setRccm('RCCM-A2');
        $assureur2->setEntreprise($entreprise);
        $em->persist($assureur2);

        $piste1 = (new Piste())->setNom('Piste Un')->setTypeAvenant(0)->setDescriptionDuRisque('Desc R1')
            ->setExercice(2026)->setClient($client1)->setRisque($risque1)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste1);
        $piste2 = (new Piste())->setNom('Piste Deux')->setTypeAvenant(0)->setDescriptionDuRisque('Desc R2')
            ->setExercice(2026)->setClient($client2)->setRisque($risque2)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste2);

        $cot1 = (new Cotation())->setNom('Cotation 1')->setDuree(365)->setPiste($piste1)->setAssureur($assureur1);
        $cot1->setEntreprise($entreprise);
        $em->persist($cot1);
        $cot2 = (new Cotation())->setNom('Cotation 2')->setDuree(365)->setPiste($piste1)->setAssureur($assureur2);
        $cot2->setEntreprise($entreprise);
        $em->persist($cot2);
        $cot3 = (new Cotation())->setNom('Cotation 3')->setDuree(365)->setPiste($piste2)->setAssureur($assureur1);
        $cot3->setEntreprise($entreprise);
        $em->persist($cot3);

        $em->flush();
        $ids = [
            'entreprise' => $entreprise->getId(),
            'client1' => $client1->getId(),
            'client2' => $client2->getId(),
            'risque1' => $risque1->getId(),
            'risque2' => $risque2->getId(),
            'assureur1' => $assureur1->getId(),
            'assureur2' => $assureur2->getId(),
            'cot1' => $cot1->getId(),
            'cot2' => $cot2->getId(),
            'cot3' => $cot3->getId(),
        ];
        $em->clear();

        return $ids;
    }

    /**
     * @param array<string, mixed> $resultat
     * @return int[]
     */
    private function ids(array $resultat): array
    {
        return array_map(static fn (Cotation $c) => $c->getId(), $resultat['data']);
    }

    private function rechercher(int $entrepriseId, string $critere, int $valeurId): array
    {
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($entrepriseId);

        return $this->service()->search(
            Cotation::class,
            [$critere => ['operator' => '=', 'value' => $valeurId]],
            $entreprise,
        );
    }

    public function testFiltreParClient(): void
    {
        $s = $this->seed();

        $c1 = $this->rechercher($s['entreprise'], 'piste.client', $s['client1']);
        $this->assertNull($c1['status']['error']);
        $this->assertEqualsCanonicalizing([$s['cot1'], $s['cot2']], $this->ids($c1));

        $c2 = $this->rechercher($s['entreprise'], 'piste.client', $s['client2']);
        $this->assertSame([$s['cot3']], $this->ids($c2));
    }

    public function testFiltreParRisque(): void
    {
        $s = $this->seed();

        $r1 = $this->rechercher($s['entreprise'], 'piste.risque', $s['risque1']);
        $this->assertEqualsCanonicalizing([$s['cot1'], $s['cot2']], $this->ids($r1));

        $r2 = $this->rechercher($s['entreprise'], 'piste.risque', $s['risque2']);
        $this->assertSame([$s['cot3']], $this->ids($r2));
    }

    public function testFiltreParAssureur(): void
    {
        $s = $this->seed();

        $a1 = $this->rechercher($s['entreprise'], 'assureur', $s['assureur1']);
        $this->assertEqualsCanonicalizing([$s['cot1'], $s['cot3']], $this->ids($a1));

        $a2 = $this->rechercher($s['entreprise'], 'assureur', $s['assureur2']);
        $this->assertSame([$s['cot2']], $this->ids($a2));
    }

    public function testFiltresCombines(): void
    {
        $s = $this->seed();
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($s['entreprise']);

        // Client C1 ET assureur A1 : seule cot1 (cot2 = C1 mais A2, cot3 = A1 mais C2).
        $resultat = $this->service()->search(
            Cotation::class,
            [
                'piste.client' => ['operator' => '=', 'value' => $s['client1']],
                'assureur' => ['operator' => '=', 'value' => $s['assureur1']],
            ],
            $entreprise,
        );
        $this->assertSame([$s['cot1']], $this->ids($resultat));
    }

    public function testCanevasDeRechercheExposeLesTroisCriteres(): void
    {
        /** @var SearchCanvasProvider $provider */
        $provider = static::getContainer()->get(SearchCanvasProvider::class);

        $parNom = [];
        foreach ($provider->getCanvas(Cotation::class) as $critere) {
            $parNom[$critere['Nom']] = $critere;
        }

        foreach (['piste.client' => 'Client', 'piste.risque' => 'Risque', 'assureur' => 'Assureur'] as $nom => $cible) {
            $this->assertArrayHasKey($nom, $parNom, "Le critère « {$cible} » doit être exposé à la recherche avancée.");
            $this->assertSame('Relation', $parNom[$nom]['Type'], "Le critère « {$cible} » est un sélecteur de relation.");
            $this->assertSame($cible, $parNom[$nom]['targetEntity'], "Le critère « {$cible} » cible la bonne entité.");
        }
    }
}
