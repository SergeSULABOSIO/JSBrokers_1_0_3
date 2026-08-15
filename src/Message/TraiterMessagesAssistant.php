<?php

namespace App\Message;

/**
 * « Il y a du travail en attente sur cette conversation. »
 *
 * LE MESSAGE PORTE LA CONVERSATION, PAS LA TÂCHE. C'est délibéré, et c'est ce
 * qui rend tout le dispositif idempotent. Un signal ne réclame pas le traitement
 * d'UNE question précise : il réveille un drainage, qui prendra tout ce qui
 * attend, dans l'ordre. Trois messages envoyés en rafale émettent trois signaux,
 * mais le premier arrivé fera peut-être le travail des trois — et les deux
 * autres n'auront alors rien à faire. Une livraison en double, un rejeu après
 * incident, un signal superflu : tous se résolvent en no-op, sans coordination.
 */
final class TraiterMessagesAssistant
{
    public function __construct(public readonly int $idConversation)
    {
    }
}
