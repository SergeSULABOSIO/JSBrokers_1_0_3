<?php

namespace App\Tests\Security;

use App\Service\Contact\GardeDuFormulaireDeContact;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LE FORMULAIRE DE CONTACT ANONYME N'EST PAS UN RELAIS D'E-MAILS.
 *
 * Un visiteur sans compte y choisit l'adresse où Joseara envoie un accusé de réception.
 * Le garde (GardeDuFormulaireDeContact) écarte les robots sans leur dire — champ piège,
 * délai minimal signé — et limite les envois par IP et par adresse de destination, en
 * journalisant l'IP retenue (jamais le contenu) pour la vérifier en production.
 */
class FormulaireDeContactTest extends WebTestCase
{
    private const PREFIXE = 'phpunit-contact-';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Les compteurs du limiteur survivent d'un test à l'autre (cache) : on repart de zéro.
        static::getContainer()->get('cache.rate_limiter')->clear();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('DELETE FROM crm_ticket WHERE contact_email LIKE :e', ['e' => self::PREFIXE . '%']);
    }

    /**
     * Soumet le formulaire de la vitrine, comme un humain : affiché il y a une minute.
     *
     * @param array<string, string> $champs
     */
    private function soumettre(string $ip, string $email, array $champs = []): void
    {
        $crawler = $this->client->request('GET', '/', [], [], ['REMOTE_ADDR' => $ip]);
        $form = $crawler->filter('form.contact-form')->form();
        $form['demande_contact[name]'] = 'Visiteur';
        $form['demande_contact[email]'] = $email;
        $form['demande_contact[objet]'] = 'Question';
        $form['demande_contact[message]'] = 'Bonjour, une question sur vos services.';
        $form['demande_contact[affiche_le]'] = static::getContainer()->get(GardeDuFormulaireDeContact::class)
            ->jeton(new \DateTimeImmutable('-1 minute'));
        foreach ($champs as $nom => $valeur) {
            $form['demande_contact[' . $nom . ']'] = $valeur;
        }
        $this->client->submit($form, [], ['REMOTE_ADDR' => $ip]);
        $this->assertResponseRedirects();
    }

    /** @return list<string> */
    private function flashes(string $type): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peek($type);
    }

    private function journal(): TestHandler
    {
        return $this->client->getContainer()->get('monolog.handler.contact');
    }

    public function testUnHumainEnvoieEtRecoitSonAccuse(): void
    {
        $this->soumettre('10.0.0.1', self::PREFIXE . 'humain@test.local');
        $this->assertQueuedEmailCount(2, null, 'Message à l\'équipe et accusé au visiteur.');
    }

    public function testLeChampPiegeRempliNEnvoieRienEtRepondCommeUnSucces(): void
    {
        $this->soumettre('10.0.0.2', self::PREFIXE . 'robot@test.local', ['site_web' => 'https://spam.example']);
        $this->assertQueuedEmailCount(0);
        self::assertNotSame([], $this->flashes('success'), 'Le robot reçoit la même réponse qu\'un humain.');
        self::assertSame([], $this->flashes('error'));
    }

    public function testUnFormulaireRenvoyeTropViteNEnvoieRien(): void
    {
        $maintenant = static::getContainer()->get(GardeDuFormulaireDeContact::class)->jeton();
        $this->soumettre('10.0.0.3', self::PREFIXE . 'presse@test.local', ['affiche_le' => $maintenant]);
        $this->assertQueuedEmailCount(0);
        self::assertNotSame([], $this->flashes('success'));
    }

    public function testUnHorodatageFalsifieNEnvoieRien(): void
    {
        $this->soumettre('10.0.0.4', self::PREFIXE . 'faux@test.local', ['affiche_le' => '1000.' . str_repeat('a', 64)]);
        $this->assertQueuedEmailCount(0);
    }

    public function testLeSixiemeEnvoiDeLaMemeIpEstRefuseEtSonIpJournalisee(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->soumettre('10.9.8.7', self::PREFIXE . 'ip' . $i . '@test.local');
            $this->assertQueuedEmailCount(2, null, "L'envoi n° $i passe.");
        }

        $this->soumettre('10.9.8.7', self::PREFIXE . 'ip6@test.local');
        $this->assertQueuedEmailCount(0, null, 'Le 6ᵉ envoi de la même IP ne part pas.');
        self::assertNotSame([], $this->flashes('error'), 'Le refus est DIT au visiteur.');

        $refus = array_values(array_filter($this->journal()->getRecords(), fn ($r) => $r->level->getName() === 'WARNING'));
        self::assertCount(1, $refus);
        self::assertSame(['limiteur' => 'ip', 'ip' => '10.9.8.7'], $refus[0]->context, 'L\'IP retenue, et rien du contenu du formulaire.');
    }

    public function testLeQuatriemeEnvoiVersLaMemeAdresseEstRefuse(): void
    {
        $email = self::PREFIXE . 'cible@test.local';
        for ($i = 1; $i <= 3; ++$i) {
            $this->soumettre('10.1.1.' . $i, $email);
            $this->assertQueuedEmailCount(2, null, "L'envoi n° $i vers la même adresse passe.");
        }

        $this->soumettre('10.1.1.4', strtoupper($email));
        $this->assertQueuedEmailCount(0, null, 'Le 4ᵉ envoi vers la même adresse (casse comprise) ne part pas.');

        $refus = array_values(array_filter($this->journal()->getRecords(), fn ($r) => $r->level->getName() === 'WARNING'));
        self::assertSame(['limiteur' => 'destinataire', 'ip' => '10.1.1.4'], $refus[0]->context ?? null);
    }

    public function testLeChampPiegeEstHorsDeVueEtHorsDuClavier(): void
    {
        $crawler = $this->client->request('GET', '/');
        $piege = $crawler->filter('input[name="demande_contact[site_web]"]');
        self::assertCount(1, $piege);
        self::assertSame('-1', $piege->attr('tabindex'));
        self::assertSame('off', $piege->attr('autocomplete'));
    }
}
