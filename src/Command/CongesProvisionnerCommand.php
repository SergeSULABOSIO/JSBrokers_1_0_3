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
    /**
     * Le marqueur du rattrapage, en tête de son commentaire.
     *
     * C'est lui, et non le montant du solde, qui rend la reprise idempotente : un
     * valideur peut avoir retiré des jours entre-temps, et une garde fondée sur le total
     * les lui rendrait à chaque exécution.
     */
    private const MARQUEUR_RATTRAPAGE = '[Reprise dotation annuelle]';

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

        $totaux = ['types' => 0, 'dotations' => 0, 'droits' => 0, 'rattrapages' => 0];
        $lignes = [];

        foreach ($entreprises as $entreprise) {
            $bilan = $this->traiterUnCabinet($entreprise, $exercice, $force);

            $totaux['types'] += $bilan['types'];
            $totaux['dotations'] += $bilan['dotations'];
            $totaux['droits'] += $bilan['droits'];
            $totaux['rattrapages'] += $bilan['rattrapages'];

            // On ne rapporte QUE les cabinets où il y avait quelque chose à faire : sur
            // un parc de plusieurs centaines, une ligne « rien à faire » par cabinet
            // noierait les trois qui comptent.
            if (array_sum($bilan) > 0) {
                $lignes[] = [
                    (string) $entreprise->getId(),
                    (string) $entreprise->getNom(),
                    (string) $bilan['types'],
                    (string) $bilan['dotations'],
                    (string) $bilan['droits'],
                    (string) $bilan['rattrapages'],
                ];
            }
        }

        if ($force) {
            $this->em->flush();
        }

        if ($lignes !== []) {
            $io->table(
                ['Cabinet', 'Nom', "Types d'absence", 'Dotations', 'Droits accordés', 'Rattrapages'],
                $lignes,
            );
        }

        $io->success(sprintf(
            '%s : %d type(s) d\'absence, %d dotation(s), %d droit(s) d\'accès, %d rattrapage(s) sur %d cabinet(s).',
            $force ? 'Appliqué' : 'À appliquer',
            $totaux['types'],
            $totaux['dotations'],
            $totaux['droits'],
            $totaux['rattrapages'],
            count($entreprises),
        ));

        if (!$force && array_sum($totaux) > 0) {
            $io->note('Relancez avec --force pour écrire.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{types: int, dotations: int, droits: int, rattrapages: int}
     */
    private function traiterUnCabinet(Entreprise $entreprise, int $exercice, bool $force): array
    {
        $proprietaire = $this->proprietaireDe($entreprise);
        if ($proprietaire === null) {
            // Sans invité propriétaire, on ne saurait à qui attribuer la paternité des
            // lignes semées. Un cabinet dans cet état a un problème antérieur au nôtre.
            return ['types' => 0, 'dotations' => 0, 'droits' => 0, 'rattrapages' => 0];
        }

        $bilan = [
            'types' => $this->compterTypesManquants($entreprise),
            'dotations' => 0,
            'droits' => 0,
            'rattrapages' => 0,
        ];

        foreach ($entreprise->getInvites() as $agent) {
            if (!$this->aSaDotation($agent, $exercice)) {
                $bilan['dotations']++;
            } elseif ($this->complementDeDemarrage($agent, $exercice) > 0.0) {
                $bilan['rattrapages']++;
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
            $this->rattraperLaDotationDeDemarrage($agent, $entreprise, $proprietaire, $exercice);
        }

        return $bilan;
    }

    /**
     * LE RATTRAPAGE DES COMPTEURS DOTÉS AU PRORATA.
     *
     * ── CE QU'IL RÉPARE ─────────────────────────────────────────────────────────────
     * La première version de ce semis proratisait la dotation de démarrage sur la date de
     * création de la fiche d'invité. Or cette date dit quand le collaborateur a été SAISI
     * dans JS Brokers, pas quand il est arrivé dans le cabinet : un cabinet qui a adopté
     * le module en avril a vu tout son personnel crédité de neuf mois sur douze. Un droit
     * amputé d'un quart, sans que rien à l'écran n'en donne la raison.
     *
     * ── POURQUOI UN AJUSTEMENT ET NON UNE CORRECTION DE LA DOTATION ─────────────────
     * Parce qu'un mouvement est immuable, et que c'est ce qui rend le journal croyable.
     * Le complément est donc une ligne DE PLUS, motivée, que l'on pourra relire dans deux
     * ans pour comprendre pourquoi le compteur a bougé un jour de septembre.
     *
     * ── IDEMPOTENT, ET SANS DÉFAIRE LA MAIN DU VALIDEUR ────────────────────────────
     * La garde n'est pas « le solde vaut-il la dotation ? » — un valideur qui aurait
     * légitimement retiré trois jours verrait alors ces trois jours revenir à chaque
     * exécution. Elle est : « ce rattrapage-ci a-t-il déjà été écrit ? », reconnu à son
     * marqueur. Une fois posé, il ne se repose jamais.
     */
    private function rattraperLaDotationDeDemarrage(
        Invite $agent,
        Entreprise $entreprise,
        Invite $proprietaire,
        int $exercice,
    ): void {
        $complement = $this->complementDeDemarrage($agent, $exercice);
        if ($complement <= 0.0) {
            return;
        }

        $mouvement = (new MouvementConge())
            ->setAgent($agent)
            ->setExercice($exercice)
            ->setTypeAbsence($this->congeAnnuelDe($entreprise))
            ->setNature(MouvementConge::NATURE_AJUSTEMENT)
            ->setQuantite(number_format($complement, 1, '.', ''))
            ->setAuteur($proprietaire)
            ->setCommentaire(sprintf(
                '%s La dotation de démarrage avait été calculée au prorata de la date de '
                . "création de la fiche, qui n'est pas la date d'arrivée dans le cabinet : "
                . 'le droit est ramené à l\'année pleine de %d.',
                self::MARQUEUR_RATTRAPAGE,
                $exercice,
            ));

        $mouvement->setEntreprise($entreprise);
        $mouvement->setInvite($proprietaire);
        $this->em->persist($mouvement);
    }

    /**
     * Ce qu'il manque à cet agent pour atteindre l'année pleine, ou 0 si le rattrapage a
     * déjà eu lieu — ou s'il n'a jamais été proratisé.
     */
    private function complementDeDemarrage(Invite $agent, int $exercice): float
    {
        if ($agent->getId() === null || $this->rattrapageDejaEcrit($agent, $exercice)) {
            return 0.0;
        }

        $dotation = (float) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantite), 0) FROM mouvement_conge
             WHERE agent_id = :a AND exercice = :x AND nature = :n',
            ['a' => $agent->getId(), 'x' => $exercice, 'n' => MouvementConge::NATURE_DOTATION],
        );

        $manque = CongeParametres::DOTATION_ANNUELLE_DEFAUT - $dotation;

        // Au-delà de l'année pleine, on ne touche à rien : c'est un cabinet qui a réglé
        // sa propre dotation, et ce n'est pas à une reprise d'en décider.
        return $manque > 0.001 ? $manque : 0.0;
    }

    private function rattrapageDejaEcrit(Invite $agent, int $exercice): bool
    {
        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM mouvement_conge
             WHERE agent_id = :a AND exercice = :x AND nature = :n AND commentaire LIKE :m
             LIMIT 1',
            [
                'a' => $agent->getId(),
                'x' => $exercice,
                'n' => MouvementConge::NATURE_AJUSTEMENT,
                'm' => self::MARQUEUR_RATTRAPAGE . '%',
            ],
        );
    }

    private function congeAnnuelDe(Entreprise $entreprise): ?TypeAbsence
    {
        return $this->em->getRepository(TypeAbsence::class)->findOneBy([
            'entreprise' => $entreprise,
            'code' => TypeAbsence::CODE_CONGE_ANNUEL,
        ]);
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
