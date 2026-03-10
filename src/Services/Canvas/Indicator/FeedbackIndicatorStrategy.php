<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Feedback;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;
use DateTimeImmutable;

class FeedbackIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Feedback::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Feedback $entity */
        $auteur = $entity->getInvite();
        if (!$auteur && $entity->getTache()) {
            $auteur = $entity->getTache()->getExecutor();
        }
        $auteurNom = $auteur ? ($auteur->getNom() ?: $auteur->getEmail()) : 'Inconnu';

        return [
            'typeString' => $this->getFeedbackTypeString($entity),
            'delaiProchaineAction' => $this->calculateFeedbackDelaiProchaineAction($entity),
            'ageFeedback' => $this->calculateFeedbackAge($entity),
            'statutProchaineAction' => $this->getFeedbackStatutProchaineActionString($entity),
            'descriptionText' => strip_tags($entity->getDescription() ?? ''),
            'auteurNom' => $auteurNom,
            'nombreDocuments' => $entity->getDocuments()->count(),
            'estEnRetard' => $this->getFeedbackEstEnRetardString($entity),
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function getFeedbackTypeString(?Feedback $feedback): ?string
    {
        if ($feedback === null) return null;

        return match ($feedback->getType()) {
            Feedback::TYPE_PHYSICAL_MEETING => "Rencontre physique",
            Feedback::TYPE_CALL => "Appel téléphonique",
            Feedback::TYPE_EMAIL => "E-mail",
            Feedback::TYPE_SMS => "SMS / Messagerie",
            Feedback::TYPE_UNDEFINED => "Autre",
            default => null,
        };
    }

    private function calculateFeedbackDelaiProchaineAction(Feedback $feedback): string
    {
        if (!$feedback->hasNextAction() || !$feedback->getNextActionAt()) {
            return 'N/A';
        }
        $now = new DateTimeImmutable();
        if ($feedback->getNextActionAt() < $now) {
            return 'Expirée';
        }
        $jours = $this->serviceDates->daysEntre($now, $feedback->getNextActionAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function calculateFeedbackAge(Feedback $feedback): string
    {
        if (!$feedback->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($feedback->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function getFeedbackStatutProchaineActionString(?Feedback $feedback): ?string
    {
        if ($feedback === null) return null;
        return $feedback->hasNextAction() ? 'Planifiée' : 'Aucune';
    }

    private function getFeedbackEstEnRetardString(Feedback $feedback): string
    {
        if (!$feedback->hasNextAction() || !$feedback->getNextActionAt()) {
            return 'Non applicable';
        }
        if ($feedback->getNextActionAt() < new DateTimeImmutable()) {
            return 'Oui';
        }
        return 'Non';
    }
}