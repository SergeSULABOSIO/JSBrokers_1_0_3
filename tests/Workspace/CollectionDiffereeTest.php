<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LE VERROU DE LA CRÉATION EST OUVERT — sauf là où l'enfant ne peut pas s'en passer.
 *
 * Une collection était inerte tant que sa fiche parente n'avait pas d'id : il fallait
 * enregistrer, rouvrir, puis saisir. Ses éléments attendent désormais en mémoire du
 * navigateur et sont créés après l'enregistrement de l'ancêtre.
 *
 * Deux accroches, posées par le serveur, et sans lesquelles rien ne fonctionne — mais dont
 * l'absence ne provoquerait AUCUNE erreur, seulement une collection qui reste inerte :
 *   - la rangée ne doit plus sortir en `row d-none` ;
 *   - le widget doit annoncer `differe`, sur quoi son contrôleur décide de tout.
 *
 * Et une exclusion, qui doit rester exclue : `defaultValueConfig`, où le SERVEUR pré-remplit
 * l'enfant à partir du parent. Différer la priverait de ses défauts, en silence.
 */
class CollectionDiffereeTest extends WebTestCase
{
    private const ENT = 'PHPUnit-CollDiff';
    private const OWNER = 'phpunit-colldiff-owner@test.local';

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
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    private function seedEtConnecte(): void
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
        $this->em->flush();

        $this->client->loginUser($owner);
    }

    /** Le HTML du formulaire de CRÉATION d'une entité (aucun id : c'est là que tout se joue). */
    private function formulaireDeCreation(string $route): string
    {
        $this->client->request('GET', "/admin/{$route}/api/get-form");
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /** La rangée qui porte une carte de champ donnée. */
    private function rangeeDe(string $html, string $fieldCode): ?string
    {
        $pos = strpos($html, 'data-field-code="' . $fieldCode . '"');
        if ($pos === false) {
            return null;
        }
        $amont = substr($html, max(0, $pos - 2500), min($pos, 2500));
        $debut = strrpos($amont, '<div class="row');

        return $debut === false ? null : substr($amont, $debut);
    }

    public function testUneCollectionOrdinaireEstUtilisableDesLaCreation(): void
    {
        $this->seedEtConnecte();
        $html = $this->formulaireDeCreation('client');

        $rangee = $this->rangeeDe($html, 'contacts');
        self::assertNotNull($rangee, 'La collection « contacts » doit être rendue dans le formulaire de création.');
        self::assertStringNotContainsString(
            'class="row d-none"',
            $rangee,
            'Masquée d\'office, la collection resterait invisible quoi que fasse l\'utilisateur.',
        );

        self::assertStringContainsString(
            'data-collection-differe-value="true"',
            $html,
            'Sans ce drapeau, le widget irait interroger le serveur avec un parentId à 0 et n\'obtiendrait que du vide.',
        );
    }

    public function testLesDocumentsAussiSAttachentDesLaCreation(): void
    {
        $this->seedEtConnecte();
        $html = $this->formulaireDeCreation('client');

        // « documents » est de loin la collection la plus répandue (une trentaine de fiches) :
        // c'est elle qui portait l'essentiel de la gêne — joindre une pièce obligeait à
        // enregistrer d'abord.
        $rangee = $this->rangeeDe($html, 'documents');
        self::assertNotNull($rangee);
        self::assertStringNotContainsString('class="row d-none"', $rangee);
    }

    public function testUneCollectionAuxDefautsServeurResteExclue(): void
    {
        $this->seedEtConnecte();
        $html = $this->formulaireDeCreation('offreindemnisationsinistre');

        // `paiements` porte `defaultValueConfig` : le serveur en déduit le montant depuis
        // l'offre. Différer la saisie la priverait de ce pré-remplissage sans rien dire,
        // et l'utilisateur saisirait des montants à la main sans savoir qu'il le fait.
        $rangee = $this->rangeeDe($html, 'paiements');
        self::assertNotNull($rangee, 'La rangée reste rendue — c\'est son masquage qui la cache.');
        self::assertStringContainsString(
            'class="row d-none"',
            $rangee,
            'Cette collection-là doit garder le comportement d\'avant : masquée tant que l\'offre n\'existe pas.',
        );
    }
}
