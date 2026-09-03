<?php

namespace App\Echange\Canevas;

/**
 * Une COLONNE d'une feuille de données du classeur d'échange.
 *
 * Objet de valeur, jamais construit à la main : {@see CanevasDEchange} le dérive des
 * sources qui existent déjà (FormType, métadonnées Doctrine, canevas d'entité). Rien
 * de ce qu'il porte n'est déclaré une seconde fois quelque part.
 */
final class ColonneDEchange
{
    /** Vocabulaire FERMÉ des types de colonne. Toute valeur hors de cette liste est un bug. */
    public const TYPE_TEXTE     = 'texte';
    public const TYPE_ENTIER    = 'entier';
    public const TYPE_DECIMAL   = 'decimal';
    public const TYPE_DATE      = 'date';
    public const TYPE_DATETIME  = 'datetime';
    public const TYPE_BOOLEEN   = 'booleen';
    public const TYPE_ENUM      = 'enum';
    public const TYPE_REFERENCE = 'reference';

    /**
     * @param string                    $code          code technique — la LIGNE 2 de la feuille, seule à faire foi au parsing
     * @param string                    $libelle       libellé humain — la ligne 1, purement décorative
     * @param string                    $type          l'une des constantes TYPE_* ci-dessus
     * @param bool                      $obligatoire   exigé à la création
     * @param bool                      $lectureSeule  exporté, mais IGNORÉ à l'import (indicateur calculé, champ absent du FormType)
     * @param array<int|string, string> $choix         énumération `code => libellé`, alimente les listes déroulantes de `_LISTES`
     * @param string|null               $referenceCode nom court de la ressource cible, pour TYPE_REFERENCE
     * @param bool                      $referenceHorsPerimetre la cible n'est pas échangeable : la colonne est descriptive, jamais réimportée
     * @param string|null               $formatExcel   format de nombre Excel (`#,##0.00`…), null = format général
     * @param string|null               $aide          texte métier repris dans `_DICTIONNAIRE`
     * @param bool                      $pourcentage   PercentType fractionnel : l'écran montre 15, la colonne stocke 0.15
     */
    public function __construct(
        public readonly string $code,
        public readonly string $libelle,
        public readonly string $type,
        public readonly bool $obligatoire = false,
        public readonly bool $lectureSeule = false,
        public readonly array $choix = [],
        public readonly ?string $referenceCode = null,
        public readonly bool $referenceHorsPerimetre = false,
        public readonly ?string $formatExcel = null,
        public readonly ?string $aide = null,
        public readonly bool $pourcentage = false,
    ) {
    }

    /** La colonne porte-t-elle une liste fermée à proposer en validation Excel ? */
    public function aUneListe(): bool
    {
        return $this->type === self::TYPE_ENUM && $this->choix !== [];
    }

    /** Une colonne modifiable est une colonne que l'import relit. */
    public function estModifiable(): bool
    {
        return !$this->lectureSeule && !$this->referenceHorsPerimetre;
    }

    /**
     * Phrase du dictionnaire : ce que l'utilisateur doit savoir de cette colonne avant
     * d'y toucher. Construite ici pour que `_DICTIONNAIRE` n'ait aucune règle à lui.
     */
    public function noticeDictionnaire(): string
    {
        $morceaux = [];
        if ($this->lectureSeule) {
            $morceaux[] = 'Calculé par l\'application : exporté pour information, ignoré à l\'import.';
        } elseif ($this->referenceHorsPerimetre) {
            $morceaux[] = 'Renvoi vers une donnée hors du périmètre d\'échange : affiché pour information, ignoré à l\'import.';
        } elseif ($this->obligatoire) {
            $morceaux[] = 'Obligatoire.';
        }
        if ($this->type === self::TYPE_REFERENCE && $this->referenceCode !== null && !$this->referenceHorsPerimetre) {
            $morceaux[] = sprintf(
                'Renvoi vers la feuille « %s » : indiquez son identifiant (_uid), une référence lisible, ou un _ref de ce même fichier.',
                $this->referenceCode,
            );
        }
        if ($this->aUneListe()) {
            $morceaux[] = 'Valeurs acceptées : ' . implode(', ', array_map(
                static fn ($libelle, $code) => sprintf('%s (%s)', $libelle, $code),
                $this->choix,
                array_keys($this->choix),
            )) . '.';
        }
        if ($this->pourcentage) {
            // Le dépôt a unifié tous ses taux en POINTS ; cette colonne est l'exception
            // que le FormType impose encore, et le taire produirait des taux au centième.
            $morceaux[] = 'Taux exprimé en pourcentage (saisir 15 pour 15 %).';
        }
        if ($this->aide !== null) {
            $morceaux[] = $this->aide;
        }

        return implode(' ', $morceaux);
    }
}
