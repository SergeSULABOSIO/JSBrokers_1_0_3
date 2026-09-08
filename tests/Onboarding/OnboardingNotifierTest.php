<?php

namespace App\Tests\Onboarding;

use App\Entity\Assureur;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Service\Onboarding\OnboardingNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LA SYNTHÈSE DE CONFIGURATION NE PART QUE QUAND LE SCORE BOUGE.
 *
 * C'est tout l'objet de ce test. « À chaque mise à jour » pris au pied de la lettre
 * enverrait un courriel par assureur créé : dix assureurs d'affilée, dix courriels, et
 * le onzième en indésirable avec tous les suivants. Le déclencheur est donc le SCORE,
 * qui ne change que lorsqu'une étape bascule.
 */
class OnboardingNotifierTest extends WebTestCase
{
    private const ENT = 'PHPUnit-Onboarding-Notifier';
    private const OWNER = 'phpunit-onboarding-notifier@test.local';

    private $client;
    private EntityManagerInterface $em;
    private OnboardingNotifier $notifier;
    private Entreprise $entreprise;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->notifier = static::getContainer()->get(OnboardingNotifier::class);
        $this->cleanUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function seed(): void
    {
        $owner = new Utilisateur();
        $owner->setEmail(self::OWNER)->setNom('Propriétaire');
        $owner->setPassword('x');
        $owner->setVerified(true);
        $this->em->persist($owner);

        $this->entreprise = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($this->entreprise);

        $invite = (new Invite())->setNom('Administrateur')->setUtilisateur($owner)
            ->setEntreprise($this->entreprise)->setProprietaire(true);
        $this->em->persist($invite);

        $this->em->flush();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :o', ['o' => self::OWNER]);
        $conn->executeStatement('DELETE t FROM assureur t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :o', ['o' => self::OWNER]);
        $this->em->clear();
    }

    private function entreprise(): Entreprise
    {
        return $this->em->getRepository(Entreprise::class)->findOneBy(['nom' => self::ENT]);
    }

    /**
     * Amène le cabinet dans l'état d'un cabinet ORDINAIRE : celui d'après sa création,
     * où le repère de départ est déjà posé (ServiceProvisionEntreprise s'en charge).
     */
    private function poserLeRepere(): void
    {
        $this->notifier->notifierSiScoreChange($this->entreprise());
        $this->em->clear();
    }

    private function creerAssureur(string $nom): void
    {
        $assureur = (new Assureur())->setNom($nom);
        $assureur->setEntreprise($this->entreprise());
        $this->em->persist($assureur);
        $this->em->flush();
    }

    /** Les messages en file adressés au propriétaire — les seuls qui nous concernent. */
    private function courrielsPourLeProprietaire(): array
    {
        return array_values(array_filter(
            $this->getMailerMessages(),
            static function ($message): bool {
                foreach ($message->getTo() as $destinataire) {
                    if ($destinataire->getAddress() === self::OWNER) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * LE PREMIER PASSAGE POSE UN REPÈRE, SANS RIEN ENVOYER.
     *
     * Sans repère antérieur, on ne sait pas si le score vient de bouger : la moindre
     * écriture sans rapport — un relevé envoyé à un client — déclencherait une synthèse
     * annonçant un progrès qui n'a pas eu lieu. C'est d'ailleurs ce qui arrivait, et un
     * test du SOA l'a vu avant l'utilisateur : deux courriels au lieu d'un.
     */
    public function testLePremierPassagePoseLeRepereSansEnvoyer(): void
    {
        $this->assertNull($this->entreprise()->getOnboardingScoreNotifie());

        $this->assertFalse($this->notifier->notifierSiScoreChange($this->entreprise()));
        $this->assertSame([], $this->courrielsPourLeProprietaire());

        $this->em->clear();
        $this->assertNotNull($this->entreprise()->getOnboardingScoreNotifie());
    }

    public function testUnScoreInchangeNEnvoieRien(): void
    {
        $this->poserLeRepere();
        $avant = count($this->courrielsPourLeProprietaire());

        $this->em->clear();
        $this->assertFalse($this->notifier->notifierSiScoreChange($this->entreprise()));

        $this->assertCount($avant, $this->courrielsPourLeProprietaire());
    }

    public function testUneEtapeQuiBasculeDeclencheLaSynthese(): void
    {
        $this->poserLeRepere();

        $this->creerAssureur('SUNU Assurances');
        $this->em->clear();

        $this->assertTrue($this->notifier->notifierSiScoreChange($this->entreprise()));

        $courriels = $this->courrielsPourLeProprietaire();
        $this->assertCount(1, $courriels);
        // Objet normalisé : « Joseara - <objet> - <concerné> ».
        $this->assertStringContainsString('Joseara', $courriels[0]->getSubject());
        $this->assertStringContainsString('Configuration du cabinet', $courriels[0]->getSubject());
        $this->assertStringContainsString(self::ENT, $courriels[0]->getSubject());
    }

    /**
     * LE CŒUR DU SUJET : le second assureur ne fait pas basculer l'étape, donc ne change
     * pas le score, donc n'envoie rien.
     */
    public function testUnSecondObjetDeLaMemeEtapeNEnvoieRien(): void
    {
        $this->poserLeRepere();
        $this->creerAssureur('SUNU Assurances');
        $this->em->clear();
        $this->notifier->notifierSiScoreChange($this->entreprise());
        $apres = count($this->courrielsPourLeProprietaire());

        $this->creerAssureur('Rawsur SA');
        $this->em->clear();

        $this->assertFalse($this->notifier->notifierSiScoreChange($this->entreprise()));
        $this->assertCount($apres, $this->courrielsPourLeProprietaire());
    }

    /** Le bouton du courriel ramène DANS le guide, et pas seulement dans l'espace. */
    public function testLeCourrielPorteLeLienQuiOuvreLeGuide(): void
    {
        $this->poserLeRepere();
        $this->creerAssureur('SUNU Assurances');
        $this->em->clear();
        $this->notifier->notifierSiScoreChange($this->entreprise());

        $corps = $this->courrielsPourLeProprietaire()[0]->getHtmlBody();

        $this->assertStringContainsString('onboarding=1', (string) $corps);
        $this->assertStringContainsString('Reprendre la configuration', (string) $corps);
        // Il nomme ce qui manque, comme les autres surfaces de rappel.
        $this->assertStringContainsString('Assureurs', (string) $corps);
    }

    /**
     * LE REPÈRE S'ÉCRIT SANS FLUSHER L'UNITÉ DE TRAVAIL EN COURS.
     *
     * Ce service tourne en FIN DE REQUÊTE, après le travail de quelqu'un d'autre. Écrit
     * avec `flush()`, il rejouait tout ce qui traînait dans l'EntityManager — et
     * RESSUSCITAIT un portefeuille qui venait d'être supprimé : la suppression répondait
     * 200, l'objet disparaissait, puis reparaissait après la réponse.
     *
     * On simule ici la même situation : une suppression flushée, puis le notifieur. Ce
     * qui a été supprimé doit le rester.
     */
    public function testLeRepereNeRessusciteRien(): void
    {
        $this->poserLeRepere();
        $this->creerAssureur('Assureur condamné');

        $assureur = $this->em->getRepository(Assureur::class)
            ->findOneBy(['nom' => 'Assureur condamné']);
        $id = $assureur->getId();

        $this->em->remove($assureur);
        $this->em->flush();

        // L'EntityManager n'est PAS vidé : on reproduit l'état réel d'une fin de requête,
        // avec ses entités supprimées encore présentes en mémoire.
        $this->notifier->notifierSiScoreChange($this->entreprise());

        $this->em->clear();
        $this->assertNull(
            $this->em->getRepository(Assureur::class)->find($id),
            "L'objet supprimé ne doit pas réapparaître après le passage du notifieur.",
        );
    }
}
