<?php

namespace App\Form;

use App\Entity\Tache;
use App\Form\DocumentType;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class TacheType extends AbstractType
{

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private Security $security
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('toBeEndedAt', DateType::class, [
                'label' => "Echéance",
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('closed', ChoiceType::class, [
                'label' => "Statut de la tâche",
                'expanded' => true,
                'label_html' => true,
                'required' => true,
                'choices'  => [
                    "En cours" => false,
                    "Terminée" => true,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    if ($choice === true) {
                        return '<div><strong>Terminée</strong><div class="text-muted small">La tâche a été réalisée et clôturée.</div></div>';
                    }
                    return '<div><strong>En cours</strong><div class="text-muted small">La tâche est toujours en attente de réalisation.</div></div>';
                },
            ])
            ->add('executor', InviteAutocompleteField::class, [
                'label' => "Assignée à",
                'required' => true,
                'placeholder' => 'Chercher un utilisateur...',
            ])
            ->add('feedbacks', CollectionType::class, [
                'label' => "Compte-rendu",
                'entry_type' => FeedbackType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                'mapped' => false,
            ])
            ->add('documents', CollectionType::class, [
                'label' => 'Documents et pièces jointes',
                'help' => 'Fichiers relatifs à l\'exécution de cette tâche.',
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
            'data_class' => Tache::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
