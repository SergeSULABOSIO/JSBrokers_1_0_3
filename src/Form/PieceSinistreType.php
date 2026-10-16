<?php

namespace App\Form;


use App\Entity\PieceSinistre;
use App\Entity\ModelePieceSinistre;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class PieceSinistreType extends AbstractType
{
    public function __construct(
        private \App\Services\FormListenerFactory $ecouteurFormulaire,
    ) {
    }

    /**
     * Le libellé d'un modèle, rendu en HTML (`label_html`) : le nom et la description sont
     * SAISIS par le cabinet — la description dans l'éditeur riche. Ils n'y entrent donc
     * qu'échappés, la description lue comme du texte (TexteRiche::enTexte).
     */
    public static function libelleDuModele(ModelePieceSinistre $modele): string
    {
        $nom = htmlspecialchars((string) $modele->getNom(), ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars(\App\Service\TexteRiche\TexteRiche::enTexte($modele->getDescription()), ENT_QUOTES, 'UTF-8');

        return '<div><strong>' . $nom . '</strong><div class="text-muted small">' . $description . '</div></div>';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('description', TextType::class, [
                'label' => "Brève description de la pièce",
                'required' => true,
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('fourniPar', TextType::class, [
                'label' => "Pièce fournie par",
                'required' => true,
                'attr' => [
                    'placeholder' => "Source",
                ],
            ])
            ->add('receivedAt', DateType::class, [
                'label' => "Date de recéption",
                'required' => true,
                'widget' => 'single_text',
            ])
            ->add('type', EntityType::class, [
                'label' => "Nature de la pièce",
                'required' => false,
                'class' => ModelePieceSinistre::class,
                // UN SÉLECTEUR N'AFFICHE JAMAIS LES DONNÉES D'UN AUTRE CABINET (règle,
                // 2026-10-15) : sans ce filtre, il listait les modèles de tous les cabinets.
                'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
                'expanded' => true,
                'label_html' => true,
                'choice_label' => [self::class, 'libelleDuModele'],
            ])
            // --- AJOUT DE LA COLLECTION DE DOCUMENTS ---
            ->add('documents', CollectionType::class, [
                'label' => "Documents joints",
                'help' => "Fichiers PDF, images, etc.",
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                'mapped' => false, // On continue avec notre logique API par élément
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PieceSinistre::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
