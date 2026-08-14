<?php

namespace App\Ai\Presentation;

use App\Services\CanvasBuilder;
use App\Services\ServiceMonnaies;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * CE QUE LA RUBRIQUE AFFICHE, servi à Ket pour chaque ligne d'une liste.
 *
 * L'INCIDENT (2026-08-14). `rechercher_entites` ne rendait que `id` + `libelle` — le
 * premier champ textuel de l'entité. Prié d'ajouter « une colonne pour le taux », le
 * modèle n'avait aucune donnée : il a affiché 0 % pour les dix partenaires, puis,
 * sommé de vérifier, a fabriqué une explication (« une valeur par défaut non
 * renseignée dans la table générale »). La part de LOCKTON valait 30 %, et l'écran
 * l'affichait. Ket ne pouvait pas répondre à une question dont la réponse était sous
 * ses yeux dans l'application.
 *
 * LA SOURCE EST DÉJÀ LÀ : le canevas de LISTE (`ListCanvasProvider`), celui-là même
 * qui peint la rubrique. Il déclare les colonnes, leurs intitulés ET leurs unités —
 * pour Partenaire : `partPourcentage` en « % », `montantPur`, `retroCommission` et
 * `retroCommissionReversee` dans la monnaie du cabinet. On ne devine donc rien, et
 * ajouter une colonne à un écran l'offre du même coup au chat.
 *
 * POURQUOI PAS LA FICHE COMPLÈTE. `FicheNormaliseur::ficheEnrichie()` rendrait TOUT
 * (32 clés pour un Partenaire, 76 pour un Avenant), soit 6 000 à 18 000 tokens pour
 * une page de 20 — davantage que le budget de sortie du moteur (4 096), et réexpédié
 * à la rédaction. Son propre docblock l'interdit d'ailleurs : « réservé aux lectures
 * CIBLÉES, on ne les déclenche pas sur des listes ». Les colonnes de l'écran sont le
 * juste milieu : bornées, choisies par le métier, et déjà payées par la rubrique.
 */
final class ColonnesDeLEcran
{
    /** Le canevas range ses colonnes chiffrées ici. */
    private const CLE_NUMERIQUES = 'colonnes_numeriques';

    private readonly PropertyAccessorInterface $accesseur;

    public function __construct(
        private readonly CanvasBuilder $canvasBuilder,
        private readonly ServiceMonnaies $serviceMonnaies,
    ) {
        // Tolérant : une colonne du canevas peut viser un indicateur qu'une entité
        // donnée ne porte pas. On lit ce qui existe, on ignore le reste.
        $this->accesseur = PropertyAccess::createPropertyAccessorBuilder()
            ->disableExceptionOnInvalidIndex()
            ->getPropertyAccessor();
    }

    /**
     * Les valeurs d'écran de chaque entité, et la déclaration de présentation.
     *
     * Le PRÉCHARGEMENT DE MASSE d'abord, exactement comme la rubrique
     * (`ControllerUtilsTrait` : `batchPreloadForCollection` puis
     * `loadAllCalculatedValues` par ligne). Sans lui, chaque ligne rallumerait ses
     * propres chargements paresseux — mesuré à 289 requêtes pour 20 avenants.
     *
     * @param list<object> $entites toutes de la même classe
     *
     * @return array{valeurs: array<int, array<string, mixed>>, presentation: array}
     */
    public function projeter(array $entites, string $fqcn): array
    {
        $colonnes = $this->colonnes($fqcn);
        if ($entites === [] || $colonnes === []) {
            return ['valeurs' => [], 'presentation' => []];
        }

        $this->canvasBuilder->batchPreloadForCollection($entites);

        $valeurs = [];
        foreach ($entites as $entite) {
            // Les indicateurs sont posés en propriétés dynamiques sur l'entité : c'est
            // le contrat de CanvasBuilder, partagé avec l'écran. On lit ensuite par
            // PropertyAccess, qui trouve indifféremment un getter ou l'une d'elles.
            $this->canvasBuilder->loadAllCalculatedValues($entite);

            $ligne = [];
            foreach ($colonnes as $code => $meta) {
                $valeur = $this->lire($entite, $code);
                // Une valeur absente ne devient PAS une colonne vide : mieux vaut
                // qu'elle manque, le contrat de présentation interdisant à Ket
                // d'inventer une colonne qu'elle ne trouve pas dans les résultats.
                if ($valeur !== null && $valeur !== '') {
                    $ligne[$code] = $valeur;
                }
            }
            $valeurs[(int) $entite->getId()] = $ligne;
        }

        return ['valeurs' => $valeurs, 'presentation' => $this->presentation($colonnes)];
    }

    /**
     * Les colonnes déclarées par le canevas de liste : code => {titre, role}.
     *
     * @return array<string, array{titre: string, role: string, tableau: bool}>
     */
    private function colonnes(string $fqcn): array
    {
        $canvas = $this->canvasBuilder->getListeCanvas($fqcn);
        if ($canvas === []) {
            return [];
        }

        $colonnes = [];

        // Les textes SECONDAIRES de la colonne principale (téléphone, e-mail…) sont
        // des données utiles, mais ce ne sont pas des colonnes de tableau : l'écran
        // les met en sous-ligne. On les sert à Ket sans les déclarer à la présentation.
        foreach ($canvas['colonne_principale']['textes_secondaires'] ?? [] as $secondaire) {
            $code = (string) ($secondaire['attribut_code'] ?? '');
            if ($code !== '') {
                $colonnes[$code] = ['titre' => $code, 'role' => Colonnes::TEXTE, 'tableau' => false];
            }
        }

        foreach ($canvas[self::CLE_NUMERIQUES] ?? [] as $numerique) {
            $code = (string) ($numerique['attribut_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $colonnes[$code] = [
                'titre'   => (string) ($numerique['titre_colonne'] ?? $code),
                'role'    => $this->role((string) ($numerique['attribut_unité'] ?? '')),
                'tableau' => true,
            ];
        }

        return $colonnes;
    }

    /**
     * L'unité du canevas dit le RÔLE : « % » est un taux (jamais sommé), le code de la
     * monnaie du cabinet est de l'argent (sommé, avec son symbole). Tout le reste est
     * une quantité. C'est la seule façon d'obtenir MONTANT — `Colonnes::roleDeduit()`
     * refuse volontairement de le deviner, pour ne jamais coller la mauvaise unité.
     */
    private function role(string $unite): string
    {
        if ($unite === '%') {
            return Colonnes::POURCENTAGE;
        }

        return $unite !== '' && $unite === $this->serviceMonnaies->getCodeMonnaieAffichage()
            ? Colonnes::MONTANT
            : Colonnes::NOMBRE;
    }

    /**
     * @param array<string, array{titre: string, role: string, tableau: bool}> $colonnes
     */
    private function presentation(array $colonnes): array
    {
        $roles = ['libelle' => Colonnes::TEXTE];
        foreach ($colonnes as $code => $meta) {
            if ($meta['tableau']) {
                $roles[$code] = $meta['role'];
            }
        }

        return $roles === ['libelle' => Colonnes::TEXTE] ? [] : Colonnes::de($roles);
    }

    private function lire(object $entite, string $code): mixed
    {
        try {
            return $this->accesseur->isReadable($entite, $code)
                ? $this->accesseur->getValue($entite, $code)
                : null;
        } catch (\Throwable) {
            return null; // Une colonne d'écran illisible ne doit jamais casser une liste.
        }
    }
}
