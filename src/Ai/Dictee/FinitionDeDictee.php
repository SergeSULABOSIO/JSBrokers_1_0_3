<?php

namespace App\Ai\Dictee;

/**
 * Le résultat d'une finition de dictée. `finie` dit si le texte a réellement été mis
 * au propre : c'est ce qui décide de la facturation — on ne facture jamais une
 * finition qui n'a pas eu lieu.
 */
final class FinitionDeDictee
{
    private function __construct(
        public readonly string $texte,
        public readonly bool $finie,
    ) {
    }

    public static function finie(string $texte): self
    {
        return new self($texte, true);
    }

    public static function inchangee(string $texte): self
    {
        return new self($texte, false);
    }
}
