<?php

namespace App\Tests\Ai;

use App\Ai\AiText;
use App\Ai\Fichier\FichierTexteExtracteur;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * UTF-8 GARANTI avant l'envoi au modèle.
 *
 * Incident du 2026-09-16 en production : « Invalid value for "json" option: Malformed
 * UTF-8 characters ». Un seul octet invalide rendait tout l'appel à Gemini inencodable
 * et Ket ne répondait plus. Deux fabriques : une lecture de fichier bornée en octets,
 * qui coupe un « é » en deux, et un CSV enregistré en Windows-1252 par Excel.
 */
class AiTextUtf8Test extends TestCase
{
    public function testUnTexteValideEstRenduTelQuel(): void
    {
        self::assertSame('Prime réglée — 15 % ✓', AiText::utf8('Prime réglée — 15 % ✓'));
    }

    public function testUnCaractereCoupeEnFinDeTexteEstRetire(): void
    {
        $coupe = substr('Caution réglée', 0, 10); // « Caution r\xC3 » : le « é » est coupé
        self::assertFalse(mb_check_encoding($coupe, 'UTF-8'));
        self::assertSame('Caution r', AiText::utf8($coupe));
    }

    public function testUnTexteWindows1252EstConverti(): void
    {
        self::assertSame('Prime réglée à 15 €', AiText::utf8("Prime r\xE9gl\xE9e \xE0 15 \x80"));
    }

    public function testLaReparationEnProfondeurDitCeQuElleAReparee(): void
    {
        $repares = [];
        $propre = AiText::utf8Profond([
            'contents' => [['parts' => [['text' => "r\xE9gl\xE9e"]]]],
            'ok'       => 'déjà valide',
            'nombre'   => 12,
        ], $repares);

        self::assertSame('réglée', $propre['contents'][0]['parts'][0]['text']);
        self::assertSame('déjà valide', $propre['ok']);
        self::assertSame(12, $propre['nombre']);
        self::assertSame(['contents.0.parts.0.text'], $repares);
        self::assertNotFalse(json_encode($propre), 'la charge est encodable');
    }

    public function testUnCsvExcelEnWindows1252EstLuEnUtf8(): void
    {
        $chemin = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($chemin, "Client;Prime;Statut\nSoci\xE9t\xE9 G\xE9n\xE9rale;1500;R\xE9gl\xE9e\n");

        $texte = (new FichierTexteExtracteur())->extraire(new UploadedFile($chemin, 'export.csv', 'text/csv', null, true));
        @unlink($chemin);

        self::assertNotNull($texte);
        self::assertTrue(mb_check_encoding($texte, 'UTF-8'));
        self::assertStringContainsString('Société Générale;1500;Réglée', $texte);
    }

    public function testUnTexteDontLaLectureCoupeUnCaractereResteValide(): void
    {
        // Assez long pour que la lecture bornée en octets tombe au milieu d'un « é ».
        $chemin = tempnam(sys_get_temp_dir(), 'txt');
        $borne = FichierTexteExtracteur::MAX_CARACTERES * 4;
        file_put_contents($chemin, str_repeat('a', $borne - 1) . 'é' . str_repeat('b', 50));

        $texte = (new FichierTexteExtracteur())->extraire(new UploadedFile($chemin, 'notes.txt', 'text/plain', null, true));
        @unlink($chemin);

        self::assertNotNull($texte);
        self::assertTrue(mb_check_encoding($texte, 'UTF-8'));
    }
}
