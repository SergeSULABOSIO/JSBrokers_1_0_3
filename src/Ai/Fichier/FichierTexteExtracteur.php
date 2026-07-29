<?php

namespace App\Ai\Fichier;

use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Extrait, best-effort et BORNÉ, le texte d'un fichier attaché au chat pour
 * alimenter la section « PIÈCES JOINTES » du prompt — c'est ce qui permet à Ket
 * de LIRE, citer, résumer, traduire ou restructurer le contenu (avec un moteur
 * LLM réel). Formats lus : texte simple (.txt/.csv/.md/.json), PDF (smalot/
 * pdfparser), Word .docx (XML interne) et Excel .xlsx (PhpSpreadsheet). Les
 * autres formats (images, .doc/.xls anciens) renvoient null — le fichier reste
 * attachable et classable, simplement non lu.
 *
 * KISS + robustesse : jamais d'exception propagée (un fichier illisible ne doit
 * pas casser l'upload), et le résultat est plafonné en caractères pour maîtriser
 * le coût en tokens.
 */
class FichierTexteExtracteur
{
    /** Plafond dur du texte extrait (caractères) — garde-fou tokens. */
    public const MAX_CARACTERES = 20000;

    /** Extensions lues comme du texte brut. */
    private const EXT_TEXTE = ['txt', 'csv', 'md', 'json'];

    public function extraire(UploadedFile $fichier): ?string
    {
        $ext = strtolower($fichier->getClientOriginalExtension() ?: $fichier->guessExtension() ?: '');
        $mime = (string) $fichier->getClientMimeType();
        $chemin = $fichier->getPathname();

        try {
            if (in_array($ext, self::EXT_TEXTE, true)) {
                $brut = @file_get_contents($chemin, false, null, 0, self::MAX_CARACTERES * 4);
                return $this->normaliser($brut === false ? null : $brut);
            }
            if ($ext === 'pdf' || $mime === 'application/pdf') {
                return $this->normaliser((new PdfParser())->parseFile($chemin)->getText());
            }
            if ($ext === 'docx' || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                return $this->normaliser($this->extraireDocx($chemin));
            }
            if ($ext === 'xlsx' || $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
                return $this->normaliser($this->extraireXlsx($chemin));
            }
        } catch (\Throwable) {
            // Illisible / corrompu / format inattendu : on n'extrait rien.
            return null;
        }

        return null;
    }

    /** Texte d'un .docx : le corps est du XML (word/document.xml) dans un zip. */
    private function extraireDocx(string $chemin): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($chemin) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            return null;
        }
        // Paragraphes / sauts / tabulations Word => séparateurs texte, puis on
        // retire les balises restantes (les nœuds <w:t> portent le texte visible).
        $xml = preg_replace('/<w:p\b[^>]*>/', "\n", $xml) ?? $xml;
        $xml = str_replace(['</w:p>', '<w:br/>', '<w:br />', '<w:tab/>', '<w:tab />'], ["\n", "\n", "\n", "\t", "\t"], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Texte d'un .xlsx : feuilles => lignes tabulées (données seules, borné). */
    private function extraireXlsx(string $chemin): ?string
    {
        $reader = SpreadsheetIOFactory::createReaderForFile($chemin);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        $classeur = $reader->load($chemin);

        $lignes = [];
        foreach ($classeur->getAllSheets() as $feuille) {
            $lignes[] = '# ' . $feuille->getTitle();
            foreach ($feuille->toArray(null, true, false, false) as $row) {
                $ligne = rtrim(implode("\t", array_map(static fn ($c) => (string) ($c ?? ''), $row)));
                if (trim($ligne) !== '') {
                    $lignes[] = $ligne;
                }
                if (count($lignes) > 3000) {
                    return implode("\n", $lignes); // garde-fou volumétrie
                }
            }
        }

        return implode("\n", $lignes);
    }

    /** Nettoie et tronque le texte extrait (contrôle du bruit et du coût). */
    private function normaliser(?string $texte): ?string
    {
        if ($texte === null) {
            return null;
        }
        // Retire les caractères de contrôle (hors tab/CR/LF) et compacte les espaces.
        $texte = preg_replace('/[^\P{C}\t\r\n]+/u', '', $texte) ?? $texte;
        $texte = preg_replace("/[ \t]+/", ' ', $texte) ?? $texte;
        $texte = preg_replace("/\n{3,}/", "\n\n", $texte) ?? $texte;
        $texte = trim($texte);

        if ($texte === '') {
            return null;
        }
        if (mb_strlen($texte) > self::MAX_CARACTERES) {
            $texte = mb_substr($texte, 0, self::MAX_CARACTERES) . "\n[…texte tronqué…]";
        }

        return $texte;
    }
}
