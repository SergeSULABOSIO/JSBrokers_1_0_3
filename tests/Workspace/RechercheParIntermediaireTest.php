<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UN ATTRIBUT DE CANEVAS PORTE AUSSI UN FILTRE DE RECHERCHE.
 *
 * En passant l'intermédiaire d'une affaire du pluriel au singulier, son attribut de canevas
 * cesse d'être une « Collection » pour devenir une « Relation ». Ce n'est pas cosmétique :
 * `SearchCanvasProvider` ÉCARTE purement et simplement les collections des critères de
 * recherche (« elles ne sont pas des champs de recherche directs »). L'axe n'existait donc
 * pas, et cette bascule le fait apparaître — il faut alors qu'il réponde.
 *
 * Ce test interroge le moteur de recherche par l'identifiant de l'intermédiaire, comme le
 * fait le sélecteur autocomplété de la recherche avancée.
 */
class RechercheParIntermediaireTest extends KernelTestCase
{
    private const ENT = 'PHPUnit-RechercheInter';
    private const OWNER = 'phpunit-recherche-inter@test.local';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        foreach (['piste', 'client', 'partenaire', 'risque', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    public function testLAxeIntermediaireRamenLesAffairesDeCetIntermediaire(): void
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+243000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($entreprise);

        $invite = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $this->em->persist($invite);

        $intermediaire = (new Partenaire())->setNom('Recherche Courtage')->setPart(5.0);
        $intermediaire->setEntreprise($entreprise);
        $this->em->persist($intermediaire);

        $client = (new Client())->setNom('Client Recherche')->setExonere(false);
        $client->setEntreprise($entreprise);
        $this->em->persist($client);

        $risque = (new Risque())->setCode('RC')->setNomComplet('Risque Recherche')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $this->em->persist($risque);

        $faire = function (string $nom) use ($entreprise, $invite, $client, $risque): Piste {
            $p = (new Piste())->setNom($nom)->setTypeAvenant(0)
                ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))
                ->setClient($client)->setRisque($risque);
            $p->setEntreprise($entreprise)->setInvite($invite);
            $this->em->persist($p);

            return $p;
        };

        $avec = $faire('Affaire avec intermediaire');
        $avec->setPartenaire($intermediaire);
        $faire('Affaire sans intermediaire');

        $this->em->flush();

        /** @var JSBDynamicSearchService $recherche */
        $recherche = static::getContainer()->get(JSBDynamicSearchService::class);
        $resultat = $recherche->search(
            Piste::class,
            ['partenaire' => (string) $intermediaire->getId()],
            $entreprise,
            null,
            1,
            20,
        );

        $noms = array_map(
            static fn ($p) => is_array($p) ? ($p['nom'] ?? '') : $p->getNom(),
            $resultat['data'] ?? [],
        );
        self::assertSame(200, $resultat['status']['code'] ?? null, (string) ($resultat['status']['message'] ?? ''));

        self::assertContains('Affaire avec intermediaire', $noms, 'L\'axe « Intermédiaire » répond.');
        self::assertNotContains(
            'Affaire sans intermediaire',
            $noms,
            'Et il ne ramène pas les affaires qui n\'ont pas cet intermédiaire.',
        );
    }
}
