<?php

namespace App\Service\Note;

/**
 * Le refus du dernier rempart ({@see \App\EventListener\IntangibiliteDesNotesListener}) :
 * une écriture modifierait une note intangible. Son message s'adresse au courtier — les
 * portes le rendent tel quel (409 à l'écran, blocage chez Ket), jamais en erreur 500.
 */
final class NoteIntangibleException extends \LogicException implements \App\Exception\MessagePourLUtilisateur
{
}
