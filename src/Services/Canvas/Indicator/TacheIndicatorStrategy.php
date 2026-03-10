<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Tache;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class TacheIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tache::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Tache $entity */
        return [
            'statutExecution' => $this->getTacheStatutExecutionString($entity),
            'delaiRestant' => $this->calculateTacheDelaiRestant($entity),
            'ageTache' => $this->calculateTacheAge($entity),
            'nombreFeedbacks' => $this->countTacheFeedbacks($entity),
            'contexteTache' => $this->getTacheContexteString($entity),
            'descriptionText' => strip_tags($entity->getDescription() ?? ''),
            'prioriteCalculee' => $this->getTachePrioriteCalculee($entity),
            'dernierFeedbackDate' => $this->getTacheDernierFeedbackDate($entity),
            'nombreDocuments' => $this->countTacheDocuments($entity),
            'clientConcerne' => $this->getTacheClientConcerne($entity),
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function getTacheStatutExecutionString(Tache $tache): string
    {
        if ($tache->isClosed()) {
            return "Clôturée";
        }
        if ($tache->getToBeEndedAt() < new \DateTimeImmutable()) {
            return "Expirée";
        }
        return "En cours";
    }

    private function calculateTacheDelaiRestant(Tache $tache): string
    {
        if ($tache->isClosed() || !$tache->getToBeEndedAt()) {
            return 'N/A';
        }
        $now = new \DateTimeImmutable();
        if ($tache->getToBeEndedAt() < $now) {
            $jours = $this->serviceDates->daysEntre($tache->getToBeEndedAt(), $now) ?? 0;
            return $this->translator->trans('tache_expired_since', ['%days%' => $jours], 'messages');
        }
        $jours = $this->serviceDates->daysEntre($now, $tache->getToBeEndedAt()) ?? 0;
        return $this->translator->trans('tache_remaining_days', ['%days%' => $jours], 'messages');
    }

    private function calculateTacheAge(Tache $tache): string
    {
        if (!$tache->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($tache->getCreatedAt(), new \DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function countTacheFeedbacks(Tache $tache): int
    {
        return $tache->getFeedbacks()->count();
    }

    private function getTacheContexteString(?Tache $tache): ?string
    {
        if ($tache === null) return null;

        if ($parent = $tache->getPiste()) return "Piste: " . $parent->getNom();
        if ($parent = $tache->getCotation()) return "Cotation: " . $parent->getNom();
        if ($parent = $tache->getNotificationSinistre()) return "Sinistre: " . $parent->getReferenceSinistre();
        if ($parent = $tache->getOffreIndemnisationSinistre()) return "Offre: " . $parent->getNom();

        return "Non-associé";
    }

    private function getTachePrioriteCalculee(Tache $tache): string
    {
        if ($tache->isClosed()) return "Aucune (Terminée)";
        $now = new \DateTimeImmutable();
        $due = $tache->getToBeEndedAt();
        if (!$due) return "Non définie";
        if ($due < $now) return "Urgente (Expirée)";
        $diff = $this->serviceDates->daysEntre($now, $due);
        if ($diff <= 2) return "Haute";
        if ($diff <= 7) return "Moyenne";
        return "Normale";
    }

    private function getTacheDernierFeedbackDate(Tache $tache): ?\DateTimeInterface
    {
        if ($tache->getFeedbacks()->isEmpty()) {
            return null;
        }
        return $tache->getFeedbacks()->last()->getCreatedAt();
    }

    private function countTacheDocuments(Tache $tache): int
    {
        return $tache->getDocuments()->count();
    }

    private function getTacheClientConcerne(Tache $tache): string
    {
        $client = null;
        if ($piste = $tache->getPiste()) {
            $client = $piste->getClient();
        } elseif ($cotation = $tache->getCotation()) {
            $client = $cotation->getPiste()?->getClient();
        } elseif ($sinistre = $tache->getNotificationSinistre()) {
            $client = $sinistre->getAssure();
        } elseif ($offre = $tache->getOffreIndemnisationSinistre()) {
            $client = $offre->getNotificationSinistre()?->getAssure();
        }
        return $client ? $client->getNom() : 'N/A';
    }
}