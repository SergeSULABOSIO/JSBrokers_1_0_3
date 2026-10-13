<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CE QUE LA VUE LISTE DES PISTES AFFICHAIT DE TRAVERS.
 *
 * - Le totaliseur disait « Total » à côté de « 1–20 sur 42 » : c'était la somme de la PAGE.
 * - L'id `principal` figurait deux fois — le panneau d'onglet ET sa liste.
 * - Le formulaire de la piste affichait « USD » en dur, quelle que soit la monnaie du cabinet.
 * - Le canevas de liste déclarait une icône que personne ne lisait.
 */
class VueListeIrregularitesTest extends WebTestCase
{
    private const EMAIL = 'phpunit-vue-liste@test.local';
    private const CABINET = 'PHPUnit Vue Liste SARL';

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
        foreach (['piste', 'monnaie', 'invite'] as $table) {
            $conn->executeStatement("DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = ?", [self::CABINET]);
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = ?', [self::CABINET]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
    }

    /** @return array{entreprise: int, invite: int} */
    private function semer(): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Vue Liste')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000066')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $owner->setConnectedTo($cabinet);
        $invite = (new Invite())->setNom('Proprio')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $em->persist($invite);

        // Un cabinet qui compte en EUROS : c'est ce que le formulaire doit dire.
        $monnaie = (new Monnaie())->setNom('Euro')->setCode('EUR')->setTauxusd('1')
            ->setFonction(Monnaie::FONCTION_SAISIE_ET_AFFICHAGE)->setLocale(false);
        $monnaie->setEntreprise($cabinet);
        $em->persist($monnaie);

        $piste = (new Piste())->setNom('Piste vue liste')->setTypeAvenant(0)->setDescriptionDuRisque('Risque')
            ->setExercice(2026)->setPrimePotentielle(1500.0)->setCommissionPotentielle(150.0);
        $piste->setEntreprise($cabinet)->setInvite($invite);
        $em->persist($piste);
        $em->flush();

        $ids = ['entreprise' => (int) $cabinet->getId(), 'invite' => (int) $invite->getId()];
        $this->client->loginUser($owner);
        $em->clear();

        return $ids;
    }

    private function rubriquePistes(array $ids): string
    {
        $this->client->request('GET', sprintf('/espacedetravail/api/load-component/%d/%d', $ids['invite'], $ids['entreprise']), [
            'component' => '_view_manager_marketing.html.twig',
            'entity' => 'Piste',
        ]);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    public function testLeTotaliseurDitQueCEstLeTotalDeLaPage(): void
    {
        $html = $this->rubriquePistes($this->semer());

        self::assertStringContainsString('Total de la page', $html);
        self::assertStringContainsString('Somme des lignes affichées sur cette page', $html);
    }

    public function testLIdPrincipalNEstPorteQuUneFois(): void
    {
        $html = $this->rubriquePistes($this->semer());

        // L'ATTRIBUT `id` lui-même : `data-tab-id="principal"` contient aussi la chaîne.
        self::assertSame(1, preg_match_all('/\sid="principal"/', $html), "L'id « principal » doit désigner la seule LISTE.");
        self::assertStringContainsString('id="panneau-principal"', $html);
        self::assertStringContainsString('data-panneau-onglet="principal"', $html);
        self::assertStringContainsString('aria-controls="panneau-principal"', $html, "L'onglet doit désigner son panneau.");
    }

    public function testLeFormulaireDeLaPisteParleLaMonnaieDuCabinet(): void
    {
        $this->semer();
        $crawler = $this->client->request('GET', '/admin/piste/api/get-form');
        self::assertResponseIsSuccessful();

        // MoneyType écrit le symbole de la monnaie à côté du champ.
        $autour = $crawler->filter('input[name$="primePotentielle"]')->ancestors()->first()->text();
        self::assertStringContainsString('€', $autour, 'La prime potentielle doit être libellée dans la monnaie du cabinet.');
        self::assertStringNotContainsString('$', $autour, '« USD » ne doit plus être écrit en dur.');
    }

    public function testLeCanevasDeListeNeDeclarePlusDIconeMorte(): void
    {
        $canevas = static::getContainer()->get(CanvasBuilder::class)->getListeCanvas(Piste::class);

        self::assertArrayNotHasKey('icone', $canevas['colonne_principale']['texte_principal']);
    }
}
