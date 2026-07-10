<?php

namespace App\Command;

use App\Ai\AiContextBuilder;
use App\Ai\Engine\AiEngineInterface;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('idEntreprise', InputArgument::REQUIRED, "Identifiant de l'entreprise")
            ->addArgument('question', InputArgument::OPTIONAL, 'Question à poser', 'Combien de clients avons-nous ?');
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
}
