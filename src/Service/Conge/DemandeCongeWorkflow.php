<?php

namespace App\Service\Conge;

use App\Entity\DemandeConge;
use App\Entity\HistoriqueDemande;
use App\Entity\Invite;
use App\Entity\MouvementConge;
use App\Repository\MouvementCongeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE CYCLE DE VIE D'UNE DEMANDE DE CONGÉ — source unique des transitions.
 *
 *     BROUILLON ──soumettre──▶ SOUMISE ──approuver──▶ APPROUVEE
 *                                  │                      │
 *                                  └──refuser──▶ REFUSEE  └──annuler──▶ ANNULEE
 *
 * ── UN SEUL ÉCRIVAIN, DEUX CANAUX ───────────────────────────────────────────────────
 * L'écran et l'assistant appellent tous deux ces méthodes. Ce sont donc EXACTEMENT les
 * mêmes lignes qui naissent en base d'un côté comme de l'autre — c'est ce qui rend le
 * test de parité vrai par construction plutôt que surveillé à la main.
 *
 * ── CE QUE CHAQUE TRANSITION LAISSE DERRIÈRE ELLE ───────────────────────────────────
 * Toute transition écrit une ligne d'HISTORIQUE, sans exception. Les transitions qui
 * touchent au compteur — approbation, annulation d'une absence approuvée — écrivent en
 * plus un MOUVEMENT. Ces deux écritures sont faites ici, et nulle part ailleurs : c'est
 * ce qui garantit qu'un compteur ne peut pas bouger sans laisser de trace, ni une trace
 * exister sans son compteur.
 *
 * ── IL N'Y A PAS D'ÉTAT « CONSOMMÉE » ───────────────────────────────────────────────
 * Le mouvement de prise est écrit à l'APPROBATION, pas au retour de la date. Aucune
 * tâche planifiée n'est donc nécessaire pour tenir le compteur à jour — et aucune tâche
 * planifiée ne peut donc manquer de tourner.
 *
 * ── ON NE FLUSHE JAMAIS ICI ─────────────────────────────────────────────────────────
 * L'appelant maîtrise sa transaction : le contrôleur passe par
 * WorkspaceMutationService::commitWrite(), qui mètre les tokens avant d'écrire. Flusher
 * ici court-circuiterait ce métrage.
 */
class DemandeCongeWorkflow
{
    public const DECISION_APPROUVER = 'approuver';
    public const DECISION_REFUSER = 'refuser';

    /** @var array<string, string> décision => statut atteint */
    private const STATUT_DE_LA_DECISION = [
        self::DECISION_APPROUVER => DemandeConge::STATUT_APPROUVEE,
        self::DECISION_REFUSER => DemandeConge::STATUT_REFUSEE,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DemandeCongePolicy $policy,
        private readonly DemandeCongeValidator $validator,
        private readonly CalculateurJoursOuvrables $calculateurJours,
        private readonly MouvementCongeRepository $mouvementRepository,
        private readonly \App\Repository\HistoriqueDemandeRepository $historiqueRepository,
    ) {
    }

    // ─────────────────────────────── SOUMISSION ────────────────────────────────────

    /**
     * Fige le décompte de la demande à partir de sa période.
     *
     * À faire AVANT tout contrôle : le contrôle de solde porte sur ce nombre, et un
     * décompte périmé validerait une demande sur des jours qu'elle ne coûte plus.
     */
    public function figerLeDecompte(DemandeConge $demande): float
    {
        $jours = $this->calculateurJours->calculer(
            $demande->getAgent(),
            $demande->getDateDebut(),
            $demande->getDateFin(),
            $demande->isDemiJourneeDebut(),
            $demande->isDemiJourneeFin(),
        );

        $demande->setNbJours(number_format($jours, 1, '.', ''));

        return $jours;
    }

    /**
     * Ce qui empêcherait cette demande d'être soumise. Le décompte est figé au passage :
     * on ne peut pas se prononcer sur une demande sans savoir ce qu'elle coûte.
     *
     * @return string[]
     */
    public function verifierSoumission(DemandeConge $demande, Invite $acteur): array
    {
        $violations = [];

        if (!in_array($demande->getStatut(), [DemandeConge::STATUT_BROUILLON, DemandeConge::STATUT_SOUMISE], true)) {
            $violations[] = sprintf(
                'Cette demande est déjà %s : elle ne peut plus être soumise.',
                mb_strtolower(\App\Services\Search\CongeStatutScope::libelle($demande->getStatut())),
            );

            return $violations;
        }

        if (!$this->policy->peutModifier($acteur, $demande) && !$this->estLeSien($acteur, $demande)) {
            $violations[] = "Vous ne pouvez pas soumettre la demande d'un autre collaborateur.";

            return $violations;
        }

        $this->figerLeDecompte($demande);

        // TROIS CONTRÔLES SONT CONTOURNABLES PAR UN VALIDEUR — préavis, absents
        // simultanés, période de blocage. Pour lui ils ne refusent pas : ils signalent,
        // et le signalement est CONSERVÉ sur la demande puis repris dans le mail. Un
        // contournement silencieux se découvre toujours trop tard.
        $controle = $this->validator->controler($demande, $this->policy->estValideur($acteur));
        $demande->setControlesContournes($controle->contournementsEnTexte());

        return $controle->violations;
    }

    /**
     * Soumet la demande.
     *
     * ── AUTO-APPROBATION (RG-01) ────────────────────────────────────────────────────
     * Nul ne valide sa propre demande. Mais lorsque le demandeur est le SEUL valideur du
     * cabinet — le cas du propriétaire, qui n'a personne au-dessus de lui —, sa demande
     * resterait indéfiniment en attente de quelqu'un qui n'existe pas. Elle est donc
     * enregistrée directement approuvée, et portée « auto-approuvée » dans l'historique
     * comme dans le mail : c'est une approbation, et elle ne doit pas se faire passer
     * pour une validation ordinaire.
     *
     * @throws CongeTransitionException
     */
    public function soumettre(DemandeConge $demande, Invite $acteur, string $origine = DemandeConge::ORIGINE_UI): HistoriqueDemande
    {
        $violations = $this->verifierSoumission($demande, $acteur);
        if ($violations !== []) {
            throw new CongeTransitionException($violations);
        }

        $agent = $demande->getAgent();
        $statutAvant = $demande->getStatut();
        $autoApprouvee = $agent !== null && $this->policy->estSeulValideur($agent) && $this->estLeSien($acteur, $demande);

        if ($autoApprouvee) {
            $demande->setStatut(DemandeConge::STATUT_APPROUVEE);
            $demande->setValideur($agent);
            $demande->setDateDecision(new \DateTimeImmutable('now'));
        } else {
            $demande->setStatut(DemandeConge::STATUT_SOUMISE);
        }

        $demande->setOrigine($origine);

        $historique = $this->tracer($demande, $statutAvant, $acteur, $origine, $demande->getMotif(), $autoApprouvee);

        if ($autoApprouvee) {
            $this->ecrireLaPrise($demande, $acteur, $origine);
        }

        return $historique;
    }

    // ──────────────────────────────── DÉCISION ─────────────────────────────────────

    /**
     * Ce qui empêcherait cette décision.
     *
     * @return string[]
     */
    public function verifierDecision(DemandeConge $demande, Invite $acteur, string $decision): array
    {
        if (!isset(self::STATUT_DE_LA_DECISION[$decision])) {
            return [sprintf('Décision inconnue : « %s ».', $decision)];
        }

        if ($demande->getStatut() !== DemandeConge::STATUT_SOUMISE) {
            return [sprintf(
                'Cette demande est %s : il n\'y a plus de décision à rendre.',
                mb_strtolower(\App\Services\Search\CongeStatutScope::libelle($demande->getStatut())),
            )];
        }

        if ($this->estLeSien($acteur, $demande)) {
            return ['Vous ne pouvez pas décider de votre propre demande de congé.'];
        }

        if (!$this->policy->peutDecider($acteur, $demande)) {
            return ["Vous n'avez pas le droit de décider des demandes de congé de ce cabinet."];
        }

        return [];
    }

    /**
     * Approuve ou refuse la demande.
     *
     * @throws CongeTransitionException
     */
    public function decider(
        DemandeConge $demande,
        Invite $acteur,
        string $decision,
        ?string $commentaire = null,
        string $origine = DemandeConge::ORIGINE_UI,
    ): HistoriqueDemande {
        $violations = $this->verifierDecision($demande, $acteur, $decision);
        if ($violations !== []) {
            throw new CongeTransitionException($violations);
        }

        $statutAvant = $demande->getStatut();

        $demande->setStatut(self::STATUT_DE_LA_DECISION[$decision]);
        $demande->setValideur($acteur);
        $demande->setDateDecision(new \DateTimeImmutable('now'));
        $demande->setCommentaireDecision($commentaire);

        $historique = $this->tracer($demande, $statutAvant, $acteur, $origine, $commentaire, false);

        if ($decision === self::DECISION_APPROUVER) {
            $this->ecrireLaPrise($demande, $acteur, $origine);
        }

        return $historique;
    }

    // ─────────────────────────────── ANNULATION ────────────────────────────────────

    /**
     * Ce qui empêcherait cette annulation.
     *
     * @return string[]
     */
    public function verifierAnnulation(DemandeConge $demande, Invite $acteur, ?string $motif = null): array
    {
        if (!in_array($demande->getStatut(), [DemandeConge::STATUT_SOUMISE, DemandeConge::STATUT_APPROUVEE], true)) {
            return [sprintf(
                'Cette demande est %s : il n\'y a rien à annuler.',
                mb_strtolower(\App\Services\Search\CongeStatutScope::libelle($demande->getStatut())),
            )];
        }

        $aujourdhui = new \DateTimeImmutable('today');

        if (!$this->policy->peutAnnuler($acteur, $demande, $aujourdhui)) {
            return $demande->aCommence($aujourdhui)
                ? ['Cette absence a déjà commencé : seul un valideur peut encore l\'annuler.']
                : ["Vous n'avez pas le droit d'annuler cette demande."];
        }

        // UNE ABSENCE DÉJÀ COMMENCÉE NE S'EFFACE PAS SANS EXPLICATION. Le motif n'est
        // exigé que dans ce cas : avant le départ, l'annulation est un geste ordinaire.
        if ($demande->aCommence($aujourdhui) && trim((string) $motif) === '') {
            return ["Un motif est obligatoire pour annuler une absence déjà commencée."];
        }

        return [];
    }

    /**
     * Annule la demande et recrédite le solde si elle était approuvée.
     *
     * @throws CongeTransitionException
     */
    public function annuler(
        DemandeConge $demande,
        Invite $acteur,
        ?string $motif = null,
        string $origine = DemandeConge::ORIGINE_UI,
    ): HistoriqueDemande {
        $violations = $this->verifierAnnulation($demande, $acteur, $motif);
        if ($violations !== []) {
            throw new CongeTransitionException($violations);
        }

        $statutAvant = $demande->getStatut();

        $demande->setStatut(DemandeConge::STATUT_ANNULEE);
        $demande->setDateDecision(new \DateTimeImmutable('now'));
        if (trim((string) $motif) !== '') {
            $demande->setCommentaireDecision($motif);
        }

        $historique = $this->tracer($demande, $statutAvant, $acteur, $origine, $motif, false);

        // Le recrédit ne concerne QUE ce qui avait été décompté. Une demande annulée
        // alors qu'elle était encore en attente n'avait rien consommé : elle sort
        // simplement de l'engagé, sans écriture au journal.
        if ($statutAvant === DemandeConge::STATUT_APPROUVEE) {
            $this->ecrireLAnnulation($demande, $acteur, $origine, $motif);
        }

        return $historique;
    }

    // ──────────────────────── RATTRAPAGE DES TRACES ────────────────────────────────

    /**
     * COMPLÈTE LA TRACE D'UNE DEMANDE DONT LE STATUT A CHANGÉ SANS PASSER PAR ICI.
     *
     * ── POURQUOI C'EST NÉCESSAIRE ──────────────────────────────────────────────────
     * L'écran passe par soumettre()/decider()/annuler(), qui écrivent la ligne
     * d'historique et le mouvement de compteur. L'assistant, lui, n'y passe pas : un
     * plan de mutation écrit la demande par le moteur générique, qui ne connaît que des
     * champs. Sans ce rattrapage, un congé approuvé via Ket n'aurait ni trace, ni
     * mouvement, ni e-mail — et rien ne le signalerait avant que le compteur ne soit
     * faux.
     *
     * ── L'ABONNÉ DÉRIVE, IL N'ARBITRE PAS ──────────────────────────────────────────
     * Cette méthode ne décide RIEN : elle constate un écart entre l'état de la demande
     * et son dernier statut tracé, puis écrit ce qui manque — par les MÊMES helpers
     * privés que les trois transitions. Il n'y a donc toujours qu'un seul endroit qui
     * sache écrire un mouvement de compteur.
     *
     * ── IDEMPOTENTE ────────────────────────────────────────────────────────────────
     * Appelée sur une demande dont la trace est à jour, elle ne fait rien. C'est ce qui
     * permet de la brancher sur TOUTES les écritures sans dupliquer celles de l'écran.
     *
     * @return bool vrai s'il reste quelque chose à écrire (l'appelant flushe)
     */
    public function completerLaTrace(DemandeConge $demande): bool
    {
        $statutTrace = $this->historiqueRepository->dernierStatutTrace($demande);
        $statutReel = $demande->getStatut();

        if ($statutTrace === $statutReel) {
            return false;
        }

        // Une demande créée directement au BROUILLON n'a rien à raconter : elle n'a
        // franchi aucune étape du circuit. La tracer produirait une ligne « → Brouillon »
        // dans chaque historique, qui n'apprendrait rien à personne.
        if ($statutTrace === null && $statutReel === DemandeConge::STATUT_BROUILLON) {
            return false;
        }

        $statutAvant = $statutTrace ?? DemandeConge::STATUT_BROUILLON;

        // L'AUTEUR EST CELUI QUE LA DEMANDE DÉSIGNE, jamais l'assistant. Sur une
        // décision, c'est le valideur enregistré ; sur une soumission, l'agent lui-même.
        $acteur = match ($statutReel) {
            DemandeConge::STATUT_APPROUVEE,
            DemandeConge::STATUT_REFUSEE => $demande->getValideur() ?? $demande->getAgent(),
            default => $demande->getValideur() ?? $demande->getAgent(),
        };

        if ($acteur === null) {
            return false; // Sans acteur identifiable, on n'invente pas une signature.
        }

        // Auto-approbation : le passage direct du brouillon à l'approbation ne peut venir
        // que d'un demandeur seul valideur de son cabinet.
        $autoApprouvee = $statutAvant === DemandeConge::STATUT_BROUILLON
            && $statutReel === DemandeConge::STATUT_APPROUVEE;

        $commentaire = $statutReel === DemandeConge::STATUT_SOUMISE
            ? $demande->getMotif()
            : $demande->getCommentaireDecision();

        $this->tracer($demande, $statutAvant, $acteur, $demande->getOrigine(), $commentaire, $autoApprouvee);

        if ($statutReel === DemandeConge::STATUT_APPROUVEE) {
            $this->ecrireLaPrise($demande, $acteur, $demande->getOrigine());
        }

        if ($statutReel === DemandeConge::STATUT_ANNULEE && $statutAvant === DemandeConge::STATUT_APPROUVEE) {
            $this->ecrireLAnnulation($demande, $acteur, $demande->getOrigine(), $commentaire);
        }

        return true;
    }

    // ────────────────────────── Écritures dérivées ─────────────────────────────────

    /**
     * La ligne d'historique de la transition.
     *
     * C'est aussi ELLE qui déclenche la notification : CongeNotificationSubscriber
     * observe la naissance des lignes d'historique. Une transition tracée est donc une
     * transition notifiée, et réciproquement — il n'y a pas d'endroit où l'on puisse
     * oublier l'un en faisant l'autre.
     */
    private function tracer(
        DemandeConge $demande,
        string $statutAvant,
        Invite $acteur,
        string $origine,
        ?string $commentaire,
        bool $autoApprouvee,
    ): HistoriqueDemande {
        $historique = new HistoriqueDemande();
        $historique->setStatutAvant($statutAvant);
        $historique->setStatutApres($demande->getStatut());
        $historique->setAuteur($acteur);
        $historique->setOrigine($origine);
        $historique->setCommentaire($commentaire);
        $historique->setAutoApprouvee($autoApprouvee);
        $historique->setEntreprise($demande->getEntreprise());
        $historique->setInvite($acteur);

        // addHistorique() pose la relation dans les deux sens ; la collection est en
        // cascade persist, la ligne partira donc avec le flush de la demande.
        $demande->addHistorique($historique);

        return $historique;
    }

    /** Le décompte du compteur, à l'approbation. Quantité NÉGATIVE. */
    private function ecrireLaPrise(DemandeConge $demande, Invite $acteur, string $origine): ?MouvementConge
    {
        return $this->ecrireLeMouvement(
            $demande,
            $acteur,
            $origine,
            MouvementConge::NATURE_PRISE,
            -$demande->nbJoursFloat(),
            null,
        );
    }

    /** Le recrédit, à l'annulation d'une demande approuvée. Quantité POSITIVE. */
    private function ecrireLAnnulation(DemandeConge $demande, Invite $acteur, string $origine, ?string $motif): ?MouvementConge
    {
        return $this->ecrireLeMouvement(
            $demande,
            $acteur,
            $origine,
            MouvementConge::NATURE_ANNULATION,
            $demande->nbJoursFloat(),
            $motif,
        );
    }

    /**
     * Écrit un mouvement de compteur, si et seulement s'il a lieu d'être.
     *
     * Trois gardes, chacune pour un incident évitable :
     *  - le type doit être DÉCOMPTÉ : facturer un arrêt maladie au solde de congés est
     *    exactement ce que la case « décompté » sert à empêcher ;
     *  - la quantité doit être non nulle : une ligne à zéro n'apprend rien et alourdit
     *    un journal qui se lit à l'œil ;
     *  - le mouvement ne doit pas déjà exister pour ce couple demande/nature. Deux
     *    onglets ouverts, un double clic, un message rejoué : le compteur perdrait
     *    silencieusement deux fois les mêmes jours.
     */
    private function ecrireLeMouvement(
        DemandeConge $demande,
        Invite $acteur,
        string $origine,
        string $nature,
        float $quantite,
        ?string $commentaire,
    ): ?MouvementConge {
        if (!$demande->estDecomptee() || abs($quantite) < 0.001) {
            return null;
        }

        $id = $demande->getId();
        if ($id !== null && $this->mouvementRepository->pourDemandeEtNature($id, $nature) !== null) {
            return null;
        }

        $mouvement = new MouvementConge();
        $mouvement->setAgent($demande->getAgent());
        $mouvement->setExercice($demande->getExercice() ?? (int) (new \DateTimeImmutable('now'))->format('Y'));
        $mouvement->setTypeAbsence($demande->getTypeAbsence());
        $mouvement->setNature($nature);
        $mouvement->setQuantite(number_format($quantite, 1, '.', ''));
        $mouvement->setDemande($demande);
        $mouvement->setAuteur($acteur);
        $mouvement->setOrigine($origine);
        $mouvement->setCommentaire($commentaire);
        $mouvement->setEntreprise($demande->getEntreprise());
        $mouvement->setInvite($acteur);

        // Aucune collection ne porte les mouvements côté demande : on les persiste
        // explicitement. Pas de flush — l'appelant maîtrise sa transaction.
        $this->em->persist($mouvement);

        return $mouvement;
    }

    private function estLeSien(Invite $acteur, DemandeConge $demande): bool
    {
        $agent = $demande->getAgent();

        return $agent !== null && $agent->getId() === $acteur->getId();
    }
}
