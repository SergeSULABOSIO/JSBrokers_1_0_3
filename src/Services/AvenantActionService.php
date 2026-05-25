<?php

namespace App\Services;

use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Chargement; // Import Chargement entity for its constants
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Repository\ChargementRepository;
use App\Repository\ClientRepository;
use App\Repository\CotationRepository;
use App\Repository\PisteRepository;
use App\Repository\RisqueRepository;
use App\Repository\TypeRevenuRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service gérant les actions réelles sur les avenants à partir des données d'analyse de bordereau.
 */
class AvenantActionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClientRepository $clientRepository,
        private RisqueRepository $risqueRepository,
        private PisteRepository $pisteRepository,
        private CotationRepository $cotationRepository,
        private TypeRevenuRepository $typeRevenuRepository,
        private ChargementRepository $chargementRepository
    ) {}

    /**
     * Scénario A : Création complète de la chaîne d'objets.
     */
    public function createFromBordereauLine(array $excelData, Bordereau $bordereau, Invite $invite): Avenant
    {
        $entreprise = $bordereau->getEntreprise();
        $assureur = $bordereau->getAssureur();

        // ÉTAPE 1 — Résolution du Client
        $clientName = $excelData['nom_client'] ?? 'Client Inconnu';
        $client = $this->clientRepository->findOneBy(['nom' => $clientName, 'entreprise' => $entreprise]);
        if (!$client) {
            $client = new Client();
            $client->setNom($clientName);
            $client->setExonere(false);
            $client->setEntreprise($entreprise);
            $client->setInvite($invite);
            $this->em->persist($client);
        }

        // ÉTAPE 2 — Résolution du Risque
        $risque = null;
        $risqueRawValue = $excelData['risque'] ?? null;

        if (!empty($risqueRawValue)) {
            $searchValue = trim($risqueRawValue);

            // Tentative 1 : Match exact sur le nom complet
            $risque = $this->risqueRepository->findOneBy(['nomComplet' => $searchValue, 'entreprise' => $entreprise]);

            // Tentative 2 : Recherche si la valeur Excel est présente dans la liste des codes (abréviations)
            if (!$risque) {
                $risque = $this->risqueRepository->createQueryBuilder('r')
                    ->where('r.code LIKE :val')
                    ->andWhere('r.entreprise = :entreprise')
                    ->setParameter('val', '%' . $searchValue . '%')
                    ->setParameter('entreprise', $entreprise)
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();
            }

            // Tentative 3 : Création automatique si le risque n'existe pas encore
            if (!$risque) {
                $risque = new Risque();
                $risque->setNomComplet($searchValue);
                $risque->setCode($searchValue);
                $risque->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE); // Valeur par défaut IARD
                $risque->setImposable(true); // Par défaut imposable
                $risque->setEntreprise($entreprise);
                $risque->setInvite($invite);
                $this->em->persist($risque);
            }
        }

        // ÉTAPE 3 — Résolution ou création de la Piste
        $startingAt = $this->createDate($excelData['date_effet_avenant'] ?? null);
        $exercice = (int)$startingAt->format('Y');
        $piste = $this->pisteRepository->findOneBy([
            'client' => $client,
            'exercice' => $exercice,
            'risque' => $risque,
            'entreprise' => $entreprise,
        ]);

        if (!$piste) {
            $piste = new Piste();
            $piste->setNom("Piste auto - " . ($excelData['reference_police'] ?? 'Import'));
            $piste->setClient($client);
            $piste->setRisque($risque);
            $piste->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION);
            $piste->setDescriptionDuRisque($excelData['risque'] ?? 'Import bordereau');
            $piste->setExercice($exercice);
            $piste->setEntreprise($entreprise);
            $piste->setInvite($invite);
            $this->em->persist($piste);
        }

        // ÉTAPE 4 — Résolution ou création de la Cotation
        $cotation = $this->cotationRepository->findOneBy([
            'piste' => $piste,
            'assureur' => $assureur,
            'entreprise' => $entreprise,
        ]);

        $endingAt = $this->createDate($excelData['date_expiration_avenant'] ?? null);

        if (!$cotation) {
            $cotation = new Cotation();
            $cotation->setNom("Cotation auto - " . ($excelData['reference_police'] ?? 'Import'));
            $cotation->setPiste($piste);
            $cotation->setAssureur($assureur);
            $diff = $startingAt->diff($endingAt);
            $cotation->setDuree($diff->days ?: 365);
            $cotation->setEntreprise($entreprise);
            $cotation->setInvite($invite);
            $this->em->persist($cotation);
        }

        // ÉTAPE 5 & 6 — Chargements et Revenus
        foreach ($excelData as $key => $value) {
            $val = (float)$value;
            if ($val <= 0) continue;

            if (str_starts_with($key, 'revenu_')) {
                $typeId = (int)explode('_', $key)[1];
                if ($type = $this->typeRevenuRepository->find($typeId)) {
                    $rpc = new RevenuPourCourtier();
                    $rpc->setTypeRevenu($type);
                    $rpc->setCotation($cotation);
                    $rpc->setNom($type->getNom());
                    $rpc->setMontantFlatExceptionel($val);
                    $rpc->setEntreprise($entreprise);
                    $rpc->setInvite($invite);
                    $cotation->addRevenu($rpc);
                    $this->em->persist($rpc);
                }
            } elseif (str_starts_with($key, 'chargement_')) {
                $typeId = (int)explode('_', $key)[1];
                if ($type = $this->chargementRepository->find($typeId)) {
                    $cpp = new ChargementPourPrime();
                    $cpp->setType($type);
                    $cpp->setCotation($cotation);
                    $cpp->setNom($type->getNom());
                    $cpp->setMontantFlatExceptionel($val);
                    $cpp->setEntreprise($entreprise);
                    $cpp->setInvite($invite);
                    $cotation->addChargement($cpp);
                    $this->em->persist($cpp);
                }
            }
        }

        // ÉTAPE 6.5 — Balancement de la Prime TTC (Ajustement Frais Admin)
        $this->balancePrimeTTC($cotation, $excelData, $entreprise, $invite);

        // ÉTAPE 6.6 — Balancement de la Commission HT (Ajustement Écart Commission)
        $this->balanceCommissionHT($cotation, $excelData, $entreprise, $invite);

        // ÉTAPE 6.7 — Persistance du taux de commission
        // CORRECTION : Division par 100 car Symfony stocke les pourcentages en valeur décimale.
        if (isset($excelData['taux_commission'])) {
            foreach ($cotation->getRevenus() as $rpc) {
                if ($rpc->getTypeRevenu()->getRedevable() === TypeRevenu::REDEVABLE_ASSUREUR) {
                    $rpc->setTauxExceptionel((float)$excelData['taux_commission'] / 100);
                }
            }
        }

        // ÉTAPE 7 — Tranche unique
        $tranche = new Tranche();
        $tranche->setNom("Tranche unique - import bordereau");
        $tranche->setCotation($cotation);
        $tranche->setPourcentage(100.0);
        $tranche->setMontantFlat((float)($excelData['prime_ttc'] ?? 0));
        $tranche->setPayableAt($startingAt);
        $tranche->setEcheanceAt($endingAt);
        $tranche->setEntreprise($entreprise);
        $tranche->setInvite($invite);
        $this->em->persist($tranche);

        // ÉTAPE 8 — Avenant
        $avenant = new Avenant();
        $avenant->setCotation($cotation);
        $avenant->setReferencePolice($excelData['reference_police'] ?? null);
        $avenant->setNumero($excelData['reference_police'] ?? null);
        $avenant->setDescription("Avenant importé depuis bordereau " . $bordereau->getReference());
        $avenant->setStartingAt($startingAt);
        $avenant->setEndingAt($endingAt);
        $avenant->setRenewalStatus(Avenant::RENEWAL_STATUS_RUNNING);
        $avenant->setEntreprise($entreprise);
        $avenant->setInvite($invite);
        $this->em->persist($avenant);

        return $avenant;
    }

    /**
     * Calcule l'écart et crée/met à jour le chargement d'ajustement.
     */
    private function balancePrimeTTC(Cotation $cotation, array $excelData, Entreprise $entreprise, Invite $invite): void
    {
        $totalExplicitChargements = 0.0;
        foreach ($cotation->getChargements() as $cpp) {
            // Exclure les chargements d'ajustement système déjà créés pour éviter de les compter deux fois
            if ($cpp->getNom() !== Chargement::SYSTEM_ADJUSTMENT_CHARGEMENT_NAME) {
                $totalExplicitChargements += $cpp->getMontantFlatExceptionel() ?? 0.0;
            }
        }
        $excelPrimeTTC = (float)($excelData['prime_ttc'] ?? 0);
        $ecart = round($excelPrimeTTC - $totalExplicitChargements, 2);

        $this->createOrUpdateSystemAdjustmentChargement(
            $cotation, $entreprise, $invite, $ecart
        );
    }

    /**
     * Scénario B : Mise à jour de l'existant.
     */
    public function updateFromBordereauLine(Avenant $avenant, array $excelData, Bordereau $bordereau): Avenant
    {
        if (isset($excelData['date_effet_avenant'])) {
            $avenant->setStartingAt($this->createDate($excelData['date_effet_avenant']));
        }
        if (isset($excelData['date_expiration_avenant'])) {
            $avenant->setEndingAt($this->createDate($excelData['date_expiration_avenant']));
        }
        
        $cotation = $avenant->getCotation();

        $explicitlyMappedChargementTypeIds = [];

        // Mise à jour Prime TTC via Tranche
        if (isset($excelData['date_effet_avenant'])) {
            $avenant->setStartingAt($this->createDate($excelData['date_effet_avenant']));
        }
        if (isset($excelData['date_expiration_avenant'])) {
            $avenant->setEndingAt($this->createDate($excelData['date_expiration_avenant']));
            // Mettre à jour la durée de la cotation si les dates de l'avenant changent
            if ($avenant->getStartingAt() && $avenant->getEndingAt()) {
                $diff = $avenant->getStartingAt()->diff($avenant->getEndingAt());
                $cotation->setDuree($diff->days ?: 365);
            }
        }

        // Mise à jour de la Tranche
        if (isset($excelData['prime_ttc'])) {
            $tranche = $cotation->getTranches()->first() ?: null;
            if ($tranche) {
                $tranche->setMontantFlat((float)$excelData['prime_ttc']);
            } else {
                // Si aucune tranche n'existe, en créer une (cas rare pour une mise à jour)
                $tranche = new Tranche();
                $tranche->setNom("Tranche unique - import bordereau (mise à jour)");
                $tranche->setCotation($cotation);
                $tranche->setPourcentage(100.0);
                $tranche->setMontantFlat((float)($excelData['prime_ttc'] ?? 0));
                $tranche->setPayableAt($avenant->getStartingAt());
                $tranche->setEcheanceAt($avenant->getEndingAt());
                $tranche->setEntreprise($bordereau->getEntreprise());
                $tranche->setInvite($bordereau->getInvite());
                $this->em->persist($tranche);
            }
        }

        // Mise à jour Revenus et Chargements
        foreach ($excelData as $key => $value) {
            $val = (float)$value;

            if (str_starts_with($key, 'chargement_')) {
                $typeId = (int)explode('_', $key)[1];
                $explicitlyMappedChargementTypeIds[] = $typeId;

                $chargementType = $this->chargementRepository->find($typeId);
                if (!$chargementType) {
                    continue; // Type de chargement non trouvé, on passe
                }

                $cppFound = false;
                foreach ($cotation->getChargements() as $cpp) {
                    if ($cpp->getType()->getId() === $typeId && $cpp->getNom() !== Chargement::SYSTEM_ADJUSTMENT_CHARGEMENT_NAME) {
                        $cpp->setMontantFlatExceptionel($val);
                        $cppFound = true;
                        break;
                    }
                }

                if (!$cppFound) {
                    // Créer un nouveau ChargementPourPrime si non trouvé (nouveau mappage dans Excel)
                    $cpp = new ChargementPourPrime();
                    $cpp->setType($chargementType);
                    $cpp->setCotation($cotation);
                    $cpp->setNom($chargementType->getNom());
                    $cpp->setMontantFlatExceptionel($val);
                    $cpp->setEntreprise($bordereau->getEntreprise());
                    $cpp->setInvite($bordereau->getInvite());
                    $cotation->addChargement($cpp);
                    $this->em->persist($cpp);
                }
            } elseif (str_starts_with($key, 'revenu_')) {
                $typeId = (int)explode('_', $key)[1];
                foreach ($cotation->getRevenus() as $rpc) {
                    if ($rpc->getTypeRevenu()->getId() === $typeId) {
                        $rpc->setMontantFlatExceptionel($val);
                    }
                }
            }
        }

        // Supprimer les ChargementPourPrime qui étaient explicitement mappés mais ne le sont plus
        // et qui ne sont pas des chargements d'ajustement système.
        foreach ($cotation->getChargements() as $cpp) {
            if (!in_array($cpp->getType()->getId(), $explicitlyMappedChargementTypeIds) && $cpp->getNom() !== Chargement::SYSTEM_ADJUSTMENT_CHARGEMENT_NAME) {
                $cotation->removeChargement($cpp); // Supprime de la collection de la cotation
                $this->em->remove($cpp); // Marque pour suppression en base
            }
        }

        // Recalcul de l'écart après mise à jour et nettoyage
        $this->balancePrimeTTC($cotation, $excelData, $bordereau->getEntreprise(), $bordereau->getInvite());

        // Recalcul de l'écart commission après mise à jour
        $this->balancePrimeTTC($cotation, $excelData, $bordereau->getEntreprise(), $bordereau->getInvite());

        // Mise à jour Taux de commission sur le revenu Assureur
        if (isset($excelData['taux_commission'])) {
            foreach ($cotation->getRevenus() as $rpc) {
                if ($rpc->getTypeRevenu()->getRedevable() === TypeRevenu::REDEVABLE_ASSUREUR) {
                    // CORRECTION : Le taux Excel est en pourcentage (ex: 7.01 pour 7,01%).
                    // Symfony stocke les pourcentages en valeur décimale (0–1).
                    // On divise donc par 100 avant la persistance.
                    $rpc->setTauxExceptionel((float)$excelData['taux_commission'] / 100);
                }
            }
        }

        return $avenant;
    }

    /**
     * Crée ou met à jour un ChargementPourPrime de type FONCTION_FRAIS_ADMIN
     * pour absorber l'écart entre la prime TTC Excel et la somme des chargements explicites.
     */
    private function createOrUpdateSystemAdjustmentChargement(
        Cotation $cotation,
        Entreprise $entreprise,
        Invite $invite,
        float $ecart
    ): void {
        $fraisAdminType = $this->chargementRepository->findOneBy([
            'fonction' => Chargement::FONCTION_FRAIS_ADMIN,
            'entreprise' => $entreprise
        ]);

        if (!$fraisAdminType) {
            // Gérer le cas où le type FONCTION_FRAIS_ADMIN n'existe pas
            // Cela pourrait être une exception ou la création automatique d'un tel type
            // Pour l'instant, on lance une exception pour signaler le problème.
            throw new \RuntimeException("Le type de chargement 'Frais Admin' n'a pas été trouvé pour l'entreprise.");
        }

        // Chercher un chargement d'ajustement existant pour cette cotation
        $systemAdjustmentCpp = $cotation->getChargements()->filter(function(ChargementPourPrime $cpp) use ($fraisAdminType) {
            return $cpp->getType()->getId() === $fraisAdminType->getId() && $cpp->getNom() === Chargement::SYSTEM_ADJUSTMENT_CHARGEMENT_NAME;
        })->first();

        if (!$systemAdjustmentCpp) {
            $systemAdjustmentCpp = new ChargementPourPrime();
            $systemAdjustmentCpp->setType($fraisAdminType);
            $systemAdjustmentCpp->setCotation($cotation);
            $systemAdjustmentCpp->setNom(Chargement::SYSTEM_ADJUSTMENT_CHARGEMENT_NAME);
            $systemAdjustmentCpp->setEntreprise($entreprise);
            $systemAdjustmentCpp->setInvite($invite);
            $this->em->persist($systemAdjustmentCpp);
        }
        $systemAdjustmentCpp->setMontantFlatExceptionel($ecart);
    }

    /**
     * Calcule l'écart entre la commission HT assureur (Excel) et la somme
     * des revenus explicitement mappés, puis crée/met à jour un RevenuPourCourtier
     * d'ajustement système pour absorber cet écart.
     *
     * Symétrique de balancePrimeTTC() côté chargements.
     */
    private function balanceCommissionHT(
        Cotation $cotation,
        array $excelData,
        Entreprise $entreprise,
        Invite $invite
    ): void {
        // Si la commission HT n'est pas dans les données Excel, rien à faire.
        if (!isset($excelData['commission_ht_assureur'])) {
            return;
        }

        $excelCommissionHT = (float)$excelData['commission_ht_assureur'];

        // Sommer les revenus explicitement mappés (hors ajustement système).
        $totalExplicitRevenus = 0.0;
        foreach ($cotation->getRevenus() as $rpc) {
            if ($rpc->getNom() !== TypeRevenu::SYSTEM_ADJUSTMENT_REVENU_NAME) {
                // On utilise le montant flat exceptionnel pour les revenus mappés
                $totalExplicitRevenus += $rpc->getMontantFlatExceptionel() ?? 0.0;
            }
        }

        $ecart = round($excelCommissionHT - $totalExplicitRevenus, 2);

        $this->createOrUpdateSystemAdjustmentRevenu(
            $cotation, $entreprise, $invite, $ecart
        );
    }

    /**
     * Crée ou met à jour un RevenuPourCourtier d'ajustement système
     * pour absorber l'écart entre la commission HT Excel et les revenus mappés.
     */
    private function createOrUpdateSystemAdjustmentRevenu(
        Cotation $cotation,
        Entreprise $entreprise,
        Invite $invite,
        float $ecart
    ): void {
        // Trouver le type de revenu de type REDEVABLE_ASSUREUR pour l'ajustement.
        /**
         * @var TypeRevenu $adjustmentType
        */
        $adjustmentType = $this->typeRevenuRepository->findOneBy([
            'redevable' => TypeRevenu::REDEVABLE_ASSUREUR,
            'entreprise' => $entreprise,
        ]);

        if (!$adjustmentType) {
            throw new \RuntimeException(
                "Aucun type de revenu de type REDEVABLE_ASSUREUR trouvé pour l'entreprise. " .
                "Impossible de créer le revenu d'ajustement système."
            );
        }

        // Chercher un revenu d'ajustement système existant sur cette cotation.
        $systemAdjustmentRpc = $cotation->getRevenus()->filter(function($rpc) {
            return $rpc->getNom() === TypeRevenu::SYSTEM_ADJUSTMENT_REVENU_NAME;
        })->first();

        if (!$systemAdjustmentRpc) {
            $systemAdjustmentRpc = new RevenuPourCourtier();
            $systemAdjustmentRpc->setTypeRevenu($adjustmentType);
            $systemAdjustmentRpc->setCotation($cotation);
            $systemAdjustmentRpc->setNom(TypeRevenu::SYSTEM_ADJUSTMENT_REVENU_NAME);
            $systemAdjustmentRpc->setEntreprise($entreprise);
            $systemAdjustmentRpc->setInvite($invite);
            $cotation->addRevenu($systemAdjustmentRpc);
            $this->em->persist($systemAdjustmentRpc);
        }
        $systemAdjustmentRpc->setMontantFlatExceptionel($ecart);
    }

    private function createDate($input): DateTimeImmutable
    {
        if ($input instanceof DateTimeImmutable) return $input;
        if ($input instanceof \DateTime) return DateTimeImmutable::createFromMutable($input);
        if (is_array($input) && isset($input['date'])) return new DateTimeImmutable($input['date']);
        if (is_string($input)) {
            try {
                return new DateTimeImmutable($input);
            } catch (\Exception $e) {
                // Fallback format Excel courant si nécessaire
                return new DateTimeImmutable('now');
            }
        }
        return new DateTimeImmutable('now');
    }
}