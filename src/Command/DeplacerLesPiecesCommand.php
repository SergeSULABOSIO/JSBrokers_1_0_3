<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * LES FICHIERS DÉPOSÉS QUITTENT LA RACINE WEB (chantier 18, décisions de Serge).
 *
 *   — les PIÈCES (`document.nom_fichier_stocke`) : l'ancien dossier public (`app.ancien_dossier_des_pieces`) → le dossier des
 *     pièces (`app.dossier_des_pieces`, hors de public/) ;
 *   — les LOGOS (`entreprise.thumbnail`) : public/images/entreprises → leur propre dossier
 *     (`app.dossier_des_logos`), séparé des images de marque suivies par git.
 *
 * Les noms ne changent pas : ce sont ceux que la base référence. Seuls les fichiers
 * RÉFÉRENCÉS sont déplacés ; un fichier que rien ne désigne reste où il est — le rapport
 * les COMPTE et en nomme les premiers (« non référencés »), leur sort est une décision.
 *
 * EN DEUX TEMPS, ET SANS RIEN PERDRE :
 *   1. copier chaque fichier, puis VÉRIFIER la copie par SHA-256 ;
 *   2. seulement si AUCUN échec : supprimer les originaux.
 * Un seul échec = aucune suppression (les copies vérifiées restent, la commande est
 * idempotente : relancée, elle compte « déjà présents »). Un fichier du dépôt git (image
 * de marque) n'est jamais supprimé — le prochain déploiement le recréerait.
 *
 * Sans --executer, la commande ne fait que rapporter (essai à blanc). bin/deploy.sh la lance
 * avec --executer, site en maintenance.
 */
#[AsCommand(
    name: 'app:documents:deplacer',
    description: 'Déplace les pièces et les logos déposés hors de leurs anciens dossiers publics (SHA-256 vérifié).',
)]
final class DeplacerLesPiecesCommand extends Command
{
    /** Les fichiers SUIVIS PAR GIT dans l'ancien dossier des logos : copiés, jamais supprimés. */
    public const FICHIERS_DU_DEPOT = [
        'default_entreprise.jpg',
        'logo.jpg',
        'logofav.png',
        'stockcake-car-collision-aftermath-1728165197-67122bd53c68e266480512.jpg',
        'stockcake-protective-insurance-agent-1728165178-6711106c667aa085998230.jpg',
        'stockcake-protective-insurance-agent-1728165178-67862fe53e393163260350.jpg',
        'stockcake-stock-market-frenzy-1728165108-670e6be0815ab603473082.jpg',
        'stockcake-stock-market-frenzy-1728165108-6711105b1612c716514637.jpg',
        'stockcake-stock-market-frenzy-1728165108-676de97bde631598424533.jpg',
    ];

    /** Les extensions admises dans le dossier PUBLIC des logos (`jpeg` : anciens noms). */
    public const EXTENSIONS_DE_LOGO = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%app.ancien_dossier_des_pieces%')]
        private readonly string $anciennesPieces,
        #[Autowire('%app.dossier_des_pieces%')]
        private readonly string $pieces,
        #[Autowire('%kernel.project_dir%/public/images/entreprises')]
        private readonly string $anciensLogos,
        #[Autowire('%app.dossier_des_logos%')]
        private readonly string $logos,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('executer', null, InputOption::VALUE_NONE, 'Déplace réellement. Sans cette option : essai à blanc.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $executer = (bool) $input->getOption('executer');
        $conn = $this->em->getConnection();

        $lots = [
            'Pièces' => [$this->anciennesPieces, $this->pieces, $conn->fetchFirstColumn(
                "SELECT DISTINCT nom_fichier_stocke FROM document WHERE nom_fichier_stocke IS NOT NULL AND nom_fichier_stocke <> ''",
            )],
            'Logos' => [$this->anciensLogos, $this->logos, $conn->fetchFirstColumn(
                "SELECT DISTINCT thumbnail FROM entreprise WHERE thumbnail IS NOT NULL AND thumbnail <> ''",
            )],
        ];

        $rapport = ['deplaces' => 0, 'deja' => 0, 'introuvables' => 0, 'echecs' => [], 'orphelins' => []];
        $aSupprimer = [];

        foreach ($lots as $libelle => [$source, $destination, $noms]) {
            if ($executer && !is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
                $rapport['echecs'][] = sprintf('%s : dossier de destination impossible à créer (%s).', $libelle, $destination);
                continue;
            }
            $references = array_flip(array_map('strval', $noms));
            foreach (is_dir($source) ? (scandir($source) ?: []) : [] as $present) {
                if (is_file($source . '/' . $present) && !isset($references[$present])
                    && !\in_array($present, ['.htaccess', '.gitkeep'], true)
                    && !($libelle === 'Logos' && \in_array($present, self::FICHIERS_DU_DEPOT, true))) {
                    $rapport['orphelins'][] = $libelle . ' : ' . $present;
                }
            }
            foreach ($noms as $nom) {
                $nom = (string) $nom;
                if ($nom !== basename($nom)) {
                    $rapport['echecs'][] = sprintf('%s : nom refusé (%s).', $libelle, $nom);
                    continue;
                }
                // Le dossier des logos est PUBLIC : n'y entre qu'une extension de la liste fermée
                // (un ancien nom gardait l'extension ANNONCÉE). Sinon : échec, à traiter à la main.
                if ($libelle === 'Logos' && !\in_array(strtolower(pathinfo($nom, PATHINFO_EXTENSION)), self::EXTENSIONS_DE_LOGO, true)) {
                    $rapport['echecs'][] = sprintf('%s : « %s » n\'a pas une extension d\'image admise (%s).', $libelle, $nom, implode(', ', self::EXTENSIONS_DE_LOGO));
                    continue;
                }
                $de = $source . '/' . $nom;
                $vers = $destination . '/' . $nom;
                $existeSource = is_file($de);
                $existeDestination = is_file($vers);

                if (!$existeSource) {
                    // Déjà déplacé (relance) ou jamais présent : rien à copier.
                    $existeDestination ? $rapport['deja']++ : $rapport['introuvables']++;
                    continue;
                }
                $empreinte = hash_file('sha256', $de);
                if (!\is_string($empreinte)) {
                    $rapport['echecs'][] = sprintf('%s : « %s » illisible (empreinte impossible).', $libelle, $nom);
                    continue;
                }
                if ($existeDestination) {
                    if (hash_file('sha256', $vers) !== $empreinte) {
                        $rapport['echecs'][] = sprintf('%s : « %s » existe déjà à destination avec un autre contenu.', $libelle, $nom);
                        continue;
                    }
                    $rapport['deja']++;
                } elseif ($executer) {
                    if (!@copy($de, $vers) || hash_file('sha256', $vers) !== $empreinte) {
                        // La copie fautive ne reste pas : relancée, la commande la referait
                        // au lieu de la prendre pour « un autre contenu » à destination.
                        @unlink($vers);
                        $rapport['echecs'][] = sprintf('%s : copie de « %s » non conforme (SHA-256), retirée.', $libelle, $nom);
                        continue;
                    }
                    $rapport['deplaces']++;
                } else {
                    $rapport['deplaces']++;
                }
                if (!($libelle === 'Logos' && \in_array($nom, self::FICHIERS_DU_DEPOT, true))) {
                    $aSupprimer[] = $de;
                }
            }
        }

        $titre = $executer ? 'Déplacement' : 'Essai à blanc (rien n\'est écrit — relancer avec --executer)';
        $io->section($titre);
        $io->listing([
            sprintf('déplacés : %d', $rapport['deplaces']),
            sprintf('déjà présents : %d', $rapport['deja']),
            sprintf('introuvables (référencés, absents partout) : %d', $rapport['introuvables']),
            sprintf('en échec : %d', \count($rapport['echecs'])),
            sprintf('non référencés par la base, laissés en place : %d', \count($rapport['orphelins'])),
        ]);
        if ($rapport['orphelins'] !== []) {
            $io->note(array_merge(
                ['Fichiers que la base ne désigne pas (laissés où ils sont — leur sort est une décision) :'],
                \array_slice($rapport['orphelins'], 0, 20),
                \count($rapport['orphelins']) > 20 ? [sprintf('… et %d autre(s).', \count($rapport['orphelins']) - 20)] : [],
            ));
        }

        if ($rapport['echecs'] !== []) {
            $io->error(array_merge(['AUCUN original n\'a été supprimé :'], $rapport['echecs']));

            return Command::FAILURE;
        }

        if ($executer) {
            $supprimes = 0;
            $restes = [];
            foreach ($aSupprimer as $original) {
                if (@unlink($original) || !is_file($original)) {
                    ++$supprimes;
                } else {
                    $restes[] = $original;
                }
            }
            if ($restes !== []) {
                // Les copies sont vérifiées et servies : l'application fonctionne. Mais ces
                // originaux restent dans l'ancien dossier — à retirer à la main.
                $io->warning(array_merge([sprintf('%d original(aux) NON supprimé(s) (droits ?) :', \count($restes))], $restes));
            }
            $io->success(sprintf('%d original(aux) supprimé(s) après vérification.', $supprimes));
        }

        return Command::SUCCESS;
    }
}
