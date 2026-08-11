<?php

namespace App\Ai\Document;

/**
 * LA SPÉCIFICATION D'UN RAPPORT — figée au moment du plan, rendue telle quelle.
 *
 * C'est la pièce qui rend honnête la promesse « vous voyez le prix avant de
 * valider ». Le barème dépend du nombre de caractères ; si le contenu était rédigé
 * au moment de la production, il divergerait du budget annoncé. Ici, le modèle
 * fournit tout à l'appel, le serveur mesure {@see texteFacturable()}, et la
 * validation ne fait plus que RENDRE — sans un seul appel au modèle.
 *
 * STRUCTURE IMPOSÉE (exigence du propriétaire), dans cet ordre :
 *   1. un titre ;
 *   2. la question / problématique qui a nécessité ce travail ;
 *   3. une introduction en la matière, définitions des termes techniques comprises ;
 *   4. la présentation du résultat proprement dit ;
 *   5. une conclusion ;
 *   6. une note de bas de page — posée par le SERVEUR (cf. PiedDePage), jamais par
 *      le modèle : l'entreprise, l'utilisateur et la date de production sont des
 *      faits, pas de la rédaction.
 *
 * NE LÈVE JAMAIS. Une exception dans AiToolInterface::execute() remonterait jusqu'au
 * contrôleur et deviendrait une bulle « le moteur a échoué », qui masque la vraie
 * cause. On construit donc toujours une spec, quitte à ce que {@see manquants()}
 * dise ce qui lui fait défaut.
 */
final readonly class RapportSpec
{
    /**
     * Plafond de contenu. Le vrai mur est ailleurs — les moteurs bornent leur
     * sortie à 4 096 jetons, soit ~15 000 caractères, et un modèle qui recopie une
     * grande analyse se fait tronquer en plein tableau. La voie normale pour un
     * résultat volumineux est donc `sourceMessageId`, que le serveur résout sans
     * rien faire transiter par le modèle. Ce plafond-ci ne protège que la base et
     * le rendu.
     */
    public const MAX_CARACTERES = 60000;
    public const MAX_SECTIONS = 20;
    public const MAX_DEFINITIONS = 30;

    /**
     * @param list<array{terme: string, explication: string}> $definitions
     * @param list<array{titre: string, corps: string}>       $sections
     */
    public function __construct(
        public string $titre,
        public string $problematique,
        public string $introduction,
        public array $definitions,
        public array $sections,
        public string $conclusion,
    ) {
    }

    /**
     * Construit la spec depuis les arguments du modèle. Tolérante par conception :
     * elle normalise, borne et tronque, mais ne refuse rien — c'est `manquants()`
     * qui portera le verdict, avec une note que le modèle sait exploiter.
     *
     * @param array<string, mixed>                     $args
     * @param array<int, array{titre: string, corps: string}> $sections déjà résolues (sourceMessageId compris)
     */
    public static function depuisArguments(array $args, array $sections): self
    {
        $definitions = [];
        foreach ((array) ($args['definitions'] ?? []) as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $terme = self::texte($definition['terme'] ?? '');
            $explication = self::texte($definition['explication'] ?? '');
            if ($terme === '' || $explication === '') {
                continue;
            }
            $definitions[] = ['terme' => $terme, 'explication' => $explication];
            if (count($definitions) >= self::MAX_DEFINITIONS) {
                break;
            }
        }

        return new self(
            titre: self::texte($args['titre'] ?? ''),
            problematique: self::texte($args['problematique'] ?? ''),
            introduction: self::texte($args['introduction'] ?? ''),
            definitions: $definitions,
            sections: array_slice(array_values($sections), 0, self::MAX_SECTIONS),
            conclusion: self::texte($args['conclusion'] ?? ''),
        );
    }

    /**
     * Ce qui manque à la structure imposée, en clair. Vide = la spec est complète.
     *
     * @return list<string>
     */
    public function manquants(): array
    {
        $manquants = [];
        if ($this->titre === '') {
            $manquants[] = 'le titre du document';
        }
        if ($this->problematique === '') {
            $manquants[] = 'la question ou la problématique qui a nécessité ce travail';
        }
        if ($this->introduction === '') {
            $manquants[] = 'l\'introduction en la matière';
        }
        if ($this->sections === []) {
            $manquants[] = 'au moins une section présentant le résultat';
        }
        if ($this->conclusion === '') {
            $manquants[] = 'la conclusion';
        }

        return $manquants;
    }

    /**
     * LE TEXTE MESURÉ — et donc facturé. Concatène tout ce que le lecteur lira,
     * hors pied de page (posé par le serveur, il n'est pas à la charge du client).
     *
     * Les clés de structure, les accolades et le JSON de transport n'y figurent
     * pas : on facture un document, pas un protocole.
     */
    public function texteFacturable(): string
    {
        $morceaux = [$this->titre, $this->problematique, $this->introduction];

        foreach ($this->definitions as $definition) {
            $morceaux[] = $definition['terme'];
            $morceaux[] = $definition['explication'];
        }
        foreach ($this->sections as $section) {
            $morceaux[] = $section['titre'];
            $morceaux[] = $section['corps'];
        }

        $morceaux[] = $this->conclusion;

        return implode("\n", array_filter($morceaux, static fn (string $m) => $m !== ''));
    }

    public function caracteres(): int
    {
        return mb_strlen($this->texteFacturable());
    }

    /** Dépasse-t-elle le plafond de contenu ? */
    public function estTropLongue(): bool
    {
        return $this->caracteres() > self::MAX_CARACTERES;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'titre'         => $this->titre,
            'problematique' => $this->problematique,
            'introduction'  => $this->introduction,
            'definitions'   => $this->definitions,
            'sections'      => $this->sections,
            'conclusion'    => $this->conclusion,
        ];
    }

    /** @param array<string, mixed> $donnees */
    public static function fromArray(array $donnees): self
    {
        $sections = [];
        foreach ((array) ($donnees['sections'] ?? []) as $section) {
            if (is_array($section)) {
                $sections[] = ['titre' => self::texte($section['titre'] ?? ''), 'corps' => self::texte($section['corps'] ?? '')];
            }
        }

        return self::depuisArguments($donnees, $sections);
    }

    /** Normalisation minimale et commune : chaîne, fins de ligne unifiées, sans bords. */
    private static function texte(mixed $valeur): string
    {
        if (is_array($valeur)) {
            $valeur = implode("\n", array_filter($valeur, 'is_scalar'));
        }

        return trim(str_replace(["\r\n", "\r"], "\n", (string) $valeur));
    }
}
