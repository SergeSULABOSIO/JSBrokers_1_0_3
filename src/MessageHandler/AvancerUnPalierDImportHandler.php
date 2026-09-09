<?php

namespace App\MessageHandler;

use App\Ai\Traitement\IdentiteDuTraitement;
use App\Echange\Service\AvanceurDImport;
use App\Entity\EchangeImportRun;
use App\Message\AvancerUnPalierDImport;
use App\Repository\EchangeImportRunRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * FAIT AVANCER UN IMPORT D'UN PALIER, hors de toute requête.
 *
 * ── POURQUOI IL SE RÉVEILLE LUI-MÊME ────────────────────────────────────────────────
 * Un message = un palier, et le handler en réclame un autre tant qu'il reste du travail.
 * Boucler ici sur tous les paliers serait plus court à écrire et ruinerait tout : la
 * rétention mémoire du contrôle à blanc s'accumulerait dans le même processus, et le
 * worker mourrait exactement là où mourait la requête qu'on a supprimée.
 *
 * ⚠ C'EST `--memory-limit` QUI FERME LA BOUCLE. Le worker déclaré dans
 * `.symfony.local.yaml` redémarre au-delà de 256 Mo : les paliers repartent donc
 * périodiquement d'un processus neuf, ce qui est la seule protection réelle contre une
 * fuite qu'on n'a pas su localiser.
 *
 * ── ET IL DOIT ENDOSSER UNE IDENTITÉ ────────────────────────────────────────────────
 * ⚠ SANS JETON, L'IMPORT ÉCHOUE LIGNE À LIGNE SANS QUE RIEN NE L'EXPLIQUE. Les champs de
 * relation des formulaires filtrent leurs choix sur le cabinet actif de l'utilisateur —
 * c'est ce qui empêche de rattacher un client au portefeuille du cabinet voisin. Dans un
 * worker, il n'y a pas de session : la liste des choix est vide, et chaque valeur est
 * refusée sur « le choix sélectionné est invalide ». Le rapport accuserait alors la
 * saisie pour une faute qui est d'infrastructure.
 *
 * On emprunte donc le service qui existe, celui-là même que l'assistant utilise pour la
 * même raison — troisième appelant d'un besoin qui n'a jamais eu qu'une réponse.
 */
#[AsMessageHandler]
final class AvancerUnPalierDImportHandler
{
    public function __construct(
        private readonly EchangeImportRunRepository $runs,
        private readonly AvanceurDImport $avanceur,
        private readonly IdentiteDuTraitement $identite,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AvancerUnPalierDImport $message): void
    {
        $run = $this->runs->find($message->idRun);
        if ($run === null) {
            // Le contrôle a été annulé, ou purgé : le signal n'a plus d'objet. Ce n'est
            // pas une panne, et le rejouer n'y changerait rien.
            return;
        }

        $entreprise = $run->getEntreprise();
        $invite = $run->getInvite();
        if ($entreprise === null || $invite === null) {
            $this->logger->warning('Import : contrôle sans cabinet identifiable, abandonné.', ['run' => $message->idRun]);

            return;
        }

        if (!in_array($run->getStatut(), [EchangeImportRun::STATUT_CONTROLE, EchangeImportRun::STATUT_EN_COURS], true)) {
            // Terminé, en échec, annulé, ou en attente de la décision de l'utilisateur :
            // dans tous ces cas, il n'y a rien à pousser.
            return;
        }

        $avant = $run->getCurseur();

        $this->identite->endosser($invite, $entreprise);
        try {
            $run = $this->avanceur->avancerUnPalier($run);
        } finally {
            $this->identite->relacher();
        }

        // ⚠ ON NE SE RÉVEILLE QUE SI L'ON A AVANCÉ. Un palier qui piétine — fichier
        // disparu, lecture impossible — ne le fera pas davantage au suivant : redemander
        // un message construirait une file qui tourne sur elle-même jusqu'à ce que
        // quelqu'un l'arrête.
        if ($run->getCurseur() > $avant
            && in_array($run->getStatut(), [EchangeImportRun::STATUT_CONTROLE, EchangeImportRun::STATUT_EN_COURS], true)) {
            $this->bus->dispatch(new AvancerUnPalierDImport((int) $run->getId()));
        }
    }
}
