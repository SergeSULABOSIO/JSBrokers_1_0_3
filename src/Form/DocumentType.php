<?php

namespace App\Form;

use App\Entity\Piste;
use App\Entity\Tache;
use App\Entity\Client;
use App\Entity\Invite;
use App\Entity\Avenant;
use App\Entity\Classeur;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Feedback;
use App\Entity\Paiement;
use App\Entity\Bordereau;
use App\Entity\Entreprise;
use App\Entity\Partenaire;
use App\Entity\PieceSinistre;
use App\Entity\CompteBancaire;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use App\Entity\OffreIndemnisationSinistre;
use Vich\UploaderBundle\Form\Type\VichFileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class DocumentType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private UrlGeneratorInterface $urlGenerator
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
            ->add('fichier', VichFileType::class, [
                'label' => 'Fichier à uploader',
                // MODIFICATION 1 : Le champ n'est plus obligatoire si un fichier existe déjà.
                'required' => !$hasFile,
                'allow_delete' => false,
                // MODIFICATION 2 : On configure l'affichage du fichier existant.
                // Affiche le nom du fichier stocké comme libellé du lien.
                'download_label' => $hasFile ? $document->getNomFichierStocke() : false,
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
