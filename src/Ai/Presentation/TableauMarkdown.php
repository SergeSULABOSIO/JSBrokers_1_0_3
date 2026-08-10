<?php

namespace App\Ai\Presentation;

use App\Services\ServiceNombres;

/**
 * LE TABLEAU DE CHAT, RENDU UNE SEULE FOIS EN PHP — et donc identique quel que soit
 * celui qui l'écrit.
 *
 * D'OÙ VIENT CE CODE. Il vivait dans RepliPrecis::tableau(), où il ne servait qu'au
 * REPLI : le cas où le modèle réclame un outil de plus au lieu d'écrire. C'était son
 * seul défaut — être excellent et inaccessible. Le tableau que Ket rédige elle-même,
 * lui, n'obéissait à aucune règle : « colonnes courtes, 4-5 maximum » était tout ce
 * que le prompt en disait.
 *
 * CE QUE LE DÉPLACEMENT APPORTE, au-delà du partage :
 *  - l'ALIGNEMENT. Une colonne de montants s'écrit « ---: » en GFM. Personne ne
 *    l'écrivait, et le navigateur l'aurait de toute façon jeté (cf. le renderer
 *    assistant-markdown-render.js, corrigé dans le même lot) ;
 *  - le FORMATAGE PAR RÔLE au lieu d'une heuristique sur le type PHP : un identifiant
 *    reste brut, un montant porte sa monnaie, une date se lit en jj/mm/aaaa ;
 *  - ServiceNombres comme source unique du nombre écrit, au lieu d'un
 *    number_format(',', ' ') codé en dur qui ignorait la langue active.
 *
 * CE QUE CETTE CLASSE NE FAIT PAS. Elle rend un TABLEAU, pas un message : ni phrase
 * d'introduction, ni mention de troncature, ni rappel de périmètre. Ces éléments
 * appartiennent à celui qui compose la réponse (RepliPrecis), qui seul sait ce que
 * l'utilisateur a demandé.
 */
final class TableauMarkdown
{
    /** Au-delà, un tableau cesse d'être lisible dans une bulle de chat. */
    public const MAX_LIGNES = 25;
    public const MAX_COLONNES = 7;

    /** Cellule vide : un tiret cadratin se lit, une case blanche laisse douter. */
    private const VIDE = '—';

    public function __construct(
        private readonly ServiceNombres $nombres,
    ) {
    }

    /**
     * Le tableau en markdown GFM : en-têtes, ligne d'alignement, corps, puis la ligne
     * de TOTAUX quand il y a quelque chose à additionner. Null quand il n'y a rien de
     * présentable — mieux vaut pas de tableau qu'un en-tête sans lignes.
     *
     * @param list<array<string, mixed>>                                              $lignes
     * @param array{colonnes?: array<string, string>, totaliser?: list<string>}|null  $presentation
     *        déclaration de l'outil (cf. Colonnes::de) ; à défaut les rôles sont déduits
     */
    public function rendre(array $lignes, ?array $presentation = null): ?string
    {
        $lignes = array_values(array_filter($lignes, 'is_array'));
        if ($lignes === []) {
            return null;
        }

        $roles = $this->roles($lignes, $presentation);
        if ($roles === []) {
            return null;
        }

        $colonnes = array_keys($roles);
        $affichees = array_slice($lignes, 0, self::MAX_LIGNES);

        $corps = [];
        foreach ($affichees as $ligne) {
            $cellules = [];
            foreach ($roles as $colonne => $role) {
                $cellules[] = $this->cellule($role, $ligne[$colonne] ?? null);
            }
            $corps[] = $this->ligne($cellules);
        }

        $texte = $this->ligne(array_map([$this, 'entete'], $colonnes)) . "\n"
            . $this->ligne(array_map(
                static fn (string $role) => Colonnes::estAlignéeADroite($role) ? '---:' : '---',
                array_values($roles),
            )) . "\n"
            . implode("\n", $corps);

        $totaux = $this->ligneDeTotaux($roles, $lignes, $presentation);

        return $totaux === null ? $texte : $texte . "\n" . $totaux;
    }

    /** Nombre de lignes réellement rendues : ce qui permet d'annoncer une troncature. */
    public function lignesRendues(array $lignes): int
    {
        return min(\count(array_filter($lignes, 'is_array')), self::MAX_LIGNES);
    }

    // ─────────────────────────────── Colonnes et rôles ──────────────────────────────

    /**
     * Les colonnes retenues et leur rôle, DANS L'ORDRE d'affichage.
     *
     * Quand l'outil a déclaré sa présentation, c'est elle qui commande — il a déjà
     * choisi ce qui compte, et le rendu n'est pas le bon endroit pour en juger
     * autrement. Sinon on déduit, en écartant les valeurs structurées : un
     * sous-tableau n'a pas de rendu de cellule honnête.
     *
     * @param list<array<string, mixed>> $lignes
     *
     * @return array<string, string> clé => rôle
     */
    private function roles(array $lignes, ?array $presentation): array
    {
        $declarees = $presentation['colonnes'] ?? null;
        if (\is_array($declarees) && $declarees !== []) {
            $roles = [];
            foreach ($declarees as $cle => $role) {
                $roles[(string) $cle] = \in_array($role, Colonnes::ROLES, true)
                    ? (string) $role
                    : Colonnes::TEXTE;
                if (\count($roles) >= self::MAX_COLONNES) {
                    break;
                }
            }

            return $roles;
        }

        $roles = [];
        foreach ($lignes as $ligne) {
            foreach ($ligne as $cle => $valeur) {
                if (!\is_string($cle) || isset($roles[$cle])) {
                    continue;
                }
                if ($valeur !== null && !\is_scalar($valeur)) {
                    continue;
                }
                $roles[$cle] = Colonnes::roleDeduit($cle, $valeur);
                if (\count($roles) >= self::MAX_COLONNES) {
                    return $roles;
                }
            }
        }

        return $roles;
    }

    /**
     * La dernière ligne : le total de chaque colonne sommable.
     *
     * Quand l'outil a déclaré « totaliser », on s'y tient strictement — il sait ce que
     * ses colonnes signifient. Sinon on additionne toute colonne entièrement numérique,
     * identifiants exclus : la somme des numéros de tranche ne veut rien dire.
     *
     * Null quand rien ne s'additionne : mieux vaut un tableau sans totaux qu'une ligne
     * vide qui promet un chiffre.
     *
     * @param array<string, string>      $roles
     * @param list<array<string, mixed>> $lignes
     */
    private function ligneDeTotaux(array $roles, array $lignes, ?array $presentation): ?string
    {
        $declares = $presentation['totaliser'] ?? null;
        $aSommer = \is_array($declares)
            ? array_values(array_intersect(array_map('strval', $declares), array_keys($roles)))
            : array_keys(array_filter(
                $roles,
                static fn (string $role) => \in_array($role, Colonnes::ROLES_SOMMABLES, true),
            ));

        $sommes = [];
        foreach ($aSommer as $colonne) {
            $somme = 0.0;
            $vue = false;
            foreach ($lignes as $ligne) {
                $valeur = $ligne[$colonne] ?? null;
                if ($valeur === null || $valeur === '') {
                    continue;
                }
                if (!\is_int($valeur) && !\is_float($valeur)) {
                    continue 2; // colonne non numérique : rien à additionner
                }
                $somme += (float) $valeur;
                $vue = true;
            }
            if ($vue) {
                $sommes[$colonne] = $somme;
            }
        }

        if ($sommes === []) {
            return null;
        }

        $cellules = [];
        $premiere = true;
        foreach ($roles as $colonne => $role) {
            $cellules[] = match (true) {
                isset($sommes[$colonne]) => '**' . $this->valeurNumerique($role, $sommes[$colonne]) . '**',
                $premiere                => '**TOTAL**',
                default                  => '',
            };
            $premiere = false;
        }

        return $this->ligne($cellules);
    }

    // ─────────────────────────────── Rendu d'une cellule ────────────────────────────

    private function cellule(string $role, mixed $valeur): string
    {
        if ($valeur === null || $valeur === '' || (\is_array($valeur) && $valeur === [])) {
            return self::VIDE;
        }
        if (\is_bool($valeur)) {
            return $valeur ? 'oui' : 'non';
        }
        // Une valeur structurée n'a pas de rendu de cellule honnête : l'outil qui la
        // déclare comme colonne doit l'aplatir lui-même.
        if (!\is_scalar($valeur)) {
            return self::VIDE;
        }

        return match ($role) {
            Colonnes::IDENTIFIANT => $this->texte((string) $valeur),
            Colonnes::DATE        => $this->date((string) $valeur),
            Colonnes::MONTANT,
            Colonnes::NOMBRE,
            Colonnes::POURCENTAGE => \is_int($valeur) || \is_float($valeur)
                ? $this->valeurNumerique($role, (float) $valeur)
                : $this->texte((string) $valeur),
            default               => $this->texte((string) $valeur),
        };
    }

    /**
     * Un nombre écrit selon la langue active (ServiceNombres, source unique du projet),
     * avec son unité quand le rôle en porte une.
     *
     * UN MONTANT PORTE TOUJOURS SES DEUX DÉCIMALES. C'est la seule exception à la règle
     * générale, et elle est visuelle : dans une colonne alignée à droite, « 0 $ » à côté
     * de « 3 195,16 $ » décale la virgule et ruine précisément ce que l'alignement
     * cherchait à obtenir. Pour un compte ou un nombre de jours, en revanche, les
     * décimales ne s'affichent que si elles existent — « 25 » et non « 25,00 ».
     */
    private function valeurNumerique(string $role, float $valeur): string
    {
        if ($role === Colonnes::MONTANT) {
            return $this->nombres->formatMontant($valeur, 2);
        }

        $decimales = abs($valeur - round($valeur)) < 0.005 ? 0 : 2;

        return $role === Colonnes::POURCENTAGE
            ? $this->nombres->format($valeur, $decimales) . ' %'
            : $this->nombres->format($valeur, $decimales);
    }

    /** Une date se lit jj/mm/aaaa. L'ISO est un format d'échange, pas d'affichage. */
    private function date(string $valeur): string
    {
        try {
            return (new \DateTimeImmutable($valeur))->format('d/m/Y');
        } catch (\Throwable) {
            return $this->texte($valeur);
        }
    }

    /** Le pipe est le séparateur de colonnes : non échappé, il casse la ligne. */
    private function texte(string $valeur): string
    {
        $valeur = trim(str_replace('|', '/', $valeur));

        return $valeur === '' ? self::VIDE : $valeur;
    }

    /**
     * « soldePrime » → « Solde prime » : un en-tête se lit, il ne se décode pas.
     *
     * Les clés d'un outil sont sans accent (ce sont des identifiants) ; un en-tête, non.
     * « Reference » et « Echeance » dans un produit francophone se remarquent, et la
     * dérivation mécanique ne peut pas les restituer. D'où ce lexique des noms de colonne
     * récurrents du métier — la dérivation reste le cas général.
     */
    private const ENTETES = [
        'reference'       => 'Référence',
        'echeance'        => 'Échéance',
        'periode'         => 'Période',
        'numero'          => 'Numéro',
        'libelle'         => 'Libellé',
        'assure'          => 'Assuré',
        'beneficiaire'    => 'Bénéficiaire',
        'intermediaire'   => 'Intermédiaire',
        'preuves'         => 'Pièces',
        'joursRetard'     => 'Jours de retard',
        'joursRestants'   => 'Jours restants',
        'soldeCommission' => 'Solde commission',
        'commissionTtc'   => 'Commission TTC',
        'commissionsTtc'  => 'Commissions TTC',
        'ht'              => 'HT',
        'ttc'             => 'TTC',
        'tvaDeductible'   => 'TVA déductible',
        'tauxTva'         => 'Taux de TVA',
        'partMarche'      => 'Part de marché',
        'ratioSP'         => 'Ratio S/P',
        'soldeDebit'      => 'Solde débit',
        'soldeCredit'     => 'Solde crédit',
        'nbPolices'       => 'Polices',
    ];

    private function entete(string $colonne): string
    {
        if (isset(self::ENTETES[$colonne])) {
            return self::ENTETES[$colonne];
        }

        $mots = preg_replace('/(?<!^)[A-Z]/', ' $0', $colonne) ?? $colonne;

        return ucfirst(mb_strtolower(trim($mots)));
    }

    /** @param list<string> $cellules */
    private function ligne(array $cellules): string
    {
        return '| ' . implode(' | ', $cellules) . ' |';
    }
}
