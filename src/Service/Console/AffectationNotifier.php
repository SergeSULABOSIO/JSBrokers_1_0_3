<?php

namespace App\Service\Console;

use App\Entity\Utilisateur;
use App\Services\Mail\CorporateMailer;
use Psr\Log\LoggerInterface;

/**
 * @file Notifie un collaborateur de son affectation (département + fonction).
 * @description E-mail adressé au SEUL collaborateur concerné, récapitulant son
 * département, sa fonction, son niveau et son périmètre d'accès. Mutualisé entre la
 * création/édition d'un collaborateur (CollaborateurController) et la section
 * d'affectation (DepartementController) — DRY. Tolérant aux pannes : un échec
 * d'envoi ne doit pas annuler l'enregistrement déjà persisté.
 */
class AffectationNotifier
{
    public function __construct(
        private CorporateMailer $corporateMailer,
        private LoggerInterface $logger,
    ) {
    }

    public function notify(Utilisateur $collaborateur): void
    {
        $email = $collaborateur->getEmail();
        if (!$email) {
            return;
        }

        $departement = $collaborateur->getDepartement();
        $fonction = $collaborateur->getFonction();

        $details = [
            'Département'            => $departement?->label() ?? 'Non affecté',
            'Fonction'              => $fonction?->label() ?? 'Non définie',
            'Niveau d\'accès'       => $fonction?->niveauLabel() ?? '—',
            'Rubriques accessibles' => implode(', ', $collaborateur->getPerimetreLabels()) ?: 'Accès complet (non restreint)',
        ];

        try {
            $this->corporateMailer->send(
                $email,
                $this->corporateMailer->buildSubject('Affectation & rôle', (string) $collaborateur->getNom()),
                'emails/agent_notification.html.twig',
                [
                    'titre'   => 'Votre rôle au sein de JS Brokers',
                    'intro'   => sprintf(
                        'Bonjour %s, votre rattachement au sein de l\'équipe JS Brokers vient d\'être défini. Voici les détails de votre affectation.',
                        $collaborateur->getNom()
                    ),
                    'icone'   => 'role',
                    'details' => $details,
                    'agent'   => $collaborateur->getNom(),
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Affectation : échec de notification e-mail de {nom} : {msg}', [
                'nom' => $collaborateur->getNom(),
                'msg' => $e->getMessage(),
            ]);
        }
    }
}
