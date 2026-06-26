<?php

namespace App\Crm;

use App\Entity\Crm\CrmNotification;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @file Émission de notifications internes (in-app) pour l'équipe JS Brokers.
 * @description Persiste une CrmNotification visible dans la Console. `agent` null
 * = diffusion à tous les agents (une seule ligne, lue globalement). Complète les
 * toasts (éphémères) et les e-mails (CorporateMailer) déjà en place.
 */
class CrmNotifier
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function notify(
        string $titre,
        ?string $message = null,
        string $niveau = CrmNotification::NIVEAU_INFO,
        ?string $lien = null,
        ?Utilisateur $agent = null,
        bool $flush = false,
    ): CrmNotification {
        $notif = (new CrmNotification())
            ->setTitre($titre)
            ->setMessage($message)
            ->setNiveau($niveau)
            ->setLien($lien)
            ->setAgent($agent);

        $this->em->persist($notif);
        if ($flush) {
            $this->em->flush();
        }

        return $notif;
    }

    /** Diffusion à tous les agents (notification non ciblée). */
    public function broadcast(string $titre, ?string $message = null, string $niveau = CrmNotification::NIVEAU_INFO, ?string $lien = null, bool $flush = false): CrmNotification
    {
        return $this->notify($titre, $message, $niveau, $lien, null, $flush);
    }
}
