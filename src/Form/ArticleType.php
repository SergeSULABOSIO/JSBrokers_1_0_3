<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Article;
use App\Services\FormListenerFactory;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Formulaire Article refactorisé pour Symfony 7.1.5.
 * - Gestion de l'affichage en cascade (Revenu -> Tranche -> Quantité/Montant).
 * - Suppression des champs 'nom' et 'taxeFacturee'.
 * - Calcul automatique du montant basé sur la quantité.
 */
class ArticleType extends AbstractType
{
    public function __construct(
        private readonly FormListenerFactory $ecouteurFormulaire,
        private readonly ServiceMonnaies $serviceMonnaies,
        private readonly RequestStack $requestStack
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $request = $this->requestStack->getCurrentRequest();
        /** @var Article|null $article */
        $article = $builder->getData();
        
        $noteId = null;
        $revenuIdInitial = null;
        $trancheIdInitial = null;
        
        // On détermine si nous sommes en mode création
        $isCreationMode = !$article || !$article->getId();

        if ($article) {
            $noteId = $article->getNote()?->getId();
            $revenuIdInitial = $article->getRevenuFacture()?->getId();
            $trancheIdInitial = $article->getTranche()?->getId();
        } elseif ($request?->query->has('parent_id')) {
            $noteId = (int)$request->query->get('parent_id');
        }

        // 'mb-3' est la classe de base pour respecter le design défini dans app.css
        $baseRowClass = 'mb-3';

        $builder
            // 1. REVENU : Toujours visible au chargement.
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

            // 2. TRANCHE : Masquée initialement, apparaît après le choix du Revenu.
            ->add('tranche', TrancheAutocompleteField::class, [
                'label' => "Lié à une Tranche",
                'required' => false,
                'revenu_id' => $revenuIdInitial,
                'row_attr' => [
                    'class' => sprintf('%s tranche-form-row %s', $baseRowClass, ($isCreationMode && !$revenuIdInitial ? 'd-none' : ''))
                ],
                'attr' => [
                    'data-controller' => 'tranche-autocomplete-filter',
                    'data-action' => 'change->tranche-autocomplete-filter#handleTrancheChange'
                ]
            ])

            // 3. QUANTITÉ : Masquée initialement, apparaît après le choix de la Tranche.
            ->add('quantite', NumberType::class, [
                'label' => "Quantité",
                'html5' => true,
                // Valeur par défaut de 1.0 en création
                'data' => $article?->getQuantite() ?? 1.0,
                'attr' => [
                    'placeholder' => "1.00",
                    'step' => '0.01',
                    'data-action' => 'input->tranche-autocomplete-filter#calculateTotal'
                ],
                'row_attr' => [
                    'class' => sprintf('%s quantite-form-row %s', $baseRowClass, ($isCreationMode && !$trancheIdInitial ? 'd-none' : ''))
                ],
            ])

            // 4. MONTANT : Masqué initialement, apparaît après le choix de la Tranche.
            ->add('montant', MoneyType::class, [
                'label' => "Montant Total (TTC)",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'attr' => [
                    'placeholder' => "0.00",
                    'data-action' => 'change->tranche-autocomplete-filter#updateUnitPrice'
                ],
                'row_attr' => [
                    'class' => sprintf('%s montant-form-row %s', $baseRowClass, ($isCreationMode && !$trancheIdInitial ? 'd-none' : ''))
                ],
            ]);
            
            // Note: Le champ 'nom' et le champ 'taxeFacturee' ont été supprimés.
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