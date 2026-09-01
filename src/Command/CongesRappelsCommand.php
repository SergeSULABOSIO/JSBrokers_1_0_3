<?php

namespace App\Command;

use App\Entity\DemandeConge;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\MouvementConge;
use App\Repository\DemandeCongeRepository;
use App\Repository\ParametresCongeRepository;
use App\Service\Conge\CalculateurJoursOuvrables;
use App\Service\Conge\CalculateurSolde;
use App\Service\Conge\DemandeCongePolicy;
use App\Services\Mail\CorporateMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * LES RELANCES ET LES ALERTES — à passer une fois par jour.
 *
 * ── DEUX CHOSES QUE PERSONNE NE VOIT VENIR ──────────────────────────────────────────
 *  1. UNE DEMANDE QUI DORT. Le valideur a reçu son e-mail le jour de la soumission ; s'il
 *     ne l'a pas traitée, plus rien ne le lui rappelle et le collaborateur attend sans
 *     savoir quoi faire. Passé le délai réglé par le cabinet, on relance.
 *  2. UN REPORT QUI ENFLE. Le report est sans limite de durée : un solde peut s'accumuler
 *     indéfiniment. C'est une dette qui grossit sans que personne ne la regarde — et
 *     qu'on découvre le jour d'un départ, quand il faut la payer.
 *
 * ── POURQUOI UNE COMMANDE, ET NON UN DÉCLENCHEMENT À LA VOLÉE ───────────────────────
 * Ces deux signaux ne dépendent d'aucun geste : ils dépendent du TEMPS QUI PASSE. Les
 * attacher à une requête reviendrait à ne relancer que les cabinets dont quelqu'un
 * s'est connecté — c'est-à-dire pas ceux qui en ont besoin.
 *
 * ── DRY-RUN PAR DÉFAUT ──────────────────────────────────────────────────────────────
 * Sans `--force`, aucun e-mail ne part : la commande rapporte qui serait relancé. On
 * regarde avant d'écrire à des gens.
 *
 * ── ELLE NE DOIT JAMAIS S'ARRÊTER SUR UN CABINET ────────────────────────────────────
 * Un envoi qui échoue est journalisé, et le parcours continue. Une commande quotidienne
 * qui abandonne au premier problème ne relance plus personne, et son silence ressemble à
 * une absence de travail à faire.
 */
#[AsCommand(
    name: 'app:conges:rappels',
    description: 'Relance les valideurs sur les demandes de congé en attente et alerte sur les reports excessifs.',
)]
final class CongesRappelsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DemandeCongeRepository $demandeRepository,
        private readonly ParametresCongeRepository $parametresRepository,
        private readonly DemandeCongePolicy $policy,
        private readonly CalculateurSolde $calculateurSolde,
        private readonly CalculateurJoursOuvrables $calculateurJours,
        private readonly CorporateMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                "Envoie réellement les e-mails. Sans cette option, la commande se contente de rapporter ce qu'elle ferait.",
            )
            ->addOption(
                'entreprise',
                null,
                InputOption::VALUE_REQUIRED,
                'Ne traiter que ce cabinet (identifiant). Par défaut, tous.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $idEntreprise = $input->getOption('entreprise');

        $io->title('Congés — relances et alertes');
        if (!$force) {
            $io->warning('Lecture seule : aucun e-mail ne partira. Ajoutez --force pour envoyer.');
        }

        $criteres = $idEntreprise !== null ? ['id' => (int) $idEntreprise] : [];
        $entreprises = $this->em->getRepository(Entreprise::class)->findBy($criteres);

        $totaux = ['relances' => 0, 'alertes' => 0];
        $lignes = [];

        foreach ($entreprises as $entreprise) {
            try {
                $bilan = $this->traiterUnCabinet($entreprise, $force);
            } catch (\Throwable $e) {
                // On continue : un cabinet en difficulté ne doit pas priver les autres de
                // leurs relances.
                $this->logger->warning('[Congés] Rappels impossibles pour un cabinet.', [
                    'entreprise' => $entreprise->getId(),
                    'erreur' => $e->getMessage(),
                ]);
                $io->warning(sprintf('Cabinet #%d ignoré : %s', (int) $entreprise->getId(), $e->getMessage()));

                continue;
            }

            $totaux['relances'] += $bilan['relances'];
            $totaux['alertes'] += $bilan['alertes'];

            if ($bilan['relances'] + $bilan['alertes'] > 0) {
                $lignes[] = [
                    (string) $entreprise->getId(),
                    (string) $entreprise->getNom(),
                    (string) $bilan['relances'],
                    (string) $bilan['alertes'],
                ];
            }
        }

        if ($lignes !== []) {
            $io->table(['Cabinet', 'Nom', 'Demandes relancées', 'Reports signalés'], $lignes);
        }

        $io->success(sprintf(
            '%s : %d relance(s), %d alerte(s) de report sur %d cabinet(s).',
            $force ? 'Envoyé' : 'À envoyer',
            $totaux['relances'],
            $totaux['alertes'],
            count($entreprises),
        ));

        if (!$force && array_sum($totaux) > 0) {
            $io->note('Relancez avec --force pour envoyer.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{relances: int, alertes: int}
     */
    private function traiterUnCabinet(Entreprise $entreprise, bool $force): array
    {
        $parametres = $this->parametresRepository->pourEntreprise($entreprise);
        $valideurs = $this->policy->valideursDe($entreprise);

        return [
            'relances' => $this->relancerLesDemandesEnAttente($entreprise, $parametres, $valideurs, $force),
            'alertes' => $this->signalerLesReportsExcessifs($entreprise, $parametres, $force),
        ];
    }

    /**
     * Les demandes soumises depuis plus longtemps que le délai réglé.
     *
     * Le délai est compté en JOURS OUVRABLES, comme tout le reste du module : une demande
     * déposée le vendredi ne doit pas être « en retard de trois jours » le lundi matin.
     *
     * @param Invite[] $valideurs
     */
    private function relancerLesDemandesEnAttente(
        Entreprise $entreprise,
        \App\Entity\ParametresConge $parametres,
        array $valideurs,
        bool $force,
    ): int {
        $delai = $parametres->getRelanceApresJours();
        if ($delai <= 0 || $valideurs === []) {
            return 0;
        }

        $aujourdhui = new \DateTimeImmutable('today');
        $enRetard = [];

        foreach ($this->demandeRepository->fileDAttente($entreprise, 500) as $demande) {
            $depuis = $demande->getCreatedAt();
            if ($depuis === null) {
                continue;
            }

            $ouvrables = $this->calculateurJours->calculer(
                $demande->getAgent(),
                $depuis->setTime(0, 0),
                $aujourdhui,
            );

            if ($ouvrables >= $delai) {
                $enRetard[] = $demande;
            }
        }

        if ($enRetard === [] || !$force) {
            return count($enRetard);
        }

        // UN SEUL E-MAIL, PAS UN PAR DEMANDE. Cinq relances séparées le même matin se
        // lisent comme du bruit et se classent comme tel ; une liste se traite.
        $destinataires = [];
        foreach ($valideurs as $valideur) {
            $email = $valideur->getEmail() ?: $valideur->getUtilisateur()?->getEmail();
            if (is_string($email) && $email !== '') {
                $destinataires[] = $email;
            }
        }

        if ($destinataires === []) {
            return count($enRetard);
        }

        $details = [];
        foreach ($enRetard as $demande) {
            $details[sprintf(
                '%s — %s au %s',
                (string) ($demande->getAgent()?->getNom() ?? 'Collaborateur'),
                $demande->getDateDebut()?->format('d/m/Y') ?? '?',
                $demande->getDateFin()?->format('d/m/Y') ?? '?',
            )] = sprintf('%s jour(s)', rtrim(rtrim(number_format($demande->nbJoursFloat(), 1, ',', ' '), '0'), ','));
        }

        $this->envoyer(
            $destinataires,
            'Demandes de congé en attente',
            sprintf(
                '%d demande(s) de congé attendent une décision depuis plus de %d jour(s) ouvrable(s).',
                count($enRetard),
                $delai,
            ),
            $details,
            'action:alert',
            (string) $entreprise->getNom(),
        );

        return count($enRetard);
    }

    /**
     * Les collaborateurs dont le solde dépasse le multiple d'alerte.
     *
     * Adressé au PROPRIÉTAIRE seul : c'est lui qui porte la dette, et une alerte diffusée
     * à toute la validation deviendrait un message qu'on ne lit plus.
     */
    private function signalerLesReportsExcessifs(
        Entreprise $entreprise,
        \App\Entity\ParametresConge $parametres,
        bool $force,
    ): int {
        $seuil = $parametres->seuilAlerteReportFloat() * $parametres->dotationAnnuelleFloat();
        if ($seuil <= 0.0) {
            return 0;
        }

        $exercice = (int) (new \DateTimeImmutable('now'))->format('Y');
        $details = [];

        foreach ($entreprise->getInvites() as $agent) {
            $solde = $this->calculateurSolde->pour($agent, $exercice);
            if ($solde->disponible() <= $seuil) {
                continue;
            }

            $details[(string) ($agent->getNom() ?? 'Collaborateur')] = sprintf(
                '%s jour(s) disponibles (seuil : %s)',
                rtrim(rtrim(number_format($solde->disponible(), 1, ',', ' '), '0'), ','),
                rtrim(rtrim(number_format($seuil, 1, ',', ' '), '0'), ','),
            );
        }

        if ($details === [] || !$force) {
            return count($details);
        }

        $email = $entreprise->getUtilisateur()?->getEmail();
        if (!is_string($email) || $email === '') {
            return count($details);
        }

        $this->envoyer(
            [$email],
            'Reports de congés à surveiller',
            sprintf(
                "%d collaborateur(s) dépassent le seuil d'alerte de report. Un solde qui s'accumule "
                . "indéfiniment est une dette qui grossit sans que personne ne la regarde.",
                count($details),
            ),
            $details,
            'action:alert',
            (string) $entreprise->getNom(),
        );

        return count($details);
    }

    /**
     * Envoi corporate, avec le gabarit générique des notifications d'agent.
     *
     * ON N'INVENTE PAS UN TEMPLATE DE PLUS : `agent_notification` accepte un titre, une
     * intro et une liste libellé/valeur — c'est exactement la forme d'une relance.
     *
     * @param string[] $destinataires
     * @param array<string, string> $details
     */
    private function envoyer(
        array $destinataires,
        string $titre,
        string $intro,
        array $details,
        string $icone,
        string $cabinet,
    ): void {
        try {
            $this->mailer->send(
                $destinataires,
                $this->mailer->buildSubject($titre, $cabinet),
                'emails/agent_notification.html.twig',
                [
                    'titre' => $titre,
                    'intro' => $intro,
                    'icone' => $icone,
                    'details' => $details,
                    'piedNote' => "Rappel automatique quotidien du module de congés. Le délai et le seuil "
                        . "se règlent dans « Paramètres congés », et se désactivent en les mettant à zéro.",
                ],
            );
        } catch (\Throwable $e) {
            // Journalisé, jamais propagé : un SMTP injoignable ne doit pas arrêter la
            // tournée des autres cabinets.
            $this->logger->warning('[Congés] Rappel non envoyé.', [
                'titre' => $titre,
                'cabinet' => $cabinet,
                'erreur' => $e->getMessage(),
            ]);
        }
    }
}
