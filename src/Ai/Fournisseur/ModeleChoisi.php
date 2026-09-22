<?php

namespace App\Ai\Fournisseur;

/**
 * QUEL MODÈLE APPELER : celui de la console s'il y en a un, celui du serveur sinon.
 *
 * LA RÈGLE N'EST ÉCRITE QU'ICI. Dix fournisseurs se la posent — les deux moteurs de
 * texte, les deux comprenants, les deux finisseurs de dictée, les deux voix, les
 * deux oreilles. Dix copies d'une même règle, c'est dix occasions de la voir
 * diverger : c'est déjà l'argument qui a fait extraire `OrdreDesFournisseurs` pour
 * l'ordre, et `ServiceTaxes` pour l'exonération.
 *
 * ── LE POINT QUI COMPTE : C'EST RELU À CHAQUE APPEL ──────────────────────────
 *
 * Avant, chaque fournisseur lisait son modèle dans le `.env` AU DÉMARRAGE, par un
 * `#[Autowire(env: …)]` de constructeur. Un agent pouvait donc changer le modèle
 * depuis la console, l'enregistrer, le voir affiché — et Ket continuait d'appeler
 * l'ancien jusqu'au redémarrage du serveur. La console mentait.
 *
 * Cette classe est appelée depuis les MÉTHODES, jamais depuis un constructeur :
 * le prochain message part avec le nouveau modèle, sans redémarrage et sans vider
 * le moindre cache.
 *
 * ── ET SURTOUT : SANS CASSER QUOI QUE CE SOIT ────────────────────────────────
 *
 * Un champ libre dans une console est un champ où l'on se trompe. Une faute de
 * frappe — `claude-haiku-4.5` au lieu de `claude-haiku-4-5` — vaut un 404 du
 * fournisseur À CHAQUE MESSAGE, et Ket ne répondrait plus. La valeur est donc
 * VÉRIFIÉE avant d'être utilisée : ce qui ne ressemble pas à un nom de modèle est
 * ignoré, et le défaut du serveur reprend la main. La console, elle, refuse la
 * saisie à l'enregistrement pour que l'agent l'apprenne tout de suite plutôt que
 * de chercher ensuite pourquoi son réglage « ne prend pas ».
 *
 * On ne vérifie QUE la forme, jamais l'existence : le catalogue des modèles change
 * sans nous, et une liste blanche figée dans le code interdirait demain un modèle
 * sorti ce matin. C'est le rôle de la chaîne de fournisseurs d'absorber un modèle
 * refusé, pas celui d'un garde-fou de le prédire.
 */
final class ModeleChoisi
{
    /**
     * Les noms de modèles de tous les fournisseurs que nous appelons tiennent dans
     * cet alphabet : `claude-haiku-4-5`, `gemini-3.1-flash-lite`,
     * `eleven_multilingual_v2`, `scribe_v2`, `gpt-4o-mini`… On accepte donc lettres,
     * chiffres, tiret, souligné, point et deux-points — et rien d'autre : ni espace,
     * ni barre oblique, ni guillemet, qui ne feraient que voyager jusqu'à une URL.
     */
    private const FORME = '/^[A-Za-z0-9._:-]{1,100}$/';

    /**
     * Le modèle à appeler pour ce fournisseur, maintenant.
     *
     * @param string $defaut la valeur du `.env`, qui reste la couche de défauts
     */
    public static function pour(
        ?PolitiqueDesFournisseurs $politique,
        string $famille,
        string $fournisseur,
        string $defaut,
        string $clef = 'modele',
    ): string {
        $choisi = $politique?->reglage($famille, $fournisseur, $clef);

        return \is_string($choisi) && self::estPlausible($choisi) ? trim($choisi) : $defaut;
    }

    /**
     * Même chose pour un réglage qui porte PLUSIEURS modèles séparés par des
     * virgules — la chaîne de repli de Gemini, ses modèles de synthèse vocale.
     * Chaque nom doit tenir debout séparément, sinon la liste entière est écartée :
     * une liste à moitié valide appellerait un modèle inexistant une fois sur deux,
     * ce qui est plus difficile à diagnostiquer qu'un réglage simplement ignoré.
     */
    public static function liste(
        ?PolitiqueDesFournisseurs $politique,
        string $famille,
        string $fournisseur,
        string $defaut,
        string $clef = 'modele',
    ): string {
        $choisi = $politique?->reglage($famille, $fournisseur, $clef);
        if (!\is_string($choisi)) {
            return $defaut;
        }

        $noms = array_values(array_filter(array_map('trim', explode(',', $choisi)), static fn (string $n): bool => $n !== ''));
        if ($noms === []) {
            return $defaut;
        }

        foreach ($noms as $nom) {
            if (!self::estPlausible($nom)) {
                return $defaut;
            }
        }

        return implode(',', $noms);
    }

    /**
     * Ce texte peut-il être un nom de modèle ? Utilisé aux DEUX bouts : ici pour
     * ignorer une valeur douteuse au moment d'appeler, et dans le contrôleur de
     * console pour refuser la saisie au moment d'enregistrer.
     */
    public static function estPlausible(string $modele): bool
    {
        return preg_match(self::FORME, trim($modele)) === 1;
    }
}
