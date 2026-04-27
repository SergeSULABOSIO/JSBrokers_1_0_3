<?php

namespace App\Form;

use App\Entity\Bordereau;
use App\Repository\BordereauRepository;
use App\Entity\Note;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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
            ->add('addressedTo', ChoiceType::class, [
                'label' => "À qui s'adresse cette note ?",
                'required' => true,
                'expanded' => true,
                'label_html' => true,
                'choices'  => [
                    "Le client" => Note::TO_CLIENT,
                    "L'assureur" => Note::TO_ASSUREUR,
                    "L'intermédiaire" => Note::TO_PARTENAIRE,
                    "L'autorité fiscale" => Note::TO_AUTORITE_FISCALE,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    $descriptions = [
                        Note::TO_CLIENT => "Pour facturer une prime, des frais ou un service directement au client.",
                        Note::TO_ASSUREUR => "Pour réclamer une commission ou d'autres frais à la compagnie d'assurance.",
                        Note::TO_PARTENAIRE => "Pour payer une rétro-commission ou facturer des frais à un partenaire.",
                        Note::TO_AUTORITE_FISCALE => "Pour déclarer et payer des taxes collectées (ex: TVA, taxe ARCA)."
                    ];
                    return '<div><strong>' . $key . '</strong><div class="text-muted small">' . ($descriptions[$value] ?? '') . '</div></div>';
                },
                'choice_attr' => function ($choice, $key, $value) {
                    // C'est ici que nous ajoutons les attributs de données pour le ciblage dynamique.
                    return [
                        'data-visibility-target' => 'choice',
                        'data-visibility-debit' => in_array($value, [Note::TO_CLIENT, Note::TO_ASSUREUR]) ? 'true' : 'false',
                        'data-visibility-credit' => in_array($value, [Note::TO_PARTENAIRE, Note::TO_AUTORITE_FISCALE]) ? 'true' : 'false',
                    ];
                },
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
            ->add('bordereau', EntityType::class, [
                'class' => Bordereau::class,
                'choice_label' => 'nom',
                'placeholder' => 'Sélectionnez le bordereau de production',
                'required' => true,
                'label' => 'Lier à un bordereau de production',
                'help' => "Lie cette note à un bordereau pour une meilleure traçabilité.",
                'query_builder' => function (BordereauRepository $br) use ($options) {
                    /** @var Note|null $note */
                    $note = $options['data'] ?? null;
                    $assureurId = $note && $note->getAssureur() ? $note->getAssureur()->getId() : null;

                    $qb = $br->createQueryBuilder('b');

                    if ($assureurId) {
                        return $qb->where('b.assureur = :assureurId')->setParameter('assureurId', $assureurId);
                    }
                    return $qb->where('1 = 0'); // Ne montre aucun bordereau si aucun assureur n'est sélectionné
                },
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