<?php

namespace App\Services\Search;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\InviteRepository;
use App\Services\JSBDynamicSearchService;

/**
 * LES CRITÈRES PAR DÉFAUT D'UNE LISTE, et ceux que le serveur lui IMPOSE — source unique.
 *
 * La liste du workspace les pose à son ouverture (ControllerUtilsTrait), et Ket les lit pour
 * dire ce que l'écran montre quand il ouvre une rubrique sans filtre (`ouvrir_rubrique`).
 * Avant (refonte de la colonne 3, C2+), Ket annonçait une rubrique « ENTIÈRE » pendant que
 * l'écran n'en montrait que les tranches impayées de mon portefeuille.
 *
 * « Réinitialiser » ramène à ces critères (Serge, 2026-10-28).
 */
final class CriteresParDefautDeListe
{
    public function __construct(
        private readonly InviteRepository $inviteRepository,
        private readonly CongeVisibiliteScope $congeVisibiliteScope,
        private readonly PortefeuilleCritereFactory $portefeuilleCritereFactory,
        private readonly JSBDynamicSearchService $searchService,
    ) {
    }

    /**
     * Critères de recherche actifs par défaut au premier chargement d'une liste.
     *
     * Pour les rubriques soumises au « périmètre portefeuille » (cf. PortefeuilleScope :
     * Client, Piste, Cotation, Avenant, Sinistres, Tâche, Feedback…), on applique par défaut
     * le critère synthétique « Mon portefeuille » = l'invité connecté. L'invité ne voit alors
     * que les éléments rattachés — directement ou indirectement — à un portefeuille qu'il
     * gère. Le critère reste retirable (badge de la barre ou champ du dialogue avancé) ; le
     * libellé décrit le périmètre réel (nom du portefeuille, nombre, ou « aucun portefeuille »).
     *
     * @return array<string, mixed>
     */
    public function pour(string $entityClass, int $idInvite, Entreprise $entreprise): array
    {
        // UNE RUBRIQUE N'A PAS FORCÉMENT DE CLASSE (Production intermédiaires) : elle n'a alors
        // aucun défaut posé ici — celui de son statut, le serveur l'applique seul.
        if (!class_exists($entityClass)) {
            return [];
        }
        $shortName = (new \ReflectionClass($entityClass))->getShortName();
        $criteria = [];

        // Tranches : au premier chargement, la rubrique montre ce que les CLIENTS doivent
        // encore (prime impayée), trié par urgence (retard décroissant puis échéances
        // proches). Un seul axe est posé : les dettes de commission et de rétro ont leur
        // propre groupe de chips, à un clic. Critère retirable comme les autres (badge de la
        // barre ou dialogue avancé) → retour à « Toutes ».
        // Se COMBINE avec le périmètre portefeuille ci-dessous (Tranche y est soumise
        // au même titre que Cotation/Avenant : les deux badges coexistent).
        if ($shortName === 'Tranche') {
            $criteria += TranchePaiementScope::critereRecherche(
                'Tranche',
                [TranchePaiementScope::AXE_PRIME => TranchePaiementScope::IMPAYEE],
            );
        }

        // Notes : au premier chargement, la rubrique montre ce qu'il reste à ENCAISSER.
        // C'est la première question qu'on se pose en ouvrant ses notes, et le filtre est
        // retirable d'un clic (chip « Toutes » ou badge de la barre de recherche).
        //
        // ⚠ Ce critère ne se COMBINE avec aucun périmètre portefeuille : Note est
        // délibérément absente de PortefeuilleScope — une note de débit agrège les
        // commissions de plusieurs clients, souvent de plusieurs gestionnaires, et ce suivi
        // est au niveau du CABINET. La rubrique n'affichera donc qu'un seul badge, là où
        // Tranches en cumule deux. Ce n'est pas un oubli.
        if ($shortName === 'Note') {
            $criteria += NoteReglementScope::critereRecherche('Note', NoteReglementScope::IMPAYEE);
        }

        // Cotations (propositions) : au premier chargement, on met en avant le travail
        // commercial restant → les propositions « En attente » (non transformées en police).
        // Critère retirable comme les autres (badge de la barre ou dialogue avancé) → « Toutes ».
        // Se COMBINE avec le périmètre portefeuille ci-dessous (les deux badges coexistent).
        if ($shortName === 'Cotation') {
            $criteria[CotationSouscriptionScope::CRITERION_KEY] = [
                'operator' => '=',
                'value' => CotationSouscriptionScope::STATUT_EN_ATTENTE,
                'label' => CotationSouscriptionScope::libelle(CotationSouscriptionScope::STATUT_EN_ATTENTE),
            ];
        }

        // Pistes (opportunités) : au premier chargement, on met en avant le travail commercial
        // restant → les pistes « En cours » (aucune cotation encore transformée en police).
        // Même logique que Cotation, un cran plus haut. Retirable (badge/dialogue) → « Toutes »,
        // et se COMBINE avec le périmètre portefeuille ci-dessous.
        if ($shortName === 'Piste') {
            $criteria[PisteTransformationScope::CRITERION_KEY] = [
                'operator' => '=',
                'value' => PisteTransformationScope::STATUT_EN_COURS,
                'label' => PisteTransformationScope::libelle(PisteTransformationScope::STATUT_EN_COURS),
            ];
        }

        // CONGÉS : au premier chargement, un collaborateur qui n'est pas valideur ne voit
        // QUE ses demandes. Ce n'est pas un confort de lecture comme le périmètre
        // portefeuille, c'est une frontière : les données de congé sont des données
        // personnelles. Le critère est reposé à chaque rafraîchissement par
        // DemandeCongeController::query() (via $extraCriteria, fusionné APRÈS la charge
        // utile du navigateur) — effacer le badge ne le lève donc pas.
        if ($shortName === 'DemandeConge') {
            $invite = $this->inviteRepository->find($idInvite);
            if ($invite !== null) {
                $criteria += $this->congeVisibiliteScope->critereFor($invite);
            }
        }

        // Périmètre portefeuille : critère produit par la fabrique partagée avec les outils
        // de l'assistant IA (source unique — cf. PortefeuilleCritereFactory), pour que la
        // rubrique et Ket ne puissent plus diverger.
        $criteria += $this->portefeuilleCritereFactory->pourInviteId($shortName, $idInvite);

        // Avenants : au premier chargement, on met en avant l'urgence réelle. Les avenants
        // DÉJÀ EXPIRÉS sont les plus urgents à traiter → si le périmètre de l'invité en
        // contient au moins un, le chip « Échus » est actif par défaut ; sinon « Sous 30
        // jours ». La sonde réutilise le moteur de recherche (même interception SQL + même
        // scope, dont le portefeuille déjà posé ci-dessus) : zéro logique de scope dupliquée.
        // Critère retirable comme les autres (badge/dialogue avancé), et il se COMBINE au
        // périmètre portefeuille (les deux badges coexistent, comme pour Tranche).
        if ($shortName === 'Avenant') {
            $sonde = $criteria;
            $sonde[AvenantEcheanceScope::CRITERION_KEY] = [
                'operator' => '=', 'value' => AvenantEcheanceScope::STATUT_ECHUS,
            ];
            $aDesExpires = ($this->searchService->search($entityClass, $sonde, $entreprise, null, 1, 1)['totalItems'] ?? 0) > 0;

            $statutParDefaut = $aDesExpires ? AvenantEcheanceScope::STATUT_ECHUS : AvenantEcheanceScope::STATUT_30J;
            $criteria[AvenantEcheanceScope::CRITERION_KEY] = [
                'operator' => '=',
                'value' => $statutParDefaut,
                'label' => AvenantEcheanceScope::libelle($statutParDefaut),
            ];
        }

        return $criteria;
    }

    /**
     * Les critères que le serveur IMPOSE à chaque recherche de la liste : la frontière des
     * congés (données personnelles). Ils se lisent sur la ligne des filtres, sans × — les
     * retirer ne changerait rien.
     *
     * @return array<string, mixed>
     */
    public function imposes(string $entityClass, ?Invite $invite): array
    {
        if ($invite === null || !class_exists($entityClass)
            || (new \ReflectionClass($entityClass))->getShortName() !== 'DemandeConge') {
            return [];
        }

        return $this->congeVisibiliteScope->critereFor($invite);
    }
}
