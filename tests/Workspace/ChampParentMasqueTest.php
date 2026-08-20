<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ON NE DEMANDE PAS UN PARENT QU'ON CONNAÎT DÉJÀ.
 *
 * Ouvrir « Ajouter un contact » depuis la fiche d'un client ne laisse aucun doute sur le
 * client concerné. Le formulaire affichait pourtant un champ « Client associé », développé
 * en carte avec les chiffres du client, tout en haut : une question dont la réponse est
 * déjà donnée, posée avant celles qui comptent.
 *
 * Le mécanisme générique masquait bien ce champ — mais seulement quand il l'injectait
 * lui-même. Dès qu'un provider le déclarait dans son propre layout (ContactFormCanvasProvider
 * pour « client », les RolesEn* pour « invite »), il restait visible : la garde anti
 * double-rendu se contentait de ne rien faire.
 *
 * Le parent reste rappelé en tête du dialogue sous forme de fait — c'est sa place.
 */
class ChampParentMasqueTest extends WebTestCase
{
    private const ENT = 'PHPUnit-ParentMasque';
    private const OWNER = 'phpunit-parentmasque@test.local';

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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        foreach (['contact', 'client', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    private function semer(): Client
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+243000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($ent);

        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);
        $owner->setConnectedTo($ent);

        $client = (new Client())->setNom('Client du test')->setExonere(false);
        $client->setEntreprise($ent);
        $this->em->persist($client);

        $this->em->flush();
        $this->client->loginUser($owner);

        return $client;
    }

    /**
     * La rangée de layout qui porte un champ donné.
     *
     * On remonte depuis le CHAMP lui-même (`name="..."`) et non depuis une carte
     * `data-field-code` : celle-ci n'est rendue que pour les champs qui déclarent une
     * icône. « client » n'en a pas, et c'est précisément ce chemin de rendu-là qui
     * laissait le champ visible.
     */
    private function rangeeDe(string $html, string $champ): ?string
    {
        $pos = strpos($html, 'name="' . $champ . '"');
        if ($pos === false) {
            return null;
        }
        $amont = substr($html, max(0, $pos - 3000), min($pos, 3000));
        $debut = strrpos($amont, '<div class="row');

        return $debut === false ? null : substr($amont, $debut);
    }

    public function testLeClientNEstPlusRedemandeQuandOnLeConnait(): void
    {
        $client = $this->semer();

        $this->client->request(
            'GET',
            '/admin/contact/api/get-form?parent_id=' . $client->getId() . '&parent_field_name=client',
        );
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        // Le champ reste RENDU — le formulaire en a besoin pour porter la valeur — mais sa
        // rangée est masquée.
        $rangee = $this->rangeeDe($html, 'client');
        self::assertNotNull($rangee, 'Le champ doit rester dans le formulaire, seulement masqué.');
        self::assertStringContainsString(
            'class="row d-none"',
            $rangee,
            'Ouvert depuis un client, le formulaire ne doit pas redemander ce client.',
        );
    }

    public function testSansParentLeChampResteVisible(): void
    {
        $this->semer();

        // Créé depuis la rubrique Contacts, sans parent : là, le client doit être demandé —
        // personne ne l'a encore dit.
        $this->client->request('GET', '/admin/contact/api/get-form');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('name="client"', $html, 'Le champ doit être proposé.');

        // Sans parent, aucune rangée n'est injectée : le champ n'appartient à aucune
        // rangée de layout (le provider ne le déclare pas), il est rendu par render_rest.
        // Ce qui compte est qu'il ne soit PAS enfermé dans une rangée masquée.
        $rangee = $this->rangeeDe($html, 'client');
        if ($rangee !== null) {
            self::assertStringNotContainsString('class="row d-none"', $rangee);
        }
    }
}
