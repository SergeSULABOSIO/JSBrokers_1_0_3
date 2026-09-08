<?php

namespace App\Tests\Onboarding;

use App\Entity\Assureur;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * LE GUIDE DE DÉMARRAGE : un atelier, pas un couloir.
 *
 * Ce qu'on vérifie ici tient en une phrase : chaque carte doit pouvoir OUVRIR un
 * dialogue et MONTRER l'existant, sans jamais renvoyer vers une rubrique. Les deux
 * reposent sur des canevas rendus côté serveur — s'ils manquent, la carte est un décor.
 */
class OnboardingPanneauTest extends WebTestCase
{
    private const ENT = 'PHPUnit-Onboarding-Panneau';
    private const OWNER = 'phpunit-onboarding-panneau-owner@test.local';
    private const GUEST = 'phpunit-onboarding-panneau-guest@test.local';
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

    /**
     * @return array{owner: Invite, guest: Invite, entreprise: Entreprise}
     */
    private function seed(): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER)->setNom('Propriétaire');
        $ownerUser->setPassword($hasher->hashPassword($ownerUser, self::PASSWORD));
        $ownerUser->setVerified(true);
        $this->em->persist($ownerUser);

        $entreprise = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($ownerUser);
        $this->em->persist($entreprise);

        $owner = (new Invite())->setNom('Administrateur')->setUtilisateur($ownerUser)
            ->setEntreprise($entreprise)->setProprietaire(true);
        $this->em->persist($owner);

        $guestUser = new Utilisateur();
        $guestUser->setEmail(self::GUEST)->setNom('Collaborateur');
        $guestUser->setPassword($hasher->hashPassword($guestUser, self::PASSWORD));
        $guestUser->setVerified(true);
        $this->em->persist($guestUser);

        $guest = (new Invite())->setNom('Collaborateur')->setUtilisateur($guestUser)
            ->setEntreprise($entreprise)->setProprietaire(false);
        $this->em->persist($guest);

        $ownerUser->setConnectedTo($entreprise);
        $guestUser->setConnectedTo($entreprise);
        $this->em->flush();

        return ['owner' => $owner, 'guest' => $guest, 'entreprise' => $entreprise];
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $emails = [self::OWNER, self::GUEST];

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:e)',
            ['e' => $emails],
            ['e' => ArrayParameterType::STRING],
        );
        $conn->executeStatement('DELETE t FROM assureur t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => $emails],
            ['e' => ArrayParameterType::STRING],
        );
        $this->em->clear();
    }

    private function user(string $email): Utilisateur
    {
        return $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    /**
     * Les canevas de dialogue, décodés depuis l'attribut du panneau — exactement ce que
     * le contrôleur Stimulus lira.
     *
     * @return array<string, array<string, mixed>>
     */
    private function canvasDuPanneau(): array
    {
        $noeud = $this->client->getCrawler()->filter('[data-onboarding-panneau-canvas-value]');
        $this->assertCount(1, $noeud, 'Le panneau doit porter ses canevas dans un attribut unique.');

        return json_decode($noeud->attr('data-onboarding-panneau-canvas-value'), true) ?? [];
    }

    /** Le HTML de la seule carte portant cette clé d'étape. */
    private function carte(string $cle): string
    {
        $bouton = $this->client->getCrawler()->filter(sprintf('[data-onboarding-panneau-cle-param="%s"]', $cle));
        $this->assertGreaterThan(0, $bouton->count(), sprintf('Aucune commande pour cette etape : %s.', $cle));

        return $bouton->closest('.jsb-onboarding-carte')->html();
    }

    private function ouvrirGuide(Invite $invite, Entreprise $entreprise, string $email): string
    {
        $this->client->loginUser($this->user($email));
        $this->client->request('GET', sprintf('/admin/onboarding/workspace/%d/%d', $entreprise->getId(), $invite->getId()));
        $this->assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    public function testLeGuideRendUneCarteEtUnCanevasParEtape(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $html = $this->ouvrirGuide($owner, $e, self::OWNER);

        $this->assertStringContainsString('jsb-onboarding-carte', $html);
        $this->assertStringContainsString('Assureurs', $html);

        // ON LIT L'ATTRIBUT COMME LE FERA LE NAVIGATEUR, en le décodant : le JSON y est
        // échappé pour l'HTML, et y chercher une URL en clair ne prouverait rien.
        $canvas = $this->canvasDuPanneau();

        // Les canevas voyagent dans UN attribut, pas un par carte : quinze attributs
        // auraient répété quinze fois le même contexte d'entreprise.
        $this->assertArrayHasKey('assureurs', $canvas);
        $this->assertSame('/admin/assureur/api/get-form', $canvas['assureurs']['parametres']['endpoint_form_url']);
        $this->assertSame('/admin/assureur/api/submit', $canvas['assureurs']['parametres']['endpoint_submit_url']);
    }

    /**
     * Le « pourquoi » d'une carte est le premier paragraphe de la description que le
     * dialogue affiche dans sa colonne gauche : un seul texte, deux surfaces.
     */
    public function testChaqueCarteExpliquePourquoiElleCompte(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $html = $this->ouvrirGuide($owner, $e, self::OWNER);

        $this->assertStringContainsString('jsb-onboarding-carte-pourquoi', $html);
        $this->assertStringContainsString('Sans assureur enregistré', $html);
    }

    public function testUneEtapeAFaireProposeLAjoutEtRienDAutre(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $html = $this->ouvrirGuide($owner, $e, self::OWNER);

        $this->assertStringContainsString('jsb-onboarding-ajouter', $html);
        $this->assertStringContainsString('data-onboarding-panneau-cle-param="assureurs"', $html);

        // ON ISOLE LA CARTE DES ASSUREURS : d'autres étapes du même écran ont déjà leurs
        // lignes (le cabinet compte deux invités), et chercher dans tout le panneau
        // ferait échouer l'assertion pour une raison qui n'a rien à voir.
        $carte = $this->carte('assureurs');
        $this->assertStringNotContainsString('jsb-onboarding-ligne', $carte, "Rien à lister tant que rien n'est enregistré.");
    }

    /**
     * L'existant est CONSULTABLE et MODIFIABLE depuis la carte : chaque ligne porte son
     * identifiant, et c'est lui qui fait basculer le dialogue en édition.
     */
    public function testUnObjetEnregistreDevientUneLigneOuvrable(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $assureur = (new Assureur())->setNom('SUNU Assurances');
        $assureur->setEntreprise($e);
        $this->em->persist($assureur);
        $this->em->flush();

        $html = $this->ouvrirGuide($owner, $e, self::OWNER);

        $this->assertStringContainsString('SUNU Assurances', $html);
        $this->assertStringContainsString(
            sprintf('data-onboarding-panneau-id-param="%d"', $assureur->getId()),
            $html,
        );
        // Le bouton d'ajout RESTE disponible : on ajoute des assureurs à volonté, il
        // cesse seulement d'être l'appel à l'action principal.
        $this->assertStringContainsString('est-secondaire', $html);
    }

    public function testLeGuideEstRefuseAUnInvite(): void
    {
        ['owner' => $owner, 'guest' => $guest, 'entreprise' => $e] = $this->seed();

        $this->client->loginUser($this->user(self::GUEST));
        $this->client->request('GET', sprintf('/admin/onboarding/workspace/%d/%d', $e->getId(), $guest->getId()));
        $this->assertResponseStatusCodeSame(403);

        // Et l'invité ne passe pas non plus en empruntant l'identifiant du propriétaire.
        $this->client->request('GET', sprintf('/admin/onboarding/workspace/%d/%d', $e->getId(), $owner->getId()));
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * Le rafraîchissement rend le panneau ET le voyant dans une seule réponse : les
     * servir en deux requêtes laisserait une fenêtre où l'un contredit l'autre.
     */
    public function testLEtatRendLesDeuxSurfacesEnUneSeuleReponse(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $this->client->loginUser($this->user(self::OWNER));
        $this->client->request('GET', sprintf('/admin/onboarding/api/etat/%d/%d', $e->getId(), $owner->getId()));
        $this->assertResponseIsSuccessful();

        $etat = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertIsInt($etat['score']);
        $this->assertFalse($etat['complet']);
        $this->assertStringContainsString('jsb-onboarding-carte', $etat['panneau']);
        $this->assertStringContainsString('ws-onboarding-jauge', $etat['voyant']);
    }

    /**
     * LE VOLET GAUCHE DU DIALOGUE, EN CRÉATION.
     *
     * Il était purement et simplement invisible : un tiers de la largeur perdu au moment
     * où l'utilisateur a le plus besoin d'être guidé. Il porte désormais la description
     * du paramètre — et jamais les attributs calculés, qui valent tous zéro sur une
     * entité neuve.
     */
    public function testLeDialogueDeCreationExpliqueCeQuOnCree(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $this->client->loginUser($this->user(self::OWNER));
        $this->client->request('GET', sprintf('/admin/assureur/api/get-form?idEntreprise=%d&idInvite=%d', $e->getId(), $owner->getId()));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('creation-description', $html);
        $this->assertStringContainsString('Sans assureur enregistré', $html);
        // Jamais les deux à la fois : sur une entité neuve, les attributs calculés
        // n'auraient que des zéros à montrer.
        $this->assertStringNotContainsString('calculated-attributes-item', $html);
    }

    /**
     * En ÉDITION, rien ne change : c'est cette symétrie qui garantit qu'aucun des
     * dialogues existants n'a été abîmé au passage.
     */
    public function testLeDialogueDEditionRetrouveSesAttributsCalcules(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $assureur = (new Assureur())->setNom('SUNU Assurances');
        $assureur->setEntreprise($e);
        $this->em->persist($assureur);
        $this->em->flush();

        $this->client->loginUser($this->user(self::OWNER));
        $this->client->request('GET', sprintf(
            '/admin/assureur/api/get-form/%d?idEntreprise=%d&idInvite=%d',
            $assureur->getId(), $e->getId(), $owner->getId(),
        ));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringNotContainsString('creation-description', $html);
        $this->assertStringContainsString('calculated-attributes-item', $html);
    }

    /**
     * NON-RÉGRESSION DES DIALOGUES NON TRAITÉS : une entité dont le provider ne déclare
     * pas de description garde exactement son comportement d'avant — colonne masquée en
     * création. C'est ce qui permet d'enrichir les dialogues un par un.
     */
    public function testUnDialogueSansDescriptionResteInchange(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $this->client->loginUser($this->user(self::OWNER));
        $this->client->request('GET', sprintf('/admin/client/api/get-form?idEntreprise=%d&idInvite=%d', $e->getId(), $owner->getId()));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringNotContainsString('creation-description', $html);
        $this->assertStringNotContainsString('calculated-attributes-item', $html);
    }
}
