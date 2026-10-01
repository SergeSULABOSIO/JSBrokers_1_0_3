<?php

namespace App\Services\Note;

use App\Entity\Note;
use App\Services\CanvasBuilder;
use App\Services\Search\NoteReglementScope;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Filtrage des notes par état de RÈGLEMENT, en mémoire.
 *
 * ── POURQUOI EN MÉMOIRE, ET PAS EN SQL ──────────────────────────────────────────────
 * Le solde d'une note n'est jamais stocké. `Article` ne persiste qu'une quantité ; le
 * montant d'une ligne passe par `getArticleMontant()`, qui branche sur le type et le
 * destinataire de la note, le taux IARD ou VIE de la taxe, la fraction de l'échéance et
 * les rétrocommissions. Aucune clause WHERE ne peut exprimer cela.
 *
 * On emprunte donc le chemin de {@see \App\Services\Tranche\TranchePaiementService} :
 * requête SQL bornée (entreprise et autres critères), hydratation par lot, puis classement
 * et pagination en PHP.
 *
 * ── LA FORME DE RETOUR N'EST PAS NÉGOCIABLE ─────────────────────────────────────────
 * {@see filtrerTrierPaginer()} rend EXACTEMENT ce que rend
 * {@see \App\Services\JSBDynamicSearchService::search()} : c'est ce qui permet au moteur
 * d'y retourner directement, et aux gabarits de ne rien savoir de ce détour.
 *
 * ⚠ C'est aussi pourquoi {@see NoteRecouvrementService} ne pouvait pas servir : il rend
 * `['items', 'totaux', 'totalItems']`, et son périmètre est figé sur les notes de débit
 * validées adressées aux assureurs. Tranche a deux méthodes pour la même raison.
 *
 * ── L'ORDRE NE CHANGE PAS ───────────────────────────────────────────────────────────
 * Tranche trie par urgence parce qu'elle a des échéances ; une note n'en a pas. On
 * conserve donc l'ordre du chemin standard. Un chip doit changer CE QU'ON VOIT, jamais la
 * disposition : une liste qui se réordonne sous un filtre fait croire qu'on a changé d'écran.
 */
class NoteReglementService
{
    /**
     * Au-delà, on journalise. Le traitement continue : rendre une liste tronquée en
     * silence serait pire qu'une page lente — le courtier croirait avoir tout vu.
     */
    private const MAX_NOTES_EN_MEMOIRE = 5000;

    private LoggerInterface $logger;

    public function __construct(
        private readonly CanvasBuilder $canvasBuilder,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Filtre et pagine des notes déjà chargées, sur un état de règlement.
     *
     * @param Note[] $notes
     * @param string $valeur une valeur de {@see NoteReglementScope::ETATS}
     */
    public function filtrerTrierPaginer(array $notes, string $valeur, int $page = 1, int $limit = 20): array
    {
        $filtrees = $this->filtrer($notes, $valeur);
        $total = count($filtrees);

        return [
            'status' => [
                'error' => null,
                'code' => 200,
                'message' => 'Requête de filtre exécutée avec succès.',
            ],
            'data' => array_slice($filtrees, ($page - 1) * $limit, $limit),
            'totalItems' => $total,
            'currentPage' => $page,
            'totalPages' => max(1, (int) ceil($total / $limit)),
            'itemsPerPage' => $limit,
        ];
    }

    /**
     * Les notes qui sont dans cet état, dans l'ordre reçu.
     *
     * @param Note[] $notes
     * @return list<Note>
     */
    public function filtrer(array $notes, string $valeur): array
    {
        if (!NoteReglementScope::estValide($valeur)) {
            return array_values($notes);
        }

        $this->chargerIndicateurs($notes);

        return array_values(array_filter(
            $notes,
            static fn (Note $note): bool => NoteReglementScope::statut(
                (float) ($note->montantTotal ?? 0.0),
                (float) ($note->montantPaye ?? 0.0),
            ) === $valeur,
        ));
    }

    /**
     * Pose les valeurs calculées sur les notes, en UNE passe de préchargement.
     *
     * ⚠ `batchPreloadForCollection` n'est pas un confort : sans lui, chaque note rouvrirait
     * la base pour ses articles et ses paiements, et une rubrique de deux cents lignes
     * ferait quatre cents requêtes.
     *
     * Ces trois lignes sont l'idiome d'hydratation du projet, employé tel quel par le trait
     * des contrôleurs et par le suivi du recouvrement. On le recopie plutôt que de dépendre
     * d'un service dont le périmètre métier est tout autre.
     *
     * @param Note[] $notes
     */
    private function chargerIndicateurs(array $notes): void
    {
        if (count($notes) > self::MAX_NOTES_EN_MEMOIRE) {
            $this->logger->warning('[NoteReglement] Volume élevé de notes classées en mémoire.', [
                'nombre' => count($notes),
                'seuil' => self::MAX_NOTES_EN_MEMOIRE,
            ]);
        }

        $this->canvasBuilder->batchPreloadForCollection($notes);
        foreach ($notes as $note) {
            $this->canvasBuilder->loadAllCalculatedValues($note);
        }
    }
}
