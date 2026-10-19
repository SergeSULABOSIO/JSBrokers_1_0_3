<?php

namespace App\Exception;

/**
 * Un refus du métier, dit à l'utilisateur, qui n'a pas d'exception dédiée.
 *
 * Ces refus étaient levés en `\RuntimeException` ou `\Exception` nues : le même `catch`
 * relayait alors à l'écran le refus voulu ET le texte brut d'une panne (SQL, chemin).
 * Typés, ils se distinguent : MessageDErreur relaie celui-ci, masque l'autre.
 */
class RefusMetierException extends \RuntimeException implements MessagePourLUtilisateur
{
}
