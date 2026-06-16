<?php

namespace App\Form;

use App\Entity\Entreprise;
use App\Services\ServiceMonnaies;
use App\Services\FormListenerFactory;
use App\Services\ServiceGeographie;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

/**
 * Formulaire de création / édition d'une entreprise.
 *
 * IMPORTANT : ce formulaire ne porte QUE les attributs propres à l'entité
 * Entreprise. Les entités liées (clients, risques, taxes, comptes, monnaies,
 * partenaires, invités, groupes…) NE sont PAS des collections de Entreprise —
 * elles sont gérées indépendamment dans l'espace de travail. Les anciens champs
 * de collection référençaient des propriétés inexistantes et provoquaient une
 * erreur 500 au rendu (PropertyAccess) ; ils ont été retirés.
 *
 * Champs « Pays » / « Ville » : le pays (code ISO 3166-1 numérique) pilote
 * dynamiquement la liste des villes ET la monnaie affichée du capital social
 * (voir le contrôleur Stimulus `pays-dependances`). Les choix de villes sont
 * reconstruits côté serveur via des Form Events (POST_SET_DATA / PRE_SUBMIT)
 * pour que la valeur injectée par le JS passe la validation. Données issues
 * de App\Services\ServiceGeographie (source de vérité unique).
 */
class EntrepriseType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies,
        private ServiceGeographie $serviceGeographie,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "entreprise_form_label_nom",
                'attr' => [
                    'placeholder' => "entreprise_form_label_nom_placeholder",
                ],
            ])
            ->add('adresse', TextType::class, [
                'label' => "entreprise_form_label_location",
                'attr' => [
                    'placeholder' => "entreprise_form_label_location_placeholder",
                ],
            ])
            ->add('licence', TextType::class, [
                'label' => "entreprise_form_label_license",
                'attr' => [
                    'placeholder' => "entreprise_form_label_license_placeholder",
                ],
            ])
            ->add('telephone', TextType::class, [
                'label' => "entreprise_form_label_phone_number",
                'attr' => [
                    'placeholder' => "entreprise_form_label_phone_number_placeholder",
                ],
            ])
            ->add('rccm', TextType::class, [
                'label' => "entreprise_form_label_trade_register_number",
                'attr' => [
                    'placeholder' => "entreprise_form_label_trade_register_placeholder",
                ],
            ])
            ->add('idnat', TextType::class, [
                'label' => "Numéro d'identification nationale",
                'attr' => [
                    'placeholder' => "IDNAT",
                ],
            ])
            ->add('numimpot', TextType::class, [
                'label' => "entreprise_form_label_fin_id_number",
                'attr' => [
                    'placeholder' => "entreprise_form_label_fin_id_number_placeholder",
                ],
            ])
            ->add('pays', ChoiceType::class, [
                'label' => "Pays",
                'choices' => $this->serviceGeographie->getPaysChoices(),
                'placeholder' => "Sélectionnez un pays",
                // Obligatoire : le pays détermine la monnaie locale et le contexte
                // réglementaire utilisés pour initialiser l'espace de travail.
                'required' => true,
                'constraints' => [
                    new NotNull(message: "Veuillez sélectionner un pays."),
                ],
                'attr' => [
                    'data-pays-dependances-target' => 'pays',
                    'data-action' => 'change->pays-dependances#paysChange',
                ],
            ])
            ->add('siteweb', UrlType::class, [
                'label' => "Site Web",
                'required' => false,
                'attr' => [
                    'placeholder' => "Site web",
                ],
            ])
            ->add('thumbnailFile', FileType::class, [
                'label' => "entreprise_form_label_profile",
                'required' => false,
            ])
            ->add('enregistrer', SubmitType::class, [
                'label' => "commom_save",
            ])

            // Le champ « Ville » et la monnaie du capital social dépendent du pays.
            // On (re)construit ces champs au moment où l'on connaît le pays :
            //  - POST_SET_DATA : pays de l'entité (édition / formulaire initial) ;
            //  - PRE_SUBMIT    : pays réellement soumis (données brutes).
            ->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
                /** @var Entreprise|null $entreprise */
                $entreprise = $event->getData();
                $this->construireChampsDependants($event->getForm(), $entreprise?->getPays());
            })
            ->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
                $data = $event->getData();
                $codePays = isset($data['pays']) && $data['pays'] !== '' ? (int) $data['pays'] : null;
                $this->construireChampsDependants($event->getForm(), $codePays);
            })

            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    /**
     * (Re)construit les champs dépendant du pays : la liste « Ville » et la
     * monnaie affichée du « Capital social ». Appelé pour le pays courant
     * (entité) puis pour le pays soumis, afin que la valeur de ville injectée
     * dynamiquement côté client soit reconnue comme un choix valide.
     */
    private function construireChampsDependants(FormInterface $form, ?int $codePays): void
    {
        $villeChoices = $codePays !== null ? $this->serviceGeographie->getVilleChoices($codePays) : [];

        $form->add('ville', ChoiceType::class, [
            'label' => "Ville",
            'choices' => $villeChoices,
            'placeholder' => "Sélectionnez une ville",
            'required' => false,
            'attr' => [
                'data-pays-dependances-target' => 'ville',
            ],
        ]);

        // La monnaie de MoneyType n'influence que le symbole affiché (pas la
        // valeur stockée). On la dérive du pays, avec repli sur la monnaie locale.
        $monnaie = ($codePays !== null ? $this->serviceGeographie->getMonnaie($codePays) : null)
            ?? $this->serviceMonnaies->getCodeMonnaieLocale()
            ?? 'EUR';

        $form->add('capitalSociale', MoneyType::class, [
            'label' => "Capital social",
            'currency' => $monnaie,
            'grouping' => true,
            'required' => false,
            'attr' => [
                'placeholder' => "Capital sociale",
                'data-pays-dependances-target' => 'capital',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Entreprise::class,
        ]);
    }
}
