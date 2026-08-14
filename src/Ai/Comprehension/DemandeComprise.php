<?php

namespace App\Ai\Comprehension;

/**
 * CE QUE KET A COMPRIS de la demande, établi AVANT toute planification.
 *
 * Deux issues, et deux seulement :
 *  - CLAIRE      : l'intention part avec le message d'origine vers la planification ;
 *  - À CLARIFIER : rien ne part. Ket rend la demande reformulée et attend l'accord.
 *
 * L'INTENTION NE REMPLACE JAMAIS LE MESSAGE. Elle fait autorité sur ce que
 * l'utilisateur VEUT, jamais sur ce qu'il a DIT : les montants, les dates et les
 * noms restent ceux de sa bulle, qui voyage intacte dans le fil. Une reformulation
 * qui se substituerait à la source rendrait définitive la moindre dérive du
 * comprenant — exactement la faute qu'il est censé corriger.
 *
 * L'ORIGINE est journalisée, et ce n'est pas de la décoration : sans elle on ne
 * saurait pas distinguer un fil qui coule bien (beaucoup de « court-circuit ») d'un
 * comprenant en panne silencieuse (beaucoup de « repli »), les deux produisant les
 * mêmes messages clairs.
 */
final class DemandeComprise
{
    /** Le modèle a tranché. */
    public const ORIGINE_MODELE = 'modele';

    /** Le serveur savait déjà : décision de plan, programme en cours, confirmation. */
    public const ORIGINE_COURT_CIRCUIT = 'court-circuit';

    /** Le comprenant n'a pas pu conclure — panne, quota, sortie inexploitable. */
    public const ORIGINE_REPLI = 'repli';

    /**
     * @param list<string> $questions points restant à trancher, vides quand la demande est claire
     */
    private function __construct(
        public readonly bool $claire,
        public readonly string $intention,
        public readonly array $questions,
        public readonly string $origine,
    ) {
    }

    /**
     * Demande claire. L'intention est la reformulation quand il y en a une, sinon le
     * message brut : un appelant n'a jamais à savoir laquelle des deux il tient.
     */
    public static function claire(string $intention, string $origine = self::ORIGINE_MODELE): self
    {
        return new self(true, trim($intention), [], $origine);
    }

    /**
     * @param list<string> $questions
     */
    public static function aClarifier(string $intention, array $questions): self
    {
        return new self(false, trim($intention), array_values(array_filter(array_map(
            static fn (mixed $q) => trim((string) $q),
            $questions,
        ))), self::ORIGINE_MODELE);
    }

    /**
     * Le MODÈLE a-t-il réellement relu la demande ?
     *
     * Non quand le serveur a court-circuité (l'intention est alors le message brut,
     * recopié) et non quand la phase s'est repliée. Dans ces deux cas, la
     * planification ne doit RIEN recevoir de plus : lui servir un bloc « demande
     * comprise » qui répète mot pour mot la bulle juste en dessous serait du bruit,
     * et lui retirer au passage la règle « comprendre avant d'agir » la priverait du
     * filet de sûreté qui la remplaçait jusqu'ici.
     */
    public function aEteEtablie(): bool
    {
        return $this->origine === self::ORIGINE_MODELE;
    }

    /**
     * LA BULLE de clarification, composée ICI et nulle part ailleurs.
     *
     * On ne demande pas au modèle d'écrire ce texte : il rend une intention et des
     * questions, le serveur les met en forme. C'est la même discipline que
     * RepliPrecis et TableauMarkdown — une seule plume pour un même objet, et un
     * rendu qui ne dépend pas de l'humeur d'un échantillonnage.
     *
     * Le ton compte autant que le contenu : on ne dit pas « votre demande est
     * ambiguë » (c'est lui renvoyer son travail, ce que le prompt interdit
     * explicitement), on dit ce qu'on a compris et ce qu'il reste à trancher.
     */
    public function texteDeClarification(): string
    {
        $texte = 'Je veux être sûr de bien vous suivre : **' . rtrim($this->intention, '.') . '**.';

        if ($this->questions !== []) {
            $texte .= "\n\nIl me manque juste " . (count($this->questions) === 1 ? 'un point' : 'quelques points') . " :\n";
            foreach ($this->questions as $question) {
                $texte .= "\n- " . $question;
            }
        }

        return $texte . "\n\nDites-moi si c'est bien cela — ou corrigez-moi, j'enchaîne aussitôt.";
    }
}
