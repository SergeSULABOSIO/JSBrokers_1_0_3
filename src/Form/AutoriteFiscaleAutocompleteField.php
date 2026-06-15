<?php

namespace App\Form;

use Doctrine\ORM\QueryBuilder;
use App\Entity\AutoriteFiscale;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityRepository;
use App\Services\Canvas\Autocomplete\AutoriteFiscaleAutocompleteCanvasProvider;
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
        private Security $security,
        private AutoriteFiscaleAutocompleteCanvasProvider $canvasProvider
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => AutoriteFiscale::class,
            'placeholder' => "Séléctionnez l'autorité",
            'query_builder' => function (EntityRepository $er): QueryBuilder {
                $user = $this->security->getUser();

                // `connectedTo` n'est défini qu'après l'entrée dans un workspace.
                // Hors workspace (ex. édition d'une entreprise depuis la liste, utilisateur
                // neuf), il peut être null : on évite alors `getId()` sur null en filtrant
                // sur un identifiant inexistant (liste vide, aucune fuite inter-entreprises).
                $entreprise = $user instanceof Utilisateur ? $user->getConnectedTo() : null;

                return $er->createQueryBuilder('autorite')
                    ->leftJoin('autorite.taxe', "taxe")
                    ->where('taxe.entreprise = :eseId')
                    ->setParameter('eseId', $entreprise?->getId() ?? 0)
                    ->orderBy('autorite.id', 'ASC');
            },
            'as_html' => true,
            // La logique de rendu est maintenant déléguée au service dédié.
            'choice_label' => fn(AutoriteFiscale $autorite) => $this->canvasProvider->getChoiceLabel($autorite),
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
