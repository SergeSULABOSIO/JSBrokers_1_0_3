<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Services\ServiceDates;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;
use DateTimeImmutable;

class NotificationSinistreIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private Security $security
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === NotificationSinistre::class;
    }

    public function calculate(object $entity): array
    {
        /** @var NotificationSinistre $entity */
        $assure = $entity->getAssure();
        $assureNom = $assure ? $assure->getNom() : 'N/A';

        return [
            'assureNom' => $assureNom,
            'delaiDeclaration' => $this->calculateDelaiDeclaration($entity),
            'ageDossier' => $this->calculateAgeDossier($entity),
            'compensationFranchise' => round($this->calculateFranchise($entity), 2),
            'tauxIndemnisation' => $this->getNotificationSinistreTauxIndemnisation($entity),
            'nombreOffres' => $this->getNotificationSinistreNombreOffres($entity),
            'nombrePaiements' => $this->getNotificationSinistreNombrePaiements($entity),
            'montantMoyenParPaiement' => round($this->getNotificationSinistreMontantMoyenParPaiement($entity) ?? 0.0, 2),
            'delaiTraitementInitial' => $this->getNotificationSinistreDelaiTraitementInitial($entity),
            'ratioPaiementsEvaluation' => $this->getNotificationSinistreRatioPaiementsEvaluation($entity),
            'compensation' => round($this->getNotificationSinistreCompensation($entity), 2),
            'compensationVersee' => round($this->getNotificationSinistreCompensationVersee($entity), 2),
            'compensationSoldeAverser' => round($this->getNotificationSinistreSoldeAVerser($entity), 2),
            'indiceCompletude' => $this->getNotificationSinistreIndiceCompletude($entity),
            'dateDernierReglement' => $this->getNotificationSinistreDateDernierReglement($entity),
            'dureeReglement' => $this->getNotificationSinistreDureeReglement($entity),
            'statusDocumentsAttendus' => $this->getNotificationSinistreStatusDocumentsAttendus($entity),
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function getEntreprise(): Entreprise
    {
        /** @var Utilisateur $user */
        $user = $this->security->getUser();
        return $user->getConnectedTo();
    }

    private function calculateDelaiDeclaration(NotificationSinistre $sinistre): string
    {
        if (!$sinistre->getOccuredAt() || !$sinistre->getNotifiedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($sinistre->getOccuredAt(), $sinistre->getNotifiedAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function calculateAgeDossier(NotificationSinistre $sinistre): string
    {
        if (!$sinistre->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($sinistre->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function calculateFranchise(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + ($offre->getFranchiseAppliquee() ?? 0);
        }, 0.0);
    }

    private function getNotificationSinistreCompensation(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + ($offre->getMontantPayable() ?? 0);
        }, 0.0);
    }

    private function getNotificationSinistreCompensationVersee(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + $this->getOffreIndemnisationCompensationVersee($offre);
        }, 0.0);
    }

    private function getOffreIndemnisationCompensationVersee(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        return array_reduce($offre_indemnisation->getPaiements()->toArray(), function ($carry, Paiement $paiement) {
            return $carry + ($paiement->getMontant() ?? 0);
        }, 0.0);
    }

    private function getNotificationSinistreSoldeAVerser(NotificationSinistre $sinistre): float
    {
        return $this->getNotificationSinistreCompensation($sinistre) - $this->getNotificationSinistreCompensationVersee($sinistre);
    }

    private function getNotificationSinistreTauxIndemnisation(NotificationSinistre $sinistre): ?float
    {
        $compensation = $this->getNotificationSinistreCompensation($sinistre);
        $evaluation = $sinistre->getEvaluationChiffree();

        if ($evaluation > 0) {
            return round(($compensation / $evaluation) * 100, 2);
        }
        return null;
    }

    private function getNotificationSinistreNombreOffres(NotificationSinistre $sinistre): int
    {
        return $sinistre->getOffreIndemnisationSinistres()->count();
    }

    private function getNotificationSinistreNombrePaiements(NotificationSinistre $sinistre): int
    {
        $nombrePaiements = 0;
        foreach ($sinistre->getOffreIndemnisationSinistres() as $offre) {
            $nombrePaiements += $offre->getPaiements()->count();
        }
        return $nombrePaiements;
    }

    private function getNotificationSinistreMontantMoyenParPaiement(NotificationSinistre $sinistre): ?float
    {
        $compensationVersee = $this->getNotificationSinistreCompensationVersee($sinistre);
        $nombrePaiements = $this->getNotificationSinistreNombrePaiements($sinistre);

        if ($nombrePaiements > 0) {
            return round($compensationVersee / $nombrePaiements, 2);
        }
        return null;
    }

    private function getNotificationSinistreDelaiTraitementInitial(NotificationSinistre $sinistre): string
    {
        if (!$sinistre->getCreatedAt() || !$sinistre->getNotifiedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($sinistre->getCreatedAt(), $sinistre->getNotifiedAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function getNotificationSinistreRatioPaiementsEvaluation(NotificationSinistre $sinistre): ?float
    {
        $compensationVersee = $this->getNotificationSinistreCompensationVersee($sinistre);
        $evaluation = $sinistre->getEvaluationChiffree();

        if ($evaluation > 0) {
            return round(($compensationVersee / $evaluation) * 100, 2);
        }
        return null;
    }

    private function getNotificationSinistreIndiceCompletude(NotificationSinistre $sinistre): string
    {
        $modelesAttendus = $this->getEntreprise()->getModelePieceSinistres();
        $nombreAttendus = $modelesAttendus->count();

        if ($nombreAttendus === 0) {
            return '100 %'; 
        }

        $typesFournisUniques = [];
        foreach ($sinistre->getPieces() as $piece) {
            if ($type = $piece->getType()) {
                $typesFournisUniques[$type->getId()] = true;
            }
        }
        $nombreFournis = count($typesFournisUniques);
        $pourcentage = ($nombreFournis / $nombreAttendus) * 100;
        return round($pourcentage) . ' %';
    }

    private function getNotificationSinistreDateDernierReglement(NotificationSinistre $sinistre): ?\DateTimeInterface
    {
        $dateDernierReglement = null;
        foreach ($sinistre->getOffreIndemnisationSinistres() as $offre) {
            foreach ($offre->getPaiements() as $paiement) {
                if ($paiement->getPaidAt() && (!$dateDernierReglement || $paiement->getPaidAt() > $dateDernierReglement)) {
                    $dateDernierReglement = $paiement->getPaidAt();
                }
            }
        }
        return $dateDernierReglement;
    }

    private function getNotificationSinistreDureeReglement(NotificationSinistre $sinistre): ?string
    {
        $dateDernierReglement = $this->getNotificationSinistreDateDernierReglement($sinistre);
        $dateNotification = $sinistre->getNotifiedAt();

        if (!$dateDernierReglement || !$dateNotification) {
            return null;
        }

        $jours = $this->serviceDates->daysEntre($dateNotification, $dateDernierReglement);
        return $jours !== null ? $jours . ' jour(s)' : null;
    }

    private function getNotificationSinistreStatusDocumentsAttendus(NotificationSinistre $sinistre): string
    {
        $modelesAttendus = $this->getEntreprise()->getModelePieceSinistres();
        $nombreAttendus = $modelesAttendus->count();

        $typesFournis = [];
        foreach ($sinistre->getPieces() as $piece) {
            if ($type = $piece->getType()) {
                $typesFournis[$type->getId()] = true;
            }
        }
        $nombreFournis = count($typesFournis);

        $nombreManquants = 0;
        foreach ($modelesAttendus as $modele) {
            if (!isset($typesFournis[$modele->getId()])) {
                $nombreManquants++;
            }
        }

        return sprintf(
            "Attendus: %d pc(s) • Fournis: %d pc(s) • Manquants: %d pc(s)",
            $nombreAttendus,
            $nombreFournis,
            $nombreManquants
        );
    }
}