<?php

namespace App\Service\Conge;

/**
 * Une période comprise, avec l'INTERPRÉTATION qui en a été faite.
 *
 * L'interprétation n'est pas un ornement : c'est elle qui est affichée dans le
 * récapitulatif de confirmation de l'assistant. « La semaine prochaine » ne veut pas dire
 * la même chose pour tout le monde, et un congé créé sur une date mal comprise coûte plus
 * cher que le tour de dialogue supplémentaire qui l'aurait évité.
 */
final class PeriodeResolue
{
    public function __construct(
        public readonly \DateTimeImmutable $debut,
        public readonly \DateTimeImmutable $fin,
        /** Ce que le service a compris, en français, à montrer à l'utilisateur. */
        public readonly string $interpretation,
    ) {
    }

    /** @return array{debut: string, fin: string, interpretation: string} */
    public function toArray(): array
    {
        return [
            'debut' => $this->debut->format('Y-m-d'),
            'fin' => $this->fin->format('Y-m-d'),
            'interpretation' => $this->interpretation,
        ];
    }
}
