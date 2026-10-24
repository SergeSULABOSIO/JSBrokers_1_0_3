<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Tests\Security\SemisDeDeuxCabinetsTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LES INFOBULLES MIGRÉES AU COMMIT 3a SE RENDENT (chantier 3).
 *
 * Le dernier commit (nom et version de l'application) et le statut de propriété d'un cabinet
 * déclarent leur contenu en JSON pour l'infobulle unique. Le lint Twig ne prouve que la syntaxe :
 * ce test rend les pages et décode le contenu — dont la liste des paragraphes du commit, filtrée
 * et transformée dans le gabarit.
 */
class InfobulleUniqueRenduTest extends WebTestCase
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

    public function testLeDernierCommitEtLeStatutDeProprieteDeclarentLeurInfobulle(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $em = $this->em();
        $owner = $em->getRepository(Utilisateur::class)->find($seed['a']['owner']);
        $entreprise = $em->find(Entreprise::class, $seed['a']['entreprise']);
        $invite = $em->getRepository(Invite::class)->findOneBy(['entreprise' => $entreprise]);
        $this->client->loginUser($owner);

        // L'espace de travail : le corps porte l'infobulle unique ; le nom et la version, le dernier commit.
        $page = $this->client->request('GET', sprintf('/espacedetravail/%d/%d', $invite->getId(), $entreprise->getId()));
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/\binfobulle\b/', (string) $page->filter('body')->attr('data-controller'));
        $version = $page->filter('.company-version[data-infobulle]');
        if ($version->count() > 0) { // git lisible sur ce poste : le dernier commit est connu
            $commit = json_decode((string) $version->attr('data-infobulle'), true, 512, \JSON_THROW_ON_ERROR);
            self::assertTrue(array_is_list($commit), 'Une liste de sections, pas un objet.');
            self::assertSame('Dernière mise à jour', $commit[0]['titre'] ?? null);
            self::assertSame('Réf.', $commit[0]['lignes'][0][0] ?? null, 'La référence dans une ligne : le titre passerait en majuscules.');
            self::assertMatchesRegularExpression('/^[0-9a-f]{7,}$/', $commit[0]['lignes'][0][1] ?? '');
            foreach (\array_slice($commit, 1) as $paragraphe) {
                self::assertNotEmpty($paragraphe['texte'] ?? null, 'Chaque paragraphe du commit est un texte non vide.');
            }
            self::assertSame($version->attr('data-infobulle'), $page->filter('.company-brand')->attr('data-infobulle'), 'Le nom et la version disent la même chose.');
        }

        // La page des cabinets : le statut de propriété.
        $cabinets = $this->client->request('GET', '/admin/entreprise');
        self::assertResponseIsSuccessful();
        $statut = $cabinets->filter('.ent-owner-status[data-infobulle]');
        self::assertGreaterThan(0, $statut->count(), 'Le statut de propriété porte l’infobulle unique.');
        $sections = json_decode((string) $statut->first()->attr('data-infobulle'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertNotEmpty($sections[0]['titre'] ?? null);
        self::assertNotEmpty($sections[0]['texte'] ?? null);

        // Le statut d'INVITÉ : le propriétaire du cabinet B, invité sur le cabinet A, voit le nom du
        // propriétaire de A dans la bulle (traduction à paramètre, échappée une seule fois).
        $this->rattacherAuCabinet($seed['b']['owner'], $seed['a']['entreprise'], 'Invité B');
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner']));
        $cabinets = $this->client->request('GET', '/admin/entreprise');
        self::assertResponseIsSuccessful();
        $invite = $cabinets->filter('.ent-owner-status--guest[data-infobulle]');
        self::assertGreaterThan(0, $invite->count(), 'Le statut d’invité porte l’infobulle unique.');
        $sections = json_decode((string) $invite->first()->attr('data-infobulle'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString((string) $owner->getNom(), $sections[0]['texte'] ?? '', 'Le nom du propriétaire, en texte.');
        self::assertStringNotContainsString('&amp;', $sections[0]['texte'] ?? '', 'Aucun double échappement.');
    }
}
