<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Guide\GuideRepository;
use App\Ai\Scope\AiScope;

/**
 * Méta-outil de CONNAISSANCE (pattern « skills » à divulgation progressive) :
 * charge une fiche métier versionnée (src/Ai/Guide/fiches/) quand la question
 * porte sur une notion ou une méthode, pas sur des données. Le prompt système
 * ne contient que le catalogue (une ligne par fiche) — le contenu n'entre dans
 * le contexte que via cet outil, à la demande du modèle.
 *
 * Pas de garde de périmètre : les fiches sont de la documentation générique de
 * la plateforme, sans AUCUNE donnée d'entreprise.
 */
final class ConsulterGuideTool implements AiToolInterface
{
    public function __construct(
        private readonly GuideRepository $guides,
    ) {
    }

    public function name(): string
    {
        return 'consulter_guide';
    }

    public function description(): string
    {
        return 'Charge une fiche de connaissance métier (notions, circuits, recettes '
            . "d'enchaînement d'outils). À appeler AVANT de répondre à une question de méthode ou "
            . 'de vocabulaire (« comment marche… », « c\'est quoi… »), jamais pour des données. '
            . 'Le sujet se choisit dans la liste fermée du paramètre, qui dit ce que chaque fiche '
            . 'couvre.';
    }

    public function aiguillage(): string
    {
        return 'Toute question de méthode, de vocabulaire ou de « comment faire », et « que peux-tu faire ? » '
            . '(fiche capacites-assistant). Consulte AVANT de répondre, puis appuie-toi sur la fiche.';
    }

    /** Ce que chaque fiche couvre, dérivé du catalogue : une fiche ajoutée s'y annonce seule. */
    private function catalogueEnClair(): string
    {
        $lignes = [];
        foreach ($this->guides->catalogue() as $slug => $fiche) {
            $lignes[] = sprintf('%s = %s', $slug, $fiche['description']);
        }

        return implode(' ; ', $lignes) . '.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sujet' => [
                    'type' => 'string',
                    // CE QUE CHAQUE FICHE COUVRE, ÉCRIT SUR LE PARAMÈTRE QU'IL CONTRAINT.
                    //
                    // Le catalogue vivait dans la description de l'outil, qu'il faisait enfler à
                    // chaque fiche ajoutée — et il y était loin de l'enum qu'il commente. Le
                    // déplacer ne coûte rien de plus et le rend utile là où le modèle choisit :
                    // sans lui, il ne voit que des slugs et doit deviner ce qu'ils recouvrent.
                    'description' => 'Fiche à consulter. ' . $this->catalogueEnClair(),
                    'enum' => $this->guides->slugs(),
                ],
            ],
            'required' => ['sujet'],
        ];
    }

    /**
     * Chemin simulé : question de méthode/notion (comment, c'est quoi, explique…)
     * dont un mot-clé du slug ou du titre d'une fiche apparaît dans le texte.
     * Cas particulier : « que peux-tu faire ? » cible directement l'inventaire
     * des capacités (fiche capacites-assistant).
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        if (preg_match('/\b(que (peux|sais)[ -]tu( faire)?|tes (capacites|competences|fonctionnalites)|de quoi es[ -]tu capable|que proposes[ -]tu|a quoi sers[ -]tu)\b/', $normalized)
            && $this->guides->fiche('capacites-assistant') !== null) {
            return ['sujet' => 'capacites-assistant'];
        }

        if (!preg_match('/\b(comment|c est quoi|qu est[- ]ce|explique[rsz]?|guide|fonctionn\w*)\b/', $normalized)) {
            return null;
        }

        foreach ($this->guides->catalogue() as $slug => $fiche) {
            foreach ($this->keywords($slug, $fiche['titre']) as $keyword) {
                if (preg_match('/\b' . preg_quote($keyword, '/') . '\w*/', $normalized)) {
                    return ['sujet' => $slug];
                }
            }
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $slug = (string) ($args['sujet'] ?? '');
        $contenu = $this->guides->fiche($slug);
        if ($contenu === null) {
            return AiToolResult::introuvable($slug);
        }

        return AiToolResult::ok([
            'sujet'   => $slug,
            'titre'   => $this->guides->catalogue()[$slug]['titre'],
            'contenu' => $contenu,
        ]);
    }

    /** Mots-clés significatifs (≥ 4 lettres) dérivés du slug et du titre de la fiche. */
    private function keywords(string $slug, string $titre): array
    {
        $mots = array_merge(
            explode('-', $slug),
            preg_split('/[^a-z0-9]+/', AiText::normalize($titre)) ?: [],
        );

        return array_values(array_unique(array_filter(
            array_map(static fn (string $m) => AiText::normalize($m), $mots),
            static fn (string $m) => mb_strlen($m) >= 4,
        )));
    }
}
