<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Entreprise;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;
use DateTimeImmutable;

class EntrepriseIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Entreprise::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Entreprise $entity */
        return [
            'ageEntreprise' => $this->calculateEntrepriseAge($entity),
            'nombreCollaborateurs' => $this->countEntrepriseCollaborateurs($entity),
            'nombreClients' => $this->countEntrepriseClients($entity),
            'nombrePartenaires' => $this->countEntreprisePartenaires($entity),
            'nombreAssureurs' => $this->countEntrepriseAssureurs($entity),
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function calculateEntrepriseAge(Entreprise $entreprise): string
    {
        if (!$entreprise->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($entreprise->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function countEntrepriseCollaborateurs(Entreprise $entreprise): int
    {
        return $entreprise->getInvites()->count();
    }

    private function countEntrepriseClients(Entreprise $entreprise): int
    {
        return $entreprise->getClients()->count();
    }

    private function countEntreprisePartenaires(Entreprise $entreprise): int
    {
        return $entreprise->getPartenaires()->count();
    }

    private function countEntrepriseAssureurs(Entreprise $entreprise): int
    {
        return $entreprise->getAssureurs()->count();
    }
}