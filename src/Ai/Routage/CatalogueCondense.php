<?php

namespace App\Ai\Routage;

use App\Ai\Tool\ActiverOutilsEcritureTool;
use App\Ai\Trousse\AiToolEcriture;
use App\Ai\Trousse\TrousseCatalogue;

/**
 * Le catalogue des outils réduit à ce qu'il faut pour CHOISIR : un nom, une ligne,
 * et la mention de la trousse à laquelle l'outil appartient.
 *
 * MESURE : 4 387 octets pour les 33 outils, contre 71 372 pour leurs déclarations
 * complètes (schémas compris). Un tour de routage coûte donc ~2 800 tokens là où un
 * tour ordinaire en coûte ~38 000 — il se rembourse dès qu'il évite un seul tour
 * plein, et il en évite plusieurs.
 *
 * La ligne vient de aiguillage() quand l'outil en a une (c'est exactement « quand
 * m'appeler »), sinon de la première phrase de sa description. Rien n'est recopié
 * ici : ajouter un outil suffit à ce qu'il apparaisse.
 */
final class CatalogueCondense
{
    /** Au-delà, une ligne cesse d'aider à choisir et se met à peser. */
    private const LONGUEUR_MAX = 180;

    public function __construct(
        private readonly TrousseCatalogue $catalogue,
    ) {
    }

    /**
     * Le catalogue, une ligne par outil, groupé par trousse pour que le modèle voie
     * ce que « lecture » et « écriture » recouvrent réellement.
     */
    public function texte(): string
    {
        $lecture = [];
        $ecriture = [];
        foreach ($this->catalogue->tous() as $outil) {
            // L'escalade est un mécanisme interne, pas une capacité métier : la
            // montrer à l'aiguilleur brouillerait son unique décision.
            if ($outil instanceof ActiverOutilsEcritureTool) {
                continue;
            }
            $ligne = sprintf('- %s : %s', $outil->name(), $this->resumer($outil->aiguillage(), $outil->description()));
            if ($outil instanceof AiToolEcriture) {
                $ecriture[] = $ligne;
            } else {
                $lecture[] = $ligne;
            }
        }

        return "OUTILS DE LECTURE (consultation, analyse, restitution) :\n"
            . implode("\n", $lecture)
            . "\n\nOUTILS D'ÉCRITURE (préparent un plan à valider, ou une saisie) :\n"
            . implode("\n", $ecriture);
    }

    /** Première phrase utile, bornée. */
    private function resumer(string $aiguillage, string $description): string
    {
        $source = trim($aiguillage) !== '' ? $aiguillage : $description;
        $source = trim((string) preg_replace('/\s+/', ' ', $source));

        $phrases = preg_split('/(?<=[.?!])\s+/', $source) ?: [$source];
        $texte = $phrases[0];
        if (mb_strlen($texte) > self::LONGUEUR_MAX) {
            $texte = mb_substr($texte, 0, self::LONGUEUR_MAX - 1) . '…';
        }

        return $texte;
    }
}
