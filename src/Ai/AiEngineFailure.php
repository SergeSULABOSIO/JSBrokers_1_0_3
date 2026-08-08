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
        if (self::estLimiteDeDebit($e)) {
            $secondes = self::secondesAvantNouvelEssai($e);

            return "Mon moteur d'intelligence est momentanément saturé : la limite de tokens "
                . 'par minute du fournisseur est atteinte. '
                . ($secondes !== null
                    ? sprintf('Réessayez dans %d secondes', $secondes)
                    : 'Patientez une petite minute puis renvoyez votre question')
                . ' — votre message a bien été conservé.';
        }

        return "Je rencontre un problème technique pour joindre mon moteur d'intelligence. "
            . 'Réessayez dans un instant — votre message a bien été conservé.';
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
        $erreur = self::corpsErreur($e);
        foreach ($erreur['details'] ?? [] as $detail) {
            $delai = $detail['retryDelay'] ?? null;
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
            'statut'     => $erreur['status'] ?? null,
            'retryApres' => self::secondesAvantNouvelEssai($e),
        ], static fn ($v) => $v !== null);

        foreach ($erreur['details'] ?? [] as $detail) {
            foreach ($detail['violations'] ?? [] as $violation) {
                $details['quotaId'] = $violation['quotaId'] ?? $violation['quotaMetric'] ?? '?';
                $details['quotaPlafond'] = $violation['quotaValue'] ?? '?';
                $details['quotaModele'] = $violation['quotaDimensions']['model'] ?? '?';
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
