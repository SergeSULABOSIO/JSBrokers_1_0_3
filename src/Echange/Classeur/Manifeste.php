<?php

namespace App\Echange\Classeur;

use App\Echange\Canevas\RessourceDEchange;

/**
 * CARTE D'IDENTITÉ d'un classeur d'échange — le contenu de la feuille `_MANIFESTE`.
 *
 * Elle répond aux quatre questions que l'import doit poser avant de lire une seule
 * ligne de données : d'où vient ce fichier, quand, produit par quelle version de
 * l'application, et sa structure a-t-elle été touchée depuis.
 *
 * L'EMPREINTE DES EN-TÊTES est ce qui distingue un fichier retravaillé d'un fichier
 * cassé. L'utilisateur a le droit de masquer des colonnes, d'en déplacer, de trier ses
 * lignes, d'ajouter une feuille de brouillon : le parsing s'appuie sur la ligne 2 de
 * chaque feuille, pas sur des positions. Mais s'il RENOMME un code technique ou en
 * supprime un, le fichier ment sur ce qu'il contient — et c'est cela, et cela seul, que
 * l'empreinte détecte.
 */
final class Manifeste
{
    public const CLE_UID_CABINET   = 'uid_cabinet';
    public const CLE_NOM_CABINET   = 'nom_cabinet';
    public const CLE_GENERE_LE     = 'genere_le';
    public const CLE_GENERE_PAR    = 'genere_par';
    public const CLE_VERSION       = 'version_schema';
    public const CLE_PERIMETRE     = 'perimetre';
    public const CLE_EMPREINTE     = 'empreinte_entetes';

    /** Libellés lisibles, pour que la feuille se lise sans documentation. */
    public const LIBELLES = [
        self::CLE_UID_CABINET => 'Identifiant du cabinet émetteur',
        self::CLE_NOM_CABINET => 'Cabinet émetteur',
        self::CLE_GENERE_LE   => 'Généré le',
        self::CLE_GENERE_PAR  => 'Généré par',
        self::CLE_VERSION     => 'Version du schéma',
        self::CLE_PERIMETRE   => 'Données présentes',
        self::CLE_EMPREINTE   => 'Empreinte des en-têtes',
    ];

    /**
     * @param string[] $perimetre codes des ressources présentes dans le fichier
     */
    public function __construct(
        public readonly string $uidCabinet,
        public readonly string $nomCabinet,
        public readonly \DateTimeImmutable $genereLe,
        public readonly string $generePar,
        public readonly string $versionSchema,
        public readonly array $perimetre,
        public readonly string $empreinteEntetes,
    ) {
    }

    /**
     * Empreinte des lignes d'en-têtes TECHNIQUES (ligne 2 de chaque feuille de données),
     * dans l'ordre des feuilles.
     *
     * Déterministe par construction : l'ordre des feuilles est topologique et stable,
     * et l'ordre des colonnes est celui du canevas. Deux exports du même périmètre sur
     * la même version produisent donc la même empreinte — sans quoi une réimportation
     * immédiate se croirait altérée.
     *
     * @param array<string, RessourceDEchange> $ressources dans l'ordre du classeur
     * @param string[]                         $colonnesTechniques
     */
    public static function empreinte(array $ressources, array $colonnesTechniques): string
    {
        $morceaux = [];
        foreach ($ressources as $ressource) {
            $codes = array_merge($colonnesTechniques, array_map(
                static fn ($colonne) => $colonne->code,
                $ressource->colonnes,
            ));
            $morceaux[] = $ressource->code . '=' . implode(',', $codes);
        }

        return hash('sha256', implode('|', $morceaux));
    }

    /**
     * Lignes clé/valeur telles qu'elles apparaissent dans la feuille.
     *
     * @return array<int, array{0: string, 1: string, 2: string}> [clé, libellé, valeur]
     */
    public function lignes(): array
    {
        return [
            [self::CLE_UID_CABINET, self::LIBELLES[self::CLE_UID_CABINET], $this->uidCabinet],
            [self::CLE_NOM_CABINET, self::LIBELLES[self::CLE_NOM_CABINET], $this->nomCabinet],
            [self::CLE_GENERE_LE,   self::LIBELLES[self::CLE_GENERE_LE],   $this->genereLe->format(\DateTimeInterface::ATOM)],
            [self::CLE_GENERE_PAR,  self::LIBELLES[self::CLE_GENERE_PAR],  $this->generePar],
            [self::CLE_VERSION,     self::LIBELLES[self::CLE_VERSION],     $this->versionSchema],
            [self::CLE_PERIMETRE,   self::LIBELLES[self::CLE_PERIMETRE],   implode(',', $this->perimetre)],
            [self::CLE_EMPREINTE,   self::LIBELLES[self::CLE_EMPREINTE],   $this->empreinteEntetes],
        ];
    }

    /**
     * Reconstruit un manifeste depuis les lignes lues d'un classeur déposé.
     *
     * Tolérant sur ce qui n'engage rien (un cabinet sans nom, une date illisible) et
     * strict sur ce qui engage : l'identifiant du cabinet et l'empreinte manquants
     * rendent le fichier non identifiable, et c'est à l'appelant d'en tirer un refus.
     *
     * @param array<string, string> $valeurs clé => valeur
     */
    public static function depuisValeurs(array $valeurs): self
    {
        $date = null;
        $brut = trim($valeurs[self::CLE_GENERE_LE] ?? '');
        if ($brut !== '') {
            try {
                $date = new \DateTimeImmutable($brut);
            } catch (\Throwable) {
                $date = null;
            }
        }

        $perimetre = array_values(array_filter(array_map(
            'trim',
            explode(',', $valeurs[self::CLE_PERIMETRE] ?? ''),
        ), static fn (string $code) => $code !== ''));

        return new self(
            uidCabinet: trim($valeurs[self::CLE_UID_CABINET] ?? ''),
            nomCabinet: trim($valeurs[self::CLE_NOM_CABINET] ?? ''),
            genereLe: $date ?? new \DateTimeImmutable('@0'),
            generePar: trim($valeurs[self::CLE_GENERE_PAR] ?? ''),
            versionSchema: trim($valeurs[self::CLE_VERSION] ?? ''),
            perimetre: $perimetre,
            empreinteEntetes: trim($valeurs[self::CLE_EMPREINTE] ?? ''),
        );
    }
}
