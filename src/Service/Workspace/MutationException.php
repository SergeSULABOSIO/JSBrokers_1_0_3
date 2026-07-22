<?php

namespace App\Service\Workspace;

/**
 * Échec MÉTIER (attendu) d'une opération d'écriture/suppression pilotée par
 * l'assistant IA : hors périmètre, cible introuvable, validation invalide ou
 * suppression bloquée par une contrainte. Levée dans la transaction d'exécution
 * → rollback global + journal marquant l'étape fautive. Porte un message
 * lisible et, le cas échéant, le détail des erreurs de champ.
 */
final class MutationException extends \RuntimeException
{
    public const HORS_PERIMETRE = 'hors_perimetre';
    public const INTROUVABLE    = 'introuvable';
    public const INVALIDE       = 'invalide';
    public const BLOQUE         = 'bloque';

    /**
     * @param array<string, string[]> $erreursChamps
     */
    public function __construct(
        public readonly string $statut,
        string $message,
        public readonly array $erreursChamps = [],
    ) {
        parent::__construct($message);
    }

    public static function horsPerimetre(string $message): self
    {
        return new self(self::HORS_PERIMETRE, $message);
    }

    public static function introuvable(string $message): self
    {
        return new self(self::INTROUVABLE, $message);
    }

    /** @param array<string, string[]> $erreursChamps */
    public static function invalide(string $message, array $erreursChamps = []): self
    {
        return new self(self::INVALIDE, $message, $erreursChamps);
    }

    public static function bloque(string $message): self
    {
        return new self(self::BLOQUE, $message);
    }
}
