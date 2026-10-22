<?php

namespace App\Services\Search;

use App\Entity\DemandeConge;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Invite;
use App\Entity\Tache;
use App\Service\Workspace\InviteCourant;
use App\Service\Workspace\WorkspaceAccessResolver;
use Doctrine\ORM\QueryBuilder;

/**
 * LA FRONTIÈRE DES TÂCHES (décisions de Serge du 2026-10-21) — source unique de la règle.
 *
 * Partout ailleurs, la frontière est le cabinet ; les tâches font exception, comme les congés
 * (CongeVisibiliteScope) : un invité ne voit une tâche que si elle le concerne. Elle le
 * concerne si son EXÉCUTANT ou son AUTEUR (`invite`, posé à la création — par Ket, c'est le
 * demandeur) appartient à son CERCLE : lui-même, son RESPONSABLE (`Invite::manager`, « je
 * travaille sous ses ordres ») et ses ASSISTANTS (un seul niveau) — ou si elle n'a pas
 * d'exécutant. Un administrateur (propriétaire ou délégué) voit tout.
 *
 * C'est une FRONTIÈRE, pas un confort : posée par le serveur à chaque porte (recherche — donc
 * liste, comptage, onglets, Ket —, fiche ouverte par lien, collections, pastilles, tableau de
 * bord, relevé de compte, statistiques et documents de Ket, échange). Les RETOURS et les
 * DOCUMENTS héritent de leur tâche ; un document rattaché AUSSI à une autre fiche reste
 * visible par ce chemin. Recalculée à chaque requête : aucun cache, un lien
 * responsable–assistant retiré agit aussitôt.
 *
 * LE LECTEUR (`lecteur()`, via InviteCourant) : un invité ; `null` — requête anonyme, ou
 * connecté sans invitation dans ce cabinet : AUCUNE tâche, pas même celles sans exécutant ;
 * `false` — contexte système EXPLICITE (commande), aucune frontière. Les portes passent par
 * les méthodes « …PourLeLecteur » : la conversion lecteur → règle n'est écrite qu'ici.
 *
 * UNE PIÈCE est visible si AU MOINS UN de ses rattachements l'est (décision de Serge du
 * 2026-10-21) : une fiche sans scope dédié compte comme visible (la frontière y est le
 * cabinet) ; une tâche, un retour et une demande de congé passent par LEUR scope. Une pièce
 * sans aucun rattachement relève du cabinet.
 */
final class TacheVisibiliteScope
{
    /** Les entités soumises à la frontière : la tâche, et ce qui en hérite. */
    public const ENTITES = ['Tache', 'Feedback', 'Document'];

    /**
     * Les rattachements d'un document qui le rendent visible PAR UN AUTRE CHEMIN que sa tâche.
     * Exclus : `tache` et `feedback` (le chemin hérité), `classeur` (un rangement, pas une
     * fiche), `entreprise` et `invite` (le cabinet, l'auteur). Tenus à jour par
     * TacheVisibiliteFrontiereTest (comparés aux métadonnées Doctrine).
     */
    public const AUTRES_RATTACHEMENTS_DU_DOCUMENT = [
        'pieceSinistre', 'offreIndemnisationSinistre', 'cotation', 'avenant', 'client', 'bordereau',
        'compteBancaire', 'piste', 'partenaire', 'paiement', 'paiementPrime', 'reversementRetroAgent',
        'fournisseur', 'assureur', 'autoriteFiscale', 'charge', 'chargeCourtier', 'chargement',
        'chargementPourPrime', 'conditionPartage', 'contact', 'demandeConge', 'depense', 'depenseCourtier',
        'entrepriseRattachee', 'evaluation', 'groupe', 'inviteRattache', 'modelePieceSinistre', 'monnaie',
        'note', 'notificationSinistre', 'objectif', 'operation', 'portefeuille', 'reglementTaxe',
        'revenuPourCourtier', 'risque', 'taxe', 'taxeVente', 'tranche', 'typeRevenu',
    ];

    /** Les rattachements d'une pièce qui ont LEUR scope (hors tâche et retour). */
    private const RATTACHEMENTS_A_SCOPE = ['demandeConge'];

    /** Le refus d'une réassignation qui ferait sortir la tâche du cercle de celui qui modifie. */
    public const REFUS_REASSIGNATION = "Vous ne pouvez confier cette tâche qu'à une personne de votre cercle.";

    private readonly CongeVisibiliteScope $conges;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly ?InviteCourant $inviteCourant = null,
    ) {
        // La règle des congés, la même que leurs listes (source unique).
        $this->conges = new CongeVisibiliteScope($accessResolver);
    }

    /** @return Invite|null|false l'invité ; null : le commun seulement ; false : contexte système */
    public function lecteur(Entreprise $entreprise): Invite|null|false
    {
        if ($this->inviteCourant === null) {
            // Échec FERMÉ : sans savoir qui lit, on ne décide pas qu'il voit tout.
            throw new \LogicException('TacheVisibiliteScope : InviteCourant non injecté.');
        }

        return $this->inviteCourant->pour($entreprise);
    }

    public function voitTout(Invite $invite): bool
    {
        return $this->accessResolver->estAdministrateurDuCabinet($invite);
    }

    /**
     * Le cercle de l'invité : lui, son responsable, ses assistants (un niveau).
     *
     * @return list<Invite>
     */
    public function cercle(Invite $invite): array
    {
        $cercle = [$invite];
        if ($invite->getManager() !== null) {
            $cercle[] = $invite->getManager();
        }
        foreach ($invite->getAssistants() as $assistant) {
            $cercle[] = $assistant;
        }

        return $cercle;
    }

    /** Sans invité (anonyme, non-membre du cabinet) : AUCUNE tâche, pas même le commun. */
    public function estVisible(Tache $tache, ?Invite $invite): bool
    {
        if ($invite === null) {
            return false;
        }
        if ($tache->getExecutor() === null || $this->voitTout($invite)) {
            return true;
        }
        foreach ($this->cercle($invite) as $membre) {
            if (self::meme($tache->getExecutor(), $membre) || self::meme($tache->getInvite(), $membre)) {
                return true;
            }
        }

        return false;
    }

    public function feedbackVisible(Feedback $feedback, ?Invite $invite): bool
    {
        return $feedback->getTache() === null || $this->estVisible($feedback->getTache(), $invite);
    }

    /** Une demande de congé : la sienne, ou toutes pour un valideur (CongeVisibiliteScope). */
    public function congeVisible(DemandeConge $conge, ?Invite $invite): bool
    {
        if ($invite === null) {
            return false;
        }

        return $this->conges->voitToutLeCabinet($invite) || self::meme($conge->getAgent(), $invite);
    }

    /** Visible si AU MOINS UN rattachement l'est ; sans aucun rattachement : le cabinet. */
    public function documentVisible(Document $document, ?Invite $invite): bool
    {
        $rattachee = false;
        if (($tache = $document->getTache()) !== null) {
            $rattachee = true;
            if ($this->estVisible($tache, $invite)) {
                return true;
            }
        }
        if (($feedback = $document->getFeedback()) !== null) {
            $rattachee = true;
            if ($this->feedbackVisible($feedback, $invite)) {
                return true;
            }
        }
        if (($conge = $document->getDemandeConge()) !== null) {
            $rattachee = true;
            if ($this->congeVisible($conge, $invite)) {
                return true;
            }
        }
        foreach (self::rattachementsSansScope() as $champ) {
            $getter = 'get' . ucfirst($champ);
            if (method_exists($document, $getter) && $document->{$getter}() !== null) {
                return true;
            }
        }

        return !$rattachee;
    }

    /** @return list<string> */
    private static function rattachementsSansScope(): array
    {
        return array_values(array_diff(self::AUTRES_RATTACHEMENTS_DU_DOCUMENT, self::RATTACHEMENTS_A_SCOPE));
    }

    /** Une fiche (tâche, retour, document) est-elle visible ? Toute autre entité : oui. */
    public function ficheVisible(object $entite, ?Invite $invite): bool
    {
        return match (true) {
            $entite instanceof Tache => $this->estVisible($entite, $invite),
            $entite instanceof Feedback => $this->feedbackVisible($entite, $invite),
            $entite instanceof Document => $this->documentVisible($entite, $invite),
            default => true,
        };
    }

    /** La fiche est-elle visible du lecteur COURANT (dans le cabinet de la fiche) ? */
    public function visiblePourLeLecteur(object $entite): bool
    {
        if (!self::estSoumise($entite)) {
            return true;
        }
        $entreprise = method_exists($entite, 'getEntreprise') ? $entite->getEntreprise() : null;
        $lecteur = $entreprise !== null ? $this->lecteur($entreprise) : null;

        return $lecteur === false || $this->ficheVisible($entite, $lecteur);
    }

    /**
     * Ne garde que ce que le lecteur courant peut voir (l'ordre est conservé).
     *
     * @template T of object
     * @param iterable<T> $elements
     * @return list<T>
     */
    public function filtrer(iterable $elements): array
    {
        $gardes = [];
        foreach ($elements as $element) {
            if (!\is_object($element) || $this->visiblePourLeLecteur($element)) {
                $gardes[] = $element;
            }
        }

        return $gardes;
    }

    /** Tâche, retour ou document : soumis à la frontière. */
    public static function estSoumise(object|string $entite): bool
    {
        $court = \is_object($entite) ? (new \ReflectionClass($entite))->getShortName() : (str_contains($entite, '\\') ? substr($entite, strrpos($entite, '\\') + 1) : $entite);

        return \in_array($court, self::ENTITES, true);
    }

    /**
     * La condition DQL « visible pour le lecteur courant », ou null s'il voit tout
     * (administrateur, contexte système) ou si l'entité n'est pas soumise.
     */
    public function conditionPourLeLecteur(QueryBuilder $qb, string $entite, string $alias, Entreprise $entreprise, string $suffixe): ?string
    {
        if (!self::estSoumise($entite)) {
            return null;
        }
        $lecteur = $this->lecteur($entreprise);
        if ($lecteur === false) {
            return null;
        }
        $court = str_contains($entite, '\\') ? substr($entite, strrpos($entite, '\\') + 1) : $entite;

        return $this->conditionDql($qb, $court, $alias, $lecteur, $suffixe);
    }

    /**
     * Les identifiants du cercle du lecteur courant, pour `conditionSurLaTache()` ; null s'il
     * voit tout (administrateur, contexte système).
     *
     * @return list<int>|null
     */
    public function cercleDuLecteur(Entreprise $entreprise): ?array
    {
        $lecteur = $this->lecteur($entreprise);

        return $lecteur === false ? null : $this->identifiantsDuCercle($lecteur);
    }

    /**
     * La condition DQL « visible pour cet invité », pour une requête dont la racine est
     * `$alias` (Tâche, Retour ou Document), ou null s'il voit tout. Sans invité : AUCUNE tâche
     * (un retour ou une pièce SANS tâche obéit à la même règle qu'en PHP, `ficheVisible`).
     * Parité PHP ↔ DQL tenue par les tests.
     */
    public function conditionDql(QueryBuilder $qb, string $entite, string $alias, ?Invite $invite, string $suffixe): ?string
    {
        $ids = $this->identifiantsDuCercle($invite); // null : toutes les tâches (administrateur)
        if ($ids === null && $entite !== 'Document') {
            return null;
        }
        $parametre = 'cercleTaches' . $suffixe;
        if ($ids !== null && $ids !== []) {
            $qb->setParameter($parametre, $ids);
        }
        $tacheVisible = static fn (string $t): string => $ids === null ? '1 = 1' : self::conditionSurLaTache($t, $parametre, $ids);

        if ($entite === 'Tache') {
            return $tacheVisible($alias);
        }

        $t = 'tvt' . $suffixe;
        $qb->leftJoin($alias . '.tache', $t);
        if ($entite === 'Feedback') {
            return sprintf('(%s.tache IS NULL OR %s)', $alias, $tacheVisible($t));
        }

        // Document : visible si AU MOINS UN rattachement l'est — une fiche sans scope dédié,
        // sa tâche, son retour, sa demande de congé —, ou s'il n'en a aucun.
        $f = 'tvf' . $suffixe;
        $ft = 'tvft' . $suffixe;
        $dc = 'tvdc' . $suffixe;
        $qb->leftJoin($alias . '.feedback', $f)->leftJoin($f . '.tache', $ft)->leftJoin($alias . '.demandeConge', $dc);
        $congeVisible = $this->conditionConge($qb, $dc, $invite, $suffixe);
        if ($ids === null && $congeVisible === '1 = 1') {
            return null;
        }
        $parUnAutre = implode(' OR ', array_map(static fn (string $c): string => sprintf('%s.%s IS NOT NULL', $alias, $c), self::rattachementsSansScope()));
        $aucun = implode(' AND ', array_map(
            static fn (string $c): string => sprintf('%s.%s IS NULL', $alias, $c),
            array_merge(['tache', 'feedback'], self::AUTRES_RATTACHEMENTS_DU_DOCUMENT),
        ));

        return sprintf(
            '((%1$s) OR (%2$s.tache IS NOT NULL AND %3$s) OR (%2$s.feedback IS NOT NULL AND (%4$s.tache IS NULL OR %5$s)) OR (%2$s.demandeConge IS NOT NULL AND %6$s) OR (%7$s))',
            $parUnAutre, $alias, $tacheVisible($t), $f, $tacheVisible($ft), $congeVisible, $aucun,
        );
    }

    /** La demande de congé (alias `$dc`) est-elle visible ? Même règle que `congeVisible()`. */
    private function conditionConge(QueryBuilder $qb, string $dc, ?Invite $invite, string $suffixe): string
    {
        if ($invite === null) {
            return self::RIEN;
        }
        if ($this->conges->voitToutLeCabinet($invite)) {
            return '1 = 1';
        }
        if ($invite->getId() === null) {
            return self::RIEN;
        }
        $qb->setParameter('congeLecteur' . $suffixe, $invite->getId());

        return sprintf('IDENTITY(%s.agent) = :congeLecteur%s', $dc, $suffixe);
    }

    /**
     * Les identifiants du cercle, à passer à `conditionSurLaTache()` ; null si le lecteur voit
     * tout (administrateur). Sans invité : [] — AUCUNE tâche.
     *
     * @return list<int>|null
     */
    public function identifiantsDuCercle(?Invite $invite): ?array
    {
        if ($invite === null) {
            return [];
        }
        if ($this->voitTout($invite)) {
            return null;
        }

        return array_values(array_filter(array_map(static fn (Invite $i) => $i->getId(), $this->cercle($invite))));
    }

    /** Condition DQL toujours fausse : rien de visible. */
    public const RIEN = '1 = 0';

    /**
     * LA condition DQL d'une tâche visible (alias `$t`), écrite UNE fois, pour un cercle donné
     * (`identifiantsDuCercle`). Le paramètre `:$parametre` n'est à poser que si
     * `$ids !== []` — un cercle vide ne voit rien et n'a pas de paramètre.
     *
     * @param list<int> $ids
     */
    public static function conditionSurLaTache(string $t, string $parametre, array $ids): string
    {
        if ($ids === []) {
            return self::RIEN;
        }

        return sprintf('(%1$s.executor IS NULL OR IDENTITY(%1$s.executor) IN (:%2$s) OR IDENTITY(%1$s.invite) IN (:%2$s))', $t, $parametre);
    }

    private static function meme(?Invite $a, ?Invite $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        return $a->getId() !== null && $a->getId() === $b->getId();
    }
}
