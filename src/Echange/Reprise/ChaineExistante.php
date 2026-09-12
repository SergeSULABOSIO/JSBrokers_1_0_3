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
 *
 * ── UNE RÉFÉRENCE, PLUSIEURS RISQUES ────────────────────────────────────────────────
 * ⚠ LE RISQUE DÉPARTAGE LES POLICES D'UNE MÊME RÉFÉRENCE. Un contrat multirisque est suivi
 * en autant d'affaires qu'il couvre de risques ({@see CleNaturelle::pourChaine()}) : deux
 * polices de même référence et de même numéro ne sont plus une anomalie dès qu'elles
 * portent des risques différents. L'index range donc, sous chaque clé de police, TOUTES
 * les chaînes trouvées, et c'est le risque de la ligne qui choisit.
 */
final class ChaineExistante
{
    /**
     * @var array<int, array<string, array<int, array{avenant: int, cotation: ?int, piste: ?int, risques: string[]}>>>
     *      entreprise => clé de police => les chaînes qui la portent
     */
    private array $chaines = [];

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
        $this->echeances = [];
    }

    /**
     * LA CLÉ D'UNE POLICE : sa référence et son numéro d'avenant.
     *
     * ⚠ LE NUMÉRO EN FAIT PARTIE, comme dans {@see CleNaturelle::pourAvenant()}. Une
     * police et son avenant n° 2 partagent la référence : les confondre ferait porter à la
     * police les dates de son avenant.
     *
     * ⚠ LE RISQUE N'Y EST PAS, et c'est voulu : cette clé sert aussi à grouper les lignes
     * d'un palier, qui ne doit jamais couper un contrat — tous risques confondus. Le
     * risque départage ensuite les chaînes rangées sous elle.
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

        // ⚠ UNE POLICE SANS NUMÉRO EST L'AVENANT ZÉRO, ici comme à l'écriture : c'est la
        // même règle, et elle n'a qu'une source ({@see CleNaturelle::numeroOuDefaut()}).
        // Une police reprise sous « 0 » et cherchée sous « » serait recréée à chaque dépôt.
        return $reference . '|' . CleNaturelle::numeroOuDefaut($numeroAvenant);
    }

    /**
     * LA CHAÎNE DÉJÀ EN BASE pour cette police et ce risque — police, proposition,
     * opportunité.
     *
     * Rend `null` quand rien ne correspond : c'est une reprise neuve, et l'appelant crée.
     * Rend `null` AUSSI quand la clé est ambiguë — voir {@see estAmbigue()}, que l'appelant
     * interroge pour refuser en le disant, plutôt que de créer un doublon de plus.
     *
     * @return array{avenant: int, cotation: ?int, piste: ?int}|null
     */
    public function pour(?string $referencePolice, ?string $numeroAvenant, ?string $risque, Entreprise $entreprise): ?array
    {
        $candidates = $this->candidates($referencePolice, $numeroAvenant, $risque, $entreprise);
        if (count($candidates) !== 1) {
            return null;
        }

        $chaine = $candidates[0];
        unset($chaine['risques']);

        return $chaine;
    }

    /**
     * Plusieurs polices du cabinet répondent-elles à cette ligne ?
     *
     * ⚠ C'EST UN REFUS, PAS UN CHOIX AU HASARD. Deux polices de même référence, de même
     * numéro et de même risque sont une anomalie du portefeuille — probablement le doublon
     * d'une reprise antérieure. Y rattacher des échéances, ce serait décider laquelle des
     * deux est la bonne, et se tromper une fois sur deux en silence. Même chose pour une
     * ligne qui ne nomme pas son risque, face à une référence qui en couvre plusieurs.
     */
    public function estAmbigue(?string $referencePolice, ?string $numeroAvenant, ?string $risque, Entreprise $entreprise): bool
    {
        return count($this->candidates($referencePolice, $numeroAvenant, $risque, $entreprise)) > 1;
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
     * Les chaînes en base qui répondent à cette ligne.
     *
     * ── TROIS CAS, ET LE RISQUE DÉCIDE ─────────────────────────────────────────────
     *   1. la ligne ne nomme pas son risque → toutes les polices de la référence : une
     *      seule est une réponse, plusieurs sont une question qu'on ne tranche pas ;
     *   2. elle le nomme, et des polices portent ce risque → celles-là ;
     *   3. aucune ne le porte → les polices SANS risque connu, et elles seules.
     *
     * ⚠ LE TROISIÈME CAS PROTÈGE L'EXISTANT. Une police créée à la main, ou une police
     * qui a perdu sa proposition, n'a pas de risque en base : elle reste retrouvable par sa
     * seule référence, comme avant. Une police qui porte un AUTRE risque, en revanche,
     * n'est pas celle-ci — c'est un autre risque du même contrat, et la ligne crée le sien.
     *
     * @return array<int, array{avenant: int, cotation: ?int, piste: ?int, risques: string[]}>
     */
    private function candidates(?string $referencePolice, ?string $numeroAvenant, ?string $risque, Entreprise $entreprise): array
    {
        $cle = self::cle($referencePolice, $numeroAvenant);
        if ($cle === null) {
            return [];
        }

        $toutes = $this->indexDesPolices($entreprise)[$cle] ?? [];
        $voulu = ResolveurDeRenvois::normaliser((string) $risque);

        if ($voulu === '') {
            return $toutes;
        }

        $memeRisque = array_values(array_filter(
            $toutes,
            static fn (array $chaine): bool => in_array($voulu, $chaine['risques'], true),
        ));

        if ($memeRisque !== []) {
            return $memeRisque;
        }

        return array_values(array_filter(
            $toutes,
            static fn (array $chaine): bool => $chaine['risques'] === [],
        ));
    }

    /**
     * Les polices du cabinet, rangées sous leur clé.
     *
     * Une seule requête, en tableau : on ne veut que des identifiants et quelques textes,
     * et hydrater des milliers d'avenants pour les lire coûterait sans rien apporter.
     *
     * ⚠ LE RISQUE SE RECONNAÎT PAR SON NOM OU PAR SON CODE — les deux libellés que
     * {@see ResolveurDeRenvois} accepte pour le retrouver. « FAP » et « Fire and allied
     * perils » désignent le même risque : n'en retenir qu'un ferait recréer l'affaire au
     * premier classeur écrit avec l'autre.
     *
     * @return array<string, array<int, array{avenant: int, cotation: ?int, piste: ?int, risques: string[]}>>
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
                'r.nomComplet AS risqueNom',
                'r.code AS risqueCode',
            )
            ->from(Avenant::class, 'a')
            ->leftJoin('a.cotation', 'c')
            ->leftJoin('c.piste', 'p')
            ->leftJoin('p.risque', 'r')
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

            $risques = array_values(array_unique(array_filter([
                ResolveurDeRenvois::normaliser((string) ($ligne['risqueNom'] ?? '')),
                ResolveurDeRenvois::normaliser((string) ($ligne['risqueCode'] ?? '')),
            ], static fn (string $forme): bool => $forme !== '')));

            $index[$cle][] = [
                'avenant' => (int) $ligne['id'],
                'cotation' => isset($ligne['cotation']) ? (int) $ligne['cotation'] : null,
                'piste' => isset($ligne['piste']) ? (int) $ligne['piste'] : null,
                'risques' => $risques,
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
