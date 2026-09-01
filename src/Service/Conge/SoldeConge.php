<?php

namespace App\Service\Conge;

/**
 * Le compteur de congés d'un agent sur un exercice, à un instant donné.
 *
 * Objet de LECTURE, immuable : il transporte des chiffres déjà calculés par
 * CalculateurSolde, et n'en calcule aucun lui-même. C'est ce qui permet à l'écran, aux
 * e-mails et à l'assistant d'afficher les mêmes nombres sans les recalculer chacun de
 * leur côté — et donc sans pouvoir diverger.
 */
final class SoldeConge
{
    public function __construct(
        /** Droits de l'exercice : dotation + report + ajustements + recrédits. */
        public readonly float $acquis,
        /** Part de l'acquis venant du reliquat de l'exercice précédent. */
        public readonly float $dontReport,
        /** Jours des demandes APPROUVÉES, passées ou à venir. */
        public readonly float $consomme,
        /** Jours des demandes SOUMISES et non encore décidées. */
        public readonly float $engage,
        public readonly int $exercice,
    ) {
    }

    /**
     * Ce que l'agent peut encore poser aujourd'hui.
     *
     * L'ENGAGÉ EST RETRANCHÉ, pas seulement le consommé : sans cela, un agent poserait
     * deux fois les mêmes jours en enchaînant deux demandes avant toute décision.
     *
     * Le résultat n'est PAS écrêté à zéro. Un solde négatif existe pour de bon — un
     * ajustement à la baisse, une régularisation de sortie — et le masquer reviendrait à
     * cacher précisément ce qu'il faut voir.
     */
    public function disponible(): float
    {
        return $this->acquis - $this->consomme - $this->engage;
    }

    /**
     * Le disponible tel qu'il était AVANT que cette demande ne soit posée.
     *
     * Une demande soumise est déjà comptée dans l'engagé : le disponible courant la
     * reflète donc déjà. Pour annoncer « avant / après » dans un e-mail, c'est l'AVANT
     * qu'il faut reconstituer — l'après, lui, est le disponible courant, puisqu'une
     * approbation ne fait que déplacer les jours de l'engagé vers le consommé.
     *
     * Écrire l'inverse (retrancher les jours au disponible pour obtenir « l'après »)
     * les compterait deux fois, et le mail annoncerait un solde plus bas que l'écran.
     */
    public function disponibleAvant(float $jours): float
    {
        return $this->disponible() + $jours;
    }

    /** Un solde suffit-il à couvrir une demande de N jours ? (CTRL-01) */
    public function couvre(float $jours): bool
    {
        return $this->disponible() >= $jours;
    }

    /** @return array<string, float|int> Forme plate, pour les e-mails et l'assistant. */
    public function toArray(): array
    {
        return [
            'exercice' => $this->exercice,
            'acquis' => $this->acquis,
            'dontReport' => $this->dontReport,
            'consomme' => $this->consomme,
            'engage' => $this->engage,
            'disponible' => $this->disponible(),
        ];
    }
}
