<?php

namespace App\Form;

use App\Entity\Tranche;
use App\Entity\RevenuPourCourtier;
use App\Services\FormListenerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Services\Canvas\Autocomplete\TrancheAutocompleteCanvasProvider;

#[AsEntityAutocompleteField]
class TrancheAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private RequestStack $requestStack,
        private EntityManagerInterface $em,
        private TrancheAutocompleteCanvasProvider $canvasProvider,
    ) {}

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Tranche::class,
            'placeholder' => 'Sélectionner une tranche à facturer',
            'revenu_id' => null, // Injecté depuis ArticleType pour le mode édition
            'searchable_fields' => ['nom'],
            'as_html' => true,
            'choice_label' => fn (Tranche $tranche) => $this->canvasProvider->getChoiceLabel($tranche),



            // ON NE FILTRE PAS PAR REVENU, MAIS ON FILTRE PAR CABINET.
            //
            // Le QueryBuilder doit pouvoir retrouver n'importe quelle tranche par son ID
            // à la soumission : c'est pourquoi il ne restreint pas à la cotation d'un
            // revenu — ce tri-là se fait à l'affichage, par l'URL d'autocomplétion qui
            // reçoit 'live_revenu_id'.
            //
            // ⚠ CETTE RAISON NE VALAIT QUE POUR LE REVENU. Faute de l'avoir dit, le
            // QueryBuilder est resté NU : l'endpoint /autocomplete/tranche_autocomplete_field
            // listait, et laissait sélectionner, les tranches de TOUS les cabinets. Le
            // besoin de résoudre un identifiant à la soumission s'arrête au cabinet de
            // l'utilisateur ; au-delà, il n'y a plus de besoin, seulement une fuite.
            //
            // setFiltreEntreprise() est fail-closed : sans identité (ligne de commande,
            // FormTreeInspector qui monte le formulaire pour en lire l'arborescence,
            // worker de Ket), il filtre sur l'entreprise -1 et rend une liste vide.
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
        ]);
    }


    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}