<?php

namespace App\Ai;

/**
 * LE TITRE D'UNE CONVERSATION, TIRÉ DE SA PREMIÈRE VRAIE QUESTION.
 *
 * Une conversation qui n'a pas de titre s'appelle « CONV#135 ». Dès la première
 * question, elle prend un titre qui s'en inspire. L'utilisateur peut toujours le
 * renommer ensuite, et c'est son choix qui l'emporte (voir
 * AssistantConversation::titrerDepuis).
 *
 * POURQUOI CE N'EST PAS UN RETOUR EN ARRIÈRE. Le titre reprenait autrefois les
 * quatre-vingts premiers caractères du message, tels quels : une phrase entière
 * dans un onglet, avec son « Bonjour Ket, peux-tu… ». Ici, la formule de politesse
 * est retirée, le cœur de la demande est gardé et le tout tient en 40 caractères.
 *
 * SANS IA, ET C'EST VOULU : 0 token, un titre visible dès l'envoi, et un résultat
 * déterministe, donc testable.
 *
 * `null` = rien d'exploitable (« Bonjour ! », « Ok merci ») : la conversation reste
 * « CONV#… » et la question suivante retentera.
 */
final class TitreDeConversation
{
    /** Longueur maximale, « … » compris : un titre d'onglet, pas une phrase. */
    public const MAX = 40;

    /**
     * Un message fait UNIQUEMENT de ces mots ne dit rien de la conversation à venir.
     * Comparés sur AiText::cle() : sans casse, sans accents, sans ponctuation.
     */
    private const MOTS_BANALS = [
        'ca', 'va', 'ok', 'okay', 'oui', 'non', 'test', 'merci', 'hello', 'coucou',
        'salut', 'bonjour', 'bonsoir', 'hey',
    ];

    /** Formules de politesse : seulement en tête, retirées tant qu'il en reste. */
    private const EN_TETE = [
        '/^(bonjour|bonsoir|salut|hello|coucou|hey)\b[\s,;:!.]*/iu',
        "/^(s'il (te|vous) pla[iî]t|stp|svp|merci)\b[\s,;:!.]*/iu",
        "/^est[- ]ce (que|qu')\s*/iu",
        "/^(peux|pourrais|pouvez|pourriez|veux|voudrais|voulez|voudriez)[- ](tu|vous)\s*(me\s+|m')?/iu",
        '/^je (voudrais|veux|souhaite|souhaiterais|aimerais)\s+/iu',
        '/^(dis|dites)[- ]moi\s*/iu',
    ];

    /** Formules et ponctuation de fin : retirées tant qu'il en reste. */
    private const EN_FIN = [
        '/[\s,;:!?.…]+$/u',
        "/\s*\b(stp|svp|s'il (te|vous) pla[iî]t|merci( beaucoup)?)$/iu",
    ];

    /**
     * @param string $nomAssistant le nom CONFIGURÉ de l'assistant (« Ket » par défaut) :
     *                             « Ket, peux-tu… » s'adresse à lui, ce n'est pas le sujet
     */
    public static function depuis(string $question, string $nomAssistant): ?string
    {
        $texte = self::normaliser($question);

        $enTete = self::EN_TETE;
        if (trim($nomAssistant) !== '') {
            // Pas `\b` : un nom qui finit par un signe (« A.I. ») n'aurait pas de frontière.
            $enTete[] = '/^' . preg_quote(trim($nomAssistant), '/') . '(?![\p{L}\p{N}])[\s,;:!.]*/iu';
        }
        $texte = self::retirerTantQuIlEnReste($texte, $enTete);
        $texte = self::retirerTantQuIlEnReste($texte, self::EN_FIN);

        if (mb_strlen($texte) < 3 || self::estBanal($texte)) {
            return null;
        }

        return self::couper(mb_strtoupper(mb_substr($texte, 0, 1)) . mb_substr($texte, 1));
    }

    /** Apostrophes typographiques, espaces insécables, guillemets et marques markdown. */
    private static function normaliser(string $question): string
    {
        $texte = str_replace(['’', '‘', 'ʼ', '`'], "'", $question);
        $texte = str_replace(["\u{00A0}", "\u{202F}"], ' ', $texte);
        $texte = (string) preg_replace('/[«»"“”*_~]+/u', ' ', $texte);
        // `#` (titre) et `>` (citation) ne sont du markdown qu'en TÊTE : ailleurs, ils
        // appartiennent au texte (« <b>Primes</b> » doit rester lisible tel quel).
        $texte = (string) preg_replace('/^[\s#>]+/u', '', $texte);

        return trim((string) preg_replace('/\s+/u', ' ', $texte));
    }

    /** @param list<string> $motifs */
    private static function retirerTantQuIlEnReste(string $texte, array $motifs): string
    {
        do {
            $avant = $texte;
            foreach ($motifs as $motif) {
                $texte = trim((string) preg_replace($motif, '', $texte));
            }
        } while ($texte !== $avant && $texte !== '');

        return $texte;
    }

    private static function estBanal(string $texte): bool
    {
        $mots = explode(' ', AiText::cle($texte));

        return array_diff($mots, self::MOTS_BANALS) === [];
    }

    /**
     * Au-delà de MAX : coupe au dernier espace avant le 39ᵉ caractère, puis « … ».
     * Sans espace (un seul long mot, une référence de police), coupe franche à 39.
     * Le résultat ne dépasse JAMAIS MAX, « … » compris.
     */
    private static function couper(string $texte): string
    {
        if (mb_strlen($texte) <= self::MAX) {
            return $texte;
        }

        $tete = mb_substr($texte, 0, self::MAX - 1);
        $espace = mb_strrpos($tete, ' ');
        if ($espace !== false && $espace > 0) {
            $tete = mb_substr($tete, 0, $espace);
        }

        return rtrim($tete, " ,;:.-'") . '…';
    }
}
