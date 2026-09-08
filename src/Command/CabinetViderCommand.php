<?php

namespace App\Command;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Services\ServiceInitialisationEntreprise;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * REPARTIR DE ZÉRO SUR UN CABINET — sans le supprimer, ni en perdre les accès.
 *
 * ── POURQUOI ELLE EXISTE ────────────────────────────────────────────────────────────
 * Un poste de développement finit par accumuler ce qu'on y a essayé : reprises rejouées,
 * semis d'initialisation relancés, imports d'essai. Le cabinet ne ment pas sur le code —
 * il ment sur les DONNÉES, et l'on finit par ne plus savoir si un doublon vient d'un
 * défaut à corriger ou d'une manipulation d'hier. On remet alors le cabinet à neuf.
 *
 * ── CE QU'ELLE EFFACE, ET CE QU'ELLE GARDE ──────────────────────────────────────────
 * Elle vide TOUTE table portant `entreprise_id` — clients, polices, tranches, notes,
 * documents, catalogues, congés —, sauf trois familles qu'il serait faux de toucher :
 *
 *  • `invite` : les accès. Les effacer déconnecterait le propriétaire de son propre
 *    cabinet, qu'il faudrait alors recréer à la main.
 *  • `roles_en_*` : les droits de ces invités. Ils appartiennent aux accès, pas aux
 *    données, et les reconstituer ne se devine pas.
 *  • `token_consumption` : le journal de consommation. Il porte ce qui a été FACTURÉ.
 *    L'effacer réécrirait une consommation déjà payée — ce n'est pas une donnée de
 *    travail, c'est une pièce comptable.
 *
 * ⚠ ET LE CATALOGUE EST RESEMÉ derrière. Un cabinet vidé sans monnaie, sans taxe et sans
 * type de chargement n'est pas « propre » : il est inutilisable, et la première saisie s'y
 * heurterait à des listes vides. On rejoue donc `ServiceInitialisationEntreprise`, dont le
 * semis est idempotent.
 *
 * ── POURQUOI LES TABLES SE LISENT DANS LE SCHÉMA ────────────────────────────────────
 * La liste n'est pas écrite ici : elle est relevée dans `information_schema`. Une liste
 * figée serait fausse à la première entité ajoutée, et le cabinet garderait des restes que
 * personne ne penserait à chercher.
 *
 * ── DRY-RUN PAR DÉFAUT ──────────────────────────────────────────────────────────────
 * Sans `--confirmer`, rien n'est écrit : la commande dit ce qu'elle effacerait, table par
 * table. Sur une suppression de cette portée, on regarde avant.
 */
#[AsCommand(
    name: 'app:cabinet:vider',
    description: "Vide toutes les données d'un cabinet (accès, droits et journal de consommation préservés) et resème son catalogue.",
)]
final class CabinetViderCommand extends Command
{
    /**
     * Ce qu'on ne touche pas, bien que portant `entreprise_id`.
     *
     * `roles_en_*` est traité à part, par préfixe : il y en a un par département, et la
     * liste s'allongera.
     */
    private const PRESERVEES = ['invite', 'token_consumption'];

    /** Le préfixe des tables de droits, qui appartiennent aux accès. */
    private const PREFIXE_ROLES = 'roles_en_';

    /**
     * Les tables de rattachement, qui ne portent pas `entreprise_id`.
     *
     * Leurs lignes n'appartiennent à personne en propre : elles relient deux fiches. Une
     * fois les fiches parties, elles ne relient plus rien — mais rien ne les emporte, et
     * elles resteraient à pointer vers des identifiants réattribués à d'autres.
     *
     * @var array<string, array<string, string>> table => colonne => table parente
     */
    private const RATTACHEMENTS = [
        'client_partenaire' => ['client_id' => 'client', 'partenaire_id' => 'partenaire'],
        'condition_partage_risque' => ['condition_partage_id' => 'condition_partage', 'risque_id' => 'risque'],
        'note_compte_bancaire' => ['note_id' => 'note', 'compte_bancaire_id' => 'compte_bancaire'],
        'piste_condition_partage' => ['piste_id' => 'piste', 'condition_partage_id' => 'condition_partage'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connexion,
        private readonly ServiceInitialisationEntreprise $initialisation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'cabinet',
                InputArgument::REQUIRED,
                "L'identifiant du cabinet, ou son nom exact.",
            )
            ->addOption(
                'confirmer',
                null,
                InputOption::VALUE_NONE,
                'Écrit réellement. Sans cette option, la commande se contente de dire ce qu\'elle effacerait.',
            )
            ->addOption(
                'sans-resemer',
                null,
                InputOption::VALUE_NONE,
                'Laisse le cabinet entièrement vide, catalogue compris.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entreprise = $this->trouverLeCabinet((string) $input->getArgument('cabinet'));
        if ($entreprise === null) {
            $io->error("Aucun cabinet ne porte cet identifiant ni ce nom.");

            return Command::FAILURE;
        }

        $id = (int) $entreprise->getId();
        $io->title(sprintf('Cabinet « %s » (n° %d)', (string) $entreprise->getNom(), $id));

        $aEffacer = $this->recenser($id);
        $total = array_sum($aEffacer);

        if ($total === 0) {
            $io->success('Ce cabinet ne porte aucune donnée : il est déjà à neuf.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Rubrique', 'Lignes'],
            array_map(static fn (string $t, int $n): array => [$t, $n], array_keys($aEffacer), $aEffacer),
        );
        $io->writeln(sprintf('  <info>%d lignes</info> au total.', $total));
        $io->newLine();
        $io->writeln('  Préservés : les accès (invite), leurs droits (roles_en_*) et le journal de consommation.');

        if (!$input->getOption('confirmer')) {
            $io->warning("Rien n'a été écrit. Ajoutez --confirmer pour effacer réellement.");

            return Command::SUCCESS;
        }

        $efface = $this->vider($id, array_keys($aEffacer));
        $io->success(sprintf('%d lignes effacées.', $efface));

        if ($input->getOption('sans-resemer')) {
            $io->writeln('  Catalogue non resemé, comme demandé.');

            return Command::SUCCESS;
        }

        // ⚠ L'ENTITÉ EST RELUE. Celle qu'on tient a traversé des suppressions faites en
        // SQL direct : ses collections chargées décrivent un état qui n'existe plus, et le
        // semis les rattacherait à des lignes disparues.
        $this->em->clear();
        $entreprise = $this->em->getRepository(Entreprise::class)->find($id);
        $proprietaire = $this->proprietaire($entreprise);

        if ($entreprise === null || $proprietaire === null) {
            $io->warning("Catalogue non resemé : ce cabinet n'a plus d'invité propriétaire.");

            return Command::SUCCESS;
        }

        $this->initialisation->initialiser($entreprise, $proprietaire);
        $this->em->flush();

        $io->success('Catalogue resemé : monnaies, taxes, chargements, types de revenu, risques, groupes et congés.');

        return Command::SUCCESS;
    }

    /** Le cabinet, par son identifiant ou par son nom exact. */
    private function trouverLeCabinet(string $reference): ?Entreprise
    {
        $depot = $this->em->getRepository(Entreprise::class);

        return ctype_digit($reference)
            ? $depot->find((int) $reference)
            : $depot->findOneBy(['nom' => $reference]);
    }

    /**
     * Le propriétaire du cabinet — celui au nom de qui le catalogue sera resemé.
     *
     * À défaut de propriétaire déclaré, le premier invité fait l'affaire : ce qu'on
     * cherche ici est un auteur pour l'audit, pas un titulaire de droits.
     */
    private function proprietaire(?Entreprise $entreprise): ?Invite
    {
        if ($entreprise === null) {
            return null;
        }

        $depot = $this->em->getRepository(Invite::class);

        return $depot->findOneBy(['entreprise' => $entreprise, 'proprietaire' => true])
            ?? $depot->findOneBy(['entreprise' => $entreprise]);
    }

    /**
     * Compte, table par table, ce qui serait effacé.
     *
     * @return array<string, int> table => lignes, les tables vides écartées
     */
    private function recenser(int $id): array
    {
        $compte = [];

        foreach ($this->tablesDuCabinet() as $table) {
            $lignes = (int) $this->connexion->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE entreprise_id = ?', $table),
                [$id],
            );

            if ($lignes > 0) {
                $compte[$table] = $lignes;
            }
        }

        arsort($compte);

        return $compte;
    }

    /**
     * Les tables portant `entreprise_id`, moins celles qu'on préserve.
     *
     * ⚠ RELEVÉES DANS LE SCHÉMA, jamais recopiées ici : une liste figée serait fausse à la
     * première entité ajoutée, et le cabinet garderait des restes invisibles.
     *
     * @return string[]
     */
    private function tablesDuCabinet(): array
    {
        $tables = $this->connexion->fetchFirstColumn(
            "SELECT TABLE_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'entreprise_id'
             ORDER BY TABLE_NAME",
        );

        return array_values(array_filter(
            array_map('strval', $tables),
            static fn (string $table): bool => !in_array($table, self::PRESERVEES, true)
                && !str_starts_with($table, self::PREFIXE_ROLES),
        ));
    }

    /**
     * Efface, puis retire les rattachements devenus orphelins.
     *
     * ⚠ LES CLÉS ÉTRANGÈRES SONT DÉSARMÉES LE TEMPS DE L'OPÉRATION. Les tables se tiennent
     * en un graphe dont l'ordre de suppression n'est pas trivial — et certaines relations
     * sont circulaires (une cotation porte ses avenants, un avenant porte sa cotation).
     * Chercher un ordre correct, c'est réécrire ce graphe à la main et se tromper. On
     * désarme, on efface le cabinet EN ENTIER, on réarme : à la fin, plus rien de ce
     * cabinet ne subsiste, donc plus rien à quoi une clé pourrait manquer.
     *
     * @param string[] $tables
     */
    private function vider(int $id, array $tables): int
    {
        $efface = 0;

        $this->connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                // ⚠ TOUJOURS AVEC SA CLAUSE D'ENTREPRISE. Un DELETE sans portée sur une
                // base partagée emporte les autres cabinets — la faute est arrivée, et
                // elle ne se rattrape pas.
                $efface += (int) $this->connexion->executeStatement(
                    sprintf('DELETE FROM %s WHERE entreprise_id = ?', $table),
                    [$id],
                );
            }

            foreach (self::RATTACHEMENTS as $table => $parents) {
                foreach ($parents as $colonne => $parent) {
                    $efface += (int) $this->connexion->executeStatement(sprintf(
                        'DELETE FROM %1$s WHERE %2$s NOT IN (SELECT id FROM %3$s)',
                        $table,
                        $colonne,
                        $parent,
                    ));
                }
            }
        } finally {
            $this->connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $efface;
    }
}
