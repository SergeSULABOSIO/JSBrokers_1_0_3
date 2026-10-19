<?php

namespace App\Service\Note;

/** Une note qui ne peut pas être émise : le message s'adresse au courtier. */
final class NoteNonValidable extends \RuntimeException implements \App\Exception\MessagePourLUtilisateur
{
}
