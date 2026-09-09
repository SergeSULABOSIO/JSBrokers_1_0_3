<?php

namespace App\Echange\Reprise;

use App\Echange\Service\ResolveurDeRenvois;
use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\Tranche;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CE QUI EST DÉJÀ EN BASE, retrouvé par la même clé que celle du fichier.
 *
 * ── LE DÉFAUT QUE CETTE CLASSE FERME ────────────────────────────────────────────────
 * {@see CleNaturelle} fait converger les lignes D'UN MÊME FICHIER : quatre échéances
 * d'une police n'écrivent qu'une police. Mais son registre vit en mémoire, et meurt avec
 * la passe. Entre DEUX dépôts, plus rien ne convergeait.
 *
 * ⚠ ET C'EST LE CAS ORDINAIRE, PAS LE CAS LIMITE. Un portefeuille se reprend en
 * plusieurs fois — parce qu'on l'a découpé, parce qu'on corrige une ligne et qu'on
 * redépose, parce qu'on le complète six mois plus tard. Chacun de ces gestes recréait
 * l'opportunité, la proposition et la police. Rien ne cassait : le portefeuille doublait
 * de volume et les primes se comptaient deux fois, ce qui ne se voit qu'aux totaux.
 *
 * Cette classe est donc le pendant en base de `CleNaturelle` : même clé, même
 * normalisation, mais interrogée sur ce que le cabinet possède déjà.
 *
 * ── UN INDEX, PAS UNE REQUÊTE PAR LIGNE ─────────────────────────────────────────────
 * Le rattachement des libellés ({@see ResolveurDeRenvois::index()}) charge une fois les
 * couples (id, libellé) d'une ressource plutôt qu'une requête par ligne. On fait ici la
 * même chose, et pour la même raison : deux mille lignes produiraient deux mille
 * requêtes. Deux requêtes suffisent — les polices, puis les échéances.
 *
 * ⚠ LA NORMALISATION EST EMPRUNTÉE, JAMAIS RÉÉCRITE. Elle vient de
 * {@see ResolveurDeRenvois::normaliser()}, exactement comme celle de `CleNaturelle`. Si
 * ces trois-là découpaient différemment, « POL/2024-17 » serait retrouvée en base pour
 * une ligne et recréée pour la suivante — le doublon reviendrait par la porte qu'on
 * croyait fermer.
 */
final class ChaineExistante
{
    /**
     * @var array<int, array<string, array{avenant: int, cotation: ?int, piste: ?int}>>
     *      entreprise => clé de police => la chaîne trouvée
     */
    private array $chaines = [];

    /** @var array<int, array<string, true>> entreprise => clés portées par PLUSIEURS polices */
    private array $ambigues = [];

    /**
     * @var array<int, array<int, array<int, array{id: int, nom: string, payableAt: ?string, echeanceAt: ?string}>>>
     *      entreprise => proposition => ses échéances
     */
    private array $echeances = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Vide les index — le service est partagé, et la base a pu changer entre deux passes.
     *
     * ⚠ INDISPENSABLE ENTRE DEUX PALIERS D'ÉCRITURE. Le palier précédent vient de créer
     * des polices : les garder hors de l'index les ferait recréer par le suivant, ce qui
     * est précisément la faute que cette classe existe pour empêcher.
     */
    public function reinitialiser(): void
    {
        $this->chaines = [];
        $this->ambigues = [];
        $this->echeances = [];
    }

    /**
     * LA CLÉ D'UNE POLICE : sa référence et son numéro d'avenant.
     *
     * ⚠ LE NUMÉRO EN FAIT PARTIE, comme dans {@see CleNaturelle::pourAvenant()}. Une
     * police et son avenant n° 2 partagent la référence : les confondre ferait porter à la
     * police les dates de son avenant.
     *
     * Rend `null` sans référence : il n'y a alors rien à rapprocher, et une clé vide
     * ferait converger vers une même police tout ce qui n'en porte pas.
     */
    public static function cle(?string $referencePolice, ?string $numeroAvenant): ?string
    {
        $reference = ResolveurDeRenvois::normaliser((string) $referencePolice);
        if ($reference === '') {
            return null;
        }

        return $reference . '|' . ResolveurDeRenvois::normaliser((string) $numeroAvenant);
    }

    /**
     * LA CHAÎNE DÉJÀ EN BASE pour cette clé — police, proposition, opportunité.
     *
     * Rend `null` quand rien ne correspond : c'est une reprise neuve, et l'appelant crée.
     * Rend `null` AUSSI quand la clé est ambiguë — voir {@see estAmbigue()}, que l'appelant
     * interroge pour refuser en le disant, plutôt que de créer un doublon de plus.
     *
     * @return array{avenant: int, cotation: ?int, piste: ?int}|null
     */
    public function pour(?string $referencePolice, ?string $numeroAvenant, Entreprise $entreprise): ?array
    {
        $cle = self::cle($referencePolice, $numeroAvenant);
        if ($cle === null) {
            return null;
        }

        $index = $this->indexDesPolices($entreprise);

        return $index[$cle] ?? null;
    }

    /**
     * Plusieurs polices du cabinet portent-elles cette clé ?
     *
     * ⚠ C'EST UN REFUS, PAS UN CHOIX AU HASARD. Deux polices de même référence et de même
     * numéro sont une anomalie du portefeuille — probablement le doublon d'une reprise
     * antérieure. Y rattacher des échéances, ce serait décider laquelle des deux est la
     * bonne, et se tromper une fois sur deux en silence.
     */
    public function estAmbigue(?string $referencePolice, ?string $numeroAvenant, Entreprise $entreprise): bool
    {
        $cle = self::cle($referencePolice, $numeroAvenant);
        if ($cle === null) {
            return false;
        }

        $this->indexDesPolices($entreprise);

        return isset($this->ambigues[(int) $entreprise->getId()][$cle]);
    }

    /**
     * CE QUI DÉCRIT UNE ÉCHÉANCE dans une ligne : son nom, sa date de règlement, son
     * échéance. Les valeurs vides sont écartées.
     *
     * ⚠ CE N'EST PAS UNE CLÉ FIGÉE, ET C'EST DÉLIBÉRÉ. L'export peut être RESTREINT à
     * quelques colonnes : une clé composée des trois valeurs ne correspondrait alors à
     * rien en base — « Échéance 1 || » contre « Échéance 1 | 2026-01-15 | 2026-12-31 » —
     * et chaque dépôt partiel rajouterait des échéances sans jamais retrouver les
     * siennes. On compare donc SEULEMENT ce que le fichier porte.
     *
     * ⚠ ET LES DATES SE COMPARENT AU JOUR. Un aller-retour par Excel décale les heures ;
     * exiger la minute ferait échouer le rapprochement sur une échéance identique.
     *
     * @return array<string, string> vide quand rien ne distingue cette échéance
     */
    public static function signesDeLEcheance(?string $nom, ?string $payableAt, ?string $echeanceAt): array
    {
        return array_filter([
            'nom' => ResolveurDeRenvois::normaliser((string) $nom),
            'payableAt' => mb_substr((string) $payableAt, 0, 10),
            'echeanceAt' => mb_substr((string) $echeanceAt, 0, 10),
        ], static fn (string $valeur) => $valeur !== '');
    }

    /**
     * L'ÉCHÉANCE DÉJÀ EN BASE sous cette proposition, ou `null`.
     *
     * Trouvée, l'appelant la MODIFIE au lieu d'en ajouter une : c'est ce qui rend un
     * redépôt inoffensif. La règle qui veut que les soldes d'ouverture ne soient lus qu'à
     * la création protège alors seule contre le double encaissement.
     *
     * ⚠ UNE SEULE CANDIDATE, OU AUCUNE. Si deux échéances de la proposition répondent aux
     * mêmes signes, rien ne dit laquelle le fichier décrit : en choisir une reviendrait à
     * écraser l'autre une fois sur deux. On rend `null`, et l'appelant crée — ce qui est
     * la conduite la moins destructrice, à défaut d'être la plus économe.
     *
     * @param array<string, string> $signes de {@see signesDeLEcheance()}
     */
    public function echeance(?int $idCotation, array $signes, Entreprise $entreprise): ?int
    {
        if ($idCotation === null || $signes === []) {
            return null;
        }

        $candidates = [];
        foreach ($this->indexDesEcheances($entreprise)[$idCotation] ?? [] as $echeance) {
            foreach ($signes as $champ => $attendu) {
                if (($echeance[$champ] ?? null) !== $attendu) {
                    continue 2;
                }
            }
            $candidates[] = $echeance['id'];
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Les polices du cabinet, indexées par leur clé.
     *
     * Une seule requête, en tableau : on ne veut que des identifiants et deux textes, et
     * hydrater des milliers d'avenants pour les lire coûterait sans rien apporter.
     *
     * @return array<string, array{avenant: int, cotation: ?int, piste: ?int}>
     */
    private function indexDesPolices(Entreprise $entreprise): array
    {
        $idEntreprise = (int) $entreprise->getId();
        if (isset($this->chaines[$idEntreprise])) {
            return $this->chaines[$idEntreprise];
        }

        $lignes = $this->em->createQueryBuilder()
            ->select(
                'a.id AS id',
                'a.referencePolice AS reference',
                'a.numero AS numero',
                'IDENTITY(a.cotation) AS cotation',
                'IDENTITY(c.piste) AS piste',
            )
            ->from(Avenant::class, 'a')
            ->leftJoin('a.cotation', 'c')
            ->andWhere('a.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $index = [];
        foreach ($lignes as $ligne) {
            $cle = self::cle((string) ($ligne['reference'] ?? ''), (string) ($ligne['numero'] ?? ''));
            if ($cle === null) {
                continue;
            }

            if (isset($index[$cle])) {
                // Deux polices pour une clé : on le retient pour refuser explicitement,
                // plutôt que de rattacher à la première venue.
                $this->ambigues[$idEntreprise][$cle] = true;
                continue;
            }

            $index[$cle] = [
                'avenant' => (int) $ligne['id'],
                'cotation' => isset($ligne['cotation']) ? (int) $ligne['cotation'] : null,
                'piste' => isset($ligne['piste']) ? (int) $ligne['piste'] : null,
            ];
        }

        return $this->chaines[$idEntreprise] = $index;
    }

    /**
     * Les échéances du cabinet, rangées sous leur proposition.
     *
     * @return array<int, array<int, array{id: int, nom: string, payableAt: ?string, echeanceAt: ?string}>>
     */
    private function indexDesEcheances(Entreprise $entreprise): array
    {
        $idEntreprise = (int) $entreprise->getId();
        if (isset($this->echeances[$idEntreprise])) {
            return $this->echeances[$idEntreprise];
        }

        $lignes = $this->em->createQueryBuilder()
            ->select(
                't.id AS id',
                't.nom AS nom',
                't.payableAt AS payableAt',
                't.echeanceAt AS echeanceAt',
                'IDENTITY(t.cotation) AS cotation',
            )
            ->from(Tranche::class, 't')
            ->andWhere('t.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->orderBy('t.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $index = [];
        foreach ($lignes as $ligne) {
            $cotation = $ligne['cotation'] ?? null;
            if ($cotation === null) {
                continue;
            }

            $index[(int) $cotation][] = [
                'id' => (int) $ligne['id'],
                'nom' => ResolveurDeRenvois::normaliser((string) ($ligne['nom'] ?? '')),
                'payableAt' => $ligne['payableAt'] instanceof \DateTimeInterface
                    ? $ligne['payableAt']->format('Y-m-d')
                    : null,
                'echeanceAt' => $ligne['echeanceAt'] instanceof \DateTimeInterface
                    ? $ligne['echeanceAt']->format('Y-m-d')
                    : null,
            ];
        }

        return $this->echeances[$idEntreprise] = $index;
    }
}
