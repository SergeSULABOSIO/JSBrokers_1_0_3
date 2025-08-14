<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Risque;
use App\Entity\Assureur;
use App\Entity\NotificationSinistre;
use App\Services\FormListenerFactory;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
// 1. Assurez-vous que cette ligne 'use' est bien présente
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;


#[AsEntityAutocompleteField]
class NotificationSinistreType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('assureur', BaseEntityAutocompleteType::class, [
                'class' => Assureur::class,
                'label' => "Assureur concerné",
                'placeholder' => "Taper pour chercher l'assureur...",
                'required' => true,
                'choice_label' => 'nom',
                'searchable_fields' => ['nom', 'email'],
            ])
            ->add('risque', BaseEntityAutocompleteType::class, [
                'class' => Risque::class,
                'label' => "Couverture d'assurance concernée",
                'placeholder' => 'Taper pour chercher un risque...',
                'required' => true,
                'choice_label' => 'nomComplet',
                'searchable_fields' => ['nomComplet', 'code'],
            ])
            ->add('assure', BaseEntityAutocompleteType::class, [
                'class' => Client::class,
                'label' => "Client concernée",
                'placeholder' => 'Taper pour chercher le client...',
                'required' => true,
                'choice_label' => 'nom',
                'searchable_fields' => ['nom', 'code'],
            ])
            ->add('referencePolice')
            ->add('referenceSinistre')
            ->add('descriptionDeFait')
            ->add('descriptionVictimes')
            ->add('notifiedAt')
            ->add('occuredAt')
            ->add('lieu')
            ->add('dommage')
            ->add('evaluationChiffree')
            
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            //->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NotificationSinistre::class,
        ]);
    }
}
