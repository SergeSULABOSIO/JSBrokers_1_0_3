<?php

namespace App\Tests\Frontend;

use App\Service\Terminal\DetecteurDeTerminal;
use App\Service\Terminal\Terminal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * LE TERMINAL EST DÉTECTÉ AVANT LE PREMIER RENDU, ET UNE SEULE FOIS.
 *
 * Joseara ne sert pas la même surface à tous les appareils : téléphone et tablette
 * reçoivent la conversation avec Ket en plein écran, l'ordinateur garde l'espace de
 * travail à colonnes. C'est donc le serveur qui tranche, et cette décision se prend
 * sur quatre indices d'inégale valeur.
 *
 * ── CE QUI ARRIVERAIT SANS CE TEST ──────────────────────────────────────────────
 * L'ordre des indices ne se voit pas à la relecture, et chaque inversion produit une
 * panne différente et silencieuse :
 *  - le cookie passant après l'UA, la bascule « Afficher la version ordinateur »
 *    deviendrait un bouton qui ne tient pas — l'UA la contredirait à chaque page ;
 *  - le motif `iPad` passant après `Mobile`, un iPad deviendrait un téléphone (son
 *    UA contient les deux) ;
 *  - `Sec-CH-UA-Mobile: ?0` traité comme une preuve d'ordinateur, toute tablette
 *    Android recevrait les colonnes.
 *
 * Le dernier test est un garde-fou de CONCORDANCE, de la même famille que
 * `AdaptationEcransEtroitsTest` : le nom du cookie et les noms des trois modes
 * traversent PHP et JavaScript, et aucun des deux langages ne force l'accord.
 */
class TerminalDetectionTest extends TestCase
{
    private const GABARIT = __DIR__ . '/../../templates/base.html.twig';

    private static function req(array $enTetes = [], array $cookies = [], array $query = []): Request
    {
        $serveur = [];
        foreach ($enTetes as $nom => $valeur) {
            $serveur['HTTP_' . strtoupper(str_replace('-', '_', $nom))] = $valeur;
        }

        return new Request($query, [], [], $cookies, [], $serveur);
    }

    /** @return iterable<string, array{0: Request, 1: Terminal}> */
    public static function casDeDetection(): iterable
    {
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        $ipad = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        $androidTel = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';
        $androidTab = 'Mozilla/5.0 (Linux; Android 14; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
        $windows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
        $macIpad = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

        yield 'iPhone' => [self::req(['User-Agent' => $iphone]), Terminal::MOBILE];
        yield 'iPad' => [self::req(['User-Agent' => $ipad]), Terminal::TABLETTE];
        yield 'telephone Android' => [self::req(['User-Agent' => $androidTel]), Terminal::MOBILE];
        yield 'tablette Android (UA sans Mobile)' => [self::req(['User-Agent' => $androidTab]), Terminal::TABLETTE];
        yield 'Windows' => [self::req(['User-Agent' => $windows]), Terminal::ORDINATEUR];
        yield 'aucun User-Agent' => [self::req(), Terminal::ORDINATEUR];

        // L'iPad moderne se déclare « Macintosh » : l'UA seul ne peut PAS le
        // reconnaître, et c'est assumé — c'est le trou que la sonde du navigateur
        // vient boucher en écrivant le cookie.
        yield 'iPadOS deguise en Macintosh (limite connue de l UA)' => [
            self::req(['User-Agent' => $macIpad]),
            Terminal::ORDINATEUR,
        ];

        // Client hints : déclarés par le navigateur, donc plus fiables que l'UA.
        yield 'client hint mobile' => [
            self::req(['User-Agent' => $windows, 'Sec-CH-UA-Mobile' => '?1']),
            Terminal::MOBILE,
        ];
        yield 'client hint Android non mobile = tablette' => [
            self::req(['User-Agent' => $macIpad, 'Sec-CH-UA-Mobile' => '?0', 'Sec-CH-UA-Platform' => '"Android"']),
            Terminal::TABLETTE,
        ];
        yield 'client hint plateforme de bureau' => [
            self::req(['User-Agent' => $iphone, 'Sec-CH-UA-Mobile' => '?0', 'Sec-CH-UA-Platform' => '"Windows"']),
            Terminal::ORDINATEUR,
        ];

        // Le CHOIX de l'utilisateur passe devant tout le reste : c'est ce qui rend
        // la bascule « Afficher la version ordinateur » tenable sur une tablette.
        yield 'cookie ordinateur contre un UA mobile' => [
            self::req(['User-Agent' => $iphone, 'Sec-CH-UA-Mobile' => '?1'], [DetecteurDeTerminal::COOKIE => 'ordinateur']),
            Terminal::ORDINATEUR,
        ];
        yield 'parametre d URL contre le cookie' => [
            self::req(['User-Agent' => $windows], [DetecteurDeTerminal::COOKIE => 'ordinateur'], [DetecteurDeTerminal::PARAM => 'mobile']),
            Terminal::MOBILE,
        ];
        yield 'cookie illisible : on redescend aux indices' => [
            self::req(['User-Agent' => $iphone], [DetecteurDeTerminal::COOKIE => 'n_importe_quoi']),
            Terminal::MOBILE,
        ];
    }

    /**
     * @dataProvider casDeDetection
     */
    public function testLOrdreDesIndicesEstRespecte(Request $requete, Terminal $attendu): void
    {
        self::assertSame($attendu, (new DetecteurDeTerminal())->detecter($requete));
    }

    /**
     * MOBILE ET TABLETTE PARTAGENT LA MÊME SURFACE, ET C'EST DIT À UN SEUL ENDROIT.
     */
    public function testLeModeKetCouvreMobileEtTablette(): void
    {
        self::assertTrue(Terminal::MOBILE->modeKet());
        self::assertTrue(Terminal::TABLETTE->modeKet());
        self::assertFalse(Terminal::ORDINATEUR->modeKet());
    }

    /**
     * UN MODE DEVINÉ N'EST PAS UN MODE CHOISI.
     *
     * La sonde du navigateur ne doit corriger que ce que le serveur a DEVINÉ. Si
     * `estFige()` répondait « non » sur un choix explicite, la sonde écraserait la
     * bascule de l'utilisateur au rechargement suivant.
     */
    public function testUnChoixExpliciteEstReconnuCommeFige(): void
    {
        $detecteur = new DetecteurDeTerminal();

        self::assertTrue($detecteur->estFige(self::req([], [DetecteurDeTerminal::COOKIE => 'ordinateur'])));
        self::assertTrue($detecteur->estFige(self::req([], [], [DetecteurDeTerminal::PARAM => 'mobile'])));
        self::assertFalse($detecteur->estFige(self::req(['User-Agent' => 'Mozilla/5.0 (iPhone) Mobile'])));
        self::assertFalse($detecteur->estFige(self::req([], [DetecteurDeTerminal::COOKIE => 'valeur_inconnue'])));
    }

    /**
     * LE PHP ET LA SONDE PARLENT DU MÊME COOKIE ET DES MÊMES MODES.
     *
     * La sonde écrit le cookie en JavaScript, sans repasser par le serveur. Si un
     * seul des deux côtés changeait de nom — de cookie ou de mode — la correction
     * serait écrite dans le vide : la page se rechargerait, le serveur ne verrait
     * rien, et la sonde se tairait ensuite à cause de son propre marqueur.
     * L'appareil resterait sur la mauvaise surface, sans le moindre message.
     */
    public function testLaSondeEtLeDetecteurParlentDuMemeCookie(): void
    {
        self::assertFileExists(self::GABARIT);
        $gabarit = (string) file_get_contents(self::GABARIT);

        // Le nom du cookie n'est pas recopié à la main : il vient de l'extension Twig.
        self::assertStringContainsString(
            '{{ terminal_cookie() }}=',
            $gabarit,
            'La sonde doit écrire le cookie dont le nom vient du PHP, jamais un littéral.',
        );
        self::assertStringContainsString(
            'max-age={{ terminal_duree_cookie() }}',
            $gabarit,
            'La durée du cookie doit elle aussi venir du PHP.',
        );

        // Les trois modes sont des valeurs d'énumération : la sonde en écrit deux
        // ('mobile', 'tablette') et compare au troisième ('ordinateur').
        foreach (Terminal::cases() as $mode) {
            self::assertStringContainsString(
                "'{$mode->value}'",
                $gabarit,
                "La sonde doit nommer le mode {$mode->value} exactement comme l'énumération PHP.",
            );
        }

        // Les trois garde-fous de la sonde, chacun contre une panne distincte.
        self::assertStringContainsString(
            "dataset.terminalFige === '1'",
            $gabarit,
            'La sonde ne doit jamais corriger un mode choisi explicitement.',
        );
        self::assertStringContainsString(
            "sessionStorage.getItem('jsb_terminal_sonde')",
            $gabarit,
            "Sans marqueur de session, un désaccord permanent rechargerait la page à l'infini.",
        );
        self::assertStringContainsString(
            'data-terminal="{{ terminal_courant() }}"',
            $gabarit,
            'Le mode servi doit être publié au front : la sonde le compare, elle ne le devine pas.',
        );
    }
}
