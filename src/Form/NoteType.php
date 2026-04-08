<?php

namespace App\Form;

use App\Entity\Note;
use App\Services\FormListenerFactory;
use Doctrine\DBAL\Types\BooleanType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class NoteType extends AbstractType
{
    public function __construct(
        private readonly FormListenerFactory $ecouteurFormulaire
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {

        $builder
            ->add('nom', TextType::class, [
                'label' => "Objet de la note",
                'attr' => [
                    'placeholder' => "Ex: Commission sur police #123",
                ],
            ])
            ->add('reference', TextType::class, [
                'required' => false,
                'disabled' => true,
                'label' => "Référence",
                'attr' => [
                    'placeholder' => "Générée automatiquement",
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => "Description détaillée",
                'required' => false,
                'attr' => [
                    'placeholder' => "Ajoutez des détails concernant cette note...",
                    'class' => 'editeur-riche'
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => "Type de note",
                'required' => true,
                'expanded' => true,
                'label_html' => true,
                'choices'  => [
                    "Débit" => Note::TYPE_NOTE_DE_DEBIT,
                    "Crédit" => Note::TYPE_NOTE_DE_CREDIT,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    if ($value === Note::TYPE_NOTE_DE_DEBIT) {
                        return '<div><strong>' . $key . '</strong><div class="text-muted small">Une facture envoyée pour réclamer un paiement (ex: commission, frais).</div></div>';
                    }
                    return '<div><strong>' . $key . '</strong><div class="text-muted small">Un avoir envoyé pour annuler ou rembourser une facture précédente.</div></div>';
                },
            ])
            // Le champ 'addressedTo' est conservé pour la logique métier mais masqué.
            ->add('addressedTo', ChoiceType::class, ['row_attr' => ['class' => 'd-none']])

            // --- NOUVEAU : Champs booléens pour chaque option de destinataire ---
            ->add('addressedToClient', BooleanType::class, [
                'label' => 'Le client',
                'help' => 'Pour facturer une prime, des frais ou un service directement au client.',
                'mapped' => false,
                'required' => false,
            ])
            ->add('addressedToAssureur', BooleanType::class, [
                'label' => "L'assureur",
                'help' => "Pour réclamer une commission ou d'autres frais à la compagnie d'assurance.",
                'mapped' => false,
                'required' => false,
            ])
            ->add('addressedToPartenaire', BooleanType::class, [
                'label' => "L'intermédiaire",
                'help' => "Pour payer une rétro-commission ou facturer des frais à un partenaire.",
                'mapped' => false,
                'required' => false,
            ])
            ->add('addressedToAutoriteFiscale', BooleanType::class, [
                'label' => "L'autorité fiscale",
                'help' => "Pour déclarer et payer des taxes collectées (ex: TVA, taxe ARCA).",
                'mapped' => false,
                'required' => false,
            ])
            ->add('client', ClientAutocompleteField::class, [
                'label' => "Client ciblé",
                'required' => false,
            ])
            ->add('assureur', AssureurAutocompleteField::class, [
                'label' => "Assureur ciblé",
                'required' => false,
            ])
            ->add('partenaire', PartenaireAutocompleteField::class, [
                'label' => "Intermédiaire / Partenaire ciblé",
                'required' => false,
            ])
            ->add('autoritefiscale', AutoriteFiscaleAutocompleteField::class, [
                'label' => "Autorité fiscale ciblée",
                'required' => false,
            ])
            ->add('comptes', CompteBancaireAutocompleteField::class, [
                'label' => "Comptes bancaires",
                'help' => "Comptes bancaires auxquels vous désirez vous faire payer.",
                'required' => false,
                'multiple' => true,
            ])
            ->add('articles', CollectionType::class, [
                'label' => "Articles de la note",
                'entry_type' => ArticleType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            ->add('paiements', CollectionType::class, [
                'label' => "Paiements liés",
                'help' => "Les paiements relatifs à cette note.",
                'entry_type' => PaiementType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            ->add('signedBy', TextType::class, [
                'label' => "Signataire",
                'required' => false,
                'attr' => [
                    'placeholder' => "Nom du signataire",
                ],
            ])
            ->add('titleSignedBy', TextType::class, [
                'label' => "Titre du signataire",
                'required' => false,
                'attr' => [
                    'placeholder' => "Ex: Directeur Général",
                ],
            ])
            ->add('sentAt', DateTimeType::class, [
                'label' => "Date de soumission",
                'required' => false,
                'widget' => 'single_text',
            ]);

        // --- NOUVEAU : Logique pour synchroniser les champs booléens avec le champ 'addressedTo' ---
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            /** @var Note|null $note */
            $note = $event->getData();
            $form = $event->getForm();

            if ($note && $note->getAddressedTo() !== null) {
                if ($note->getAddressedTo() === Note::TO_CLIENT) $form->get('addressedToClient')->setData(true);
                if ($note->getAddressedTo() === Note::TO_ASSUREUR) $form->get('addressedToAssureur')->setData(true);
                if ($note->getAddressedTo() === Note::TO_PARTENAIRE) $form->get('addressedToPartenaire')->setData(true);
                if ($note->getAddressedTo() === Note::TO_AUTORITE_FISCALE) $form->get('addressedToAutoriteFiscale')->setData(true);
            }
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $form = $event->getForm();

            // On détermine la valeur de 'addressedTo' en fonction de la case qui a été cochée.
            // Le système de layout garantit qu'une seule peut être cochée à la fois.
            $addressedToValue = null;
            if (!empty($data['addressedToClient'])) $addressedToValue = Note::TO_CLIENT;
            elseif (!empty($data['addressedToAssureur'])) $addressedToValue = Note::TO_ASSUREUR;
            elseif (!empty($data['addressedToPartenaire'])) $addressedToValue = Note::TO_PARTENAIRE;
            elseif (!empty($data['addressedToAutoriteFiscale'])) $addressedToValue = Note::TO_AUTORITE_FISCALE;

            // On met à jour la donnée qui sera soumise au vrai champ 'addressedTo'.
            $data['addressedTo'] = $addressedToValue;

            // On remet les données à jour pour la soumission.
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Note::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}