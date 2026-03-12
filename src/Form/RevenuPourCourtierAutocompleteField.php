<?php

namespace App\Form;

use App\Entity\RevenuPourCourtier;
use App\Services\FormListenerFactory;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class RevenuPourCourtierAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => RevenuPourCourtier::class,
            'placeholder' => 'Rechercher un revenu',
            'query_builder' => function (EntityRepository $er) {
                $entrepriseId = $this->ecouteurFormulaire->getCurrentEntrepriseId();
                
                return $er->createQueryBuilder('r')
                    ->addSelect('tr', 'c', 'a', 'assureur', 'piste', 'client', 'partenaires') // Optimisation pour éviter les requêtes N+1
                    ->join('r.typeRevenu', 'tr')
                    // Exigence métier : la cotation DOIT exister et DOIT avoir un avenant
                    ->join('r.cotation', 'c')
                    ->join('c.avenants', 'a')
                    ->leftJoin('c.assureur', 'assureur')
                    ->leftJoin('c.piste', 'piste')
                    ->leftJoin('piste.client', 'client')
                    ->leftJoin('piste.partenaires', 'partenaires')
                    ->where('tr.entreprise = :eseId')
                    ->setParameter('eseId', $entrepriseId)
                    ->orderBy('r.id', 'ASC');
            },
            'searchable_fields' => ['nom'],
            'as_html' => true,
            'choice_label' => function(RevenuPourCourtier $revenu) {
                // Formatage des indicateurs avec fallback '-'
                $taux = $revenu->getTauxExceptionel() !== null ? ($revenu->getTauxExceptionel() * 100) . '%' : '-';
                $montant = $revenu->getMontantFlatExceptionel() !== null ? number_format($revenu->getMontantFlatExceptionel(), 2, ',', ' ') : '-';
                
                // Récupération des entités liées
                $cotation = $revenu->getCotation();
                $avenant = ($cotation && !$cotation->getAvenants()->isEmpty()) ? $cotation->getAvenants()->first() : null;
                $piste = $cotation ? $cotation->getPiste() : null;
                
                // Extraction des références et des noms
                $policeRef = ($avenant && $avenant->getReferencePolice()) ? $avenant->getReferencePolice() : 'N/A';
                $avenantNum = ($avenant && $avenant->getNumero()) ? $avenant->getNumero() : 'N/A';
                
                $assureurNom = ($cotation && $cotation->getAssureur()) ? $cotation->getAssureur()->getNom() : 'N/A';
                $clientNom = ($piste && $piste->getClient()) ? $piste->getClient()->getNom() : 'N/A';
                
                // Un lead/piste peut avoir plusieurs partenaires
                $partenaireNoms = [];
                if ($piste && !$piste->getPartenaires()->isEmpty()) {
                    foreach ($piste->getPartenaires() as $partenaire) {
                        $partenaireNoms[] = $partenaire->getNom();
                    }
                }
                $partenaireNom = !empty($partenaireNoms) ? implode(', ', $partenaireNoms) : 'N/A';
                
                $typeRevenu = $revenu->getTypeRevenu() ? $revenu->getTypeRevenu()->getNom() : 'Type N/A';
                
                return sprintf(
                    '<div><strong>%s</strong><div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">Réf Police: %s | Avenant: %s | Assureur: %s | Client: %s | Partenaire(s): %s | Type: %s | Taux: %s | Flat: %s</div></div>',
                    htmlspecialchars($revenu->getNom() ?? 'Sans nom'),
                    htmlspecialchars($policeRef),
                    htmlspecialchars($avenantNum),
                    htmlspecialchars($assureurNom),
                    htmlspecialchars($clientNom),
                    htmlspecialchars($partenaireNom),
                    htmlspecialchars($typeRevenu),
                    $taux,
                    $montant
                );
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}