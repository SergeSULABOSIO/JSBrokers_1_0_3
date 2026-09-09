<?php

namespace App\Ai\Acces;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Token\TokenAccountService;

/**
 * @file À quelles conditions Ket est disponible.
 * @description Deux verrous, et ils ne bougent pas :
 *  - le MODULE : la pseudo-entité `AssistantIa` doit être dans le périmètre de
 *    lecture de l'invité (rôle d'administration) ;
 *  - le PREMIUM : l'entreprise doit disposer d'un solde de tokens payant —
 *    l'allocation gratuite ne suffit pas.
 *
 * ── POURQUOI CETTE CLASSE EXISTE ───────────────────────────────────────────
 * Ces deux tests vivaient uniquement dans les méthodes privées
 * d'`AssistantIaController`. Depuis que le terminal décide de la surface, une
 * SECONDE porte s'ouvre sur Ket : l'espace de travail mobile, servi par un
 * autre contrôleur. Deux portes qui recopient la même condition finissent par
 * la lire différemment — et le jour où la règle du premium change, l'une des
 * deux laisse passer. La condition est donc écrite ici, une fois.
 *
 * ⚠ CE N'EST PAS LA GARDE, C'EST LA MÊME GARDE À UN SEUL ENDROIT. Chaque API de
 * l'assistant continue de se protéger elle-même (fail-closed) ; cette classe ne
 * fait que dire ce que ces gardes vérifient déjà, pour que la porte d'entrée et
 * les APIs racontent la même histoire.
 */
final class PorteDeKet
{
    /** Le module n'est pas dans le périmètre de l'invité. */
    public const MOTIF_MODULE = 'module';

    /** Le compte n'a pas de solde de tokens payant. */
    public const MOTIF_PREMIUM = 'premium';

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly TokenAccountService $tokenAccountService,
    ) {
    }

    /** L'invité a-t-il le module « Assistant IA » dans son périmètre ? */
    public function moduleAutorise(?Invite $invite): bool
    {
        return $invite !== null && $this->accessResolver->canRead($invite, 'AssistantIa');
    }

    /** L'entreprise dispose-t-elle d'un solde de tokens payant ? */
    public function comptePayant(Entreprise $entreprise): bool
    {
        return $this->tokenAccountService->estComptePayant($entreprise);
    }

    /**
     * Pourquoi la porte est fermée, ou `null` si elle est ouverte.
     *
     * L'ordre compte : le module d'abord. Un invité sans le droit n'a pas à
     * apprendre l'état du solde de son cabinet, et surtout pas à se voir
     * proposer un achat qu'il ne peut pas faire.
     */
    public function motifDeFermeture(?Invite $invite, Entreprise $entreprise): ?string
    {
        if (!$this->moduleAutorise($invite)) {
            return self::MOTIF_MODULE;
        }

        return $this->comptePayant($entreprise) ? null : self::MOTIF_PREMIUM;
    }

    public function estOuverte(?Invite $invite, Entreprise $entreprise): bool
    {
        return $this->motifDeFermeture($invite, $entreprise) === null;
    }

    /**
     * L'invité est-il le PROPRIÉTAIRE du cabinet ? Seul lui peut acheter des
     * tokens : c'est ce qui décide si le CTA d'achat s'affiche ou si le message
     * invite à s'adresser au propriétaire.
     */
    public function estProprietaire(?Invite $invite, Entreprise $entreprise): bool
    {
        return $invite !== null
            && $entreprise->getUtilisateur()?->getId() === $invite->getUtilisateur()?->getId();
    }
}
