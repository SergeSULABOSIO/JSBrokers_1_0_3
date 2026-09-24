<?php

namespace App\Tests\Console;

use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\Utilisateur;
use App\Enum\Departement;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'ÉCRAN « RÉGLAGES DE KET » — inventaire, en lecture seule.
 *
 * Trois choses à protéger :
 *  - IL EST OUVERT AU-DELÀ DU SUPER-ADMIN, et c'est délibéré. Le support et le
 *    commercial ont besoin de savoir ce que Ket sait faire ; la page ne montre aucune
 *    donnée de cabinet, seulement le catalogue du produit. Finance et RH n'y ont
 *    rien à faire, et le filtre départemental le dit.
 *  - IL NE MONTRE QUE CE QUI EXISTE. Tous les outils du conteneur, avec leur poids
 *    réel : un écran de réglage qui décrit autre chose que la réalité est pire que
 *    pas d'écran du tout.
 *  - IL N'ÉCRIT RIEN. Les interrupteurs arrivent au lot suivant ; d'ici là, aucune
 *    méthode d'écriture ne doit exister sur cette route.
 */
final class ConsoleKetReglagesTest extends WebTestCase
{
    private const CLIENT  = 'phpunit-ketreg-client@test.local';
    private const SUPPORT = 'phpunit-ketreg-support@test.local';
    private const COMMERCE = 'phpunit-ketreg-commerce@test.local';
    private const FINANCE = 'phpunit-ketreg-finance@test.local';
    private const RH      = 'phpunit-ketreg-rh@test.local';
    private const SUPER   = 'phpunit-ketreg-super@test.local';
    private const PASSWORD = 'Test1234!';
    private const URL = '/console/ket/reglages';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        $comptes = [
            self::CLIENT   => [[], null],
            self::SUPPORT  => [['ROLE_ADMIN'], Departement::RELATION_CLIENT],
            self::COMMERCE => [['ROLE_ADMIN'], Departement::COMMERCIAL],
            self::FINANCE  => [['ROLE_ADMIN'], Departement::FINANCE],
            self::RH       => [['ROLE_ADMIN'], Departement::RH],
            self::SUPER    => [['ROLE_SUPER_ADMIN'], Departement::DIRECTION],
        ];

        foreach ($comptes as $email => [$roles, $departement]) {
            $u = (new Utilisateur())
                ->setEmail($email)
                ->setNom('PHPUnit ' . $email)
                ->setVerified(true);
            $u->setRoles($roles);
            $u->setDepartement($departement);
            $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
            $em->persist($u);
        }
        $em->flush();
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
        $this->em()->getConnection()->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => [self::CLIENT, self::SUPPORT, self::COMMERCE, self::FINANCE, self::RH, self::SUPER]],
            ['e' => ArrayParameterType::STRING],
        );
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    public function testUnCourtierClientNAtteintPasLEcran(): void
    {
        $this->client->loginUser($this->user(self::CLIENT));
        $this->client->request('GET', self::URL);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLeSupportEtLeCommercialConsultentLInventaire(): void
    {
        foreach ([self::SUPPORT, self::COMMERCE, self::SUPER] as $email) {
            $this->client->loginUser($this->user($email));
            $this->client->request('GET', self::URL);

            $this->assertResponseIsSuccessful(sprintf('%s doit pouvoir consulter l’inventaire.', $email));
        }
    }

    public function testFinanceEtRhSontHorsPerimetre(): void
    {
        foreach ([self::FINANCE, self::RH] as $email) {
            $this->client->loginUser($this->user($email));
            $this->client->request('GET', self::URL);

            $this->assertResponseStatusCodeSame(403, sprintf('%s n’a rien à faire sur cet écran.', $email));
        }
    }

    /**
     * L'INVENTAIRE DÉCRIT LA RÉALITÉ. On vérifie sur trois outils de nature
     * différente — un de consultation, un d'écriture, un verrouillé — plutôt que sur
     * un seul, pour qu'une régression du filtrage se voie.
     */
    public function testLInventaireMontreLesOutilsAvecLeurPoids(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $crawler = $this->client->request('GET', self::URL);
        $html = (string) $this->client->getResponse()->getContent();

        foreach (['vigie_echeances', 'preparer_operations', 'rechercher_entites'] as $outil) {
            $this->assertStringContainsString($outil, $html, sprintf('L’outil %s manque à l’inventaire.', $outil));
        }

        // LE NOMBRE SE DÉRIVE, IL NE SE FIGE PAS. Écrire « 53 » ici — c'était le
        // chiffre annoncé, et il était faux — obligerait à corriger ce test à chaque
        // outil ajouté, et le transformerait en simple compteur. Comparé au
        // conteneur, il dit ce qui compte vraiment : l'inventaire ne cache rien.
        $attendus = \count(static::getContainer()->get(TrousseCatalogue::class)->tous());

        $this->assertSame(
            $attendus,
            $crawler->filter('details.kr-row')->count(),
            'L’inventaire doit montrer TOUS les outils du conteneur, désactivés compris.'
        );
        $this->assertStringContainsString('jetons', $html, 'Le poids en jetons doit être affiché.');
    }

    /**
     * LE STATUT N'EST JAMAIS PORTÉ PAR LA SEULE COULEUR (WCAG 1.4.1) : un outil
     * verrouillé porte le mot, pas seulement une pastille d'une autre teinte.
     */
    public function testUnOutilVerrouillePorteSonLibelle(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->client->request('GET', self::URL);

        $this->assertStringContainsString('Verrouillé', (string) $this->client->getResponse()->getContent());
    }

    public function testLEncartDAccesAnnonceLaConditionEtLesChiffres(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->client->request('GET', self::URL);
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('solde de jetons payant', $html);
        $this->assertStringContainsString('ne se modifie pas depuis la console', $html);
    }

    public function testLeFiltreParClasseReduitLaListe(): void
    {
        $this->client->loginUser($this->user(self::SUPER));

        $tout = $this->client->request('GET', self::URL)->filter('details.kr-row')->count();
        $verrouilles = $this->client->request('GET', self::URL . '?classe=indispensable')
            ->filter('details.kr-row')->count();

        $this->assertGreaterThan(0, $verrouilles);
        $this->assertLessThan($tout, $verrouilles, 'Le filtre de classe doit réduire la liste.');
    }

    /**
     * AUCUNE ÉCRITURE TANT QUE LES INTERRUPTEURS N'EXISTENT PAS. Une route qui
     * accepterait POST alors que rien ne l'écoute est une porte qu'on oublie
     * d'examiner le jour où elle sert.
     */
    public function testLEcranNAccepteAucuneEcriture(): void
    {
        $this->client->loginUser($this->user(self::SUPER));

        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $methode) {
            $this->client->request($methode, self::URL);
            $this->assertResponseStatusCodeSame(405, sprintf('%s ne doit pas être accepté.', $methode));
        }
    }
}
