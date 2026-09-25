<?php

namespace App\Command;

use App\Ai\Telemetrie\RapportTokens;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dépouille la campagne de mesure des tokens de l'assistant IA.
 *
 * Lit le canal « assistant_tokens » (une ligne JSON par tour et par message) et
 * répond aux quatre questions qui décident de la suite : combien de tours coûte
 * un message, quel pic par minute glissante on atteint réellement, quelle part
 * du volume est invariante, et combien de refus un allègement du contexte
 * aurait évités.
 *
 *   php bin/console app:assistant:tokens:rapport --depuis="2026-08-08"
 */
#[AsCommand(
    name: 'app:assistant:tokens:rapport',
    description: "Dépouille la campagne de mesure des tokens de l'assistant IA.",
)]
class AssistantTokensRapportCommand extends Command
{
    /** Scénarios d'allègement du bloc invariant testés par la projection. */
    private const REDUCTIONS = [0.10, 0.20, 0.30, 0.40];

    public function __construct(
        #[Autowire('%kernel.logs_dir%')] private readonly string $logsDir,
        // Le plafond réellement opposé au moteur (BudgetDebit) : sans lui, le
        // rapport continuerait de compter les dépassements contre le palier
        // gratuit longtemps après une bascule en payant.
        #[Autowire(env: 'int:GEMINI_TPM_PLAFOND')] private readonly int $plafond = RapportTokens::PLAFOND_ENTREE_PAR_MINUTE,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('depuis', null, InputOption::VALUE_REQUIRED, 'Date/heure de début (ex. "2026-08-08" ou "-3 days")')
            ->addOption('jusqu-a', null, InputOption::VALUE_REQUIRED, 'Date/heure de fin')
            ->addOption('fichier', null, InputOption::VALUE_REQUIRED, 'Journal à dépouiller (défaut : var/log/assistant_tokens*.log)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $depuis = $this->borne($input->getOption('depuis'), $io);
        $jusqua = $this->borne($input->getOption('jusqu-a'), $io);
        if ($depuis === false || $jusqua === false) {
            return Command::FAILURE;
        }

        $fichiers = $input->getOption('fichier') !== null
            ? [(string) $input->getOption('fichier')]
            : (glob($this->logsDir . '/assistant_tokens*.log') ?: []);

        if ($fichiers === []) {
            $io->warning(sprintf(
                'Aucun journal dans %s. La campagne n\'a rien enregistré : vérifiez que le canal '
                . '« assistant_tokens » est bien configuré et qu\'au moins un message a été envoyé à Ket.',
                $this->logsDir,
            ));

            return Command::SUCCESS;
        }

        $lignes = $this->lire($fichiers, $depuis, $jusqua);
        if ($lignes === []) {
            $io->warning('Journal présent mais aucune ligne dans la fenêtre demandée.');

            return Command::SUCCESS;
        }

        $rapport = new RapportTokens($lignes, $this->plafond);
        $tours = $rapport->tours();
        $messages = $rapport->messages();

        $io->title(sprintf('Campagne de mesure — %d tours, %d messages', \count($tours), \count($messages)));

        // ── Homogénéité de la campagne ───────────────────────────────────────
        $moteurs = $rapport->moteurs();
        if (\count($moteurs) > 1) {
            $io->warning(
                'Plusieurs moteurs/modèles dans la fenêtre : les mesures ne sont pas comparables entre elles. '
                . 'AiEngineResolver bascule sur Anthropic dès qu\'une clé est posée.'
            );
        }
        foreach ($moteurs as $moteur => $n) {
            $io->writeln(sprintf(' Moteur : <info>%s</info> (%d lignes)', $moteur, $n));
        }
        $io->newLine();

        // ── Question 1 : combien de tours coûte un message ? ─────────────────
        $io->section('1. Tours par message — le multiplicateur');
        $toursParMessage = $rapport->toursParMessage();
        $io->table(
            ['Médiane', 'p95', 'Max', 'Moyenne'],
            [[
                $this->nombre(RapportTokens::percentile($toursParMessage, 0.5)),
                $this->nombre(RapportTokens::percentile($toursParMessage, 0.95)),
                $toursParMessage !== [] ? max($toursParMessage) : '—',
                $toursParMessage !== [] ? sprintf('%.1f', array_sum($toursParMessage) / \count($toursParMessage)) : '—',
            ]],
        );
        $io->writeln(' Chaque tour réexpédie tout le contexte : diviser les tours par deux vaut mieux que compresser le prompt de 30 %.');
        $io->newLine();

        $io->writeln(' Issues des messages :');
        foreach ($rapport->issues() as $issue => $n) {
            $io->writeln(sprintf('   %-22s %d', $issue, $n));
        }
        $io->newLine();

        // ── Question 2 : le pic qui touche réellement le mur ─────────────────
        $io->section('2. Pic de tokens d\'entrée par minute glissante — la métrique fatale');
        $observe = $rapport->picParMinute();
        $io->writeln(sprintf(
            ' Pic observé : <comment>%s tokens</comment> sur 60 s (plafond %s)%s',
            number_format($observe['pic'], 0, ',', ' '),
            number_format($rapport->plafond(), 0, ',', ' '),
            $observe['instant'] !== null ? ' — le ' . $observe['instant'] : '',
        ));
        $io->writeln(sprintf(
            ' Tours au-dessus du plafond : <comment>%d</comment>, répartis sur %d message(s).',
            $observe['depassements'],
            $observe['messagesEnDepassement'],
        ));
        $io->writeln(' Ce pic agrège TOUS les invités et toutes les conversations : le quota est partagé, pas individuel.');
        $io->newLine();

        // ── Question 3 : coût par message et part invariante ─────────────────
        $io->section('3. Coût par message et part invariante');
        $entree = $rapport->entreeParMessage();
        $io->table(
            ['Tokens d\'entrée / message', 'Médiane', 'p95', 'Max'],
            [[
                '',
                $this->nombre(RapportTokens::percentile($entree, 0.5)),
                $this->nombre(RapportTokens::percentile($entree, 0.95)),
                $entree !== [] ? number_format(max($entree), 0, ',', ' ') : '—',
            ]],
        );
        $part = $rapport->partInvariante();
        $ratio = $rapport->ratioOctetsParToken();
        if ($part !== null) {
            $io->writeln(sprintf(
                ' Bloc invariant (prompt système + déclarations d\'outils) : <info>%.1f %%</info> des octets envoyés.',
                100 * $part,
            ));
        }
        if ($ratio !== null) {
            $io->writeln(sprintf(' Ratio observé : %.2f octets par token (mesuré, non supposé).', $ratio));
        }
        $io->newLine();

        // ── Outils qui font enchaîner les tours ──────────────────────────────
        $outils = $rapport->outilsLesPlusCouteux();
        if ($outils !== []) {
            $io->section('4. Outils, par coût induit (tours moyens du message où ils apparaissent)');
            $io->table(
                ['Outil', 'Appels', 'Messages', 'Tours moyens'],
                array_map(
                    static fn (array $o) => [$o['outil'], $o['appels'], $o['messages'], sprintf('%.1f', $o['toursMoyens'])],
                    \array_slice($outils, 0, 12),
                ),
            );
        }

        // ── La projection qui tranche ────────────────────────────────────────
        // ── LES BASCULES DE SECOURS ─────────────────────────────────────────────
        // Placées AVANT la projection, et ce n’est pas cosmétique : une projection
        // calculée sur des tours issus de deux modèles différents ne vaut rien. Le
        // lecteur doit savoir, à cet endroit précis, si la fenêtre en contient.
        $replis = $rapport->replis();
        if ($replis !== []) {
            $io->section('5. Bascules sur un modèle de secours');
            $io->writeln(sprintf(' <fg=yellow>%d bascule(s)</> dans la fenêtre : le message a changé de modèle en cours de route.', array_sum($replis)));
            $io->writeln(' Un même message peut donc relever de deux tarifs et de deux quotas.');
            $io->newLine();
            $io->table(['Bascule', 'Occurrences'], array_map(
                static fn (string $cle, int $n): array => [$cle, $n],
                array_keys($replis),
                array_values($replis),
            ));
        }

        // ── AIGUILLAGE ──────────────────────────────────────────────────────────
        //
        // La question la plus chère du rapport, et elle n'était pas posée : armer la
        // trousse d'ÉCRITURE coûte dix-neuf déclarations d'outils de plus et
        // vingt-sept kilo-octets de protocoles. Le journal portait déjà de quoi
        // répondre depuis le 2026-09-23 ; il manquait seulement de le lire.
        $aiguillages = $rapport->aiguillages();
        if ($aiguillages !== []) {
            $io->section('6. Aiguillage de trousse — paie-t-on l\'écriture à bon escient ?');
            $rangees = [];
            $armees = 0;
            $ecrites = 0;
            foreach ($aiguillages as $cle => $compte) {
                $rangees[] = [
                    $cle,
                    $compte['n'],
                    $compte['ecrit'],
                    sprintf('%d %%', (int) round(100 * $compte['ecrit'] / max(1, $compte['n']))),
                ];
                if (str_starts_with($cle, 'ecriture')) {
                    $armees += $compte['n'];
                    $ecrites += $compte['ecrit'];
                }
            }
            $io->table(['Trousse / déclencheur', 'Messages', 'Ayant écrit', 'Taux'], $rangees);
            if ($armees > 0) {
                $io->writeln(sprintf(
                    ' Lecture : %d message(s) ont été armés pour l\'écriture, %d ont écrit (%d %%).'
                    . ' Les %d autres ont payé la grosse trousse pour rien.',
                    $armees,
                    $ecrites,
                    (int) round(100 * $ecrites / $armees),
                    $armees - $ecrites,
                ));
            }

            $mots = $rapport->motsQuiArment();
            if ($mots !== []) {
                $io->newLine();
                $io->writeln(' <options=bold>Les mots qui arment l\'écriture</> — un mot qui n\'écrit jamais est un mot à retirer de la liste.');
                $io->table(['Mot', 'Messages', 'Ayant écrit'], array_map(
                    static fn (string $mot, array $c): array => [$mot, $c['n'], $c['ecrit']],
                    array_keys($mots),
                    array_values($mots),
                ));
            }
        }

        // ── COMPRÉHENSION ───────────────────────────────────────────────────────
        $motifs = $rapport->motifsDeRepli();
        if ($motifs !== []) {
            $io->section('7. Compréhension — pourquoi elle s\'est repliée');
            $io->writeln(' Un repli laisse le message partir sans rien comprendre. Les jetons disent lesquels');
            $io->writeln(' avaient DÉJÀ été payés : une sortie rejetée coûte plein tarif pour rien.');
            $io->newLine();
            $io->table(['Motif', 'Occurrences', 'Jetons dépensés'], array_map(
                static fn (string $cle, array $c): array => [$cle, $c['n'], number_format($c['tokens'], 0, ',', ' ')],
                array_keys($motifs),
                array_values($motifs),
            ));
        }

        $io->section('8. Projection — que rapporterait un allègement du bloc invariant ?');
        $rangees = [[
            'tel quel',
            number_format($observe['pic'], 0, ',', ' '),
            $observe['depassements'],
            $observe['messagesEnDepassement'],
        ], new TableSeparator()];
        foreach (self::REDUCTIONS as $reduction) {
            $projete = $rapport->picParMinute($reduction);
            $rangees[] = [
                sprintf('−%d %%', (int) round(100 * $reduction)),
                number_format($projete['pic'], 0, ',', ' '),
                $projete['depassements'],
                $projete['messagesEnDepassement'],
            ];
        }
        $io->table(['Bloc invariant', 'Pic / minute', 'Tours au-dessus du plafond', 'Messages touchés'], $rangees);

        // ⚠ LA CONCLUSION SE DÉDUIT DES CHIFFRES, elle ne les précède pas.
        //
        // Ces deux lignes étaient écrites EN DUR : « si un −20 % ramène les
        // dépassements à zéro… », « s'ils persistent même à −40 %… ». Elles
        // s'imprimaient à l'identique quels que soient les nombres du tableau
        // au-dessus — un rapport qui conclut avant de mesurer est pire qu'un rapport
        // muet, parce qu'on le croit.
        $io->writeln(' ' . $this->lectureDeLaProjection($rapport, $observe));
        $io->newLine();
        $io->note(
            'La projection rejoue la chronologie observée. Elle ne modélise pas le fait qu\'un utilisateur '
            . 'moins souvent refusé réessaie moins — le gain réel est donc plutôt sous-estimé.'
        );

        return Command::SUCCESS;
    }

    /**
     * CE QUE LA PROJECTION DIT VRAIMENT — déduit, jamais récité.
     *
     * Trois verdicts possibles, et un seul est vrai à la fois : rien ne dépasse ;
     * un allègement suffit, et on dit lequel ; aucun allègement ne suffit, et c'est
     * alors la simultanéité qui sature, pas la taille du contexte.
     *
     * @param array{pic: int, depassements: int, messagesEnDepassement: int} $observe
     */
    private function lectureDeLaProjection(RapportTokens $rapport, array $observe): string
    {
        // La DÉCISION vit dans RapportTokens (cœur pur, testé) ; il ne reste ici que
        // la mise en mots — c'est le partage que suit tout le dossier.
        $suffisante = $rapport->reductionSuffisante(self::REDUCTIONS);

        if ($suffisante === 0.0) {
            return 'Lecture : aucun tour ne dépasse le plafond sur la période. Le contexte n\'est pas'
                . ' ce qui sature aujourd\'hui — alléger le bloc invariant ferait baisser la facture,'
                . ' pas les refus.';
        }

        if ($suffisante !== null) {
            return sprintf(
                'Lecture : un allègement de %d %% du bloc invariant ramène les dépassements de %d à ZÉRO'
                . ' (%d messages touchés aujourd\'hui). Le dégraissage vaut son risque de régression.',
                (int) round(100 * $suffisante),
                $observe['depassements'],
                $observe['messagesEnDepassement'],
            );
        }

        $pire = $rapport->picParMinute((float) max(self::REDUCTIONS));

        return sprintf(
            'Lecture : même à −%d %%, %d tours dépassent encore (contre %d aujourd\'hui). C\'est la'
            . ' SIMULTANÉITÉ qui sature, pas la taille du contexte : seul un plafond plus haut —'
            . ' ou un étalement des messages — y changera quelque chose.',
            (int) round(100 * max(self::REDUCTIONS)),
            $pire['depassements'],
            $observe['depassements'],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lire(array $fichiers, ?int $depuis, ?int $jusqua): array
    {
        $lignes = [];
        foreach ($fichiers as $fichier) {
            $handle = @fopen($fichier, 'r');
            if ($handle === false) {
                continue;
            }
            while (($ligne = fgets($handle)) !== false) {
                $enregistrement = json_decode(trim($ligne), true);
                // Le formateur JSON de Monolog range nos champs sous « context ».
                $contexte = \is_array($enregistrement) ? ($enregistrement['context'] ?? null) : null;
                if (!\is_array($contexte) || !isset($contexte['evenement'])) {
                    continue;
                }
                $instant = isset($contexte['horodatage'])
                    ? strtotime((string) $contexte['horodatage'])
                    : false;
                if ($instant !== false) {
                    if ($depuis !== null && $instant < $depuis) {
                        continue;
                    }
                    if ($jusqua !== null && $instant > $jusqua) {
                        continue;
                    }
                }
                $lignes[] = $contexte;
            }
            fclose($handle);
        }

        return $lignes;
    }

    /** @return int|null|false false = valeur invalide (erreur déjà affichée) */
    private function borne(mixed $valeur, SymfonyStyle $io): int|null|false
    {
        if ($valeur === null) {
            return null;
        }
        $instant = strtotime((string) $valeur);
        if ($instant === false) {
            $io->error(sprintf('Date incompréhensible : « %s ».', $valeur));

            return false;
        }

        return $instant;
    }

    private function nombre(?float $valeur): string
    {
        return $valeur === null ? '—' : number_format($valeur, $valeur < 100 ? 1 : 0, ',', ' ');
    }
}
