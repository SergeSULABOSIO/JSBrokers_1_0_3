<?php

namespace App\Service\Conge;

/**
 * Une transition refusée, avec les raisons du refus.
 *
 * Les messages sont destinés à l'utilisateur : ils sont rendus tels quels par le
 * contrôleur (422) comme par l'assistant, pour que le même geste refusé donne le même
 * mot d'un canal à l'autre.
 */
class CongeTransitionException extends \RuntimeException
{
    /**
     * @param string[] $violations
     */
    public function __construct(
        public readonly array $violations,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : (string) ($violations[0] ?? 'Transition refusée.'));
    }
}
