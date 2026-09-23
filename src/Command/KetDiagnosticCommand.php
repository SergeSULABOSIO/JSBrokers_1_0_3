<?php

namespace App\Command;

use App\Ai\Fournisseur\EtatDesFournisseurs;
use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use App\Ai\Oreille\OreilleDeKet;
use App\Ai\Oreille\Transcription;
use App\Ai\Voix\VoixDeKet;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * QUI RÉPOND POUR KET, SUR CE SERVEUR-CI, MAINTENANT.
 *
 * POURQUOI CETTE COMMANDE EXISTE. L'écran de console montre l'état des
 * fournisseurs — mais il faut un navigateur, une session super-admin, et il ne
 * dit que ce que le serveur CROIT. Quand Ket n'écoute pas en production et
 * qu'elle écoute en développement, la question n'est pas « qu'affiche l'écran »
 * mais « qu'est-ce qui diffère entre ces deux machines ». Une commande se lance
 * en SSH sur l'une et sur l'autre, et les deux sorties se comparent ligne à
 * ligne.
 *
 * DEUX NIVEAUX, ET LA DIFFÉRENCE COMPTE.
 *
 *  - SANS OPTION : l'audit statique. Zéro token, zéro appel réseau, zéro
 *    centime. Il dit ce qui est CONFIGURÉ : la chaîne en vigueur, les clés
 *    posées, les marques d'épuisement, le modèle de chacun. C'est ce qu'on
 *    lance en premier, et c'est souvent suffisant — une clé absente ou une
 *    chaîne vide se voit là.
 *
 *  - AVEC `--reel` : on APPELLE vraiment la voix et les oreilles, et on
 *    rapporte ce qu'elles répondent. Parce qu'une clé présente ne prouve pas
 *    qu'elle est valide, qu'un quota reste, ni que le réseau sortant du serveur
 *    atteint le fournisseur. C'est précisément l'écart qui fait qu'un écran tout
 *    vert cohabite avec un Ket muet. Quelques centimes, d'où l'option.
 *
 * CE QU'ELLE NE COUVRE PAS, ET IL FAUT LE DIRE : le moteur de texte, la
 * compréhension et la finition de dictée ne sont testés QUE statiquement. Les
 * appeler pour de vrai demande un contexte d'entreprise (périmètre, outils,
 * portefeuille) que cette commande n'a pas à fabriquer : `app:assistant:smoke`
 * le fait déjà, et mieux. La ligne de conclusion le rappelle.
 */
#[AsCommand(
    name: 'app:ket:diagnostic',
    description: "L'état réel des fournisseurs de Ket sur ce serveur — chaîne, clés, quotas, modèles.",
)]
class KetDiagnosticCommand extends Command
{
    /** Ce que chaque famille sert, en une ligne, pour qui lit la sortie sans le code. */
    private const ROLES = [
        'moteur'        => 'rédige les réponses et choisit les outils',
        'comprehension' => 'établit ce que la demande veut dire, avant tout le reste',
        'dictee'        => 'met au propre le texte dicté',
        'voix'          => 'lit les réponses à voix haute',
        'oreille'       => 'transcrit la parole en mode Live',
    ];

    /**
     * Ce que la famille perd quand plus personne ne peut répondre. Un diagnostic
     * qui dit « indisponible » sans dire ce que ça coûte oblige à aller lire le
     * code pour savoir s'il faut s'inquiéter.
     */
    private const CONSEQUENCES = [
        'moteur'        => 'Ket ne répond plus du tout.',
        'comprehension' => 'sans gravité : la demande passe telle quelle (phase facultative).',
        'dictee'        => 'sans gravité : le texte dicté part sans être mis au propre.',
        'voix'          => 'sans gravité : c’est le navigateur qui lit, gratuitement.',
        'oreille'       => 'sans gravité SI le navigateur reconnaît la parole ; sinon le mode Live n’entend rien.',
    ];

    public function __construct(
        private readonly EtatDesFournisseurs $etat,
        private readonly PolitiqueDesFournisseurs $politique,
        private readonly VoixDeKet $voix,
        private readonly OreilleDeKet $oreille,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'reel',
                null,
                InputOption::VALUE_NONE,
                'Appeler réellement la voix et les oreilles (quelques centimes) au lieu de se fier à la configuration.',
            )
            ->setHelp(<<<'AIDE'
                L'audit statique ne coûte rien et se lance partout :

                  <info>php bin/console app:ket:diagnostic</info>

                Pour savoir si les clés sont VALIDES et si le serveur atteint vraiment
                les fournisseurs — ce qu'aucune configuration ne peut dire :

                  <info>php bin/console app:ket:diagnostic --reel</info>

                Le moteur de texte se teste, lui, avec <info>app:assistant:smoke</info>,
                qui dispose du contexte d'entreprise nécessaire.
                AIDE)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Les fournisseurs de Ket sur ce serveur');

        $tout = $this->etat->tout();
        $familles = [];

        foreach (PolitiqueDesFournisseurs::FAMILLES as $famille) {
            $familles[$famille] = $this->auditer($io, $famille, $tout[$famille] ?? []);
        }

        if ($input->getOption('reel')) {
            $io->section('Appels réels');
            $this->appelerLaVoix($io);
            $this->appelerLesOreilles($io);
        } else {
            $io->note(
                'Audit de configuration seulement. Une clé présente ne prouve ni qu’elle est valide, '
                . 'ni qu’il reste du quota, ni que ce serveur joint le fournisseur : relancez avec --reel pour le savoir.',
            );
        }

        return $this->conclure($io, $familles);
    }

    /**
     * L'audit statique d'une famille.
     *
     * @param list<array{nom: string, disponible: bool, epuise: bool, modele: string|null, cle: string|null, echeance: string|null}> $etat
     *
     * @return bool quelqu'un peut-il répondre dans cette famille
     */
    private function auditer(SymfonyStyle $io, string $famille, array $etat): bool
    {
        $io->section(sprintf('%s — %s', mb_strtoupper($famille), self::ROLES[$famille] ?? ''));

        $ordre = array_values(array_filter(explode(',', $this->politique->ordre($famille))));
        $mode = $this->politique->mode($famille);

        $io->writeln(sprintf(
            ' Mode : <info>%s</info> — %s',
            $mode,
            $mode === PolitiqueDesFournisseurs::MODE_EPINGLE
                ? 'seul le premier de la liste est appelé, sans repli'
                : 'la liste est parcourue de haut en bas',
        ));
        $io->newLine();

        $lignes = [];
        $unPeutRepondre = false;

        foreach ($etat as $fournisseur) {
            $rang = array_search($fournisseur['nom'], $ordre, true);
            $utilisable = $rang !== false && $fournisseur['disponible'] && !$fournisseur['epuise'];
            $unPeutRepondre = $unPeutRepondre || $utilisable;

            $lignes[] = [
                $rang === false ? '—' : (string) ($rang + 1),
                $fournisseur['nom'],
                $rang === false ? '<comment>écarté de la chaîne</comment>' : 'dans la chaîne',
                $fournisseur['disponible'] ? 'oui' : '<error>NON</error>',
                $this->quota($fournisseur),
                $fournisseur['modele'] ?? '—',
            ];
        }

        if ($lignes === []) {
            $io->writeln(' <error>Aucun fournisseur n’est déclaré pour cette famille.</error>');

            return false;
        }

        $io->table(['Rang', 'Fournisseur', 'Chaîne', 'Configuré', 'Quota', 'Modèle'], $lignes);

        if ($unPeutRepondre) {
            $io->writeln(' <info>✓</info> Au moins un fournisseur peut répondre.');
        } else {
            $io->writeln(sprintf(' <error>✗ Personne ne peut répondre</error> — %s', self::CONSEQUENCES[$famille] ?? ''));
        }

        return $unPeutRepondre;
    }

    /**
     * @param array{epuise: bool, echeance: string|null} $fournisseur
     */
    private function quota(array $fournisseur): string
    {
        if (!$fournisseur['epuise']) {
            return 'ok';
        }

        return $fournisseur['echeance'] === null
            ? '<comment>à sec</comment>'
            : sprintf('<comment>à sec jusqu’à %s</comment>', (new \DateTimeImmutable($fournisseur['echeance']))->format('d/m H:i'));
    }

    /** Une phrase courte, vraiment synthétisée : on ne juge pas une clé sur sa présence. */
    private function appelerLaVoix(SymfonyStyle $io): void
    {
        if (!$this->voix->estDisponible()) {
            $io->writeln(' VOIX    : <comment>aucun fournisseur configuré, rien à appeler.</comment>');

            return;
        }

        $octets = 0;
        try {
            foreach ($this->voix->flux('Bonjour, ceci est un test.') as $morceau) {
                $octets += \strlen((string) $morceau);
                // Le premier morceau suffit à prouver que la clé est acceptée et que le
                // serveur joint le fournisseur : on ne paie pas la phrase entière.
                if ($octets > 0) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            $io->writeln(sprintf(' VOIX    : <error>échec</error> — %s', $e->getMessage()));

            return;
        }

        $rendu = $this->voix->dernierFournisseur();
        $io->writeln($octets > 0
            ? sprintf(' VOIX    : <info>OK</info> — %s a rendu du son (%d octets sur le premier morceau).', $rendu?->nom() ?? '?', $octets)
            : ' VOIX    : <error>aucun son rendu</error> — le navigateur lira à sa place.');
    }

    /**
     * On envoie un vrai WAV et on regarde ce qui revient.
     *
     * CE QUE CELA PROUVE, ET CE QUE CELA NE PROUVE PAS. Le son est une tonalité,
     * pas une phrase : un « complet » sans texte est donc le résultat NORMAL et
     * prouve ce qu'on cherche — clé acceptée, réseau joignable, quota restant. Ce
     * que cela ne prouve pas, c'est la qualité de la transcription ; pour cela il
     * faut parler dans un micro.
     */
    private function appelerLesOreilles(SymfonyStyle $io): void
    {
        if (!$this->oreille->estDisponible()) {
            $io->writeln(' OREILLE : <comment>aucun fournisseur configuré, rien à appeler.</comment>');
            $io->writeln('           En mode Live, seule la reconnaissance du navigateur peut alors entendre.');

            return;
        }

        try {
            $transcription = $this->oreille->transcrire($this->wavDeTest());
        } catch (\Throwable $e) {
            $io->writeln(sprintf(' OREILLE : <error>échec</error> — %s', $e->getMessage()));

            return;
        }

        $io->writeln(match ($transcription->statut) {
            Transcription::COMPLET => sprintf(
                ' OREILLE : <info>OK</info> — %s a répondu%s.',
                $transcription->fournisseur !== '' ? $transcription->fournisseur : '?',
                $transcription->aDuTexte() ? sprintf(' (« %s »)', $transcription->texte) : ' (aucun mot : normal sur une tonalité)',
            ),
            Transcription::QUOTA => ' OREILLE : <error>quota épuisé</error> chez tous les fournisseurs configurés.',
            Transcription::INDISPONIBLE => ' OREILLE : <error>aucun fournisseur joignable</error>.',
            default => ' OREILLE : <error>échec</error> — le fournisseur a refusé le son.',
        });
    }

    /** Une tonalité de 0,5 s en PCM 16 bits mono 16 kHz — le format que les oreilles attendent. */
    private function wavDeTest(): string
    {
        $frequenceEchantillonnage = 16000;
        $echantillons = (int) ($frequenceEchantillonnage * 0.5);

        $pcm = '';
        for ($i = 0; $i < $echantillons; ++$i) {
            $pcm .= pack('v', (int) (8000 * sin(2 * M_PI * 440 * $i / $frequenceEchantillonnage)) & 0xFFFF);
        }

        $tailleDonnees = \strlen($pcm);

        return 'RIFF' . pack('V', 36 + $tailleDonnees) . 'WAVE'
            . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1)
            . pack('V', $frequenceEchantillonnage) . pack('V', $frequenceEchantillonnage * 2)
            . pack('v', 2) . pack('v', 16)
            . 'data' . pack('V', $tailleDonnees) . $pcm;
    }

    /**
     * @param array<string, bool> $familles
     */
    private function conclure(SymfonyStyle $io, array $familles): int
    {
        $io->section('Verdict');

        // SEUL LE MOTEUR EST VITAL. Les quatre autres familles ont toutes un repli
        // gratuit — la demande passe telle quelle, le navigateur lit, le navigateur
        // écoute. Les confondre ferait sonner l'alarme pour une voix payante à sec,
        // ce qui est le fonctionnement NORMAL d'un palier gratuit.
        $muettes = array_keys(array_filter($familles, static fn (bool $ok): bool => !$ok));

        if ($muettes === []) {
            $io->success('Les cinq familles ont au moins un fournisseur prêt à répondre.');
        } elseif (\in_array('moteur', $muettes, true)) {
            $io->error('Le MOTEUR DE TEXTE n’a personne : Ket ne peut pas répondre du tout.');
        } else {
            $io->warning(sprintf(
                'Ces familles n’ont personne : %s. Aucune n’empêche Ket de répondre, mais chacune perd son service.',
                implode(', ', $muettes),
            ));
        }

        $io->writeln(' Le moteur de texte se teste réellement avec : <info>php bin/console app:assistant:smoke [idEntreprise] "Combien de clients ?"</info>');

        return \in_array('moteur', $muettes, true) ? Command::FAILURE : Command::SUCCESS;
    }
}
