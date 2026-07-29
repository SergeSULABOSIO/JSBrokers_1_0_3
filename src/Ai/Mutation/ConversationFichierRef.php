<?php

namespace App\Ai\Mutation;

/**
 * Marqueur « @fichier:<id> » : une valeur de champ d'un plan de Ket qui désigne
 * un FICHIER attaché à la conversation (cf. AssistantConversationFichier), à
 * injecter comme véritable upload dans le formulaire de l'entité (ex. le champ
 * « fichier » d'un Document). C'est le canal dédié qui permet à Ket de CLASSER
 * une pièce jointe sans que le contrat scalaire des « champs » n'ait à porter un
 * binaire.
 *
 * Le marqueur partage le préfixe « @ » des renvois entre opérations
 * (MutationReferences) mais en est DISTINCT : MutationReferences::estReference()
 * l'exclut explicitement pour qu'il ne soit jamais interprété comme une
 * étiquette introuvable. La résolution en UploadedFile a lieu, fail-closed au
 * périmètre de la conversation, juste avant la soumission du formulaire
 * (WorkspaceMutationService), en dry-run comme à l'exécution.
 */
final class ConversationFichierRef
{
    /** Préfixe du marqueur (suivi de l'identifiant du fichier de conversation). */
    public const PREFIXE = '@fichier:';

    private const MOTIF = '/^@fichier:(\d+)$/';

    /** La valeur est-elle un marqueur de fichier de conversation bien formé ? */
    public static function estMarqueur(mixed $valeur): bool
    {
        return is_string($valeur) && preg_match(self::MOTIF, $valeur) === 1;
    }

    /** Identifiant du fichier désigné, ou null si la valeur n'est pas un marqueur. */
    public static function id(string $valeur): ?int
    {
        return preg_match(self::MOTIF, $valeur, $m) === 1 ? (int) $m[1] : null;
    }

    /** Construit le marqueur pour un identifiant donné (« @fichier:7 »). */
    public static function marqueur(int $id): string
    {
        return self::PREFIXE . $id;
    }
}
