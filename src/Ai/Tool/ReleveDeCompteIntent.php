<?php

namespace App\Ai\Tool;

/**
 * Garde de ROUTAGE partagée du moteur simulé : « cette question porte-t-elle sur
 * le RELEVÉ DE COMPTE d'un client ? »
 *
 * ── LE MOT « COMPTE » VEUT DIRE DEUX CHOSES ────────────────────────────────
 * En français, « compte » est à la fois le substantif d'un relevé (« le compte
 * du client ») et une forme du verbe compter (« combien de clients compte-t-on
 * ? »). `CompterEntitesTool` déclenche sur `compte[sz]?` ; « où en est le compte
 * du client Kin Avia ? » partait donc sur un COMPTAGE de clients — constaté en
 * diagnostic (`app:assistant:smoke`), et le comptage l'emportait parce que les
 * outils sont essayés par ordre alphabétique.
 *
 * Le vocabulaire du relevé est donc écrit ICI, une fois, et lu par les deux
 * outils : `LireSoaTool` s'en sert pour se reconnaître, `CompterEntitesTool`
 * pour s'écarter. Même dispositif que {@see PaiementPrimeIntent}, né du même
 * genre de collision.
 *
 * Ne concerne QUE le chemin simulé : un LLM réel arbitre sur les descriptions
 * et l'aiguillage des outils.
 */
final class ReleveDeCompteIntent
{
    /** @param string $texteNormalise Question passée par AiText::normalize(). */
    public static function concerne(string $texteNormalise): bool
    {
        return (bool) preg_match(
            '/\b(soa|releve de compte|compte (du |de la |de l |des )?client|situation de compte'
            . '|compte du|solde du client)\b/',
            $texteNormalise,
        );
    }

    /**
     * La demande porte-t-elle sur l'ENVOI de la pièce plutôt que sur sa lecture ?
     *
     * Les deux outils du relevé partagent tout leur vocabulaire ; seul le verbe
     * les sépare. Sans cette distinction, « envoie le relevé de compte de X »
     * aurait été servi par une lecture, et l'utilisateur aurait attendu un
     * courriel qui ne serait jamais parti.
     */
    public static function estUnEnvoi(string $texteNormalise): bool
    {
        return (bool) preg_match(
            '/\b(envoie[rsz]?|envoyer|transmet[st]?[a-z]*|expedie[rsz]?|adresse[rsz]?)\b/',
            $texteNormalise,
        );
    }
}
