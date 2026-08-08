<?php

namespace App\Command;

use App\Ai\AiContextBuilder;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Tool\AiToolConditionnel;
use App\Ai\Tool\AiToolInterface;
use App\Entity\AssistantMessage;
use App\Repository\AssistantConversationRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Services\VersionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Photographie ce que l'assistant envoie au fournisseur à CHAQUE tour de
 * function calling, bloc par bloc.
 *
 * POURQUOI. L'API generateContent est sans état : prompt système et
 * déclarations d'outils repartent en entier à chaque aller-retour, et c'est ce
 * volume — pas le nombre de messages — qui approche le plafond de tokens
 * d'entrée par minute. Savoir combien pèse chaque bloc, et surtout quelle part
 * en est INVARIANTE, dit où un dégraissage rapporterait et où il ne
 * rapporterait rien.
 *
 * Aucun appel réseau, aucun token consommé : tout est calculé localement.
 *
 *   php bin/console app:assistant:tokens:composition 1 28
 */
#[AsCommand(
    name: 'app:assistant:tokens:composition',
    description: "Décompose le payload envoyé au moteur IA (prompt système / outils / historique).",
)]
class AssistantTokensCompositionCommand extends Command
{
    /**
     * Ratio octets → tokens observé sur ce prompt (français, JSON, beaucoup de
     * ponctuation). Sert uniquement à donner un ordre de grandeur comparable au
     * plafond du fournisseur ; les chiffres exacts viennent du journal, où le
     * fournisseur donne lui-même le compte.
     */
    private const OCTETS_PAR_TOKEN = 3.7;

    /** @var iterable<AiToolInterface> */
    private iterable $tools;

    public function __construct(
        private readonly EntrepriseRepository $entrepriseRepository,
        private readonly InviteRepository $inviteRepository,
        private readonly AssistantConversationRepository $conversationRepository,
        private readonly AiContextBuilder $contextBuilder,
        private readonly VersionService $versionService,
        #[AutowireIterator('app.ai_tool')] iterable $tools,
        // Le plafond réellement opposé au moteur (BudgetDebit) : le « tours par
        // minute » ci-dessous doit décrire l'installation courante, pas le palier
        // gratuit une fois la facturation activée.
        #[Autowire(env: 'int:GEMINI_TPM_PLAFOND')] private readonly int $plafond = BudgetDebit::PLAFOND_DEFAUT_PAR_MINUTE,
    ) {
        $this->tools = $tools;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('idEntreprise', InputArgument::REQUIRED, "Identifiant de l'entreprise")
            ->addArgument('idConversation', InputArgument::OPTIONAL, 'Conversation réelle à mesurer (sinon conversation vide)')
            ->addOption('outils', null, InputOption::VALUE_NONE, 'Détaille la taille de chaque déclaration d\'outil');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entreprise = $this->entrepriseRepository->find((int) $input->getArgument('idEntreprise'));
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

        $idConversation = $input->getArgument('idConversation');
        $conversation = $idConversation !== null
            ? $this->conversationRepository->find((int) $idConversation)
            : null;
        if ($idConversation !== null && $conversation === null) {
            $io->error('Conversation introuvable.');

            return Command::FAILURE;
        }

        // Une conversation réelle se termine souvent sur une réponse de
        // l'assistant : on ajoute un message utilisateur TRANSIENT (jamais
        // persisté) pour mesurer le payload tel qu'il partirait vraiment.
        if ($conversation !== null) {
            $conversation->addMessage(
                (new AssistantMessage())
                    ->setRole(AssistantMessage::ROLE_USER)
                    ->setContenu('Mesure de composition (message transient, non enregistré).')
            );
        }

        $request = $conversation !== null
            ? $this->contextBuilder->build($entreprise, $invite, $conversation)
            : null;

        if ($request === null) {
            $io->warning('Sans conversation, seules les déclarations d\'outils sont mesurables (le prompt système dépend du fil).');
        }

        $declarations = [];
        $parOutil = [];
        $octetsEcartes = 0;
        $nbEcartes = 0;
        foreach ($this->tools as $tool) {
            $declaration = [
                'name'        => $tool->name(),
                'description' => $tool->description(),
                'parameters'  => $tool->schema(),
            ];
            // Même filtrage que les moteurs : un outil que l'invité ne peut pas
            // exécuter, ou sans objet dans ce fil, n'est pas déclaré — donc pas
            // payé à chaque tour (cf. AiToolConditionnel). Sans conversation, on
            // n'a pas de périmètre : tout est compté, comme avant.
            $ecarte = $request !== null
                && $tool instanceof AiToolConditionnel
                && !$tool->estDisponible($request->scope);

            if ($ecarte) {
                $octetsEcartes += $this->taille($declaration);
                ++$nbEcartes;
            } else {
                $declarations[] = $declaration;
            }

            $parOutil[$tool->name()] = [
                'total'  => $this->taille($declaration),
                'desc'   => \strlen($tool->description()),
                'schema' => $this->taille($tool->schema()),
                'ecarte' => $ecarte,
            ];
        }

        $octetsOutils = $this->taille($declarations);
        $octetsPrompt = $request !== null ? \strlen($this->contextBuilder->toSystemPrompt($request)) : 0;
        $octetsHistorique = $request !== null ? $this->taille($request->messages) : 0;
        $total = $octetsOutils + $octetsPrompt + $octetsHistorique;

        $io->title(sprintf(
            'Composition du payload — version applicative %s, %d outils déclarés',
            $this->versionService->getVersion(),
            \count($declarations),
        ));

        if ($nbEcartes > 0) {
            $io->writeln(sprintf(
                ' <info>%d outil(s) écarté(s)</info> pour ce périmètre et ce fil : %s économisés à CHAQUE tour'
                . ' (≈ %s tokens).',
                $nbEcartes,
                $this->lisible($octetsEcartes),
                number_format((int) round($octetsEcartes / self::OCTETS_PAR_TOKEN), 0, ',', ' '),
            ));
            $io->newLine();
        }

        $io->table(
            ['Bloc', 'Octets', 'Part', '≈ tokens', 'Renvoyé à chaque tour ?'],
            [
                $this->ligne('Prompt système', $octetsPrompt, $total, 'oui'),
                $this->ligne('Déclarations d\'outils', $octetsOutils, $total, 'oui — invariant'),
                $this->ligne('Historique', $octetsHistorique, $total, 'oui — grossit à chaque tour'),
                new \Symfony\Component\Console\Helper\TableSeparator(),
                $this->ligne('TOTAL par tour', $total, $total, ''),
            ],
        );

        $tokensParTour = (int) round($total / self::OCTETS_PAR_TOKEN);
        $io->writeln(sprintf(
            ' Un tour coûte donc ≈ <info>%s tokens d\'entrée</info>.',
            number_format($tokensParTour, 0, ',', ' '),
        ));
        if ($tokensParTour > 0) {
            $io->writeln(sprintf(
                ' Sur un plafond de %s tokens d\'entrée/minute : <comment>%.1f tours par minute</comment>,'
                . ' partagés entre TOUS les invités et toutes les conversations.',
                number_format($this->plafond, 0, ',', ' '),
                $this->plafond / $tokensParTour,
            ));
        }
        $io->newLine();

        // Les pièces jointes lisibles nativement partent en base64 : elles
        // pèsent lourd et ne sont visibles nulle part ailleurs.
        if ($request !== null && $request->piecesNatives !== []) {
            $io->section('Pièces jointes transmises nativement');
            foreach ($request->piecesNatives as $piece) {
                $io->writeln(sprintf(
                    ' %s — %s en base64',
                    $piece['mimeType'],
                    $this->lisible(\strlen($piece['donneesBase64'])),
                ));
            }
        }

        if ($input->getOption('outils')) {
            arsort($parOutil);
            $io->section('Déclarations d\'outils, de la plus lourde à la plus légère');
            $io->table(
                ['Outil', 'Total (o)', 'Description (o)', 'Schéma (o)', 'Déclaré ?'],
                array_map(
                    static fn (string $nom, array $t) => [
                        $nom,
                        $t['total'],
                        $t['desc'],
                        $t['schema'],
                        $t['ecarte'] ? 'écarté' : 'oui',
                    ],
                    array_keys($parOutil),
                    $parOutil,
                ),
            );
        }

        $io->success('Aucun appel au fournisseur n\'a été effectué : mesure purement locale.');

        return Command::SUCCESS;
    }

    /** @return array<int, string> */
    private function ligne(string $libelle, int $octets, int $total, string $note): array
    {
        return [
            $libelle,
            number_format($octets, 0, ',', ' '),
            $total > 0 ? sprintf('%.1f %%', 100 * $octets / $total) : '—',
            number_format((int) round($octets / self::OCTETS_PAR_TOKEN), 0, ',', ' '),
            $note,
        ];
    }

    private function taille(array $donnees): int
    {
        return \strlen((string) json_encode($donnees, JSON_UNESCAPED_UNICODE));
    }

    private function lisible(int $octets): string
    {
        return $octets >= 1024 * 1024
            ? sprintf('%.1f Mo', $octets / 1024 / 1024)
            : sprintf('%.1f Ko', $octets / 1024);
    }
}
