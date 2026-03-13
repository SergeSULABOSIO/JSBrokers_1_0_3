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
        private RequestStack $requestStack // Indispensable pour lire le parent_id dans l'URL
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // 1. Récupération robuste de l'ID de la Note parente
        $request = $this->requestStack->getCurrentRequest();
        $article = $builder->getData();
        $noteId = null;

        if ($article && $article->getNote()) {
            // Mode Édition : l'Article existe déjà en base, il connaît sa Note.
            $noteId = $article->getNote()->getId();
        } elseif ($request && $request->query->has('parent_id')) {
            // Mode Création : l'ID vient de ton dialog-instance_controller.js
            $noteId = $request->query->get('parent_id');
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
                'attr' => [
                    // On connecte le contrôleur Stimulus au champ Autocomplete
                    'data-controller' => 'revenu-autocomplete-filter',
                    // On transmet l'ID de la note au script Javascript
                    'data-revenu-autocomplete-filter-note-id-value' => $noteId
                ]
            ])
            ->add('tranche', TrancheAutocompleteField::class, [
                'label' => "Lié à une Tranche",
                'required' => false,
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
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}