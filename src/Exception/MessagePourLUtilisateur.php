<?php

namespace App\Exception;

/**
 * « MON MESSAGE EST RÉDIGÉ POUR L'UTILISATEUR. »
 *
 * Une exception qui porte ce marqueur a un texte écrit dans les mots du métier : il peut
 * aller à l'écran tel quel. Toute autre exception (Doctrine, SQL, lecteur de fichier,
 * erreur PHP) a un texte technique — chemin, requête, classe — qui ne sort jamais : il
 * part au journal (supervision) et l'écran reçoit un repli métier.
 * Source unique du tri : App\Service\Erreur\MessageDErreur::pourLEcran().
 */
interface MessagePourLUtilisateur
{
}
