<?php

namespace App\Ai\Boussole;

use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Tache;
use App\Entity\Tranche;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Note\NoteRecouvrementService;
use App\Services\Search\AvenantEcheanceScope;
use App\Services\Search\ChargeInviteCritereFactory;
use App\Services\Search\TranchePaiementScope;
use App\Services\Tranche\TranchePaiementService;

/**
 * LE PROGRAMME DU JOUR du courtier — la todo list par laquelle Ket ouvre la
 * conversation, dans le périmètre de l'invité connecté.
 *
 * Là où BoussoleService restitue des COMPTES (huit axes, un rappel d'une phrase
 * injecté à chaque message), ce service restitue des LIGNES ACTIONNABLES : quelles
 * tâches m'incombent aujourd'hui, quelles prochaines actions de feedback sont dues,
 * quelles polices arrivent à échéance, quelles primes réclamer, quelles commissions
 * facturer puis recouvrer.
 *
 * IL NE RECALCULE RIEN. Chaque section délègue à la source unique de son domaine
 * (TranchePaiementService, AvenantEcheanceScope, NoteRecouvrementService,
 * ChargeInviteCritereFactory) : le plan affiché et la rubrique à l'écran ne peuvent
 * pas diverger. Le barème de priorité est celui de la boussole
 * (BoussoleService::URGENCE) — le programme du jour et le rappel de fin de réponse
 * parlent donc de la même urgence.
 *
 * FAIL-CLOSED : une section n'apparaît que si l'invité a le droit de lire l'entité
 * sous-jacente. FAIL-SAFE : ce service tourne à l'ouverture du chat ; toute exception
 * d'une section est avalée (la section disparaît, le chat s'ouvre quand même).
 *
 * COÛT : appelé une fois à l'ouverture d'une conversation VIDE, jamais par message.
 */
final class PlanDuJourService
{
    /** Lignes restituées par section — au-delà, on annonce le reste sans le détailler. */
    public const MAX_LIGNES_PAR_SECTION = 8;

    /** Statuts temporels d'une ligne, du plus urgent au moins urgent. */
    public const EN_RETARD = 'en_retard';
    public const AUJOURDHUI = 'aujourdhui';
    public const A_VENIR = 'a_venir';

    /**
     * Entêtes de colonnes par défaut du tableau d'une section. Chaque section les
     * surcharge avec le vocabulaire de son domaine : une ligne de la section
     * « Polices à renouveler » n'est pas un « objet » rattaché à un « tiers »,
     * c'est une POLICE rattachée à un CLIENT et qui expire à une date. C'est le
     * service — seul à savoir de quelle entité il parle — qui nomme les colonnes ;
     * le template se contente de les afficher.
     */
    private const COLONNES_PAR_DEFAUT = [
        'objet' => 'Objet',
        'contexte' => 'Rattachement',
        'date' => 'Échéance',
        'montant' => 'Montant',
    ];

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly ChargeInviteCritereFactory $chargeCritere,
        private readonly TranchePaiementService $tranchePaiement,
        private readonly NoteRecouvrementService $noteRecouvrement,
    ) {
    }

    /**
     * Programme du jour de l'invité. `priorite` = la section actionnable la plus
     * urgente, sur laquelle Ket appuie son ouverture ; `toutAuVert` signale qu'il
     * n'y a rien à faire (ou rien de visible dans le périmètre), auquel cas
     * l'appelant doit retomber sur le message d'accueil ordinaire.
     *
     * @return array{date: string, sections: array<int, array>, priorite: ?array, toutAuVert: bool}
     */
    public function plan(Entreprise $entreprise, Invite $invite): array
    {
        $jour = new \DateTimeImmutable('today');

        $sections = array_merge(
            $this->section($invite, 'Tache', fn (): array => $this->sectionsTaches($entreprise, $invite, $jour)),
            $this->section($invite, 'Feedback', fn (): array => $this->sectionsFeedbacks($entreprise, $invite, $jour)),
            $this->section($invite, 'Avenant', fn (): array => $this->sectionsRenouvellements($entreprise, $invite, $jour)),
            $this->section($invite, 'Tranche', fn (): array => $this->sectionsTranches($entreprise, $invite, $jour)),
            $this->section($invite, 'Note', fn (): array => $this->sectionsNotes($entreprise, $jour)),
        );

        // Une section vide n'a rien à dire : le programme ne liste que ce qui appelle
        // une action. Tri par urgence décroissante, comme le barème de la boussole.
        $sections = array_values(array_filter($sections, static fn (array $s): bool => $s['compte'] > 0));
        usort($sections, static fn (array $a, array $b): int => $b['urgence'] <=> $a['urgence'] ?: strcmp($a['cle'], $b['cle']));

        return [
            'date' => $jour->format('Y-m-d'),
            'sections' => $sections,
            'priorite' => $sections[0] ?? null,
            'toutAuVert' => $sections === [],
        ];
    }

    /**
     * Enveloppe fail-closed (canRead) + fail-safe (exceptions avalées) d'un groupe
     * de sections : l'ouverture du chat ne doit JAMAIS échouer à cause du plan.
     *
     * @param callable(): array<int, array> $calcul
     * @return array<int, array>
     */
    private function section(Invite $invite, string $entite, callable $calcul): array
    {
        if (!$this->accessResolver->canRead($invite, $entite)) {
            return [];
        }
        try {
            return $calcul();
        } catch (\Throwable) {
            return [];
        }
    }

    // ------------------------------------------------------------------ Tâches

    /**
     * Deux sections distinctes, jamais fusionnées : ce qui m'est ASSIGNÉ (ou n'est
     * assigné à personne) d'abord, puis ce qui relève de MON PORTEFEUILLE sans
     * m'être assigné. Une tâche présente des deux côtés n'apparaît que dans la
     * première — c'est déjà à moi de la faire.
     *
     * @return array<int, array>
     */
    private function sectionsTaches(Entreprise $entreprise, Invite $invite, \DateTimeImmutable $jour): array
    {
        $assignees = $this->chercherTaches($entreprise, $this->chargeCritere->tachesAssignees($invite), $jour);

        $colonnes = ['objet' => 'Tâche', 'contexte' => 'Client', 'date' => 'À finir le'];

        $sections = [$this->composer(
            'taches_assignees',
            'Mes tâches',
            'assignées à moi ou à personne',
            BoussoleService::URGENCE['taches'],
            $assignees,
            null,
            $colonnes,
        )];

        // Sans portefeuille, le critère de périmètre serait vide et la requête
        // remonterait les tâches de toute l'entreprise : on s'abstient.
        if ($this->chargeCritere->aUnPortefeuille('Tache', $invite)) {
            $dejaVues = array_column($assignees['lignes'], 'id');
            $portefeuille = $this->chercherTaches($entreprise, $this->chargeCritere->tachesPortefeuille($invite), $jour, $dejaVues);

            $sections[] = $this->composer(
                'taches_portefeuille',
                'Tâches de mon portefeuille',
                'mes clients, assignées à un collègue',
                BoussoleService::URGENCE['taches'] - 1,
                $portefeuille,
                null,
                $colonnes,
            );
        }

        return $sections;
    }

    /**
     * Le COMPTE porte sur toutes les tâches ouvertes du jeu (« vous en avez 12 »),
     * les LIGNES sur les seules qui échoient dans l'horizon (« 5 à traiter cette
     * semaine ») : c'est une todo list de la journée, pas un inventaire.
     *
     * @param array<string, mixed> $criteres
     * @param int[] $exclureIds
     * @return array{lignes: array<int, array>, compte: int, echeant: int}
     */
    private function chercherTaches(Entreprise $entreprise, array $criteres, \DateTimeImmutable $jour, array $exclureIds = []): array
    {
        $ouvertes = $this->searchService->search(Tache::class, $criteres, $entreprise, null, 1, 1);
        $compte = $this->totalItems($ouvertes);

        $echeantes = $this->searchService->search(
            Tache::class,
            $this->chargeCritere->borneEcheance($criteres, $jour),
            $entreprise,
            null,
            1,
            self::MAX_LIGNES_PAR_SECTION * 4,
        );

        $taches = array_filter(
            $this->donnees($echeantes),
            static fn (Tache $t): bool => !in_array($t->getId(), $exclureIds, true)
        );

        // Le moteur trie par id décroissant : la plus urgente d'abord relève de nous.
        usort($taches, static fn (Tache $a, Tache $b): int => ($a->getToBeEndedAt()?->getTimestamp() ?? PHP_INT_MAX)
            <=> ($b->getToBeEndedAt()?->getTimestamp() ?? PHP_INT_MAX)
            ?: $a->getId() <=> $b->getId());

        $lignes = array_map(fn (Tache $t): array => [
            'id' => $t->getId(),
            'entite' => 'Tache',
            'libelle' => $this->tronquer((string) $t->getDescription()),
            'contexte' => $this->contexteTache($t),
            // Qui la traite : décisif pour la section « portefeuille » (assignée à
            // un collègue), et rassurant pour la mienne (« à personne » = à moi).
            'detail' => $t->getExecutor()?->getNom(),
            'date' => $t->getToBeEndedAt()?->format('Y-m-d'),
            'statutTemporel' => $this->statutTemporel($t->getToBeEndedAt(), $jour),
        ], array_slice($taches, 0, self::MAX_LIGNES_PAR_SECTION));

        return ['lignes' => $lignes, 'compte' => $compte, 'echeant' => count($taches)];
    }

    /** Client concerné, atteint par le seul rattachement renseigné de la tâche. */
    private function contexteTache(Tache $t): ?string
    {
        return $t->getPiste()?->getClient()?->getNom()
            ?? $t->getCotation()?->getPiste()?->getClient()?->getNom()
            ?? $t->getNotificationSinistre()?->getAssure()?->getNom()
            ?? $t->getOffreIndemnisationSinistre()?->getNotificationSinistre()?->getAssure()?->getNom();
    }

    // --------------------------------------------------------------- Feedbacks

    /**
     * Prochaines actions de feedback dues ou en retard — le seul « prochain pas »
     * daté du workspace. Auteur et portefeuille sont réunis en UNE section : quelle
     * que soit la porte d'entrée, l'action reste la même et ne doit être comptée
     * qu'une fois.
     *
     * @return array<int, array>
     */
    private function sectionsFeedbacks(Entreprise $entreprise, Invite $invite, \DateTimeImmutable $jour): array
    {
        $feedbacks = $this->donnees($this->searchService->search(
            Feedback::class,
            $this->chargeCritere->feedbacksAuteur($invite, $jour),
            $entreprise,
            null,
            1,
            self::MAX_LIGNES_PAR_SECTION * 4,
        ));

        if ($this->chargeCritere->aUnPortefeuille('Feedback', $invite)) {
            $feedbacks = array_merge($feedbacks, $this->donnees($this->searchService->search(
                Feedback::class,
                $this->chargeCritere->feedbacksPortefeuille($invite, $jour),
                $entreprise,
                null,
                1,
                self::MAX_LIGNES_PAR_SECTION * 4,
            )));
        }

        // Dédoublonnage : un feedback dont je suis l'auteur ET qui porte sur mon
        // client remonte par les deux requêtes.
        $uniques = [];
        foreach ($feedbacks as $feedback) {
            $uniques[$feedback->getId()] = $feedback;
        }
        $uniques = array_values($uniques);

        usort($uniques, static fn (Feedback $a, Feedback $b): int => ($a->getNextActionAt()?->getTimestamp() ?? PHP_INT_MAX)
            <=> ($b->getNextActionAt()?->getTimestamp() ?? PHP_INT_MAX)
            ?: $a->getId() <=> $b->getId());

        $lignes = array_map(fn (Feedback $f): array => [
            'id' => $f->getId(),
            'entite' => 'Feedback',
            'libelle' => $this->tronquer((string) ($f->getNextAction() ?: $f->getDescription())),
            'contexte' => $f->getTache() !== null ? $this->tronquer((string) $f->getTache()->getDescription(), 40) : null,
            'date' => $f->getNextActionAt()?->format('Y-m-d'),
            'statutTemporel' => $this->statutTemporel($f->getNextActionAt(), $jour),
        ], array_slice($uniques, 0, self::MAX_LIGNES_PAR_SECTION));

        return [$this->composer(
            'feedbacks_actions',
            'Prochaines actions à mener',
            'issues de mes feedbacks',
            BoussoleService::URGENCE['feedbacks'],
            ['lignes' => $lignes, 'compte' => count($uniques), 'echeant' => count($uniques)],
            null,
            ['objet' => 'Action', 'contexte' => 'Tâche liée', 'date' => 'Prévue le'],
        )];
    }

    // --------------------------------------------------------- Renouvellements

    /**
     * Polices échues puis sous 30 jours, dans cet ordre — le pipeline d'échéance
     * (AvenantEcheanceScope) écarte déjà celles dont le sort est scellé.
     *
     * @return array<int, array>
     */
    private function sectionsRenouvellements(Entreprise $entreprise, Invite $invite, \DateTimeImmutable $jour): array
    {
        $avenants = [];
        $compte = 0;
        $echus = 0;

        foreach ([AvenantEcheanceScope::STATUT_ECHUS, AvenantEcheanceScope::STATUT_30J] as $statut) {
            $resultat = $this->searchService->search(
                Avenant::class,
                AvenantEcheanceScope::critereRecherche('Avenant', $statut)
                    + $this->chargeCritere->criterePortefeuille('Avenant', $invite),
                $entreprise,
                null,
                1,
                self::MAX_LIGNES_PAR_SECTION,
            );

            $total = $this->totalItems($resultat);
            $compte += $total;
            if ($statut === AvenantEcheanceScope::STATUT_ECHUS) {
                $echus = $total;
            }
            // Le moteur trie déjà par endingAt croissant : les échus, puis les
            // imminents, arrivent dans l'ordre d'urgence.
            $avenants = array_merge($avenants, $this->donnees($resultat));
        }

        $lignes = array_map(fn (Avenant $a): array => [
            'id' => $a->getId(),
            'entite' => 'Avenant',
            'libelle' => $a->getReferencePolice() ?: ('Police #' . $a->getId()),
            'contexte' => $a->getCotation()?->getPiste()?->getClient()?->getNom(),
            // L'assureur commande la relance : c'est lui qu'on sollicite pour les
            // conditions de reconduction.
            'detail' => $a->getCotation()?->getAssureur()?->getNom(),
            'date' => $a->getEndingAt()?->format('Y-m-d'),
            'statutTemporel' => $this->statutTemporel($a->getEndingAt(), $jour),
        ], array_slice($avenants, 0, self::MAX_LIGNES_PAR_SECTION));

        return [$this->composer(
            'renouvellements',
            'Polices à renouveler',
            'échues ou sous 30 jours',
            $echus > 0 ? BoussoleService::URGENCE['renouvellements'] : 60,
            ['lignes' => $lignes, 'compte' => $compte, 'echeant' => $compte],
            null,
            ['objet' => 'Police', 'contexte' => 'Client', 'date' => 'Fin de validité'],
        )];
    }

    // ----------------------------------------------------------- Primes / com.

    /**
     * Deux sections adossées à TranchePaiementService : les primes que le client
     * doit encore régler, puis les commissions devenues exigibles — la prime est
     * payée, il est temps de facturer l'assureur.
     *
     * @return array<int, array>
     */
    private function sectionsTranches(Entreprise $entreprise, Invite $invite, \DateTimeImmutable $jour): array
    {
        // Axe PRIME SEULE, sans borner à l'échéance : le tri par urgence du service place
        // déjà les retards en tête, la section montre donc bien le plus pressant. Le titre
        // « dues par les clients » ne serait PAS vrai avec l'ancien filtre « impayées », qui
        // comptait aussi les dettes de commission (débiteur = l'assureur) tout en valorisant
        // le seul solde de prime — d'où un compte de 5 pour 1 seule prime réellement due.
        $primes = $this->tranchePaiement->lister(
            $entreprise,
            [TranchePaiementScope::AXE_PRIME => TranchePaiementScope::IMPAYEE],
            null,
            null,
            1,
            self::MAX_LIGNES_PAR_SECTION,
            $invite,
        );

        // Commission exigible = prime PAYÉE + commission impayée : sa définition même.
        $commissions = $this->tranchePaiement->lister(
            $entreprise,
            [
                TranchePaiementScope::AXE_PRIME => TranchePaiementScope::PAYEE,
                TranchePaiementScope::AXE_COMMISSION => TranchePaiementScope::IMPAYEE,
            ],
            null,
            null,
            1,
            self::MAX_LIGNES_PAR_SECTION,
            $invite,
        );

        return [
            $this->composer(
                'primes_dues',
                'Primes à encaisser',
                'dues par les clients',
                BoussoleService::URGENCE['primes_impayees'],
                $this->lignesTranches($primes, $jour, 'primeSoldeDue'),
                (float) ($primes['totaux']['totalSoldePrime'] ?? 0),
                ['objet' => 'Tranche', 'contexte' => 'Client', 'date' => 'Échéance', 'montant' => 'Solde dû'],
            ),
            $this->composer(
                'commissions_a_facturer',
                'Commissions à facturer',
                'prime encaissée, note de débit à émettre',
                BoussoleService::URGENCE['commissions'],
                $this->lignesTranches($commissions, $jour, 'solde_restant_du'),
                (float) ($commissions['totaux']['totalSoldeCommission'] ?? 0),
                ['objet' => 'Tranche', 'contexte' => 'Client', 'date' => 'Échéance', 'montant' => 'Commission'],
            ),
        ];
    }

    /**
     * @param array{items: Tranche[], totalItems: int} $resultat
     * @return array{lignes: array<int, array>, compte: int, echeant: int}
     */
    private function lignesTranches(array $resultat, \DateTimeImmutable $jour, string $champSolde): array
    {
        $lignes = array_map(fn (Tranche $t): array => [
            'id' => $t->getId(),
            'entite' => 'Tranche',
            'libelle' => $t->getNom() ?: ('Tranche #' . $t->getId()),
            'contexte' => $t->clientNom ?? $t->referencePolice ?? null,
            // Police (et assureur) : sans elle, deux tranches nommées « Tranche
            // unique » chez le même client sont indiscernables dans la liste.
            'detail' => $t->clientNom !== null ? ($t->referencePolice ?: $t->assureurNom) : $t->assureurNom,
            'date' => $t->getEcheanceAt()?->format('Y-m-d') ?? $t->getPayableAt()?->format('Y-m-d'),
            'statutTemporel' => $this->statutTemporel($t->getEcheanceAt() ?? $t->getPayableAt(), $jour),
            'montant' => round(max(0.0, (float) ($t->{$champSolde} ?? 0)), 2),
        ], $resultat['items']);

        $compte = (int) ($resultat['totalItems'] ?? 0);

        return ['lignes' => $lignes, 'compte' => $compte, 'echeant' => $compte];
    }

    // ------------------------------------------------------------ Recouvrement

    /**
     * Commissions FACTURÉES non encaissées — stade suivant de « commissions à
     * facturer ». Périmètre CABINET : une note de débit agrège les commissions de
     * plusieurs clients, elle n'est rattachable à aucun portefeuille. Le libellé
     * de périmètre le dit, pour ne pas laisser croire à un chiffre personnel.
     *
     * @return array<int, array>
     */
    private function sectionsNotes(Entreprise $entreprise, \DateTimeImmutable $jour): array
    {
        $resultat = $this->noteRecouvrement->lister($entreprise, 1, self::MAX_LIGNES_PAR_SECTION);

        $lignes = array_map(function (Note $n) use ($jour): array {
            $anciennete = $this->noteRecouvrement->joursAnciennete($n, $jour);
            $libelle = $n->getReference() ?: ($n->getNom() ?: ('Note #' . $n->getId()));

            return [
                'id' => $n->getId(),
                'entite' => 'Note',
                'libelle' => $libelle,
                'contexte' => $n->getAssureur()?->getNom(),
                // L'intitulé de la note, sauf quand il TIENT DÉJÀ lieu de libellé
                // (note sans référence) : jamais deux fois la même chaîne.
                'detail' => $n->getNom() !== $libelle ? $n->getNom() : null,
                'date' => ($n->getSentAt() ?? $n->getCreatedAt())?->format('Y-m-d'),
                // Une créance émise est due : l'ancienneté sert d'argument de relance.
                'statutTemporel' => ($anciennete ?? 0) > 0 ? self::EN_RETARD : self::AUJOURDHUI,
                'joursAnciennete' => $anciennete,
                'montant' => round(max(0.0, (float) ($n->solde ?? 0)), 2),
            ];
        }, $resultat['items']);

        return [$this->composer(
            'commissions_a_recouvrer',
            'Commissions à recouvrer',
            'notes de débit émises non encaissées — périmètre cabinet',
            BoussoleService::URGENCE['recouvrement_notes'],
            ['lignes' => $lignes, 'compte' => (int) $resultat['totalItems'], 'echeant' => (int) $resultat['totalItems']],
            (float) ($resultat['totaux']['totalSolde'] ?? 0),
            ['objet' => 'Note de débit', 'contexte' => 'Assureur', 'date' => 'Émise le', 'montant' => 'Solde'],
        )];
    }

    // ------------------------------------------------------------------ Outils

    /**
     * @param array{lignes: array<int, array>, compte: int, echeant: int} $contenu
     * @param array<string, string> $colonnes surcharge des entêtes (cf. COLONNES_PAR_DEFAUT)
     * @return array<string, mixed>
     */
    private function composer(string $cle, string $titre, string $perimetre, int $urgence, array $contenu, ?float $montant = null, array $colonnes = []): array
    {
        $section = [
            'cle' => $cle,
            'titre' => $titre,
            'perimetre' => $perimetre,
            'compte' => $contenu['compte'],
            'echeant' => $contenu['echeant'],
            'urgence' => $urgence,
            'colonnes' => $colonnes + self::COLONNES_PAR_DEFAUT,
            'lignes' => $contenu['lignes'],
            'tronque' => $contenu['echeant'] > count($contenu['lignes']),
            'enRetard' => count(array_filter(
                $contenu['lignes'],
                static fn (array $l): bool => ($l['statutTemporel'] ?? null) === self::EN_RETARD
            )),
        ];

        if ($montant !== null) {
            $section['montant'] = round($montant, 2);
        }

        return $section;
    }

    /** Position d'une date par rapport au jour de référence (horloge du projet). */
    private function statutTemporel(?\DateTimeInterface $date, \DateTimeImmutable $jour): ?string
    {
        if (!$date instanceof \DateTimeInterface) {
            return null;
        }

        // Ramenée à minuit : sinon l'heure de la date tronque un jour de retard.
        $date = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);

        return match (true) {
            $date < $jour => self::EN_RETARD,
            $date == $jour => self::AUJOURDHUI,
            default => self::A_VENIR,
        };
    }

    /** @return object[] Résultats d'une recherche, ou [] si le moteur a refusé. */
    private function donnees(array $resultat): array
    {
        return ($resultat['status']['code'] ?? 500) === 200 ? ($resultat['data'] ?? []) : [];
    }

    private function totalItems(array $resultat): int
    {
        return ($resultat['status']['code'] ?? 500) === 200 ? (int) ($resultat['totalItems'] ?? 0) : 0;
    }

    private function tronquer(string $texte, int $max = 80): string
    {
        return mb_strlen($texte) > $max ? mb_substr($texte, 0, $max - 1) . '…' : $texte;
    }
}
