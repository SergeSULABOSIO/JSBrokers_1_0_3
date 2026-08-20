<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LE PARTAGE DES REVENUS D'UNE AFFAIRE, LISIBLE D'UN SEUL TENANT.
 *
 * Trois blocs traitaient du même sujet — qui se partage la commission — en se suivant sans
 * que rien ne le dise, sous des libellés qui n'expliquaient ni ce que chacun faisait, ni
 * comment ils s'articulaient : « Partenaires », « Agents internes rémunérés », « Liste des
 * conditions spéciales de partage ».
 *
 * Ce test verrouille ce qui ne se voit pas au premier coup d'œil : l'intertitre existe et ne
 * se déguise pas en champ obligatoire, chaque bloc dit son effet SUR LE CALCUL, et le bloc
 * des conditions ne paraît que lorsqu'il a un bénéficiaire à qui rétrocéder.
 */
class PisteBlocsDePartageTest extends WebTestCase
{
    private const ENT = 'PHPUnit-BlocsPartage';
    private const OWNER = 'phpunit-blocspartage@test.local';

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
        foreach (['condition_partage', 'piste', 'client', 'partenaire', 'risque', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** @return array{piste:Piste, partenaire:Partenaire, agent:Invite, entreprise:Entreprise} */
    private function semer(bool $avecIntermediaire): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+243000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($entreprise);

        $invite = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $this->em->persist($invite);
        $owner->setConnectedTo($entreprise);

        $agent = (new Invite())->setNom('Alice Agent')->setProprietaire(false);
        $agent->setEntreprise($entreprise);
        $this->em->persist($agent);

        $partenaire = (new Partenaire())->setNom('Intermédiaire Blocs')->setPart(5.0);
        $partenaire->setEntreprise($entreprise);
        $this->em->persist($partenaire);

        $client = (new Client())->setNom('Client Blocs')->setExonere(false);
        $client->setEntreprise($entreprise);
        $this->em->persist($client);

        $risque = (new Risque())->setCode('RC')->setNomComplet('Risque Blocs')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $this->em->persist($risque);

        $piste = (new Piste())->setNom('Piste Blocs')->setTypeAvenant(0)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        if ($avecIntermediaire) {
            $piste->setPartenaire($partenaire);
        }
        $this->em->persist($piste);

        $this->em->flush();
        $this->client->loginUser($owner);

        return compact('piste', 'partenaire', 'agent', 'entreprise');
    }

    private function formulaireDeLaPiste(Piste $piste): string
    {
        $this->client->request('GET', '/admin/piste/api/get-form/' . $piste->getId());
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    public function testLesTroisBlocsSontRassemblesSousUnIntertitre(): void
    {
        $html = $this->formulaireDeLaPiste($this->semer(true)['piste']);

        self::assertStringContainsString(
            'Partage des revenus de cette affaire',
            $html,
            'Trois blocs traitant du même sujet doivent le dire.',
        );
        // L'intertitre nomme une SECTION : il ne doit pas porter la marque des champs
        // obligatoires, qui promettrait une saisie qu'il ne demande pas.
        self::assertStringContainsString('dlg-group-title', $html);
        self::assertStringNotContainsString(
            '<label class="form-label required">Partage des revenus',
            $html,
            'Un intertitre de section n\'est pas un champ obligatoire.',
        );
    }

    public function testChaqueBlocDitSonEffetSurLeCalcul(): void
    {
        $html = $this->formulaireDeLaPiste($this->semer(true)['piste']);

        self::assertStringContainsString('Intermédiaire externe', $html);
        // L'apostrophe est échappée dans le HTML : on vise une portion qui n'en porte pas.
        self::assertStringContainsString('Sans lui, aucune commission', $html, 'La cle d entree du partage est nommee.');

        self::assertStringContainsString('Agents internes rémunérés', $html);
        self::assertStringContainsString('sur ce qui reste', $html, 'La part des agents se calcule en aval.');

        self::assertStringContainsString('Conditions propres à cette affaire', $html);
        self::assertStringContainsString('REMPLACENT', $html, 'Une condition propre remplace, elle ne s\'ajoute pas.');
    }

    public function testLeBlocDesConditionsAttendUnBeneficiaire(): void
    {
        $html = $this->formulaireDeLaPiste($this->semer(false)['piste']);

        // La règle est DÉCLARÉE, et lue par le moteur de visibilité : le bloc paraît dès
        // qu'un intermédiaire OU un agent est désigné, sans recharger la fiche.
        self::assertStringContainsString('&quot;operator&quot;:&quot;any&quot;', $html);
        self::assertStringContainsString('&quot;field&quot;:&quot;partenaire&quot;', $html);
        self::assertStringContainsString('&quot;field&quot;:&quot;conditionsPartageAgent&quot;', $html);
    }

    public function testLeClientNEstPlusRedemandeEnModification(): void
    {
        $piste = $this->semer(true)['piste'];
        $html = $this->formulaireDeLaPiste($piste);

        $pos = strpos($html, 'name="client"');
        self::assertNotFalse($pos, 'Le champ reste rendu — il porte la valeur.');

        $amont = substr($html, max(0, $pos - 3000), min($pos, 3000));
        $rangee = substr($amont, (int) strrpos($amont, '<div class="row'));

        // On ne rouvre pas une affaire pour en changer le client : on y travaille les
        // autres champs.
        self::assertStringContainsString('class="row d-none"', $rangee);

        // ET LE MARQUEUR, SANS QUOI LE MASQUAGE NE TIENT PAS À L'ÉCRAN. La seconde passe
        // du contrôleur rouvrait la rangée dès qu'une de ses colonnes était visible : le
        // `d-none` du serveur n'existait que dans le HTML brut, et le champ revenait sous
        // les yeux de l'utilisateur. Le test ne le voyait pas — il lisait le HTML, pas
        // l'écran.
        self::assertStringContainsString('data-rangee-masquee="1"', $rangee);
    }

    public function testEnCreationLeClientEstBienDemande(): void
    {
        $this->semer(true);

        $this->client->request('GET', '/admin/piste/api/get-form');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $pos = strpos($html, 'name="client"');
        self::assertNotFalse($pos);
        $amont = substr($html, max(0, $pos - 3000), min($pos, 3000));
        $rangee = substr($amont, (int) strrpos($amont, '<div class="row'));

        // À la création, personne ne l'a encore dit : la question est légitime.
        self::assertStringNotContainsString('class="row d-none"', $rangee);
    }

    public function testLIntermediaireNAcceptePlusQuUnSeulChoix(): void
    {
        $html = $this->formulaireDeLaPiste($this->semer(true)['piste']);

        $pos = strpos($html, 'name="partenaire"');
        self::assertNotFalse($pos, 'Le champ est au singulier — un seul intermédiaire par affaire.');
        self::assertStringNotContainsString('name="partenaire[]"', $html, 'Plus de liste : le moteur n\'en lit qu\'un.');
    }
}
