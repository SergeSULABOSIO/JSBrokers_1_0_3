<?php

namespace App\Form;

use App\Entity\Document;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Vich\UploaderBundle\Form\Type\VichFileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
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
            // RATTACHEMENT UNIVERSEL — cachés à l'écran, mais bien mappés.
            //
            // POURQUOI ILS SONT ICI PLUTÔT QUE POSÉS DIRECTEMENT SUR L'ENTITÉ. Le
            // moteur d'écriture de l'assistant soumet ses champs à CE formulaire :
            // ce qu'il n'expose pas est jeté en silence. Et surtout, l'inventaire
            // des champs connus se dérive du formulaire — un champ Doctrine qui n'y
            // figure pas est traité comme INCONNU par AliasDeChamps, qui le
            // rapproche alors par ressemblance de libellé. C'est très exactement
            // l'incident du 14/08/2026, où « fichier » devenait « nomFichierStocke »
            // et l'upload partait à la poubelle sans un mot. Les déclarer ici est ce
            // qui garantit qu'ils traversent le plan intacts.
            //
            // L'utilisateur ne les voit ni ne les saisit : ils sont posés par le
            // serveur (AttacherFichierTool → PieceSourceRattachement), jamais dictés.
            ->add('cibleType', HiddenType::class, [
                'required' => false,
                'empty_data' => '',
            ])
            ->add('cibleId', HiddenType::class, [
                'required' => false,
            ])
        ;

        // HiddenType ne transporte que du TEXTE, or `cibleId` est un `?int` typé :
        // sans ce transformateur, la soumission lève une TypeError au lieu d'écrire.
        // Le sens inverse (int -> chaîne) est ce qui permet de ré-afficher un
        // document déjà rattaché sans le délier.
        $builder->get('cibleId')->addModelTransformer(new CallbackTransformer(
            static fn (?int $valeur): string => $valeur === null ? '' : (string) $valeur,
            static fn ($valeur): ?int => ($valeur === null || trim((string) $valeur) === '') ? null : (int) $valeur,
        ));
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
