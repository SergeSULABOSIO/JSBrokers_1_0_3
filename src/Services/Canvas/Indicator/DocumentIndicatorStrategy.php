<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Document;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;
use DateTimeImmutable;

class DocumentIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Document::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Document $entity */
        return [
            'ageDocument' => $this->calculateDocumentAge($entity),
            'typeFichier' => $this->getDocumentTypeFichier($entity),
            'parent_string' => $this->Document_getParentAsString($entity),
            'classeur_string' => $this->Document_getClasseurAsString($entity),
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function calculateDocumentAge(Document $document): string
    {
        if (!$document->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($document->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function getDocumentTypeFichier(Document $document): string
    {
        $nomFichier = $document->getNomFichierStocke();
        if (!$nomFichier) {
            return 'Inconnu';
        }
        return pathinfo($nomFichier, PATHINFO_EXTENSION);
    }

    private function Document_getParentAsString(?Document $document): string
    {
        if ($document === null) {
            return "Document non trouvé.";
        }

        $parentGetters = [
            'getPieceSinistre' => fn ($e) => "Lié à la pièce sinistre : '" . $e->getDescription() . "'",
            'getOffreIndemnisationSinistre' => fn ($e) => "Lié à l'offre d'indemnisation : '" . $e->getNom() . "'",
            'getCotation' => fn ($e) => "Lié à la cotation : '" . $e->getNom() . "'",
            'getAvenant' => fn ($e) => "Lié à l'avenant (police n°" . $e->getReferencePolice() . ")",
            'getTache' => fn ($e) => "Lié à la tâche : '" . $e->getDescription() . "'",
            'getFeedback' => fn ($e) => "Lié au feedback : '" . $e->getDescription() . "'",
            'getClient' => fn ($e) => "Lié au client : '" . $e->getNom() . "'",
            'getBordereau' => fn ($e) => "Lié au bordereau : '" . $e->getNom() . "'",
            'getCompteBancaire' => fn ($e) => "Lié au compte bancaire : '" . $e->getNom() . "'",
            'getPiste' => fn ($e) => "Lié à la piste : '" . $e->getNom() . "'",
            'getPartenaire' => fn ($e) => "Lié au partenaire : '" . $e->getNom() . "'",
            'getPaiement' => fn ($e) => "Utilisé comme preuve pour le paiement n°" . $e->getReference(),
        ];

        foreach ($parentGetters as $getter => $formatter) {
            if ($parent = $document->$getter()) {
                return $formatter($parent);
            }
        }

        return "Ce document n'est rattaché à aucun élément parent.";
    }

    private function Document_getClasseurAsString(?Document $document): string
    {
        if ($document === null || !$document->getClasseur()) {
            return "Non classé";
        }
        return "Classé dans : '" . $document->getClasseur()->getNom() . "'";
    }
}