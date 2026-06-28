<?php

namespace App\Form;

use App\Entity\Crm\CrmTicketFeedback;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Formulaire d'un feedback de ticket, sur le pattern Console (champ à icône).
 */
class CrmTicketFeedbackType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('contenu', TextareaType::class, [
            'label'       => 'Votre message',
            'constraints' => [new NotBlank(message: 'Le message ne peut pas être vide.')],
            'attr'        => [
                'placeholder' => 'Saisissez votre note ou votre suivi sur ce ticket…',
                'rows'        => 5,
                'data-icon'   => 'feedback',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CrmTicketFeedback::class]);
    }
}
