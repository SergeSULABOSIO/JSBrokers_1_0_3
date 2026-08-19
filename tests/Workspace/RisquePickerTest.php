<?php

namespace App\Tests\Workspace;

use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * LE SÉLECTEUR DE RISQUES CIBLÉS — on choisit au catalogue, on ne fabrique pas.
 *
 * Créer un risque depuis la fiche d'une condition produisait un doublon de catalogue par
 * condition. Le sélecteur y remédie, et le passage en ManyToMany le rend sûr : cibler un
 * risque déjà visé ailleurs ne le retire à personne.
 *
 * Trois choses à verrouiller, dont deux sont des questions de sécurité — un sélecteur mal
 * scopé donnerait à voir, puis à rattacher, le catalogue d'une AUTRE entreprise.
 */
class RisquePickerTest extends WebTestCase
{
    private const ENT = 'PHPUnit-RisquePicker';
    private const AUTRE_ENT = 'PHPUnit-RisquePicker-Autre';
    private const OWNER = 'phpunit-risquepicker@test.local';
    private const AUTRE_OWNER = 'phpunit-risquepicker-autre@test.local';

    private KernelBrowser $client;
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
        foreach ([self::OWNER, self::AUTRE_OWNER] as $email) {
            $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => $email]);
        }
        foreach ([self::ENT, self::AUTRE_ENT] as $nom) {
            $conn->executeStatement(
                'DELETE l FROM condition_partage_risque l JOIN condition_partage c ON l.condition_partage_id = c.id
                 JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n',
                ['n' => $nom],
            );
            foreach (['condition_partage', 'risque', 'invite'] as $table) {
                $conn->executeStatement(
                    "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                    ['n' => $nom],
                );
            }
            $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => $nom]);
        }
        foreach ([self::OWNER, self::AUTRE_OWNER] as $email) {
            $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => $email]);
        }
        $this->em->clear();
    }

    /** @return array{owner:Utilisateur, condition:ConditionPartage, risque:Risque, risqueEtranger:Risque} */
    private function semer(): array
    {
        $owner = $this->utilisateur(self::OWNER);
        $ent = $this->entreprise(self::ENT, $owner);
        $this->invite($ent, $owner);

        $risque = (new Risque())->setCode('INC')->setNomComplet('Incendie')->setImposable(true);
        $risque->setEntreprise($ent);
        $this->em->persist($risque);

        $condition = (new ConditionPartage())->setNom('Condition ciblée')->setTaux(10.0)->setSeuil(0.0)
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setCritereRisque(ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES);
        $condition->setEntreprise($ent);
        $this->em->persist($condition);

        // Une SECONDE entreprise, avec son propre catalogue : c'est elle qui rend le test
        // de périmètre honnête.
        $autreOwner = $this->utilisateur(self::AUTRE_OWNER);
        $autreEnt = $this->entreprise(self::AUTRE_ENT, $autreOwner);
        $risqueEtranger = (new Risque())->setCode('ETR')->setNomComplet('Risque étranger')->setImposable(true);
        $risqueEtranger->setEntreprise($autreEnt);
        $this->em->persist($risqueEtranger);

        $this->em->flush();

        return compact('owner', 'condition', 'risque', 'risqueEtranger');
    }

    private function utilisateur(string $email): Utilisateur
    {
        $u = (new Utilisateur())->setEmail($email)->setNom('PHPUnit')->setVerified(true);
        $u->setPassword('x');
        $this->em->persist($u);

        return $u;
    }

    private function entreprise(string $nom, Utilisateur $owner): Entreprise
    {
        $e = (new Entreprise())->setNom($nom)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($e);
        $owner->setConnectedTo($e);

        return $e;
    }

    private function invite(Entreprise $ent, Utilisateur $owner): Invite
    {
        $i = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($i);

        return $i;
    }

    public function testLeSelecteurListeLeCatalogueDeLEntreprise(): void
    {
        $s = $this->semer();
        $this->client->loginUser($s['owner']);

        $this->client->request('GET', '/admin/conditionpartage/api/' . $s['condition']->getId() . '/risque-picker');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Incendie', $html, 'Le catalogue de l\'entreprise est proposé.');
        self::assertStringNotContainsString(
            'Risque étranger',
            $html,
            'Le catalogue d\'une AUTRE entreprise ne doit jamais apparaître ici.',
        );
        self::assertStringContainsString('data-picker-id="' . $s['risque']->getId() . '"', $html);
    }

    public function testCiblerPuisRetirerUnRisque(): void
    {
        $s = $this->semer();
        $this->client->loginUser($s['owner']);
        $base = '/admin/conditionpartage/api/' . $s['condition']->getId();
        $risqueId = $s['risque']->getId();

        $this->client->request('PUT', $base . '/attach-risque/' . $risqueId);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $condition = $this->em->getRepository(ConditionPartage::class)->find($s['condition']->getId());
        self::assertCount(1, $condition->getProduits(), 'Le risque est désormais ciblé.');

        $this->client->request('DELETE', $base . '/detach-risque/' . $risqueId);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $condition = $this->em->getRepository(ConditionPartage::class)->find($s['condition']->getId());
        self::assertCount(0, $condition->getProduits(), 'Le risque n\'est plus ciblé…');
        self::assertNotNull(
            $this->em->getRepository(Risque::class)->find($risqueId),
            '…mais il reste au catalogue : « Retirer » n\'est pas « Supprimer ».',
        );
    }

    public function testUnRisqueHorsPerimetreEstRefuse(): void
    {
        $s = $this->semer();
        $this->client->loginUser($s['owner']);

        // Rattacher le risque d'une autre entreprise franchirait la frontière de l'espace
        // de travail — et cette condition pilote des montants.
        $this->client->request(
            'PUT',
            '/admin/conditionpartage/api/' . $s['condition']->getId() . '/attach-risque/' . $s['risqueEtranger']->getId(),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->em->clear();
        $condition = $this->em->getRepository(ConditionPartage::class)->find($s['condition']->getId());
        self::assertCount(0, $condition->getProduits(), 'Rien ne doit avoir été rattaché.');
    }
}
