<?php

namespace App\Ai\Mutation;

/**
 * Registre des RÉFÉRENCES entre opérations d'un même plan de Ket.
 *
 * Raison d'être : dans un plan multi-entités, une opération peut dépendre d'un
 * enregistrement créé par une opération PRÉCÉDENTE du même plan — dont l'id
 * n'existe pas encore au moment où le plan est présenté (ex. « crée le client,
 * puis la piste DE ce client »). Le LLM étiquette donc la création (`ref`) et y
 * renvoie ailleurs par la valeur `@etiquette`.
 *
 * Deux modes, un seul comportement de résolution :
 *  - DRY-RUN (préparation) : une référence déclarée est « promise » — le champ est
 *    considéré comme FOURNI (il ne sera pas réclamé à l'utilisateur) mais n'est pas
 *    soumis au formulaire (aucun id à valider) ;
 *  - LIVE (exécution) : la référence est résolue en id réel, enregistré juste après
 *    la persistance de l'opération qui la déclare.
 *
 * FAIL-CLOSED : une référence inconnue (jamais déclarée) ou déclarée PLUS LOIN dans
 * le plan (renvoi en avant) n'est jamais résolue — elle est signalée comme champ
 * manquant au dry-run et fait échouer l'exécution.
 */
final class MutationReferences
{
    /** Préfixe d'une valeur de champ qui désigne une autre opération du plan. */
    public const PREFIXE = '@';

    /** Référence déclarée mais dont l'id n'est pas encore connu (dry-run). */
    public const PROMISE = '__ref_promise__';

    /** @var array<string, int|null> étiquette => id réel (null = promise) */
    private array $refs = [];

    public function __construct(
        private readonly bool $dryRun = true,
    ) {
    }

    public static function dryRun(): self
    {
        return new self(true);
    }

    public static function live(): self
    {
        return new self(false);
    }

    /** Une valeur de champ est-elle un renvoi vers une autre opération ? */
    public static function estReference(mixed $valeur): bool
    {
        return is_string($valeur)
            && strlen($valeur) > 1
            && str_starts_with($valeur, self::PREFIXE);
    }

    /** Étiquette normalisée d'une valeur de référence (« @client » => « client »). */
    public static function etiquette(string $valeur): string
    {
        return mb_strtolower(trim(substr($valeur, 1)));
    }

    /** Déclare l'étiquette d'une création (dry-run : promesse ; live : id réel). */
    public function declarer(?string $ref, ?int $id = null): void
    {
        $cle = $this->normaliser($ref);
        if ($cle === null) {
            return;
        }
        $this->refs[$cle] = $this->dryRun ? null : $id;
    }

    /**
     * Résout une valeur de référence :
     *  - id réel (live, déjà persisté) ;
     *  - self::PROMISE (dry-run, ou live avant persistance) ;
     *  - null si l'étiquette est inconnue (fail-closed).
     */
    public function resoudre(string $valeur): int|string|null
    {
        $cle = self::etiquette($valeur);
        if (!array_key_exists($cle, $this->refs)) {
            return null;
        }

        return $this->refs[$cle] ?? self::PROMISE;
    }

    private function normaliser(?string $ref): ?string
    {
        if ($ref === null) {
            return null;
        }
        $cle = mb_strtolower(trim(str_starts_with($ref, self::PREFIXE) ? substr($ref, 1) : $ref));

        return $cle === '' ? null : $cle;
    }
}
