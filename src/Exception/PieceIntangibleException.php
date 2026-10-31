<?php

namespace App\Exception;

/**
 * Le refus du dernier rempart d'une PIÈCE COMPTABLE intangible — une note émise
 * ({@see \App\Service\Note\NoteIntangibleException}), un reversement enregistré
 * ({@see \App\Service\Retro\ReversementIntangibleException}). Son message s'adresse au courtier :
 * les portes le rendent tel quel (409 à l'écran, blocage chez Ket), jamais en erreur 500. Elles
 * rattrapent CETTE classe, pas ses filles : une pièce de plus ne demande pas une porte de plus.
 */
abstract class PieceIntangibleException extends \LogicException implements MessagePourLUtilisateur
{
}
