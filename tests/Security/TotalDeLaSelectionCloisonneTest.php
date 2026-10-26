<?php

namespace App\Tests\Security;

use App\Entity\Client;
use App\Entity\Utilisateur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LE TOTAL DE LA SÉLECTION NE FRANCHIT PAS LA FRONTIÈRE DU CABINET (chantier 4, commit 3).
 *
 * Le total des lignes cochées est calculé par le serveur : la requête de la liste porte les
 * identifiants cochés, et le serveur ne totalise que ceux qui font partie de SA recherche.
 * Un identifiant du cabinet voisin glissé dans la requête n'entre jamais dans le total — et le
 * total dit qu'il ne couvre pas toute la demande, sans rien révéler de la ligne étrangère.
 */
class TotalDeLaSelectionCloisonneTest extends WebTestCase
{
    use SemisDeDeuxCabinetsTrait;

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

    public function testUnClientDuCabinetVoisinNEntreJamaisDansLeTotal(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $a = $seed['a'];
        $inviteA = $this->premiereInviteDe($a['owner'], $a['entreprise']);
        $this->remettreLeCabinetActif($a['owner'], $a['entreprise']);
        $clientA = $this->identifiantsDuCabinet(Client::class, $a['entreprise'])[0];
        $clientB = $this->identifiantsDuCabinet(Client::class, $seed['b']['entreprise'])[0];
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($a['owner']));

        $totaux = function (array $ids) use ($inviteA, $a): array {
            $this->client->request(
                'POST',
                sprintf('/admin/client/api/dynamic-query/%d/%d', $inviteA, $a['entreprise']),
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['criteria' => [], 'parentContext' => null, 'page' => 1, 'totauxDeLaSelection' => $ids]),
            );
            self::assertResponseIsSuccessful();

            return json_decode((string) $this->client->getResponse()->getContent(), true);
        };

        $seulementA = $totaux([$clientA]);
        self::assertSame(1, $seulementA['lignes']);
        self::assertNotSame([], $seulementA['totaux'], 'Le client A est totalisé.');

        $avecB = $totaux([$clientA, $clientB]);
        self::assertSame(1, $avecB['lignes'], 'Le client du cabinet voisin ne compte pas.');
        self::assertSame($seulementA['totaux'], $avecB['totaux'], 'Rien de B dans le total.');
        self::assertSame(' (1 sur 2)', $avecB['suffixe']);

        $seulementB = $totaux([$clientB]);
        self::assertSame(0, $seulementB['lignes']);
        self::assertSame([], $seulementB['totaux'], 'Aucune valeur du cabinet voisin.');
        self::assertArrayNotHasKey('html', $seulementB);
    }
}
