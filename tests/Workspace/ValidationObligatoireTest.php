<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Service\Workspace\ChampsObligatoiresInspector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renforcement de la validation des formulaires d'ÉDITION.
 *
 * 1) ChampsObligatoiresInspector : source unique de la notion « champ obligatoire »
 *    dérivée des métadonnées Doctrine (partagée avec l'assistant IA).
 * 2) CRUD HTTP : un champ obligatoire vide SANS contrainte #[Assert] (ex. `exonere`)
 *    produit désormais une 422 propre nommant le champ, au lieu d'un 500 au flush.
 *
 * Le FormType des champs autocomplete scope sa requête sur l'utilisateur connecté
 * (getConnectedTo) : on se connecte donc comme le ferait l'endpoint réel.
 */
class ValidationObligatoireTest extends WebTestCase
{
    private const ENT = 'PHPUnit-ValidOblig';
    private const OWNER = 'phpunit-validoblig-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ChampsObligatoiresInspector $inspector;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->inspector = static::getContainer()->get(ChampsObligatoiresInspector::class);
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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        $conn->executeStatement('DELETE c FROM client c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** Seed owner + entreprise + invité propriétaire, connecté à l'entreprise active. */
    private function seedContexte(): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($ent);

        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);

        $owner->setConnectedTo($ent);
        $this->em->flush();

        return [$owner, $ent, $inv];
    }

    // ───────────────────────── Inspecteur (unité) ─────────────────────────

    public function testChampsManquantsSignaleLesObligatoiresVides(): void
    {
        $manquants = $this->inspector->champsManquants(new Client());

        // nom (non-nullable, NotBlank) ET exonere (booléen non-nullable, SANS Assert).
        $this->assertArrayHasKey('nom', $manquants);
        $this->assertArrayHasKey('exonere', $manquants);
    }

    public function testChampsManquantsRestreintAuxChampsDuFormulaire(): void
    {
        // Restreint aux seuls champs exposés : nom/exonere ne sont pas dans la liste,
        // donc jamais signalés (cas d'un champ obligatoire renseigné hors formulaire).
        $manquants = $this->inspector->champsManquants(new Client(), ['telephone', 'email']);

        $this->assertSame([], $manquants);
    }

    public function testChampsManquantsIgnoreLesRelationsAutoScopees(): void
    {
        // Client complet côté scalaires mais SANS entreprise/invite : ces relations
        // auto-scopées ne doivent jamais être signalées à l'utilisateur.
        $client = (new Client())->setNom('Complet')->setExonere(false);
        $manquants = $this->inspector->champsManquants($client);

        $this->assertArrayNotHasKey('entreprise', $manquants);
        $this->assertArrayNotHasKey('invite', $manquants);
        $this->assertSame([], $manquants, 'Un client scalaire complet ne manque de rien.');
    }

    public function testLibelleChampLisible(): void
    {
        $this->seedContexte();
        $this->client->loginUser($this->em->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER]));

        $this->assertSame('Nom', $this->inspector->libelleChamp(Client::class, 'nom'));
    }

    // ───────────────────────── CRUD HTTP (fonctionnel) ─────────────────────────

    public function testChampObligatoireVideRenvoie422AvecLeChamp(): void
    {
        [$owner, $ent, $inv] = $this->seedContexte();
        $this->client->loginUser($owner);

        // nom fourni, mais `exonere` (obligatoire, non-nullable, SANS #[Assert]) OMIS :
        // avant, cela passait la validation puis échouait au flush (500).
        $this->client->request('POST', '/admin/client/api/submit', [
            'idEntreprise' => $ent->getId(),
            'idInvite' => $inv->getId(),
            'nom' => 'Client Test Obligation',
        ]);

        $this->assertResponseStatusCodeSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Un champ obligatoire vide doit donner une 422 propre, pas un 500.'
        );
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('exonere', $payload['errors'] ?? [], 'Le champ fautif est nommé.');
        $this->assertArrayHasKey('exonere', $payload['labels'] ?? [], 'Un libellé lisible accompagne le champ.');

        // Aucune fiche ne doit avoir été créée.
        $this->em->clear();
        $this->assertNull(
            $this->em->getRepository(Client::class)->findOneBy(['nom' => 'Client Test Obligation']),
            'Rien ne doit être persisté quand un champ obligatoire manque.'
        );
    }

    public function testSoumissionValideEnregistre(): void
    {
        [$owner, $ent, $inv] = $this->seedContexte();
        $this->client->loginUser($owner);

        $this->client->request('POST', '/admin/client/api/submit', [
            'idEntreprise' => $ent->getId(),
            'idInvite' => $inv->getId(),
            'nom' => 'Client Valide',
            'exonere' => '0',
        ]);

        $this->assertResponseIsSuccessful('Une soumission complète doit répondre 200.');

        $this->em->clear();
        $this->assertNotNull(
            $this->em->getRepository(Client::class)->findOneBy(['nom' => 'Client Valide']),
            'La fiche valide est bien persistée.'
        );
    }

    /** Seed direct d'un client existant (pour les scénarios d'édition). */
    private function seedClient(Entreprise $ent, Invite $inv, string $nom): Client
    {
        $c = (new Client())->setNom($nom)->setExonere(false)->setEntreprise($ent)->setInvite($inv)
            ->setTelephone('+243000000000');
        $this->em->persist($c);
        $this->em->flush();

        return $c;
    }

    public function testEditionNeBloquePasSurChampNonTouche(): void
    {
        [$owner, $ent, $inv] = $this->seedContexte();
        $client = $this->seedClient($ent, $inv, 'Client À Éditer');
        $id = $client->getId();
        $this->client->loginUser($owner);

        // On n'édite QUE le téléphone : le garde-fou ne doit pas exiger les autres
        // champs obligatoires (non soumis = non touchés), l'édition passe normalement.
        $this->client->request('POST', '/admin/client/api/submit', [
            'id' => $id,
            'idEntreprise' => $ent->getId(),
            'idInvite' => $inv->getId(),
            'telephone' => '+243111222333',
        ]);

        $this->assertResponseIsSuccessful('Éditer un champ ne doit pas exiger les champs obligatoires non touchés.');

        $this->em->clear();
        $this->assertSame('+243111222333', $this->em->getRepository(Client::class)->find($id)->getTelephone());
    }

    public function testEditionQuiVideUnChampObligatoireRenvoie422(): void
    {
        [$owner, $ent, $inv] = $this->seedContexte();
        $client = $this->seedClient($ent, $inv, 'Client Nom Requis');
        $id = $client->getId();
        $this->client->loginUser($owner);

        // L'utilisateur EFFACE lui-même le nom (soumis mais vide) → erreur de champ propre.
        $this->client->request('POST', '/admin/client/api/submit', [
            'id' => $id,
            'idEntreprise' => $ent->getId(),
            'idInvite' => $inv->getId(),
            'nom' => '',
        ]);

        // Champ obligatoire soumis VIDE : converti proprement en 422 nommant le champ,
        // au lieu du 500 (TypeError du data-mapper) que produirait un setter non-nullable.
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('nom', $payload['errors'] ?? []);
        $this->assertArrayHasKey('nom', $payload['labels'] ?? []);
    }
}
