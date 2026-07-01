<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnFinance;
use App\Entity\RolesEnProduction;
use App\Entity\RolesEnSinistre;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceAccessResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests de la décision d'accès de l'espace de travail (rôles des invités rendus
 * effectifs). Logique pure : on construit des entités en mémoire (non persistées) et
 * on interroge le WorkspaceAccessResolver, source unique du contrôle d'accès.
 *
 * Couverture : fail-closed (aucun rôle = aucun accès), bypass propriétaire, niveaux
 * (Lecture/Écriture/Modification/Suppression), alias non triviaux (Chargement,
 * PieceSinistre partageant accessTypePiece), gestion des invités (propriétaire/délégué),
 * filtrage du menu et description du périmètre.
 */
class WorkspaceAccessResolverTest extends KernelTestCase
{
    private WorkspaceAccessResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resolver = static::getContainer()->get(WorkspaceAccessResolver::class);
    }

    /** Invité NON propriétaire, rattaché à une entreprise appartenant à quelqu'un d'autre. */
    private function guestInvite(): Invite
    {
        $entreprise = new Entreprise();
        $entreprise->setUtilisateur(new Utilisateur()); // propriétaire = un autre compte

        $invite = new Invite();
        $invite->setProprietaire(false);
        $invite->setEntreprise($entreprise);

        return $invite;
    }

    private function ownerInvite(): Invite
    {
        $invite = new Invite();
        $invite->setProprietaire(true);

        return $invite;
    }

    public function testFailClosedWithoutAnyRole(): void
    {
        $invite = $this->guestInvite();

        $this->assertFalse($this->resolver->canRead($invite, 'Client'), 'Sans rôle, aucun accès (fail-closed).');
        $this->assertFalse($this->resolver->can($invite, 'Taxe', Invite::ACCESS_ECRITURE));
        $this->assertFalse($this->resolver->hasAnyPerimetre($invite), 'Un invité sans rôle n\'a aucun périmètre.');
    }

    public function testOwnerHasFullAccess(): void
    {
        $invite = $this->ownerInvite();

        foreach (['Client', 'Taxe', 'Note', 'Piste', 'Document'] as $entity) {
            foreach (array_keys(WorkspaceAccessResolver::LEVEL_LABELS) as $level) {
                $this->assertTrue($this->resolver->can($invite, $entity, $level), "Le propriétaire doit tout pouvoir ($entity/$level).");
            }
        }
        $this->assertTrue($this->resolver->canManageInvites($invite));
        $this->assertTrue($this->resolver->hasAnyPerimetre($invite));
    }

    public function testPerLevelEnforcement(): void
    {
        $invite = $this->guestInvite();
        // Client : Lecture + Écriture uniquement (pas Modification ni Suppression).
        $role = new RolesEnProduction();
        $role->setAccessClient([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);
        $invite->addRolesEnProduction($role);

        $this->assertTrue($this->resolver->can($invite, 'Client', Invite::ACCESS_LECTURE));
        $this->assertTrue($this->resolver->can($invite, 'Client', Invite::ACCESS_ECRITURE));
        $this->assertFalse($this->resolver->can($invite, 'Client', Invite::ACCESS_MODIFICATION));
        $this->assertFalse($this->resolver->can($invite, 'Client', Invite::ACCESS_SUPPRESSION));
        // Une autre entité de la même famille reste fermée.
        $this->assertFalse($this->resolver->canRead($invite, 'Risque'));
        $this->assertTrue($this->resolver->hasAnyPerimetre($invite));
    }

    public function testNonTrivialAliasChargement(): void
    {
        $invite = $this->guestInvite();
        $role = new RolesEnFinance();
        $role->setAccessTypeChargement([Invite::ACCESS_LECTURE]); // champ « TypeChargement » → entité Chargement
        $invite->addRolesEnFinance($role);

        $this->assertTrue($this->resolver->canRead($invite, 'Chargement'), 'Chargement doit lire accessTypeChargement.');
        $this->assertFalse($this->resolver->canRead($invite, 'Note'));
    }

    public function testPieceSinistreSharesTypePieceRight(): void
    {
        $invite = $this->guestInvite();
        $role = new RolesEnSinistre();
        $role->setAccessTypePiece([Invite::ACCESS_LECTURE]);
        $invite->addRolesEnSinistre($role);

        // Le droit « Types pièces » gouverne aussi les « Pièces Sinistre » (pas de trou d'accès).
        $this->assertTrue($this->resolver->canRead($invite, 'ModelePieceSinistre'));
        $this->assertTrue($this->resolver->canRead($invite, 'PieceSinistre'));
        // Mais pas les règlements, non couverts par ce champ.
        $this->assertFalse($this->resolver->canRead($invite, 'OffreIndemnisationSinistre'));
    }

    public function testInviteAndRoleEntitiesRequireManagement(): void
    {
        $guest = $this->guestInvite();
        // Même avec un rôle métier, un invité ordinaire ne gère pas les invités/rôles.
        $role = new RolesEnProduction();
        $role->setAccessClient([Invite::ACCESS_LECTURE]);
        $guest->addRolesEnProduction($role);

        $this->assertFalse($this->resolver->canManageInvites($guest));
        $this->assertFalse($this->resolver->canRead($guest, 'Invite'));
        $this->assertFalse($this->resolver->can($guest, 'RolesEnFinance', Invite::ACCESS_ECRITURE));

        // Délégué explicitement désigné : peut gérer les invités et leurs rôles…
        $delegate = $this->guestInvite();
        $delegate->setGestionnaireInvites(true);
        $this->assertTrue($this->resolver->canManageInvites($delegate));
        $this->assertTrue($this->resolver->canRead($delegate, 'Invite'));
        $this->assertTrue($this->resolver->can($delegate, 'RolesEnFinance', Invite::ACCESS_SUPPRESSION));
        // … mais n'obtient AUCUN privilège sur les données métier.
        $this->assertFalse($this->resolver->canRead($delegate, 'Client'), 'Le délégué n\'a pas de droit data implicite.');
    }

    public function testFilterMenuKeepsOnlyReadableRubriques(): void
    {
        $invite = $this->guestInvite();
        $role = new RolesEnProduction();
        $role->setAccessClient([Invite::ACCESS_LECTURE]);
        $invite->addRolesEnProduction($role);

        $menu = ['colonne_1' => ['groupes' => [
            'Finances'   => ['rubriques' => [
                'Taxes' => ['entity_name' => 'Taxe'],
                'Notes' => ['entity_name' => 'Note'],
            ]],
            'Production' => ['rubriques' => [
                'Clients' => ['entity_name' => 'Client'],
                'Risques' => ['entity_name' => 'Risque'],
            ]],
            'Assistance' => ['rubriques' => [
                'Support' => ['composant_twig' => '_support_component.html.twig'], // sans entity_name
            ]],
        ]]];

        $filtered = $this->resolver->filterMenu($menu, $invite)['colonne_1']['groupes'];

        $this->assertArrayNotHasKey('Finances', $filtered, 'Groupe sans aucune rubrique lisible : retiré.');
        $this->assertArrayHasKey('Production', $filtered);
        $this->assertSame(['Clients'], array_keys($filtered['Production']['rubriques']), 'Seul « Clients » (lisible) reste.');
        $this->assertArrayHasKey('Assistance', $filtered, 'Une rubrique sans entité reste toujours visible.');

        // Le propriétaire conserve le menu complet.
        $ownerFiltered = $this->resolver->filterMenu($menu, $this->ownerInvite())['colonne_1']['groupes'];
        $this->assertArrayHasKey('Finances', $ownerFiltered);
        $this->assertCount(2, $ownerFiltered['Production']['rubriques']);
    }

    public function testDescribePerimetre(): void
    {
        $owner = $this->ownerInvite();
        $this->assertArrayHasKey('Accès', $this->resolver->describePerimetre($owner));

        $invite = $this->guestInvite();
        $role = new RolesEnProduction();
        $role->setAccessClient([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);
        $invite->addRolesEnProduction($role);

        $details = $this->resolver->describePerimetre($invite);
        $this->assertArrayHasKey('Production', $details);
        $this->assertStringContainsString('Clients', $details['Production']);
        $this->assertStringContainsString('Lecture', $details['Production']);
        $this->assertStringContainsString('Écriture', $details['Production']);
        $this->assertArrayNotHasKey('Finances', $details, 'Aucun droit Finance ne doit apparaître.');
    }
}
