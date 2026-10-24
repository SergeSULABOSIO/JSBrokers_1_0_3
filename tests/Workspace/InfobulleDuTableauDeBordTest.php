<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use App\Services\ServiceSuppressionEntreprise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LES INFOBULLES DU TABLEAU DE BORD : DÉCLARÉES PAR LE SERVEUR, ÉCHAPPÉES, UNIQUES (chantier 3).
 *
 * Les neuf infobulles du tableau de bord fabriquaient leur HTML dans le navigateur, à partir de
 * dizaines d'attributs `data-*-tip-*`. Elles passent toutes par l'infobulle unique : le serveur
 * déclare le contenu en JSON (`data-infobulle`, `|json_encode|e('html_attr')`), le navigateur le
 * pose en TEXTE.
 *
 * Ajout 2 de Serge : une donnée piégée (" ' < > & et un saut de ligne) donne un JSON valide, un
 * texte rendu à l'identique, et aucun HTML interprété.
 */
class InfobulleDuTableauDeBordTest extends WebTestCase
{
    private const ENT = 'PHPUNIT Infobulles du tableau de bord';
    private const EMAIL = 'phpunit-infobulles-tdb@test.local';
    private const PIEGE = "Piste \"<img src=x onerror=alert(1)>\" l'été & <b>gras</b>\nligne 2";
    private const CLIENT_PIEGE = "Client </td><script>alert(2)</script> & 'cie'";

    private KernelBrowser $navigateur;

    protected function setUp(): void
    {
        $this->navigateur = static::createClient();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    public function testUneDonneePiegeeResteDuTexteDansLInfobulle(): void
    {
        $e = $this->semer();

        $crawler = $this->navigateur->request('GET', sprintf('/admin/entreprise_dashbord/block/pistes/%d', $e->getId()));
        self::assertResponseIsSuccessful();

        $bulles = $crawler->filter('[data-infobulle]');
        self::assertGreaterThan(0, $bulles->count(), 'La piste porte une infobulle déclarée par le serveur.');
        $sections = json_decode((string) $bulles->first()->attr('data-infobulle'), true, 512, \JSON_THROW_ON_ERROR);

        $lignes = array_merge(...array_map(static fn (array $s): array => $s['lignes'] ?? [], $sections));
        $valeurs = array_column($lignes, 1, 0);
        self::assertSame(self::PIEGE, $valeurs['Nom'] ?? null, 'Le nom revient À L’IDENTIQUE, guillemets, chevrons, esperluette et saut de ligne compris.');
        self::assertSame(self::CLIENT_PIEGE, $valeurs['Client'] ?? null);

        self::assertCount(0, $crawler->filter('img[src="x"]'), 'Aucune balise n’est sortie de l’attribut.');
        self::assertCount(0, $crawler->filter('script:not([src]):not([type])')->reduce(static fn ($n) => str_contains($n->text(), 'alert(2)')), 'Aucun script injecté.');
    }

    public function testPlusAucunAncienAttributDInfobulle(): void
    {
        $racine = \dirname(__DIR__, 2);
        $anciens = '/data-(dep|cash|sin|renew|compta|piste|bord|note|meta)-tip\b|data-tip-(label|intro|calcul)\b/';
        $fautifs = [];
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine . '/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterateur as $fichier) {
            if (str_ends_with($fichier->getFilename(), '.twig') && preg_match($anciens, (string) file_get_contents($fichier->getPathname()))) {
                $fautifs[] = substr($fichier->getPathname(), \strlen($racine) + 1);
            }
        }
        self::assertSame([], $fautifs, 'Une infobulle se déclare par data-infobulle (contenu JSON du serveur), plus par des attributs data-*-tip-*.');

        $app = (string) file_get_contents($racine . '/assets/app.js');
        foreach (['db-dep-tip', 'db-cash-tip', 'db-sin-tip', 'db-meta-tip', 'db-renew-tip', 'db-compta-tip', 'db-piste-tip', 'db-bord-tip', 'db-note-tip'] as $ancien) {
            self::assertStringNotContainsString("'$ancien'", $app, "app.js ne fabrique plus l'infobulle « $ancien » : elle passe par l'infobulle unique.");
        }
    }

    private function semer(): Entreprise
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Patron')->setVerified(true)->setPassword('x');
        $em->persist($u);
        $e = (new Entreprise())->setNom(self::ENT)->setLicence('L')->setAdresse('A')->setTelephone('T')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($u);
        $em->persist($e);
        $u->setConnectedTo($e);
        $invite = (new Invite())->setNom('Patron')->setUtilisateur($u)->setEntreprise($e)->setProprietaire(true);
        $em->persist($invite);
        $client = (new Client())->setNom(self::CLIENT_PIEGE)->setExonere(false);
        $client->setEntreprise($e);
        $em->persist($client);
        $piste = (new Piste())->setNom(self::PIEGE)->setClient($client)->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('r')->setExercice((int) date('Y'));
        $piste->setEntreprise($e);
        $piste->setInvite($invite);
        $em->persist($piste);
        $em->flush();
        $this->navigateur->loginUser($u);

        return $e;
    }

    private function nettoyer(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        foreach ($conn->fetchFirstColumn('SELECT id FROM entreprise WHERE nom = :n', ['n' => self::ENT]) as $id) {
            \App\Tests\Support\FichiersDesCabinetsDeTest::effacer(static::getContainer(), self::ENT);
            static::getContainer()->get(ServiceSuppressionEntreprise::class)->supprimer($em->find(Entreprise::class, (int) $id));
        }
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::EMAIL]);
        $em->clear();
    }
}
