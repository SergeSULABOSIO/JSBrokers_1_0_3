<?php

namespace App\Tests\Console;

use App\Entity\ErreurApplicative;
use App\Entity\Utilisateur;
use App\Supervision\EnregistreurDErreurs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'ÉCRAN DE SUPERVISION S'OUVRE VRAIMENT — ET SEULEMENT POUR UN AGENT.
 *
 * ── POURQUOI CE TEST EXISTE ─────────────────────────────────────────────────
 * Les tests de comptage (SupervisionDesErreursTest) prouvent que la mécanique
 * est juste ; ils n'ouvrent jamais la page. Le 2026-09-15, la rubrique a répondu
 * 500 en production alors que toute la suite était au vert : un écran qui n'est
 * jamais rendu par un test est un écran dont on ignore s'il s'affiche.
 *
 * C'est d'autant plus ironique ici que cette page est justement celle qui doit
 * révéler les pannes des autres.
 *
 * ── ET LA RÈGLE D'ACCÈS, ÉPROUVÉE PAR L'USAGE ───────────────────────────────
 * La Console est réservée aux agents Joseara. Un courtier client — fût-il
 * administrateur tout-puissant de son propre cabinet — ne doit pas l'atteindre :
 * il y verrait le chiffre d'affaires de la plateforme et la liste de ses
 * concurrents. AccesReserveAuxAgentsTest le vérifie en lisant le code ; ce
 * test-ci le vérifie en frappant à la porte.
 */
final class SupervisionEcranTest extends WebTestCase
{
    private const AGENT  = 'phpunit-sv-agent@test.local';
    private const CLIENT = 'phpunit-sv-client@test.local';
    private const PASSWORD = 'Test1234!';

    private KernelBrowser $navigateur;

    protected function setUp(): void
    {
        $this->navigateur = static::createClient();
        $this->nettoyer();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        // Un agent Joseara, et un client à qui tout est permis DANS son cabinet
        // mais qui n'est pas de la maison.
        foreach ([self::AGENT => ['ROLE_ADMIN'], self::CLIENT => []] as $email => $roles) {
            $compte = new Utilisateur();
            $compte->setEmail($email)
                ->setNom('PHPUnit ' . $email)
                ->setVerified(true)
                ->setRoles($roles)
                ->setPassword($hasher->hashPassword($compte, self::PASSWORD));
            $em->persist($compte);
        }

        $em->flush();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    /** L'écran s'ouvre, base vide — l'état le plus fréquent, et le plus oublié. */
    public function testLaRubriqueSOuvrePourUnAgent(): void
    {
        $this->navigateur->loginUser($this->compte(self::AGENT));
        $this->navigateur->request('GET', '/console/supervision');

        self::assertResponseIsSuccessful('La rubrique de supervision doit s\'ouvrir : c\'est elle qui révèle les pannes des autres.');
    }

    /** Et avec un défaut enregistré : les colonnes, les pastilles, le compteur. */
    public function testLaRubriqueSOuvreAvecUnDefautEnregistre(): void
    {
        $this->unDefaut();

        $this->navigateur->loginUser($this->compte(self::AGENT));
        $crawler = $this->navigateur->request('GET', '/console/supervision');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('RuntimeException', $crawler->filter('table')->text(''));
    }

    /** Le détail, qui porte la trace — et son propre jeu d'icônes. */
    public function testLeDetailDUnDefautSOuvre(): void
    {
        $defaut = $this->unDefaut();

        $this->navigateur->loginUser($this->compte(self::AGENT));
        $this->navigateur->request('GET', '/console/supervision/' . $defaut->getId());

        self::assertResponseIsSuccessful();
    }

    /**
     * ⚠ LA RÈGLE QUI COMPTE : un client n'entre pas.
     *
     * Il est authentifié, il a un espace de travail complet, et il se voit
     * refuser la porte — pas une page vide, un refus.
     */
    public function testUnClientNAccedePasALaSupervision(): void
    {
        $this->navigateur->loginUser($this->compte(self::CLIENT));
        $this->navigateur->request('GET', '/console/supervision');

        self::assertResponseStatusCodeSame(
            403,
            'Un utilisateur client ne doit jamais atteindre la Console : elle contient les données de tous les cabinets.'
        );
    }

    /** Et il n'entre pas davantage par le tableau de bord de la Console. */
    public function testUnClientNAccedePasNonPlusAuTableauDeBordDeLaConsole(): void
    {
        $this->navigateur->loginUser($this->compte(self::CLIENT));
        $this->navigateur->request('GET', '/console');

        self::assertResponseStatusCodeSame(403);
    }

    private function unDefaut(): ErreurApplicative
    {
        $defaut = static::getContainer()->get(EnregistreurDErreurs::class)->enregistrer(
            cote: ErreurApplicative::COTE_SERVEUR,
            branche: ErreurApplicative::BRANCHE_CONSOLE,
            type: 'RuntimeException',
            message: 'Panne simulée pour le rendu de l\'écran',
            fichier: 'src/Essai/Ecran.php',
            ligne: 42,
            trace: '#0 essai',
        );

        self::assertInstanceOf(ErreurApplicative::class, $defaut);

        return $defaut;
    }

    private function compte(string $email): Utilisateur
    {
        $compte = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Utilisateur::class, $compte);

        return $compte;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function nettoyer(): void
    {
        $em = $this->em();

        $em->createQuery('DELETE FROM ' . ErreurApplicative::class . ' e')->execute();
        $em->createQuery('DELETE FROM ' . Utilisateur::class . ' u WHERE u.email IN (:emails)')
            ->setParameter('emails', [self::AGENT, self::CLIENT])
            ->execute();
    }
}
