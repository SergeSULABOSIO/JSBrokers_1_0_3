<?php

namespace App\Form;

use App\Entity\Tache;
use App\Entity\Invite;
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
                'label' => "La tâche est-elle accomplie?",
                'expanded' => false,
                'required' => true,
                'choices'  => [
                    "Oui" => true,
                    "Pas encore." => false,
                ],
            ])
            ->add('executor', InviteAutocompleteField::class, [
                'label' => "Assignée à",
                'required' => true,
                'class' => Invite::class,
                'placeholder' => 'Chercher un utilisateur...',
                'choice_label' => 'nom',
            ])
            ->add('feedbacks', CollectionType::class, [
                'label' => "tache_form_label_feedbacks",
                'entry_type' => FeedbackType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                'mapped' => false,
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
