<?php

namespace App\Form;

use App\Entity\Entreprise;
use App\Services\FormListenerFactory;
use App\Services\ServiceGeographie;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

/**
 * Formulaire allégé de l'assistant d'onboarding : crée la TOUTE PREMIÈRE entreprise
 * d'un courtier avec uniquement les champs requis pour une entité valide, plus
 * pays → ville (la monnaie est dérivée du pays lors de l'initialisation de l'espace).
 *
 * Différences avec App\Form\EntrepriseType (formulaire complet) :
 *  - on ne demande PAS rccm / idnat / numimpot / siteweb / capitalSociale / logo
 *    (renseignables ensuite depuis « Paramètres » de l'espace de travail) ;
 *  - même pilotage pays → ville via les Form Events + le contrôleur Stimulus
 *    `pays-dependances` (réutilise l'endpoint `admin.entreprise.api.villes`).
 *
 * Données géographiques issues de App\Services\ServiceGeographie (source unique).
 */
class OnboardingEntrepriseType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
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
            ->add('licence', TextType::class, [
                'label' => "entreprise_form_label_license",
                'attr' => [
                    'placeholder' => "entreprise_form_label_license_placeholder",
                ],
            ])
            ->add('adresse', TextType::class, [
                'label' => "entreprise_form_label_location",
                'attr' => [
                    'placeholder' => "entreprise_form_label_location_placeholder",
                ],
            ])
            ->add('telephone', TextType::class, [
                'label' => "entreprise_form_label_phone_number",
                'attr' => [
                    'placeholder' => "entreprise_form_label_phone_number_placeholder",
                ],
            ])
            ->add('enregistrer', SubmitType::class, [
                'label' => "onboarding_company_submit",
            ])

            // Le champ « Ville » dépend du pays : on le (re)construit quand on connaît
            // le pays (entité initiale via POST_SET_DATA, pays soumis via PRE_SUBMIT)
            // afin que la valeur injectée dynamiquement côté client passe la validation.
            ->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
                /** @var Entreprise|null $entreprise */
                $entreprise = $event->getData();
                $this->construireChampVille($event->getForm(), $entreprise?->getPays());
            })
            ->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
                $data = $event->getData();
                $codePays = isset($data['pays']) && $data['pays'] !== '' ? (int) $data['pays'] : null;
                $this->construireChampVille($event->getForm(), $codePays);
            })

            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    /**
     * (Re)construit le champ « Ville » selon le pays. Inséré après « pays » pour
     * conserver un ordre de saisie logique.
     */
    private function construireChampVille(FormInterface $form, ?int $codePays): void
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Entreprise::class,
        ]);
    }
}
