<?php

namespace App\Command;

use App\Echange\Etat\EtatDuPortefeuille;
use App\Echange\Etat\ProducteurDeLEtat;
use App\Entity\Entreprise;
use App\Repository\InviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * VÉRIFICATION À CHAUD de l'état du portefeuille, sur les VRAIES données d'un cabinet.
 *
 * Les tests travaillent sur des cabinets fabriqués : des tranches propres, des taxes
 * paramétrées, un partenaire bien rangé. Le réel apporte les cas qu'on n'écrit pas —
 * une tranche sans cotation, une police sans avenant, une taxe absente. C'est cette
 * commande qui les rencontre en premier, et qui les NOMME.
 *
 * ⚠ ELLE NE FACTURE JAMAIS. Elle passe par `produire()`, jamais par `exporter()`. La
 * distinction n'est pas théorique : une commande de diagnostic branchée sur le chemin
 * complet a débité 23 400 tokens au propriétaire d'un cabinet réel pour une vérification
 * que personne n'avait demandée.
 */
#[AsCommand(
    name: 'app:etat:smoke',
    description: 'Produit l\'état du portefeuille d\'un cabinet et rend compte de ce qu\'il contient.',
)]
final class EtatSmokeCommand extends Command
{
    public function __construct(
        private readonly ProducteurDeLEtat $producteur,
        private readonly InviteRepository $invites,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('idEntreprise', InputArgument::REQUIRED, 'Identifiant du cabinet');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Les avertissements PHP deviennent des exceptions : c'est exactement le genre de
        // défaut (« Array to string conversion ») qui passe inaperçu en production et fait
        // échouer l'écriture du classeur bien plus loin, sans dire pourquoi.
        set_error_handler(static function (int $niveau, string $message, string $fichier, int $ligne): bool {
            throw new \ErrorException($message, 0, $niveau, $fichier, $ligne);
        });

        try {
            $entreprise = $this->em->getRepository(Entreprise::class)->find((int) $input->getArgument('idEntreprise'));
            if ($entreprise === null) {
                $io->error('Cabinet introuvable.');

                return Command::FAILURE;
            }

            $invite = $this->invites->findOneBy(['entreprise' => $entreprise, 'proprietaire' => true])
                ?? $this->invites->findOneBy(['entreprise' => $entreprise]);
            if ($invite === null) {
                $io->error('Ce cabinet n\'a aucun invité : impossible de signer l\'état.');

                return Command::FAILURE;
            }

            $debut = microtime(true);
            [$classeur, $manifeste, $total] = $this->producteur->produire(
                $entreprise,
                $invite,
                $entreprise->getUtilisateur(),
            );
            $duree = microtime(true) - $debut;

            $feuille = $classeur->getSheetByName(EtatDuPortefeuille::FEUILLE);
            if ($feuille === null) {
                $io->error('La feuille de données est absente.');

                return Command::FAILURE;
            }

            $colonnes = Coordinate::columnIndexFromString($feuille->getHighestDataColumn());
            $lignes = max(0, $feuille->getHighestDataRow() - 1);

            $io->section(sprintf('État de « %s »', $entreprise->getNom() ?? '?'));
            $io->listing([
                sprintf('%d feuille(s) : %s', $classeur->getSheetCount(), implode(', ', $classeur->getSheetNames())),
                sprintf('%d colonne(s), %d ligne(s) de données', $colonnes, $lignes),
                sprintf('%d tranche(s) annoncée(s) par le pré-comptage', $total),
                sprintf('produit en %.1f s', $duree),
                sprintf('empreinte de structure : %s', substr($manifeste->empreinteEntetes, 0, 12)),
            ]);

            // ⚠ LE PRÉ-COMPTAGE ET LE RÉSULTAT DOIVENT CONCORDER. S'ils divergent, la
            // barre de progression ment — elle annonce un reste qui n'arrivera jamais, ou
            // atteint 100 % avant la fin.
            if ($lignes !== $total) {
                $io->warning(sprintf(
                    'Le pré-comptage annonce %d ligne(s), le classeur en porte %d : la progression serait fausse.',
                    $total,
                    $lignes,
                ));

                return Command::FAILURE;
            }

            $io->success('L\'état se produit sans avertissement.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('%s : %s', $e::class, $e->getMessage()));
            $io->writeln(sprintf('<comment>%s:%d</comment>', $e->getFile(), $e->getLine()));

            return Command::FAILURE;
        } finally {
            restore_error_handler();
        }
    }
}
