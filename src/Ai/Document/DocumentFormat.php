<?php

namespace App\Ai\Document;

use App\Token\TokenPricing;

/**
 * SOURCE UNIQUE des formats de sortie d'un document produit par Ket.
 *
 * Un format n'est pas qu'une extension : il porte son libellé humain (ce que
 * l'utilisateur lit sur le bouton), sa pastille courte, son type MIME et — via le
 * plan tarifaire — son multiplicateur. Les quatre vivaient dispersés dans les
 * premières esquisses ; les réunir ici évite qu'un `.xlsx` soit servi avec le MIME
 * d'un `.docx` parce que deux tableaux ont divergé.
 *
 * L'ordre des cas est celui du catalogue tarifaire : du plus brut au plus paginé.
 */
enum DocumentFormat: string
{
    case Txt  = 'txt';
    case Md   = 'md';
    case Html = 'html';
    case Xlsx = 'xlsx';
    case Docx = 'docx';
    case Pdf  = 'pdf';

    /** Libellé humain — celui que Ket emploie et que le bouton affiche. */
    public function libelle(): string
    {
        return match ($this) {
            self::Txt  => 'Texte',
            self::Md   => 'Markdown',
            self::Html => 'Page web',
            self::Xlsx => 'Excel',
            self::Docx => 'Word',
            self::Pdf  => 'PDF',
        };
    }

    /** Pastille courte du bouton de téléchargement (DOCX, XLSX…). */
    public function badge(): string
    {
        return mb_strtoupper($this->value);
    }

    public function extension(): string
    {
        return $this->value;
    }

    public function mime(): string
    {
        return match ($this) {
            self::Txt  => 'text/plain; charset=UTF-8',
            self::Md   => 'text/markdown; charset=UTF-8',
            self::Html => 'text/html; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            self::Pdf  => 'application/pdf',
        };
    }

    /**
     * Ce format sait-il porter un THÈME (clair / sombre) ?
     *
     * Seuls ceux qui PEIGNENT leur propre fond : la page web et le PDF, qui
     * partagent le même gabarit. Un .docx n'a pas de fond de page en OOXML tel que
     * PHPWord l'écrit — une encre claire y donnerait un document blanc illisible —
     * une feuille Excel ne teinte que ses cellules remplies, et .txt / .md n'ont
     * aucune couleur. Le sélecteur de thème de la barre de décision se règle sur
     * cette réponse : il disparaît quand l'option ne serait pas tenue.
     */
    public function supporteTheme(): bool
    {
        return match ($this) {
            self::Html, self::Pdf => true,
            default               => false,
        };
    }

    /**
     * Le format retenu quand l'utilisateur n'en précise aucun. Décision produit :
     * Word — c'est le format qu'un courtier retouche puis envoie à son client.
     */
    public static function defaut(): self
    {
        return self::from(TokenPricing::DOCUMENT_FORMAT_DEFAUT);
    }

    /**
     * Lecture TOLÉRANTE : casse, espaces et alias courants sont acceptés, et tout
     * ce qui reste inconnu retombe sur le défaut.
     *
     * Le repli est volontaire plutôt qu'une exception : la valeur vient du modèle,
     * et refuser une production parce qu'il a écrit « Word » au lieu de « docx »
     * serait punir l'utilisateur pour une broutille de vocabulaire.
     */
    public static function depuis(mixed $valeur): self
    {
        if ($valeur instanceof self) {
            return $valeur;
        }
        if (!is_string($valeur)) {
            return self::defaut();
        }

        $normalise = ltrim(mb_strtolower(trim($valeur)), '.');

        return self::tryFrom($normalise)
            ?? match ($normalise) {
                'word', 'doc', 'msword'      => self::Docx,
                'excel', 'xls', 'tableur'    => self::Xlsx,
                'markdown'                   => self::Md,
                'texte', 'text', 'plain'     => self::Txt,
                'htm', 'web', 'page'         => self::Html,
                default                      => self::defaut(),
            };
    }

    /** @return list<string> valeurs servies — l'enum du JSON-Schema de l'outil */
    public static function valeurs(): array
    {
        return array_map(static fn (self $f) => $f->value, self::cases());
    }
}
