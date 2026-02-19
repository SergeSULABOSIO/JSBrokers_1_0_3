<?php

namespace App\Services\Canvas;

use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Contact;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\ModelePieceSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\PieceSinistre;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Tache;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Services\ServiceMonnaies;
use App\Services\Canvas\Provider\List\ListCanvasProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class ListCanvasProvider
{
    /**
     * @var ListCanvasProviderInterface[]
     */
    private iterable $providers;

    public function __construct(
        #[TaggedIterator('app.list_canvas_provider')] iterable $providers,
        private ServiceMonnaies $serviceMonnaies
    ) {
        $this->providers = $providers;
    }

    public function getCanvas(string $entityClassName): array
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($entityClassName)) {
                $canvas = $provider->getCanvas();

                // S'assurer que 'colonnes_numeriques' existe toujours, même vide, avant d'ajouter les colonnes partagées.
                $canvas['colonnes_numeriques'] = $canvas['colonnes_numeriques'] ?? [];

                // Ajouter les colonnes numériques partagées si elles existent.
                $canvas['colonnes_numeriques'] = array_merge($canvas['colonnes_numeriques'], $this->getSharedNumericColumnsForEntity($entityClassName));

                return $canvas;
            }
        }

        return [];
    }

    private function getSharedNumericColumnsForEntity(string $entityClassName): array
    {
        return [];
    }
}
