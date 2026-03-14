<?php

namespace App\Form;

use App\Entity\Article;
use App\Services\FormListenerFactory;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
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
        $trancheIdInitial = null;
        
        // Détection du mode création pour masquer les champs par défaut
        $isCreationMode = !$article || !$article->getId();

        if ($article) {
            if ($article->getNote()) {
                $noteId = $article->getNote()->getId();
            }
            if ($article->getRevenuFacture()) {
                $revenuIdInitial = $article->getRevenuFacture()->getId();
            }
            if ($article->getTranche()) {
                $trancheIdInitial = $article->getTranche()->getId();
            }
        } elseif ($request && $request->query->has('parent_id')) {
            $noteId = $request->query->get('parent_id');
        }

        // mb-3 est crucial pour le design défini dans app.css
        $baseRowClass = 'mb-3';

        $builder
            // 1. REVENU (Toujours visible au chargement)
            ->add('revenuFacture', RevenuPourCourtierAutocompleteField::class, [
                'label' => "Lié à un Revenu/Commission",
                'required' => false,
                'note_id' => $noteId,
                'row_attr' => ['class' => $baseRowClass],
                'attr' => [
                    'data-controller' => 'revenu-autocomplete-filter',
                    'data-revenu-autocomplete-filter-note-id-value' => $noteId
                ]
            ])
            // 2. TRANCHE (Apparaît après le choix du Revenu)
            ->add('tranche', TrancheAutocompleteField::class, [
                'label' => "Lié à une Tranche",
                'required' => false,
                'revenu_id' => $revenuIdInitial,
                'row_attr' => [
                    'class' => $baseRowClass . ' tranche-form-row' . ($isCreationMode && !$revenuIdInitial ? ' d-none' : '')
                ],
                'attr' => [
                    'data-controller' => 'tranche-autocomplete-filter'
                ]
            ])
            // 3. QUANTITÉ (Nouveau champ - Apparaît après le choix de la Tranche)
            ->add('quantite', NumberType::class, [
                'label' => "Quantité",
                'html5' => true,
                'scale' => 2,
                'attr' => [
                    'placeholder' => "0.00",
                    'step' => '0.01',
                ],
                'row_attr' => [
                    'class' => $baseRowClass . ' quantite-form-row' . ($isCreationMode && !$trancheIdInitial ? ' d-none' : '')
                ],
            ])
            // 4. MONTANT (Apparaît après le choix de la Tranche)
            ->add('montant', MoneyType::class, [
                'label' => "Montant (TTC)",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'attr' => ['placeholder' => "0.00"],
                'row_attr' => [
                    'class' => $baseRowClass . ' montant-form-row' . ($isCreationMode && !$trancheIdInitial ? ' d-none' : '')
                ],
            ])
            // 5. TAXE (Apparaît après le choix de la Tranche)
            ->add('taxeFacturee', TaxeAutocompleteField::class, [
                'label' => "Lié à une Taxe",
                'required' => false,
                'row_attr' => [
                    'class' => $baseRowClass . ' taxe-form-row' . ($isCreationMode && !$trancheIdInitial ? ' d-none' : '')
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