<?php

namespace App\Command;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\MouvementConge;
use App\Entity\TypeAbsence;
use App\Service\Conge\CongeParametres;
use App\Service\Conge\DroitCongeParDefaut;
use App\Services\ServiceInitialisationEntreprise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * PROVISIONNEMENT DES CONGÉS SUR LES CABINETS DÉJÀ EN SERVICE.
 *
 * ── POURQUOI ELLE EXISTE ────────────────────────────────────────────────────────────
 * Un cabinet créé après cette livraison reçoit tout d'office
 * (ServiceInitialisationEntreprise). Ceux qui existaient déjà, non : ils auraient une
 * rubrique « Congés » sans un seul type d'absence, un compteur à zéro pour tout le monde,
 * et des collaborateurs sans le droit d'y accéder. La première demande serait refusée par
 * le contrôle de solde — ce qui ressemble à une panne, pas à un paramétrage manquant.
 *
 * ── POURQUOI UNE COMMANDE ET NON LA MIGRATION ───────────────────────────────────────
 * La migration a fait ce que le SQL sait faire : les colonnes, et la reprise des droits
 * sur les rôles EXISTANTS. Le reste ne relève pas d'elle. La dotation d'un agent se
 * calcule au prorata de ses mois de présence — une règle métier, portée par
 * CongeParametres — et un invité qui n'a AUCUN rôle en administration n'a aucune ligne à
 * mettre à jour : il faut lui en créer une. Écrire cela en SQL aurait voulu réinventer la
 * formule, c'est-à-dire en inventer une seconde.
 *
 * ── DRY-RUN PAR DÉFAUT ──────────────────────────────────────────────────────────────
 * Sans `--force`, rien n'est écrit : la commande rapporte ce qu'elle ferait. Sur des
 * droits d'accès et des compteurs, on regarde avant.
 *
 * ── IDEMPOTENTE ─────────────────────────────────────────────────────────────────────
 * Rejouée, elle ne trouve plus rien à faire. Un type est reconnu par son CODE (même
 * désactivé : recréer un type que le cabinet a volontairement retiré serait défaire son
 * réglage) ; une dotation par le triplet agent/exercice/nature ; un droit par la présence
 * d'un accès aux congés, quel qu'en soit le rôle porteur.
 */
#[AsCommand(
    name: 'app:conges:provisionner',
    description: "Provisionne la rubrique Congés sur les cabinets existants : types d'absence, dotation de l'exercice et droit d'accès des collaborateurs.",
)]
final class CongesProvisionnerCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServiceInitialisationEntreprise $initialisation,
        private readonly DroitCongeParDefaut $droitConge,
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
                "Écrit réellement. Sans cette option, la commande se contente de rapporter ce qu'elle ferait.",
            )
            ->addOption(
                'exercice',
                null,
                InputOption::VALUE_REQUIRED,
                "Exercice à doter (année civile). Par défaut, l'année en cours.",
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
        $exercice = (int) ($input->getOption('exercice') ?? 0) ?: (int) (new \DateTimeImmutable('now'))->format('Y');
        $idEntreprise = $input->getOption('entreprise');

        $io->title('Provisionnement de la rubrique Congés');
        $io->text(sprintf('Exercice traité : <info>%d</info>', $exercice));

        if (!$force) {
            $io->warning("Lecture seule : rien ne sera écrit. Ajoutez --force pour appliquer.");
        }

        $criteres = $idEntreprise !== null ? ['id' => (int) $idEntreprise] : [];
        $entreprises = $this->em->getRepository(Entreprise::class)->findBy($criteres);

        if ($entreprises === []) {
            $io->warning('Aucun cabinet à traiter.');

            return Command::SUCCESS;
        }

        $totaux = ['types' => 0, 'dotations' => 0, 'droits' => 0];
        $lignes = [];

        foreach ($entreprises as $entreprise) {
            $bilan = $this->traiterUnCabinet($entreprise, $exercice, $force);

            $totaux['types'] += $bilan['types'];
            $totaux['dotations'] += $bilan['dotations'];
            $totaux['droits'] += $bilan['droits'];

            // On ne rapporte QUE les cabinets où il y avait quelque chose à faire : sur
            // un parc de plusieurs centaines, une ligne « rien à faire » par cabinet
            // noierait les trois qui comptent.
            if ($bilan['types'] + $bilan['dotations'] + $bilan['droits'] > 0) {
                $lignes[] = [
                    (string) $entreprise->getId(),
                    (string) $entreprise->getNom(),
                    (string) $bilan['types'],
                    (string) $bilan['dotations'],
                    (string) $bilan['droits'],
                ];
            }
        }

        if ($force) {
            $this->em->flush();
        }

        if ($lignes !== []) {
            $io->table(
                ['Cabinet', 'Nom', "Types d'absence", 'Dotations', 'Droits accordés'],
                $lignes,
            );
        }

        $io->success(sprintf(
            '%s : %d type(s) d\'absence, %d dotation(s), %d droit(s) d\'accès sur %d cabinet(s).',
            $force ? 'Appliqué' : 'À appliquer',
            $totaux['types'],
            $totaux['dotations'],
            $totaux['droits'],
            count($entreprises),
        ));

        if (!$force && array_sum($totaux) > 0) {
            $io->note('Relancez avec --force pour écrire.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{types: int, dotations: int, droits: int}
     */
    private function traiterUnCabinet(Entreprise $entreprise, int $exercice, bool $force): array
    {
        $proprietaire = $this->proprietaireDe($entreprise);
        if ($proprietaire === null) {
            // Sans invité propriétaire, on ne saurait à qui attribuer la paternité des
            // lignes semées. Un cabinet dans cet état a un problème antérieur au nôtre.
            return ['types' => 0, 'dotations' => 0, 'droits' => 0];
        }

        $bilan = [
            'types' => $this->compterTypesManquants($entreprise),
            'dotations' => 0,
            'droits' => 0,
        ];

        foreach ($entreprise->getInvites() as $agent) {
            if (!$this->aSaDotation($agent, $exercice)) {
                $bilan['dotations']++;
            }
            if (!$this->aUnAccesConge($agent)) {
                $bilan['droits']++;
            }
        }

        if (!$force) {
            return $bilan;
        }

        // ÉCRITURE. On réutilise le semeur du provisionnement plutôt que d'en écrire un
        // second : c'est la même règle, et deux implémentations d'une même règle finissent
        // par diverger.
        $this->initialisation->initialiser($entreprise, $proprietaire);

        foreach ($entreprise->getInvites() as $agent) {
            $this->droitConge->appliquer($agent);
        }

        return $bilan;
    }

    /**
     * L'exercice DEMANDÉ peut différer de l'année en cours (rattrapage d'un exercice
     * antérieur). Le semeur, lui, ne connaît que l'année en cours : on complète donc ici
     * ce qu'il ne couvre pas.
     */
    private function aSaDotation(Invite $agent, int $exercice): bool
    {
        if ($agent->getId() === null) {
            return false;
        }

        return $this->em->getRepository(MouvementConge::class)->findOneBy([
            'agent' => $agent,
            'exercice' => $exercice,
            'nature' => MouvementConge::NATURE_DOTATION,
        ]) !== null;
    }

    private function aUnAccesConge(Invite $agent): bool
    {
        foreach ($agent->getRolesEnAdministration() as $role) {
            if ($role->getAccessConge() !== []) {
                return true;
            }
        }

        return false;
    }

    private function compterTypesManquants(Entreprise $entreprise): int
    {
        $codes = [
            TypeAbsence::CODE_CONGE_ANNUEL,
            TypeAbsence::CODE_SANS_SOLDE,
            TypeAbsence::CODE_MALADIE,
            TypeAbsence::CODE_EVENEMENT_FAMILIAL,
            TypeAbsence::CODE_RECUPERATION,
        ];

        $manquants = 0;
        foreach ($codes as $code) {
            $existe = $this->em->getRepository(TypeAbsence::class)
                ->findOneBy(['entreprise' => $entreprise, 'code' => $code]);
            if ($existe === null) {
                $manquants++;
            }
        }

        return $manquants;
    }

    /** L'invité propriétaire du cabinet, à qui la paternité des lignes semées revient. */
    private function proprietaireDe(Entreprise $entreprise): ?Invite
    {
        foreach ($entreprise->getInvites() as $invite) {
            if ($invite->isProprietaire() === true) {
                return $invite;
            }
        }

        // Repli : l'invité rattaché au compte propriétaire de l'entreprise. Les cabinets
        // les plus anciens ne portent pas toujours le drapeau `proprietaire`.
        $compte = $entreprise->getUtilisateur();
        foreach ($entreprise->getInvites() as $invite) {
            if ($compte !== null && $invite->getUtilisateur() === $compte) {
                return $invite;
            }
        }

        return $entreprise->getInvites()->first() ?: null;
    }
}
