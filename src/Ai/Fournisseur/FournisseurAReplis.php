<?php

namespace App\Ai\Fournisseur;

/**
 * UN FOURNISSEUR QUI NE S'ARRÊTE PAS AU PREMIER MODÈLE.
 *
 * ── CE QUE L'ÉCRAN NE DISAIT PAS ────────────────────────────────────────────
 * La console affichait UN modèle par fournisseur — celui de `GEMINI_MODEL`, par
 * exemple — et le présentait comme LE modèle. Or le moteur Gemini porte une chaîne
 * de secours (`GEMINI_MODELES_REPLI`) : sur un 503 ou un quota atteint, il bascule
 * sur le suivant et continue de répondre. L'agent lisait donc un nom, croyait savoir
 * à quoi Ket était branchée, et pouvait se tromper de modèle en cherchant la cause
 * d'une réponse lente, chère ou médiocre.
 *
 * Un écran de réglage qui nomme la mauvaise chose est pire qu'un écran muet : on lui
 * fait confiance.
 *
 * ── FACULTATIVE, COMME `FournisseurAModele` ─────────────────────────────────
 * La plupart des fournisseurs n'ont qu'un modèle et n'ont rien à déclarer ici. Seul
 * celui qui en enchaîne plusieurs doit dire lesquels, et dans quel ordre.
 */
interface FournisseurAReplis
{
    /**
     * Les modèles de secours, dans l'ordre où ils seront tentés — le modèle
     * principal EXCLU (il est déjà rendu par `modeleEnVigueur()`).
     *
     * @return list<string>
     */
    public function modelesDeRepli(): array;
}
