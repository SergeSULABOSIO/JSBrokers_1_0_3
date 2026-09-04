<?php

namespace App\Echange\Service;

/**
 * Le contrôle visé ne peut plus être exécuté : expiré, déjà joué, annulé, ou son
 * fichier n'est plus sur le disque.
 *
 * Distincte d'un échec de contrôle : ici, rien n'a même été tenté. La base est
 * intacte, aucune occurrence n'est décomptée, et la réponse à donner à l'utilisateur
 * est « redéposez le fichier », pas « corrigez ces lignes ».
 */
final class ImportImpossibleException extends \RuntimeException
{
}
