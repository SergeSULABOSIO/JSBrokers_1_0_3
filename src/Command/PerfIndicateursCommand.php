<?php

namespace App\Command;

use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ce que coûte UNE PAGE de rubrique, en millisecondes et en requêtes.
 *
 * Rejoue exactement la séquence des deux chemins de liste (ControllerUtilsTrait :
 * `batchPreloadForCollection` sur la page, puis `loadAllCalculatedValues` par ligne),
 * pour plusieurs tailles de page, et en déduit la PENTE (le coût par ligne) et
 * l'ORDONNÉE À L'ORIGINE (le coût fixe d'une page, quelle que soit sa longueur).
 *
 * POURQUOI CETTE COMMANDE EXISTE. Les deux pièges de mesure du chantier N+1 des
 * avenants avaient fait conclure à l'envers : `--no-debug` n'écrit rien dans
 * `var/log/dev.log`, et une lecture isolée à 126 ms avait fait croire à une
 * régression alors que la médiane sur trois essais était à ~240 ms. On mesure donc
 * ici la MÉDIANE de plusieurs essais, chacun parti d'un état froid (identity map
 * vidée, caches du helper réinitialisés), et on compte les requêtes par
 * `Com_select` sur la connexion Doctrine plutôt que par le journal général — qui
 * ajoute des E/S par requête et fausserait les durées.
 *
 * Un préchargement ne se juge JAMAIS sur une seule petite page : son coût est fixe
 * et son gain croît avec la taille (le chantier Avenant est passé de −34 % à 20
 * lignes à −64 % à 61). D'où la comparaison de plusieurs tailles.
 */
#[AsCommand(
    name: 'app:perf:indicateurs',
    description: "Mesure le coût des indicateurs calculés d'une rubrique (durée, requêtes, pente par ligne)",
)]
final class PerfIndicateursCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CanvasBuilder $canvasBuilder,
        private readonly IndicatorCalculationHelper $helper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('entite', InputArgument::OPTIONAL, 'Nom court de la rubrique', 'Partenaire')
            ->addOption('tailles', null, InputOption::VALUE_REQUIRED, 'Tailles de page à comparer', '1,10,20')
            ->addOption('essais', null, InputOption::VALUE_REQUIRED, 'Essais par taille (la médiane est retenue)', '3')
            ->addOption('entreprise', null, InputOption::VALUE_REQUIRED, 'Identifiant d\'entreprise (défaut : la mieux fournie)')
            ->addOption('empreinte', null, InputOption::VALUE_REQUIRED, 'Écrit dans ce fichier les indicateurs de TOUS les enregistrements, au lieu de mesurer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $court = (string) $input->getArgument('entite');
        $fqcn = 'App\\Entity\\' . $court;
        if (!class_exists($fqcn)) {
            $io->error(sprintf('Entité inconnue : %s', $court));

            return Command::FAILURE;
        }

        $tailles = array_values(array_filter(array_map('intval', explode(',', (string) $input->getOption('tailles')))));
        $essais = max(1, (int) $input->getOption('essais'));

        $idEntreprise = $input->getOption('entreprise') !== null
            ? (int) $input->getOption('entreprise')
            : $this->entrepriseLaMieuxFournie($fqcn);

        if ($idEntreprise === 0) {
            $io->error(sprintf('Aucun enregistrement %s en base.', $court));

            return Command::FAILURE;
        }

        $disponibles = (int) $this->em->createQuery(
            sprintf('SELECT COUNT(e.id) FROM %s e WHERE e.entreprise = :ent', $fqcn)
        )->setParameter('ent', $idEntreprise)->getSingleScalarResult();

        $io->title(sprintf('%s — entreprise #%d, %d enregistrements', $court, $idEntreprise, $disponibles));

        if (($empreinte = $input->getOption('empreinte')) !== null) {
            return $this->ecrireEmpreinte($io, $fqcn, $idEntreprise, $disponibles, (string) $empreinte);
        }

        $lignesTableau = [];
        $mesures = [];

        foreach ($tailles as $taille) {
            if ($taille > $disponibles) {
                $lignesTableau[] = [$taille, sprintf('— (seulement %d disponibles)', $disponibles), '', '', ''];
                continue;
            }

            $durees = [];
            $requetes = 0;
            $memoire = 0;

            for ($essai = 0; $essai < $essais; ++$essai) {
                $page = $this->pageFroide($fqcn, $idEntreprise, $taille);

                $q0 = $this->comSelect();
                $m0 = memory_get_usage();
                $t0 = microtime(true);

                $this->canvasBuilder->batchPreloadForCollection($page);
                foreach ($page as $entite) {
                    $this->canvasBuilder->loadAllCalculatedValues($entite);
                }

                $durees[] = (microtime(true) - $t0) * 1000;
                // −1 : le relevé de fin se compte lui-même.
                $requetes = $this->comSelect() - $q0 - 1;
                $memoire = memory_get_usage() - $m0;
            }

            sort($durees);
            $mediane = $durees[intdiv(count($durees), 2)];
            $mesures[$taille] = ['duree' => $mediane, 'requetes' => $requetes];

            $lignesTableau[] = [
                $taille,
                sprintf('%.0f ms', $mediane),
                sprintf('%.0f ms', $mediane / $taille),
                $requetes,
                sprintf('%.1f Mo', $memoire / 1048576),
            ];
        }

        $io->table(['lignes', 'durée (médiane)', 'par ligne', 'requêtes', 'mémoire'], $lignesTableau);

        if (count($mesures) >= 2) {
            $tailles = array_keys($mesures);
            $a = reset($tailles);
            $b = end($tailles);
            $pente = ($mesures[$b]['duree'] - $mesures[$a]['duree']) / ($b - $a);

            $io->writeln(sprintf(
                "  pente <info>%.0f ms/ligne</info> · coût fixe <info>%.0f ms</info> · requêtes %d → %d (de %d à %d lignes)",
                $pente,
                $mesures[$a]['duree'] - $pente * $a,
                $mesures[$a]['requetes'],
                $mesures[$b]['requetes'],
                $a,
                $b,
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * TOUS les indicateurs de TOUS les enregistrements, sérialisés à l'identique.
     *
     * C'est le seul contrôle qui vaille pour un chantier de performance sur un moteur
     * financier : on compare l'empreinte prise avant la modification à celle prise
     * après, et la moindre décimale qui bouge se voit. Une optimisation n'a le droit
     * de changer que la durée.
     */
    private function ecrireEmpreinte(SymfonyStyle $io, string $fqcn, int $idEntreprise, int $taille, string $fichier): int
    {
        $page = $this->pageFroide($fqcn, $idEntreprise, $taille);
        $this->canvasBuilder->batchPreloadForCollection($page);

        $empreinte = [];
        foreach ($page as $entite) {
            $this->canvasBuilder->loadAllCalculatedValues($entite);
            $valeurs = get_object_vars($entite);
            // Les relations hydratées ne sont pas des indicateurs : seules les valeurs
            // scalaires posées par le calcul nous intéressent, et elles seules sont
            // comparables d'une exécution à l'autre.
            $valeurs = array_filter($valeurs, static fn ($v) => is_scalar($v) || $v === null);
            ksort($valeurs);
            $empreinte[$entite->getId()] = $valeurs;
        }

        ksort($empreinte);
        file_put_contents($fichier, json_encode($empreinte, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $io->success(sprintf('Empreinte de %d enregistrements écrite dans %s', count($empreinte), $fichier));

        return Command::SUCCESS;
    }

    /**
     * Une page lue à FROID : sans cela, le deuxième essai mesurerait l'identity map
     * du premier et non le coût réel d'un affichage.
     *
     * @return object[]
     */
    private function pageFroide(string $fqcn, int $idEntreprise, int $taille): array
    {
        $this->em->clear();
        $this->helper->reset();
        gc_collect_cycles();

        return $this->em->getRepository($fqcn)
            ->findBy(['entreprise' => $idEntreprise], ['id' => 'ASC'], $taille);
    }

    /** Le compteur de SELECT de la connexion : Doctrine n'utilise pas de requêtes préparées ici. */
    private function comSelect(): int
    {
        $ligne = $this->em->getConnection()
            ->executeQuery("SHOW SESSION STATUS LIKE 'Com_select'")
            ->fetchAssociative();

        return (int) ($ligne['Value'] ?? 0);
    }

    /** Mesurer sur une entreprise vide ne prouverait rien : on prend la mieux fournie. */
    private function entrepriseLaMieuxFournie(string $fqcn): int
    {
        $ligne = $this->em->createQuery(
            sprintf('SELECT IDENTITY(e.entreprise) AS ent, COUNT(e.id) AS n FROM %s e GROUP BY e.entreprise ORDER BY n DESC', $fqcn)
        )->setMaxResults(1)->getArrayResult();

        return (int) ($ligne[0]['ent'] ?? 0);
    }
}
