<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * LA RUBRIQUE « CONGÉS » EXISTE, DANS LE BON GROUPE, AVEC SON PROPRE DROIT.
 *
 * Ce que ce test tient :
 *
 *  1. LA RUBRIQUE EST DANS LE GROUPE ADMINISTRATION, et nulle part ailleurs — c'est le
 *     scénario 24 de la recette.
 *  2. LE PROPRIÉTAIRE VOIT TOUT sans le moindre rôle (bypass).
 *  3. LE DROIT EST UN INTERRUPTEUR : sans lui, rien ; avec lui, la rubrique.
 *  4. VALIDER EST UN NIVEAU, PAS UN RÔLE À PART : le niveau Modification fait le
 *     valideur, et lui seul ouvre la vue sur les demandes de tout le cabinet.
 *  5. LE PARAMÉTRAGE SE CONFIE SÉPARÉMENT : accorder la validation n'ouvre pas les types
 *     d'absence — c'est tout l'objet du second droit.
 *  6. LES ENTITÉS DÉRIVÉES NE SONT PAS EN FAIL-OPEN : mouvement, historique et régime
 *     suivent le droit d'un parent, jamais le `return true` final de can().
 */
class CongeRubriqueTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-conge-rubrique@test.local';
    private const ENT = 'PHPUnit Congés Rubrique SARL';

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
        foreach ([
            'mouvement_conge', 'historique_demande', 'demande_conge', 'regime_travail',
            'jour_ferie', 'type_absence', 'roles_en_administration', 'invite',
        ] as $table) {
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

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Congés')->setVerified(true)->setPassword('x');
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

    /** @param int[] $conge @param int[] $parametre */
    private function droit(Invite $invite, Entreprise $ent, array $conge, array $parametre = []): void
    {
        $roles = (new RolesEnAdministration())->setNom('Administration de ' . $invite->getNom());
        $roles->setAccessConge($conge);
        $roles->setAccessCongeParametre($parametre);
        $roles->setInvite($invite);
        $roles->setEntreprise($ent);
        $this->em()->persist($roles);
        $this->em()->flush();
        $this->em()->refresh($invite);
    }

    // ═══════════ 1. La rubrique est déclarée, dans le bon groupe ═══════════

    /**
     * Scénario 24 : la rubrique apparaît sous Administration, et nulle part ailleurs.
     *
     * On lit le YAML plutôt que le conteneur : c'est le fichier que quelqu'un éditera, et
     * c'est donc lui qui doit porter la vérité.
     */
    public function testLesTroisRubriquesSontDansLeGroupeAdministration(): void
    {
        $menu = Yaml::parseFile(__DIR__ . '/../../config/packages/menu.yaml');
        $groupes = $menu['parameters']['app.menu_data']['colonne_1']['groupes'];

        $administration = $groupes['Administration']['rubriques'];

        self::assertArrayHasKey('Congés', $administration);
        self::assertSame('App\Entity\DemandeConge', $administration['Congés']['entity_name']);
        self::assertArrayHasKey("Types d'absence", $administration);
        self::assertArrayHasKey('Jours fériés', $administration);

        // Et nulle part ailleurs : une rubrique en double se règle deux fois, donc mal.
        foreach ($groupes as $nomGroupe => $groupe) {
            if ($nomGroupe === 'Administration') {
                continue;
            }
            foreach (array_keys($groupe['rubriques'] ?? []) as $rubrique) {
                self::assertNotContains(
                    $rubrique,
                    ['Congés', "Types d'absence", 'Jours fériés'],
                    sprintf('La rubrique « %s » ne doit exister que dans Administration.', $rubrique),
                );
            }
        }
    }

    /**
     * Sans la liste blanche du moteur de recherche, la liste répond 403 dès le premier
     * chargement — et l'onglet reste vide sans qu'on sache pourquoi.
     */
    public function testLesEntitesSontAutoriseesAuMoteurDeRecherche(): void
    {
        foreach (['DemandeConge', 'TypeAbsence', 'JourFerie'] as $entite) {
            self::assertContains(
                $entite,
                JSBDynamicSearchService::$allowedEntities,
                sprintf('%s doit être interrogeable, sinon la rubrique répond 403.', $entite),
            );
        }
    }

    /**
     * Le module déclaré dans la carte doit coïncider avec la collection de rôles lue :
     * « Administration » ↔ getRolesEnAdministration. Une incohérence rendrait le droit
     * inatteignable depuis le gestionnaire des rôles.
     */
    public function testLeDroitEstPropreALaRubrique(): void
    {
        $libelles = $this->resolver()->libellesEntites();

        self::assertSame('Congés', $libelles['DemandeConge'] ?? null);
        self::assertSame("Types d'absence", $libelles['TypeAbsence'] ?? null);
        self::assertSame('Jours fériés', $libelles['JourFerie'] ?? null);
    }

    // ═══════════ 2 & 3. Le propriétaire, puis l'interrupteur ═══════════

    public function testLeProprietaireVoitLaRubriqueSansAucunRole(): void
    {
        $s = $this->semer();

        self::assertTrue($this->resolver()->canRead($s['proprietaire'], 'DemandeConge'));
        self::assertTrue($this->resolver()->canRead($s['proprietaire'], 'TypeAbsence'));
    }

    public function testSansAucunRoleLInviteNAAucunAcces(): void
    {
        $s = $this->semer();

        self::assertFalse($this->resolver()->canRead($s['agent'], 'DemandeConge'));
    }

    public function testAvecLeDroitLInviteLitLaRubrique(): void
    {
        $s = $this->semer();
        $this->droit($s['agent'], $s['entreprise'], [Invite::ACCESS_LECTURE]);

        self::assertTrue($this->resolver()->canRead($s['agent'], 'DemandeConge'));
    }

    /** Scénario 21 : retirer le droit fait disparaître la rubrique du menu, sans redéploiement. */
    public function testLeMenuSuitLeDroit(): void
    {
        $s = $this->semer();
        $menu = ['colonne_1' => ['groupes' => ['Administration' => ['rubriques' => [
            'Congés' => ['entity_name' => 'DemandeConge'],
            'Documents' => ['entity_name' => 'Document'],
        ]]]]];

        $sansDroit = $this->resolver()->filterMenu($menu, $s['agent']);
        self::assertArrayNotHasKey(
            'Congés',
            $sansDroit['colonne_1']['groupes']['Administration']['rubriques'] ?? [],
            'Sans le droit, la rubrique ne doit pas figurer au menu.',
        );

        $this->droit($s['agent'], $s['entreprise'], [Invite::ACCESS_LECTURE]);
        $avecDroit = $this->resolver()->filterMenu($menu, $s['agent']);
        self::assertArrayHasKey(
            'Congés',
            $avecDroit['colonne_1']['groupes']['Administration']['rubriques'] ?? [],
            'Avec le droit, la rubrique doit revenir au menu.',
        );
    }

    // ═══════════ 4. Le niveau Modification fait le valideur ═══════════

    public function testLaLectureSeuleNeFaitPasUnValideur(): void
    {
        $s = $this->semer();
        $this->droit($s['agent'], $s['entreprise'], [Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);

        $policy = static::getContainer()->get(\App\Service\Conge\DemandeCongePolicy::class);

        self::assertFalse(
            $policy->estValideur($s['agent']),
            'Lecture et Écriture permettent de poser un congé, jamais de le valider.',
        );
    }

    public function testLeNiveauModificationFaitLeValideur(): void
    {
        $s = $this->semer();
        $this->droit($s['agent'], $s['entreprise'], [Invite::ACCESS_LECTURE, Invite::ACCESS_MODIFICATION]);

        $policy = static::getContainer()->get(\App\Service\Conge\DemandeCongePolicy::class);

        self::assertTrue($policy->estValideur($s['agent']));
        self::assertContains(
            $s['agent']->getId(),
            array_map(static fn (Invite $i) => $i->getId(), $policy->valideursDe($s['entreprise'])),
        );
    }

    /** Le propriétaire est valideur d'office : il n'a personne au-dessus de lui. */
    public function testLeProprietaireEstValideurDOffice(): void
    {
        $s = $this->semer();
        $policy = static::getContainer()->get(\App\Service\Conge\DemandeCongePolicy::class);

        self::assertTrue($policy->estValideur($s['proprietaire']));
    }

    // ═══════════ 5. Le paramétrage se confie séparément ═══════════

    public function testValiderNOuvrePasLeParametrage(): void
    {
        $s = $this->semer();
        $this->droit($s['agent'], $s['entreprise'], [Invite::ACCESS_LECTURE, Invite::ACCESS_MODIFICATION]);

        self::assertTrue($this->resolver()->canRead($s['agent'], 'DemandeConge'));
        self::assertFalse(
            $this->resolver()->canRead($s['agent'], 'TypeAbsence'),
            "Confier la validation des demandes ne doit pas ouvrir le réglage des droits à congé.",
        );
        self::assertFalse($this->resolver()->canRead($s['agent'], 'JourFerie'));
    }

    public function testLeParametrageSOuvreParSonPropreDroit(): void
    {
        $s = $this->semer();
        $this->droit($s['agent'], $s['entreprise'], [], [Invite::ACCESS_LECTURE]);

        self::assertTrue($this->resolver()->canRead($s['agent'], 'TypeAbsence'));
        self::assertTrue($this->resolver()->canRead($s['agent'], 'JourFerie'));
        self::assertFalse(
            $this->resolver()->canRead($s['agent'], 'DemandeConge'),
            "Et réciproquement : régler les types d'absence ne donne pas accès aux demandes.",
        );
    }

    // ═══════════ 6. Les entités dérivées ne sont pas en fail-open ═══════════

    /**
     * Une entité absente de la carte ET de GOUVERNANCE_PARENT tombe sur le `return true`
     * final de can() — un fail-open. C'est exactement le défaut qu'a corrigé l'ajout de
     * ConditionPartage, et il ne doit pas revenir par les congés.
     */
    public function testLesEntitesDeriveesSuiventLeDroitDUnParent(): void
    {
        $s = $this->semer();

        foreach (['MouvementConge', 'HistoriqueDemande'] as $derivee) {
            self::assertFalse(
                $this->resolver()->can($s['agent'], $derivee, Invite::ACCESS_ECRITURE),
                sprintf('%s ne doit pas être en fail-open : elle suit le droit « Congés ».', $derivee),
            );
        }

        // Le régime de travail, lui, relève de la gestion des invités.
        self::assertFalse(
            $this->resolver()->can($s['agent'], 'RegimeTravail', Invite::ACCESS_ECRITURE),
            'RegimeTravail suit le droit « Invité », donc la gestion des invités.',
        );
        self::assertTrue(
            $this->resolver()->can($s['proprietaire'], 'RegimeTravail', Invite::ACCESS_ECRITURE),
            'Le propriétaire gère les invités, donc leurs régimes de travail.',
        );
    }

    /** Le droit « Congés » accordé ouvre aussi ses entités dérivées, et pas avant. */
    public function testLeDroitCongeOuvreSesEntitesDerivees(): void
    {
        $s = $this->semer();
        $this->droit($s['agent'], $s['entreprise'], [Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);

        self::assertTrue($this->resolver()->can($s['agent'], 'MouvementConge', Invite::ACCESS_ECRITURE));
        self::assertTrue($this->resolver()->can($s['agent'], 'HistoriqueDemande', Invite::ACCESS_ECRITURE));
    }
}
