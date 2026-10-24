<?php

namespace App\Service\Infobulle;

/**
 * L'INFOBULLE D'UNE PUCE DE CONTEXTE DU CHAT DE KET (chantier 3, commit 3c).
 *
 * Le contenu de l'infobulle unique est DÉCLARÉ par le serveur (`data-infobulle`) : ici, la fiche
 * EXACTE capturée par l'assistant (même source que le prompt), mise en forme pour être lue.
 * Les règles sont celles qu'appliquait le navigateur (assistant-chat_controller::_ctxTipFormat) :
 * booléens en clair, liste résumée à son nombre d'éléments, objet résumé à son nom, texte borné.
 *
 * La bulle ne défile pas : au-delà de MAX_LIGNES, le reste est ANNONCÉ, jamais rogné en silence —
 * l'utilisateur sait que l'assistante a reçu la fiche complète.
 */
final class InfobulleContexte
{
    public const MAX_LIGNES = 20;
    private const LONGUEUR_MAX = 120;

    /**
     * @param array<string, mixed>|null $fiche la fiche capturée ; null = objet supprimé ou hors périmètre
     *
     * @return list<array<string, mixed>> les sections de l'infobulle unique
     */
    public static function sections(string $typeLabel, string $label, ?array $fiche): array
    {
        $sections = [[
            'titre' => $typeLabel !== '' ? $typeLabel : 'Objet',
            'lignes' => [['Nom', $label !== '' ? $label : '—']],
        ]];

        if ($fiche === null) {
            $sections[] = ['texte' => 'Détails indisponibles (objet supprimé ou hors de votre périmètre).', 'classe' => 'tip-libelle'];

            return $sections;
        }

        $lignes = [];
        foreach (\array_slice($fiche, 0, self::MAX_LIGNES, true) as $cle => $valeur) {
            $lignes[] = [(string) $cle, self::valeur($valeur)];
        }
        $section = ['titre' => 'Fiche capturée par l’assistant', 'lignes' => $lignes];
        $restants = \count($fiche) - self::MAX_LIGNES;
        if ($restants > 0) {
            $pluriel = $restants > 1 ? 's' : '';
            $section['texte'] = sprintf('… et %d autre%s indicateur%s : la fiche COMPLÈTE est transmise à l’assistant.', $restants, $pluriel, $pluriel);
            $section['classe'] = 'tip-libelle';
        }
        $sections[] = $section;

        return $sections;
    }

    /**
     * Une valeur de fiche, lisible. Un indicateur calculé peut être un objet (date, énumération,
     * entité) : il est lu s'il sait se dire, sinon « — » — jamais une erreur qui ferait tomber
     * le rendu du chat.
     */
    public static function valeur(mixed $valeur): string
    {
        if (\is_array($valeur) && array_is_list($valeur)) {
            return \count($valeur) . ' élément(s)';
        }
        if (\is_array($valeur)) {
            foreach (['nom', 'libelle', 'titre', 'id'] as $cle) {
                if (isset($valeur[$cle]) && $valeur[$cle] !== '') {
                    return self::texte($valeur[$cle]);
                }
            }

            return '—';
        }

        return self::texte($valeur);
    }

    private static function texte(mixed $valeur): string
    {
        $texte = match (true) {
            $valeur === null, $valeur === '' => null,
            \is_bool($valeur) => $valeur ? 'Oui' : 'Non',
            \is_scalar($valeur) => (string) $valeur,
            $valeur instanceof \DateTimeInterface => $valeur->format('d/m/Y H:i'),
            $valeur instanceof \BackedEnum => (string) $valeur->value,
            $valeur instanceof \UnitEnum => $valeur->name,
            $valeur instanceof \Stringable => (string) $valeur,
            default => null,
        };
        if ($texte === null || $texte === '') {
            return '—';
        }

        return mb_strlen($texte) > self::LONGUEUR_MAX ? mb_substr($texte, 0, self::LONGUEUR_MAX) . '…' : $texte;
    }
}
