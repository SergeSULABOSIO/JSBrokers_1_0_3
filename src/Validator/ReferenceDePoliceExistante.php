<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * UN SINISTRE PORTE SUR UNE AFFAIRE RÉELLEMENT SOUSCRITE.
 *
 * Posée sur la CLASSE et non sur la propriété : la règle lit deux champs à la fois —
 * la référence, et l'assuré auquel elle doit appartenir.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ReferenceDePoliceExistante extends Constraint
{
    public string $messageIntrouvable =
        'La police « {{ reference }} » ne correspond à aucune affaire de votre cabinet. '
        . 'Choisissez une police dans la liste.';

    public string $messageAutreClient =
        'La police « {{ reference }} » couvre {{ titulaire }}, pas {{ assure }}. '
        . 'Choisissez une police de cet assuré.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
