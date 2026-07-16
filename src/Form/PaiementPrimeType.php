<?php

namespace App\Form;

use App\Entity\PaiementPrime;
use App\Entity\Tranche;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\ServiceMonnaies;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Signalement du paiement d'une prime (marché où l'ASSUREUR facture et encaisse) :
 * date, montant (défaut = solde de prime restant), référence auto, description et
 * preuves documentaires. Aucun compte bancaire : ce paiement n'impacte JAMAIS la
 * trésorerie du courtier — il rend la commission de courtage exigible.
 */
class PaiementPrimeType extends AbstractType
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private RequestStack $requestStack,
        private EntityManagerInterface $em,
        private IndicatorCalculationHelper $calculationHelper,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var PaiementPrime|null $paiementPrime */
        $paiementPrime = $builder->getData();
        $isCreationMode = !$paiementPrime || null === $paiementPrime->getId();

        $defaultMontant = null;
        $defaultDescription = null;

        if ($isCreationMode && $paiementPrime) {
            if (!$paiementPrime->getReference()) {
                $paiementPrime->setReference('PRIME-' . (new \DateTime())->format('dmY-His'));
            }

            // Tranche associée par le contrôleur (parentContext), repli sur parent_id.
            $tranche = $paiementPrime->getTranche();
            if (!$tranche) {
                $trancheId = $this->requestStack->getCurrentRequest()?->query->get('parent_id');
                $tranche = $trancheId ? $this->em->getRepository(Tranche::class)->find($trancheId) : null;
            }

            if ($tranche) {
                // Montant par défaut : solde de prime restant à signaler.
                $prime = $this->calculationHelper->getCotationMontantPrimePayableParClient($tranche->getCotation())
                    * $this->calculationHelper->getTrancheTauxFactor($tranche);
                $solde = round($prime - $this->calculationHelper->getTranchePrimePayee($tranche), 2);
                $defaultMontant = $solde > 0 ? $solde : 0;

                $defaultDescription = sprintf(
                    "Prime réglée par l'assuré et encaissée par l'assureur — %s. Signalement à titre de suivi (sans impact sur la trésorerie du cabinet).",
                    $tranche->getNom() ?? 'tranche'
                );
                if (!$paiementPrime->getDescription()) {
                    $paiementPrime->setDescription($defaultDescription);
                }
            }
        }

        $builder
            ->add('montant', MoneyType::class, [
                'label' => 'Montant de prime réglé',
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'required' => true,
                'grouping' => true,
                'data' => $isCreationMode ? $defaultMontant : $paiementPrime?->getMontant(),
                'help' => "Prime encaissée par l'assureur (paiement partiel possible).",
                'attr' => ['placeholder' => 'Montant'],
            ])
            ->add('reference', TextType::class, [
                'required' => false,
                'label' => 'Référence',
                'attr' => ['readonly' => true, 'placeholder' => 'Générée automatiquement'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'data' => $isCreationMode ? $defaultDescription : $paiementPrime?->getDescription(),
                'attr' => ['placeholder' => "Source de l'information (client, avis de l'assureur…)"],
            ])
            ->add('paidAt', DateTimeType::class, [
                'label' => 'Date de paiement de la prime',
                'widget' => 'single_text',
                'data' => $isCreationMode ? new \DateTimeImmutable() : $paiementPrime?->getPaidAt(),
            ])
            ->add('preuves', CollectionType::class, [
                'label' => 'Preuves du paiement',
                'help' => "Pièces justificatives (avis de l'assureur, reçu, correspondance…).",
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                'mapped' => false, // logique API par élément (même pattern que Paiement.preuves)
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PaiementPrime::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
