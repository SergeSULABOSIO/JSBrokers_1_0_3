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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        /**
         * CE QUI PERMET À CETTE COMMANDE DE TENIR CENT QUATRE-VINGT-QUINZE CAS.
         *
         * Le même service que celui dont se sert un worker Messenger entre deux messages.
         * Sans lui, la passe complète mourait à 164 cas sur 195, mémoire épuisée : chaque
         * cas laissait derrière lui son unité de travail Doctrine, ses enregistrements de
         * journal et l'état des services à durée de requête. Un processus court ne le voit
         * jamais ; celui-ci vit une heure.
         */
        // Le service n'est pas aliasé sur sa classe : on le désigne par son identifiant,
        // comme le fait le worker Messenger qui s'en sert pour la même raison.
        #[Autowire(service: 'services_resetter')] private readonly ServicesResetter $resetter,
        private readonly EntityManagerInterface $em,
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
     * Mesuré au 2026-09-25 : une seule paire y était, `echange_exporter` et
     * `echange_importer` (distance 2), et elle franchissait la frontière lecture/écriture —
     * si bien qu'en trousse d'écriture, où les deux étaient déclarés, aucune écorchure de
     * cette famille n'était rattrapable. Leur renommage du 2026-09-26 a porté la distance
     * à 12, et AliasDOutilTest interdit désormais toute nouvelle paire.
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
                // DEMANDÉ AU CATALOGUE. « Pas un outil d'écriture » n'a jamais voulu dire
                // « déclaré en lecture » : depuis la trousse minimale, la porte de sortie
                // n'est ni l'un ni l'autre, et cette commande annonçait 381 octets de trop.
                'lecture' => \in_array(Trousse::LECTURE, $this->catalogue->troussesDe($outil), true),
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
            $io->write(sprintf(
                "\r  %d/%d  %-44s %4d Mo",
                $i + 1,
                \count($cas),
                substr($c->libelle, 0, 44),
                (int) (memory_get_usage(true) / 1048576),
            ));

            // Conversation TRANSIENTE, jamais persistée : le rejeu ne doit laisser
            // aucune trace dans le fil d'un courtier.
            $conversation = $this->filDuCas($c, $entreprise, $invite);

            try {
                $reponse = $this->moteur->reply($this->contextBuilder->build($entreprise, $invite, $conversation));
                $resultats[] = [
                    'cas'     => $c,
                    'obtenus' => $this->outilsAppeles(),
                    'clarifie' => $this->aDemandeUneClarification(),
                    'erreur'  => $reponse->refused ? 'refus périmètre' : null,
                ];
            } catch (\Throwable $e) {
                $resultats[] = ['cas' => $c, 'obtenus' => [], 'clarifie' => false, 'erreur' => $e->getMessage()];
            }

            // ── ON REPART PROPRE, CAS PAR CAS ─────────────────────────────────────
            //
            // L'ordre compte. Le resetter vide les services à durée de requête — dont le
            // journal, qui accumule ses étapes —, `clear()` lâche les entités hydratées
            // par les outils, et le ramasse-miettes conclut : sans lui, les cycles de
            // références entre entités Doctrine survivent au clear.
            //
            // L'IDENTITÉ EST RÉENDOSSÉE APRÈS, et pas avant : le reset la jette avec le
            // reste, et sans elle les cas d'écriture cassent sur un utilisateur absent.
            $this->resetter->reset();
            $this->em->clear();
            gc_collect_cycles();
            $this->identite->endosser($invite, $entreprise);

            if ($pause > 0 && $i < \count($cas) - 1) {
                sleep($pause);
            }
        }
        $io->newLine(2);

        return $this->imprimerLeBilan($io, $resultats);
    }

    /**
     * LE FIL DANS LEQUEL LA QUESTION EST POSÉE — et non la question toute seule.
     *
     * ── LE BIAIS QUE CETTE MÉTHODE CORRIGE ──────────────────────────────────────
     *
     * Le rejeu montait une conversation ne contenant que la question. Or une part du
     * corpus n'a de sens que par ce qui précède : « Dans cela, affiche uniquement celles
     * dont les primes sont échues » ne désigne RIEN dans un fil vide. Ket demandait une
     * clarification — et elle avait raison —, mais la mesure comptait un mauvais choix
     * d'outil. On mesurait la patience du modèle, pas son jugement.
     *
     * Deux choses sont donc restituées, et elles n'ont pas le même rôle :
     *
     *  · l'ANTÉCÉDENT donne un référent aux pronoms (« dans cela », « celles-ci ») ;
     *  · le CONTEXTE reproduit le signal structurel que le sélecteur de trousse lit —
     *    un plan en attente, une offre d'écrire, un tour qui vient d'écrire. Sans lui,
     *    un « ok » de validation partirait en lecture et ne mesurerait rien de réel.
     *
     * Ce qui n'est PAS reproduit, faute de pouvoir l'être sans base : le programme en
     * cours et la pièce jointe, qui exigent des enregistrements persistés. Les cas qui
     * les déclarent sont rejoués sans eux, et c'est une limite connue du harnais — pas
     * un résultat.
     */
    private function filDuCas(CasDuCorpus $cas, $entreprise, $invite): AssistantConversation
    {
        $conversation = (new AssistantConversation())->setEntreprise($entreprise)->setInvite($invite);

        // L'antécédent d'abord : il doit précéder la question dans le fil, sans quoi le
        // pronom n'aurait toujours rien à désigner.
        $texte = $cas->antecedent !== '' ? $cas->antecedent : $this->antecedentDuContexte($cas);
        if ($texte !== null) {
            $reponse = (new AssistantMessage())
                ->setRole(AssistantMessage::ROLE_ASSISTANT)
                ->setContenu($texte);
            // La META porte le signal structurel : c'est elle, et non le texte, que le
            // sélecteur de trousse lit pour « le dernier tour a écrit » et « un plan
            // attend une décision ».
            $meta = $this->metaDuContexte($cas);
            if ($meta !== []) {
                $reponse->setMeta($meta);
            }
            $conversation->addMessage($reponse);
        }

        $conversation->addMessage(
            (new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu($cas->question),
        );

        return $conversation;
    }

    /** La phrase que Ket aurait dite, quand le cas déclare un contexte sans antécédent écrit. */
    private function antecedentDuContexte(CasDuCorpus $cas): ?string
    {
        return match ($cas->contexte) {
            // La tournure d'offre est celle que SelecteurDeTrousse reconnaît : reproduire
            // le signal exige d'employer ses mots, pas des mots équivalents.
            CasDuCorpus::CONTEXTE_A_PROPOSE_D_ECRIRE => 'Voulez-vous que je l\'enregistre ?',
            CasDuCorpus::CONTEXTE_DERNIER_TOUR_A_ECRIT => 'C\'est enregistré.',
            CasDuCorpus::CONTEXTE_PLAN_EN_ATTENTE => 'Voici le plan, à valider.',
            CasDuCorpus::CONTEXTE_PROGRAMME_EN_COURS => 'Étape 1 sur 3 terminée.',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function metaDuContexte(CasDuCorpus $cas): array
    {
        return match ($cas->contexte) {
            // Un outil d'écriture réel : le sélecteur lit l'appartenance dans le
            // catalogue, un nom inventé ne déclencherait rien.
            CasDuCorpus::CONTEXTE_DERNIER_TOUR_A_ECRIT => ['tool' => 'preparer_operations'],
            CasDuCorpus::CONTEXTE_PLAN_EN_ATTENTE => ['mutationPlan' => ['operations' => []]],
            default => [],
        };
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
     * LA QUESTION NOMME-T-ELLE UNE ENTITÉ QUI N'EXISTE PAS DANS LA BASE ?
     *
     * Les pseudonymes de l'anonymisation ne désignent aucun enregistrement réel. Une
     * clarification sur l'un d'eux mesure l'anonymisation, pas le jugement de Ket : la
     * compréhension a cherché, n'a rien trouvé, et a eu raison de demander.
     *
     * La liste vient de CorpusDeReference::PSEUDONYMES — celle-là même qui sert à relire
     * l'anonymisation. Une seule table, deux usages, et pas de risque qu'elles divergent.
     */
    private static function nommeUnPseudonyme(string $question): bool
    {
        static $noms = null;
        if ($noms === null) {
            $noms = [];
            foreach (CorpusDeReference::PSEUDONYMES as $famille => $liste) {
                // Les personnes et les identifiants sont écartés : « Mme Perrin » ou
                // « ASRVOY00000001 » ne se résolvent pas contre une table d'entités, et
                // une clarification qui les vise dit autre chose.
                if (!\in_array($famille, ['clients', 'assureurs', 'intermediaires', 'fournisseurs'], true)) {
                    continue;
                }
                foreach (explode(',', $liste) as $nom) {
                    $nom = trim($nom);
                    if (mb_strlen($nom) >= 5) {
                        $noms[] = mb_strtolower($nom);
                    }
                }
            }
        }

        $question = mb_strtolower($question);
        foreach ($noms as $nom) {
            if (str_contains($question, $nom)) {
                return true;
            }
        }

        return false;
    }

    /**
     * LA COMPRÉHENSION A-T-ELLE REFUSÉ DE TRAITER LA DEMANDE ?
     *
     * ⚠ CE N'EST PAS UNE ERREUR DE CHOIX D'OUTIL, ET LES CONFONDRE FAUSSE TOUT.
     *
     * Quand la phase 0 juge une demande peu claire, le message s'arrête là : aucun outil
     * n'est déclaré, aucun n'est appelé, et la planification n'a jamais lieu. Compter ces
     * cas comme « outil attendu absent » reviendrait à imputer au nommage et aux
     * descriptions un défaut qu'ils ne peuvent pas corriger — et à voir l'indicateur
     * monter ou descendre au gré d'une phase que ce chantier ne touche pas.
     *
     * Mesuré le 2026-09-26 : 10 cas sur 38 (26 %) se terminent ainsi, sur des questions
     * telles que « Donne-moi les rétrocommissions des agents » ou « La réserve est de
     * combien ? ». C'est le défaut dominant du corpus, et il mérite son propre chantier.
     *
     * La marque est l'ABSENCE d'étape de planification dans le récapitulatif : on la lit
     * là plutôt que sur un drapeau de la réponse, parce que c'est le journal qui fait foi
     * sur ce qui s'est réellement passé.
     */
    private function aDemandeUneClarification(): bool
    {
        foreach ($this->journal->recapitulatif()['etapes'] ?? [] as $etape) {
            if (($etape['cle'] ?? '') === 'planification') {
                return false;
            }
        }

        return true;
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
        $clarifies = 0;
        $pseudonymes = 0;
        $ecarts = [];

        foreach ($resultats as $r) {
            $attendus = $r['cas']->outils;
            $obtenus = $r['obtenus'];

            if ($r['erreur'] !== null) {
                ++$erreurs;
                $ecarts[] = [$r['cas']->libelle, implode(', ', $attendus) ?: '(aucun)', 'ERREUR : ' . $r['erreur']];
                continue;
            }

            // ⚠ MIS À PART, ET C'EST LE POINT. Une demande jugée peu claire n'atteint
            // jamais la planification : aucun outil ne lui est déclaré, aucun ne peut
            // donc être choisi. La compter comme un mauvais choix imputerait au nommage
            // un défaut qui vient d'ailleurs, et ferait bouger l'indicateur au gré d'une
            // phase que ce chantier ne touche pas.
            if (($r['clarifie'] ?? false) && $attendus !== []) {
                // ⚠ DEUX CLARIFICATIONS TRÈS DIFFÉRENTES, ET LES CONFONDRE FAUSSE TOUT.
                // Celle qui porte sur un pseudonyme absent de la base mesure mon
                // anonymisation ; l'autre mesure le jugement de Ket. Une seule des deux
                // relève d'un chantier.
                if (self::nommeUnPseudonyme($r['cas']->question)) {
                    ++$pseudonymes;
                    $ecarts[] = [
                        $r['cas']->libelle,
                        implode(', ', $attendus) ?: '(aucun)',
                        'NOM ABSENT DE LA BASE — artefact d\'anonymisation, pas un défaut',
                    ];
                    continue;
                }
                ++$clarifies;
                $ecarts[] = [
                    $r['cas']->libelle,
                    implode(', ', $attendus) ?: '(aucun)',
                    'CLARIFICATION demandée — la question n\'a pas atteint les outils',
                ];
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
        // LE DÉNOMINATEUR DE LA JUSTESSE N'EST PAS LE CORPUS ENTIER : c'est le nombre de
        // cas où Ket a EU des outils à choisir. Les clarifications et les erreurs
        // techniques n'ont jamais atteint ce choix ; les inclure mesurerait autre chose.
        $aChoisi = $total - $clarifies - $erreurs - $pseudonymes;

        $io->section('Bilan');
        $io->table(
            ['Indicateur', 'Cas', 'Part du corpus'],
            [
                ['Bon outil au premier tour, exactement', $justes, self::part($justes, $total)],
                ['Bon outil, mais un outil de plus appelé', $justesAvecSupplement, self::part($justesAvecSupplement, $total)],
                ['Outil attendu absent', $manques, self::part($manques, $total)],
                ['Nom d\'outil inexistant prononcé', $inventes, self::part($inventes, $total)],
                ['— Clarification demandée (hors choix d\'outil)', $clarifies, self::part($clarifies, $total)],
                ['— Nom absent de la base (artefact du corpus)', $pseudonymes, self::part($pseudonymes, $total)],
                ['— Erreur technique (hors choix d\'outil)', $erreurs, self::part($erreurs, $total)],
            ],
        );

        [$bas, $haut] = self::intervalle($justes, $aChoisi);
        $io->writeln(sprintf(
            ' <info>INDICATEUR PRINCIPAL — bon outil quand Ket a eu à choisir : %s (%d cas sur %d)</info>',
            self::part($justes, $aChoisi),
            $justes,
            $aChoisi,
        ));
        // ⚠ L'INCERTITUDE EST IMPRIMÉE AVEC LE CHIFFRE, ET C'EST TOUT L'INTÉRÊT.
        //
        // Le 2026-09-27, la même famille a rendu 57,1 %, puis 38,9 %, puis 47,1 %, puis
        // 46,7 %. Ces écarts ont été lus comme des effets de changements de code — et un
        // lot a été annulé sur cette base. Sur quinze cas, UN SEUL vaut près de sept
        // points, et le modèle n'est pas déterministe : une partie de ces écarts était du
        // bruit. Un chiffre sans sa marge invite à conclure ce qu'il ne dit pas.
        $io->writeln(sprintf(
            ' Marge à 95 %% : de %s à %s. Deux passes dont les intervalles se CHEVAUCHENT ne',
            self::pourcent($bas),
            self::pourcent($haut),
        ));
        $io->writeln(' se départagent pas. Trancher un écart de DIX points en demanderait environ');
        $io->writeln(' QUATRE CENTS : une famille de vingt cas ne détecte qu\'un effondrement, pas');
        $io->writeln(' un progrès. Pour juger un lot, passer le corpus ENTIER — et même là, la');
        $io->writeln(' marge reste de l\'ordre de sept points.');
        $io->writeln(sprintf(
            ' Rapporté au corpus entier : %s. L\'écart entre les deux, ce sont les %d cas que la',
            self::part($justes, $total),
            $clarifies + $erreurs + $pseudonymes,
        ));
        $io->writeln(' COMPRÉHENSION a écartés avant tout choix d\'outil — ni le nommage ni les');
        $io->writeln(' descriptions ne les corrigeront, et les confondre ferait varier l\'indicateur');
        $io->writeln(' au gré d\'une phase que ce chantier ne touche pas.');
        $io->newLine();
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

    /**
     * INTERVALLE DE CONFIANCE À 95 %, par le score de Wilson.
     *
     * Wilson plutôt que l'approximation normale : sur de petits effectifs — et quinze
     * cas, c'est petit — l'approximation normale déborde de [0, 1] et annonce des marges
     * absurdes près des extrêmes. Wilson reste borné et garde sa couverture jusqu'à une
     * dizaine d'observations.
     *
     * @return array{0: float, 1: float}
     */
    private static function intervalle(int $succes, int $total): array
    {
        if ($total <= 0) {
            return [0.0, 0.0];
        }

        $z = 1.96;
        $p = $succes / $total;
        $denominateur = 1 + $z ** 2 / $total;
        $centre = ($p + $z ** 2 / (2 * $total)) / $denominateur;
        $demi = $z * sqrt($p * (1 - $p) / $total + $z ** 2 / (4 * $total ** 2)) / $denominateur;

        return [max(0.0, $centre - $demi), min(1.0, $centre + $demi)];
    }

    private static function pourcent(float $fraction): string
    {
        return number_format(100 * $fraction, 1, ',', ' ') . ' %';
    }

    private static function part(int $n, int $total): string
    {
        return $total > 0 ? number_format(100 * $n / $total, 1, ',', ' ') . ' %' : '—';
    }
}
