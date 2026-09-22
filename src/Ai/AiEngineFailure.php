<?php

namespace App\Ai;

use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Message d'excuse de l'assistant quand le moteur IA réel échoue : honnête sur
 * la cause quand elle est identifiable (429 = quota/limite de débit de l'API du
 * fournisseur), générique sinon. Utilisé par le point de repli unique
 * (AssistantIaController), quel que soit le moteur (Claude, Gemini…).
 *
 * Le 429 des fournisseurs ne dit PAS seulement « trop de requêtes » : son corps
 * nomme le quota violé et le délai à attendre (google.rpc.QuotaFailure +
 * RetryInfo chez Gemini, en-tête retry-after chez Anthropic). On l'exploite pour
 * deux choses : annoncer à l'utilisateur un délai RÉEL au lieu d'un « patientez
 * une petite minute » deviné, et journaliser le quota exact — sans quoi la
 * saturation reste un mystère (cf. detailsPourJournal()).
 */
final class AiEngineFailure
{
    public static function messagePour(\Throwable $e): string
    {
        // AVANT le 429 ordinaire, dont il partage le code HTTP — et à qui il ne
        // ressemble en rien : ici, aucune attente ne rouvre la porte.
        if (self::estPlafondDeDepense($e)) {
            return "Mon moteur d'intelligence est suspendu : le plafond de dépense mensuel "
                . 'du compte est atteint. '
                . (self::dateDeReouverture($e) !== null
                    ? sprintf('Il rouvrira le %s. ', self::dateDeReouverture($e))
                    : '')
                . "Ce n'est pas une saturation passagère — réessayer n'y changera rien : "
                . "prévenez l'administrateur de la plateforme. Votre message a bien été conservé.";
        }

        if (self::estLimiteDeDebit($e)) {
            $secondes = self::secondesAvantNouvelEssai($e);

            return "Mon moteur d'intelligence est momentanément saturé : la limite de tokens "
                . 'par minute du fournisseur est atteinte. '
                . ($secondes !== null
                    ? sprintf('Réessayez dans %d secondes', $secondes)
                    : 'Patientez une petite minute puis renvoyez votre question')
                . ' — votre message a bien été conservé.';
        }

        if (self::estMoteurIndisponible($e)) {
            return "Le modèle d'intelligence sur lequel je tourne est surchargé chez le "
                . 'fournisseur, et mes modèles de secours le sont aussi. '
                . "Ce n'est pas votre demande : réessayez dans une minute — votre message "
                . 'a bien été conservé.';
        }

        return "Je rencontre un problème technique pour joindre mon moteur d'intelligence. "
            . 'Réessayez dans un instant — votre message a bien été conservé.';
    }

    /**
     * L'échec est-il une INDISPONIBILITÉ DU MODÈLE (HTTP 503) ?
     *
     * ⚠ À NE PAS CONFONDRE AVEC LE 429. Le 429 dit « vous avez trop consommé » : le
     * quota est à nous, attendre le libère, et réessayer sur le même modèle a du sens.
     * Le 503 dit « ce modèle est débordé » — c'est une saturation CHEZ GOOGLE, partagée
     * par tous ses clients. Attendre n'y change rien de prévisible, et le fournisseur
     * n'annonce d'ailleurs aucun délai. La seule réponse utile est de changer de modèle.
     *
     * C'est la panne du 2026-09-04, où Ket a passé une journée à répondre « je rencontre
     * un problème technique » alors qu'un autre modèle de la même famille répondait
     * normalement.
     */
    public static function estMoteurIndisponible(\Throwable $e): bool
    {
        if (!$e instanceof HttpExceptionInterface) {
            return false;
        }

        try {
            // 503 chez Google, 529 « overloaded_error » chez Anthropic : même
            // situation, même remède — ce n'est pas notre quota, c'est leur charge.
            return in_array($e->getResponse()->getStatusCode(), [503, 529], true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * L'échec est-il le PLAFOND DE DÉPENSE MENSUEL du compte (Anthropic) ?
     *
     * Il arrive en HTTP 429, comme une saturation de débit — et c'est tout ce
     * qu'ils ont en commun. Celui-ci n'a PAS d'en-tête « retry-after », aucune
     * attente ne le libère, et les réessais automatiques des clients HTTP y
     * échouent en boucle jusqu'au premier du mois suivant. Lui servir le message
     * « réessayez dans N secondes » serait un mensonge, et faire patienter le
     * moteur devant une porte fermée à clé.
     *
     * Le fournisseur le signe explicitement pour qu'on puisse le distinguer :
     * error.details.error_code = « enforced_spend_limit_reached ».
     */
    public static function estPlafondDeDepense(\Throwable $e): bool
    {
        $details = self::corpsErreur($e)['details'] ?? null;

        return \is_array($details) && ($details['error_code'] ?? null) === 'enforced_spend_limit_reached';
    }

    /** L'échec est-il un HTTP 429 (Too Many Requests) du fournisseur ? */
    public static function estLimiteDeDebit(\Throwable $e): bool
    {
        if (!$e instanceof HttpExceptionInterface) {
            return false;
        }

        try {
            return $e->getResponse()->getStatusCode() === 429;
        } catch (\Throwable) {
            return false; // réponse illisible : on reste sur le message générique
        }
    }

    /**
     * Délai d'attente annoncé par le fournisseur, en secondes entières
     * (arrondies au-dessus), ou null s'il ne l'annonce pas.
     *
     * Gemini : details[] contient un google.rpc.RetryInfo « retryDelay: 47s ».
     * Anthropic et la plupart des API HTTP : en-tête « retry-after » en secondes.
     */
    public static function secondesAvantNouvelEssai(\Throwable $e): ?int
    {
        // Le plafond de dépense est un 429 SANS délai : rien à annoncer, et surtout
        // pas l'en-tête d'une autre requête ni une durée inventée.
        if (self::estPlafondDeDepense($e)) {
            return null;
        }

        $erreur = self::corpsErreur($e);
        // « details » est une LISTE d'objets chez Google et un OBJET chez Anthropic :
        // le is_array() par élément évite de traiter une chaîne comme un tableau.
        foreach ($erreur['details'] ?? [] as $detail) {
            $delai = \is_array($detail) ? ($detail['retryDelay'] ?? null) : null;
            if (\is_string($delai) && preg_match('/^([\d.]+)s$/', $delai, $m)) {
                return max(1, (int) ceil((float) $m[1]));
            }
        }

        $entete = self::entete($e, 'retry-after');
        if ($entete !== null && ctype_digit($entete)) {
            return max(1, (int) $entete);
        }

        return null;
    }

    /**
     * La date de réouverture annoncée dans le message du plafond de dépense.
     *
     * Le fournisseur l'écrit en toutes lettres — « You will regain access on
     * 2026-09-01 at 00:00 UTC » — et c'est la seule information utile à rendre à
     * l'utilisateur, puisqu'aucun délai n'est fourni par ailleurs. Null si le
     * libellé change : mieux vaut une phrase sans date qu'une date inventée.
     */
    public static function dateDeReouverture(\Throwable $e): ?string
    {
        $message = (string) (self::corpsErreur($e)['message'] ?? '');

        return preg_match('/regain access on (\d{4}-\d{2}-\d{2})/', $message, $m) === 1 ? $m[1] : null;
    }

    /**
     * Détail exploitable du 429 pour le journal : quota violé, plafond, délai.
     * Sans lui, le journal ne montre qu'un « HTTP 429 » muet — impossible de
     * savoir si l'on butte sur les requêtes par minute, les tokens d'entrée par
     * minute ou le quota journalier, donc impossible de corriger la cause.
     *
     * @return array<string, int|string>
     */
    public static function detailsPourJournal(\Throwable $e): array
    {
        $erreur = self::corpsErreur($e);
        $details = array_filter([
            'message'    => isset($erreur['message']) ? mb_substr((string) $erreur['message'], 0, 400) : null,
            // « status » chez Google (RESOURCE_EXHAUSTED), « type » chez Anthropic
            // (rate_limit_error) : deux noms pour la même information.
            'statut'     => $erreur['status'] ?? $erreur['type'] ?? null,
            'retryApres' => self::secondesAvantNouvelEssai($e),
        ], static fn ($v) => $v !== null);

        // Structure google.rpc.QuotaFailure : le quota violé est nommé dans le corps.
        foreach ($erreur['details'] ?? [] as $detail) {
            if (!\is_array($detail)) {
                continue;
            }
            foreach ($detail['violations'] ?? [] as $violation) {
                $details['quotaId'] = $violation['quotaId'] ?? $violation['quotaMetric'] ?? '?';
                $details['quotaPlafond'] = $violation['quotaValue'] ?? '?';
                $details['quotaModele'] = $violation['quotaDimensions']['model'] ?? '?';
            }
        }

        // Anthropic ne nomme pas le quota dans le corps : il le publie en EN-TÊTES,
        // et sur chaque réponse — y compris celles qui réussissent. Le code d'erreur,
        // lui, distingue la saturation de débit du plafond de dépense mensuel.
        if (($erreur['details']['error_code'] ?? null) !== null) {
            $details['quotaId'] = (string) $erreur['details']['error_code'];
        }
        foreach ([
            'quotaEntreeRestante' => 'anthropic-ratelimit-input-tokens-remaining',
            'quotaEntreePlafond'  => 'anthropic-ratelimit-input-tokens-limit',
            'quotaEntreeReset'    => 'anthropic-ratelimit-input-tokens-reset',
            'quotaSortieRestante' => 'anthropic-ratelimit-output-tokens-remaining',
        ] as $cle => $entete) {
            $valeur = self::entete($e, $entete);
            if ($valeur !== null) {
                $details[$cle] = $valeur;
            }
        }

        return $details;
    }

    /** Corps JSON de l'erreur (clé « error »), tableau vide si illisible. */
    private static function corpsErreur(\Throwable $e): array
    {
        if (!$e instanceof HttpExceptionInterface) {
            return [];
        }

        try {
            // false = ne pas relever l'exception : on VEUT lire le corps du 4xx.
            $corps = json_decode($e->getResponse()->getContent(false), true);
        } catch (\Throwable) {
            return [];
        }

        return \is_array($corps['error'] ?? null) ? $corps['error'] : [];
    }

    private static function entete(\Throwable $e, string $nom): ?string
    {
        if (!$e instanceof HttpExceptionInterface) {
            return null;
        }

        try {
            return $e->getResponse()->getHeaders(false)[$nom][0] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
