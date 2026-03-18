<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Article;
use App\Services\FormListenerFactory;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Formulaire Article refactorisé pour Symfony 7.1.5.
 * - Gestion de l'affichage en cascade (Revenu -> Tranche -> Quantité/Montant).
 * - Suppression des champs 'nom' et 'taxeFacturee'.
 * - Calcul automatique complet : Revenu TTC * Quantité * Taux.
 * - Libellés optimisés pour une meilleure expérience utilisateur (UX).
 */
class ArticleType extends AbstractType
{
    public function __construct(
        private readonly FormListenerFactory $ecouteurFormulaire,
        private readonly ServiceMonnaies $serviceMonnaies,
        private readonly RequestStack $requestStack
    ) {}

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

        // 1. REVENU : Toujours visible au chargement.
        $builder
            ->add('revenuFacture', RevenuPourCourtierAutocompleteField::class, [
                'label' => "Revenu / Commission source à facturer",
                'required' => false,
                'note_id' => $noteId,
                'row_attr' => ['class' => $baseRowClass],
                'attr' => [
                    'data-controller' => 'revenu-autocomplete-filter',
                    'data-revenu-autocomplete-filter-note-id-value' => $noteId
                ]
            ]); // Ajout d'un écouteur PRE_SUBMIT pour ajuster dynamiquement les champs 'tranche' et 'quantite'
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($baseRowClass, $isCreationMode) {
            $data = $event->getData();
            $form = $event->getForm();

            $revenuFactureId = $data['revenuFacture'] ?? null;

            // Re-ajout du champ 'tranche' avec l'ID du revenu soumis
            $form->add('tranche', TrancheAutocompleteField::class, [
                'label' => "Tranche de prime correspondante",
                'required' => false,
                'revenu_id' => $revenuFactureId, // Utilise l'ID du revenu soumis pour le query_builder
                'row_attr' => ['class' => sprintf('%s tranche-form-row %s', $baseRowClass, ($isCreationMode && !$revenuFactureId ? 'd-none' : ''))],
                'attr' => ['data-controller' => 'tranche-autocomplete-filter']
            ]);

            // Re-ajout du champ 'quantite'. Sa visibilité est gérée par le CanvasProvider, mais il doit faire partie du formulaire.
            $form->add('quantite', NumberType::class, [
                'label' => "Quantité (nombre d'unités)",
                'html5' => true,
                'data' => 1.0, // Valeur par défaut
                'attr' => ['placeholder' => "1.00", 'step' => '0.01'],
                'row_attr' => ['class' => sprintf('%s quantite-form-row', $baseRowClass)]
            ]);
        });

        // Ajout initial des champs 'tranche' et 'quantite' pour le premier rendu du formulaire (avant toute soumission).
        // Ces champs seront remplacés par l'écouteur PRE_SUBMIT si le formulaire est soumis.
        $builder
            ->add('tranche', TrancheAutocompleteField::class, [
                'label' => "Tranche de prime correspondante",
                'required' => false,
                'revenu_id' => $revenuIdInitial, // ID du revenu initial
                'row_attr' => [
                    'class' => sprintf('%s tranche-form-row %s', $baseRowClass, ($isCreationMode && !$revenuIdInitial ? 'd-none' : ''))
                ],
                // Le contrôleur est monté ici. Il écoutera la tranche, mais aussi les champs frères.
                'attr' => [
                    'data-controller' => 'tranche-autocomplete-filter'
                ]
            ])
            
            ->add('quantite', NumberType::class, [
                'label' => "Quantité (nombre d'unités)",
                'html5' => true,
                'data' => 1.0,
                'attr' => [
                    'placeholder' => "1.00",
                    'step' => '0.01'
                ],
                'row_attr' => [
                    'class' => sprintf('%s quantite-form-row', $baseRowClass) // La visibilité est gérée par le CanvasProvider
                ],
            ]);
        // Le champ idPoste n'est plus nécessaire et a été supprimé de l'entité Article.
        // Il n'est donc plus ajouté au formulaire.
    }
}