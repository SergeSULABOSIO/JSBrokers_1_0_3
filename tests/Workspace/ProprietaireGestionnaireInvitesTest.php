<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\ServiceProvisionEntreprise;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * LE PROPRIÉTAIRE EST TOUJOURS GESTIONNAIRE DES INVITÉS — et la base le dit.
 *
 * `canManageInvites()` le lui accorde par son seul statut, sans regarder le drapeau : ce
 * dernier ne décidait donc rien pour lui, il se contentait de dire le contraire à
 * l'écran. Un propriétaire ouvrant sa propre fiche y trouvait une case décochée en face
 * d'un pouvoir qu'il détient.
 */
class ProprietaireGestionnaireInvitesTest extends WebTestCase
{
    private const ENT = 'PHPUnit-Proprietaire-Gestionnaire';
    private const OWNER = 'phpunit-proprio-gestionnaire@test.local';
    private const PASSWORD = 'Test1234!';

    private $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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
        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:e)',
            ['e' => [self::OWNER]],
            ['e' => ArrayParameterType::STRING],
        );

        foreach ([
            'roles_en_administration', 'roles_en_finance', 'roles_en_marketing',
            'roles_en_production', 'roles_en_sinistre', 'mouvement_conge', 'type_absence',
            'type_revenu', 'chargement', 'autorite_fiscale', 'taxe', 'risque', 'groupe', 'monnaie',
        ] as $table) {
            $conn->executeStatement(
                sprintf('DELETE t FROM %s t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n', $table),
                ['n' => self::ENT],
            );
        }

        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :o', ['o' => self::OWNER]);
        $this->em->clear();
    }

    /** Un cabinet provisionné comme le fait l'assistant d'onboarding. */
    private function provisionner(): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new Utilisateur();
        $user->setEmail(self::OWNER)->setNom('Propriétaire');
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $user->setVerified(true);
        $this->em->persist($user);

        // Le rattachement au créateur est posé par le POST_SUBMIT du formulaire dans le
        // vrai parcours ; `provisionner()` le suppose acquis (`entreprise.utilisateur_id`
        // est NOT NULL et le premier flush a lieu avant).
        $entreprise = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setUtilisateur($user);

        $invite = static::getContainer()->get(ServiceProvisionEntreprise::class)
            ->provisionner($entreprise, $user);

        $user->setConnectedTo($entreprise);
        $this->em->flush();

        return ['user' => $user, 'entreprise' => $entreprise, 'invite' => $invite];
    }

    /**
     * L'invariant est posé sur le cycle de vie, pas chez les appelants : trois chemins
     * créent l'invité propriétaire, et un quatrième finirait par oublier.
     */
    public function testUnProprietaireNaitGestionnaire(): void
    {
        ['invite' => $invite] = $this->provisionner();

        $this->em->clear();
        $relu = $this->em->getRepository(Invite::class)->find($invite->getId());

        $this->assertTrue($relu->isProprietaire());
        $this->assertTrue($relu->isGestionnaireInvites(), 'Le drapeau doit être écrit EN BASE, pas seulement déduit.');
    }

    /** Même en tentant de l'écrire à false : le cycle de vie a le dernier mot. */
    public function testLeDrapeauNePeutPasNaitreFauxPourUnProprietaire(): void
    {
        ['entreprise' => $entreprise, 'user' => $user] = $this->provisionner();

        $second = (new Invite())->setNom('Second propriétaire')->setUtilisateur($user)
            ->setEntreprise($entreprise)->setProprietaire(true)
            ->setGestionnaireInvites(false);
        $this->em->persist($second);
        $this->em->flush();

        $this->em->clear();
        $this->assertTrue(
            $this->em->getRepository(Invite::class)->find($second->getId())->isGestionnaireInvites(),
        );
    }

    /** Un invité ordinaire, lui, garde ce qu'on lui donne — l'invariant ne vaut que pour le propriétaire. */
    public function testUnInviteOrdinaireNestPasTouche(): void
    {
        ['entreprise' => $entreprise] = $this->provisionner();

        $collegue = (new Invite())->setNom('Collègue')->setEntreprise($entreprise)
            ->setProprietaire(false)->setGestionnaireInvites(false);
        $this->em->persist($collegue);
        $this->em->flush();

        $this->em->clear();
        $this->assertFalse(
            $this->em->getRepository(Invite::class)->find($collegue->getId())->isGestionnaireInvites(),
        );
    }

    /**
     * LA CASE EST VERROUILLÉE SUR LA FICHE DU PROPRIÉTAIRE.
     *
     * Laisser décocher une visibilité qu'il garderait de toute façon aurait fait afficher
     * à l'écran l'inverse de la règle. `disabled` verrouille ET fait ignorer par Symfony
     * toute valeur soumise pour ce champ — une requête forgée n'y peut rien non plus.
     */
    public function testLaCaseEstVerrouilleeSurLaFicheDuProprietaire(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite, 'user' => $user] = $this->provisionner();

        $collegue = (new Invite())->setNom('Collègue')->setEntreprise($entreprise)->setProprietaire(false);
        $this->em->persist($collegue);
        $this->em->flush();

        $this->client->loginUser($user);

        $sienne = $this->formulaire($entreprise, $invite, $invite->getId());
        $this->assertMatchesRegularExpression(
            '/name="gestionnaireInvites"[^>]*disabled/',
            $sienne,
            'Sur sa propre fiche, le propriétaire ne doit pas pouvoir décocher.',
        );
        $this->assertStringContainsString('checked', $sienne);

        // Sur la fiche d'un collaborateur, en revanche, la case reste bien décidable :
        // c'est tout l'objet de la délégation.
        $autre = $this->formulaire($entreprise, $invite, $collegue->getId());
        $this->assertDoesNotMatchRegularExpression('/name="gestionnaireInvites"[^>]*disabled/', $autre);
    }

    private function formulaire(Entreprise $entreprise, Invite $invite, int $cible): string
    {
        $this->client->request('GET', sprintf(
            '/admin/invite/api/get-form/%d?idEntreprise=%d&idInvite=%d',
            $cible,
            $entreprise->getId(),
            $invite->getId(),
        ));
        $this->assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /** Et le résolveur reste d'accord avec la base. */
    public function testLeResolveurEtLaBaseDisentLaMemeChose(): void
    {
        ['invite' => $invite] = $this->provisionner();
        $resolver = static::getContainer()->get(WorkspaceAccessResolver::class);

        $this->assertTrue($resolver->canManageInvites($invite));
        $this->assertTrue($invite->isGestionnaireInvites());
    }
}
