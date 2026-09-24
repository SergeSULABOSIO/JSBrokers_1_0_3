<?php

namespace App\Ai\Reglage;

use App\Ai\Tool\AiToolInterface;

/**
 * CE QUE PÈSE UN OUTIL, ET À QUEL PRIX — la formule, à un seul endroit.
 *
 * ── POURQUOI ELLE SORT DE LA COMMANDE ───────────────────────────────────────
 * Elle vivait dans `AssistantTokensCompositionCommand`, qui est un outil de
 * diagnostic : on l'exécute quand on se pose la question. L'écran de console, lui,
 * l'affiche en permanence et annonce un GAIN avant de couper un outil. Deux
 * copies d'un même calcul finiraient par ne plus dire le même chiffre — et c'est
 * le genre d'écart qu'on ne voit pas, puisque chacun des deux paraît plausible.
 *
 * La déclaration mesurée est EXACTEMENT celle qui part au fournisseur : `name`,
 * `description` et `parameters` (le JSON-Schema), sérialisés en UTF-8 non échappé.
 * C'est aussi ce que `TroussePoidsTest` mesure. Le dialecte Gemini rabote ensuite
 * quelques clés (`additionalProperties`) et Anthropic renomme `parameters` en
 * `input_schema` : l'écart est de quelques octets sur des dizaines de milliers, et
 * mesurer le dialecte de chaque fournisseur donnerait deux chiffres différents pour
 * le même outil — donc un écran qui ne saurait pas lequel afficher.
 *
 * ── LE RATIO ────────────────────────────────────────────────────────────────
 * 3,7 octets par jeton, relevé sur le corpus de production (cf. `RapportTokens::
 * ratioOctetsParToken()`, qui le RECALCULE sur les usages réellement renvoyés par
 * le fournisseur). C'est une estimation, et elle est annoncée comme telle partout
 * où elle s'affiche : « ≈ ».
 */
final class PoidsDesDeclarations
{
    /** Octets par jeton d'entrée, mesuré sur le corpus de production. */
    public const OCTETS_PAR_TOKEN = 3.7;

    /** La déclaration d'un outil, telle qu'elle part au fournisseur. */
    public static function declaration(AiToolInterface $outil): array
    {
        return [
            'name'        => $outil->name(),
            'description' => $outil->description(),
            'parameters'  => $outil->schema(),
        ];
    }

    /** Octets d'une structure sérialisée — la seule façon de compter dans ce projet. */
    public static function octets(array $donnees): int
    {
        return \strlen((string) json_encode($donnees, JSON_UNESCAPED_UNICODE));
    }

    /** Octets de la déclaration d'un outil. */
    public static function octetsDe(AiToolInterface $outil): int
    {
        return self::octets(self::declaration($outil));
    }

    /**
     * Jetons d'entrée correspondants. Arrondi à l'entier : un dixième de jeton
     * n'a aucun sens pour qui lit l'écran, et la précision du ratio ne le porte pas.
     */
    public static function jetons(int $octets): int
    {
        return (int) round($octets / self::OCTETS_PAR_TOKEN);
    }

    /**
     * Octets cumulés d'une liste d'outils, sérialisée EN BLOC.
     *
     * ⚠ CE N'EST PAS LA SOMME DES OCTETS UN À UN. Un tableau JSON ajoute ses
     * crochets et ses virgules : mesurer chaque outil puis additionner rend un
     * total légèrement inférieur à ce qui part réellement. L'écran annonce un
     * total de payload, il doit donc mesurer le payload.
     *
     * @param iterable<AiToolInterface> $outils
     */
    public static function octetsDeLaListe(iterable $outils): int
    {
        $declarations = [];
        foreach ($outils as $outil) {
            $declarations[] = self::declaration($outil);
        }

        return self::octets($declarations);
    }

    /** « 12,3 Ko » / « 1,2 Mo » — pour les en-têtes, jamais pour un calcul. */
    public static function lisible(int $octets): string
    {
        return $octets >= 1024 * 1024
            ? sprintf('%.1f Mo', $octets / 1024 / 1024)
            : sprintf('%.1f Ko', $octets / 1024);
    }
}
