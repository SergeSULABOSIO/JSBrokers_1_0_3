<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\Utilisateur;
use App\Service\Conge\DemandeCongePolicy;
use App\Service\Conge\DroitCongeParDefaut;
use App\Service\Workspace\WorkspaceAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * TOUT COLLABORATEUR PEUT DEMANDER UN CONGÉ DÈS SON ARRIVÉE.
 *
 * ── LA SEULE ATTRIBUTION D'OFFICE DE L'APPLICATION ──────────────────────────────────
 * Partout ailleurs le modèle est fail-closed : un invité n'a que ce que le propriétaire
 * lui coche. Poser un congé n'est pas une faveur qu'on accorde, c'est un droit du contrat
 * de travail — un nouvel arrivant qui doit attendre qu'on lui ouvre la rubrique ne verrait
 * qu'un menu incomplet, sans rien qui lui dise pourquoi.
 *
 * ── MAIS RIEN DE PLUS ───────────────────────────────────────────────────────────────
 * Lecture et Écriture, jamais Modification (qui ferait de chacun un valideur), jamais le
 * paramétrage. Et c'est un VRAI rôle, visible et révocable dans le gestionnaire — pas une
 * exception cachée dans le moteur d'accès, qui accorderait un accès que personne ne
 * pourrait constater.
 */
class CongeDroitsInitiauxTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-conge-droits-owner@test.local';
    private const ENT = 'PHPUnit Congés Droits SARL';

    protected function setUp(): void
    {
        static::createClient();
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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        foreach (['roles_en_administration', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /** @return array{entreprise: Entreprise, nouveau: Invite} */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Patron')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $proprietaire = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $proprietaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($proprietaire);

        // Le nouvel arrivant : aucun rôle, comme au sortir d'une invitation.
        $nouveau = (new Invite())->setNom('Nouvelle Recrue')->setProprietaire(false);
        $nouveau->setEntreprise($ent);
        $em->persist($nouveau);

        $em->flush();

        return ['entreprise' => $ent, 'nouveau' => $nouveau];
    }

    private function droits(): DroitCongeParDefaut
    {
        return static::getContainer()->get(DroitCongeParDefaut::class);
    }

    private function resolver(): WorkspaceAccessResolver
    {
        return static::getContainer()->get(WorkspaceAccessResolver::class);
    }

    public function testUnNouvelInviteNAAucunAccesAvantAttribution(): void
    {
        $s = $this->semer();

        self::assertFalse(
            $this->resolver()->canRead($s['nouveau'], 'DemandeConge'),
            'Le modèle reste fail-closed : rien ne se donne tout seul dans le moteur.',
        );
    }

    public function testLAttributionDOfficeDonneLaLectureEtLEcriture(): void
    {
        $s = $this->semer();

        self::assertTrue($this->droits()->appliquer($s['nouveau']));
        $this->em()->flush();
        $this->em()->refresh($s['nouveau']);

        self::assertTrue($this->resolver()->can($s['nouveau'], 'DemandeConge', Invite::ACCESS_LECTURE));
        self::assertTrue($this->resolver()->can($s['nouveau'], 'DemandeConge', Invite::ACCESS_ECRITURE));
    }

    /** RIEN DE PLUS : ni la validation, ni la suppression, ni le paramétrage. */
    public function testLAttributionDOfficeNeDonneNiLaValidationNiLeParametrage(): void
    {
        $s = $this->semer();
        $this->droits()->appliquer($s['nouveau']);
        $this->em()->flush();
        $this->em()->refresh($s['nouveau']);

        self::assertFalse(
            $this->resolver()->can($s['nouveau'], 'DemandeConge', Invite::ACCESS_MODIFICATION),
            "L'accès de base ne fait de personne un valideur.",
        );
        self::assertFalse($this->resolver()->can($s['nouveau'], 'DemandeConge', Invite::ACCESS_SUPPRESSION));
        self::assertFalse($this->resolver()->canRead($s['nouveau'], 'TypeAbsence'));

        self::assertFalse(
            static::getContainer()->get(DemandeCongePolicy::class)->estValideur($s['nouveau']),
        );
    }

    /**
     * C'EST UN VRAI RÔLE, VISIBLE DANS LE GESTIONNAIRE. L'alternative — un cas particulier
     * dans le moteur d'accès — aurait accordé un accès que le propriétaire n'aurait pu ni
     * constater ni révoquer.
     */
    public function testLeDroitEstUnEnregistrementDeRoleVisibleEtRevocable(): void
    {
        $s = $this->semer();
        $this->droits()->appliquer($s['nouveau']);
        $this->em()->flush();
        $this->em()->refresh($s['nouveau']);

        $roles = $this->em()->getRepository(RolesEnAdministration::class)
            ->findBy(['invite' => $s['nouveau']]);

        self::assertCount(1, $roles);
        self::assertSame(DroitCongeParDefaut::NOM_ROLE, $roles[0]->getNom());
        self::assertSame(
            [Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE],
            $roles[0]->getAccessConge(),
        );

        // Révocable : le propriétaire vide la case, l'accès tombe.
        $roles[0]->setAccessConge([]);
        $this->em()->flush();
        $this->em()->refresh($s['nouveau']);

        self::assertFalse($this->resolver()->canRead($s['nouveau'], 'DemandeConge'));
    }

    /** IDEMPOTENT : on ne défait pas le réglage d'un cabinet en rejouant un semis. */
    public function testLAttributionNeSeRejouePasSurQuiALDejaUnAcces(): void
    {
        $s = $this->semer();

        self::assertTrue($this->droits()->appliquer($s['nouveau']));
        $this->em()->flush();
        $this->em()->refresh($s['nouveau']);

        self::assertFalse(
            $this->droits()->appliquer($s['nouveau']),
            'Un invité déjà servi ne doit pas recevoir un second rôle.',
        );
        $this->em()->flush();

        self::assertCount(
            1,
            $this->em()->getRepository(RolesEnAdministration::class)->findBy(['invite' => $s['nouveau']]),
        );
    }

    /**
     * UN ACCÈS ACCORDÉ AUTREMENT COMPTE AUSSI. Si le propriétaire a déjà confié les congés
     * sous un autre nom de rôle, on n'en ajoute pas un second par-dessus.
     */
    public function testUnAccesDejaAccordeSousUnAutreNomEstReconnu(): void
    {
        $s = $this->semer();

        $roleMaison = (new RolesEnAdministration())->setNom('Rôle RH maison');
        $roleMaison->setAccessConge([Invite::ACCESS_LECTURE, Invite::ACCESS_MODIFICATION]);
        $roleMaison->setInvite($s['nouveau'])->setEntreprise($s['entreprise']);
        $this->em()->persist($roleMaison);
        $this->em()->flush();
        $this->em()->refresh($s['nouveau']);

        self::assertFalse($this->droits()->appliquer($s['nouveau']));
        $this->em()->flush();

        self::assertCount(
            1,
            $this->em()->getRepository(RolesEnAdministration::class)->findBy(['invite' => $s['nouveau']]),
            "On ne superpose pas un accès de base à un réglage que le cabinet a déjà fait.",
        );
    }
}
