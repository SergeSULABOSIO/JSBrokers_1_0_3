<?php

namespace App\Ai\Live;

/**
 * LES INTERMÈDES DE KET : les petites phrases qu'il dit PENDANT qu'il réfléchit.
 *
 * En mode Live, une réponse demande 10 à 40 secondes — le temps que le moteur comprenne,
 * planifie, lise les données et rédige. Un silence de cette longueur dans une
 * conversation orale se lit comme une panne. Ket comble donc l'attente comme le ferait
 * une conseillère au téléphone : « Hum… laissez-moi vérifier. »
 *
 * CE NE SONT QUE DES SONS D'ATTENTE. Ils ne portent AUCUNE information métier, ne
 * viennent pas du moteur, et n'entrent jamais dans le fil de la conversation : le
 * cerveau de Ket reste seul à parler du fond.
 *
 * SOURCE UNIQUE : les clés sont stables (elles servent d'URL et de nom de fichier dans
 * le cache audio), et reformuler une phrase suffit à changer ce que Ket dit. Ajouter une
 * phrase ne demande rien d'autre que d'écrire une ligne ici.
 */
final class IntermedesDeKet
{
    /** Dit juste après la question, quand la réflexion commence. */
    public const DEBUT = 'debut';

    /** Dit plus tard, si la réflexion dure. */
    public const RELANCE = 'relance';

    /**
     * @var array<string, array<string, string>> moment => clé => phrase
     */
    private const PHRASES = [
        // UN ACCUSÉ DE RÉCEPTION, ET RIEN D'AUTRE. Ce que Ket doit dire quand on vient
        // de lui parler tient en deux syllabes : « je t'ai entendu, je m'en occupe ».
        // Les formules plus longues — « Bonne question, je consulte ma base de
        // connaissances… » — annonçaient un travail au lieu d'accuser réception, et
        // occupaient la parole au moment précis où l'utilisateur vient de la rendre.
        // Décision de l'exploitant, 2026-09-23.
        self::DEBUT => [
            'debut-1' => 'Hmm…',
            'debut-2' => 'Ok, entendu…',
            'debut-3' => 'Okay…',
        ],

        // VIDE, ET C'EST VOULU. Ket ne meuble plus une attente qui dure : elle accuse
        // réception une fois, puis se tait jusqu'à sa réponse. Les relances toutes les
        // neuf secondes parlaient pour ne rien dire, et repoussaient d'autant le moment
        // où l'utilisateur pouvait reprendre la parole. La constante et le moment
        // restent en place : rouvrir cette porte ne demande que d'écrire une ligne.
        self::RELANCE => [],
    ];

    /**
     * Le catalogue tel que le navigateur le reçoit : il précharge l'audio de chaque clé
     * au démarrage de la session, pour que les intermèdes se jouent sans attendre.
     *
     * @return array<string, array<string, string>>
     */
    public static function catalogue(): array
    {
        return self::PHRASES;
    }

    /** La phrase d'une clé, ou null si la clé n'existe pas (route : 404). */
    public static function phrase(string $cle): ?string
    {
        foreach (self::PHRASES as $phrases) {
            if (isset($phrases[$cle])) {
                return $phrases[$cle];
            }
        }

        return null;
    }
}
