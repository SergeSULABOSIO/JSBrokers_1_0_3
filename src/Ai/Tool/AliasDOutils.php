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
        // Renommé le 2026-09-26, dans le sens que le TERRAIN dictait : le modèle écrivait
        // déjà « analyser_portefeuille » — quatre appels réels sur ce nom, aucun sur
        // l'ancien. On a aligné l'outil sur ce que le modèle produit spontanément, plutôt
        // que de corriger le modèle à chaque tour. L'alias couvre donc l'ANCIEN nom, celui
        // que plus personne n'écrivait déjà.
        'analyse_portefeuille' => 'analyser_portefeuille',

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

        // Renommés le 2026-09-26. « echange_exporter » et « echange_importer » étaient
        // à distance 2 l'un de l'autre ET de part et d'autre de la frontière : en
        // trousse d'écriture, où les deux sont déclarés, RattrapageDeNom refusait de
        // trancher entre eux, et TOUTE écorchure de cette famille restait introuvable.
        // ⚠ CHANGER LE VERBE NE SUFFISAIT PAS. Premier essai : « exporter_donnees » et
        // « importer_donnees » — toujours à distance 2, le préfixe seul ayant bougé.
        // Ce sont les OBJETS qui portent la distance : on exporte un PORTEFEUILLE, on
        // importe un CLASSEUR. Distance 12, et chaque nom dit en plus ce qu'il manipule.
        // « echange_consulter » suit ses deux frères, la famille gardant un vocabulaire
        // d'un seul tenant.
        'echange_exporter' => 'exporter_portefeuille',
        'echange_importer' => 'importer_classeur',
        'echange_consulter' => 'consulter_echanges',
    ];

    /**
     * LES ANCIENS NOMS QUI ÉTAIENT DÉJÀ DES OUTILS D'ÉCRITURE.
     *
     * Un alias ne doit jamais faire passer de la lecture à l'écriture : ce serait ouvrir,
     * par un mot d'hier, une capacité que le mot d'aujourd'hui réserve. Mais l'inverse
     * n'est pas vrai — un ancien nom d'écriture PEUT viser un outil d'écriture, c'est même
     * la seule chose qu'il puisse faire.
     *
     * La distinction ne se déduit plus du code : l'outil d'origine n'existe plus, rien ne
     * dit donc de quel côté il était. Elle se DÉCLARE ici, et {@see AliasDOutilTest} refuse
     * toute cible d'écriture qui ne figure pas dans cette liste. Ajouter un alias vers
     * l'écriture oblige ainsi à l'écrire noir sur blanc, plutôt qu'à le laisser passer.
     *
     * @var list<string>
     */
    public const ANCIENS_NOMS_D_ECRITURE = [
        // `echange_importer` importait un classeur : une écriture, hier comme aujourd'hui.
        'echange_importer',
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
