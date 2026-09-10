<?php

namespace App\Echange\Service;

use App\Echange\Classeur\LigneLue;
use App\Echange\Etat\ColonneEtat;
use App\Service\Workspace\ChampsObligatoiresInspector;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * TRADUIT LE VERDICT DU CIRCUIT D'ÉCRITURE en reproche qu'un courtier peut lire.
 *
 * ── CE QUI EST EN JEU ───────────────────────────────────────────────────────────────
 * ⚠ CEUX QUI REPRENNENT LEURS DONNÉES NE SONT PAS DES INFORMATICIENS. Le rapport disait :
 *
 *     « Informations manquantes ou invalides : gestionnaire (Relation obligatoire à
 *       préciser (identifiant).) »
 *
 * Trois mots sur quatre appartiennent au modèle et non au métier : « gestionnaire » est un
 * nom de propriété, « relation » et « identifiant » décrivent la base de données. Rien
 * là-dedans ne dit à un courtier QUELLE CASE de son classeur remplir. Il ferme le fichier.
 *
 * ── LA RÈGLE : PARLER DU CLASSEUR, JAMAIS DU MODÈLE ─────────────────────────────────
 * L'utilisateur n'a sous les yeux qu'une chose : sa feuille de calcul, avec ses colonnes
 * nommées et ses lignes numérotées. Un reproche utile désigne donc une CELLULE — une
 * colonne, une ligne — et dit ce qu'il faut y écrire.
 *
 * Quand le champ qui manque a une colonne au classeur, on la nomme et on la situe : la
 * cellule fautive devient trouvable, et le classeur annoté peut la surligner.
 *
 * Quand il n'en a pas — l'application exige quelque chose que le format ne transporte
 * pas —, le dire franchement vaut mieux que de citer un nom de propriété : on nomme
 * l'information en clair et on indique où la régler.
 *
 * ⚠ CETTE TRADUCTION EST PARTAGÉE. Deux voies mènent au même circuit d'écriture ; en
 * écrire deux versions, ce serait promettre que la même faute se dit de la même façon des
 * deux côtés, et manquer à cette promesse au premier message retouché.
 */
final class DiagnosticEnAnomalie
{
    public function __construct(
        private readonly ChampsObligatoiresInspector $inspecteur,
        private readonly WorkspaceAccessResolver $accessResolver,
    ) {
    }

    /**
     * @param array<string, mixed>       $diagnostic rendu par `WorkspaceMutationService::analyserOperation()`
     * @param array<string, ColonneEtat> $colonnes   le catalogue du classeur, pour retrouver les cellules
     */
    public function signaler(
        array $diagnostic,
        LigneLue $ligne,
        string $entite,
        string $code,
        RapportDeControle $rapport,
        array $colonnes = [],
    ): void {
        $libelle = $this->libelleDeLEntite($entite);
        $cellule = null;

        $message = match ($diagnostic['statut']) {
            'hors_perimetre' => sprintf(
                'Vous n\'avez pas le droit de créer ou de modifier « %s ». Demandez ce droit à '
                . 'l\'administrateur de votre cabinet, ou retirez cette ligne du fichier.',
                $libelle,
            ),
            'introuvable' => sprintf(
                'Aucun élément « %s » ne porte cet identifiant dans votre cabinet. Il a peut-être '
                . 'été supprimé depuis votre export : effacez l\'identifiant de cette ligne pour '
                . 'la reprendre comme une nouveauté.',
                $libelle,
            ),
            'bloque' => implode(' ', $diagnostic['impacts'] ?: ['Cette opération est impossible.']),
            default => $this->reproche($diagnostic['manquants'] ?? [], $entite, $libelle, $ligne, $colonnes, $cellule),
        };

        $rapport->ajouter(Anomalie::erreur(
            match ($diagnostic['statut']) {
                'hors_perimetre' => Anomalie::DROIT_INSUFFISANT,
                'introuvable' => Anomalie::LIGNE_INTROUVABLE,
                'bloque' => Anomalie::SUPPRESSION_BLOQUEE,
                default => Anomalie::CHAMP_OBLIGATOIRE,
            },
            $message,
            $ligne->feuille,
            $ligne->numero,
            // ⚠ LA COLONNE FAIT LA MOITIÉ DU TRAVAIL. Sans elle, « ligne 2 » oblige à
            // parcourir soixante colonnes à la main — et c'est elle qui permet au classeur
            // annoté de surligner la bonne cellule.
            $cellule,
        ));
        $rapport->compterErreur($code);
    }

    /**
     * CE QU'IL FAUT ÉCRIRE, ET DANS QUELLE CASE.
     *
     * @param array<string, string[]>    $manquants nom de propriété => messages du validateur
     * @param array<string, ColonneEtat> $colonnes
     * @param string|null                $cellule   rempli de la lettre de colonne, si on la trouve
     */
    private function reproche(
        array $manquants,
        string $entite,
        string $libelleEntite,
        LigneLue $ligne,
        array $colonnes,
        ?string &$cellule,
    ): string {
        if ($manquants === []) {
            return sprintf('Cette ligne ne peut pas créer « %s » en l\'état.', $libelleEntite);
        }

        $auClasseur = [];
        $horsClasseur = [];

        foreach (array_keys($manquants) as $champ) {
            $code = $this->codeDeLaColonne($entite, (string) $champ, $colonnes);

            if ($code !== null) {
                $auClasseur[] = sprintf('« %s »', $colonnes[$code]->libelle);
                // La première cellule trouvée porte l'anomalie : c'est celle que le
                // classeur annoté surlignera.
                $cellule ??= $ligne->colonne($code);
                continue;
            }

            $horsClasseur[] = sprintf('« %s »', $this->libelleDuChamp($entite, (string) $champ));
        }

        $phrases = [];

        if ($auClasseur !== []) {
            // ⚠ ON DIT QUOI FAIRE, PAS CE QUI MANQUE. « champ obligatoire » constate ;
            // « remplissez cette colonne » se traduit en geste.
            $phrases[] = sprintf(
                'Remplissez %s %s : sans %s, « %s » ne peut pas être repris.',
                count($auClasseur) > 1 ? 'les colonnes' : 'la colonne',
                $this->enumerer($auClasseur),
                count($auClasseur) > 1 ? 'elles' : 'elle',
                $libelleEntite,
            );
        }

        if ($horsClasseur !== []) {
            // Le classeur ne porte pas cette information : la réclamer dans une colonne
            // qui n'existe pas serait envoyer l'utilisateur chercher une case introuvable.
            $phrases[] = sprintf(
                'Pour créer « %s », l\'application demande aussi %s, que le classeur de reprise ne '
                . 'transporte pas. Créez cet élément depuis sa propre rubrique, puis redéposez le '
                . 'fichier — ou laissez la colonne correspondante vide pour ne pas le créer ici.',
                $libelleEntite,
                $this->enumerer($horsClasseur),
            );
        }

        return implode(' ', $phrases);
    }

    /**
     * Le code de la colonne du classeur qui ÉCRIT ce champ, s'il y en a une.
     *
     * Les colonnes déclarent leur cible sous la forme « Entité.propriété »
     * ({@see ColonneEtat::enSaisie()}) : c'est la seule source, et on la lit à l'envers.
     *
     * @param array<string, ColonneEtat> $colonnes
     */
    private function codeDeLaColonne(string $entite, string $champ, array $colonnes): ?string
    {
        $cible = $entite . '.' . $champ;

        foreach ($colonnes as $code => $colonne) {
            if ($colonne->cible === $cible) {
                return $code;
            }
        }

        return null;
    }

    /** Le nom que l'écran donne à cette donnée — « Portefeuille », et non « Portefeuille » nu. */
    private function libelleDeLEntite(string $entite): string
    {
        return $this->accessResolver->libellesEntites()[$entite] ?? $entite;
    }

    /**
     * Le nom que le FORMULAIRE donne à ce champ — « Gestionnaire de compte », et non
     * « gestionnaire ».
     *
     * ⚠ C'EST LE MÊME MOT QUE CELUI DE L'ÉCRAN, et il le doit : l'utilisateur qui va
     * corriger le trouvera sous ce libellé-là dans la rubrique, pas sous un nom de
     * propriété qu'aucune interface n'affiche.
     */
    private function libelleDuChamp(string $entite, string $champ): string
    {
        $fqcn = 'App\\Entity\\' . $entite;

        if (!class_exists($fqcn)) {
            return $champ;
        }

        try {
            return $this->inspecteur->libelleChamp($fqcn, $champ);
        } catch (\Throwable) {
            return $champ;
        }
    }

    /** « A », « A et B », « A, B et C » — une énumération qui se lit à voix haute. */
    private function enumerer(array $morceaux): string
    {
        if (count($morceaux) === 1) {
            return $morceaux[0];
        }

        $dernier = array_pop($morceaux);

        return implode(', ', $morceaux) . ' et ' . $dernier;
    }
}
