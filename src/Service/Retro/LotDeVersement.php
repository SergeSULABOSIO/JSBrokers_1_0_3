<?php

namespace App\Service\Retro;

use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\ReversementRetroAgent;
use App\Repository\ReversementRetroAgentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN VIREMENT, UNE LIGNE, UNE PREUVE — la lecture par LOT des reversements.
 *
 * Cocher plusieurs affaires dans le picker n'enregistre pas plusieurs virements : les N
 * reversements partagent une `lotReference` posée par le serveur, et la comptabilité
 * n'émettra qu'UNE écriture — celle du virement réel. Le justificatif suit la même
 * logique : un bordereau pour le virement, pas un par affaire.
 *
 * ── LE FICHIER N'EST ÉCRIT QU'UNE FOIS ──────────────────────────────────────────────
 * D'où le PORTEUR : le membre de plus petit id du lot, celui qui reçoit la pièce. Le
 * choix est déterministe, donc recalculable à tout moment sans rien stocker de plus —
 * aucune colonne, aucune table de liaison, aucune migration. La référence qui relie les
 * lignes d'un même virement existait déjà.
 *
 * ── ON ÉCRIT SUR LE PORTEUR, ON LIT LE LOT ENTIER ───────────────────────────────────
 * Écrire sur le porteur est une convention de l'écran. Ket, elle, attache la pièce au
 * reversement que l'utilisateur NOMME, qui peut être n'importe quel membre : une lecture
 * limitée au porteur rendrait ces pièces invisibles. Toute relecture passe donc par
 * documentsDuLot(), qui prend l'union.
 *
 * ⚠ SUPPRIMER LE PORTEUR EMPORTE LA PREUVE DU LOT. La collection `documents` du
 * reversement est en `orphanRemoval` : effacer le membre qui porte le bordereau le détruit,
 * alors que les autres lignes du virement subsistent. C'est le prix assumé du
 * non-stockage redondant — l'aperçu d'un plan de suppression doit donc nommer la pièce.
 */
final class LotDeVersement
{
    /** Préfixe des lots d'un seul membre : un reversement isolé n'a pas de lotReference. */
    private const PREFIXE_SOLO = 'solo-';

    /** @var array<string, int> clé de lot => pièces, préchargées pour une page. */
    private array $comptesParLot = [];

    /** @var array<string, array{total: float, membres: int}> clé de lot => chiffres du virement. */
    private array $virementsParLot = [];

    /**
     * La liste en cours est-elle REPLIÉE — une ligne par virement ?
     *
     * Faux par défaut, et posé à vrai par la requête AU MOMENT où elle applique le
     * repli : le drapeau ne dit donc pas ce qu'on souhaite, il dit ce qui a été fait.
     * Sans lui, la colonne d'une ligne et la barre des totaux auraient répondu chacune
     * pour son compte — et le total de l'écran aurait triplé sous « Détail par
     * échéance », ou chuté au tiers sous la vue repliée.
     */
    private bool $replie = false;

    public function __construct(
        private readonly ReversementRetroAgentRepository $reversements,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * La clé du virement auquel appartient ce reversement.
     *
     * Un reversement isolé est traité comme un lot d'UN membre : sans cette
     * normalisation, chaque écran aurait deux cas à distinguer, et l'un des deux
     * finirait oublié.
     */
    public function cle(ReversementRetroAgent $reversement): string
    {
        $lot = trim((string) $reversement->getLotReference());

        return $lot !== '' ? $lot : self::PREFIXE_SOLO . $reversement->getId();
    }

    /**
     * Les reversements d'un agent, regroupés par virement, du plus récent au plus ancien.
     *
     * @return array<string, array{cle: string, membres: ReversementRetroAgent[], porteur: ReversementRetroAgent, total: float}>
     */
    public function grouper(Invite|Partenaire $beneficiaire, Entreprise $entreprise): array
    {
        $lots = [];
        foreach ($this->reversements->findPourBeneficiaire($beneficiaire, $entreprise) as $reversement) {
            $lots[$this->cle($reversement)]['membres'][] = $reversement;
        }

        $resultat = [];
        foreach ($lots as $cle => $donnees) {
            $membres = $this->trier($donnees['membres']);
            $resultat[$cle] = [
                'cle'     => $cle,
                'membres' => $membres,
                'porteur' => $this->porteurParmi($membres),
                'total'   => round(array_sum(array_map(
                    static fn (ReversementRetroAgent $r) => (float) $r->getMontant(),
                    $membres,
                )), 2),
            ];
        }

        return $resultat;
    }

    /**
     * Les membres d'un lot donné.
     *
     * @return ReversementRetroAgent[] vide si le lot n'appartient pas à cet agent
     */
    public function membres(Invite|Partenaire $beneficiaire, Entreprise $entreprise, string $cle): array
    {
        return $this->grouper($beneficiaire, $entreprise)[$cle]['membres'] ?? [];
    }

    /** La requête déclare ce qu'elle a fait : voir $replie. */
    public function marquerReplie(bool $replie): void
    {
        $this->replie = $replie;
    }

    /**
     * CE QUE CETTE LIGNE REPRÉSENTE, en argent.
     *
     * Repliée, elle vaut son VIREMENT entier ; dépliée, elle ne vaut qu'elle-même. La
     * somme des lignes affichées rend donc le décaissement réel dans les deux modes —
     * ce qui est exactement ce que la barre des totaux additionne.
     */
    public function montantAffiche(ReversementRetroAgent $reversement): float
    {
        return $this->replie
            ? $this->montantDuVirement($reversement)
            : round((float) $reversement->getMontant(), 2);
    }

    /** La liste est-elle lue à la maille du virement ? */
    public function litLesVirements(): bool
    {
        return $this->replie;
    }

    /**
     * LES MEMBRES DU VIREMENT AUQUEL CE REVERSEMENT APPARTIENT — la règle, un seul endroit.
     *
     * Elle sert à trois choses qui doivent dire la même : l'édition d'un virement (quelles
     * lignes rouvrir), sa suppression (un virement se défait en entier), et la relecture de
     * ses pièces. Trois copies auraient fini par diverger sur le cas du versement isolé.
     *
     * Un reversement isolé est un lot d'UN membre : lui-même. C'est la normalisation de
     * `cle()`, et elle évite à chaque appelant d'avoir deux cas à traiter.
     *
     * @return ReversementRetroAgent[] triés par id croissant — le premier est le porteur
     */
    public function membresDuLot(ReversementRetroAgent $reversement): array
    {
        $lot = trim((string) $reversement->getLotReference());
        if ($lot === '') {
            return [$reversement];
        }

        $membres = $this->reversements->findBy([
            'lotReference' => $lot,
            'entreprise' => $reversement->getEntreprise(),
        ]);

        // Le reversement de départ EN FAIT PARTIE, même si la requête ne l'a pas rendu —
        // il peut n'être pas encore écrit en base au moment de l'appel.
        if (!in_array($reversement, $membres, true)) {
            $membres[] = $reversement;
        }

        return $this->trier($membres);
    }

    /** Le membre qui porte la pièce : le plus petit id. Null si le lot est inconnu. */
    public function porteur(Invite|Partenaire $beneficiaire, Entreprise $entreprise, string $cle): ?ReversementRetroAgent
    {
        return $this->grouper($beneficiaire, $entreprise)[$cle]['porteur'] ?? null;
    }

    /**
     * Les pièces du virement, d'où qu'elles viennent.
     *
     * UNE requête pour tout le lot : parcourir `getDocuments()` membre par membre en
     * allumerait une par ligne.
     *
     * @param ReversementRetroAgent[] $membres
     *
     * @return Document[]
     */
    public function documentsDuLot(array $membres): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (ReversementRetroAgent $r) => $r->getId(),
            $membres,
        )));
        if ($ids === []) {
            return [];
        }

        return $this->em->getRepository(Document::class)->createQueryBuilder('d')
            ->where('d.reversementRetroAgent IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Le compte des pièces de chaque lot, en UNE agrégation.
     *
     * C'est ce qui permet au volet d'annoncer « 1 pièce » sans charger les documents, et
     * de montrer à zéro les versements enregistrés avant que la preuve soit exigée.
     *
     * @param array<string, array{membres: ReversementRetroAgent[]}> $lots
     *
     * @return array<string, int> clé de lot => nombre de pièces
     */
    public function comptesDePieces(array $lots): array
    {
        $parReversement = [];
        $tousLesIds = [];
        foreach ($lots as $cle => $lot) {
            foreach ($lot['membres'] as $membre) {
                $id = $membre->getId();
                if ($id !== null) {
                    $parReversement[$id] = $cle;
                    $tousLesIds[] = $id;
                }
            }
        }
        if ($tousLesIds === []) {
            return [];
        }

        $lignes = $this->em->getRepository(Document::class)->createQueryBuilder('d')
            ->select('IDENTITY(d.reversementRetroAgent) AS rid', 'COUNT(d.id) AS nb')
            ->where('d.reversementRetroAgent IN (:ids)')
            ->setParameter('ids', $tousLesIds)
            ->groupBy('d.reversementRetroAgent')
            ->getQuery()
            ->getScalarResult();

        $comptes = array_fill_keys(array_keys($lots), 0);
        foreach ($lignes as $ligne) {
            $cle = $parReversement[(int) $ligne['rid']] ?? null;
            if ($cle !== null) {
                $comptes[$cle] += (int) $ligne['nb'];
            }
        }

        return $comptes;
    }

    /**
     * PRÉCHARGE le compte de pièces d'une PAGE de reversements, en une requête.
     *
     * Appelé avant le calcul des indicateurs (CalculationProvider::batchPreload). Sans lui,
     * chaque ligne interrogerait son lot — et un lot de trois lignes en coûterait trois de
     * plus. C'est exactement le N+1 que `preloadAvenantRelations` combat pour les avenants.
     *
     * @param ReversementRetroAgent[] $reversements
     */
    public function prechargerJustificatifs(array $reversements): void
    {
        [$ids, $references] = $this->idsEtReferences($reversements);
        if ($ids === []) {
            return;
        }

        $comptes = [];
        foreach ($this->reversements->comptesDeJustificatifs($ids, $references) as $ligne) {
            $lot = trim((string) $ligne['lot']);
            // La clé d'un versement isolé est la sienne : même normalisation que cle().
            $cle = $lot !== '' ? $lot : self::PREFIXE_SOLO . $ligne['id'];
            $comptes[$cle] = ($comptes[$cle] ?? 0) + $ligne['nb'];
        }

        // On ÉCRASE plutôt qu'on ne fusionne : une page succède à une autre dans la même
        // requête (pagination, rafraîchissement), et un compte périmé se lirait comme un
        // compte à jour.
        $this->comptesParLot = $comptes;
    }

    /**
     * PRÉCHARGE les chiffres du VIREMENT d'une page — total versé et nombre d'échéances.
     *
     * Depuis que la rubrique replie chaque lot sur son porteur, une ligne annonce ce que
     * le virement a coûté et combien d'échéances il règle : les frères de lot ne sont
     * plus affichés, leur argent l'est. Même passe, même parade au N+1 que les pièces.
     *
     * @param ReversementRetroAgent[] $reversements
     */
    public function prechargerVirements(array $reversements): void
    {
        [$ids, $references] = $this->idsEtReferences($reversements);
        if ($ids === []) {
            return;
        }

        $this->virementsParLot = $this->replierLignes($this->reversements->lignesDeLots($ids, $references));
    }

    /**
     * LE TOTAL DU VIREMENT auquel ce reversement appartient.
     *
     * C'est lui que porte la colonne numérique d'une ligne repliée : afficher le montant
     * du seul porteur ferait chuter le total de la barre au tiers de la réalité.
     */
    public function montantDuVirement(ReversementRetroAgent $reversement): float
    {
        return $this->chiffresDuVirement($reversement)['total'];
    }

    /** Le nombre d'échéances que ce virement règle. */
    public function nombreEcheances(ReversementRetroAgent $reversement): int
    {
        return $this->chiffresDuVirement($reversement)['membres'];
    }

    /**
     * Lus dans le préchargement quand il y en a un ; à défaut — une fiche ouverte seule,
     * un appel isolé — une requête ciblée. Le repli existe pour que la valeur soit
     * toujours juste, jamais pour dispenser du préchargement en liste.
     *
     * @return array{total: float, membres: int}
     */
    private function chiffresDuVirement(ReversementRetroAgent $reversement): array
    {
        $cle = $this->cle($reversement);
        if (array_key_exists($cle, $this->virementsParLot)) {
            return $this->virementsParLot[$cle];
        }

        $lot = trim((string) $reversement->getLotReference());
        $replie = $this->replierLignes($this->reversements->lignesDeLots(
            $reversement->getId() !== null ? [$reversement->getId()] : [],
            $lot !== '' ? [$lot] : [],
        ));

        return $replie[$cle] ?? ['total' => (float) $reversement->getMontant(), 'membres' => 1];
    }

    /**
     * Les lignes brutes, repliées par clé de lot. La normalisation d'un versement isolé
     * est celle de `cle()` : une seule façon de nommer un lot dans tout ce service.
     *
     * @param array<int, array{id: int, lot: ?string, montant: float}> $lignes
     *
     * @return array<string, array{total: float, membres: int}>
     */
    private function replierLignes(array $lignes): array
    {
        $replie = [];
        foreach ($lignes as $ligne) {
            $lot = trim((string) $ligne['lot']);
            $cle = $lot !== '' ? $lot : self::PREFIXE_SOLO . $ligne['id'];
            $replie[$cle] ??= ['total' => 0.0, 'membres' => 0];
            $replie[$cle]['total'] = round($replie[$cle]['total'] + $ligne['montant'], 2);
            ++$replie[$cle]['membres'];
        }

        return $replie;
    }

    /**
     * Les identifiants d'une page et les références de lot qu'elle porte.
     *
     * @param ReversementRetroAgent[] $reversements
     *
     * @return array{0: int[], 1: string[]}
     */
    private function idsEtReferences(array $reversements): array
    {
        $ids = [];
        $references = [];
        foreach ($reversements as $reversement) {
            if (!$reversement instanceof ReversementRetroAgent || $reversement->getId() === null) {
                continue;
            }
            $ids[] = $reversement->getId();
            $lot = trim((string) $reversement->getLotReference());
            if ($lot !== '') {
                $references[] = $lot;
            }
        }

        return [$ids, $references];
    }

    /**
     * Le nombre de pièces qui justifient CE versement — c'est-à-dire son VIREMENT.
     *
     * Lu dans le préchargement quand il y en a un ; à défaut (une fiche ouverte seule, un
     * appel isolé), une requête ciblée sur ce seul lot. Le repli existe pour que la valeur
     * soit toujours juste, jamais pour dispenser du préchargement en liste.
     */
    public function compteDeJustificatifs(ReversementRetroAgent $reversement): int
    {
        $cle = $this->cle($reversement);
        if (array_key_exists($cle, $this->comptesParLot)) {
            return $this->comptesParLot[$cle];
        }

        $lot = trim((string) $reversement->getLotReference());
        $comptes = $this->reversements->comptesDeJustificatifs(
            [$reversement->getId()],
            $lot !== '' ? [$lot] : [],
        );

        $total = 0;
        foreach ($comptes as $ligne) {
            $total += $ligne['nb'];
        }

        return $this->comptesParLot[$cle] = $total;
    }

    /** Le libellé porté par la ligne de liste : « 1 pièce », « 3 pièces », ou « Aucune ». */
    public function libelleJustificatif(ReversementRetroAgent $reversement): string
    {
        $nb = $this->compteDeJustificatifs($reversement);

        return $nb === 0 ? 'Aucune pièce' : sprintf('%d pièce%s', $nb, $nb > 1 ? 's' : '');
    }
    /**
     * LES PIÈCES QUI JUSTIFIENT CHAQUE AFFAIRE — vu depuis la ligne du rapport.
     *
     * Une affaire peut avoir été payée par plusieurs virements successifs (versements
     * partiels) ; et un virement qui en solde trois justifie les trois avec la MÊME pièce.
     * Le compte d'une affaire est donc la somme des pièces des lots qui l'ont payée — et
     * la même pièce peut légitimement compter pour deux affaires : elle les justifie
     * toutes deux, sans être stockée deux fois.
     *
     * @return array<int, int> identifiant d'avenant => nombre de pièces
     */
    public function comptesDePiecesParAvenant(Invite|Partenaire $beneficiaire, Entreprise $entreprise): array
    {
        $lots = $this->grouper($beneficiaire, $entreprise);
        $parLot = $this->comptesDePieces($lots);

        $parAvenant = [];
        foreach ($lots as $cle => $lot) {
            $nb = $parLot[$cle] ?? 0;
            foreach ($lot['membres'] as $membre) {
                foreach ($this->avenantsCouvertsPar($membre) as $avenantId) {
                    $parAvenant[$avenantId] = ($parAvenant[$avenantId] ?? 0) + $nb;
                }
            }
        }

        return $parAvenant;
    }

    /**
     * LES AFFAIRES QU'UN VERSEMENT JUSTIFIE.
     *
     * Un reversement se rattache à une ÉCHÉANCE, à une AFFAIRE, ou aux deux — la maille a
     * changé le jour où la rémunération a suivi le rythme des paiements de prime. Ne lire
     * que l'avenant laissait donc hors de l'index tous les versements rattachés à la seule
     * tranche : leurs affaires s'affichaient « sans pièce » alors que le bordereau était
     * là. Sur un écran dont c'est la question — qu'ai-je versé sans justificatif ? — c'est
     * un mensonge, pas une approximation.
     *
     * On remonte donc par la tranche jusqu'à sa proposition, puis à ses affaires : c'est le
     * même chemin que celui du rapport, qui présente une ligne par affaire.
     *
     * @return int[] identifiants d'avenants, sans doublon
     */
    private function avenantsCouvertsPar(ReversementRetroAgent $reversement): array
    {
        $ids = [];

        $direct = $reversement->getAvenant()?->getId();
        if ($direct !== null) {
            $ids[$direct] = $direct;
        }

        foreach ($reversement->getTranche()?->getCotation()?->getAvenants() ?? [] as $avenant) {
            $id = $avenant->getId();
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Les pièces qui justifient UNE affaire : celles de tous les lots l'ayant payée.
     *
     * @return Document[]
     */
    public function documentsPourAvenant(Invite|Partenaire $beneficiaire, Entreprise $entreprise, int $avenantId): array
    {
        $membres = [];
        foreach ($this->grouper($beneficiaire, $entreprise) as $lot) {
            foreach ($lot['membres'] as $membre) {
                if ($membre->getAvenant()?->getId() === $avenantId) {
                    // Tout le lot, pas seulement la ligne : la pièce vit sur l'un de ses
                    // membres, et pas nécessairement celui qui porte cette affaire.
                    $membres = array_merge($membres, $lot['membres']);
                    break;
                }
            }
        }

        return $membres === [] ? [] : $this->documentsDuLot($membres);
    }
    /**
     * Le porteur d'un lot DÉJÀ EN MAIN — celui qu'on vient d'écrire, par exemple.
     *
     * Le picker en a besoin juste après avoir créé ses N reversements : les membres
     * sont là, sous la main, et les relire en base pour appliquer la même règle
     * serait une requête pour rien. La RÈGLE, elle, ne se recopie pas.
     *
     * @param ReversementRetroAgent[] $membres
     */
    public function porteurParmi(array $membres): ?ReversementRetroAgent
    {
        return $this->trier($membres)[0] ?? null;
    }

    /**
     * Tri par id croissant : c'est lui qui désigne le porteur, il ne peut donc pas
     * dépendre de l'ordre dans lequel la base a rendu les lignes.
     *
     * @param ReversementRetroAgent[] $membres
     *
     * @return ReversementRetroAgent[]
     */
    private function trier(array $membres): array
    {
        usort($membres, static fn (ReversementRetroAgent $a, ReversementRetroAgent $b) => $a->getId() <=> $b->getId());

        return array_values($membres);
    }
}
