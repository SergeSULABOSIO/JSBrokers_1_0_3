<?php

namespace App\Services\Search;

use App\Entity\Invite;
use App\Entity\Portefeuille;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fabrique du critère synthétique « Mon portefeuille » (PortefeuilleScope::CRITERION_KEY).
 *
 * SOURCE UNIQUE de ce filtre par défaut, partagée par ses DEUX consommateurs :
 *  - les listes du workspace (ControllerUtilsTrait::getInitialSearchCriteria) ;
 *  - les outils de données de l'assistant IA (compter_entites, rechercher_entites,
 *    suivi_impayes).
 *
 * Raison d'être : tant que chaque consommateur construisait son propre jeu de critères, Ket
 * et la rubrique affichée pouvaient répondre des nombres différents à la même question — un
 * avenant appartenant au portefeuille d'un autre gestionnaire était compté par l'assistant
 * mais invisible à l'écran. En passant par cette fabrique, les deux chemins posent
 * littéralement le même critère, donc le même SQL (cf. le CASE 0 de JSBDynamicSearchService)
 * et le même résultat.
 */
final class PortefeuilleCritereFactory
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Critère de périmètre pour une entité (nom court) et un invité donnés. Tableau VIDE si
     * l'entité n'est pas soumise au périmètre portefeuille (cf. PortefeuilleScope::PATHS) ou
     * si l'invité est inconnu — le filtre est alors simplement absent.
     *
     * @return array<string, array{operator: string, value: int, label: string}>
     */
    public function pour(string $entityShortName, ?Invite $invite): array
    {
        if ($invite === null || $invite->getId() === null || !PortefeuilleScope::isScopable($entityShortName)) {
            return [];
        }

        return [PortefeuilleScope::CRITERION_KEY => [
            'operator' => '=',
            'value' => $invite->getId(),
            'label' => $this->libelle($invite),
        ]];
    }

    /**
     * Idem à partir d'un identifiant d'invité (chemin des contrôleurs, qui ne manipulent que
     * l'id). Retourne un tableau vide si l'invité n'existe plus.
     *
     * @return array<string, array{operator: string, value: int, label: string}>
     */
    public function pourInviteId(string $entityShortName, int $idInvite): array
    {
        if (!PortefeuilleScope::isScopable($entityShortName)) {
            return [];
        }

        return $this->pour($entityShortName, $this->em->getRepository(Invite::class)->find($idInvite));
    }

    /**
     * Libellé du périmètre réel de l'invité : nom du portefeuille quand il n'en gère qu'un,
     * décompte au-delà, et mention explicite quand il n'en gère aucun (la liste est alors
     * vide à l'écran — l'assistant doit pouvoir l'expliquer plutôt que d'annoncer un zéro sec).
     */
    public function libelle(Invite $invite): string
    {
        $portefeuilles = $this->em->getRepository(Portefeuille::class)->findBy(['gestionnaire' => $invite]);
        $nb = count($portefeuilles);

        return match (true) {
            $nb === 1 => (string) $portefeuilles[0]->getNom(),
            $nb > 1 => $nb . ' portefeuilles',
            default => 'aucun portefeuille',
        };
    }
}
