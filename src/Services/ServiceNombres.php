<?php

namespace App\Services;

use Symfony\Component\Translation\LocaleSwitcher;

/**
 * @file Formatage des nombres selon la LANGUE ACTIVE.
 * @description Source de vérité unique du projet pour écrire un nombre à
 * l'écran : anglais → « 8,800 » (virgule pour les milliers, point décimal),
 * toute autre langue (français) → « 8 800 » (espace pour les milliers, virgule
 * décimale), ce qui est la convention déjà appliquée aux montants de
 * l'application. La POSITION du symbole monétaire suit la même règle de langue
 * (« 10,00 $ » / « $10.00 »). Le miroir côté navigateur vit dans
 * assets/number-format.js et doit rester aligné sur ces règles.
 */
class ServiceNombres
{
    public function __construct(private LocaleSwitcher $localeSwitcher)
    {
    }

    /**
     * Formate un nombre pour l'affichage.
     *
     * @param int|float|string|null $valeur
     * @param int                   $decimales Nombre de décimales (0 pour un compteur).
     * @param string|null           $locale    Langue forcée (e-mails, vitrine) ; sinon celle de la requête.
     */
    public function format(int|float|string|null $valeur, int $decimales = 0, ?string $locale = null): string
    {
        if (!is_numeric($valeur)) {
            return '';
        }

        return self::anglais($locale ?? $this->localeCourante())
            ? number_format((float) $valeur, $decimales, '.', ',')
            : number_format((float) $valeur, $decimales, ',', ' ');
    }

    /**
     * Formate un MONTANT : le symbole se place selon la langue — « 10,00 $ » en
     * français (suffixe), « $10.00 » en anglais (préfixe collé).
     *
     * @param int|float|string|null $valeur
     * @param int                   $decimales
     * @param string|null           $locale   Langue forcée ; sinon la langue active.
     * @param string                $symbole  Symbole monétaire (dollar par défaut).
     */
    public function formatMontant(
        int|float|string|null $valeur,
        int $decimales = 2,
        ?string $locale = null,
        string $symbole = '$',
    ): string {
        $nombre = $this->format($valeur, $decimales, $locale);
        if ($nombre === '') {
            return '';
        }

        return self::anglais($locale ?? $this->localeCourante())
            ? $symbole . $nombre
            : $nombre . ' ' . $symbole;
    }

    /** La langue demande-t-elle la notation anglaise (1,000.00) ? */
    public static function anglais(?string $locale): bool
    {
        return str_starts_with(strtolower((string) $locale), 'en');
    }

    /**
     * Langue ACTIVE de l'application. On interroge le LocaleSwitcher, et non la
     * requête : c'est lui que pilote UserLocaleListener (préférence de langue de
     * l'utilisateur) et donc lui qui gouverne déjà `|trans`. La requête, elle,
     * garde la locale par défaut même après une bascule de langue.
     */
    private function localeCourante(): string
    {
        return $this->localeSwitcher->getLocale();
    }
}
