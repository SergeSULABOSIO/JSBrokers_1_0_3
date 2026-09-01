<?php

namespace App\Service\Conge;

use App\Entity\DemandeConge;
use App\Entity\HistoriqueDemande;
use App\Entity\Invite;
use App\Repository\DemandeCongeRepository;
use App\Services\Mail\CorporateMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * LES E-MAILS PORTENT LE CIRCUIT DE VALIDATION.
 *
 * Ce ne sont pas un confort : le module serait inutilisable si le valideur devait penser
 * à se connecter pour découvrir qu'une demande l'attend. C'est pourquoi ils font partie
 * du socle et non des finitions.
 *
 * ── UN SEUL POINT D'ENTRÉE, DÉRIVÉ DE LA TRACE ──────────────────────────────────────
 * On ne notifie pas « une approbation » : on notifie une LIGNE D'HISTORIQUE. Toute
 * transition en écrit une, donc toute transition est notifiée — quel que soit le canal
 * qui l'a produite, l'écran comme l'assistant. Il n'existe aucun endroit où l'on puisse
 * enregistrer une décision en oubliant d'en informer quelqu'un.
 *
 * ── UN ÉCHEC D'ENVOI N'ANNULE JAMAIS UNE DÉCISION ───────────────────────────────────
 * Tout est enveloppé et journalisé. Un serveur SMTP injoignable ne doit pas empêcher un
 * congé d'être posé ni décidé ; le message partira au rejeu (l'envoi est asynchrone via
 * Messenger, avec sa propre politique de reprise).
 */
class CongeMailer
{
    public function __construct(
        private readonly CorporateMailer $corporateMailer,
        private readonly DemandeCongePolicy $policy,
        private readonly CalculateurSolde $calculateurSolde,
        private readonly DemandeCongeRepository $demandeRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Notifie la transition que porte cette ligne d'historique.
     *
     * Ne lève jamais : la décision est déjà enregistrée, et rien de ce qui suit ne doit
     * pouvoir la remettre en cause.
     */
    public function notifier(HistoriqueDemande $transition): void
    {
        try {
            $this->envoyer($transition);
        } catch (\Throwable $e) {
            $this->logger->warning('[Congés] Notification non envoyée.', [
                'historique' => $transition->getId(),
                'demande' => $transition->getDemande()?->getId(),
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    private function envoyer(HistoriqueDemande $transition): void
    {
        $demande = $transition->getDemande();
        $agent = $demande?->getAgent();
        $entreprise = $demande?->getEntreprise();

        if ($demande === null || $agent === null || $entreprise === null) {
            return; // Rien à notifier : la trace est orpheline.
        }

        $valideurs = $this->policy->valideursDe($entreprise);
        $destination = $this->destinataires($transition, $demande, $agent, $valideurs);

        if ($destination['to'] === []) {
            // AUCUN DESTINATAIRE N'EST UNE ANOMALIE, pas un cas normal : une demande
            // soumise dans un cabinet sans valideur joignable resterait invisible.
            $this->logger->warning('[Congés] Transition sans destinataire joignable.', [
                'demande' => $demande->getId(),
                'statut' => $transition->getStatutApres(),
            ]);

            return;
        }

        $contexte = $this->construireContexte($transition, $demande, $agent, $destination['inviteCible']);

        $this->corporateMailer->send(
            $destination['to'],
            $this->corporateMailer->buildSubject($contexte->titre, (string) ($agent->getNom() ?? 'Collaborateur')),
            'emails/conge_notification.html.twig',
            ['ctx' => $contexte],
            $destination['replyTo'],
            [],
            $destination['cc'],
        );
    }

    /**
     * Qui reçoit quoi.
     *
     * ── LE PROPRIÉTAIRE EST INFORMÉ DE CHAQUE DEMANDE ───────────────────────────────
     * Le mail de soumission part vers TOUS les valideurs configurés, propriétaire
     * compris, même lorsqu'un valideur invité traite habituellement les demandes. Le
     * propriétaire est ainsi au courant sans avoir à consulter l'application — et sans
     * qu'un rôle d'administration distinct soit nécessaire.
     *
     * ── LE « RÉPONDRE À » PART VERS LA BONNE PERSONNE ───────────────────────────────
     * Sur une soumission, c'est l'agent ; sur une décision, c'est son auteur. Une réponse
     * atteint donc son destinataire sans manipulation.
     *
     * @param Invite[] $valideurs
     * @return array{to: string[], cc: string[], replyTo: ?Address, inviteCible: Invite}
     */
    private function destinataires(
        HistoriqueDemande $transition,
        DemandeConge $demande,
        Invite $agent,
        array $valideurs,
    ): array {
        $auteur = $transition->getAuteur();
        $emailsValideurs = $this->emails($valideurs);
        $emailAgent = $this->email($agent);

        // SOUMISSION : la demande part vers ceux qui peuvent la trancher.
        if ($transition->getStatutApres() === DemandeConge::STATUT_SOUMISE) {
            return [
                'to' => $emailsValideurs,
                'cc' => [],
                'replyTo' => $emailAgent !== null ? new Address($emailAgent, (string) $agent->getNom()) : null,
                'inviteCible' => $valideurs[0] ?? $agent,
            ];
        }

        // DÉCISION ou ANNULATION : elle s'adresse au collaborateur ; les autres valideurs
        // la voient passer. On retire l'agent de la copie pour ne pas le compter deux fois
        // — il est déjà destinataire principal, et le cas se produit dès qu'il est
        // lui-même valideur (auto-approbation).
        $copie = array_values(array_diff($emailsValideurs, array_filter([$emailAgent])));

        return [
            'to' => array_filter([$emailAgent]),
            'cc' => $copie,
            'replyTo' => $this->adresseDe($auteur),
            'inviteCible' => $agent,
        ];
    }

    private function construireContexte(
        HistoriqueDemande $transition,
        DemandeConge $demande,
        Invite $agent,
        Invite $inviteCible,
    ): CongeMailContext {
        $solde = $this->calculateurSolde->pour($agent, $demande->getExercice());
        $jours = $demande->nbJoursFloat();

        // Un type NON décompté ne bouge pas le compteur : annoncer un « avant / après »
        // différent laisserait croire le contraire.
        $pese = $demande->estDecomptee();

        [$titre, $intro, $icone] = $this->redaction($transition, $demande, $agent);

        return new CongeMailContext(
            demande: $demande,
            transition: $transition,
            solde: $solde,
            disponibleAvant: $pese ? $solde->disponibleAvant($jours) : $solde->disponible(),
            disponibleApres: $solde->disponible(),
            collegues: $this->colleguesAbsents($demande, $agent),
            instantaneLe: new \DateTimeImmutable('now'),
            lienApplication: $this->lienEspaceDeTravail($inviteCible),
            titre: $titre,
            intro: $intro,
            icone: $icone,
        );
    }

    /**
     * Titre, phrase d'accroche et pastille, selon la transition.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function redaction(HistoriqueDemande $transition, DemandeConge $demande, Invite $agent): array
    {
        $nom = (string) ($agent->getNom() ?? 'Un collaborateur');
        $auteur = (string) ($transition->getAuteur()?->getNom() ?? 'Un valideur');
        $periode = $this->periode($demande);

        return match ($transition->getStatutApres()) {
            DemandeConge::STATUT_SOUMISE => [
                'Demande de congé à valider',
                sprintf('%s demande un congé %s. Sa demande attend votre décision.', $nom, $periode),
                'conge',
            ],
            DemandeConge::STATUT_APPROUVEE => $transition->isAutoApprouvee()
                ? [
                    'Congé auto-approuvé',
                    sprintf(
                        'Votre congé %s est enregistré et <strong>auto-approuvé</strong> : vous êtes le seul valideur du cabinet, il n\'y avait personne d\'autre pour le trancher.',
                        $periode,
                    ),
                    'action:completed',
                ]
                : [
                    'Congé approuvé',
                    sprintf('Votre congé %s a été <strong>approuvé</strong> par %s.', $periode, $auteur),
                    'action:completed',
                ],
            DemandeConge::STATUT_REFUSEE => [
                'Congé refusé',
                sprintf('Votre congé %s a été <strong>refusé</strong> par %s.', $periode, $auteur),
                'action:cancel',
            ],
            DemandeConge::STATUT_ANNULEE => [
                'Congé annulé',
                sprintf('Le congé %s de %s a été <strong>annulé</strong> par %s.', $periode, $nom, $auteur),
                'action:annulation',
            ],
            default => [
                'Demande de congé mise à jour',
                sprintf('La demande de congé de %s %s a changé d\'état.', $nom, $periode),
                'conge',
            ],
        };
    }

    private function periode(DemandeConge $demande): string
    {
        $debut = $demande->getDateDebut()?->format('d/m/Y');
        $fin = $demande->getDateFin()?->format('d/m/Y');

        if ($debut === null || $fin === null) {
            return 'sur une période non précisée';
        }

        return $debut === $fin ? sprintf('le %s', $debut) : sprintf('du %s au %s', $debut, $fin);
    }

    /**
     * Collègues déjà absents sur tout ou partie de la période — le contexte qui manque le
     * plus au valideur : approuver n'est pas la même décision selon que l'équipe est au
     * complet ou déjà à moitié partie.
     *
     * @return array<int, array{nom: string, periode: string}>
     */
    private function colleguesAbsents(DemandeConge $demande, Invite $agent): array
    {
        $entreprise = $demande->getEntreprise();
        $debut = $demande->getDateDebut();
        $fin = $demande->getDateFin();

        if ($entreprise === null || $debut === null || $fin === null) {
            return [];
        }

        $absences = [];
        foreach ($this->demandeRepository->absencesApprouveesSurPeriode($entreprise, $debut, $fin, $agent) as $autre) {
            $absences[] = [
                'nom' => (string) ($autre->getAgent()?->getNom() ?? 'Collaborateur'),
                'periode' => $this->periode($autre),
            ];
        }

        return $absences;
    }

    /** Lien direct vers l'espace de travail du destinataire. */
    private function lienEspaceDeTravail(Invite $invite): string
    {
        try {
            return $this->urlGenerator->generate(
                'app_espace_de_travail_component.index',
                ['idInvite' => $invite->getId(), 'idEntreprise' => $invite->getEntreprise()?->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        } catch (\Throwable) {
            // Hors requête HTTP (commande, worker), la génération d'URL absolue peut
            // échouer faute de contexte : mieux vaut un mail sans bouton qu'un mail perdu.
            return '';
        }
    }

    /** @param Invite[] $invites @return string[] */
    private function emails(array $invites): array
    {
        $emails = [];
        foreach ($invites as $invite) {
            $email = $this->email($invite);
            if ($email !== null && !in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /**
     * L'adresse d'un invité : la sienne, ou à défaut celle de son compte utilisateur.
     *
     * Un invité EN ATTENTE n'a pas encore de compte : c'est son adresse d'invitation qui
     * le joint. L'ignorer ferait disparaître du circuit un collaborateur pourtant
     * légitime.
     */
    private function email(?Invite $invite): ?string
    {
        if ($invite === null) {
            return null;
        }

        $email = $invite->getEmail() ?: $invite->getUtilisateur()?->getEmail();

        return is_string($email) && $email !== '' ? $email : null;
    }

    private function adresseDe(?Invite $invite): ?Address
    {
        $email = $this->email($invite);

        return $email !== null ? new Address($email, (string) ($invite?->getNom() ?? '')) : null;
    }
}
