<?php

namespace App\Tests\Security;

use App\Security\Csp\NonceCsp;
use App\Supervision\AdresseDuNavigateur;
use PHPUnit\Framework\TestCase;

/**
 * Les adresses rapportées par le navigateur (erreurs JS, violations CSP) : ce qui est
 * étranger à notre code, et ce qui ne doit jamais entrer dans la supervision.
 */
class AdresseDuNavigateurTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function adresses(): iterable
    {
        yield 'extension complète' => ['chrome-extension://abc/x.js', true];
        yield 'extension, schéma nu (CSP3)' => ['chrome-extension', true];
        yield 'Firefox, schéma nu' => ['moz-extension', true];
        yield 'Safari' => ['safari-web-extension://x/y.js', true];
        yield 'page vide' => ['about:blank', true];
        yield 'about nu' => ['about', true];
        yield 'antivirus' => ['http://me.kis.v2.scr.kaspersky-labs.com/FD12/main.js', true];
        yield 'notre fichier' => ['https://www.joseara.com/assets/app-abc.js', false];
        yield 'inline' => ['inline', false];
        yield 'un hôte qui ressemble' => ['https://kaspersky-labs.com.evil.example/x.js', false];
    }

    /** @dataProvider adresses */
    public function testEstEtrangere(string $adresse, bool $attendu): void
    {
        self::assertSame($attendu, AdresseDuNavigateur::estEtrangere($adresse));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function anonymisations(): iterable
    {
        yield 'requête et fragment' => ['https://www.joseara.com/login?jeton=X#y', 'https://www.joseara.com/login'];
        yield 'identifiants numériques' => ['https://www.joseara.com/espacedetravail/12/345', 'https://www.joseara.com/espacedetravail/{id}/{id}'];
        yield 'jeton du relevé public' => ['https://www.joseara.com/soa/' . str_repeat('9f3c', 16), 'https://www.joseara.com/soa/{jeton}'];
        yield 'nom de fichier haché intact' => ['https://www.joseara.com/assets/controllers/list-manager_controller-7i3XfLN.js', 'https://www.joseara.com/assets/controllers/list-manager_controller-7i3XfLN.js'];
        yield 'long segment sans chiffre intact' => ['https://www.joseara.com/admin/entreprise_dashbord_fragment_des_retours', 'https://www.joseara.com/admin/entreprise_dashbord_fragment_des_retours'];
        yield 'hôte avec port intact' => ['http://127.0.0.1:8901/x/7', 'http://127.0.0.1:8901/x/{id}'];
        yield 'inline' => ['inline', 'inline'];
        yield 'rien' => [null, null];
        yield 'seulement une requête' => ['?a=1', null];
    }

    /** @dataProvider anonymisations */
    public function testAnonymisee(?string $adresse, ?string $attendu): void
    {
        self::assertSame($attendu, AdresseDuNavigateur::anonymisee($adresse));
    }

    /** Le nonce est oublié entre deux requêtes (kernel.reset) : jamais deux fois le même. */
    public function testLeNonceEstOublieALaRemiseAZero(): void
    {
        $nonce = new NonceCsp();
        $premier = $nonce->valeur();

        self::assertSame($premier, $nonce->valeur(), 'Stable pendant la requête.');
        $nonce->reset();
        self::assertNotSame($premier, $nonce->valeur());
        self::assertSame(16, \strlen((string) base64_decode($premier, true)));
    }
}
