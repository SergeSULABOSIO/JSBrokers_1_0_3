<?php

namespace App\Form;

use App\Constantes\Constante;
use App\Entity\Paiement;
use App\Entity\Note;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\FormListenerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class PaiementType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies,
        private Constante $constante,
        private Security $security,
        private RequestStack $requestStack,
        private EntityManagerInterface $em,
        private IndicatorCalculationHelper $calculationHelper
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Paiement|null $paiement */
        $paiement = $builder->getData();
        $isCreationMode = !$paiement || null === $paiement->getId();

        $request = $this->requestStack->getCurrentRequest();
        $note = null;
        $defaultMontant = null;
        $defaultDescription = null;
        $defaultCompte = null;
        $defaultReference = null;

        // Logique spécifique au mode CRÉATION
        if ($isCreationMode) {
            // 1. Récupération de la note parente via le contexte (parent_id dans l'URL AJAX)
            $noteId = $request?->query->get('parent_id');
            if ($noteId) {
                $note = $this->em->getRepository(Note::class)->find($noteId);
            }

            if ($note) {
                // 2. Calcul du montant par défaut (le solde de la note)
                $payable = $this->calculationHelper->getNoteMontantPayable($note);
                $paye = $this->calculationHelper->getNoteMontantPaye($note);
                $solde = $payable - $paye;
                $defaultMontant = $solde > 0 ? $solde : 0;

                // 3. Génération de la référence automatique
                $defaultReference = 'PAY-' . (new \DateTime())->format('dmY-His');

                // 4. Identification du nom réel du destinataire (au lieu du label N/A)
                $recipientName = match ($note->getAddressedTo()) {
                    Note::TO_CLIENT => $note->getClient()?->getNom(),
                    Note::TO_ASSUREUR => $note->getAssureur()?->getNom(),
                    Note::TO_PARTENAIRE => $note->getPartenaire()?->getNom(),
                    Note::TO_AUTORITE_FISCALE => $note->getAutoritefiscale()?->getNom(),
                    default => 'Inconnu'
                } ?? 'Non défini';

                // 5. Génération de la description automatique
                $defaultDescription = sprintf(
                    "Règlement relatif à la Note %s (%s). Destinataire : %s.",
                    $note->getReference(),
                    $note->getNom(),
                    $recipientName
                );
            }

            // 6. Recherche du compte bancaire par défaut de l'entreprise
            $user = $this->security->getUser();
            if ($user instanceof Utilisateur && $entreprise = $user->getConnectedTo()) {
                $defaultCompte = $entreprise->getCompteBancaires()->first() ?: null;
            }
        }

        $builder
            ->add('montant', MoneyType::class, [
                'label' => "Montant en cours de paiement",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'required' => false,
                'grouping' => true,
                'data' => $isCreationMode ? $defaultMontant : $paiement?->getMontant(),
                'attr' => [
                    'placeholder' => "Montant",
                ],
            ])
            ->add('reference', TextType::class, [
                'required' => false,
                'disabled' => true, // Comme dans NoteType
                'data' => $isCreationMode ? $defaultReference : $paiement?->getReference(),
                'label' => "Référence",
                'attr' => ['placeholder' => "Générée automatiquement"],
            ])
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'data' => $isCreationMode ? $defaultDescription : $paiement?->getDescription(),
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('paidAt', DateTimeType::class, [
                'label' => "Date de paiement",
                'widget' => 'single_text',
                'data' => $isCreationMode ? new \DateTimeImmutable() : $paiement?->getPaidAt(),
            ])
            ->add('CompteBancaire', CompteBancaireAutocompleteField::class, [
                'label' => "Compte bancaire",
                'required' => true,
                'data' => $isCreationMode ? $defaultCompte : $paiement?->getCompteBancaire(),
            ])
            ->add('preuves', CollectionType::class, [
                'label' => 'Preuves de paiement',
                'help' => 'Documents justificatifs (avis de débit, etc.).',
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                'mapped' => false, // On continue avec notre logique API par élément
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Paiement::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
