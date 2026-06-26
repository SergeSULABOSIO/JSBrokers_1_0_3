<?php

namespace App\Crm;

use App\Entity\Crm\CrmCampagne;
use App\Entity\Crm\CrmCampagneCible;
use App\Repository\Crm\CrmProfilRepository;
use App\Services\Mail\CorporateMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @file Exécution des campagnes marketing CRM.
 * @description Résout le segment (étapes de pipeline / couleurs de santé),
 * matérialise les cibles et envoie un e-mail corporate à chaque client via
 * CorporateMailer. Met à jour les compteurs et le statut de la campagne.
 */
class CrmCampagneService
{
    public function __construct(
        private EntityManagerInterface $em,
        private CrmProfilRepository $profilRepository,
        private CorporateMailer $mailer,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** Aperçu du nombre de destinataires d'un segment (sans envoi). */
    public function previewCount(array $stages, array $couleurs): int
    {
        return count($this->profilRepository->findBySegment($stages, $couleurs));
    }

    /**
     * Envoie la campagne à son segment. Idempotent au niveau métier : une campagne
     * déjà envoyée n'est pas renvoyée.
     *
     * @return int Nombre d'e-mails envoyés
     */
    public function send(CrmCampagne $campagne): int
    {
        if ($campagne->getStatut() === CrmCampagne::STATUT_ENVOYEE) {
            return 0;
        }

        $segment = $campagne->getSegmentRegles();
        $profils = $this->profilRepository->findBySegment(
            $segment['stages'] ?? [],
            $segment['couleurs'] ?? [],
        );

        $lien = $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $messageLignes = array_filter(array_map('trim', preg_split('/\r?\n/', (string) $campagne->getMessage())));

        $envois = 0;
        foreach ($profils as $profil) {
            $client = $profil->getUtilisateur();
            $email = $client?->getEmail();
            if (!$email) {
                continue;
            }

            $cible = (new CrmCampagneCible())->setCampagne($campagne)->setClient($client);

            try {
                $this->mailer->send(
                    $email,
                    $this->mailer->buildSubject('Campagne', $campagne->getNom()),
                    'emails/crm_campagne.html.twig',
                    [
                        'objet'         => $campagne->getObjet(),
                        'clientNom'     => $client->getNom(),
                        'messageLignes' => array_values($messageLignes),
                        'lien'          => $lien,
                    ],
                );
                $cible->setStatutEnvoi(CrmCampagneCible::ENVOI_ENVOYE)->setSentAt(new \DateTimeImmutable());
                $envois++;
            } catch (\Throwable) {
                $cible->setStatutEnvoi(CrmCampagneCible::ENVOI_ECHEC);
            }

            $this->em->persist($cible);
        }

        $campagne->setNbCibles(count($profils))
            ->setNbEnvois($envois)
            ->setStatut(CrmCampagne::STATUT_ENVOYEE)
            ->setSentAt(new \DateTimeImmutable());

        $this->em->flush();

        return $envois;
    }
}
