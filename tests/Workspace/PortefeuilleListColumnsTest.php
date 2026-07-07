<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Portefeuille;
use App\Entity\Utilisateur;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels des colonnes monétaires de la liste des portefeuilles :
 *  - le canvas de liste expose les 4 colonnes numériques (Primes / Sinistres /
 *    Commissions / Réserve) et chaque attribut_code correspond bien à une propriété
 *    calculée déclarée sur l'entité Portefeuille (garde anti-typo canvas ↔ entité) ;
 *  - PortefeuilleIndicatorStrategy hydrate ces attributs (jamais null, même sans
 *    production : agrégats à 0.0) via CanvasBuilder::loadAllCalculatedValues ;
 *  - la rubrique Portefeuille (module Production) rend les en-têtes de colonnes.
 *
 * On agit en tant que PROPRIÉTAIRE de l'entreprise (bypass du contrôle d'accès) pour
 * isoler la logique testée. Chaque test crée ses données et les nettoie.
 */
class PortefeuilleListColumnsTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-plc-owner@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit PfListColumns SARL';

    private const PF_NOM = 'PHPUNIT-PLC-PF';
    private const GEST_NOM = 'PHPUNIT-PLC-GESTIONNAIRE';
    private const CLI_NOM = 'PHPUNIT-PLC-CLIENT';

    /** Colonnes attendues : titre => attribut calculé porté par l'entité Portefeuille. */
    private const EXPECTED_COLUMNS = [
        'Primes'      => 'primeTotale',
        'Sinistres'   => 'indemnisationVersee',
        'Commissions' => 'montantTTC',
        'Réserve'     => 'reserve',
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $nom = self::ENTREPRISE_NOM;

        $conn->executeStatement(
            "UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e",
            ['e' => self::OWNER_EMAIL]
        );

        // Ordre des FK : client → portefeuille → invite → entreprise → utilisateur.
        $conn->executeStatement("DELETE c FROM client c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE pf FROM portefeuille pf JOIN entreprise e ON pf.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :e", ['e' => self::OWNER_EMAIL]);
    }

    /**
     * Jeu de données : entreprise + propriétaire connecté, un invité gestionnaire d'un
     * portefeuille auquel un client est rattaché.
     *
     * @return array{owner: Invite, entreprise: Entreprise, portefeuille: Portefeuille}
     */
    private function seed(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER_EMAIL);
        $ownerUser->setNom('PHPUnit Owner');
        $ownerUser->setVerified(true);
        $ownerUser->setPassword($hasher->hashPassword($ownerUser, self::PASSWORD));
        $em->persist($ownerUser);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-TEST');
        $entreprise->setAdresse('1 rue du Test');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-TEST');
        $entreprise->setIdnat('IDNAT-TEST');
        $entreprise->setNumimpot('IMP-TEST');
        $entreprise->setUtilisateur($ownerUser);
        $ownerUser->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $ownerInvite = new Invite();
        $ownerInvite->setNom('Administrateur');
        $ownerInvite->setUtilisateur($ownerUser);
        $ownerInvite->setEntreprise($entreprise);
        $ownerInvite->setProprietaire(true);
        $em->persist($ownerInvite);

        $gestionnaire = new Invite();
        $gestionnaire->setNom(self::GEST_NOM);
        $gestionnaire->setEntreprise($entreprise);
        $gestionnaire->setProprietaire(false);
        $em->persist($gestionnaire);

        $portefeuille = new Portefeuille();
        $portefeuille->setNom(self::PF_NOM);
        $portefeuille->setGestionnaire($gestionnaire);
        $portefeuille->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $clientIn = new Client();
        $clientIn->setNom(self::CLI_NOM);
        $clientIn->setExonere(false);
        $clientIn->setEntreprise($entreprise);
        $portefeuille->addClient($clientIn);
        $em->persist($clientIn);

        $em->flush();

        // Le KernelBrowser partage l'EM du test : on recharge les entités pour que les
        // collections inverses soient des PersistentCollection lazy-loadées depuis la
        // base, comme dans une vraie requête.
        $ids = [
            'owner' => $ownerInvite->getId(),
            'entreprise' => $entreprise->getId(),
            'portefeuille' => $portefeuille->getId(),
        ];
        $em->clear();

        return [
            'owner' => $em->getRepository(Invite::class)->find($ids['owner']),
            'entreprise' => $em->getRepository(Entreprise::class)->find($ids['entreprise']),
            'portefeuille' => $em->getRepository(Portefeuille::class)->find($ids['portefeuille']),
        ];
    }

    public function testListCanvasExposesMonetaryColumns(): void
    {
        $canvasBuilder = static::getContainer()->get(CanvasBuilder::class);
        $listeCanvas = $canvasBuilder->getListeCanvas(Portefeuille::class);

        $colonnes = $listeCanvas['colonnes_numeriques'] ?? [];
        $this->assertCount(
            count(self::EXPECTED_COLUMNS),
            $colonnes,
            'La liste des portefeuilles doit exposer les colonnes Primes / Sinistres / Commissions / Réserve.'
        );

        $byTitle = array_column($colonnes, null, 'titre_colonne');
        foreach (self::EXPECTED_COLUMNS as $titre => $attributCode) {
            $this->assertArrayHasKey($titre, $byTitle, "La colonne « $titre » doit être déclarée dans le canvas.");
            $this->assertSame(
                $attributCode,
                $byTitle[$titre]['attribut_code'],
                "La colonne « $titre » doit lire l'attribut calculé « $attributCode »."
            );
            // Garde anti-typo : l'attribut du canvas doit exister sur l'entité, sinon le
            // rendu Twig (attribute(entity, code)) échouerait silencieusement.
            $this->assertTrue(
                property_exists(Portefeuille::class, $attributCode),
                "L'attribut « $attributCode » doit être une propriété déclarée de Portefeuille."
            );
            $this->assertSame('nombre', $byTitle[$titre]['attribut_type'], "La colonne « $titre » doit être typée nombre.");
        }
    }

    public function testCalculatedIndicatorsHydrateMonetaryAttributes(): void
    {
        ['portefeuille' => $portefeuille] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $canvasBuilder = static::getContainer()->get(CanvasBuilder::class);
        $canvasBuilder->loadAllCalculatedValues($portefeuille);

        // Sans production (aucune cotation), les agrégats valent 0.0 mais ne sont JAMAIS
        // null : la liste affiche « 0,00 » (un zéro réel) et non « — » (valeur absente).
        foreach (self::EXPECTED_COLUMNS as $titre => $attributCode) {
            $this->assertNotNull(
                $portefeuille->{$attributCode},
                "L'attribut « $attributCode » (colonne « $titre ») doit être hydraté par la stratégie."
            );
            $this->assertIsFloat($portefeuille->{$attributCode});
        }
        $this->assertSame(1, $portefeuille->nombreClients, 'Le comptage des clients rattachés doit rester intact (non-régression).');
    }

    public function testPortefeuilleListRendersMonetaryColumnHeaders(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // La rubrique Portefeuille (module Production) rend le tableau avec les en-têtes
        // des colonnes monétaires et la ligne du portefeuille seedé.
        $this->client->request(
            'GET',
            sprintf('/espacedetravail/api/load-component/%d/%d', $owner->getId(), $e->getId()),
            ['component' => '_view_manager_production.html.twig', 'entity' => 'Portefeuille']
        );
        $this->assertResponseIsSuccessful('La rubrique Portefeuilles doit se charger.');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString(self::PF_NOM, $html, 'Le portefeuille seedé doit apparaître dans la liste.');
        foreach (array_keys(self::EXPECTED_COLUMNS) as $titre) {
            $this->assertStringContainsString($titre, $html, "L'en-tête de colonne « $titre » doit être rendu.");
        }
        // Non-régression : la colonne principale et la ligne secondaire (nom du
        // gestionnaire — le template n'affiche que la valeur, portée par son icône).
        $this->assertStringContainsString('Portefeuilles', $html);
        $this->assertStringContainsString(self::GEST_NOM, $html, 'Le gestionnaire doit rester rendu sur la ligne secondaire.');
    }
}
