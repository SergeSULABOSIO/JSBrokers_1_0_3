<?php

namespace App\Form;

use Symfony\Component\Form\Extension\Core\Type\CollectionType;

use App\Entity\Chargement;
use App\Entity\TypeRevenu;
use App\Services\ServiceMonnaies;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;

class TypeRevenuType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies
    ) {}
    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'required' => false,
                'attr' => [
                    'placeholder' => "Nom ici",
                ],
            ])
            ->add('modeCalcul', ChoiceType::class, [
                'label' => "Mode de calcul",
                'choices'  => [
                    "Pourcentage d'un chargement" => TypeRevenu::MODE_CALCUL_POURCENTAGE_CHARGEMENT,
                    "Un montant fixe à préciser" => TypeRevenu::MODE_CALCUL_MONTANT_FLAT,
                ],
                'expanded' => true,
                'required' => false,
                'label_html' => true,
                'choice_label' => function ($choice, $key, $value) {
                    $labels = [
                        TypeRevenu::MODE_CALCUL_POURCENTAGE_CHARGEMENT => '<div><strong>Pourcentage d\'un chargement</strong><div class="text-muted small">Le revenu est un pourcentage d\'un composant de la prime (ex: prime nette).</div></div>',
                        TypeRevenu::MODE_CALCUL_MONTANT_FLAT => '<div><strong>Montant fixe</strong><div class="text-muted small">Le revenu est un montant forfaitaire prédéfini.</div></div>',
                    ];
                    return $labels[$value] ?? '<div><strong>' . $key . '</strong></div>';
                },
            ])
            // L'ASSIETTE D'UNE COMMISSION N'EST JAMAIS UNE TAXE (Art. 2 du barème ARCA : prime
            // nette d'impôts et taxes ; décision du 2026-10-15). Et seulement les chargements DU
            // CABINET : sans filtre, ce sélecteur listait ceux de tous les cabinets. Ket et la
            // reprise soumettent ce même formulaire ; la garde serveur est dans
            // ChampsObligatoiresInspector::incoherencesMetier.
            ->add('typeChargement', EntityType::class, [
                'label' => "Chargement cible",
                'required' => true,
                'class' => Chargement::class,
                'choice_label' => 'nom',
                'query_builder' => fn (\Doctrine\ORM\EntityRepository $er): \Doctrine\ORM\QueryBuilder
                    => ($this->ecouteurFormulaire->setFiltreEntreprise())($er)
                        ->andWhere('e.fonction IS NULL OR e.fonction <> :taxe')
                        ->setParameter('taxe', Chargement::FONCTION_TAXE),
                // ⚠ UN CHOIX EXPLICITE, JAMAIS UNE BASCULE SILENCIEUSE. Un type d'avant ce
                // filtre peut viser une taxe, désormais absente de la liste : sans option
                // vide, le navigateur présélectionnait la première option, et enregistrer un
                // autre champ changeait en silence la base de la commission. Vide, le champ
                // obligatoire oblige à la choisir.
                'placeholder' => 'Choisissez la composante de la prime…',
                'help' => 'La composante de la prime sur laquelle ce revenu se calcule (en général la prime nette). Une taxe (TVA, ARCA) ne peut pas servir de base.',
            ])
            // ⚠ CE LIBELLÉ PROMETTAIT L'INVERSE DU CALCUL (« Privilégier le taux du risque…
            // appliqué en priorité ») : la facturation a toujours placé le pourcentage et le
            // forfait du TYPE avant le taux du risque. L'ordre facturé fait foi (décision du
            // 2026-10-15, TarifDuRevenu) ; le libellé le dit désormais.
            ->add('appliquerPourcentageDuRisque', ChoiceType::class, [
                'required' => false,
                'label' => "À défaut de pourcentage ou de forfait sur ce type, appliquer le taux de commission du risque ?",
                'expanded' => true,
                'choices'  => [
                    "Oui, quand ce type ne porte ni pourcentage ni forfait." => true,
                    "Non, pas du tout." => false,
                ],
                'label_html' => true,
                'choice_label' => function ($choice, $key, $value) {
                    if ($choice === true) {
                        return '<div><strong>Oui</strong><div class="text-muted small">Sans pourcentage ni forfait sur ce type, la commission suit le taux configuré pour le risque de l\'affaire. Un pourcentage ou un forfait du type l\'emporte toujours.</div></div>';
                    }
                    return '<div><strong>Non</strong><div class="text-muted small">Seuls le pourcentage ou le forfait de ce type s\'appliquent.</div></div>';
                },
            ])
            ->add('pourcentage', PercentType::class, [
                'label' => "Pourcentage",
                'required' => false,
                'help' => "On applique un %",
                // Stockage en POINTS (5 = 5 %), pas en fraction. Calculs via TypeRevenu::getFraction().
                'type' => 'integer',
                'scale' => 3,
                'attr' => [
                    'placeholder' => "Pourcentage",
                ],
            ])
            ->add('montantflat', MoneyType::class, [
                'label' => "Montant fixe",
                'help' => "On considère un montant fixe",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'required' => false,
                'attr' => [
                    'placeholder' => "Montant fixe",
                ],
            ])
            ->add('shared', ChoiceType::class, [
                'label' => "Est-il partageable avec un partenaire?",
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "Oui, si celui-ci existe." => true,
                    "Non, pas du tout." => false,
                ],
                'label_html' => true,
                'choice_label' => function ($choice, $key, $value) {
                    if ($choice === true) {
                        return '<div><strong>Oui</strong><div class="text-muted small">Ce revenu peut être inclus dans le calcul de la rétro-commission d\'un partenaire.</div></div>';
                    }
                    return '<div><strong>Non</strong><div class="text-muted small">Ce revenu est exclusif au courtier.</div></div>';
                },
            ])
            ->add('multipayments', ChoiceType::class, [
                'label' => "Son paiement peut-il être échélonné?",
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "Oui, il peut être payé en plusieurs tranches." => true,
                    "Non, pas du tout." => false,
                ],
                'label_html' => true,
                'choice_label' => function ($choice, $key, $value) {
                    if ($choice === true) {
                        return '<div><strong>Oui</strong><div class="text-muted small">Permet de facturer ce revenu en plusieurs fois.</div></div>';
                    }
                    return '<div><strong>Non</strong><div class="text-muted small">Ce revenu doit être facturé en une seule fois.</div></div>';
                },
            ])
            ->add('redevable', ChoiceType::class, [
                'label' => "Qui est débiteur?",
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "L'assureur" => TypeRevenu::REDEVABLE_ASSUREUR,
                    "Le client" => TypeRevenu::REDEVABLE_CLIENT,
                    "Le partenaire" => TypeRevenu::REDEVABLE_PARTENAIRE,
                    "Le réassureur" => TypeRevenu::REDEVABLE_REASSURER,
                ],
                'label_html' => true,
                'choice_label' => function ($choice, $key, $value) {
                    $descriptions = [
                        TypeRevenu::REDEVABLE_ASSUREUR => "La commission est due par l'assureur.",
                        TypeRevenu::REDEVABLE_CLIENT => "Les honoraires sont dus par le client.",
                        TypeRevenu::REDEVABLE_PARTENAIRE => "Le revenu est dû par le partenaire.",
                        TypeRevenu::REDEVABLE_REASSURER => "Le revenu est dû par le réassureur."
                    ];
                    return '<div><strong>' . $key . '</strong><div class="text-muted small">' . ($descriptions[$value] ?? '') . '</div></div>';
                },
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
            'data_class' => TypeRevenu::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
