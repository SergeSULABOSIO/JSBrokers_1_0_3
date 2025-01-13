<?php

namespace App\Form;

use App\Entity\Assureur;
use Doctrine\ORM\QueryBuilder;
use App\Entity\AutoriteFiscale;
use Doctrine\ORM\EntityRepository;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class AutoriteFiscaleAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private Security $security
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => AutoriteFiscale::class,
            'placeholder' => "Séléctionnez l'autorité",
            'choice_label' => 'nom',
            'query_builder' => function (EntityRepository $er): QueryBuilder {
                /** @var Utilisateur $user */
                $user = $this->security->getUser();
    
                /** @var Entreprise $entreprise */
                $entreprise = $user->getConnectedTo();
    
                // dd($entreprise->getNom());
                Ici je dois personnaliser cette requête DQL
                return $er->createQueryBuilder('e')
                    ->where('e.entreprise =:eseId')
                    ->setParameter('eseId', $entreprise->getId())
                    ->orderBy('e.id', 'ASC');
            },

            // choose which fields to use in the search
            // if not passed, *all* fields are used
            // 'searchable_fields' => ['name'],

            // 'security' => 'ROLE_SOMETHING',
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
