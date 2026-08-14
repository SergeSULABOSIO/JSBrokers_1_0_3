<?php

namespace App\Command;

use App\Ai\AiContextBuilder;
use App\Ai\Comprehension\Comprehenseur;
use App\Ai\Trousse\Phase;
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
 * Exécute la SEULE phase de compréhension sur un message, et imprime ce qu'elle a
 * compris — sans planification, sans rédaction, sans rien persister ni métrer.
 *
 *   php bin/console app:assistant:comprendre 1 "renouvelle la police de Kibali"
 *
 * POURQUOI CETTE COMMANDE EXISTE. La prose de Ket PARAPHRASE ce qu'elle a compris,
 * et en déplace le sens au passage : diagnostiquer une mécompréhension depuis le
 * chat, c'est lire une traduction de la traduction. Ici on voit la sortie brute —
 * clarté, intention, questions — telle que la planification va la recevoir.
 *
 * C'est aussi et surtout l'instrument de RÉGLAGE. Le seuil qui décide qu'une
 * demande est ambiguë ne se choisit pas à l'intuition : on rejoue des messages
 * réels et on regarde combien déclenchent une clarification. Au-delà d'environ
 * 15 %, Ket devient un questionneur compulsif — pire que le mal qu'on corrige.
 * Même protocole que le réglage des trousses, rejouées sur 58 messages.
 */
#[AsCommand(
    name: 'app:assistant:comprendre',
    description: "Exécute la phase de compréhension sur un message et imprime sa sortie brute (rien n'est persisté ni métré).",
)]
class AssistantComprendreCommand extends Command
{
    public function __construct(
        private readonly EntrepriseRepository $entrepriseRepository,
        private readonly InviteRepository $inviteRepository,
        private readonly AiContextBuilder $contextBuilder,
        private readonly Comprehenseur $comprehenseur,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('idEntreprise', InputArgument::REQUIRED, "Identifiant de l'entreprise")
            ->addArgument('message', InputArgument::REQUIRED, "Le message de l'utilisateur à comprendre");
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

        $message = (string) $input->getArgument('message');

        // Conversation TRANSIENTE : jamais persistée, donc aucun court-circuit issu
        // de l'état du fil ne s'applique — c'est bien le modèle qu'on mesure ici.
        $conversation = (new AssistantConversation())
            ->setEntreprise($entreprise)
            ->setInvite($invite);
        $conversation->addMessage(
            (new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu($message)
        );

        $requete = $this->contextBuilder->build($entreprise, $invite, $conversation);

        $io->text(sprintf(
            'Entreprise : %s · Invité : %s · Modèle : %s',
            $entreprise->getNom(),
            $invite->getNom(),
            $this->comprehenseur->modelName(),
        ));
        $io->text(sprintf('Message : %s', $message));
        // Le poids du prompt de cette phase est la moitié de l'affaire : s'il dérive
        // vers celui de la planification, le troisième appel cesse d'être bon marché.
        $io->text(sprintf(
            'Prompt de compréhension : %s octets',
            number_format(\strlen($this->contextBuilder->toSystemPrompt($requete, null, Phase::COMPREHENSION)), 0, ',', ' '),
        ));

        $debut = microtime(true);
        $comprise = $this->comprehenseur->comprendre($requete);
        $duree = (int) round((microtime(true) - $debut) * 1000);

        $io->section(sprintf('%s (%d ms, origine : %s)', $comprise->claire ? 'CLAIRE' : 'À CLARIFIER', $duree, $comprise->origine));
        $io->text('Intention : ' . $comprise->intention);
        if ($comprise->questions !== []) {
            $io->listing($comprise->questions);
        }

        if (!$comprise->claire) {
            $io->section('Ce que l’utilisateur verrait');
            $io->writeln($comprise->texteDeClarification());
        }

        return Command::SUCCESS;
    }
}
