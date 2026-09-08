<?php

namespace App\Form;

use Symfony\Component\Form\Extension\Core\Type\CollectionType;

use App\Entity\Assureur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

class AssureurType extends AbstractType
{
    /**
     * UN ASSUREUR S'INSCRIT SOUS SON NOM, le reste se complète ensuite.
     *
     * Tous les champs sauf « nom » sont facultatifs — en parité EXACTE avec les colonnes,
     * désormais nullables (cf. le commentaire de Assureur::$email). Laisser `required` à
     * son défaut ici n'aurait tenu que la moitié de la promesse : l'assistant, qui lit la
     * nullabilité Doctrine, aurait accepté une création à quatre champs vides que l'écran,
     * lui, aurait refusée au premier `required` du navigateur. Deux vérités pour une même
     * fiche, c'est exactement ce que ChampsObligatoiresInspector existe pour empêcher.
     *
     * LES LIBELLÉS SONT AUSSI DES NOMS DE CHAMPS, et c'est pour cela qu'on répare ici deux
     * coquilles anciennes (« Nunméro Impôt », « Nunméro RCCM »). AliasDeChamps rattache un
     * champ dicté par l'assistant au champ dont le LIBELLÉ porte les mêmes mots :
     * « numeroImpot » se ramène à « Numéro d'impôt », jamais à « Nunméro Impôt ». La faute
     * de frappe coupait ce rattachement — la valeur dictée était écartée en silence, puis
     * le champ réclamé à l'utilisateur qui venait de le donner.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('telephone', TextType::class, [
                'label' => "Téléphone",
                'required' => false,
                'attr' => [
                    'placeholder' => "Téléphone",
                ],
            ])
            ->add('numimpot', TextType::class, [
                'label' => "Numéro d'impôt (NIF)",
                'required' => false,
                'attr' => [
                    'placeholder' => "NIF",
                ],
            ])
            ->add('rccm', TextType::class, [
                'label' => "Numéro RCCM",
                'required' => false,
                'attr' => [
                    'placeholder' => "RCCM",
                ],
            ])
            ->add('idnat', TextType::class, [
                'label' => "Identification nationale (IDNAT)",
                'required' => false,
                'attr' => [
                    'placeholder' => "Idnat",
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => "Email",
                'required' => false,
                'attr' => [
                    'placeholder' => "Email",
                ],
            ])
            ->add('url', UrlType::class, [
                'label' => "Site Internet",
                'required' => false,
                'attr' => [
                    'placeholder' => "Site Internet",
                ],
            ])
            ->add('adressePhysique', TextType::class, [
                'label' => "Adresse Physique",
                'required' => false,
                'attr' => [
                    'placeholder' => "Adresse Physique",
                ],
            ])
            // PIÈCES JOINTES de cette fiche. `mapped: false` comme les onze autres
            // collections de documents du projet : chaque pièce est créée, modifiée et
            // supprimée par son propre dialogue, via l'API de Document — le formulaire
            // parent ne fait que porter le widget.
            ->add('documents', CollectionType::class, [
                'label' => "Documents",
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'required' => false,
                'entry_options' => ['label' => false],
                'mapped' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Assureur::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
