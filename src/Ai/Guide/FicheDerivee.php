<?php

namespace App\Ai\Guide;

/**
 * UNE FICHE DE CONNAISSANCE QUI SE CALCULE, au lieu d'être écrite à la main.
 *
 * ── POURQUOI CE CONTRAT ─────────────────────────────────────────────────────
 * Les fiches de `src/Ai/Guide/fiches/*.md` disent le MÉTIER : ce qu'est une piste,
 * comment marche un bordereau. Elles se périment lentement, et c'est acceptable.
 *
 * Une fiche qui décrit L'APPLICATION, elle, se périme au commit suivant. Écrire à
 * la main « voici les rubriques et les boutons » condamne quelqu'un à la tenir à
 * jour, et personne ne le fera : c'est exactement ce qui est arrivé à la fiche des
 * capacités, dont une ligne renvoyait encore le courtier à l'écran pour un geste
 * que Ket sait faire. D'où ce contrat : le contenu est DÉRIVÉ du code, à la demande.
 *
 * ── LA RÈGLE QUI COMPTE, ET ELLE EST COÛTEUSE À OUBLIER ─────────────────────
 * `titre()` et `description()` sont payés À CHAQUE TOUR : le catalogue des fiches
 * part dans le prompt système ET dans la description du paramètre `sujet` de
 * `consulter_guide`. Ce doivent donc être des LITTÉRAUX — quelques dizaines de
 * jetons, pas un calcul.
 *
 * `contenu()` est le seul point coûteux, et il n'est appelé que lorsque le modèle
 * ouvre la fiche. Écrire `description() { return $this->contenu(); }` par
 * étourderie ferait payer le texte entier, et son calcul, à chaque message.
 */
interface FicheDerivee
{
    /** Identifiant de la fiche, tel que `consulter_guide` l'accepte en paramètre. */
    public function slug(): string;

    /** Titre affiché au catalogue. LITTÉRAL : payé à chaque tour. */
    public function titre(): string;

    /** Une phrase, pour que le modèle sache quand l'ouvrir. LITTÉRAL : payé à chaque tour. */
    public function description(): string;

    /** Le texte complet, calculé. Appelé UNIQUEMENT quand la fiche est ouverte. */
    public function contenu(): string;
}
