<?php

namespace App\Command;

use App\Ai\AiContextBuilder;
use App\Ai\Engine\AiEngineInterface;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolInterface;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Test de fumée de l'assistant IA en ligne de commande : envoie une question
 * au moteur actif (Claude si ANTHROPIC_API_KEY est renseignée, simulateur
 * sinon) dans le contexte du PROPRIÉTAIRE d'une entreprise, sans rien
 * persister ni métrer. Sert à valider la clé API avant le test navigateur.
 *
 *   php bin/console app:assistant:smoke 42 "Combien de clients avons-nous ?"
 */
#[AsCommand(name: 'app:assistant:smoke', description: "Teste le moteur de l'assistant IA (rien n'est persisté ni métré).")]
class AssistantSmokeCommand extends Command
{
    public function __construct(
        private readonly EntrepriseRepository $entrepriseRepository,
        private readonly InviteRepository $inviteRepository,
        private readonly AiContextBuilder $contextBuilder,
        private readonly AiEngineInterface $aiEngine,
        // Il n'y a pas de session en ligne de commande. Or les outils d'ÉCRITURE
        // passent par les formulaires de l'application, dont les filtres de
        // relations lisent l'utilisateur connecté et son espace de travail
        // (FormListenerFactory::setFiltreEntreprise). Sans jeton, toute
        // préparation de plan casse sur « getConnectedTo() on null » et le test de
        // fumée ne peut vérifier que les outils de lecture.
        private readonly TokenStorageInterface $tokenStorage,
        /** @var iterable<AiToolInterface> */
        #[AutowireIterator('app.ai_tool')] private readonly iterable $outils = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('idEntreprise', InputArgument::REQUIRED, "Identifiant de l'entreprise")
            ->addArgument('question', InputArgument::OPTIONAL, 'Question à poser', 'Combien de clients avons-nous ?')
            ->addOption('outil', null, InputOption::VALUE_REQUIRED, "Exécute UN outil directement (sans le moteur, donc sans consommer de tokens) : son nom technique, ex. saisir_proposition")
            ->addOption('args', null, InputOption::VALUE_REQUIRED, "Arguments JSON de l'outil, ex. '{\"assureur\":\"SFA\"}'", '{}');
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

        // Jeton de sécurité ÉPHÉMÈRE (mémoire du processus, jamais persisté) : il
        // place l'utilisateur de l'invité dans l'espace de travail visé, exactement
        // comme le ferait une session web. C'est ce qui rend les parcours d'écriture
        // testables ici — ils restent en dry-run, rien n'est écrit ni flushé.
        if (($utilisateur = $invite->getUtilisateur()) !== null) {
            $utilisateur->setConnectedTo($entreprise);
            $this->tokenStorage->setToken(new UsernamePasswordToken($utilisateur, 'main', $utilisateur->getRoles()));
        }

        // Invocation DIRECTE d'un outil : ce que le moteur aurait obtenu, mot pour
        // mot, sans passer par lui. Indispensable pour diagnostiquer — la prose du
        // modèle paraphrase la sortie de l'outil et peut en déplacer le sens (« il
        // manque une date » sans dire laquelle, ni sur quelle entité).
        if (($nomOutil = $input->getOption('outil')) !== null) {
            return $this->executerOutil($io, (string) $nomOutil, (string) $input->getOption('args'), $entreprise, $invite);
        }

        $question = (string) $input->getArgument('question');

        // Conversation TRANSIENTE : jamais persistée, aucun métrage.
        $conversation = (new AssistantConversation())
            ->setEntreprise($entreprise)
            ->setInvite($invite);
        $conversation->addMessage(
            (new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu($question)
        );

        $io->section(sprintf('Moteur actif : %s', $this->aiEngine->name()));
        $io->text(sprintf('Entreprise : %s · Invité : %s', $entreprise->getNom(), $invite->getNom()));
        $io->text(sprintf('Question : %s', $question));

        $debut = microtime(true);
        $reply = $this->aiEngine->reply($this->contextBuilder->build($entreprise, $invite, $conversation));
        $duree = (int) round((microtime(true) - $debut) * 1000);

        $io->section(sprintf('Réponse (%d ms%s%s)', $duree, $reply->toolUsed ? ', outil : ' . $reply->toolUsed : '', $reply->refused ? ', REFUS périmètre' : ''));
        $io->text($reply->content);
        $io->success('Le moteur a répondu.');

        return Command::SUCCESS;
    }

    /**
     * Exécute un seul outil et affiche sa réponse BRUTE. Les outils de données ne
     * font que lire ; ceux d'écriture sont en dry-run (ils préparent un plan, ils
     * n'écrivent pas). Aucun token n'est consommé : le moteur n'est pas sollicité.
     */
    private function executerOutil(
        SymfonyStyle $io,
        string $nom,
        string $argsJson,
        Entreprise $entreprise,
        Invite $invite,
    ): int {
        $args = json_decode($argsJson, true);
        if (!is_array($args)) {
            $io->error(sprintf('Arguments JSON invalides : %s', json_last_error_msg()));

            return Command::FAILURE;
        }

        foreach ($this->outils as $outil) {
            if ($outil->name() !== $nom) {
                continue;
            }

            $io->section(sprintf('Outil : %s (dry-run, aucun token)', $nom));
            $resultat = $outil->execute($args, new AiScope($entreprise, $invite));

            $io->text(sprintf('statut : %s', $resultat->status));
            $io->text('data :');
            $io->writeln((string) json_encode($resultat->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            if ($resultat->uiAction !== null) {
                $io->text('uiAction :');
                $io->writeln((string) json_encode($resultat->uiAction, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }

            return Command::SUCCESS;
        }

        $noms = [];
        foreach ($this->outils as $outil) {
            $noms[] = $outil->name();
        }
        sort($noms);
        $io->error(sprintf('Outil « %s » inconnu. Disponibles : %s', $nom, implode(', ', $noms)));

        return Command::FAILURE;
    }
}
