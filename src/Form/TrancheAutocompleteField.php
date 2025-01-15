<?php

namespace App\Form;

use App\Entity\Tranche;
use App\Entity\Assureur;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class TrancheAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private Security $security
    ) {}

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Tranche::class,
            'placeholder' => "Séléctionnez la tranche",
            // 'choice_label' => 'nom',
            'query_builder' => function (EntityRepository $er): QueryBuilder {
                /** @var Utilisateur $user */
                $user = $this->security->getUser();

                /** @var Entreprise $entreprise */
                $entreprise = $user->getConnectedTo();

                return $er->createQueryBuilder('tranche')
                    ->leftJoin("tranche.cotation", "cotation")
                    ->leftJoin("cotation.piste", "piste")
                    ->leftJoin("piste.invite", "invite")
                    ->where('invite.entreprise =:eseId')
                    ->setParameter('eseId', $entreprise->getId())
                    ->orderBy('tranche.id', 'ASC');
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
