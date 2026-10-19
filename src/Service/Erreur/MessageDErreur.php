<?php

namespace App\Service\Erreur;

use App\Exception\MessagePourLUtilisateur;
use Psr\Log\LoggerInterface;

/**
 * LE TEXTE D'UNE ERREUR TECHNIQUE NE SORT PAS — SOURCE UNIQUE DU TRI.
 *
 * Quinze `catch` relayaient `$e->getMessage()` à l'écran : un `SQLSTATE[23000]…`, le chemin
 * d'un fichier sur le serveur, le nom d'une classe arrivaient chez le courtier (et chez
 * quiconque cherche à cartographier le serveur). Le tri :
 *  - une exception marquée MessagePourLUtilisateur, ou une `\DomainException` (le projet
 *    ne s'en sert que pour ses règles métier, ex. intangibilité OHADA), est relayée ;
 *  - toute autre est journalisée au niveau ERROR — ce qui l'envoie à la supervision
 *    (HandlerDeSupervision) avec type, fichier et ligne — et l'écran reçoit le REPLI du
 *    site, rédigé pour le geste en cours.
 */
final class MessageDErreur
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function estPourLUtilisateur(\Throwable $erreur): bool
    {
        return $erreur instanceof MessagePourLUtilisateur || $erreur instanceof \DomainException;
    }

    /** Le message à montrer : celui du métier, ou le repli si l'erreur est technique. */
    public function pourLEcran(\Throwable $erreur, string $repli): string
    {
        if (self::estPourLUtilisateur($erreur)) {
            $message = trim($erreur->getMessage());
            if ($message !== '') {
                return $message;
            }
        }

        $this->logger->error('Erreur technique masquée à l\'écran : {type}', [
            'exception' => $erreur,
            'type' => $erreur::class,
        ]);

        return $repli;
    }
}
