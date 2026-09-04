<?php

namespace App\Echange\Service;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\NormaliseurDeDates;
use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Canevas\ColonneDEchange;
use App\Echange\Canevas\RessourceDEchange;
use App\Echange\Classeur\LigneLue;
use App\Entity\Entreprise;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;

/**
 * TRADUIT une ligne de classeur en OPÉRATION D'ÉCRITURE.
 *
 * C'est le point de jonction avec l'existant, et son seul intérêt est de s'y raccorder
 * sans rien réinventer : la ligne devient une MutationOperation, exactement celle que
 * l'assistant produit et que le circuit d'écriture de l'espace de travail sait
 * analyser à blanc puis exécuter. Droits, champs obligatoires, validation par le
 * formulaire, liens protégés à la suppression : tout cela est déjà écrit ailleurs, et
 * le redire ici serait s'engager à le maintenir deux fois.
 *
 * Ce service ne décide donc que de ce que le tableur seul peut dire : quelle action,
 * quelle cible, et comment une cellule Excel devient une valeur PHP.
 */
final class TraducteurDeLigne
{
    public function __construct(
        private readonly ResolveurDeRenvois $resolveur,
        private readonly NormaliseurDeDates $normaliseurDeDates,
    ) {
    }

    /**
     * Action déduite d'une ligne, selon les règles du format.
     *
     * Une SUPPRESSION ne se déduit jamais : elle doit avoir été écrite. Un identifiant
     * effacé par mégarde en triant un fichier ne doit pas pouvoir vider une feuille.
     *
     * @return array{0: string, 1: ?int, 2: ?Anomalie} action, id cible, anomalie éventuelle
     */
    public function action(LigneLue $ligne, RessourceDEchange $ressource): array
    {
        $brut = mb_strtoupper($ligne->texte(CanevasDEchange::COL_ACTION));
        $uid = $ligne->texte(CanevasDEchange::COL_UID);

        $id = null;
        if ($uid !== '') {
            $decompose = LigneExportable::lireUid($uid);
            if ($decompose === null || $decompose[0] !== $ressource->code) {
                return ['', null, Anomalie::erreur(
                    Anomalie::UID_INVALIDE,
                    sprintf(
                        'L\'identifiant « %s » n\'a pas la forme attendue pour cette feuille (« %s:numéro »). '
                        . 'Laissez-le tel qu\'il a été exporté, ou videz-le pour créer une nouvelle ligne.',
                        $uid,
                        $ressource->code,
                    ),
                    $ligne->feuille,
                    $ligne->numero,
                    $ligne->colonne(CanevasDEchange::COL_UID),
                )];
            }
            $id = $decompose[1];
        }

        if ($brut === '') {
            // Déduction : identifiant vide = création, identifiant rempli = mise à jour.
            return [$id === null ? MutationOperation::OP_CREATE : MutationOperation::OP_EDIT, $id, null];
        }

        return match ($brut) {
            CanevasDEchange::ACTION_CREER => [MutationOperation::OP_CREATE, null, null],
            CanevasDEchange::ACTION_MAJ => $id === null
                ? ['', null, Anomalie::erreur(
                    Anomalie::ACTION_INVALIDE,
                    sprintf(
                        'Action « %s » demandée sans identifiant : on ne peut pas modifier une ligne sans dire laquelle. '
                        . 'Renseignez la colonne %s, ou laissez l\'action vide pour créer.',
                        CanevasDEchange::ACTION_MAJ,
                        CanevasDEchange::COL_UID,
                    ),
                    $ligne->feuille,
                    $ligne->numero,
                    $ligne->colonne(CanevasDEchange::COL_ACTION),
                )]
                : [MutationOperation::OP_EDIT, $id, null],
            CanevasDEchange::ACTION_SUPPRIMER => $id === null
                ? ['', null, Anomalie::erreur(
                    Anomalie::ACTION_INVALIDE,
                    sprintf(
                        'Suppression demandée sans identifiant : on ne peut pas supprimer une ligne sans dire laquelle. '
                        . 'Renseignez la colonne %s.',
                        CanevasDEchange::COL_UID,
                    ),
                    $ligne->feuille,
                    $ligne->numero,
                    $ligne->colonne(CanevasDEchange::COL_ACTION),
                )]
                : [MutationOperation::OP_DELETE, $id, null],
            default => ['', null, Anomalie::erreur(
                Anomalie::ACTION_INVALIDE,
                sprintf(
                    '« %s » n\'est pas une action connue. Valeurs acceptées : %s, %s, %s — ou vide, '
                    . 'auquel cas l\'action se déduit de l\'identifiant.',
                    $brut,
                    CanevasDEchange::ACTION_CREER,
                    CanevasDEchange::ACTION_MAJ,
                    CanevasDEchange::ACTION_SUPPRIMER,
                ),
                $ligne->feuille,
                $ligne->numero,
                $ligne->colonne(CanevasDEchange::COL_ACTION),
            )],
        };
    }

    /**
     * Champs d'une ligne, convertis et renvois résolus.
     *
     * @param Anomalie[] $anomalies (par référence) — tout ce qui empêche d'écrire
     *
     * @return array<string, scalar|array|null>
     */
    public function champs(LigneLue $ligne, RessourceDEchange $ressource, Entreprise $entreprise, array &$anomalies): array
    {
        $champs = [];

        foreach ($ressource->colonnes as $colonne) {
            // Un champ calculé, ou absent du formulaire, est exporté pour information :
            // le relire écraserait une valeur que l'application maintient elle-même.
            if (!$colonne->estModifiable()) {
                continue;
            }
            // Un renvoi différé est posé par une SECONDE passe, après création de sa
            // cible : l'écrire maintenant désignerait une ligne qui n'existe pas encore.
            if ($ressource->renvoiEstDiffere($colonne->code)) {
                continue;
            }
            if (!array_key_exists($colonne->code, $ligne->valeurs)) {
                // Colonne absente du fichier : on ne touche pas au champ. C'est ce qui
                // permet d'importer une feuille réduite à quelques colonnes utiles.
                continue;
            }

            $valeur = $this->convertir($ligne, $colonne, $ressource, $entreprise, $anomalies);
            if ($valeur !== self::IGNORER) {
                $champs[$colonne->code] = $valeur;
            }
        }

        return $champs;
    }

    /** Sentinelle : la colonne ne doit pas être écrite du tout. */
    private const IGNORER = "\0__ignorer__\0";

    /** @param Anomalie[] $anomalies */
    private function convertir(
        LigneLue $ligne,
        ColonneDEchange $colonne,
        RessourceDEchange $ressource,
        Entreprise $entreprise,
        array &$anomalies,
    ): mixed {
        $brut = $ligne->valeur($colonne->code);

        if ($colonne->type === ColonneDEchange::TYPE_REFERENCE) {
            $renvoi = $this->resolveur->resoudre($brut, $colonne, $entreprise);
            if ($renvoi->estRefus()) {
                $anomalies[] = Anomalie::erreur(
                    $renvoi->ambigu ? Anomalie::RENVOI_AMBIGU : Anomalie::RENVOI_IRRESOLU,
                    sprintf('%s : %s', $colonne->libelle, $renvoi->motif),
                    $ligne->feuille,
                    $ligne->numero,
                    $ligne->colonne($colonne->code),
                );

                return self::IGNORER;
            }

            return $renvoi->statut === 'ignore' ? self::IGNORER : $renvoi->valeur;
        }

        $texte = is_scalar($brut) ? trim((string) $brut) : '';
        if ($brut === null || $texte === '') {
            return null;
        }

        return match ($colonne->type) {
            ColonneDEchange::TYPE_BOOLEEN  => $this->booleen($texte),
            ColonneDEchange::TYPE_DATE,
            ColonneDEchange::TYPE_DATETIME => $this->date($brut, $texte, $ligne, $colonne, $anomalies),
            ColonneDEchange::TYPE_ENTIER   => $this->entier($texte, $ligne, $colonne, $anomalies),
            ColonneDEchange::TYPE_DECIMAL  => $this->decimal($texte, $ligne, $colonne, $anomalies),
            ColonneDEchange::TYPE_ENUM     => $this->choix($texte, $ligne, $colonne, $anomalies),
            default                        => $texte,
        };
    }

    /**
     * OUI/NON, mais aussi ce qu'un tableur peut avoir mis à la place : VRAI/FAUX selon
     * sa langue, 1/0 selon son format, TRUE si le fichier a transité par un outil
     * anglophone. Refuser tout cela serait techniquement défendable et pratiquement
     * pénible.
     */
    private function booleen(string $texte): bool
    {
        return in_array(mb_strtolower($texte), ['oui', 'o', 'vrai', 'true', 'yes', 'y', '1', 'x'], true);
    }

    /**
     * Une cellule de date devient la chaîne qu'attend le FORMULAIRE de l'entité.
     *
     * Le format de sortie n'est PAS décidé ici. Il dépend du type Doctrine du champ
     * (une date nue est refusée là où un horodatage est attendu), et cette connaissance
     * vit déjà dans NormaliseurDeDates — écrite à la suite d'un incident où une date
     * française parfaitement valide faisait échouer un enregistrement sans que rien ne
     * dise pourquoi. La redire ici serait s'engager à la corriger deux fois.
     *
     * Notre part se limite à ce que le normaliseur ne peut pas savoir : une cellule
     * Excel native porte un NOMBRE — des jours écoulés depuis 1900 — et non un texte.
     *
     * @param Anomalie[] $anomalies
     */
    private function date(mixed $brut, string $texte, LigneLue $ligne, ColonneDEchange $colonne, array &$anomalies): ?string
    {
        $typeDoctrine = $colonne->type === ColonneDEchange::TYPE_DATETIME ? 'datetime' : 'date';
        $sortie = $typeDoctrine === 'datetime' ? 'Y-m-dTH:i' : 'Y-m-d';
        $sortie = str_replace('T', '\\T', $sortie);

        // Date Excel NATIVE : le cas nominal, puisque c'est ce que l'export écrit.
        if (is_numeric($brut)) {
            try {
                $texte = DateExcel::excelToDateTimeObject((float) $brut)->format('d/m/Y H:i');
            } catch (\Throwable) {
                // Un nombre qui n'est pas une date : on retombe sur le texte d'origine,
                // que le normaliseur refusera proprement.
            }
        }

        $normalise = $this->normaliseurDeDates->normaliser($texte, $typeDoctrine);

        // Le normaliseur rend la valeur d'ORIGINE quand il ne reconnaît rien : c'est
        // ce qui nous dit qu'il faut signaler, plutôt que d'inventer une date.
        if (!is_string($normalise) || (\DateTimeImmutable::createFromFormat($sortie, $normalise) === false)) {
            $anomalies[] = Anomalie::erreur(
                Anomalie::VALEUR_INVALIDE,
                sprintf(
                    '%s : « %s » n\'est pas une date lisible. Saisissez une date (jj/mm/aaaa) plutôt qu\'un texte.',
                    $colonne->libelle,
                    $texte,
                ),
                $ligne->feuille,
                $ligne->numero,
                $ligne->colonne($colonne->code),
            );

            return null;
        }

        return $normalise;
    }

    /** @param Anomalie[] $anomalies */
    private function entier(string $texte, LigneLue $ligne, ColonneDEchange $colonne, array &$anomalies): ?int
    {
        $propre = $this->nettoyerNombre($texte);
        if (!is_numeric($propre)) {
            $anomalies[] = $this->anomalieNombre($texte, $ligne, $colonne, 'un nombre entier');

            return null;
        }

        return (int) round((float) $propre);
    }

    /** @param Anomalie[] $anomalies */
    private function decimal(string $texte, LigneLue $ligne, ColonneDEchange $colonne, array &$anomalies): ?float
    {
        $propre = $this->nettoyerNombre($texte);
        if (!is_numeric($propre)) {
            $anomalies[] = $this->anomalieNombre($texte, $ligne, $colonne, 'un montant');

            return null;
        }

        return (float) $propre;
    }

    /**
     * Nombre saisi à la main : séparateurs de milliers, virgule décimale, espaces
     * insécables collés par un copier-coller, symbole monétaire.
     */
    private function nettoyerNombre(string $texte): string
    {
        $propre = str_replace(["\u{202F}", "\u{00A0}", ' ', "'"], '', $texte);
        $propre = (string) preg_replace('/[^0-9,.\-]/', '', $propre);

        // Virgule décimale française : « 1234,50 ». Si le texte porte les deux
        // séparateurs, le point est celui des milliers (« 1.234,50 »).
        if (str_contains($propre, ',')) {
            $propre = str_replace('.', '', $propre);
            $propre = str_replace(',', '.', $propre);
        }

        return $propre;
    }

    private function anomalieNombre(string $texte, LigneLue $ligne, ColonneDEchange $colonne, string $attendu): Anomalie
    {
        return Anomalie::erreur(
            Anomalie::VALEUR_INVALIDE,
            sprintf('%s : « %s » n\'est pas %s.', $colonne->libelle, $texte, $attendu),
            $ligne->feuille,
            $ligne->numero,
            $ligne->colonne($colonne->code),
        );
    }

    /**
     * Valeur d'une liste fermée. L'export écrit le LIBELLÉ ; on accepte aussi le CODE,
     * qu'un utilisateur méthodique aura pu recopier depuis le dictionnaire.
     *
     * @param Anomalie[] $anomalies
     */
    private function choix(string $texte, LigneLue $ligne, ColonneDEchange $colonne, array &$anomalies): mixed
    {
        // Champ à choix MULTIPLES : la cellule porte plusieurs libellés séparés par un
        // point-virgule, tels que l'export les a écrits. Les relire comme UNE valeur
        // unique ne trouverait jamais de correspondance, et l'utilisateur verrait une
        // erreur sur une cellule qu'il n'a pas touchée.
        if ($colonne->multiple) {
            $valeurs = [];
            foreach (explode(LigneExportable::SEPARATEUR_MULTIPLE, $texte) as $morceau) {
                $morceau = trim($morceau);
                if ($morceau === '') {
                    continue;
                }
                $code = $this->codeDuChoix($morceau, $colonne);
                if ($code === null) {
                    $anomalies[] = $this->anomalieChoix($morceau, $ligne, $colonne);

                    return null;
                }
                $valeurs[] = $code;
            }

            return $valeurs;
        }

        $code = $this->codeDuChoix($texte, $colonne);
        if ($code === null) {
            $anomalies[] = $this->anomalieChoix($texte, $ligne, $colonne);
        }

        return $code;
    }

    /**
     * Code correspondant à une valeur saisie : par LIBELLÉ d'abord — c'est ce que
     * l'export écrit et ce qu'un humain recopie — puis par code brut, qu'un utilisateur
     * méthodique aura pu relever dans le dictionnaire.
     */
    private function codeDuChoix(string $texte, ColonneDEchange $colonne): int|string|null
    {
        $cible = $this->comparable($texte);

        foreach ($colonne->choix as $code => $libelle) {
            if ($this->comparable((string) $libelle) === $cible) {
                return $code;
            }
        }
        foreach (array_keys($colonne->choix) as $code) {
            if ($this->comparable((string) $code) === $cible) {
                return $code;
            }
        }

        return null;
    }

    private function anomalieChoix(string $texte, LigneLue $ligne, ColonneDEchange $colonne): Anomalie
    {
        return Anomalie::erreur(
            Anomalie::VALEUR_INVALIDE,
            sprintf(
                '%s : « %s » n\'est pas une valeur acceptée. Choisissez parmi : %s.',
                $colonne->libelle,
                $texte,
                implode(', ', array_values($colonne->choix)),
            ),
            $ligne->feuille,
            $ligne->numero,
            $ligne->colonne($colonne->code),
        );
    }

    private function comparable(string $texte): string
    {
        static $accents = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        ];

        $texte = strtr(mb_strtolower(trim($texte)), $accents);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $texte));
    }
}
