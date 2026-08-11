<?php

namespace App\Tests\Frontend;

use App\Ai\Document\DocumentTarificateur;
use App\Repository\PlateformeParametresRepository;
use App\Token\ParametresTokenService;
use App\Token\TokenPricing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LE TARIF AFFICHÉ EST-IL LE TARIF FACTURÉ, et le reste-t-il dans les deux langues ?
 *
 * Les deux partiels de la page publique sont dupliqués À LA MAIN (aucune clé de
 * traduction) : leur unique mode de panne est la dérive entre le français et
 * l'anglais. D'où l'assertion croisée sur le nombre de lignes.
 *
 * ⚠ ISOLATION : `plateforme_parametres` est un SINGLETON GLOBAL, partagé par toute
 * la suite et sans rollback automatique. Un test qui l'écrit sans le purger fait
 * échouer, plus loin, des tests d'un AUTRE fichier — et l'échec est alors
 * introuvable. On purge donc en setUp ET en tearDown.
 */
class VitrineDocumentsIaTest extends WebTestCase
{
    private function purgerParametres(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('DELETE FROM plateforme_parametres');
        $em->clear();
        static::getContainer()->get(ParametresTokenService::class)->refresh();
    }

    protected function tearDown(): void
    {
        if (static::$booted) {
            $this->purgerParametres();
        }
        parent::tearDown();
    }

    /** Coût de référence d'un document de 3 pages, calculé par le FACTURIER. */
    private function coutTroisPages(string $format): int
    {
        return static::getContainer()->get(DocumentTarificateur::class)->chiffrerPages(3, $format)->cout;
    }

    public function testLaPageDecritLeBaremeDesDocumentsEnFrancais(): void
    {
        $client = static::createClient();
        $this->purgerParametres();

        $crawler = $client->request('GET', '/fonctionnement-tokens?lang=fr');
        self::assertResponseIsSuccessful();
        $texte = $crawler->text();

        self::assertStringContainsString('Documents produits par l’assistant', str_replace("'", '’', $texte));
        self::assertStringContainsString('Word', $texte);
        self::assertStringContainsString('Excel', $texte);
        // On assertionne sur le COÛT ENTIER, jamais sur le coefficient : « 1,5 » ou
        // « 1.5 » dépend de la locale du filtre, l'entier n'en dépend pas — et c'est
        // lui qui compte pour le client.
        self::assertStringContainsString((string) $this->coutTroisPages('docx'), $texte);
        self::assertStringContainsString((string) $this->coutTroisPages('pdf'), $texte);
    }

    public function testLaPageDecritLeBaremeDesDocumentsEnAnglais(): void
    {
        $client = static::createClient();
        $this->purgerParametres();

        $crawler = $client->request('GET', '/fonctionnement-tokens?lang=en');
        self::assertResponseIsSuccessful();
        $texte = $crawler->text();

        self::assertStringContainsString('Documents produced by the assistant', $texte);
        self::assertStringContainsString((string) $this->coutTroisPages('docx'), $texte);
    }

    /**
     * GARDE-FOU ANTI-DÉRIVE FR/EN : les deux partiels doivent lister exactement les
     * mêmes formats, et tous ceux que le code déclare.
     */
    public function testLesDeuxLanguesListentLesMemesFormats(): void
    {
        $client = static::createClient();
        $this->purgerParametres();

        $fr = $client->request('GET', '/fonctionnement-tokens?lang=fr')->filter('.cgu-format-row')->count();
        $en = $client->request('GET', '/fonctionnement-tokens?lang=en')->filter('.cgu-format-row')->count();

        self::assertSame($fr, $en, 'Les deux partiels sont dupliqués à la main : ils ont dérivé.');
        self::assertSame(count(TokenPricing::DOCUMENT_FORMATS), $fr);
    }

    /**
     * Le prix AFFICHÉ suit le barème édité en console : c'est la preuve que la page
     * publique et le facturier lisent la même source.
     */
    public function testLaPageRefleteLeBaremeEditeEnConsole(): void
    {
        $client = static::createClient();
        $this->purgerParametres();

        $avant = $this->coutTroisPages('docx');

        $repository = static::getContainer()->get(PlateformeParametresRepository::class);
        $parametres = $repository->getSingleton();
        $parametres->setDocumentBase(500);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        static::getContainer()->get(ParametresTokenService::class)->refresh();

        $apres = $this->coutTroisPages('docx');
        self::assertNotSame($avant, $apres, 'Le barème console doit changer le prix.');

        $texte = $client->request('GET', '/fonctionnement-tokens?lang=fr')->text();
        self::assertStringContainsString((string) $apres, $texte);
    }

    /** La vitrine annonce la capacité sur les paquets payants, dans les deux langues. */
    public function testLaVitrineAnnonceLesDocumentsIa(): void
    {
        $client = static::createClient();
        $this->purgerParametres();

        foreach (['fr', 'en'] as $langue) {
            $texte = $client->request('GET', '/?lang=' . $langue)->text();
            self::assertResponseIsSuccessful();
            self::assertMatchesRegularExpression(
                '/documents?/i',
                $texte,
                sprintf('La grille tarifaire (%s) doit mentionner les documents téléchargeables.', $langue),
            );
        }
    }
}
