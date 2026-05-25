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
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

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
        $explicitlyMappedRevenuTypeIds = [];

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
                    // Log or handle the case where a mapped chargement type is not found
                    // For now, we skip it.
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
                $explicitlyMappedRevenuTypeIds[] = $typeId;
                $typeRevenu = $this->typeRevenuRepository->find($typeId);

                if (!$typeRevenu) {
                    // Log or handle the case where a mapped revenue type is not found
                    // For now, we skip it.
                    continue;
                }

                $rpcFound = false;
                foreach ($cotation->getRevenus() as $rpc) {
                    // Ensure we don't update the system adjustment revenue here
                    if ($rpc->getTypeRevenu()->getId() === $typeId && $rpc->getNom() !== TypeRevenu::SYSTEM_ADJUSTMENT_REVENU_NAME) {
                        $rpc->setMontantFlatExceptionel($val);
                        $rpcFound = true;
                        break;
                    }
                }

                if (!$rpcFound) {
                    // Create new RevenuPourCourtier if not found (new mapping in Excel)
                    $rpc = new RevenuPourCourtier();
                    $rpc->setTypeRevenu($typeRevenu);
                    $rpc->setCotation($cotation);
                    $rpc->setNom($typeRevenu->getNom()); // Use the name from TypeRevenu
                    $rpc->setMontantFlatExceptionel($val);
                    $rpc->setEntreprise($bordereau->getEntreprise());
                    $rpc->setInvite($bordereau->getInvite());
                    $cotation->addRevenu($rpc);
                    $this->em->persist($rpc);
                }
            }
        }

        // Supprimer les RevenuPourCourtier qui étaient explicitement mappés mais ne le sont plus
        // et qui ne sont pas des revenus d'ajustement système.
        foreach ($cotation->getRevenus() as $rpc) {
            if ($rpc->getTypeRevenu() && !in_array($rpc->getTypeRevenu()->getId(), $explicitlyMappedRevenuTypeIds) && $rpc->getNom() !== TypeRevenu::SYSTEM_ADJUSTMENT_REVENU_NAME) {
                $cotation->removeRevenu($rpc);
                $this->em->remove($rpc);
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
        $this->balanceCommissionHT($cotation, $excelData, $bordereau->getEntreprise(), $bordereau->getInvite());

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
     * Calcule l'écart entre la commission théorique due (Prime Nette × Taux Commission)
     * et la somme des revenus explicitement mappés, puis crée/met à jour un
     * RevenuPourCourtier d'ajustement système pour absorber cet écart.
     *
     * Règle métier :
     *   Σ(Revenu_XX mappés) + écart  =  Prime Nette × Taux Commission
     *
     * La clé "commission_ht_assureur" n'est PAS utilisée ici : elle représente
     * uniquement la part encaissable maintenant (paiement partiel possible),
     * pas la commission totale due sur la cotation.
     */
    private function balanceCommissionHT(
        Cotation $cotation,
        array $excelData,
        Entreprise $entreprise,
        Invite $invite
    ): void {
        // Determine the target commission HT from Excel
        // This is the authoritative value from the Excel file for comparison.
        $targetCommissionHT = (float)($excelData['commission_ht_assureur'] ?? 0);

        // If the explicit commission_ht_assureur is not provided or is zero,
        // we fall back to calculating it from prime nette and taux commission.
        // This ensures a target is always available.
        $tauxCommission = isset($excelData['taux_commission']) ? (float)$excelData['taux_commission'] / 100 : null;

        // ÉTAPE 1 — Resolution of Prime Nette
        // ÉTAPE 1 — Résolution de la Prime Nette
        // Priorité A : depuis les ChargementPourPrime de la cotation existante
        $primeNette = null;
        foreach ($cotation->getChargements() as $cpp) {
            if (
                $cpp->getType() &&
                $cpp->getType()->getFonction() === Chargement::FONCTION_PRIME_NETTE
            ) {
                $primeNette = $cpp->getMontantFlatExceptionel();
                break;
            }
        }

        // Priorité B : déduction depuis prime_ttc Excel
        // Prime Nette ≈ prime_ttc - Σ(chargements dont fonction ≠ FONCTION_PRIME_NETTE)
        if ($primeNette === null && isset($excelData['prime_ttc'])) {
            $primeTTC = (float)$excelData['prime_ttc'];
            $totalAutresChargements = 0.0;
            foreach ($cotation->getChargements() as $cpp) {
                if (
                    $cpp->getType() &&
                    $cpp->getType()->getFonction() !== Chargement::FONCTION_PRIME_NETTE &&
                    $cpp->getNom() !== Chargement::SYSTEM_ADJUSTMENT_CHARGEMENT_NAME
                ) {
                    $totalAutresChargements += $cpp->getMontantFlatExceptionel() ?? 0.0;
                }
            }
            $primeNette = round($primeTTC - $totalAutresChargements, 2);
        }
        // If targetCommissionHT from Excel is 0 or not provided, and we have primeNette and tauxCommission,
        // calculate the theoretical commission as a fallback target.
        if ($targetCommissionHT === 0.0 && $primeNette !== null && $tauxCommission !== null) {
            $targetCommissionHT = round($primeNette * $tauxCommission, 2);
        } elseif ($targetCommissionHT === 0.0 && (!isset($excelData['commission_ht_assureur']) || $primeNette === null || $tauxCommission === null)) {
            // If no explicit commission from Excel and no way to derive it,
            // we cannot balance. Create adjustment with 0.0 and let manual correction happen.
            $this->createOrUpdateSystemAdjustmentRevenu(
                $cotation, $entreprise, $invite, 0.0
            );
            return;
        }

        // The target for reconciliation is now $targetCommissionHT

        // ÉTAPE 2 — Sum of explicitly mapped revenues (excluding the system adjustment one)
        // This is now ÉTAPE 3 in the original code, but conceptually it's the sum of what's already there.
        $totalExplicitRevenus = 0.0;
        foreach ($cotation->getRevenus() as $rpc) {
            if ($rpc->getNom() !== TypeRevenu::SYSTEM_ADJUSTMENT_REVENU_NAME) {
                $totalExplicitRevenus += $rpc->getMontantFlatExceptionel() ?? 0.0;
            }
        }

        // ÉTAPE 3 — Calculate the required adjustment
        $ecart = round($targetCommissionHT - $totalExplicitRevenus, 2);

        // ÉTAPE 4 — Create or update the system adjustment revenue
        $this->createOrUpdateSystemAdjustmentRevenu(
            $cotation, $entreprise, $invite, $ecart
        );
    }

    /**
     * Crée ou met à jour un RevenuPourCourtier d'ajustement système
     * pour absorber l'écart entre la commission théorique due et les revenus mappés.
     * Si écart = 0.0, le revenu est quand même créé/mis à jour pour signaler
     * que la réconciliation a été effectuée.
     */
    private function createOrUpdateSystemAdjustmentRevenu(
        Cotation $cotation,
        Entreprise $entreprise,
        Invite $invite,
        float $ecart
    ): void {
        // Trouver le premier TypeRevenu de type REDEVABLE_ASSUREUR pour l'ajustement
        /** @var TypeRevenu $adjustmentType */
        $adjustmentType = $this->typeRevenuRepository->findOneBy([
            'redevable' => TypeRevenu::REDEVABLE_ASSUREUR,
            'entreprise' => $entreprise,
        ]);

        if (!$adjustmentType) {
            // Fallback silencieux : on ne bloque pas l'import pour un ajustement
            // Le courtier devra réconcilier manuellement
            return;
        }

        // Recherche d'un revenu d'ajustement système existant (boucle explicite)
        $systemAdjustmentRpc = null;
        foreach ($cotation->getRevenus() as $rpc) {
            if ($rpc->getNom() === TypeRevenu::SYSTEM_ADJUSTMENT_REVENU_NAME) {
                $systemAdjustmentRpc = $rpc;
                break;
            }
        }

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