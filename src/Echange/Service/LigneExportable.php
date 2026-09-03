<?php

namespace App\Echange\Service;

use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Canevas\ColonneDEchange;
use App\Echange\Canevas\RessourceDEchange;
use App\Service\Workspace\ChampsObligatoiresInspector;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;

/**
 * TRADUIT une entité en ligne de classeur, et rien d'autre.
 *
 * La traduction inverse (ligne → entité) ne vit pas ici : elle passera par le circuit
 * d'écriture commun de l'espace de travail, qui porte déjà les droits, les champs
 * obligatoires et la validation. Ce service n'a donc qu'un sens, et c'est voulu — le
 * jour où les deux sens se répondent mal, on saura que le fautif est du côté qui écrit.
 *
 * TROIS RÈGLES DE CONVERSION, qui décident si l'aller-retour est fidèle :
 *
 *  1. UN RENVOI S'ÉCRIT « Ressource:id ». C'est la seule forme qui garantisse qu'un
 *     export réimporté sans modification ne change rien : deux clients peuvent porter
 *     le même nom, pas le même identifiant. Un humain reste libre de taper un nom pour
 *     une ligne NOUVELLE — la relecture accepte les deux.
 *  2. UNE DATE S'ÉCRIT EN DATE EXCEL NATIVE, jamais en texte. Un texte se relit
 *     différemment selon la locale du poste, et « 03/09/2026 » n'a pas le même sens à
 *     Paris et à Chicago.
 *  3. UN BOOLÉEN S'ÉCRIT « OUI » / « NON ». Ni VRAI/FAUX (qui se traduit tout seul
 *     selon la langue d'Excel), ni 1/0 (qu'on confond avec un montant).
 */
final class LigneExportable
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChampsObligatoiresInspector $inspecteur,
    ) {
    }

    /**
     * @return array<string, mixed> code de colonne => valeur prête pour la cellule
     */
    public function convertir(object $entite, RessourceDEchange $ressource): array
    {
        $meta = $this->em->getClassMetadata($ressource->fqcn);
        $identifiant = $meta->getIdentifierValues($entite)['id'] ?? null;

        $ligne = [
            CanevasDEchange::COL_UID    => $identifiant === null ? '' : $this->uid($ressource->code, (int) $identifiant),
            CanevasDEchange::COL_ACTION => '',
            CanevasDEchange::COL_REF    => '',
            // Une entité sans AuditableTrait n'a pas d'horodatage de modification : la
            // colonne reste vide, et le dictionnaire dit qu'aucun conflit n'y sera
            // détecté. Mieux vaut l'absence qu'une fausse promesse de sécurité.
            CanevasDEchange::COL_MODIFIE_LE => $this->dateExcel($this->horodatage($entite)),
        ];

        foreach ($ressource->colonnes as $colonne) {
            $ligne[$colonne->code] = $this->valeur($entite, $meta, $colonne, $ressource);
        }

        return $ligne;
    }

    /** Forme canonique d'un identifiant de ligne dans le classeur. */
    public function uid(string $codeRessource, int $id): string
    {
        return $codeRessource . ':' . $id;
    }

    /**
     * Décompose un `_uid`. Renvoie null si la forme n'est pas reconnue — un identifiant
     * mal formé est une erreur à signaler, jamais une valeur à deviner.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function lireUid(string $uid): ?array
    {
        if (!preg_match('/^([A-Za-z]+):(\d+)$/', trim($uid), $m)) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }

    private function valeur(object $entite, $meta, ColonneDEchange $colonne, RessourceDEchange $ressource): mixed
    {
        $brut = $this->lire($entite, $meta, $colonne->code);
        if ($brut === null) {
            return '';
        }

        if ($colonne->type === ColonneDEchange::TYPE_REFERENCE) {
            return $this->reference($brut, $colonne);
        }

        if ($brut instanceof \DateTimeInterface) {
            return $this->dateExcel($brut);
        }

        if ($colonne->type === ColonneDEchange::TYPE_BOOLEEN) {
            return $brut ? 'OUI' : 'NON';
        }

        if ($colonne->aUneListe()) {
            // On écrit le LIBELLÉ, pas le code : un fichier plein de 0 et de 2 ne se
            // relit pas. La comparaison au retour est faite sur une forme normalisée
            // (casse et accents ôtés), et accepte aussi le code brut.
            $cle = is_bool($brut) ? ($brut ? '1' : '0') : (is_int($brut) ? $brut : (string) $brut);

            return $colonne->choix[$cle] ?? $brut;
        }

        if (is_array($brut)) {
            // Champ multiple (tableau sérialisé) : une valeur par ligne serait
            // ingérable en tableur, on les sépare par un point-virgule.
            return implode('; ', array_map(static fn ($v) => (string) $v, $brut));
        }

        if (is_object($brut)) {
            return method_exists($brut, '__toString') ? (string) $brut : '';
        }

        return $brut;
    }

    /** Renvoi vers une autre ligne : « Ressource:id », ou le libellé si la cible est hors périmètre. */
    private function reference(mixed $cible, ColonneDEchange $colonne): string
    {
        if (!is_object($cible)) {
            return (string) $cible;
        }

        if ($colonne->referenceHorsPerimetre) {
            // Hors périmètre : la colonne est descriptive et ne sera pas relue. On y
            // met ce qui se lit le mieux, pas ce qui se parse le mieux.
            return $this->libelleLisible($cible);
        }

        try {
            $meta = $this->em->getClassMetadata($this->classeReelle($cible));
            $id = $meta->getIdentifierValues($cible)['id'] ?? null;
        } catch (\Throwable) {
            return '';
        }

        return $id === null ? '' : $this->uid($colonne->referenceCode ?? '', (int) $id);
    }

    private function libelleLisible(object $entite): string
    {
        foreach (['getNom', 'getNomComplet', 'getLibelle', 'getEmail', 'getCode', 'getReference'] as $getter) {
            if (!method_exists($entite, $getter)) {
                continue;
            }
            $valeur = $entite->{$getter}();
            if (is_string($valeur) && trim($valeur) !== '') {
                return $valeur;
            }
        }

        return method_exists($entite, '__toString') ? (string) $entite : '';
    }

    /**
     * Valeur d'un champ ou d'une association, lue par les métadonnées Doctrine plutôt
     * que par un getter : toutes les entités n'exposent pas leurs champs de la même
     * façon, et la réflexion, elle, ne se trompe jamais de nom.
     */
    private function lire(object $entite, $meta, string $champ): mixed
    {
        try {
            if ($meta->hasField($champ) || $meta->hasAssociation($champ)) {
                return $meta->getFieldValue($entite, $champ);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function horodatage(object $entite): ?\DateTimeInterface
    {
        if (!method_exists($entite, 'getUpdatedAt')) {
            return null;
        }

        $valeur = $entite->getUpdatedAt();
        if ($valeur instanceof \DateTimeInterface) {
            return $valeur;
        }

        // Une ligne jamais rééditée n'a pas d'updatedAt : sa date de création fait foi
        // pour la détection de conflit, sans quoi toute modification concurrente sur
        // une ligne neuve passerait inaperçue.
        return method_exists($entite, 'getCreatedAt') && $entite->getCreatedAt() instanceof \DateTimeInterface
            ? $entite->getCreatedAt()
            : null;
    }

    private function dateExcel(?\DateTimeInterface $date): string|float
    {
        return $date === null ? '' : DateExcel::PHPToExcel($date);
    }

    /** Classe réelle derrière un éventuel proxy Doctrine. */
    private function classeReelle(object $entite): string
    {
        $classe = $entite::class;

        return str_contains($classe, '\\Proxies\\') || str_starts_with($classe, 'Proxies\\')
            ? get_parent_class($entite) ?: $classe
            : $classe;
    }
}
