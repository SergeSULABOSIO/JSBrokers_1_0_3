<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnFinance;
use App\Entity\RolesEnProduction;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LES RÉTROS AGENTS ONT LEUR PROPRE DROIT, RÉGLABLE.
 *
 * La rubrique empruntait en silence le droit « Avenants » : le cabinet ne pouvait ni
 * l'ouvrir ni la fermer sans toucher aux contrats, et le réglage n'apparaissait NULLE PART
 * dans le gestionnaire des rôles. Or ce qu'elle montre — combien chaque collaborateur a
 * touché — n'a pas la sensibilité d'un contrat.
 *
 * La politique arbitrée est celle de toutes les autres rubriques, et c'est ce que ce test
 * tient :
 *
 *  1. LE PROPRIÉTAIRE VOIT TOUT, sans le moindre rôle (bypass déjà en place).
 *  2. LE DROIT EST UN INTERRUPTEUR : sans lui, rien — pas même au menu ; avec lui, la
 *     rubrique entière, sans filtrage par bénéficiaire.
 *  3. IL EST INDÉPENDANT DU DROIT « AVENANTS ». C'est tout l'objet du changement : les
 *     deux devaient cesser d'être liés, et rien ne le prouve mieux que de n'accorder que
 *     l'un des deux.
 */
class ReversementDroitDedieTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-droit-reversement@test.local';
    private const ENT = 'PHPUnit Droit Reversement SARL';

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

    private function resolver(): WorkspaceAccessResolver
    {
        return static::getContainer()->get(WorkspaceAccessResolver::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        foreach (['roles_en_finance', 'roles_en_production', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /** @return array{entreprise: Entreprise, proprietaire: Invite, agent: Invite} */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Droit')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $proprietaire = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $proprietaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($proprietaire);

        $agent = (new Invite())->setNom('Alice Mukendi')->setProprietaire(false);
        $agent->setEntreprise($ent);
        $em->persist($agent);

        $em->flush();

        return ['entreprise' => $ent, 'proprietaire' => $proprietaire, 'agent' => $agent];
    }

    private function droitFinance(Invite $invite, Entreprise $ent, array $niveaux): void
    {
        $roles = (new RolesEnFinance())->setNom('Finance de ' . $invite->getNom());
        $roles->setAccessReversementRetroAgent($niveaux);
        $roles->setInvite($invite);
        $roles->setEntreprise($ent);
        $this->em()->persist($roles);
        $this->em()->flush();
        $this->em()->refresh($invite);
    }

    private function droitAvenant(Invite $invite, Entreprise $ent, array $niveaux): void
    {
        $roles = (new RolesEnProduction())->setNom('Production de ' . $invite->getNom());
        $roles->setAccessAvenant($niveaux);
        $roles->setInvite($invite);
        $roles->setEntreprise($ent);
        $this->em()->persist($roles);
        $this->em()->flush();
        $this->em()->refresh($invite);
    }

    // ===================== 1. Le propriétaire =====================

    /** Le propriétaire voit tout d'office : c'est le bypass, et il ne bouge pas. */
    public function testLeProprietaireVoitLaRubriqueSansAucunRole(): void
    {
        $s = $this->semer();

        self::assertTrue($this->resolver()->canRead($s['proprietaire'], 'ReversementRetroAgent'));
    }

    // ===================== 2. L'interrupteur =====================

    /** FAIL-CLOSED : sans rôle, rien. */
    public function testSansAucunRoleLInviteNAAucunAcces(): void
    {
        $s = $this->semer();

        self::assertFalse($this->resolver()->canRead($s['agent'], 'ReversementRetroAgent'));
    }

    /** Avec le droit, la rubrique s'ouvre. */
    public function testAvecLeDroitLInviteLitLaRubrique(): void
    {
        $s = $this->semer();
        $this->droitFinance($s['agent'], $s['entreprise'], [Invite::ACCESS_LECTURE]);

        self::assertTrue($this->resolver()->canRead($s['agent'], 'ReversementRetroAgent'));
    }

    /**
     * LA RUBRIQUE DISPARAÎT DU MENU sans le droit, et y revient avec.
     *
     * `canRead` gouverne la règle, mais c'est `filterMenu` que l'utilisateur voit : un
     * droit correct servi par un menu qui l'ignore ne protège rien et ne montre rien.
     */
    public function testLeMenuSuitLeDroit(): void
    {
        $s = $this->semer();
        $menu = ['colonne_1' => ['groupes' => ['Finances' => ['rubriques' => [
            'Rétros intermédiaires' => ['entity_name' => 'ReversementRetroAgent'],
            'Notes' => ['entity_name' => 'Note'],
        ]]]]];

        $sansDroit = $this->resolver()->filterMenu($menu, $s['agent']);
        self::assertArrayNotHasKey(
            'Rétros intermédiaires',
            $sansDroit['colonne_1']['groupes']['Finances']['rubriques'] ?? [],
            'Sans le droit, la rubrique ne doit pas figurer au menu.',
        );

        $this->droitFinance($s['agent'], $s['entreprise'], [Invite::ACCESS_LECTURE]);
        $avecDroit = $this->resolver()->filterMenu($menu, $s['agent']);
        self::assertArrayHasKey(
            'Rétros intermédiaires',
            $avecDroit['colonne_1']['groupes']['Finances']['rubriques'] ?? [],
        );
    }

    /** Le droit de LECTURE n'accorde pas l'écriture : les niveaux restent distincts. */
    public function testLaLectureNAccordePasLEcriture(): void
    {
        $s = $this->semer();
        $this->droitFinance($s['agent'], $s['entreprise'], [Invite::ACCESS_LECTURE]);

        self::assertTrue($this->resolver()->canRead($s['agent'], 'ReversementRetroAgent'));
        self::assertFalse($this->resolver()->can($s['agent'], 'ReversementRetroAgent', Invite::ACCESS_ECRITURE));
    }

    // ===================== 3. L'indépendance vis-à-vis des Avenants =====================

    /**
     * LE DROIT « AVENANTS » N'OUVRE PLUS LES RÉTROS — c'est tout l'objet du changement.
     *
     * Avant, lire les avenants suffisait à voir ce que chaque collègue avait touché, sans
     * que personne l'ait décidé.
     */
    public function testLireLesAvenantsNOuvrePlusLesRetros(): void
    {
        $s = $this->semer();
        $this->droitAvenant($s['agent'], $s['entreprise'], [Invite::ACCESS_LECTURE]);

        self::assertTrue($this->resolver()->canRead($s['agent'], 'Avenant'), 'Le droit Avenants doit rester effectif.');
        self::assertFalse(
            $this->resolver()->canRead($s['agent'], 'ReversementRetroAgent'),
            'Lire les avenants ne doit plus donner accès aux rémunérations des collègues.',
        );
    }

    /** Et réciproquement : voir les rétros n'ouvre pas les contrats. */
    public function testVoirLesRetrosNOuvrePasLesAvenants(): void
    {
        $s = $this->semer();
        $this->droitFinance($s['agent'], $s['entreprise'], [Invite::ACCESS_LECTURE]);

        self::assertTrue($this->resolver()->canRead($s['agent'], 'ReversementRetroAgent'));
        self::assertFalse($this->resolver()->canRead($s['agent'], 'Avenant'));
    }

    /** Le libellé du droit est celui de la rubrique : on règle ce qu'on a sous les yeux. */
    public function testLeDroitPorteLeLibelleDeLaRubrique(): void
    {
        self::assertSame('Rétros intermédiaires', $this->resolver()->libellesEntites()['ReversementRetroAgent'] ?? null);
    }
}
