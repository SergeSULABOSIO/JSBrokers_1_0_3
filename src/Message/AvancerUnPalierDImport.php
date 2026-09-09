<?php

namespace App\Message;

/**
 * LE SIGNAL « un import a du travail en attente ».
 *
 * ⚠ IL NE PORTE QU'UN IDENTIFIANT, et c'est délibéré. Le contrôle vit en base, avec son
 * curseur, son rapport et son statut : c'est LUI l'état, et le message n'est qu'un coup
 * de sonnette. Transporter la fenêtre de lignes à traiter reviendrait à figer dans la
 * file une décision que la base seule peut prendre — et deux messages en vol
 * écriraient alors les mêmes lignes deux fois.
 *
 * Voisin de `TraiterMessagesAssistant`, qui porte une conversation pour la même raison.
 */
final class AvancerUnPalierDImport
{
    public function __construct(
        public readonly int $idRun,
    ) {
    }
}
