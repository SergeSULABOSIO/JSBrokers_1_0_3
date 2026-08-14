<?php

namespace App\Ai\Fichier;

use App\Entity\AssistantConversationFichier;

/**
 * UN CONTRAT JOINT PROUVE QUE LA POLICE EXISTE.
 *
 * L'INCIDENT (2026-08-14). Le courtier attache « CONTRACT-MBUSA-KAYITH….pdf » et
 * demande d'enregistrer le dossier. Ket prépare un plan complet — proposition,
 * composition de la prime, échéancier, commission — puis annonce : « L'opération
 * “Propositions” ne couvre pas les documents, les tâches et les avenants.
 * Disposez-vous de ces informations maintenant ? » Elle demandait s'il existait un
 * contrat en ayant le contrat sous les yeux.
 *
 * La trame « proposition » range en effet l'étape « Le contrat (avenant) » parmi les
 * OPTIONNELLES, aux côtés des tâches de suivi. C'est le bon défaut quand on saisit une
 * proposition en cours de négociation : tant que le client n'a pas dit oui, il n'y a
 * pas de police. Mais ce n'est plus le bon défaut quand la pièce versée au dossier EST
 * le contrat : l'avenant n'est alors plus une hypothèse à confirmer, c'est un fait
 * établi que le plan doit écrire. Sans lui la cotation reste un PROJET — ses primes et
 * ses commissions ne comptent nulle part (règle isBound) —, et le courtier a saisi un
 * dossier qui ne produira aucun revenu.
 *
 * CE QUE CETTE CLASSE NE FAIT PAS. Elle ne lit pas le contrat et n'en tire aucune
 * valeur : ni référence de police, ni dates, ni prime. Elle répond à UNE question —
 * « le dossier porte-t-il la preuve d'une police ? » — et le reste est demandé ou
 * déduit comme d'habitude. Un fichier joint quelconque (un mail, un relevé, une pièce
 * d'identité) ne promeut rien : on ne transforme pas une négociation en contrat parce
 * qu'un PDF traîne dans le fil.
 */
final class PreuveDeContrat
{
    /**
     * Marqueurs FORTS : chacun suffit, parce qu'aucun n'apparaît dans un document qui
     * ne serait pas une police. « Conditions particulières » et « attestation
     * d'assurance » sont des pièces contractuelles ; « avenant n° » et « police n° »
     * ne se rencontrent que sur un contrat émis.
     *
     * Ce qui n'y est PAS, et pourquoi : « assurance », « prime », « assureur »,
     * « souscripteur » figurent tout autant sur une simple COTATION. Les retenir
     * ferait promouvoir l'étape sur la proposition qu'on est justement en train de
     * négocier — l'erreur exactement inverse de celle qu'on corrige.
     */
    private const MARQUEURS_TEXTE = [
        'conditions particulieres',
        'attestation d assurance',
        'certificat d assurance',
        'police n',
        'police no',
        'numero de police',
        'reference de police',
        'avenant n',
        'contrat d assurance',
    ];

    /** Le nom du fichier, quand le texte n'a pas pu être extrait (PDF scanné). */
    private const MARQUEURS_NOM = ['contract', 'contrat', 'police', 'avenant', 'attestation'];

    /**
     * Le fil porte-t-il la preuve qu'une police existe ?
     *
     * @param iterable<AssistantConversationFichier> $fichiers
     */
    public static function presenteDans(iterable $fichiers): bool
    {
        foreach ($fichiers as $fichier) {
            if (self::leProuve($fichier)) {
                return true;
            }
        }

        return false;
    }

    /** Le premier fichier qui porte la preuve, pour pouvoir la CITER. */
    public static function piece(iterable $fichiers): ?AssistantConversationFichier
    {
        foreach ($fichiers as $fichier) {
            if (self::leProuve($fichier)) {
                return $fichier;
            }
        }

        return null;
    }

    private static function leProuve(AssistantConversationFichier $fichier): bool
    {
        $texte = self::normaliser((string) $fichier->getTexteExtrait());
        foreach (self::MARQUEURS_TEXTE as $marqueur) {
            if ($texte !== '' && str_contains($texte, $marqueur)) {
                return true;
            }
        }

        // REPLI SUR LE NOM. Un contrat signé est très souvent un PDF SCANNÉ, dont
        // l'extraction de texte ne rend rien — c'est-à-dire précisément le cas le plus
        // courant en production. Ignorer le nom laisserait la panne entière sur ces
        // dossiers-là. Le nom est un indice plus faible, mais il est DÉLIBÉRÉ : c'est
        // le courtier qui a appelé son fichier « CONTRACT-… ».
        $nom = self::normaliser((string) $fichier->getNomOriginal());
        foreach (self::MARQUEURS_NOM as $marqueur) {
            if ($nom !== '' && str_contains($nom, $marqueur)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minuscules, sans accents ni ponctuation : « Police N° » == « police n ».
     *
     * GOTCHA — PAS D'iconv //TRANSLIT ICI. Sous Windows il ne translittère pas, il
     * DÉCOMPOSE : « particulières » devient « particuli`eres », et le filtre de
     * ponctuation en fait « particuli eres ». Une comparaison entre deux chaînes
     * passées par la même moulinette n'y voit rien (les artefacts s'annulent), mais
     * ici on confronte à des marqueurs ASCII écrits à la main : la preuve était
     * manquée sur tout document accentué, c'est-à-dire sur tous. Table explicite,
     * donc, dont le résultat ne dépend ni de la plateforme ni de la locale.
     */
    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o', 'ò' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
    ];

    private static function normaliser(string $valeur): string
    {
        $valeur = strtr(mb_strtolower(trim($valeur)), self::ACCENTS);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $valeur));
    }
}
