<?php

namespace App\Form;

use App\DTO\DemandeContactDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class DemandeContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => "ContactForm.name",
                'empty_data' => ''
            ])
            ->add('email', EmailType::class, [
                'label' => "ContactForm.email",
                'empty_data' => ''
            ])
            // Objet du message. Les libellés (clés de la liste) sont traduits pour
            // l'affichage ; la valeur stockée reste un libellé lisible repris tel quel
            // dans les e-mails (objet du mail + corps).
            ->add('objet', ChoiceType::class, [
                'label' => "ContactForm.subject",
                'placeholder' => "ContactForm.subject_placeholder",
                'choices' => [
                    "ContactForm.subject_appointment"  => "Demande de rendez-vous",
                    "ContactForm.subject_question"     => "Question",
                    "ContactForm.subject_information"   => "Demande de renseignement",
                    "ContactForm.subject_other"        => "Autres (à préciser)",
                ],
            ])
            ->add('message', TextareaType::class, [
                'label' => "ContactForm.message",
                'empty_data' => ''
            ])
            // Téléphone facultatif : le visiteur l'active volontairement via la case.
            // Les deux champs sont non requis côté formulaire ; la validation du
            // numéro est conditionnelle (Assert\When sur wantsPhone) dans le DTO.
            ->add('wantsPhone', CheckboxType::class, [
                'label' => "ContactForm.phone_consent",
                'required' => false,
            ])
            ->add('phone', TelType::class, [
                'label' => "ContactForm.phone",
                'required' => false,
                'empty_data' => '',
            ])
            // Le bouton de soumission est fourni par chaque template (pour le styliser
            // et y intégrer une icône) ; il n'est donc pas déclaré dans le type.
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DemandeContactDTO::class,
        ]);
    }
}
