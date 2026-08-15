<?php

namespace App\Ai\Traitement;

use Doctrine\DBAL\Connection;

/**
 * @file Un seul traitement à la fois par conversation.
 * @description Mutex réel, obtenu par UPDATE conditionnel atomique.
 *
 * POURQUOI UN VERROU ALORS QU'ON NE LANCE QU'UN WORKER. Parce que « on ne lance
 * qu'un worker » est une décision d'exploitation, pas une propriété du code.
 * Le jour où quelqu'un en démarre deux — pour absorber une charge, ou par
 * mégarde —, rien ne doit casser. Le verrou est l'assurance ; le drainage
 * séquentiel est le mécanisme.
 *
 * CE QU'IL PROTÈGE, ET C'EST DU MÉTIER. Deux traitements simultanés sur la même
 * conversation violeraient tout ce qui fait la cohérence d'un fil : la fenêtre
 * des vingt derniers messages (le second ne verrait pas la réponse au premier),
 * le verrou « un seul plan en attente » (deux barres de décision, dont une
 * orpheline), le rattachement d'une étape de programme à SON message, et les
 * garde-fous anti-plan-fantôme, qui raisonnent sur l'état complet du fil.
 *
 * POURQUOI PAS « SELECT … FOR UPDATE ». Il faudrait tenir une transaction
 * ouverte pendant les vingt à quarante secondes du traitement, alors que
 * celui-ci flushe plusieurs fois. Le second worker attendrait sur
 * innodb_lock_wait_timeout (cinquante secondes par défaut) puis lèverait — on
 * aurait remplacé une course par une panne.
 *
 * POURQUOI PAS UN INDEX UNIQUE PARTIEL. MariaDB ne les a pas. Le contournement
 * (colonne nullable + contrainte UNIQUE, les NULL échappant à l'unicité)
 * fonctionne, mais transforme la prise de verrou en rattrapage d'exception —
 * moins lisible qu'un rowCount().
 *
 * POURQUOI PAS symfony/lock. Le composant n'est pas installé, et une seule
 * requête SQL suffit ici. L'ajouter pour ça serait une dépendance de plus pour
 * un problème déjà résolu par la base.
 */
final class VerrouDeConversation
{
    /**
     * Au-delà, le verrou est réputé abandonné et peut être repris.
     *
     * Un worker tué net (déploiement, OOM, coupure) ne relâche rien : sans
     * péremption, la conversation resterait gelée pour toujours et l'utilisateur
     * n'aurait aucun moyen de s'en sortir. Cinq minutes, soit largement plus que
     * le traitement le plus lent qu'on ait mesuré, et largement moins qu'une
     * pause déjeuner.
     */
    public const EXPIRATION_SECONDES = 300;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Tente de prendre le verrou. Vrai si cette instance l'a obtenu.
     *
     * L'atomicité vient de l'UPDATE lui-même : MariaDB pose un verrou de ligne
     * pour l'exécuter, donc de deux exécutions concurrentes une seule peut voir
     * la condition satisfaite. Il n'y a pas de fenêtre entre le test et la prise
     * — c'est la même instruction.
     */
    public function prendre(int $idConversation): bool
    {
        $peremption = (new \DateTimeImmutable())
            ->sub(new \DateInterval('PT' . self::EXPIRATION_SECONDES . 'S'));

        $lignes = $this->connection->executeStatement(
            'UPDATE assistant_conversation
                SET traitement_depuis = :maintenant
              WHERE id = :id
                AND (traitement_depuis IS NULL OR traitement_depuis < :peremption)',
            [
                'maintenant' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id'         => $idConversation,
                'peremption' => $peremption->format('Y-m-d H:i:s'),
            ],
        );

        return $lignes === 1;
    }

    /**
     * Relâche le verrou. Toujours dans un `finally` : un verrou oublié gèle la
     * conversation jusqu'à sa péremption, et l'utilisateur ne comprendrait pas
     * pourquoi Ket ne lui répond plus.
     */
    public function relacher(int $idConversation): void
    {
        $this->connection->executeStatement(
            'UPDATE assistant_conversation SET traitement_depuis = NULL WHERE id = :id',
            ['id' => $idConversation],
        );
    }
}
