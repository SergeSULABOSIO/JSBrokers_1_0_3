<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Document;
use App\Service\Document\DocumentFichier;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;
use DateTimeImmutable;

class DocumentIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private DocumentFichier $documentFichier
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
            // Le POIDS du fichier, qui manquait à toutes les surfaces : la fiche
            // décrivait un document sans jamais dire ce qu'il pesait, alors que
            // c'est la première question avant de télécharger sur une connexion
            // lente. Lu sur le disque, donc « inconnue » quand le binaire a disparu
            // — ce qui est en soi une information.
            'tailleFichier' => DocumentFichier::tailleLisible($this->documentFichier->taille($entity)),
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

    /**
     * La phrase de rattachement affichée sur la fiche d'un document.
     *
     * CE QUI A CHANGÉ, ET POURQUOI. Cette méthode énumérait elle-même les getters de
     * parent, et il en manquait un : `paiementPrime`, ajouté à l'entité avec le
     * signalement déclaratif de paiement de prime, n'y figurait pas. Un document servi
     * de preuve à un paiement de prime s'affichait donc « rattaché à aucun élément
     * parent » — la relation existait en base, la fiche affirmait le contraire.
     *
     * La SÉLECTION du parent est désormais déléguée à {@see DocumentFichier::parentDe()},
     * qui la lit dans les métadonnées Doctrine : une nouvelle relation est prise en
     * compte sans que personne ait à y penser. Ne reste ici que ce qui appartient
     * vraiment à l'affichage — la FORMULATION.
     *
     * Et le repli n'est plus « aucun parent » mais une phrase construite à partir du
     * nom de l'entité : une relation non formulée reste ainsi VISIBLE, au lieu d'être
     * niée. C'est exactement le défaut qu'on vient de corriger ; autant l'empêcher de
     * revenir.
     */
    private function Document_getParentAsString(?Document $document): string
    {
        if ($document === null) {
            return "Document non trouvé.";
        }

        $parent = $this->documentFichier->parentDe($document);
        if ($parent === null) {
            return "Ce document n'est rattaché à aucun élément parent.";
        }

        $formulations = [
            \App\Entity\PieceSinistre::class => fn ($e) => "Lié à la pièce sinistre : '" . $e->getDescription() . "'",
            \App\Entity\OffreIndemnisationSinistre::class => fn ($e) => "Lié à l'offre d'indemnisation : '" . $e->getNom() . "'",
            \App\Entity\Cotation::class => fn ($e) => "Lié à la cotation : '" . $e->getNom() . "'",
            \App\Entity\Avenant::class => fn ($e) => "Lié à l'avenant (police n°" . $e->getReferencePolice() . ")",
            \App\Entity\Tache::class => fn ($e) => "Lié à la tâche : '" . $e->getDescription() . "'",
            \App\Entity\Feedback::class => fn ($e) => "Lié au feedback : '" . $e->getDescription() . "'",
            \App\Entity\Client::class => fn ($e) => "Lié au client : '" . $e->getNom() . "'",
            \App\Entity\Bordereau::class => fn ($e) => "Lié au bordereau : '" . $e->getNom() . "'",
            \App\Entity\CompteBancaire::class => fn ($e) => "Lié au compte bancaire : '" . $e->getNom() . "'",
            \App\Entity\Piste::class => fn ($e) => "Lié à la piste : '" . $e->getNom() . "'",
            \App\Entity\Partenaire::class => fn ($e) => "Lié au partenaire : '" . $e->getNom() . "'",
            \App\Entity\Paiement::class => fn ($e) => "Utilisé comme preuve pour le paiement n°" . $e->getReference(),
            \App\Entity\PaiementPrime::class => fn ($e) => "Utilisé comme preuve pour un paiement de prime (#" . $e->getId() . ")",
            \App\Entity\Fournisseur::class => fn ($e) => "Lié au fournisseur : '" . $e->getNom() . "'",
        ];

        // Doctrine peut rendre un PROXY : sa classe est une sous-classe de l'entité.
        foreach ($formulations as $classe => $formatter) {
            if ($parent instanceof $classe) {
                return $formatter($parent);
            }
        }

        return "Lié à : " . (new \ReflectionClass($parent))->getShortName();
    }

    private function Document_getClasseurAsString(?Document $document): string
    {
        if ($document === null || !$document->getClasseur()) {
            return "Non classé";
        }
        return "Classé dans : '" . $document->getClasseur()->getNom() . "'";
    }
}