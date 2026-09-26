<?php

namespace App\Ai\Tool;

/**
 * LES ANCIENS NOMS D'OUTILS, ACCEPTÉS LE TEMPS DE LA TRANSITION.
 *
 * ── POURQUOI UN ALIAS ET PAS LE RATTRAPAGE ──────────────────────────────────────
 *
 * {@see RattrapageDeNom} est un FILET : il corrige une faute de frappe, à deux
 * caractères près, et seulement quand un candidat est seul à cette distance. Un
 * renommage n'est pas une faute de frappe — `analyse_portefeuille` devenu
 * `analyser_portefeuille` passerait par chance (distance 1), mais `compter_entites`
 * fusionné dans `rechercher_entites` est à distance 8 : le filet ne le rattraperait
 * jamais.
 *
 * Surtout, faire reposer une transition sur une ressemblance orthographique reviendrait
 * à espérer que le hasard des lettres couvre une décision d'architecture. Un alias est
 * une correspondance DÉCLARÉE : on sait ce qu'elle couvre, on sait quand la retirer.
 *
 * ── CE QUE CETTE CLASSE NE FAIT PAS ─────────────────────────────────────────────
 *
 * Elle ne tient PAS la frontière lecture/écriture, et ne la franchit pas non plus :
 * un alias ne fait que renommer, l'outil visé est ensuite cherché parmi ceux réellement
 * déclarés. Elle ne ressuscite pas davantage un outil coupé en console — le contrôle de
 * coupure porte sur le nom demandé ET le catalogue n'énumère plus l'outil coupé.
 * {@see AliasDOutilTest} éprouve les deux propriétés.
 *
 * ── QUAND LES RETIRER ───────────────────────────────────────────────────────────
 *
 * Pas sur un seuil automatique. Le trafic réel est de quelques messages par jour : une
 * fenêtre de trente jours sans occurrence ne prouverait rien. La décision se prend à une
 * REVUE, à trois mois, en lisant la section 8 d'`app:assistant:tokens:rapport`, qui
 * imprime la date de dernière occurrence de chaque alias. Un alias que plus personne
 * n'écrit depuis des mois se retire ; les autres restent.
 */
final class AliasDOutils
{
    /**
     * Ancien nom => nom actuel.
     *
     * CHAQUE LIGNE PORTE SA RAISON. Un alias sans motif écrit est un alias qu'on
     * n'osera jamais retirer, faute de savoir ce qu'il couvrait.
     *
     * @var array<string, string>
     */
    public const ALIAS = [
        // Renommé le 2026-09-26. Le modèle écrivait DÉJÀ « analyser_portefeuille » :
        // quatre appels réels sur ce nom, aucun sur l'ancien. On a aligné le nom sur
        // ce que le modèle produit, plutôt que de corriger le modèle à chaque tour.
        'analyser_portefeuille' => 'analyse_portefeuille',

        // Fusionnés le 2026-09-26 dans rechercher_entites(mode: compte). Les deux
        // outils avaient les mêmes droits, le même périmètre et des schémas quasi
        // identiques ; leur coexistence a produit les deux seuls noms inventés que le
        // rattrapage ne sauvait pas — « lister_entites » (3 appels) et
        // « lecture_donnees » (1). Les trois noms pointent désormais au même endroit.
        'compter_entites' => 'rechercher_entites',
        'lister_entites' => 'rechercher_entites',

        // Même fusion, autre écorchure : « lecture_donnees » (1 appel) ne ressemblait à
        // rien du catalogue — distance 9 du plus proche — et demandait pourtant
        // exactement cela : lire des données. Le filet ne pouvait rien pour lui ; un
        // alias, si.
        'lecture_donnees' => 'rechercher_entites',
    ];

    /**
     * Le nom actuel d'un ancien nom, ou null si ce n'en est pas un.
     *
     * ⚠ UN NOM QUI EXISTE N'EST JAMAIS UN ALIAS. Même garde que dans RattrapageDeNom,
     * et pour la même raison : si un outil porte ce nom aujourd'hui, c'est lui qu'il
     * faut exécuter, quoi qu'ait pu désigner ce mot par le passé.
     *
     * @param list<string> $existants les noms d'outils du catalogue
     */
    public static function resoudre(string $demande, array $existants): ?string
    {
        if (\in_array($demande, $existants, true)) {
            return null;
        }

        return self::ALIAS[$demande] ?? null;
    }
}
