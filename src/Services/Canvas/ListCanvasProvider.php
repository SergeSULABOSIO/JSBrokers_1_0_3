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

                $sharedColumns = $this->getSharedNumericColumnsForEntity($entityClassName);
                if (!empty($sharedColumns)) {
                    $canvas['colonnes_numeriques'] = array_merge($canvas['colonnes_numeriques'] ?? [], $sharedColumns);
                }
                
                return $canvas;
            }
        }

        return [];
    }

    private function getSharedNumericColumnsForEntity(string $entityClassName): array
    {
        $entitiesWithSharedColumns = [
            Assureur::class, 
            Avenant::class, 
            Chargement::class, 
            ChargementPourPrime::class,
            Client::class, 
            ConditionPartage::class, 
            Contact::class, 
            Cotation::class,
            Entreprise::class, 
            Feedback::class, 
            Groupe::class, 
            Invite::class,
            OffreIndemnisationSinistre::class, 
            Paiement::class,
            Partenaire::class, 
            Piste::class, 
            RevenuPourCourtier::class,
            Risque::class, 
            Tache::class, 
            Tranche::class, 
            TypeRevenu::class,
        ];

        if (!in_array($entityClassName, $entitiesWithSharedColumns)) {
            return [];
        }

        return [
            [
                "titre_colonne" => "Prime Nette",
                "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                "attribut_code" => "prime_nette",
                "attribut_type" => "nombre",
            ],
            [
                "titre_colonne" => "Prime Totale",
                "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                "attribut_code" => "prime_totale",
                "attribut_type" => "nombre",
            ],
            // [
            //     "titre_colonne" => "Comm. Pure",
            //     "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
            //     "attribut_code" => "commission_pure",
            //     "attribut_type" => "nombre",
            // ],
            [
                "titre_colonne" => "Comm. Nette",
                "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                "attribut_code" => "commission_nette",
                "attribut_type" => "nombre",
            ],
            [
                "titre_colonne" => "Comm. Totale",
                "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                "attribut_code" => "commission_totale",
                "attribut_type" => "nombre",
            ],
            [
                "titre_colonne" => "Rétro-comm.",
                "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                "attribut_code" => "retro_commission_partenaire",
                "attribut_type" => "nombre",
            ],
        ];
    }
}
