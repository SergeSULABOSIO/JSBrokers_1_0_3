<?php

namespace App\Ai\Trousse;

/**
 * TROUSSE d'un message : le sous-ensemble d'outils déclarés au modèle, et la part
 * du prompt système qui les accompagne.
 *
 * POURQUOI. L'API est sans mémoire : à chaque tour de function calling, le serveur
 * réexpédie l'intégralité du prompt système et des déclarations d'outils. Mesuré sur
 * la campagne du 2026-08-08/09 : 53 Ko de prompt et 71 Ko de déclarations, soit 94 %
 * du payload, à chacun des 96 tours — pour 7 outils réellement appelés sur 33. On
 * payait donc plein tarif, plusieurs fois par message, pour 26 outils inutilisés.
 *
 * DEUX TROUSSES, ET PAS DAVANTAGE. Le fournisseur cache le PRÉFIXE d'une requête
 * (75 % de hit observés, jusqu'à 86 % sur les tours tardifs). Ce préfixe, ce sont
 * précisément le prompt système et les déclarations : le faire varier à chaque
 * message le ferait manquer à chaque fois. Deux trousses stables = deux entrées de
 * cache réutilisées d'un message à l'autre. Multiplier les trousses reviendrait à
 * échanger une économie de débit contre une inflation de facture.
 *
 * CE N'EST PAS UN MÉCANISME DE SÉCURITÉ. La garde reste dans execute(), qui
 * re-vérifie les droits en fail-closed. Un mauvais aiguillage ne donne jamais
 * d'accès ; il prive au pire le modèle d'une capacité — que les signaux structurels
 * de SelecteurDeTrousse (plan en attente, programme en cours, pièce jointe, tour
 * précédent ayant écrit ou ayant PROPOSÉ d'écrire) lui rendent au message SUIVANT.
 * Jamais dans le même message : la règle des deux appels (cf. Phase) interdit un
 * troisième tour, la trousse ne peut donc pas s'élargir en cours de route.
 */
enum Trousse: string
{
    /**
     * Le strict nécessaire pour lever une ambiguïté de RÉFÉRENCE, servi à la phase
     * de compréhension (cf. AiToolDeComprehension). Elle n'entre pas en conflit avec
     * la règle « deux trousses » ci-dessus : cette phase tourne sur un AUTRE modèle,
     * elle ne partage donc aucun préfixe de cache avec les deux autres.
     */
    case COMPREHENSION = 'comprehension';

    /** Consultation, analyse, restitution. Ne peut rien préparer qui s'écrive. */
    case LECTURE = 'lecture';

    /** Tout : lecture + préparation de plans d'écriture et parcours de saisie. */
    case ECRITURE = 'ecriture';

    public function estEcriture(): bool
    {
        return $this === self::ECRITURE;
    }

    /** Libellé court pour la télémétrie et les journaux. */
    public function libelle(): string
    {
        return $this->value;
    }

    public static function depuis(?string $valeur): self
    {
        return self::tryFrom((string) $valeur) ?? self::ECRITURE;
    }
}
