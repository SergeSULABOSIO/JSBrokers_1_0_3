<?php

namespace App\Ai\Fichier;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Le résultat de la résolution d'un marqueur « @fichier:<id> » : le fichier, OU la
 * raison pour laquelle il n'a pas pu être joint.
 *
 * POURQUOI CE TYPE EXISTE (incident du 2026-08-14). Le résolveur rendait `null`
 * pour CINQ causes distinctes — marqueur malformé, hors conversation, fichier
 * introuvable, binaire absent du stockage, copie impossible — et l'appelant se
 * contentait de retirer le champ. Ket a donc référencé une pièce jointe #19 qui
 * n'existait pas (les pièces de la conversation s'arrêtaient à #18), le Document
 * s'est créé SANS fichier, et le plan s'est annoncé « exécuté, relu en base,
 * conforme au plan validé ». L'utilisateur a cherché son contrat dans un document
 * vide.
 *
 * Une pièce perdue ne se laisse pas au silence : c'est la même règle que l'aperçu
 * autoritaire d'un plan et que le démenti d'exécution fantôme — ce que le serveur
 * SAIT, l'utilisateur doit l'apprendre tout de suite.
 */
final class PieceResolue
{
    private function __construct(
        public readonly ?UploadedFile $upload,
        public readonly ?string $motif,
    ) {
    }

    public static function ok(UploadedFile $upload): self
    {
        return new self($upload, null);
    }

    /** Le motif est destiné à l'UTILISATEUR : il nomme ce qui manque et ce qui existe. */
    public static function refus(string $motif): self
    {
        return new self(null, $motif);
    }

    public function estResolue(): bool
    {
        return $this->upload !== null;
    }
}
