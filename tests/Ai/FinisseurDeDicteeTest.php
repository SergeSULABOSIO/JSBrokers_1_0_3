<?php

namespace App\Tests\Ai;

use App\Ai\Debit\BudgetDebit;
use App\Ai\Dictee\FinisseurDeDictee;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Finition d'une dictée vocale : le texte revient mis au propre, ou revient BRUT —
 * jamais perdu, jamais dénaturé.
 */
class FinisseurDeDicteeTest extends TestCase
{
    private const BRUT = 'euh bonjour Ket euh j\'ai un client le le client Kibali qui fait de la construction '
        . 'quels risques je peux lui proposer avec une prime de 1 500 dollars';

    private static function reponse(string $texte): MockResponse
    {
        return new MockResponse(json_encode([
            'candidates'    => [['content' => ['parts' => [['text' => json_encode(['texte' => $texte])]]]]],
            'usageMetadata' => ['promptTokenCount' => 420],
        ]));
    }

    /** @param list<MockResponse>|callable $reponses */
    private function finisseur(array|callable $reponses, string $moteur = '', ?MockHttpClient &$http = null): FinisseurDeDictee
    {
        $http = new MockHttpClient($reponses);

        return new FinisseurDeDictee(
            $http,
            new BudgetDebit(new ArrayAdapter()),
            new NullLogger(),
            'gm-test',
            'gemini-flash-lite-test',
            $moteur,
        );
    }

    public function testLeTexteRevientMisAuPropre(): void
    {
        $propre = "Bonjour Ket. J'ai un client, Kibali, qui fait de la construction : quels risques puis-je lui "
            . 'proposer, avec une prime de 1 500 dollars ?';

        $finition = $this->finisseur([self::reponse($propre)], '', $http)->finir(self::BRUT);

        self::assertTrue($finition->finie);
        self::assertSame($propre, $finition->texte);
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testUnNombrePerduRendLeTexteBrut(): void
    {
        $sansMontant = "Bonjour Ket. J'ai un client, Kibali, qui fait de la construction : quels risques puis-je lui "
            . 'proposer, avec une prime de 1 400 dollars ?';

        $finition = $this->finisseur([self::reponse($sansMontant)])->finir(self::BRUT);

        self::assertFalse($finition->finie);
        self::assertSame(self::BRUT, $finition->texte);
    }

    public function testUneSortieQuiSEffondreRendLeTexteBrut(): void
    {
        $finition = $this->finisseur([self::reponse('Quels risques pour 1500 ?')])->finir(self::BRUT);

        self::assertFalse($finition->finie);
        self::assertSame(self::BRUT, $finition->texte);
    }

    public function testUnePanneRendLeTexteBrut(): void
    {
        $finition = $this->finisseur([new MockResponse('', ['http_code' => 503])])->finir(self::BRUT);

        self::assertFalse($finition->finie);
        self::assertSame(self::BRUT, $finition->texte);
    }

    public function testLeMoteurSimuleNAppelleAucuneApi(): void
    {
        $finition = $this->finisseur([], 'simulated', $http)->finir(self::BRUT);

        self::assertFalse($finition->finie);
        self::assertSame(self::BRUT, $finition->texte);
        self::assertSame(0, $http->getRequestsCount());
    }

    /** Constaté sur Gemini le 2026-09-16 : « Bonjour Ket » rendu « Bonjour. ». */
    public function testUnNomPerduRendLeTexteBrut(): void
    {
        $finisseur = $this->finisseur([]);

        self::assertFalse($finisseur->fidele('euh bonjour Ket pour le client Ki pour le client Kibali', 'Bonjour. Pour le client Kibali ?'));
        self::assertTrue($finisseur->fidele('euh bonjour Ket pour le client Ki pour le client Kibali', 'Bonjour Ket, pour le client Kibali ?'), 'le faux départ « Ki » est couvert par « Kibali »');
    }

    /** Constaté sur Gemini le 2026-09-16 : la liste revenait sur une seule ligne. */
    public function testUneListeEnLigneEstRemiseSurDesLignes(): void
    {
        $finisseur = $this->finisseur([]);

        self::assertSame(
            "Donne-moi :\n- les clients impayés\n- les polices échues.\nPour Kibali ?",
            $finisseur->listesSurDesLignes('Donne-moi : - les clients impayés - les polices échues. Pour Kibali ?'),
        );
        self::assertSame("Déjà :\n- a\n- b", $finisseur->listesSurDesLignes("Déjà :\n- a\n- b"), 'une liste déjà en lignes reste intacte');
        self::assertSame('Un tiret - isolé.', $finisseur->listesSurDesLignes('Un tiret - isolé.'));
    }

    public function testLesChiffresSontComparesSansEspaces(): void
    {
        $finisseur = $this->finisseur([]);

        self::assertTrue($finisseur->fidele('une prime de 1 500 dollars au 12', 'Une prime de 1500 dollars au 12.'));
        self::assertFalse($finisseur->fidele('une prime de 1 500 dollars au 12', 'Une prime de 1500 dollars au 21.'));
    }
}
