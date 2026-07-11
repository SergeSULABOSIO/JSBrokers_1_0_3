<?php

namespace App\Ai;

use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Message d'excuse de l'assistant quand le moteur IA réel échoue : honnête sur
 * la cause quand elle est identifiable (429 = quota/limite de débit de l'API du
 * fournisseur), générique sinon. Utilisé par le point de repli unique
 * (AssistantIaController), quel que soit le moteur (Claude, Gemini…).
 */
final class AiEngineFailure
{
    public static function messagePour(\Throwable $e): string
    {
        if (self::estLimiteDeDebit($e)) {
            return "Mon moteur d'intelligence est momentanément saturé (limite de requêtes du "
                . 'fournisseur atteinte). Patientez une petite minute puis renvoyez votre '
                . 'question — votre message a bien été conservé.';
        }

        return "Je rencontre un problème technique pour joindre mon moteur d'intelligence. "
            . 'Réessayez dans un instant — votre message a bien été conservé.';
    }

    /** L'échec est-il un HTTP 429 (Too Many Requests) du fournisseur ? */
    private static function estLimiteDeDebit(\Throwable $e): bool
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
}
