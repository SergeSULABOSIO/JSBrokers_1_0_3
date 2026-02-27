<?php

namespace App\Form;

use App\Entity\Tache;
use App\Entity\Invite;
use App\Entity\Feedback;
use App\Form\DocumentType;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class FeedbackType extends AbstractType
{

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private Security $security
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {

        $builder
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'attr' => [
                    'rows' => 4, // Hauteur du champ de texte
                    'placeholder' => 'Saisissez votre commentaire ici...',
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => "Moyen de contact utilisé",
                'expanded' => true,
                'required' => true,
                'label_html' => true,
                'choices'  => [
                    "Physique" => Feedback::TYPE_PHYSICAL_MEETING,
                    "Appel" => Feedback::TYPE_CALL,
                    "Email" => Feedback::TYPE_EMAIL,
                    "SMS" => Feedback::TYPE_SMS,
                    "Autre" => Feedback::TYPE_UNDEFINED,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    return match ($choice) {
                        Feedback::TYPE_PHYSICAL_MEETING => '<div><strong>Rencontre physique</strong><div class="text-muted small">Rendez-vous en face à face.</div></div>',
                        Feedback::TYPE_CALL => '<div><strong>Appel téléphonique</strong><div class="text-muted small">Discussion vocale.</div></div>',
                        Feedback::TYPE_EMAIL => '<div><strong>E-mail</strong><div class="text-muted small">Échange par courrier électronique.</div></div>',
                        Feedback::TYPE_SMS => '<div><strong>SMS / Messagerie</strong><div class="text-muted small">Message texte rapide.</div></div>',
                        default => '<div><strong>Non défini</strong><div class="text-muted small">Autre moyen de contact.</div></div>',
                    };
                },
            ])
            ->add('hasNextAction', ChoiceType::class, [
                'label' => "Y a-t-il une prochaine action?",
                'help' => "Action consécutive au compte-rendu courant et qui devra être exécutée par la suite.",
                'expanded' => true,
                'required' => true,
                'label_html' => true,
                'choices'  => [
                    "Non" => false,
                    "Oui" => true,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    if ($choice === true) {
                        return '<div><strong>Oui</strong><div class="text-muted small">Une action de suivi est requise.</div></div>';
                    }
                    return '<div><strong>Non</strong><div class="text-muted small">Aucune action immédiate.</div></div>';
                },
            ])
            ->add('nextAction', TextareaType::class, [
                'required' => false,
                'label' => "Prochaine Action",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('nextActionAt', DateTimeType::class, [
                'label' => "Date de la prochaine action",
                'help' => "Date en laquelle la prochaine action devra être exécutée.",
                'required' => false,
                'widget' => 'single_text',
            ])
            // --- AJOUT DE LA COLLECTION DE DOCUMENTS ---
            ->add('documents', CollectionType::class, [
                'label' => 'Documents et pièces jointes',
                'help' => 'Fichiers relatifs à ce feedback.',
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
            'data_class' => Feedback::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
