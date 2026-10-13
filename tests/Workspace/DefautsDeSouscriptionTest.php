<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use App\Service\Avenant\DefautsDeSouscription;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SOUSCRIRE UNE COTATION DONNE LE MÊME AVENANT, PAR L'ÉCRAN OU PAR KET.
 *
 * Le formulaire d'un avenant créé sous une cotation proposait « maintenant + 1 an », sans
 * lire la durée de la proposition ; Ket, lui, posait début + durée − 1 jour, le numéro 0 et
 * une description. Les règles vivent désormais dans DefautsDeSouscription, que les deux
 * chemins appellent.
 */
class DefautsDeSouscriptionTest extends WebTestCase
{
    private const EMAIL = 'phpunit-defauts-souscription@test.local';
    private const CABINET = 'PHPUnit Defauts Souscription SARL';
    private const VOISIN = 'PHPUnit Defauts Souscription Voisin SARL';

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
        foreach (['avenant', 'cotation', 'piste', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:n)",
                ['n' => [self::CABINET, self::VOISIN]],
                ['n' => ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (?, ?)', [self::CABINET, self::VOISIN]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
    }

    /** @return array{cotation: int, voisine: int} */
    private function semer(): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Souscription')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $cotations = [];
        foreach ([self::CABINET, self::VOISIN] as $nom) {
            $cabinet = (new Entreprise())->setNom($nom)->setLicence('L')->setAdresse('1 rue')
                ->setTelephone('+243000000044')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
            $em->persist($cabinet);
            $invite = (new Invite())->setNom('Proprio')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
            $em->persist($invite);
            $piste = (new Piste())->setNom('Piste ' . $nom)->setTypeAvenant(0)->setDescriptionDuRisque('Risque')->setExercice(2026);
            $piste->setEntreprise($cabinet)->setInvite($invite);
            $em->persist($piste);
            $cotation = (new Cotation())->setNom('Proposition six mois')->setDuree(6);
            $cotation->setPiste($piste)->setEntreprise($cabinet);
            $em->persist($cotation);
            $cotations[] = $cotation;
            if ($nom === self::CABINET) {
                $owner->setConnectedTo($cabinet);
            }
        }
        $em->flush();
        $ids = ['cotation' => (int) $cotations[0]->getId(), 'voisine' => (int) $cotations[1]->getId()];
        $this->client->loginUser($owner);
        $em->clear();

        return $ids;
    }

    public function testLaRegle(): void
    {
        $defauts = new DefautsDeSouscription();
        $cotation = (new Cotation())->setNom('Proposition')->setDuree(6);

        $v = $defauts->pour($cotation, new \DateTimeImmutable('2026-01-31'), null);
        self::assertSame('2026-01-31', $v['startingAt']->format('Y-m-d'));
        self::assertSame('2026-07-30', $v['endingAt']->format('Y-m-d'), 'Début + durée − 1 jour.');
        self::assertSame('0', $v['numero']);
        self::assertSame('Souscription de la proposition « Proposition ».', $v['description']);
        self::assertCount(2, $v['annonces'], 'Date dictée : on annonce la période et le numéro, pas la date.');

        $sansDate = $defauts->pour($cotation, null, '3');
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $sansDate['startingAt']->format('Y-m-d'));
        self::assertSame('3', $sansDate['numero']);
        self::assertStringStartsWith('Prise d’effet', $sansDate['annonces'][0]);

        self::assertNull($defauts->pour((new Cotation())->setNom('Sans durée')->setDuree(0))['endingAt'], 'Sans durée, aucune fin devinée.');

        self::assertFalse($defauts->dejaSouscrite($cotation));
        $cotation->addAvenant(new Avenant());
        self::assertTrue($defauts->dejaSouscrite($cotation));
    }

    public function testLeFormulaireDeLAvenantSousUneCotationEstPreremplitCommeKet(): void
    {
        $ids = $this->semer();
        $crawler = $this->client->request('GET', '/admin/avenant/api/get-form?parent_field_name=cotation&parent_id=' . $ids['cotation']);
        self::assertResponseIsSuccessful();

        $aujourdhui = new \DateTimeImmutable('today');
        $fin = $aujourdhui->modify('+6 month')->modify('-1 day');
        $valeur = static fn (string $champ): string => (string) $crawler->filter(sprintf('[name="%s"]', $champ))->attr('value');

        self::assertStringStartsWith($aujourdhui->format('Y-m-d'), $valeur('startingAt'));
        self::assertStringStartsWith($fin->format('Y-m-d'), $valeur('endingAt'), 'La période suit la DURÉE de la proposition (six mois), pas « un an ».');
        self::assertSame('0', $valeur('numero'));
        self::assertStringContainsString('Souscription de la proposition « Proposition six mois »', (string) $this->client->getResponse()->getContent());
    }

    public function testLaCotationDUnAutreCabinetNOuvreRien(): void
    {
        $ids = $this->semer();
        $this->client->request('GET', '/admin/avenant/api/get-form?parent_field_name=cotation&parent_id=' . $ids['voisine']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testHorsCotationLeDefautResteInchange(): void
    {
        $this->semer();
        $crawler = $this->client->request('GET', '/admin/avenant/api/get-form');
        self::assertResponseIsSuccessful();
        $fin = (string) $crawler->filter('[name="endingAt"]')->attr('value');
        self::assertStringStartsWith((new \DateTimeImmutable('+1 year'))->format('Y-m-d'), $fin);
    }
}
