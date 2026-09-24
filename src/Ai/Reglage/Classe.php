<?php

namespace App\Ai\Reglage;

/**
 * CE QU'UN ADMINISTRATEUR JOSEARA A LE DROIT DE TOUCHER, ET CE QU'IL NE PEUT QUE LIRE.
 *
 * Quatre classes, et la frontière ne passe pas où l'on croit. Elle ne sépare pas
 * l'important de l'accessoire : elle sépare ce dont le retrait PRIVE d'une capacité
 * de ce dont le retrait CASSE quelque chose — les données, la sécurité, le
 * cloisonnement entre cabinets, la comptabilité, la réglementation.
 */
enum Classe: string
{
    /**
     * Intégrité, sécurité, cloisonnement, comptabilité, réglementation, et la
     * restriction de Ket aux comptes payants. Jamais modifiable, pas même par un
     * super-administrateur, pas même par appel direct.
     */
    case INVARIANT = 'invariant';

    /**
     * Outil sans lequel Ket ne fonctionne plus. Lecture seule — non par précaution,
     * mais parce que le prompt le NOMME : le couper produirait un outil fantôme,
     * c'est-à-dire une capacité promise à l'utilisateur et introuvable au moment de
     * s'en servir.
     */
    case INDISPENSABLE = 'indispensable';

    /** Valeur ajustable entre deux bornes : un seuil, un délai, un horizon. */
    case PARAMETRE = 'parametre';

    /**
     * Outil dont le retrait ne met en danger ni les données ni la sécurité — la
     * garde vit dans `execute()`, pas dans la déclaration. Ce qu'on perd est une
     * capacité, et l'écran qui la porte continue de l'offrir.
     */
    case DESACTIVABLE = 'desactivable';

    public function libelle(): string
    {
        return match ($this) {
            self::INVARIANT     => 'Invariant',
            self::INDISPENSABLE => 'Indispensable',
            self::PARAMETRE     => 'Paramètre',
            self::DESACTIVABLE  => 'Désactivable',
        };
    }

    /** Une phrase, en langage de courtier, pour la colonne et la fiche. */
    public function explication(): string
    {
        return match ($this) {
            self::INVARIANT     => 'Touche à l’intégrité des données, à la sécurité ou à la comptabilité. Ne se modifie jamais, pas même par un super-administrateur.',
            self::INDISPENSABLE => 'Ket ne fonctionne plus sans lui : ses instructions le nomment. Le couper lui ferait promettre une capacité introuvable.',
            self::PARAMETRE     => 'Valeur ajustable entre deux bornes. Hors bornes, la valeur d’origine reprend la main.',
            self::DESACTIVABLE  => 'Se coupe sans risque : Ket perd une capacité, l’écran qui la porte continue de l’offrir.',
        };
    }

    /** Peut-on agir dessus depuis la console ? */
    public function estModifiable(): bool
    {
        return $this === self::PARAMETRE || $this === self::DESACTIVABLE;
    }

    /** Pastille de la liste : la charte n'a que trois couleurs sémantiques. */
    public function ton(): string
    {
        return match ($this) {
            self::INVARIANT, self::INDISPENSABLE => 'verrou',
            self::PARAMETRE                      => 'reglable',
            self::DESACTIVABLE                   => 'ouvert',
        };
    }
}
