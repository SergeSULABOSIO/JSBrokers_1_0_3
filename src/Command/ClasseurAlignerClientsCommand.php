<?php

namespace App\Command;

use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\Document;
use App\Service\Document\ClasseurDuClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Command\Command;

/**
 * RATTRAPAGE des documents enregistrés AVANT que le rangement ne devienne automatique.
 *
 * POURQUOI IL Y A QUELQUE CHOSE À RATTRAPER. Le classement des documents n'existait qu'à
 * moitié : le champ `Document.classeur` était là depuis l'origine, le formulaire l'offrait,
 * l'écran affichait « Classé dans : … » — et aucun code de production ne créait de classeur
 * ni n'en posait un. Tout l'historique porte donc la marque de ce vide : des documents « non
 * classés », et des clients sans dossier. Le rangement est désormais automatique pour ce qui
 * s'écrit ; cette commande s'occupe de ce qui était déjà écrit.
 *
 * CE QU'ELLE FAIT, DANS CET ORDRE :
 *
 *  1. elle donne son classeur à chaque CLIENT qui n'en a pas encore un — même sans document,
 *     parce que la règle est « tout client a son classeur », et qu'un dossier vide qui existe
 *     vaut mieux qu'un dossier qui apparaît un jour par surprise ;
 *  2. elle range chaque DOCUMENT non classé qui relève d'un client.
 *
 * CE QU'ELLE NE FAIT PAS SANS QU'ON LE DEMANDE. Un document déjà rangé dans un classeur
 * choisi à la main n'est PAS déplacé. C'est une décision de l'utilisateur, et la défaire
 * d'office lui ferait chercher ses pièces là où il les avait mises. L'option `--reclasser`
 * existe pour ceux qui veulent l'alignement complet, et elle le dit franchement : ce qui
 * était rangé ailleurs rejoint le dossier du client.
 *
 * DRY-RUN PAR DÉFAUT. Sans `--force`, rien n'est écrit : la commande rapporte ce qu'elle
 * ferait. Une reprise de données doit pouvoir se lire avant de s'exécuter.
 *
 * IDEMPOTENTE. Relancée, elle ne trouve plus rien à faire — le classeur d'un client est
 * retrouvé par sa RELATION, jamais recréé à côté du précédent.
 */
#[AsCommand(
    name: 'app:classeur:aligner-clients',
    description: 'Donne à chaque client son classeur et y range ses documents non classés.',
)]
final class ClasseurAlignerClientsCommand extends Command
{
    /**
     * Taille des paquets de documents traités entre deux `flush`.
     *
     * Un seul flush pour cent mille documents tiendrait toute l'unité de travail en
     * mémoire ; un flush par document multiplierait les allers-retours. Le compromis est
     * arbitraire mais borné, ce qui est le point.
     */
    private const LOT = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClasseurDuClient $classeurs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Écrit réellement. Sans cette option, la commande se contente de rapporter (dry-run).',
        );
        $this->addOption(
            'reclasser',
            null,
            InputOption::VALUE_NONE,
            'Déplace AUSSI les documents déjà rangés dans un autre classeur. À utiliser en connaissance '
            . 'de cause : cela défait les classements faits à la main.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ecrire = (bool) $input->getOption('force');
        $reclasser = (bool) $input->getOption('reclasser');

        if (!$ecrire) {
            $io->note('Dry-run : aucune écriture. Relancer avec --force pour appliquer.');
        }
        if ($reclasser) {
            $io->warning('Option --reclasser : les documents déjà rangés à la main seront DÉPLACÉS.');
        }

        $totaux = [
            'classeurs' => 0,
            'ranges' => 0,
            'deplaces' => 0,
            'deja' => 0,
            'sansClient' => 0,
        ];

        // ── 1. Tout client a son classeur.
        $io->section('Classeurs des clients');
        foreach ($this->em->getRepository(Client::class)->findAll() as $client) {
            if ($client->getEntreprise() === null) {
                // L'entreprise est NOT NULL sur Classeur : un client sans entreprise ne
                // peut pas en recevoir un. On le signale plutôt que de faire échouer tout
                // le rattrapage sur un enregistrement aberrant.
                $io->warning(sprintf('Client #%d sans entreprise, ignoré.', $client->getId() ?? 0));
                continue;
            }

            $existant = $this->em->getRepository(Classeur::class)->findOneBy(['client' => $client]);
            if ($existant instanceof Classeur) {
                continue;
            }

            ++$totaux['classeurs'];
            $io->writeln(sprintf('  + classeur « %s » (client #%d)', $this->classeurs->nomPour($client), $client->getId() ?? 0));

            if ($ecrire) {
                $this->classeurs->pour($client);
            }
        }

        if ($ecrire) {
            $this->em->flush();
        }

        // ── 2. Les documents rejoignent le dossier de leur client.
        $io->section('Rangement des documents');
        $documents = $this->em->getRepository(Document::class)->findAll();
        $traites = 0;

        foreach ($documents as $document) {
            $client = $this->classeurs->clientDe($document);

            if (!$client instanceof Client || $client->getEntreprise() === null) {
                ++$totaux['sansClient'];
                continue;
            }

            $actuel = $document->getClasseur();

            // DÉJÀ AU BON ENDROIT : rien à faire, et c'est le cas de tout second passage.
            if ($actuel instanceof Classeur && $actuel->getClient() === $client) {
                ++$totaux['deja'];
                continue;
            }

            if ($actuel instanceof Classeur && !$reclasser) {
                // Rangé ailleurs, à la main : on le laisse et on le dit. Le compte
                // renseigne l'utilisateur sur ce que `--reclasser` déplacerait.
                ++$totaux['deja'];
                continue;
            }

            $estUnDeplacement = $actuel instanceof Classeur;
            ++$totaux[$estUnDeplacement ? 'deplaces' : 'ranges'];
            $io->writeln(sprintf(
                '  %s document #%d « %s » → %s',
                $estUnDeplacement ? '↷' : '+',
                $document->getId() ?? 0,
                (string) $document->getNom(),
                $this->classeurs->nomPour($client),
            ));

            if ($ecrire) {
                // Le classeur est retrouvé ou créé ici même : le rattrapage n'a pas
                // besoin de l'écouteur Doctrine, qui ne verrait de toute façon rien à
                // faire puisque la relation est déjà posée.
                $document->setClasseur($this->classeurs->pour($client));

                if (++$traites % self::LOT === 0) {
                    $this->em->flush();
                }
            }
        }

        if ($ecrire) {
            $this->em->flush();
        }

        $io->section('Bilan');
        $io->table(
            ['Ce qui a été fait', 'Nombre'],
            [
                ['Classeurs de client créés', $totaux['classeurs']],
                ['Documents rangés', $totaux['ranges']],
                ['Documents déplacés', $totaux['deplaces']],
                ['Documents déjà classés (laissés tels quels)', $totaux['deja']],
                ['Documents sans client (non classables)', $totaux['sansClient']],
            ],
        );

        if (!$reclasser && $totaux['deja'] > 0) {
            $io->note(sprintf(
                '%d document(s) sont déjà rangés. Ceux qui le sont dans un autre classeur que celui de leur '
                . 'client ne bougeront qu’avec --reclasser.',
                $totaux['deja'],
            ));
        }

        $io->success(sprintf(
            'Alignement terminé%s',
            $ecrire ? '.' : ' — RIEN N\'A ÉTÉ ÉCRIT (dry-run).',
        ));

        return Command::SUCCESS;
    }
}
