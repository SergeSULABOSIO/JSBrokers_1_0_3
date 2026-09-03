<?php

namespace App\Echange\Classeur;

/**
 * Le fichier déposé n'est pas un classeur exploitable.
 *
 * Levée en passe 1, avant qu'une seule ligne de données ait été lue : l'import
 * s'arrête là, avec un message unique et clair, et AUCUNE occurrence n'est décomptée.
 * Un fichier qu'on ne sait pas ouvrir n'a rien coûté à personne.
 */
final class ClasseurIllisibleException extends \RuntimeException
{
}
