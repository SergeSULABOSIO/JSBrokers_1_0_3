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
        self::DEBUT => [
            'debut-1' => 'Hum… laissez-moi vérifier.',
            'debut-2' => 'D’accord… une seconde, je vérifie cela.',
            'debut-3' => 'Bonne question, je consulte ma base de connaissances…',
            'debut-4' => 'Très bien, un petit instant…',
            'debut-5' => 'Hum… je regarde tout de suite.',
            'debut-6' => 'Entendu. Je vais chercher cela.',
        ],
        self::RELANCE => [
            'relance-1' => 'Encore un petit instant…',
            'relance-2' => 'Je rassemble les éléments…',
            'relance-3' => 'J’y suis presque…',
            'relance-4' => 'Je vérifie les derniers détails…',
            'relance-5' => 'Voilà, ça vient…',
        ],
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
