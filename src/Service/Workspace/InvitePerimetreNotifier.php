<?php

namespace App\Service\Workspace;

use App\Entity\Invite;
use App\Services\Mail\CorporateMailer;
use Psr\Log\LoggerInterface;

/**
 * @file Notifie un invité de son périmètre d'action lorsqu'il change.
 * @description E-mail adressé au SEUL invité concerné, récapitulant les droits qui lui
 * sont attribués (module → entités → niveaux). Mutualisé (DRY) : appelé depuis le point
 * de passage générique des rôles (ControllerUtilsTrait), après enregistrement/suppression
 * d'un RolesEn*. Le déclenchement « uniquement quand le périmètre change » est garanti
 * par le routage : les rôles se modifient via les contrôleurs RolesEn*, tandis qu'une
 * simple édition de la fiche invité (nom/email) passe par InviteController et n'émet
 * donc aucun e-mail. Sur le modèle de Console\AffectationNotifier (même template, même
 * tolérance aux pannes : un échec d'envoi n'annule jamais l'enregistrement persisté).
 */
class InvitePerimetreNotifier
{
    public function __construct(
        private readonly CorporateMailer $corporateMailer,
        private readonly WorkspaceAccessResolver $resolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(Invite $invite): void
    {
        $email = $invite->getEmail();
        if (!$email) {
            // Invitation sans destinataire exploitable : rien à envoyer.
            return;
        }

        $entreprise = $invite->getEntreprise();
        $nom = $invite->getNom() ?: $email;

        try {
            $this->corporateMailer->send(
                $email,
                $this->corporateMailer->buildSubject('Mise à jour de vos accès', (string) $nom),
                'emails/agent_notification.html.twig',
                [
                    'titre'   => "Votre périmètre d'action a été mis à jour",
                    'intro'   => sprintf(
                        'Bonjour %s, le propriétaire de l\'espace de travail%s vient de mettre à jour vos droits d\'accès. Voici votre périmètre d\'action actuel.',
                        $nom,
                        $entreprise ? ' « ' . $entreprise->getNom() . ' »' : ''
                    ),
                    'icone'   => 'role',
                    'details' => $this->resolver->describePerimetre($invite),
                    'agent'   => $nom,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Périmètre invité : échec de notification e-mail de {nom} : {msg}', [
                'nom' => $nom,
                'msg' => $e->getMessage(),
            ]);
        }
    }
}
