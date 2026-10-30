<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnFinance;
use App\Entity\Utilisateur;
use App\Services\Canvas\FormCanvasProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UN RÔLE SE CRÉE AUSSI DEPUIS SA LISTE — À TROIS CONDITIONS (Serge, 2026-10-30).
 *
 * Le formulaire d'un rôle laisse choisir le collaborateur : la création depuis la liste
 * principale reste donc permise (les rôles sortent du registre ParentObligatoire), pourvu que
 * 1. le collaborateur soit exigé PAR LE SERVEUR — sans lui, AuditableTrait posait d'office
 *    l'invité connecté : un rôle muet pour soi-même ;
 * 2. il appartienne au cabinet ouvert (404 sinon, AppartenanceAuCabinet) ;
 * 3. un SECOND rôle de la même famille soit refusé avec une explication — les droits d'un
 *    invité sont l'union de ses rôles : un doublon rendait un retrait de droit sans effet.
 */
class RolesCreationTest extends WebTestCase
{
    private const EMAIL = 'phpunit-roles-creation@test.local';
    private const CABINET = 'PHPUnit Roles Creation SARL';
    private const VOISIN = 'PHPUnit Roles Creation Voisin SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = ?', [self::EMAIL]);
        foreach ([self::CABINET, self::VOISIN] as $nom) {
            $id = $conn->fetchOne('SELECT id FROM entreprise WHERE nom = ?', [$nom]);
            if ($id === false) {
                continue;
            }
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach (['roles_en_finance', 'roles_en_marketing', 'roles_en_production', 'roles_en_sinistre', 'roles_en_administration', 'invite'] as $table) {
                    $conn->executeStatement("DELETE FROM `$table` WHERE entreprise_id = ?", [$id]);
                }
                $conn->executeStatement('DELETE FROM entreprise WHERE id = ?', [$id]);
            } finally {
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
        $this->em()->clear();
    }

    /** @return array{proprio: int, collaborateur: int, etranger: int, entreprise: int} */
    private function semer(): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Proprio')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000098')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $voisin = (new Entreprise())->setNom(self::VOISIN)->setLicence('L')->setAdresse('2 rue')
            ->setTelephone('+243000000097')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $em->persist($voisin);
        $owner->setConnectedTo($cabinet);
        $proprio = (new Invite())->setNom('Proprio')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $collaborateur = (new Invite())->setNom('Collaborateur')->setEntreprise($cabinet)->setProprietaire(false);
        $etranger = (new Invite())->setNom('Etranger')->setEntreprise($voisin)->setProprietaire(false);
        foreach ([$proprio, $collaborateur, $etranger] as $invite) {
            $em->persist($invite);
        }
        $em->flush();
        $this->client->loginUser($owner);
        $s = ['proprio' => (int) $proprio->getId(), 'collaborateur' => (int) $collaborateur->getId(), 'etranger' => (int) $etranger->getId(), 'entreprise' => (int) $cabinet->getId()];
        $em->clear();

        return $s;
    }

    private function poster(array $plus = []): int
    {
        $this->client->request('POST', '/admin/rolesenfinance/api/submit', ['nom' => 'Droits Finance'] + $plus);

        return $this->client->getResponse()->getStatusCode();
    }

    private function message(): string
    {
        return (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['message'] ?? '');
    }

    private function rolesDe(int $invite): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM roles_en_finance WHERE invite_id = ?', [$invite]);
    }

    public function testLaListePrincipaleOffreDeNouveauAjouter(): void
    {
        $s = $this->semer();
        $parametres = static::getContainer()->get(FormCanvasProvider::class)->getCanvas(new RolesEnFinance(), $s['entreprise'])['parametres'] ?? [];

        self::assertTrue($parametres['ajout']['offert'] ?? null, 'Le formulaire choisit le collaborateur : la liste principale peut créer.');
    }

    public function testSansCollaborateurLeServeurRefuseAuLieuDeLeChoisirPourVous(): void
    {
        $s = $this->semer();

        self::assertSame(422, $this->poster(), $this->message());
        self::assertStringContainsString('collaborateur', $this->message());
        self::assertSame(0, $this->rolesDe($s['proprio']), 'Aucun rôle muet pour celui qui crée.');
    }

    public function testUnCollaborateurDUnAutreCabinetEstIntrouvable(): void
    {
        $s = $this->semer();

        self::assertSame(404, $this->poster(['invite' => (string) $s['etranger']]));
        self::assertSame(0, $this->rolesDe($s['etranger']));
    }

    public function testUnSecondRoleDeLaMemeFamilleEstRefuseAvecUneExplication(): void
    {
        $s = $this->semer();

        self::assertSame(200, $this->poster(['invite' => (string) $s['collaborateur']]), $this->message());
        self::assertSame(422, $this->poster(['invite' => (string) $s['collaborateur']]));
        self::assertStringContainsString('déjà un rôle Finance', $this->message());
        self::assertSame(1, $this->rolesDe($s['collaborateur']));
    }

    public function testLOngletDeLaFicheNOffrePasUnSecondRole(): void
    {
        $s = $this->semer();
        self::assertSame(200, $this->poster(['invite' => (string) $s['collaborateur']]), $this->message());
        $this->em()->clear();

        $canevas = static::getContainer()->get(FormCanvasProvider::class)
            ->getCanvas($this->em()->find(Invite::class, $s['collaborateur']), $s['entreprise']);
        $motifs = [];
        foreach ($canevas['form_layout'] ?? [] as $rangee) {
            $champ = $rangee['colonnes'][0]['champs'][0] ?? null;
            if (is_array($champ) && ($champ['field_code'] ?? null) !== null) {
                $motifs[$champ['field_code']] = $champ['options']['ajoutImpossible'] ?? null;
            }
        }

        self::assertStringContainsString('déjà un rôle Finance', (string) ($motifs['rolesEnFinance'] ?? ''), 'Le « + » cède la place à son explication.');
        self::assertNull($motifs['rolesEnMarketing'] ?? null, 'Les autres familles restent ouvertes.');
    }
}
