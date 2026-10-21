<?php

namespace App\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * LES ATTRIBUTS D'UN CHAMP DE RECHERCHE OU DE FILTRE — écrits ici, et nulle part ailleurs.
 *
 * Chantier 16 (2026-10-20) : Chrome et Edge prenaient les champs de recherche anonymes pour un
 * champ d'identifiant et y collaient l'adresse e-mail de l'utilisateur ; la liste se filtrait
 * alors sur ce texte, à son insu (8 blocs du tableau de bord, 29 champs en tout). Un champ de
 * recherche porte donc :
 *
 * - `autocomplete="off"` : Chrome l'ignore souvent, mais il coupe aussi la restauration de
 *   formulaire de Firefox au F5 ;
 * - les consignes des gestionnaires de mots de passe, qui n'écoutent pas `autocomplete` :
 *   LastPass (`data-lpignore`), 1Password (`data-1p-ignore`), Bitwarden (`data-bwignore`),
 *   Dashlane et autres (`data-form-type="other"`) ;
 * - `data-champ-recherche` : la marque que lit le filet (assets/champs-de-recherche.js), qui
 *   efface un remplissage du NAVIGATEUR (`:autofill`) — jamais une valeur posée par
 *   l'application.
 *
 * `attributs_champ_recherche('jsb-filtre')` — avec un nom — sert au champ qui n'appartient à
 * AUCUN formulaire envoyé : il reçoit le nom neutre et `form="jsb-hors-formulaire"` (un
 * formulaire qui n'existe pas), pour qu'aucun `new FormData(form)` ne l'emporte — un champ en
 * trop ferait refuser l'enregistrement d'un dialogue. Sans nom : les protections seules — pour
 * un VRAI champ d'un formulaire de recherche envoyé (console, `<form method="get">`), qui garde
 * son nom, ou pour un champ lu en direct dont le retrait du <form> changerait l'envoi implicite
 * par Entrée (recherche avancée). `ChampsDeRechercheProtegesTest` refuse qu'un champ d'un
 * formulaire GET soit détaché : il ne serait plus envoyé.
 *
 * Jumelle JavaScript : assets/controllers/champ-recherche.js (`ChampsDeRechercheProtegesTest` les lie).
 */
final class ChampRechercheExtension extends AbstractExtension
{
    public const PROTECTIONS = 'autocomplete="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore data-bwignore data-form-type="other" data-champ-recherche';
    public const FORMULAIRE_INEXISTANT = 'jsb-hors-formulaire';

    public function getFunctions(): array
    {
        return [new TwigFunction('attributs_champ_recherche', self::attributs(...), ['is_safe' => ['html']])];
    }

    public static function attributs(?string $nomHorsFormulaire = null): string
    {
        if (null === $nomHorsFormulaire) {
            return self::PROTECTIONS;
        }

        return sprintf('name="%s" form="%s" %s', htmlspecialchars($nomHorsFormulaire, \ENT_QUOTES), self::FORMULAIRE_INEXISTANT, self::PROTECTIONS);
    }
}
