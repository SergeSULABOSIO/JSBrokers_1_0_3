<?php

namespace App\Echange\Reprise;

use App\Ai\Mutation\MutationReferences;
use App\Echange\Service\ResolveurDeRenvois;

/**
 * LE REPÈRE STABLE D'UNE ENTITÉ, DÉDUIT DE CE QUE LA LIGNE EN DIT.
 *
 * ── LE PROBLÈME QUE CETTE CLASSE RÉSOUT ────────────────────────────────────────────
 * Une ligne du classeur décrit UNE TRANCHE, mais porte toute sa chaîne : le client, le
 * risque, l'assureur, la police, la cotation. Une police à quatre échéances occupe donc
 * quatre lignes qui répètent les mêmes quatre premiers niveaux.
 *
 * ⚠ SANS REPÈRE STABLE, CES QUATRE LIGNES CRÉERAIENT QUATRE POLICES. Et quatre clients,
 * et quatre cotations. C'est la faute la plus probable de toute la reprise, et la plus
 * coûteuse : elle ne casse rien, elle DUPLIQUE — le portefeuille double de volume, les
 * primes se comptent plusieurs fois, et l'on ne s'en aperçoit qu'à la lecture des totaux.
 *
 * Le repère est donc dérivé du CONTENU de la ligne, et non de son rang : deux lignes qui
 * parlent de la même police produisent le même repère, quel que soit l'ordre du fichier
 * — que l'utilisateur a parfaitement le droit de changer.
 *
 * ── UNE CLÉ NE SE DEVINE JAMAIS ────────────────────────────────────────────────────
 * ⚠ POUR LA CHAÎNE PISTE / COTATION / POLICE, LA CLÉ EST LA RÉFÉRENCE DE POLICE. Rien
 * d'autre ne convient : deux polices du même client, chez le même assureur, sur le même
 * risque et démarrant le même jour sont parfaitement possibles. Les départager sur ces
 * quatre valeurs, c'est fusionner deux affaires distinctes — et l'inverse, les séparer
 * quand elles n'en font qu'une, c'est dupliquer.
 *
 * ⚠ ET LE RISQUE LA COMPLÈTE, sans la remplacer : une référence peut couvrir plusieurs
 * risques, dont chacun est une affaire à part ({@see pourChaine()}).
 *
 * Une ligne sans référence de police n'a donc pas de clé, et {@see cleDeLaPolice()} rend
 * `null` : le reconstitueur en fait un refus nommé. Un projet non encore lié n'a pas de
 * référence, et c'est bien pour cela qu'on ne peut pas l'inventer.
 *
 * ── LA NORMALISATION EST EMPRUNTÉE, JAMAIS RÉÉCRITE ────────────────────────────────
 * ⚠ Elle vient de {@see ResolveurDeRenvois::normaliser()}, qui indexe les libellés
 * métier pour retrouver une entité EXISTANTE. Les deux doivent découper identiquement :
 * si le repère différait de l'index d'un espace ou d'un accent, « SFA Congo » serait
 * résolu en base pour une ligne et recréé pour la suivante. Deux assureurs pour un, sans
 * que rien ne le signale.
 */
final class CleNaturelle
{
    /**
     * Les préfixes de repère, un par niveau.
     *
     * Ils évitent qu'un client et un assureur homonymes — « AXA », le courtier et la
     * compagnie — partagent un repère et se confondent en une seule création.
     */
    public const CLIENT = 'cli';
    public const RISQUE = 'ris';
    public const ASSUREUR = 'ass';
    public const PORTEFEUILLE = 'pf';
    public const PARTENAIRE = 'part';
    public const PISTE = 'pis';
    public const COTATION = 'cot';
    public const AVENANT = 'ave';
    public const CONDITION = 'cond';

    /** Le numéro d'une police que rien ne numérote : le contrat d'origine ({@see numeroOuDefaut()}). */
    public const NUMERO_DEFAUT = '0';

    /**
     * LE REPÈRE D'UN NIVEAU NOMMÉ PAR SON LIBELLÉ — client, risque, assureur…
     *
     * Rend `null` si le libellé est vide : il n'y a alors rien à créer, et fabriquer un
     * repère vide ferait converger vers une même entité anonyme tout ce qui manque.
     */
    public static function pourLibelle(string $prefixe, ?string $libelle): ?string
    {
        $forme = ResolveurDeRenvois::normaliser((string) $libelle);

        return $forme === '' ? null : self::repere($prefixe, $forme);
    }

    /**
     * LA CLÉ DE LA CHAÎNE PISTE / COTATION / POLICE : la référence de police.
     *
     * ⚠ ELLE EST OBLIGATOIRE, ET C'EST UN CHOIX. Voir l'en-tête de cette classe : aucune
     * combinaison de client, risque, assureur et dates ne départage deux polices avec
     * certitude. Sans référence, on ne sait pas si deux lignes parlent d'une affaire ou de
     * deux — et les deux erreurs possibles sont graves.
     */
    public static function cleDeLaPolice(?string $referencePolice): ?string
    {
        $forme = ResolveurDeRenvois::normaliser((string) $referencePolice);

        return $forme === '' ? null : $forme;
    }

    /**
     * Le repère de la POLICE elle-même — référence, numéro d'avenant et risque.
     *
     * ⚠ LE NUMÉRO D'AVENANT FAIT PARTIE DE LA CLÉ. Une police et son avenant n° 2
     * partagent la référence : les confondre écraserait l'un par l'autre, et la police
     * porterait les dates de son avenant.
     *
     * ⚠ LE RISQUE AUSSI : voir {@see pourChaine()}.
     */
    public static function pourAvenant(?string $referencePolice, ?string $numeroAvenant, ?string $risque = null): ?string
    {
        $cle = self::cleDeLaPolice($referencePolice);
        if ($cle === null) {
            return null;
        }

        return self::repere(
            self::AVENANT,
            self::avecRisque($cle . ' ' . self::numeroOuDefaut($numeroAvenant), $risque),
        );
    }

    /**
     * LE NUMÉRO D'AVENANT D'UNE LIGNE — « 0 » quand elle n'en donne pas.
     *
     * ⚠ ZÉRO EST LE CONTRAT D'ORIGINE, pas une absence. Une police sans avenant EST
     * l'avenant zéro : c'est le langage du métier, et le classeur laisse la colonne vide
     * dans l'immense majorité des lignes.
     *
     * ⚠ ET LA CLÉ DOIT LIRE PAREIL DES DEUX CÔTÉS. Écrire « 0 » en base tout en cherchant
     * sous une clé vide ferait manquer la police au dépôt suivant : la reprise la recréerait
     * à chaque fois, sans que rien ne le signale. D'où cette source unique, partagée avec
     * {@see ChaineExistante::cle()}.
     */
    public static function numeroOuDefaut(?string $numeroAvenant): string
    {
        $numero = ResolveurDeRenvois::normaliser((string) $numeroAvenant);

        return $numero === '' ? self::NUMERO_DEFAUT : $numero;
    }

    /**
     * Le repère de la piste ou de la cotation : la clé de police ET le risque.
     *
     * ⚠ UNE MÊME RÉFÉRENCE PEUT COUVRIR PLUSIEURS RISQUES. Un contrat « incendie et pertes
     * d'exploitation » porte un seul numéro chez l'assureur, mais le cabinet le suit en deux
     * affaires : chaque risque a sa prime, son taux de commission, sa part d'intermédiaire.
     * Sans le risque dans la clé, la seconde ligne convergeait sur la première — sa prime et
     * sa commission n'étaient jamais écrites, et le contrôle des parts lui reprochait un
     * échéancier à 200 % qui n'existait pas.
     *
     * Deux lignes du MÊME risque restent deux échéances d'une même affaire : c'est la règle
     * d'avant, intacte.
     */
    public static function pourChaine(string $prefixe, ?string $referencePolice, ?string $risque = null): ?string
    {
        $cle = self::cleDeLaPolice($referencePolice);

        return $cle === null ? null : self::repere($prefixe, self::avecRisque($cle, $risque));
    }

    /** La forme d'une clé complétée du risque, s'il est nommé. */
    private static function avecRisque(string $forme, ?string $risque): string
    {
        $risque = ResolveurDeRenvois::normaliser((string) $risque);

        return $risque === '' ? $forme : $forme . ' ' . $risque;
    }

    /**
     * L'ÉTIQUETTE À ÉCRIRE DANS UN CHAMP pour désigner une ligne créée ailleurs.
     *
     * ⚠ C'EST LE MÊME « @étiquette » QUE LE CIRCUIT D'ÉCRITURE RÉSOUT DÉJÀ, au dry-run
     * comme à l'exécution. On ne fabrique aucune mécanique de liaison : on emploie celle
     * de l'espace de travail, celle que l'assistant emploie aussi.
     */
    public static function renvoiVers(string $repere): string
    {
        return MutationReferences::PREFIXE . $repere;
    }

    /**
     * ⚠ UN REPÈRE NE PORTE QUE DES CARACTÈRES SÛRS, et sa longueur est bornée.
     *
     * Il traverse le circuit d'écriture comme une étiquette de texte ; une référence de
     * police avec un slash ou un espace de trop n'y a rien à faire. Le remplacement par
     * `-` est appliqué APRÈS la normalisation partagée, qui a déjà réduit accents et
     * ponctuation à des espaces : on ne redéfinit donc rien, on met en forme.
     */
    private static function repere(string $prefixe, string $forme): string
    {
        $forme = (string) preg_replace('/\s+/', '-', trim($forme));

        // Bornée : une référence anormalement longue ne doit pas produire une étiquette
        // que rien ne pourra rapprocher de son origine. Le hachage garde l'unicité.
        if (mb_strlen($forme) > 48) {
            $forme = mb_substr($forme, 0, 40) . '-' . substr(md5($forme), 0, 7);
        }

        return $prefixe . '-' . $forme;
    }
}
