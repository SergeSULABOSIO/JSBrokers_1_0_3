<?php

namespace App\Services\Canvas;

use App\Constantes\Constante;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Services\ServiceDates;
use DateTimeImmutable;

class CalculationProvider
{
    /**
     * @param ServiceDates $serviceDates
     * @param Constante $constante
     */
    public function __construct(
        private ServiceDates $serviceDates,
        private Constante $constante
    ) {
    }

    /**
     * Calcule le délai en jours entre la survenance et la notification d'un sinistre.
     */
    public function Notification_Sinistre_getDelaiDeclaration(NotificationSinistre $sinistre): string
    {
        if (!$sinistre->getOccuredAt() || !$sinistre->getNotifiedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($sinistre->getOccuredAt(), $sinistre->getNotifiedAt());
        return $jours . ' jour(s)';
    }

    /**
     * Calcule l'âge du dossier sinistre depuis sa création.
     */
    public function Notification_Sinistre_getAgeDossier(NotificationSinistre $sinistre): string
    {
        if (!$sinistre->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($sinistre->getCreatedAt(), new DateTimeImmutable());
        return $jours . ' jour(s)';
    }

    /**
     * Calcule le pourcentage de pièces fournies par rapport aux pièces attendues.
     */
    public function Notification_Sinistre_getIndiceCompletude(NotificationSinistre $sinistre): string
    {
        $attendus = count($this->constante->getEnterprise()->getModelePieceSinistres());
        if ($attendus === 0) {
            return '100 %'; // S'il n'y a aucune pièce modèle, le dossier est complet.
        }
        $fournis = count($sinistre->getPieces());
        $pourcentage = ($fournis / $attendus) * 100;
        return round($pourcentage) . ' %';
    }

    /**
     * Calcule le pourcentage payé d'une offre d'indemnisation.
     */
    public function Offre_Indemnisation_getPourcentagePaye(OffreIndemnisationSinistre $offre): string
    {
        $montantPayable = $offre->getMontantPayable();
        if ($montantPayable == 0 || $montantPayable === null) {
            return '100 %'; // Si rien n'est à payer, c'est considéré comme payé.
        }
        $totalVerse = $this->constante->Offre_Indemnisation_getCompensationVersee($offre);
        $pourcentage = ($totalVerse / $montantPayable) * 100;
        return round($pourcentage) . ' %';
    }

        /**
     * Calcule le montant total de l'indemnisation convenue pour ce sinistre.
     */
    public function Notification_Sinistre_getCompensation(NotificationSinistre $sinistre): float
    {
        $compensation = 0;
        if ($sinistre != null) {
            foreach ($sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $compensation += $offre_indemnisation->getMontantPayable();
            }
        }
        return $compensation;
    }

    /**
     * Calcule le montant cumulé des paiements déjà effectués pour cette indemnisation.
     */
    public function Notification_Sinistre_getCompensationVersee(NotificationSinistre $sinistre): float
    {
        $montant = 0;
        if ($sinistre != null) {
            foreach ($sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $montant += $this->Offre_Indemnisation_getCompensationVersee($offre_indemnisation);
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant restant à payer pour solder complètement ce dossier sinistre.
     */
    public function Notification_Sinistre_getSoldeAVerser(NotificationSinistre $sinistre): float
    {
        $montant = 0;
        if ($sinistre != null) {
            foreach ($sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $montant += $this->Offre_Indemnisation_getSoldeAVerser($offre_indemnisation);
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant de la franchise qui a été appliquée conformément aux termes de la police.
     */
    public function Notification_Sinistre_getFranchise(NotificationSinistre $sinistre): float
    {
        $montant = 0;
        if ($sinistre != null) {
            foreach ($sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $montant += $offre_indemnisation->getFranchiseAppliquee();
            }
        }
        return $montant;
    }

    /**
     * Calcule la durée totale en jours entre la notification du sinistre et le dernier paiement de règlement.
     */
    public function Notification_Sinistre_getDureeReglement(NotificationSinistre $notification_sinistre): int
    {
        $duree = -1;
        $dateNotfication = $notification_sinistre->getNotifiedAt();
        $dateRgelement = null;
        if ($this->Notification_Sinistre_getSoldeAVerser($notification_sinistre) == 0) {
            $offres = $notification_sinistre->getOffreIndemnisationSinistres();
            if (count($offres) != 0) {
                $reglements = ($offres[count($offres) - 1])->getPaiements();
                $dateRgelement = ($reglements[count($reglements) - 1])->getPaidAt();
                $duree = $this->serviceDates->daysEntre($dateNotfication, $dateRgelement);
            }
        }
        return $duree;
    }

    /**
     * Récupère la date à laquelle le tout dernier paiement a été effectué pour ce sinistre.
     */
    public function Notification_Sinistre_getDateDernierRgelement(NotificationSinistre $notification_sinistre): ?\DateTimeInterface
    {
        $dateDernierRgelement = null;
        if ($this->Notification_Sinistre_getSoldeAVerser($notification_sinistre) == 0) {
            $offres = $notification_sinistre->getOffreIndemnisationSinistres();
            if (count($offres) != 0) {
                $reglements = ($offres[count($offres) - 1])->getPaiements();
                $dateDernierRgelement = ($reglements[count($reglements) - 1])->getPaidAt();
            }
        }
        return $dateDernierRgelement;
    }

    /**
     * Calcule le montant cumulé des paiements déjà effectués pour cette offre.
     */
    public function Offre_Indemnisation_getCompensationVersee(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        $montant = 0;
        if ($offre_indemnisation != null) {
            foreach ($offre_indemnisation->getPaiements() as $paiement) {
                $montant += $paiement->getMontant();
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant restant à payer pour solder cette offre.
     */
    public function Offre_Indemnisation_getSoldeAVerser(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        $montant = 0;
        if ($offre_indemnisation != null) {
            $compensation = 0;
            if ($offre_indemnisation->getNotificationSinistre() != null) {
                $compensation = $offre_indemnisation->getMontantPayable();
            }
            $compensationVersee = $this->Offre_Indemnisation_getCompensationVersee($offre_indemnisation);
            $montant = $compensation - $compensationVersee;
        }
        return $montant;
    }

}
}