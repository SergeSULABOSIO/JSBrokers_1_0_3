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
                // On récupère l'ID de l'entreprise via la méthode disponible dans FormListenerFactory
                $entrepriseId = $this->ecouteurFormulaire->getCurrentEntrepriseId();
                
                // On fait une jointure (join) sur typeRevenu pour accéder à l'entreprise
                return $er->createQueryBuilder('r')
                    ->join('r.typeRevenu', 'tr')
                    ->where('tr.entreprise = :eseId')
                    ->setParameter('eseId', $entrepriseId)
                    ->orderBy('r.id', 'ASC');
            },
            'searchable_fields' => ['nom'],
            'as_html' => true,
            'choice_label' => function(RevenuPourCourtier $revenu) {
                return sprintf(
                    '<div><strong>%s</strong><div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">Revenu / Commission</div></div>',
                    htmlspecialchars($revenu->getNom() ?? 'Sans nom')
                );
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}