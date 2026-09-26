<?php

namespace App\Command;

use App\Ai\AiContextBuilder;
use App\Ai\Engine\AiEngineInterface;
use App\Ai\Reglage\PoidsDesDeclarations;
use App\Ai\Scope\AiScope;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Tool\RattrapageDeNom;
use App\Ai\Traitement\IdentiteDuTraitement;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Tests\Ai\Corpus\CasDuCorpus;
use App\Tests\Ai\Corpus\CorpusDeReference;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * KET CHOISIT-ELLE LE BON OUTIL, ET QUE COÛTE-T-IL DE LE LUI DEMANDER ?
 *
 * ── DEUX MODES, ET DEUX COÛTS ───────────────────────────────────────────────────
 *
 * SANS OPTION — audit HORS LIGNE. Zéro token, zéro appel réseau : le poids de chaque
 * déclaration, les distances entre noms d'outils déclarés dans une même trousse, les
 * recouvrements de portée, et la composition du corpus. Tourne partout, y compris en
 * intégration continue.
 *
 * AVEC `--reel` — REJEU CONTRE LE VRAI MOTEUR. C'est la seule mesure honnête de
 * « bon outil au premier tour », et elle consomme du quota : chaque cas est un
 * message complet. À lancer HORS DES HEURES DE TRAVAIL, à débit limité (cf. `--pause`),
 * et de préférence sur un modèle distinct de celui qui sert les courtiers — depuis
 * `7efe037f`, chaque phase peut avoir le sien.
 *
 * ── POURQUOI PAS LE MOTEUR SIMULÉ ───────────────────────────────────────────────
 *
 * `SimulatedAiEngine` ne choisit pas comme un modèle : il prend le PREMIER outil dont
 * le `match()` lexical répond, dans l'ordre d'itération du conteneur. Et quinze outils
 * sur cinquante-deux — tous ceux d'écriture — ont un `match()` qui rend toujours null :
 * ils sont structurellement hors de sa portée. Il ignore par ailleurs la trousse, les
 * réglages de console et l'exécuteur. Il mesurerait donc autre chose que ce qu'on veut
 * savoir, et il le mesurerait sur une moitié du catalogue.
 *
 *   php bin/console app:ket:justesse
 *   php bin/console app:ket:justesse --reel 5
 *   php bin/console app:ket:justesse --reel 5 --cas=classement --pause=6
 */
#[AsCommand(
    name: 'app:ket:justesse',
    description: 'Mesure le choix d\'outil de Ket sur le corpus de référence (hors ligne par défaut).',
)]
// ⚠ DEV ET TEST SEULEMENT, ET C'EST STRUCTUREL. Le corpus de référence vit sous
// `tests/`, donc dans `autoload-dev` : en production, installée avec
// `composer install --no-dev`, sa classe n'existe pas. Sans ces attributs, le
// conteneur de production enregistrerait une commande qui casserait à la première
// invocation — et `cache:warmup` pourrait la résoudre dès le déploiement.
//
// Le corpus RESTE sous `tests/` : c'est un jeu de mesure anonymisé, il n'a rien à
// faire dans le code livré au courtier, et son garde-fou d'anonymat (CorpusFigeTest)
// doit vivre à côté de lui.
#[When(env: 'dev')]
#[When(env: 'test')]
class KetJustesseCommand extends Command
{
    public function __construct(
        private readonly TrousseCatalogue $catalogue,
        private readonly EntrepriseRepository $entrepriseRepository,
        private readonly InviteRepository $inviteRepository,
        private readonly AiContextBuilder $contextBuilder,
        private readonly AiEngineInterface $moteur,
        private readonly JournalTokens $journal,
        // Même raison que dans app:assistant:smoke : sans jeton d'identité, les
        // parcours d'écriture cassent en ligne de commande et seule la lecture
        // serait mesurable — c'est-à-dire la moitié du corpus.
        private readonly IdentiteDuTraitement $identite,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('idEntreprise', InputArgument::OPTIONAL, 'Entreprise du rejeu réel (requis avec --reel)')
            ->addOption('reel', null, InputOption::VALUE_NONE, 'Rejoue le corpus contre le VRAI moteur. Consomme du quota.')
            ->addOption('cas', null, InputOption::VALUE_REQUIRED, 'Ne rejouer que les cas dont le libellé ou la famille contient ce motif')
            // LE DÉBIT, ET POURQUOI IL EST RÉGLABLE. Le quota du palier gratuit se
            // compte par MINUTE : un rejeu lancé à pleine vitesse sature la fenêtre
            // et mesure alors des refus plutôt que des choix d'outil.
            ->addOption('pause', null, InputOption::VALUE_REQUIRED, 'Secondes entre deux cas, pour ne pas saturer la fenêtre de quota', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cas = $this->casRetenus((string) ($input->getOption('cas') ?? ''));

        if ($cas === []) {
            $io->error('Aucun cas du corpus ne correspond à ce motif.');

            return Command::FAILURE;
        }

        if (!$input->getOption('reel')) {
            return $this->horsLigne($io, $cas);
        }

        return $this->rejeuReel($io, $input, $cas);
    }

    // ── MODE HORS LIGNE ─────────────────────────────────────────────────────────

    /**
     * @param list<CasDuCorpus> $cas
     */
    private function horsLigne(SymfonyStyle $io, array $cas): int
    {
        $io->title(sprintf('Audit hors ligne — %d cas du corpus, aucun appel au fournisseur', \count($cas)));

        $this->sectionComposition($io, $cas);
        $this->sectionDistances($io);
        $this->sectionPoids($io);

        $io->success('Aucun token consommé : tout est calculé localement.');

        return Command::SUCCESS;
    }

    /** @param list<CasDuCorpus> $cas */
    private function sectionComposition(SymfonyStyle $io, array $cas): void
    {
        $io->section('1. Composition du corpus');

        $parFamille = [];
        $sansOutil = 0;
        $multi = 0;
        $parTrousse = [];
        foreach ($cas as $c) {
            $parFamille[$c->famille] = ($parFamille[$c->famille] ?? 0) + 1;
            $parTrousse[$c->trousse->libelle()] = ($parTrousse[$c->trousse->libelle()] ?? 0) + 1;
            if ($c->outils === []) {
                ++$sansOutil;
            }
            if (\count($c->outils) > 1) {
                ++$multi;
            }
        }
        arsort($parFamille);

        $io->table(
            ['Famille', 'Cas', 'Part'],
            array_map(
                static fn (string $f, int $n): array => [$f, $n, number_format(100 * $n / \count($cas), 1, ',', ' ') . ' %'],
                array_keys($parFamille),
                array_values($parFamille),
            ),
        );
        $io->writeln(sprintf(
            ' Trousse attendue : %s. Cas sans aucun outil : %d. Cas multi-outils : %d.',
            implode(', ', array_map(static fn (string $t, int $n): string => "$t $n", array_keys($parTrousse), array_values($parTrousse))),
            $sansOutil,
            $multi,
        ));
    }

    /**
     * LES DISTANCES ENTRE NOMS — la contrainte que le rattrapage impose au nommage.
     *
     * `RattrapageDeNom` refuse de trancher quand deux outils déclarés au même tour
     * sont à égale distance du nom écorché. Deux noms trop proches DANS UNE MÊME
     * TROUSSE désarment donc le filet pour toute leur famille, en silence.
     *
     * Mesuré au 2026-09-25 : une seule paire y est, `echange_exporter` et
     * `echange_importer` (distance 2), et elle franchit la frontière lecture/écriture —
     * si bien qu'en trousse d'écriture, où les deux sont déclarés, aucune écorchure de
     * cette famille n'est rattrapable.
     */
    private function sectionDistances(SymfonyStyle $io): void
    {
        $io->section('2. Distances entre noms d\'outils — le rattrapage peut-il encore trancher ?');

        $rangees = [];
        foreach ([Trousse::LECTURE, Trousse::ECRITURE] as $trousse) {
            $noms = array_map(
                static fn ($o): string => $o->name(),
                array_filter(
                    $this->catalogue->tous(),
                    fn ($o): bool => $trousse->estEcriture() || !$this->catalogue->estOutilDEcriture($o->name()),
                ),
            );
            $noms = array_values($noms);

            for ($i = 0; $i < \count($noms); ++$i) {
                for ($j = $i + 1; $j < \count($noms); ++$j) {
                    $d = levenshtein($noms[$i], $noms[$j]);
                    if ($d > RattrapageDeNom::DISTANCE_MAX) {
                        continue;
                    }
                    $rangees[] = [$trousse->libelle(), $noms[$i], $noms[$j], $d];
                }
            }
        }

        if ($rangees === []) {
            $io->writeln(sprintf(
                ' Aucune paire à distance ≤ %d dans une même trousse : le rattrapage peut trancher partout.',
                RattrapageDeNom::DISTANCE_MAX,
            ));

            return;
        }

        $io->table(['Trousse', 'Outil', 'Outil', 'Distance'], $rangees);
        $io->warning(sprintf(
            '%d paire(s) trop proche(s) : sur ces familles, une écorchure reste introuvable, '
            . 'le rattrapage refusant de choisir entre deux candidats à égale distance.',
            \count($rangees),
        ));
    }

    /**
     * LE POIDS, PAR OUTIL — le second axe du chantier.
     *
     * Le détail complet vit dans app:assistant:tokens:composition --outils, qui sait
     * opposer un périmètre réel. Ici on ne donne que ce qui décide : les dix plus
     * lourds, et la part qu'ils portent.
     */
    private function sectionPoids(SymfonyStyle $io): void
    {
        $io->section('3. Poids des déclarations — où porte le dégraissage');

        $poids = [];
        foreach ($this->catalogue->tous() as $outil) {
            $poids[$outil->name()] = [
                'total'  => PoidsDesDeclarations::octetsDe($outil),
                'desc'   => \strlen($outil->description()),
                'lecture' => !$this->catalogue->estOutilDEcriture($outil->name()),
            ];
        }
        uasort($poids, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $totalLecture = array_sum(array_map(
            static fn (array $p): int => $p['lecture'] ? $p['total'] : 0,
            $poids,
        ));
        $totalTout = array_sum(array_column($poids, 'total'));

        $rangees = [];
        foreach (\array_slice($poids, 0, 10, true) as $nom => $p) {
            $rangees[] = [
                $nom,
                $p['lecture'] ? 'lecture' : 'écriture',
                number_format($p['total'], 0, ',', ' '),
                number_format($p['desc'], 0, ',', ' '),
                number_format(100 * $p['total'] / $totalTout, 1, ',', ' ') . ' %',
            ];
        }
        $io->table(['Outil', 'Trousse', 'Octets', 'dont description', 'Part du catalogue'], $rangees);

        $dixPremiers = array_sum(array_column(\array_slice($poids, 0, 10, true), 'total'));
        $io->writeln(sprintf(
            ' Catalogue : %s o (%d outils). Trousse lecture : %s o. Les dix plus lourds portent %s du total.',
            number_format($totalTout, 0, ',', ' '),
            \count($poids),
            number_format($totalLecture, 0, ',', ' '),
            number_format(100 * $dixPremiers / $totalTout, 1, ',', ' ') . ' %',
        ));
    }

    // ── MODE RÉEL ───────────────────────────────────────────────────────────────

    /**
     * @param list<CasDuCorpus> $cas
     */
    private function rejeuReel(SymfonyStyle $io, InputInterface $input, array $cas): int
    {
        $idEntreprise = $input->getArgument('idEntreprise');
        if ($idEntreprise === null) {
            $io->error('Le rejeu réel a besoin d\'une entreprise : app:ket:justesse --reel <idEntreprise>');

            return Command::FAILURE;
        }

        $entreprise = $this->entrepriseRepository->find((int) $idEntreprise);
        if ($entreprise === null) {
            $io->error('Entreprise introuvable.');

            return Command::FAILURE;
        }
        $invite = $this->inviteRepository->findOneBy(['entreprise' => $entreprise, 'proprietaire' => true])
            ?? $this->inviteRepository->findOneBy(['entreprise' => $entreprise]);
        if ($invite === null) {
            $io->error('Aucun invité rattaché à cette entreprise.');

            return Command::FAILURE;
        }

        $pause = max(0, (int) $input->getOption('pause'));
        $this->identite->endosser($invite, $entreprise);

        $io->title(sprintf('Rejeu réel — %d cas, moteur %s', \count($cas), $this->moteur->name()));
        $io->warning(sprintf(
            'CE REJEU CONSOMME DU QUOTA : %d messages complets, %d s de pause entre chacun. '
            . 'À lancer hors des heures de travail.',
            \count($cas),
            $pause,
        ));

        $resultats = [];
        foreach ($cas as $i => $c) {
            $io->write(sprintf("\r  %d/%d  %-48s", $i + 1, \count($cas), substr($c->libelle, 0, 48)));

            // Conversation TRANSIENTE, jamais persistée : le rejeu ne doit laisser
            // aucune trace dans le fil d'un courtier.
            $conversation = (new AssistantConversation())->setEntreprise($entreprise)->setInvite($invite);
            $conversation->addMessage(
                (new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu($c->question),
            );

            try {
                $reponse = $this->moteur->reply($this->contextBuilder->build($entreprise, $invite, $conversation));
                $obtenus = $this->outilsAppeles();
                $resultats[] = ['cas' => $c, 'obtenus' => $obtenus, 'erreur' => $reponse->refused ? 'refus périmètre' : null];
            } catch (\Throwable $e) {
                $resultats[] = ['cas' => $c, 'obtenus' => [], 'erreur' => $e->getMessage()];
            }

            if ($pause > 0 && $i < \count($cas) - 1) {
                sleep($pause);
            }
        }
        $io->newLine(2);

        return $this->imprimerLeBilan($io, $resultats);
    }

    /**
     * LES OUTILS RÉELLEMENT APPELÉS À CE MESSAGE, lus dans le récapitulatif du journal.
     *
     * On ne se contente pas de `reply->toolUsed` : il ne porte que le DERNIER outil.
     * Un message qui en appelle deux — le cas « donne la liste ET ouvre l'onglet » —
     * n'en montrerait qu'un, et la mesure conclurait à une erreur là où le modèle a
     * eu parfaitement raison.
     *
     * @return list<string>
     */
    private function outilsAppeles(): array
    {
        $recap = $this->journal->recapitulatif();
        $outils = [];
        foreach ($recap['etapes'] ?? [] as $etape) {
            foreach ((array) ($etape['outils'] ?? []) as $nom) {
                if ($nom !== '' && !\in_array($nom, $outils, true)) {
                    $outils[] = (string) $nom;
                }
            }
        }

        return $outils;
    }

    /**
     * L'INDICATEUR PRINCIPAL DU CHANTIER : bon outil au premier tour, sans rattrapage.
     *
     * « Bon » veut dire : l'ensemble des outils appelés contient TOUS ceux attendus, et
     * aucun outil inexistant n'a été prononcé. On ne pénalise pas un outil appelé EN
     * PLUS — un modèle qui vérifie une fiche avant de lister n'a rien fait de mal — mais
     * on compte ces cas à part, parce qu'ils coûtent des tours.
     *
     * @param list<array{cas: CasDuCorpus, obtenus: list<string>, erreur: ?string}> $resultats
     */
    private function imprimerLeBilan(SymfonyStyle $io, array $resultats): int
    {
        $catalogue = array_map(static fn ($o): string => $o->name(), $this->catalogue->tous());

        $justes = 0;
        $justesAvecSupplement = 0;
        $manques = 0;
        $inventes = 0;
        $erreurs = 0;
        $ecarts = [];

        foreach ($resultats as $r) {
            $attendus = $r['cas']->outils;
            $obtenus = $r['obtenus'];

            if ($r['erreur'] !== null) {
                ++$erreurs;
                $ecarts[] = [$r['cas']->libelle, implode(', ', $attendus) ?: '(aucun)', 'ERREUR : ' . $r['erreur']];
                continue;
            }

            $fantomes = array_values(array_diff($obtenus, $catalogue));
            if ($fantomes !== []) {
                ++$inventes;
            }

            $absents = array_values(array_diff($attendus, $obtenus));
            $supplement = array_values(array_diff($obtenus, $attendus, $fantomes));

            if ($absents === [] && $fantomes === []) {
                if ($supplement === []) {
                    ++$justes;
                    continue;
                }
                ++$justesAvecSupplement;
            } else {
                ++$manques;
            }

            $ecarts[] = [
                $r['cas']->libelle,
                implode(', ', $attendus) ?: '(aucun)',
                implode(', ', $obtenus) ?: '(aucun)',
            ];
        }

        $total = \count($resultats);
        $io->section('Bilan');
        $io->table(
            ['Indicateur', 'Cas', 'Part'],
            [
                ['Bon outil au premier tour, exactement', $justes, self::part($justes, $total)],
                ['Bon outil, mais un outil de plus appelé', $justesAvecSupplement, self::part($justesAvecSupplement, $total)],
                ['Outil attendu absent', $manques, self::part($manques, $total)],
                ['Nom d\'outil inexistant prononcé', $inventes, self::part($inventes, $total)],
                ['Erreur technique (non imputable au choix)', $erreurs, self::part($erreurs, $total)],
            ],
        );

        $io->writeln(sprintf(
            ' <info>INDICATEUR PRINCIPAL — bon outil au premier tour, sans rattrapage : %s</info>',
            self::part($justes, $total),
        ));
        $io->writeln(' Les appels rattrapés et les noms inventés se relisent aussi dans la section 8 de app:assistant:tokens:rapport.');

        if ($ecarts !== []) {
            $io->section(sprintf('Les %d écart(s), cas par cas', \count($ecarts)));
            $io->table(['Cas', 'Attendu', 'Obtenu'], $ecarts);
        }

        return Command::SUCCESS;
    }

    // ── Échafaudage ─────────────────────────────────────────────────────────────

    /** @return list<CasDuCorpus> */
    private function casRetenus(string $motif): array
    {
        $tous = CorpusDeReference::tous();
        if ($motif === '') {
            return $tous;
        }

        return array_values(array_filter(
            $tous,
            static fn (CasDuCorpus $c): bool => str_contains($c->libelle, $motif) || $c->famille === $motif,
        ));
    }

    private static function part(int $n, int $total): string
    {
        return $total > 0 ? number_format(100 * $n / $total, 1, ',', ' ') . ' %' : '—';
    }
}
