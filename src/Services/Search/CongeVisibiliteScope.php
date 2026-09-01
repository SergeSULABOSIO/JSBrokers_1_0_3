<?php

namespace App\Services\Search;

use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * QUI VOIT QUELLES DEMANDES DE CONGÉ.
 *
 * ── UN ÉCART ASSUMÉ AU MODÈLE DE L'APPLICATION ──────────────────────────────────────
 * Partout ailleurs, le droit est un INTERRUPTEUR : qui a la Lecture voit tout le
 * cabinet. Ici non. Les données de congé sont des données personnelles — un arrêt
 * maladie n'est pas une police —, et la visibilité par défaut se limite à ses propres
 * demandes. Un collaborateur qui n'est pas valideur ne voit que les siennes.
 *
 * ── POURQUOI UN CRITÈRE ET NON UN FILTRE EN MÉMOIRE ─────────────────────────────────
 * Le périmètre portefeuille, lui, filtre après coup : c'est un confort de lecture, et il
 * peut se permettre de fausser un décompte de pagination. Ici c'est une frontière : elle
 * doit être posée EN SQL, faute de quoi la pagination annoncerait « 3 sur 40 » à
 * quelqu'un qui n'a le droit d'en voir que trois — et le nombre lui-même serait déjà une
 * fuite.
 *
 * ── ET NON RETIRABLE ────────────────────────────────────────────────────────────────
 * Le critère est réinjecté par le contrôleur à CHAQUE requête, après la charge utile du
 * navigateur (cf. renderViewOrListComponent : array_merge($criteria, $extraCriteria)).
 * Un utilisateur qui efface le badge ne l'efface donc que pour lui-même.
 *
 * Source unique : le même critère sert l'écran et les outils de l'assistant, qui ne
 * peuvent ainsi pas diverger.
 */
final class CongeVisibiliteScope
{
    /** Le champ filtré : l'agent dont la demande porte le congé. */
    public const CHAMP_AGENT = 'agent';

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
    ) {
    }

    /**
     * L'invité voit-il les demandes de TOUT le cabinet ?
     *
     * Le niveau MODIFICATION sur la rubrique « Congés » est ce qui définit un valideur :
     * décider d'une demande, c'est la modifier. Le propriétaire, lui, bypasse tout —
     * c'est la règle du resolver, pas une exception locale.
     */
    public function voitToutLeCabinet(Invite $invite): bool
    {
        return $this->accessResolver->can($invite, 'DemandeConge', Invite::ACCESS_MODIFICATION);
    }

    /**
     * Critère restreignant la liste aux demandes de l'invité, ou tableau vide s'il a le
     * droit de tout voir.
     *
     * @return array<string, array{operator: string, value: int, label: string}>
     */
    public function critereFor(Invite $invite): array
    {
        if ($this->voitToutLeCabinet($invite)) {
            return [];
        }

        return [
            self::CHAMP_AGENT => [
                'operator' => '=',
                'value' => (int) $invite->getId(),
                'label' => 'Mes demandes',
            ],
        ];
    }
}
