<?php

namespace App\Form;

use App\Entity\Tranche;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class TrancheAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire
    ) {}

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Tranche::class,
            'placeholder' => "Sélectionnez la tranche",
            'query_builder' => function (EntityRepository $er): QueryBuilder {
                // Utilisation propre de ta factory pour récupérer l'ID
                $entrepriseId = $this->ecouteurFormulaire->getCurrentEntrepriseId();

                return $er->createQueryBuilder('tranche')
                    ->leftJoin("tranche.cotation", "cotation")
                    ->leftJoin("cotation.piste", "piste")
                    ->leftJoin("piste.invite", "invite")
                    ->where('invite.entreprise = :eseId')
                    ->setParameter('eseId', $entrepriseId)
                    ->orderBy('tranche.id', 'ASC');
            },
            'searchable_fields' => ['nom'],
            'as_html' => true,
            'choice_label' => function(Tranche $tranche) {
                // Sécurisation stricte contre les valeurs nulles (0 est accepté)
                $taux = $tranche->getPourcentage() !== null ? ($tranche->getPourcentage() * 100) . '%' : '-';
                $montant = $tranche->getMontantFlat() !== null ? number_format($tranche->getMontantFlat(), 2, ',', ' ') : '-';
                
                return sprintf(
                    '<div><strong>%s</strong><div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">Tranche de prime | Taux: %s | Montant: %s</div></div>',
                    htmlspecialchars($tranche->getNom() ?? 'Sans nom'),
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