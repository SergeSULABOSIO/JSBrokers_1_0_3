<?php

namespace App\Form;

use App\Entity\Document;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Vich\UploaderBundle\Form\Type\VichFileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class DocumentType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private UrlGeneratorInterface $urlGenerator,
        // Le nom LISIBLE du fichier (le nom stocké est aléatoire, chantier 18).
        private \App\Service\Document\DocumentFichier $documentFichier,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Document|null $document */
        $document = $options['data'] ?? null;
        $hasFile = $document && $document->getNomFichierStocke();

        $builder
            ->add('nom', TextType::class, [
                'label' => "nom",
                'attr' => [
                    'placeholder' => "nom",
                ],
            ])
            ->add('classeur', ClasseurAutocompleteField::class, [
                'label' => "Classeur",
                'required' => false,
            ])
            // PARTAGÉE AVEC LE CLIENT (chantier 18, option (c) de Serge) : décochée par défaut.
            // false_values explicite : le moteur de Ket normalise les booléens en '1'/'0', et
            // CheckboxType tient par défaut toute valeur non nulle pour vraie — '0' PARTAGERAIT.
            ->add('partageeAvecLeClient', \Symfony\Component\Form\Extension\Core\Type\CheckboxType::class, [
                'label' => "Partagée avec le client",
                'help' => "Le client pourra la consulter et la télécharger depuis son relevé de compte en ligne. Décochée : la pièce reste interne au cabinet.",
                'required' => false,
                'false_values' => [null, '0', 'false', ''],
            ])
            ->add('fichier', VichFileType::class, [
                'label' => 'Fichier à uploader',
                // MODIFICATION 1 : Le champ n'est plus obligatoire si un fichier existe déjà.
                'required' => !$hasFile,
                'allow_delete' => false,
                // MODIFICATION 2 : On configure l'affichage du fichier existant.
                // Affiche le nom du fichier stocké comme libellé du lien.
                'download_label' => $hasFile ? $this->documentFichier->nomDeTelechargement($document) : false,
                // Génère l'URL pour que l'utilisateur puisse cliquer et télécharger le fichier.
                'download_uri' => $hasFile ? $this->urlGenerator->generate('admin.document.api.download', ['id' => $document->getId()]) : false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
