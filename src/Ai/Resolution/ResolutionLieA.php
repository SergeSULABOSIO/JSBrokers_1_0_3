<?php

namespace App\Ai\Resolution;

use App\Ai\Tool\AiToolResult;

/**
 * L'issue d'une résolution de rattachement {@see CritereLieA}. Quatre cas, et il fallait
 * bien les distinguer tous les quatre — les confondre produirait à chaque fois une
 * réponse fausse plutôt qu'une erreur :
 *
 *  - ABSENT   : aucun `lieA` demandé. On liste sans restriction, et c'est normal.
 *  - LIÉ      : le rattachement est résolu ; `criteria` restreint la recherche.
 *  - IGNORÉ   : un `lieA` était demandé mais reste inapplicable (entité inconnue, ou
 *               aucun chemin de relations entre les deux). On liste SANS restriction et
 *               on le SIGNALE au modèle : silencieusement élargi, il présenterait une
 *               liste générale comme étant celle du dossier demandé.
 *  - REFUS    : rien ne sera listé. Soit le droit de lecture manque sur l'entité de
 *               rattachement, soit le nom dicté est ambigu et l'outil rend une question
 *               déjà formulée. Dans les deux cas l'appelant retourne `resultat` tel quel.
 */
final class ResolutionLieA
{
    private function __construct(
        public readonly ?AiToolResult $refus,
        public readonly ?array $lien,
        public readonly array $criteria,
        public readonly bool $ignore,
    ) {
    }

    public static function absent(): self
    {
        return new self(null, null, [], false);
    }

    public static function ignore(): self
    {
        return new self(null, null, [], true);
    }

    /**
     * @param array{entite: string, id: int}  $lien     rattachement retenu, restitué au modèle
     * @param array<string, mixed>            $criteria critère à fusionner dans la recherche
     */
    public static function lie(array $lien, array $criteria): self
    {
        return new self(null, $lien, $criteria, false);
    }

    public static function refus(AiToolResult $resultat): self
    {
        return new self($resultat, null, [], false);
    }

    /** L'appelant doit-il s'arrêter et retourner {@see $refus} ? */
    public function estRefus(): bool
    {
        return $this->refus !== null;
    }
}
