<?php

namespace App\Service\Retro;

/**
 * Le refus du dernier rempart ({@see \App\EventListener\IntangibiliteDesReversementsListener}) :
 * une écriture modifierait ou supprimerait un reversement enregistré. Rattrapé par les portes
 * comme toute pièce intangible (409 à l'écran, blocage chez Ket).
 */
final class ReversementIntangibleException extends \App\Exception\PieceIntangibleException
{
}
