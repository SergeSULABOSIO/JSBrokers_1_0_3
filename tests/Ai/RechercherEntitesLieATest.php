<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\RechercherEntitesTool;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\RolesEnMarketing;
use App\Entity\Tache;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * rechercher_entites, paramètre lieA : liste les enregistrements LIÉS à une
 * fiche précise (ex. les tâches d'une piste) via la relation Doctrine détectée
 * par métadonnées — la lacune qui faisait affirmer à tort à l'assistant qu'une
 * piste n'avait « pas de tâche ». Fail-closed sur l'entité liée, scoping
 * entreprise conservé, lien sans relation directe signalé (lienIgnore).
 */
class RechercherEntitesLieATest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-liea-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-liea-guest@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit LieA SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit LieA Autre SARL';

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

    private function tool(): RechercherEntitesTool
    {
        return static::getContainer()->get(RechercherEntitesTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL];
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_B_NOM];

        $conn->executeStatement(
            "UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        // Enfants avant parents : tâches → pistes → clients.
        foreach (['tache', 'piste', 'client'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }
        foreach (['roles_en_marketing', 'roles_en_production', 'roles_en_administration'] as $table) {
            $conn->executeStatement(
                "DELETE r FROM {$table} r
                 JOIN invite i ON r.invite_id = i.id
                 JOIN entreprise e ON i.entreprise_id = e.id
                 WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }
        $conn->executeStatement(
            "DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email IN (:emails) OR e.nom IN (:noms)",
            ['emails' => $emails, 'noms' => $noms],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING, 'noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $conn->executeStatement(
            "DELETE FROM entreprise WHERE nom IN (:noms)",
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $conn->executeStatement(
            "DELETE FROM utilisateur WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    private function makeEntreprise(string $nom, Utilisateur $owner): Entreprise
    {
        $entreprise = new Entreprise();
        $entreprise->setNom($nom);
        $entreprise->setLicence('LIC-LIEA');
        $entreprise->setAdresse('1 rue LieA');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-LIEA');
        $entreprise->setIdnat('IDNAT-LIEA');
        $entreprise->setNumimpot('IMP-LIEA');
        $entreprise->setUtilisateur($owner);
        $this->em()->persist($entreprise);

        return $entreprise;
    }

    private function makePiste(string $nom, Entreprise $entreprise, Invite $invite, ?Client $client = null): Piste
    {
        return (new Piste())
            ->setNom($nom)
            ->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque de test lieA')
            ->setExercice(2026)
            ->setClient($client)
            ->setEntreprise($entreprise)
            ->setInvite($invite);
    }

    private function makeTache(string $description, Entreprise $entreprise, Invite $invite): Tache
    {
        return (new Tache())
            ->setDescription($description)
            ->setToBeEndedAt(new \DateTimeImmutable('+7 days'))
            ->setClosed(false)
            ->setEntreprise($entreprise)
            ->setInvite($invite);
    }

    /**
     * Entreprise A (owner = accès complet ; guest = Lecture Tache SANS Piste)
     * avec un client → une piste → 2 tâches (+ 1 tâche orpheline), et une
     * entreprise B avec sa propre piste (contrôle du scoping).
     *
     * @return array{owner: Invite, guest: Invite, entreprise: Entreprise, client: Client, piste: Piste, pisteB: Piste, tachesPiste: int[]}
     */
    private function seed(): array
    {
        $em = $this->em();

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER_EMAIL);
        $ownerUser->setNom('PHPUnit LieA');
        $ownerUser->setVerified(true);
        $ownerUser->setPassword('irrelevant');
        $em->persist($ownerUser);

        $entreprise = $this->makeEntreprise(self::ENTREPRISE_NOM, $ownerUser);

        $owner = new Invite();
        $owner->setNom('Propriétaire');
        $owner->setUtilisateur($ownerUser);
        $owner->setEntreprise($entreprise);
        $owner->setProprietaire(true);
        $em->persist($owner);

        $guestUser = new Utilisateur();
        $guestUser->setEmail(self::GUEST_EMAIL);
        $guestUser->setNom('PHPUnit LieA Invité');
        $guestUser->setVerified(true);
        $guestUser->setPassword('irrelevant');
        $em->persist($guestUser);

        $guest = new Invite();
        $guest->setNom('Invité tâches seulement');
        $guest->setUtilisateur($guestUser);
        $guest->setEntreprise($entreprise);
        $guest->setProprietaire(false);
        $em->persist($guest);

        // Lecture des Tâches mais PAS des Pistes : référencer une piste en
        // lieA doit être refusé (fail-closed).
        $role = new RolesEnMarketing();
        $role->setNom('Rôle tâches seulement');
        $role->setAccessTache([Invite::ACCESS_LECTURE]);
        $role->setEntreprise($entreprise);
        $guest->addRolesEnMarketing($role);
        $em->persist($role);

        // Généalogie complète : client (grand-père) → piste (père) → tâches (fils).
        $client = new Client();
        $client->setNom('Client LieA Grand-Père');
        $client->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = $this->makePiste('Piste LieA Cible', $entreprise, $owner, $client);
        $em->persist($piste);

        $tachesPiste = [];
        foreach (['Relancer l\'assureur', 'Préparer le comparatif'] as $description) {
            $tache = $this->makeTache($description, $entreprise, $owner)->setPiste($piste);
            $em->persist($tache);
            $tachesPiste[] = $tache;
        }
        // Tâche de la même entreprise mais SANS piste : ne doit pas remonter.
        $em->persist($this->makeTache('Tâche orpheline hors piste', $entreprise, $owner));

        // Entreprise B : sa piste ne doit jamais fuiter dans le scope de A.
        $entrepriseB = $this->makeEntreprise(self::ENTREPRISE_B_NOM, $ownerUser);
        $pisteB = $this->makePiste('Piste LieA Etrangère', $entrepriseB, $owner);
        $em->persist($pisteB);

        $em->flush();

        return [
            'owner'       => $owner,
            'guest'       => $guest,
            'entreprise'  => $entreprise,
            'client'      => $client,
            'piste'       => $piste,
            'pisteB'      => $pisteB,
            'tachesPiste' => array_map(static fn (Tache $t) => $t->getId(), $tachesPiste),
        ];
    }

    public function testListeLesTachesDUnePiste(): void
    {
        ['owner' => $owner, 'entreprise' => $e, 'piste' => $piste, 'tachesPiste' => $ids] = $this->seed();
        $scope = new AiScope($e, $owner);

        $result = $this->tool()->execute([
            'entite' => 'Tache',
            'lieA'   => ['entite' => 'Piste', 'id' => $piste->getId()],
        ], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(2, $result->data['totalItems'], 'Seules les tâches DE la piste doivent remonter (pas l\'orpheline).');
        $this->assertEqualsCanonicalizing($ids, array_column($result->data['items'], 'id'));
        $this->assertSame(['entite' => 'Piste', 'id' => $piste->getId()], $result->data['lien']);
        $this->assertArrayNotHasKey('lienIgnore', $result->data);
    }

    /**
     * Multi-niveaux (père → fils → petit-fils) : les tâches d'un CLIENT ne lui
     * sont pas directement rattachées — le chemin Tache → piste → client est
     * résolu automatiquement par métadonnées (BFS), sans aucun code spécifique
     * au couple d'entités.
     */
    public function testListeLesTachesDUnClientViaSesPistes(): void
    {
        ['owner' => $owner, 'entreprise' => $e, 'client' => $client, 'tachesPiste' => $ids] = $this->seed();
        $scope = new AiScope($e, $owner);

        $result = $this->tool()->execute([
            'entite' => 'Tache',
            'lieA'   => ['entite' => 'Client', 'id' => $client->getId()],
        ], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(2, $result->data['totalItems'], 'Les tâches du client remontent via ses pistes (2 niveaux).');
        $this->assertEqualsCanonicalizing($ids, array_column($result->data['items'], 'id'));
        $this->assertSame(['entite' => 'Client', 'id' => $client->getId()], $result->data['lien']);
    }

    public function testLienScopeALEntreprise(): void
    {
        ['owner' => $owner, 'entreprise' => $e, 'pisteB' => $pisteB] = $this->seed();
        $scope = new AiScope($e, $owner);

        // La piste d'une AUTRE entreprise ne rend rien : le scoping entreprise
        // du service de recherche s'applique toujours.
        $result = $this->tool()->execute([
            'entite' => 'Tache',
            'lieA'   => ['entite' => 'Piste', 'id' => $pisteB->getId()],
        ], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(0, $result->data['totalItems']);
    }

    public function testLienSansRelationDirecteSignale(): void
    {
        ['owner' => $owner, 'entreprise' => $e, 'piste' => $piste] = $this->seed();
        $scope = new AiScope($e, $owner);

        // Client n'a aucune relation *-vers-un ciblant Piste : le lien est
        // ignoré (signalé au modèle), la liste reste servie sans crash.
        $result = $this->tool()->execute([
            'entite' => 'Client',
            'lieA'   => ['entite' => 'Piste', 'id' => $piste->getId()],
        ], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertTrue($result->data['lienIgnore']);
        $this->assertArrayNotHasKey('lien', $result->data);
    }

    public function testLienHorsPerimetreRefuse(): void
    {
        ['guest' => $guest, 'entreprise' => $e, 'piste' => $piste] = $this->seed();
        $scope = new AiScope($e, $guest);

        // L'invité lit les Tâches mais pas les Pistes : référencer une piste
        // en lieA = la lire → refus fail-closed.
        $result = $this->tool()->execute([
            'entite' => 'Tache',
            'lieA'   => ['entite' => 'Piste', 'id' => $piste->getId()],
        ], $scope);

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
    }

    public function testSansLieAComportementInchange(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $scope = new AiScope($e, $owner);

        $result = $this->tool()->execute(['entite' => 'Tache'], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(3, $result->data['totalItems'], 'Sans lieA, toutes les tâches de l\'entreprise (non-régression).');
        $this->assertArrayNotHasKey('lien', $result->data);
        $this->assertArrayNotHasKey('lienIgnore', $result->data);
    }
}
