<?php

namespace App\Form;

use App\Entity\Article;
use App\Services\FormListenerFactory;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\HttpFoundation\RequestStack;

class ArticleType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies,
        private RequestStack $requestStack
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $request = $this->requestStack->getCurrentRequest();
        /** @var Article|null $article */
        $article = $builder->getData();
        $noteId = null;
        $revenuIdInitial = null;
        $isCreationMode = !$article || !$article->getId();

        if ($article) {
            if ($article->getNote()) {
                $noteId = $article->getNote()->getId();
            }
            if ($article->getRevenuFacture()) {
                $revenuIdInitial = $article->getRevenuFacture()->getId();
            }
        } elseif ($request && $request->query->has('parent_id')) {
            $noteId = $request->query->get('parent_id');
        }

        $trancheRowAttrs = [];
        if ($isCreationMode) {
            $trancheRowAttrs['class'] = 'd-none';
        }

        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Description de la ligne",
                ],
            ])
            ->add('revenuFacture', RevenuPourCourtierAutocompleteField::class, [
                'label' => "Lié à un Revenu/Commission",
                'required' => false,
                'note_id' => $noteId,
                'attr' => [
                    'data-controller' => 'revenu-autocomplete-filter',
                    'data-revenu-autocomplete-filter-note-id-value' => $noteId
                ]
            ])
            ->add('tranche', TrancheAutocompleteField::class, [
                'label' => "Lié à une Tranche",
                'required' => false,
                'revenu_id' => $revenuIdInitial,
                'row_attr' => $trancheRowAttrs,
                'attr' => [
                    // Branchement du super-contrôleur spécifique à la Tranche
                    'data-controller' => 'tranche-autocomplete-filter'
                ]
            ])
            ->add('taxeFacturee', TaxeAutocompleteField::class, [
                'label' => "Lié à une Taxe",
                'required' => false,
            ])
            ->add('montant', MoneyType::class, [
                'label' => "Montant (TTC)",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'disabled' => false,
                'attr' => [
                    'placeholder' => "Montant payable",
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Article::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
            // (Suppression du contrôleur global pour éviter les conflits)
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}