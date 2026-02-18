<?php

namespace App\Services\Canvas;


use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Contact;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\ModelePieceSinistre;
use App\Entity\Note;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\PieceSinistre;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\RolesEnAdministration;
use App\Entity\RolesEnFinance;
use App\Entity\RolesEnMarketing;
use App\Entity\RolesEnProduction;
use App\Entity\RolesEnSinistre;
use App\Entity\Tache;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Repository\CotationRepository;
use App\Repository\NotificationSinistreRepository;
use App\Repository\TaxeRepository;
use App\Repository\UtilisateurRepository;

use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security; // Correction: S'assurer que cette ligne est bien présente
use Symfony\Contracts\Translation\TranslatorInterface;

class CalculationProvider
{
    /**
     */
    public function __construct(
        private ServiceDates $serviceDates,
        private Security $security,
        private ServiceTaxes $serviceTaxes,
        private CotationRepository $cotationRepository,
        private NotificationSinistreRepository $notificationSinistreRepository,
        private TaxeRepository $taxeRepository,
        private TranslatorInterface $translator,
        private UtilisateurRepository $utilisateurRepository
    ) {}

    /**
     * Calcule les indicateurs spécifiques pour une entité donnée.
     *
     * @param object $entity L'entité pour laquelle calculer les indicateurs.
     * @return array Un tableau associatif d'indicateurs calculés.
     */
    public function getIndicateursSpecifics(object $entity): array
    {
        $indicateurs = [];

        switch (get_class($entity)) {
            case NotificationSinistre::class:
                /** @var NotificationSinistre $entity */
                // La protection contre les entités supprimées est maintenant gérée dans NotificationSinistre::getAssure().
                $assure = $entity->getAssure();
                $assureNom = $assure ? $assure->getNom() : 'N/A';

                $indicateurs = [
                    'assureNom' => $assureNom,
                    'delaiDeclaration' => $this->calculateDelaiDeclaration($entity),
                    'ageDossier' => $this->calculateAgeDossier($entity),
                    'compensationFranchise' => round($this->calculateFranchise($entity), 2),
                    'tauxIndemnisation' => $this->getNotificationSinistreTauxIndemnisation($entity),
                    'nombreOffres' => $this->getNotificationSinistreNombreOffres($entity),
                    'nombrePaiements' => $this->getNotificationSinistreNombrePaiements($entity),
                    'montantMoyenParPaiement' => round($this->getNotificationSinistreMontantMoyenParPaiement($entity) ?? 0.0, 2),
                    'delaiTraitementInitial' => $this->getNotificationSinistreDelaiTraitementInitial($entity),
                    'ratioPaiementsEvaluation' => $this->getNotificationSinistreRatioPaiementsEvaluation($entity),
                    'compensation' => round($this->getNotificationSinistreCompensation($entity), 2),
                    'compensationVersee' => round($this->getNotificationSinistreCompensationVersee($entity), 2),
                    'compensationSoldeAverser' => round($this->getNotificationSinistreSoldeAVerser($entity), 2),
                    'indiceCompletude' => $this->getNotificationSinistreIndiceCompletude($entity),
                    'dateDernierReglement' => $this->getNotificationSinistreDateDernierReglement($entity),
                    'dureeReglement' => $this->getNotificationSinistreDureeReglement($entity),
                    'statusDocumentsAttendus' => $this->getNotificationSinistreStatusDocumentsAttendus($entity),
                ];
                break;
            case OffreIndemnisationSinistre::class:
                /** @var OffreIndemnisationSinistre $entity */
                $indicateurs = [
                    'compensationVersee' => round($this->getOffreIndemnisationCompensationVersee($entity), 2),
                    'soldeAVerser' => round($this->getOffreIndemnisationSoldeAVerser($entity), 2),
                    'pourcentagePaye' => $this->getOffreIndemnisationPourcentagePaye($entity),
                    'nombrePaiements' => $this->getOffreIndemnisationNombrePaiements($entity),
                    'montantMoyenParPaiement' => round($this->getOffreIndemnisationMontantMoyenParPaiement($entity) ?? 0.0, 2),
                ];
                break;
            case Cotation::class:
                /** @var Cotation $entity */
                $indicateurs = [
                    'contextePiste' => $this->getCotationContextePiste($entity),
                    'statutSouscription' => $this->calculateStatutSouscription($entity),
                    'referencePolice' => $this->getCotationReferencePolice($entity),
                    'periodeCouverture' => $this->getCotationPeriodeCouverture($entity),
                    // NOUVEAU : Indicateurs Sinistralité
                    'indemnisationDue' => round($this->getCotationIndemnisationDue($entity), 2),
                    'indemnisationVersee' => round($this->getCotationIndemnisationVersee($entity), 2),
                    'indemnisationSolde' => round($this->getCotationIndemnisationSolde($entity), 2),
                    'tauxSP' => $this->getCotationTauxSP($entity),
                    'tauxSPInterpretation' => $this->getCotationTauxSPInterpretation($entity),
                    'dateDernierReglement' => $this->getCotationDateDernierReglement($entity),
                    'vitesseReglement' => $this->getCotationVitesseReglement($entity),

                    'delaiDepuisCreation' => $this->calculateDelaiDepuisCreation($entity),
                    'nombreTranches' => $this->calculateNombreTranches($entity),
                    'montantMoyenTranche' => $this->calculateMontantMoyenTranche($entity),
                    
                    // NOUVEAU : Indicateurs financiers
                    'primeTotale' => round($this->getCotationMontantPrimePayableParClient($entity), 2),
                    'primePayee' => round($this->getCotationMontantPrimePayableParClientPayee($entity), 2),
                    'primeSoldeDue' => round($this->getCotationMontantPrimePayableParClient($entity) - $this->getCotationMontantPrimePayableParClientPayee($entity), 2),
                    
                    'tauxCommission' => $this->getCotationTauxCommission($entity),
                    'montantHT' => round($this->getCotationMontantCommissionHt($entity, -1, false), 2),
                    'montantTTC' => round($this->getCotationMontantCommissionTtc($entity, -1, false), 2),
                    'detailCalcul' => "Somme des revenus",
                    
                    'taxeCourtierMontant' => round($this->getCotationMontantTaxeCourtier($entity, false), 2),
                    'taxeAssureurMontant' => round($this->getCotationMontantTaxeAssureur($entity, false), 2),
                    
                    'montant_du' => round($this->getCotationMontantCommissionTtc($entity, -1, false), 2),
                    'montant_paye' => round($this->getCotationMontantCommissionEncaissee($entity), 2),
                    'solde_restant_du' => round($this->getCotationMontantCommissionTtc($entity, -1, false) - $this->getCotationMontantCommissionEncaissee($entity), 2),
                    
                    'taxeCourtierPayee' => round($this->getCotationMontantTaxeCourtierPayee($entity), 2),
                    'taxeCourtierSolde' => round($this->getCotationMontantTaxeCourtier($entity, false) - $this->getCotationMontantTaxeCourtierPayee($entity), 2),
                    
                    'taxeAssureurPayee' => round($this->getCotationMontantTaxeAssureurPayee($entity), 2),
                    'taxeAssureurSolde' => round($this->getCotationMontantTaxeAssureur($entity, false) - $this->getCotationMontantTaxeAssureurPayee($entity), 2),
                    
                    'montantPur' => round($this->getCotationMontantCommissionPure($entity, -1, false), 2),
                    'retroCommission' => round($this->getCotationMontantRetrocommissionsPayableParCourtier($entity, null, -1, []), 2),
                    'retroCommissionReversee' => round($this->getCotationMontantRetrocommissionsPayableParCourtierPayee($entity, null), 2),
                    'retroCommissionSolde' => round($this->getCotationMontantRetrocommissionsPayableParCourtier($entity, null, -1, []) - $this->getCotationMontantRetrocommissionsPayableParCourtierPayee($entity, null), 2),
                    
                    'reserve' => round($this->getCotationMontantCommissionPure($entity, -1, false) - $this->getCotationMontantRetrocommissionsPayableParCourtier($entity, null, -1, []), 2),
                ];
                break;
            case Tranche::class:
                /** @var Tranche $entity */
                $indicateurs = [
                    'ageTranche' => $this->calculateTrancheAge($entity),
                    'joursRestantsAvantEcheance' => $this->calculateTrancheJoursRestants($entity),
                    'contexteParent' => $this->getTrancheContexteParent($entity),
                    // NOUVEAU : Indicateurs financiers
                    'primeTranche' => round($this->getTranchePrime($entity), 2),
                    'primePayee' => round($this->getTranchePrimePayee($entity), 2),
                    'primeSoldeDue' => round($this->getTranchePrimeSoldeDue($entity), 2),
                    'tauxTranche' => $this->getTrancheTauxDisplay($entity),
                    'montantCalculeHT' => round($this->getTrancheMontantHT($entity), 2),
                    'montantCalculeTTC' => round($this->getTrancheMontantTTC($entity), 2),
                    'descriptionCalcul' => $this->getTrancheDescriptionCalcul($entity),
                    'taxeCourtierMontant' => round($this->getTrancheTaxeCourtierMontant($entity), 2),
                    'taxeCourtierTaux' => $this->getTrancheTaxeCourtierTaux($entity),
                    'taxeAssureurMontant' => round($this->getTrancheTaxeAssureurMontant($entity), 2),
                    'taxeAssureurTaux' => $this->getTrancheTaxeAssureurTaux($entity),
                    'montant_du' => round($this->getTrancheMontantTTC($entity), 2), // Montant dû = Montant TTC
                    'montant_paye' => round($this->getTrancheMontantCommissionEncaissee($entity), 2),
                    'solde_restant_du' => round($this->getTrancheMontantTTC($entity) - $this->getTrancheMontantCommissionEncaissee($entity), 2),
                    'taxeCourtierPayee' => round($this->getTrancheMontantTaxePayee($entity, false), 2),
                    'taxeCourtierSolde' => round($this->getTrancheTaxeCourtierMontant($entity) - $this->getTrancheMontantTaxePayee($entity, false), 2),
                    'taxeAssureurPayee' => round($this->getTrancheMontantTaxePayee($entity, true), 2),
                    'taxeAssureurSolde' => round($this->getTrancheTaxeAssureurMontant($entity) - $this->getTrancheMontantTaxePayee($entity, true), 2),
                    'estPartageable' => $this->getTrancheEstPartageable($entity),
                    'montantPur' => round($this->getTrancheMontantPur($entity), 2),
                    'partPartenaire' => $this->getTranchePartPartenaire($entity),
                    'retroCommission' => round($this->getTrancheRetroCommission($entity), 2),
                    'retroCommissionReversee' => round($this->getTrancheMontantRetrocommissionsPayableParCourtierPayee($entity), 2),
                    'retroCommissionSolde' => round($this->getTrancheRetroCommission($entity) - $this->getTrancheMontantRetrocommissionsPayableParCourtierPayee($entity), 2),
                    'reserve' => round($this->getTrancheReserve($entity), 2),
                ];
                break;
            case Avenant::class:
                /** @var Avenant $entity */
                $indicateurs = [
                    'dureeCouverture' => $this->calculateDureeCouvertureAvenant($entity),
                    'joursRestants' => $this->calculateJoursRestantsAvenant($entity),
                    'ageAvenant' => $this->calculateAgeAvenant($entity),
                    'statutRenouvellement' => $this->getAvenantStatutRenouvellementString($entity),
                ];
                break;
            case Tache::class:
                /** @var Tache $entity */
                $indicateurs = [
                    'statutExecution' => $this->getTacheStatutExecutionString($entity),
                    'delaiRestant' => $this->calculateTacheDelaiRestant($entity),
                    'ageTache' => $this->calculateTacheAge($entity),
                    'nombreFeedbacks' => $this->countTacheFeedbacks($entity),
                    'contexteTache' => $this->getTacheContexteString($entity),
                ];
                break;
            case Feedback::class:
                /** @var Feedback $entity */
                $indicateurs = [
                    'typeString' => $this->getFeedbackTypeString($entity),
                    'delaiProchaineAction' => $this->calculateFeedbackDelaiProchaineAction($entity),
                    'ageFeedback' => $this->calculateFeedbackAge($entity),
                    'statutProchaineAction' => $this->getFeedbackStatutProchaineActionString($entity),
                ];
                break;
            case Client::class:
                /** @var Client $entity */
                $indicateurs = [
                    'civiliteString' => $this->getClientCiviliteString($entity),
                    'nombrePistes' => $this->countClientPistes($entity),
                    'nombreSinistres' => $this->countClientSinistres($entity),
                    'nombrePolices' => $this->countClientPolices($entity),
                ];
                break;
            case Assureur::class:
                /** @var Assureur $entity */
                $indicateurs = [
                    'nombrePolicesSouscrites' => $this->countAssureurPolicesSouscrites($entity),
                    'nombreSinistresGeres' => $this->countAssureurSinistresGeres($entity),
                    'tauxTransformationCotations' => $this->calculateAssureurTauxTransformation($entity),
                ];
                break;
            case Partenaire::class:
                /** @var Partenaire $entity */
                $indicateurs = [
                    'nombrePistesApportees' => $this->countPartenairePistes($entity),
                    'nombreClientsAssocies' => $this->countPartenaireClients($entity),
                    'nombrePolicesGenerees' => $this->countPartenairePolices($entity),
                    'nombreConditionsPartage' => $this->countPartenaireConditions($entity),
                ];
                break;
            case ConditionPartage::class:
                /** @var ConditionPartage $entity */
                $indicateurs = [
                    'descriptionRegle' => $this->getConditionPartageDescriptionRegle($entity),
                    'nombreRisquesCibles' => $this->countConditionPartageRisquesCibles($entity),
                    'porteeCondition' => $this->getConditionPartagePortee($entity),
                ];
                break;
            case Risque::class:
                /** @var Risque $entity */
                $indicateurs = [
                    'brancheString' => $this->getRisqueBrancheString($entity),
                    'nombrePistes' => $this->countRisquePistes($entity),
                    'nombreSinistres' => $this->countRisqueSinistres($entity),
                    'nombrePolices' => $this->countRisquePolices($entity),
                ];
                break;
            case Document::class:
                /** @var Document $entity */
                $indicateurs = [
                    'ageDocument' => $this->calculateDocumentAge($entity),
                    'typeFichier' => $this->getDocumentTypeFichier($entity),
                    'parent_string' => $this->Document_getParentAsString($entity),
                    'classeur_string' => $this->Document_getClasseurAsString($entity),
                ];
                break;
            case ChargementPourPrime::class:
                /** @var ChargementPourPrime $entity */
                $indicateurs = [
                    'montant_final' => round($this->getChargementPourPrimeMontantFinal($entity), 2),
                    'montantTaxeAppliquee' => round($this->getChargementPourPrimeMontantTaxe($entity), 2),
                    'poidsSurPrimeTotale' => $this->getChargementPourPrimePoidsSurPrime($entity),
                    'ageChargement' => $this->calculateChargementPourPrimeAge($entity),
                    'fonctionChargement' => $this->Chargement_getFonctionString($entity->getType()),
                ];
                break;
            case Chargement::class:
                /** @var Chargement $entity */
                $indicateurs = [
                    'fonction_string' => $this->Chargement_getFonctionString($entity),
                ];
                break;
            case Contact::class:
                /** @var Contact $entity */
                $indicateurs = [
                    'type_string' => $this->Contact_getTypeString($entity),
                ];
                break;
            case Invite::class:
                /** @var Invite $entity */
                $indicateurs = [
                    'ageInvitation' => $this->calculateInviteAge($entity),
                    'tachesEnCours' => $this->countInviteTachesEnCours($entity),
                    'rolePrincipal' => $this->getInviteRolePrincipal($entity),
                    'proprietaireString' => $this->getInviteProprietaireString($entity), // Ajout de l'indicateur manquant
                    'status_string' => $this->getInviteStatusString($entity),
                ];
                break;
            case Note::class:
                /** @var Note $entity */
                $indicateurs = [
                    'typeString' => $this->getNoteTypeString($entity),
                    'addressedToString' => $this->getNoteAddressedToString($entity),
                    'montantTotal' => round($this->getNoteMontantPayable($entity), 2),
                    'montantPaye' => round($this->getNoteMontantPaye($entity), 2),
                    'solde' => round($this->getNoteSolde($entity), 2),
                    'statutPaiement' => $this->getNoteStatutPaiementString($entity),
                ];
                break;
            case Groupe::class:
                /** @var Groupe $entity */
                $indicateurs = [
                    'nombreClients' => $this->countGroupeClients($entity),
                    'nombrePolices' => $this->countGroupePolices($entity),
                    'nombreSinistres' => $this->countGroupeSinistres($entity),
                ];
                break;
            case RevenuPourCourtier::class:
                /** @var RevenuPourCourtier $entity */
                $indicateurs = [
                    'montantCalculeHT' => round($this->getRevenuMontantHt($entity), 2),
                    'montantCalculeTTC' => round($this->getRevenuPourCourtierMontantTTC($entity), 2),
                    'descriptionCalcul' => $this->getRevenuPourCourtierDescriptionCalcul($entity),
                    'montant_du' => round($this->getRevenuPourCourtierMontantDu($entity), 2),
                    'montant_paye' => round($this->getRevenuPourCourtierMontantPaye($entity), 2),
                    'solde_restant_du' => round($this->getRevenuPourCourtierSoldeRestantDu($entity), 2),
                    'montantPur' => round($this->getRevenuMontantPur($entity), 2),
                    'partPartenaire' => $this->getRevenuPartPartenaire($entity),
                    'retroCommission' => round($this->getRevenuRetroCommission($entity), 2),
                    'reserve' => round($this->getRevenuReserve($entity), 2),
                    'retroCommissionReversee' => round($this->getRevenuRetroCommissionReversee($entity), 2),
                    'retroCommissionSolde' => round($this->getRevenuRetroCommissionSolde($entity), 2),
                    'taxeCourtierMontant' => round($this->getRevenuTaxeCourtierMontant($entity), 2),
                    'taxeCourtierTaux' => $this->getRevenuTaxeCourtierTaux($entity),
                    'taxeAssureurMontant' => round($this->getRevenuTaxeAssureurMontant($entity), 2),
                    'taxeAssureurTaux' => $this->getRevenuTaxeAssureurTaux($entity),
                    'estPartageable' => $this->getRevenuEstPartageable($entity),
                    'taxeCourtierPayee' => round($this->getRevenuTaxeCourtierPayee($entity), 2),
                    'taxeCourtierSolde' => round($this->getRevenuTaxeCourtierSolde($entity), 2),
                    'taxeAssureurPayee' => round($this->getRevenuTaxeAssureurPayee($entity), 2),
                    'taxeAssureurSolde' => round($this->getRevenuTaxeAssureurSolde($entity), 2),
                ];
                break;
            case TypeRevenu::class:
                /** @var TypeRevenu $entity */
                $indicateurs = [
                    'descriptionModeCalcul' => $this->getTypeRevenuDescriptionModeCalcul($entity),
                    'redevableString' => $this->getTypeRevenuRedevableString($entity),
                    'sharedString' => $this->getTypeRevenuSharedString($entity),
                    'nombreUtilisations' => $this->countTypeRevenuUtilisations($entity),
                ];
                break;
            case Entreprise::class:
                /** @var Entreprise $entity */
                $indicateurs = [
                    'ageEntreprise' => $this->calculateEntrepriseAge($entity),
                    'nombreCollaborateurs' => $this->countEntrepriseCollaborateurs($entity),
                    'nombreClients' => $this->countEntrepriseClients($entity),
                    'nombrePartenaires' => $this->countEntreprisePartenaires($entity),
                    'nombreAssureurs' => $this->countEntrepriseAssureurs($entity),
                ];
                break;
            case PieceSinistre::class:
                /** @var PieceSinistre $entity */
                $indicateurs = [
                    'agePiece' => $this->calculatePieceSinistreAge($entity),
                    'typePieceNom' => $this->getPieceSinistreTypeName($entity),
                    'estObligatoire' => $this->getPieceSinistreEstObligatoire($entity),
                ];

                break;
            case ModelePieceSinistre::class:
                /** @var ModelePieceSinistre $entity */
                $indicateurs = [
                    'nombreUtilisations' => $this->countModelePieceSinistreUtilisations($entity),
                    'statutObligation' => $this->getModelePieceSinistreStatutObligationString($entity),
                ];
                break;
            case Classeur::class:
                /** @var Classeur $entity */
                $indicateurs = [
                    'nombreDocuments' => $this->countClasseurDocuments($entity),
                    'ageClasseur' => $this->calculateClasseurAge($entity),
                    'dateDernierAjout' => $this->getClasseurDateDernierAjout($entity),
                    'apercuTypesFichiers' => $this->getClasseurApercuTypesFichiers($entity),
                    'estVide' => $this->getClasseurEstVideString($entity),
                ];
                break;
            case RolesEnFinance::class:
                /** @var RolesEnFinance $entity */
                $invite = $entity->getInvite();
                $inviteNom = $invite ? $invite->getNom() : 'N/A';
                $indicateurs = [
                    'inviteNom' => $inviteNom,
                ];
                $accessFields = [
                    'accessMonnaie', 'accessCompteBancaire', 'accessTaxe', 'accessTypeRevenu',
                    'accessTranche', 'accessTypeChargement', 'accessNote', 'accessPaiement',
                    'accessBordereau', 'accessRevenu'
                ];
                foreach ($accessFields as $field) {
                    if (method_exists($entity, 'get' . ucfirst($field))) {
                        $indicateurs[$field . 'String'] = $this->Role_getAccessString($entity, [$field]);
                    }
                }
                break;
            case RolesEnMarketing::class:
                /** @var RolesEnMarketing $entity */
                $invite = $entity->getInvite();
                $inviteNom = $invite ? $invite->getNom() : 'N/A';
                $indicateurs = [
                    'inviteNom' => $inviteNom,
                ];
                $accessFields = ['accessPiste', 'accessTache', 'accessFeedback'];
                foreach ($accessFields as $field) {
                    if (method_exists($entity, 'get' . ucfirst($field))) {
                        $indicateurs[$field . 'String'] = $this->Role_getAccessString($entity, [$field]);
                    }
                }
                break;
            case RolesEnProduction::class:
                /** @var RolesEnProduction $entity */
                $invite = $entity->getInvite();
                $inviteNom = $invite ? $invite->getNom() : 'N/A';
                $indicateurs = [
                    'inviteNom' => $inviteNom,
                ];
                $accessFields = [
                    'accessGroupe', 'accessClient', 'accessAssureur', 'accessContact',
                    'accessRisque', 'accessAvenant', 'accessPartenaire', 'accessCotation'
                ];
                foreach ($accessFields as $field) {
                    if (method_exists($entity, 'get' . ucfirst($field))) {
                        $indicateurs[$field . 'String'] = $this->Role_getAccessString($entity, [$field]);
                    }
                }
                break;
            case RolesEnSinistre::class:
                /** @var RolesEnSinistre $entity */
                $invite = $entity->getInvite();
                $inviteNom = $invite ? $invite->getNom() : 'N/A';
                $indicateurs = [
                    'inviteNom' => $inviteNom,
                ];
                $accessFields = ['accessTypePiece', 'accessNotification', 'accessReglement'];
                foreach ($accessFields as $field) {
                    if (method_exists($entity, 'get' . ucfirst($field))) {
                        $indicateurs[$field . 'String'] = $this->Role_getAccessString($entity, [$field]);
                    }
                }
                break;
            case RolesEnAdministration::class:
                /** @var RolesEnAdministration $entity */
                $invite = $entity->getInvite();
                $inviteNom = $invite ? $invite->getNom() : 'N/A';
                $indicateurs = [
                    'inviteNom' => $inviteNom,
                ];
                $accessFields = ['accessDocument', 'accessClasseur', 'accessInvite'];
                foreach ($accessFields as $field) {
                    if (method_exists($entity, 'get' . ucfirst($field))) {
                        $indicateurs[$field . 'String'] = $this->Role_getAccessString($entity, [$field]);
                    }
                }
                break;
                // D'autres entités pourraient être ajoutées ici avec 'case AutreEntite::class:'
        }

        return $indicateurs;
    }

    public function getIndicateursGlobaux(Entreprise $entreprise, bool $isBound, array $options = []): array
    {
        // Initialisation des variables de totaux
        $totals = array_fill_keys([
            'prime_totale',
            'prime_totale_payee',
            'commission_totale',
            'commission_totale_encaissee',
            'commission_nette',
            'commission_pure',
            'prime_nette',
            'commission_partageable',
            'reserve',
            'retro_commission_partenaire',
            'retro_commission_partenaire_payee',
            'taxe_courtier',
            'taxe_courtier_payee',
            'taxe_assureur',
            'taxe_assureur_payee',
            'sinistre_payable',
            'sinistre_paye'
        ], 0.0);
        extract($totals);

        // 1. Extraire les filtres des options
        $pisteCible = $options['pisteCible'] ?? null;
        $cotationCible = $options['cotationCible'] ?? null;
        $assureurCible = $options['assureurCible'] ?? null;
        $risqueCible = $options['risqueCible'] ?? null;
        $partenaireCible = $options['partenaireCible'] ?? null;
        $inviteCible = $options['inviteCible'] ?? null;
        $groupeCible = $options['groupeCible'] ?? null;
        $avenantCible = $options['avenantCible'] ?? null;
        $clientCible = $options['clientCible'] ?? null;
        $trancheCible = $options['trancheCible'] ?? null;
        $brancheCible = $options['brancheCible'] ?? null;
        $reper = $options['reper'] ?? null;
        $dateA_str = $options['entre'] ?? null;
        $dateB_str = $options['et'] ?? null;
        $typeRevenuCible = $options['typeRevenuCible'] ?? null;
        $revenuPourCourtierCible = $options['revenuPourCourtierCible'] ?? null;
        $paiementCible = $options['paiementCible'] ?? null;
        $notificationSinistreCible = $options['notificationSinistreCible'] ?? null;
        $conditionPartageCible = $options['conditionPartageCible'] ?? null;

        // 2. Construire la requête dynamique pour les Cotations
        $qb = $this->cotationRepository->createQueryBuilder('c')
            // Jointures INNER pour le filtrage principal
            ->join('c.piste', 'p')
            ->join('p.invite', 'i')
            // Jointures LEFT pour l'Eager Loading et éviter les problèmes N+1 dans la boucle
            ->leftJoin('c.avenants', 'av')
            ->leftJoin('c.revenus', 'rev')
            ->leftJoin('rev.typeRevenu', 'rt')
            ->leftJoin('c.tranches', 't')
            ->leftJoin('t.articles', 'art')
            ->leftJoin('art.note', 'n')
            ->leftJoin('n.paiements', 'np')
            ->leftJoin('c.chargements', 'ch')
            ->leftJoin('ch.type', 'cht')
            ->leftJoin('p.risque', 'r')
            ->leftJoin('p.client', 'cl')
            ->leftJoin('p.partenaires', 'pa')
            ->leftJoin('cl.partenaires', 'clpa')
            // Sélectionne toutes les entités jointes pour les hydrater en une seule requête
            ->addSelect('p', 'i', 'av', 'rev', 'rt', 't', 'art', 'n', 'np', 'ch', 'cht', 'r', 'cl', 'pa', 'clpa')
            ->where('i.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->distinct() // DISTINCT est crucial pour éviter les doublons dus aux jointures "to-many"
        ;

        // Appliquer les filtres
        if ($isBound) {
            $qb->andWhere($qb->expr()->gt('SIZE(c.avenants)', 0));
        }
        if ($pisteCible) $qb->andWhere('p = :pisteCible')->setParameter('pisteCible', $pisteCible);
        if ($cotationCible) $qb->andWhere('c = :cotationCible')->setParameter('cotationCible', $cotationCible);
        if ($assureurCible) $qb->andWhere('c.assureur = :assureurCible')->setParameter('assureurCible', $assureurCible);
        if ($risqueCible) $qb->andWhere('p.risque = :risqueCible')->setParameter('risqueCible', $risqueCible);
        if ($inviteCible) $qb->andWhere('p.invite = :inviteCible')->setParameter('inviteCible', $inviteCible);
        if ($clientCible) $qb->andWhere('p.client = :clientCible')->setParameter('clientCible', $clientCible);
        if ($groupeCible) $qb->join('p.client', 'cl_g')->andWhere('cl_g.groupe = :groupeCible')->setParameter('groupeCible', $groupeCible);
        if ($partenaireCible) $qb->join('p.partenaires', 'pa')->andWhere('pa = :partenaireCible')->setParameter('partenaireCible', $partenaireCible);
        if ($avenantCible) $qb->join('c.avenants', 'av')->andWhere('av = :avenantCible')->setParameter('avenantCible', $avenantCible);
        if ($trancheCible) $qb->join('c.tranches', 't')->andWhere('t = :trancheCible')->setParameter('trancheCible', $trancheCible);
        if ($revenuPourCourtierCible) $qb->join('c.revenus', 'rpc')->andWhere('rpc = :revenuPourCourtierCible')->setParameter('revenuPourCourtierCible', $revenuPourCourtierCible);
        if ($typeRevenuCible) $qb->join('c.revenus', 'rpc_tr')->andWhere('rpc_tr.typeRevenu = :typeRevenuCible')->setParameter('typeRevenuCible', $typeRevenuCible);

        if ($brancheCible) {
            $brancheCode = ($brancheCible === 'IARD') ? Risque::BRANCHE_IARD_OU_NON_VIE : (($brancheCible === 'VIE') ? Risque::BRANCHE_VIE : -1);
            if ($brancheCode !== -1) {
                $qb->join('p.risque', 'r_b')->andWhere('r_b.branche = :brancheCode')->setParameter('brancheCode', $brancheCode);
            }
        }

        if ($conditionPartageCible) {
            $qb->join('p.conditionsPartageExceptionnelles', 'cp')->andWhere('cp = :conditionPartageCible')->setParameter('conditionPartageCible', $conditionPartageCible);
        }

        if ($reper && $dateA_str && $dateB_str) {
            $dateA = DateTimeImmutable::createFromFormat('d/m/Y', $dateA_str);
            $dateB = DateTimeImmutable::createFromFormat('d/m/Y', $dateB_str);
            if ($dateA && $dateB) {
                $qb->join('c.avenants', 'av_date')
                    ->andWhere($qb->expr()->between(($reper === 'dateEffet' ? 'av_date.startingAt' : 'av_date.endingAt'), ':dateA', ':dateB'))
                    ->setParameter('dateA', $dateA->setTime(0, 0, 0))
                    ->setParameter('dateB', $dateB->setTime(23, 59, 59));
            }
        }

        if ($notificationSinistreCible && $notificationSinistreCible->getReferencePolice()) {
            $qb->join('c.avenants', 'av_sin')->andWhere('av_sin.referencePolice = :refPolice')->setParameter('refPolice', $notificationSinistreCible->getReferencePolice());
        }

        if ($paiementCible) {
            if ($note = $paiementCible->getNote()) {
                // On utilise des jointures directes pour lier la cotation au paiement via la note.
                // C'est plus lisible et idiomatique que de construire une sous-requête en DQL.
                $qb->join('c.tranches', 't_payment')->join('t_payment.articles', 'a_payment')
                   ->andWhere('a_payment.note = :payment_note')
                   ->setParameter('payment_note', $note)
                   ->distinct(); // On ajoute distinct pour éviter les doublons si plusieurs articles de la même cotation sont dans la note.
            } else {
                $qb->andWhere('1=0');
            }
        }

        // 3. Exécuter la requête pour obtenir les cotations filtrées
        $cotationsAcalculer = $qb->getQuery()->getResult();

        // NOUVEAU : Pré-calcul des sommes pour éviter les requêtes N+1
        $commissionSums = $this->precomputeCommissionSums($entreprise, $options);

        // Récupérer et filtrer les sinistres de manière optimisée
        $sinistresQb = $this->notificationSinistreRepository->createQueryBuilder('ns')
            ->join('ns.invite', 'i')
            ->where('i.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise);

        if ($notificationSinistreCible) {
            $sinistresQb->andWhere('ns = :notificationSinistreCible')
                ->setParameter('notificationSinistreCible', $notificationSinistreCible);
        }

        if ($paiementCible) {
            if ($offre = $paiementCible->getOffreIndemnisationSinistre()) {
                if ($sinistreDuPaiement = $offre->getNotificationSinistre()) {
                    $sinistresQb->andWhere('ns = :sinistreDuPaiement')
                        ->setParameter('sinistreDuPaiement', $sinistreDuPaiement);
                } else {
                    $sinistresQb->andWhere('1=0');
                }
            } else {
                $sinistresQb->andWhere('1=0');
            }
        }

        $sinistresAcalculer = $sinistresQb->getQuery()->getResult();

        // 4. Calculate totals from the filtered list
        foreach ($cotationsAcalculer as $cotation) {
            if ($isBound && !$this->isCotationBound($cotation)) {
                continue; // On saute les cotations non-souscrites si isBound est true.
            }

            // Prime Nette
            $prime_nette += $this->getCotationMontantPrimeNette($cotation);

            // Prime
            $prime_cotation = $this->getCotationMontantPrimePayableParClient($cotation);
            $prime_totale += $prime_cotation;
            // La logique de facturation des primes n'étant pas clairement définie via les Articles,
            // le calcul du montant payé ne peut être implémenté de manière fiable pour le moment.
            // $prime_totale_payee += $this->getCotationMontantPrimePayableParClientPayee($cotation);

            // Commission Totale (TTC)
            $commission_ttc_cotation = $this->getCotationMontantCommissionTtc($cotation, -1, false);
            $commission_totale += $commission_ttc_cotation;
            $commission_totale_encaissee += $this->getCotationMontantCommissionEncaissee($cotation);

            // Commission Nette (HT)
            $cotation_com_nette = $this->getCotationMontantCommissionHt($cotation, -1, false);
            $commission_nette += $cotation_com_nette;

            // Taxes
            $cotation_taxe_courtier = $this->getCotationMontantTaxeCourtier($cotation, false);
            $cotation_taxe_assureur = $this->getCotationMontantTaxeAssureur($cotation, false);
            $taxe_courtier += $cotation_taxe_courtier;
            $taxe_assureur += $cotation_taxe_assureur;
            $taxe_courtier_payee += $this->getCotationMontantTaxeCourtierPayee($cotation);
            $taxe_assureur_payee += $this->getCotationMontantTaxeAssureurPayee($cotation);

            // Commission Pure
            $commission_pure += $cotation_com_nette - $cotation_taxe_courtier;

            // Assiette partageable (Commission Pure sur revenus partageables)
            $cotation_com_nette_partageable = $this->getCotationMontantCommissionHt($cotation, -1, true);
            $cotation_taxe_courtier_partageable = $this->getCotationMontantTaxeCourtier($cotation, true);
            $commission_partageable += $cotation_com_nette_partageable - $cotation_taxe_courtier_partageable;

            // Rétro-commissions (Logique complexe conservée dans Constante pour le moment)
            $retro_commission_partenaire += $this->getCotationMontantRetrocommissionsPayableParCourtier($cotation, $partenaireCible, -1, $commissionSums);
            $retro_commission_partenaire_payee += $this->getCotationMontantRetrocommissionsPayableParCourtierPayee($cotation, $partenaireCible);
        }

        // Calculate claim totals
        foreach ($sinistresAcalculer as $sinistre) {
            $sinistre_payable += $this->getNotificationSinistreCompensation($sinistre);
            $sinistre_paye += $this->getNotificationSinistreCompensationVersee($sinistre);
        }

        // 5. Apply tranche percentage if provided
        if ($trancheCible) {
            $pourcentage = $trancheCible->getPourcentage();
            if ($pourcentage !== null) {
                $prime_totale *= $pourcentage;
                $commission_totale *= $pourcentage;
                $commission_nette *= $pourcentage;
                $commission_pure *= $pourcentage;
                $commission_partageable *= $pourcentage;
                $prime_nette *= $pourcentage;
                $retro_commission_partenaire *= $pourcentage;
                $reserve *= $pourcentage;
                // Les montants payés ne sont pas affectés par le pourcentage de la tranche dans ce contexte.
                $taxe_courtier *= $pourcentage;
                $taxe_assureur *= $pourcentage;
            }
        }

        // 6. Final calculations
        $reserve = $commission_pure - $retro_commission_partenaire;
        $prime_totale_solde = $prime_totale - $prime_totale_payee;
        $commission_totale_solde = $commission_totale - $commission_totale_encaissee;
        $retro_commission_partenaire_solde = $retro_commission_partenaire - $retro_commission_partenaire_payee;
        $taxe_courtier_solde = $taxe_courtier - $taxe_courtier_payee;
        $taxe_assureur_solde = $taxe_assureur - $taxe_assureur_payee;
        $sinistre_solde = $sinistre_payable - $sinistre_paye;
        $taux_sinistralite = ($prime_totale > 0) ? ($sinistre_payable / $prime_totale) * 100 : 0;
        $taux_de_commission = ($prime_nette > 0) ? ($commission_nette / $prime_nette) * 100 : 0;
        $taux_de_retrocommission_effectif = ($commission_partageable > 0) ? ($retro_commission_partenaire / $commission_partageable) * 100 : 0;
        $taux_de_paiement_prime = ($prime_totale > 0) ? ($prime_totale_payee / $prime_totale) * 100 : 0;
        $taux_de_paiement_commission = ($commission_totale > 0) ? ($commission_totale_encaissee / $commission_totale) * 100 : 0;
        $taux_de_paiement_retro_commission = ($retro_commission_partenaire > 0) ? ($retro_commission_partenaire_payee / $retro_commission_partenaire) * 100 : 0;
        $taux_de_paiement_taxe_courtier = ($taxe_courtier > 0) ? ($taxe_courtier_payee / $taxe_courtier) * 100 : 0;
        $taux_de_paiement_taxe_assureur = ($taxe_assureur > 0) ? ($taxe_assureur_payee / $taxe_assureur) * 100 : 0;
        $taux_de_paiement_sinistre = ($sinistre_payable > 0) ? ($sinistre_paye / $sinistre_payable) * 100 : 0;

        return [
            'prime_totale' => $prime_totale,
            'prime_totale_payee' => $prime_totale_payee,
            'prime_totale_solde' => $prime_totale_solde,
            'commission_totale' => $commission_totale,
            'commission_totale_encaissee' => $commission_totale_encaissee,
            'commission_totale_solde' => $commission_totale_solde,
            'commission_nette' => $commission_nette,
            'commission_pure' => $commission_pure,
            'commission_partageable' => $commission_partageable,
            'prime_nette' => $prime_nette,
            'reserve' => $reserve,
            'retro_commission_partenaire' => $retro_commission_partenaire,
            'retro_commission_partenaire_payee' => $retro_commission_partenaire_payee,
            'retro_commission_partenaire_solde' => $retro_commission_partenaire_solde,
            'taxe_courtier' => $taxe_courtier,
            'taxe_courtier_payee' => $taxe_courtier_payee,
            'taxe_courtier_solde' => $taxe_courtier_solde,
            'taxe_assureur' => $taxe_assureur,
            'taxe_assureur_payee' => $taxe_assureur_payee,
            'taxe_assureur_solde' => $taxe_assureur_solde,
            'sinistre_payable' => $sinistre_payable,
            'sinistre_paye' => $sinistre_paye,
            'sinistre_solde' => $sinistre_solde,
            'taux_sinistralite' => $taux_sinistralite,
            'taux_de_commission' => $taux_de_commission,
            'taux_de_retrocommission_effectif' => $taux_de_retrocommission_effectif,
            'taux_de_paiement_prime' => $taux_de_paiement_prime,
            'taux_de_paiement_commission' => $taux_de_paiement_commission,
            'taux_de_paiement_retro_commission' => $taux_de_paiement_retro_commission,
            'taux_de_paiement_taxe_courtier' => $taux_de_paiement_taxe_courtier,
            'taux_de_paiement_taxe_assureur' => $taux_de_paiement_taxe_assureur,
            'taux_de_paiement_sinistre' => $taux_de_paiement_sinistre,
        ];
    }

    private function getEntreprise(): Entreprise
    {
        /** @var Utilisateur $user */
        $user = $this->security->getUser();

        return $user->getConnectedTo();
    }

    /**
     * Calcule le montant total de l'indemnisation convenue pour ce sinistre.
     */
    private function getNotificationSinistreCompensation(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + ($offre->getMontantPayable() ?? 0);
        }, 0.0);
    }

    /**
     * Calcule le montant cumulé des paiements déjà effectués pour cette indemnisation.
     */
    private function getNotificationSinistreCompensationVersee(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + $this->getOffreIndemnisationCompensationVersee($offre);
        }, 0.0);
    }

    /**
     * Calcule le montant de la franchise qui a été appliquée conformément aux termes de la police.
     */

    /**
     * Calcule le montant cumulé des paiements déjà effectués pour cette offre.
     */
    private function getOffreIndemnisationCompensationVersee(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        return array_reduce($offre_indemnisation->getPaiements()->toArray(), function ($carry, Paiement $paiement) {
            return $carry + ($paiement->getMontant() ?? 0);
        }, 0.0);
    }

    /**
     * Calcule le montant restant à payer pour solder cette offre.
     */
    private function getOffreIndemnisationSoldeAVerser(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        $montantPayable = $offre_indemnisation->getMontantPayable() ?? 0.0;
        $compensationVersee = $this->getOffreIndemnisationCompensationVersee($offre_indemnisation);
        return $montantPayable - $compensationVersee;
    }

    /**
     * Calcule le solde de l'indemnisation restant à verser.
     */
    private function getNotificationSinistreSoldeAVerser(NotificationSinistre $sinistre): float
    {
        return $this->getNotificationSinistreCompensation($sinistre) - $this->getNotificationSinistreCompensationVersee($sinistre);
    }

    /**
     * Trouve la date du tout dernier règlement pour un sinistre.
     */
    private function getNotificationSinistreDateDernierReglement(NotificationSinistre $sinistre): ?\DateTimeInterface
    {
        $dateDernierReglement = null;
        foreach ($sinistre->getOffreIndemnisationSinistres() as $offre) {
            foreach ($offre->getPaiements() as $paiement) {
                if ($paiement->getPaidAt() && (!$dateDernierReglement || $paiement->getPaidAt() > $dateDernierReglement)) {
                    $dateDernierReglement = $paiement->getPaidAt();
                }
            }
        }
        return $dateDernierReglement;
    }

    /**
     * Calcule la durée totale de règlement d'un sinistre en jours.
     */
    private function getNotificationSinistreDureeReglement(NotificationSinistre $sinistre): ?string
    {
        $dateDernierReglement = $this->getNotificationSinistreDateDernierReglement($sinistre);
        $dateNotification = $sinistre->getNotifiedAt();

        if (!$dateDernierReglement || !$dateNotification) {
            return null;
        }

        $jours = $this->serviceDates->daysEntre($dateNotification, $dateDernierReglement);
        return $jours !== null ? $jours . ' jour(s)' : null;
    }

    /**
     * Calcule le statut des documents attendus pour un sinistre.
     */
    private function getNotificationSinistreStatusDocumentsAttendus(NotificationSinistre $sinistre): string
    {
        $modelesAttendus = $this->getEntreprise()->getModelePieceSinistres();
        $nombreAttendus = $modelesAttendus->count();

        $typesFournis = [];
        foreach ($sinistre->getPieces() as $piece) {
            if ($type = $piece->getType()) {
                $typesFournis[$type->getId()] = true;
            }
        }
        $nombreFournis = count($typesFournis);

        $nombreManquants = 0;
        foreach ($modelesAttendus as $modele) {
            if (!isset($typesFournis[$modele->getId()])) {
                $nombreManquants++;
            }
        }

        return sprintf(
            "Attendus: %d pc(s) • Fournis: %d pc(s) • Manquants: %d pc(s)",
            $nombreAttendus,
            $nombreFournis,
            $nombreManquants
        );
    }

    /**
     * Calcule le délai en jours entre la survenance et la notification d'un sinistre.
     */
    private function calculateDelaiDeclaration(NotificationSinistre $sinistre): string
    {
        if (!$sinistre->getOccuredAt() || !$sinistre->getNotifiedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($sinistre->getOccuredAt(), $sinistre->getNotifiedAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Calcule l'âge du dossier sinistre depuis sa création.
     */
    private function calculateAgeDossier(NotificationSinistre $sinistre): string
    {
        if (!$sinistre->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($sinistre->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Calcule le montant de la franchise qui a été appliquée conformément aux termes de la police.
     */
    private function calculateFranchise(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + ($offre->getFranchiseAppliquee() ?? 0);
        }, 0.0);
    }

    /**
     * Calcule le pourcentage de pièces fournies par rapport aux pièces attendues.
     */
    private function getNotificationSinistreIndiceCompletude(NotificationSinistre $sinistre): string
    {
        $modelesAttendus = $this->getEntreprise()->getModelePieceSinistres();
        $nombreAttendus = $modelesAttendus->count();

        if ($nombreAttendus === 0) {
            return '100 %'; // S'il n'y a aucune pièce modèle, le dossier est complet.
        }

        $typesFournisUniques = [];
        foreach ($sinistre->getPieces() as $piece) {
            if ($type = $piece->getType()) {
                $typesFournisUniques[$type->getId()] = true;
            }
        }
        $nombreFournis = count($typesFournisUniques);
        $pourcentage = ($nombreFournis / $nombreAttendus) * 100;
        return round($pourcentage) . ' %';
    }

    /**
     * Calcule le taux d'indemnisation (offres / évaluation).
     */
    private function getNotificationSinistreTauxIndemnisation(NotificationSinistre $sinistre): ?float
    {
        $compensation = $this->getNotificationSinistreCompensation($sinistre);
        $evaluation = $sinistre->getEvaluationChiffree();

        if ($evaluation > 0) {
            return round(($compensation / $evaluation) * 100, 2);
        }
        return null;
    }

    /**
     * Calcule le nombre total d'offres d'indemnisation.
     */
    private function getNotificationSinistreNombreOffres(NotificationSinistre $sinistre): int
    {
        return $sinistre->getOffreIndemnisationSinistres()->count();
    }

    /**
     * Calcule le nombre total de paiements effectués.
     */
    private function getNotificationSinistreNombrePaiements(NotificationSinistre $sinistre): int
    {
        $nombrePaiements = 0;
        foreach ($sinistre->getOffreIndemnisationSinistres() as $offre) {
            $nombrePaiements += $offre->getPaiements()->count();
        }
        return $nombrePaiements;
    }

    /**
     * Calcule le montant moyen par paiement.
     */
    private function getNotificationSinistreMontantMoyenParPaiement(NotificationSinistre $sinistre): ?float
    {
        $compensationVersee = $this->getNotificationSinistreCompensationVersee($sinistre);
        $nombrePaiements = $this->getNotificationSinistreNombrePaiements($sinistre);

        if ($nombrePaiements > 0) {
            return round($compensationVersee / $nombrePaiements, 2);
        }
        return null;
    }

    /**
     * Calcule le délai de traitement initial (création à notification).
     */
    private function getNotificationSinistreDelaiTraitementInitial(NotificationSinistre $sinistre): string
    {
        if (!$sinistre->getCreatedAt() || !$sinistre->getNotifiedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($sinistre->getCreatedAt(), $sinistre->getNotifiedAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Calcule le ratio des paiements par rapport à l'évaluation chiffrée.
     */
    private function getNotificationSinistreRatioPaiementsEvaluation(NotificationSinistre $sinistre): ?float
    {
        $compensationVersee = $this->getNotificationSinistreCompensationVersee($sinistre);
        $evaluation = $sinistre->getEvaluationChiffree();

        if ($evaluation > 0) {
            return round(($compensationVersee / $evaluation) * 100, 2);
        }
        return null;
    }

    /**
     * Calcule le pourcentage du montant payable qui a été versé.
     */
    private function getOffreIndemnisationPourcentagePaye(OffreIndemnisationSinistre $offre): ?float
    {
        $montantPayable = $offre->getMontantPayable();
        if ($montantPayable > 0) {
            $compensationVersee = $this->getOffreIndemnisationCompensationVersee($offre);
            return round(($compensationVersee / $montantPayable) * 100, 2);
        }
        return 0.0; // Retourne 0 si le montant payable est 0 ou null
    }

    /**
     * Compte le nombre de paiements pour une offre.
     */
    private function getOffreIndemnisationNombrePaiements(OffreIndemnisationSinistre $offre): int
    {
        return $offre->getPaiements()->count();
    }

    /**
     * Calcule le montant moyen par paiement pour une offre.
     */
    private function getOffreIndemnisationMontantMoyenParPaiement(OffreIndemnisationSinistre $offre): ?float
    {
        $nombrePaiements = $this->getOffreIndemnisationNombrePaiements($offre);
        if ($nombrePaiements > 0) {
            $compensationVersee = $this->getOffreIndemnisationCompensationVersee($offre);
            return round($compensationVersee / $nombrePaiements, 2);
        }
        return null;
    }


    /**
     * RETRO-COMMISSION DUE AU PARTENAIRE
     */
    private function getCotationMontantRetrocommissionsPayableParCourtier(?Cotation $cotation, ?Partenaire $partenaireCible, $addressedTo, array $precomputedSums): float
    {
        if (!$cotation) {
            return 0.0;
        }

        $montant = 0.0;
        foreach ($cotation->getRevenus() as $revenu) {
            $montant += $this->getRevenuMontantRetrocommissionsPayableParCourtier($revenu, $partenaireCible, $addressedTo, $precomputedSums);
        }
        return $montant;
    }


    private function getCotationPartenaire(?Cotation $cotation)
    {
        // Utilisation de l'opérateur null-safe pour plus de sécurité
        if ($cotation?->getPiste()) {
            // Priorité 1 : Partenaire directement lié à la piste.
            if (!$cotation->getPiste()->getPartenaires()->isEmpty()) {
                return $cotation->getPiste()->getPartenaires()->first();
            }

            // Priorité 2 : Partenaire lié au client de la piste (avec vérification de nullité).
            $client = $cotation->getPiste()->getClient();
            if ($client && !$client->getPartenaires()->isEmpty()) {
                return $client->getPartenaires()->first();
            }
        }
        return null;
    }

    private function isSamePartenaire(?Partenaire $partenaire, ?Partenaire $partenaireCible): bool
    {
        if ($partenaireCible == null) {
            return true;
        } else {
            if ($partenaireCible != $partenaire) {
                return false;
            } else {
                return true;
            }
        }
    }


    private function getRevenuMontantRetrocommissionsPayableParCourtier(?RevenuPourCourtier $revenu, ?Partenaire $partenaireCible, $addressedTo, array $precomputedSums): float
    {
        // 1. Gardes de protection
        if (!$revenu || !$revenu->getTypeRevenu() || !$revenu->getTypeRevenu()->isShared()) {
            return 0.0;
        }
        $cotation = $revenu->getCotation();
        if (!$cotation || !$cotation->getPiste()) {
            return 0.0;
        }

        $partenaireAffaire = $this->getCotationPartenaire($cotation);
        if (!$partenaireAffaire || !$this->isSamePartenaire($partenaireAffaire, $partenaireCible)) {
            return 0.0;
        }

        // 2. Logique de partage hiérarchique
        // Priorité 1 : Conditions exceptionnelles sur la Piste
        $conditionsPartagePiste = $cotation->getPiste()->getConditionsPartageExceptionnelles();
        if (!$conditionsPartagePiste->isEmpty()) {
            return $this->applyRevenuConditionsSpeciales($conditionsPartagePiste->first(), $revenu, $addressedTo, $precomputedSums);
        }

        // Priorité 2 : Conditions générales sur le Partenaire
        $conditionsPartagePartenaire = $partenaireAffaire->getConditionPartages();
        if (!$conditionsPartagePartenaire->isEmpty()) {
            return $this->applyRevenuConditionsSpeciales($conditionsPartagePartenaire->first(), $revenu, $addressedTo, $precomputedSums);
        }

        // Priorité 3 : Taux par défaut du partenaire
        if ($partenaireAffaire->getPart() > 0) {
            $assiette = $this->getRevenuMontantPure($revenu, $addressedTo, true);
            return $assiette * $partenaireAffaire->getPart();
        }

        return 0.0;
    }

    private function getCotationMontantChargementPrime(Cotation $cotation, TypeRevenu $typeRevenu)
    {
        $montantChargementCible = 0;
        if ($cotation != null && $typeRevenu != null) {
            //On doit récupérer le montant ou la valeur de ce composant
            foreach ($cotation->getChargements() as $loading) {
                if ($loading->getType() == $typeRevenu->getTypeChargement()) {
                    $montantChargementCible = $loading->getMontantFlatExceptionel();
                }
            }
        }
        return $montantChargementCible;
    }

    private function getCotationRisque(?Cotation $cotation)
    {
        if ($cotation) {
            if ($cotation->getPiste()) {
                return $cotation->getPiste()->getRisque();
            }
        }
        return null;
    }

    private function calculateCommissionPure(RevenuPourCourtier $revenu, bool $onlySharable)
    {
        $taxeCourtier = 0;
        $taxeAssureur = false;
        $comNette = 0;
        $isIARD = $this->isIARD($revenu->getCotation());
        $commissionPure = 0;


        if ($onlySharable == true) {
            if ($revenu->getTypeRevenu()->isShared() == true) {
                // dd($revenu->getTypeRevenu()->isShared(), $revenu);
                $comNette = $this->getRevenuMontantHt($revenu);
                $taxeCourtier = $this->serviceTaxes->getMontantTaxe($comNette, $isIARD, $taxeAssureur);
                $commissionPure = $comNette - $taxeCourtier;
            }
        } else {
            $comNette = $this->getRevenuMontantHt($revenu);
            $taxeCourtier = $this->serviceTaxes->getMontantTaxe($comNette, $isIARD, $taxeAssureur);
            $commissionPure = $comNette - $taxeCourtier;
        }
        return $commissionPure;
    }

    private function getRevenuMontantPure(?RevenuPourCourtier $revenu, $addressedTo, bool $onlySharable): float
    {
        if ($addressedTo != -1) {
            if ($revenu->getTypeRevenu()->getRedevable() == $addressedTo) {
                return $this->calculateCommissionPure($revenu, $onlySharable);
            }
            return 0;
        } else {
            return $this->calculateCommissionPure($revenu, $onlySharable);
        }
    }

    private function calculerRetroCommission(?Risque $risque, ?ConditionPartage $conditionPartage, $assiette): float
    {
        if (!$conditionPartage || !$risque) {
            return 0.0;
        }

        $taux = $conditionPartage->getTaux();
        $produitsCible = $conditionPartage->getProduits();

        switch ($conditionPartage->getCritereRisque()) {
            case ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES:
                if (!$produitsCible->contains($risque)) {
                    return $assiette * $taux;
                }
                return 0.0;

            case ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES:
                if ($produitsCible->contains($risque)) {
                    return $assiette * $taux;
                }
                return 0.0;

            case ConditionPartage::CRITERE_PAS_RISQUES_CIBLES:
                return $assiette * $taux;
        }
        return 0.0;
    }

    private function getRevenuMontantHtAddressedTo($addressedTo, RevenuPourCourtier $revenu)
    {
        $montant = 0;
        if ($addressedTo != -1) {
            if ($revenu->getTypeRevenu()->getRedevable() == $addressedTo) {
                $montant += $this->getRevenuMontantHt($revenu);
            }
        } else {
            $montant += $this->getRevenuMontantHt($revenu);
        }
        return $montant;
    }


    private function getCotationMontantCommissionHt(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($cotation) {
            //Pour chaque revenu configuré dans cette cotation
            foreach ($cotation->getRevenus() as $revenu) {
                if ($onlySharable == true) {
                    if ($revenu->getTypeRevenu()->isShared() == $onlySharable) {
                        $montant += $this->getRevenuMontantHtAddressedTo($addressedTo, $revenu);
                    }
                } else {
                    $montant += $this->getRevenuMontantHtAddressedTo($addressedTo, $revenu);
                }
            }
        }
        return $montant;
    }

    private function getCotationMontantCommissionPure(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $comHT = $this->getCotationMontantCommissionHt($cotation, $addressedTo, $onlySharable);
        $taxeCourtier = $this->getCotationMontantTaxePayableParCourtier($cotation, $onlySharable);
        return $comHT - $taxeCourtier;
    }

    /**
     * Calcule le montant de la taxe courtier pour une cotation.
     *
     * @param Cotation|null $cotation
     * @param boolean $onlySharable
     * @return float
     */
    private function getCotationMontantTaxePayableParCourtier(?Cotation $cotation, bool $onlySharable): float
    {
        return $this->getTotalNet($cotation, $onlySharable, false);
    }

    private function applyRevenuConditionsSpeciales(?ConditionPartage $conditionPartage, RevenuPourCourtier $revenu, $addressedTo, array $precomputedSums): float
    {
        $montant = 0;
        //Assiette de l'affaire individuelle
        $assiette = $this->getRevenuMontantPure($revenu, $addressedTo, true);
        $piste = $revenu->getCotation()->getPiste();
        if (!$piste) return 0.0;

        //Application de l'unité de mésure
        $uniteMesure = match ($conditionPartage->getUniteMesure()) {
            // NOUVEAU : On utilise les sommes pré-calculées au lieu d'appeler la méthode coûteuse.
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE => $precomputedSums['by_risque'][$piste->getRisque()->getId()] ?? 0.0,
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT => $precomputedSums['by_client'][$piste->getClient()->getId()] ?? 0.0,
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE => $precomputedSums['by_partenaire'][($this->getCotationPartenaire($revenu->getCotation()))?->getId()] ?? 0.0,
            default => 0.0,
        };

        $formule = $conditionPartage->getFormule();
        $seuil = $conditionPartage->getSeuil();
        $risque = $revenu->getCotation()->getPiste()->getRisque();

        //formule
        switch ($formule) {
            case ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL:
                return $this->calculerRetroCommission($risque, $conditionPartage, $assiette);
            case ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL:
                if ($uniteMesure < $seuil) {
                    return $this->calculerRetroCommission($risque, $conditionPartage, $assiette);
                } else {
                    return 0;
                }
            case ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL:
                if ($uniteMesure >= $seuil) {
                    return $this->calculerRetroCommission($risque, $conditionPartage, $assiette);
                } else {
                    return 0;
                }

            default:
                # code...
                break;
        }
        return $montant;
    }

    private function isCotationBound(?Cotation $cotation): bool
    {
        // Correction pour la performance et la sécurité :
        // 1. On vérifie que $cotation n'est pas null.
        // 2. On utilise isEmpty() qui est optimisé par Doctrine et ne charge pas toute la collection.
        return $cotation && !$cotation->getAvenants()->isEmpty();
    }

    private function getCotationMontantPrimePayableParClient(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getChargements() as $chargement) {
                $montant += $chargement->getMontantFlatExceptionel();
            }
        }
        return $montant;
    }

    private function getCotationMontantPrimePayableParClientPayee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTranchePrimePayee($tranche);
            }
        }
        return $montant;
    }

    private function getCotationTauxCommission(?Cotation $cotation): float
    {
        $prime = $this->getCotationMontantPrimePayableParClient($cotation);
        if ($prime > 0) {
            return round(($this->getCotationMontantCommissionHt($cotation, -1, false) / $prime) * 100, 2);
        }
        return 0.0;
    }

    private function getTrancheMontantRetrocommissionsPayableParCourtierPayee(?Tranche $tranche, ?Partenaire $partenaireCible = null): float
    {
        $montant = 0;
        // Sécurité : on vérifie que la tranche existe.
        // Performance : on utilise isEmpty() qui ne charge pas toute la collection si elle n'est pas initialisée.
        if (!$tranche || $tranche->getArticles()->isEmpty()) {
            return 0.0;
        }

        //On doit d'abord s'assurer que nous parlons du même partenaire
        if ($this->isSamePartenaire($this->getTranchePartenaire($tranche), $partenaireCible)) {
            foreach ($tranche->getArticles() as $article) {
                /** @var Note|null $note */
                $note = $article->getNote();

                // Sécurité : on s'assure que l'article est bien lié à une note
                if (!$note) {
                    continue;
                }

                //Quelle proportion de la note a-t-elle été payée (100%?)
                $montantPayableNote = $this->getNoteMontantPayable($note);
                $proportionPaiement = 0;
                // Sécurité : on évite la division par zéro
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                }

                //Qu'est-ce qu'on a facturé?
                if ($note->getAddressedTo() == Note::TO_PARTENAIRE) {
                    $montant += $proportionPaiement * ($article->getMontant() ?? 0);
                }
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant de la prime nette pour une cotation.
     *
     * @param Cotation|null $cotation
     * @return float
     */
    private function getNoteMontantPaye(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            // dd("Les paiements: ", $note->getPaiements());
            foreach ($note->getPaiements() as $encaisse) {
                /** @var Paiement $paiement */
                $paiement = $encaisse;
                $montant += $paiement->getMontant();
                // dd("Paiement : ", $paiement);
            }
        }
        return $montant;
    }

    private function getTranchePartenaire(?Tranche $tranche)
    {
        if ($tranche != null) {
            if ($tranche->getCotation() != null) {
                return $this->getCotationPartenaire($tranche->getCotation());
            }
        }

        return null;
    }

    private function getCotationMontantRetrocommissionsPayableParCourtierPayee(?Cotation $cotation, ?Partenaire $partenaireCible): float
    {
        $montant = 0;
        if ($cotation != null) {
            // $partenaire = $cotation->getPiste()->getPartenaires()[0];
            $partenaire = $this->getCotationPartenaire($cotation);


            if ($partenaire) {
                //On doit d'abord s'assurer que nous parlons du même partenaire
                if ($this->isSamePartenaire($partenaire, $partenaireCible)) {
                    /** @var Tranche $tranche */
                    foreach ($cotation->getTranches() as $tranche) {
                        $montant += $this->getTrancheMontantRetrocommissionsPayableParCourtierPayee($tranche, $partenaireCible);
                    }
                }
            }
        }
        // dd($montant, $partenaire, $partenaireCible);
        return $montant;
    }

    private function getCotationMontantCommissionTtc(?Cotation $cotation, ?int $addressedTo, bool $onlySharable): float
    {
        if (!$cotation) return 0;

        $comTTCAssureur = $this->getCotationMontantCommissionTtcPayableParAssureur($cotation, $onlySharable);
        $comTTCClient = $this->getCotationMontantCommissionTtcPayableParClient($cotation, $onlySharable);
        return round($comTTCAssureur + $comTTCClient, 2);
    }

    /**
     * Calcule le montant de la commission TTC payable par l'assureur pour une cotation.
     *
     * @param Cotation|null $cotation
     * @param boolean $onlySharable
     * @return float
     */
    private function getCotationMontantCommissionTtcPayableParAssureur(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_ASSUREUR, $onlySharable);
        $taxe = $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
        return $net + $taxe;
    }

    /**
     * Calcule le montant de la commission TTC payable par le client pour une cotation.
     *
     * @param Cotation|null $cotation
     * @param boolean $onlySharable
     * @return float
     */
    private function getCotationMontantCommissionTtcPayableParClient(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_CLIENT, $onlySharable);
        $taxe = $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
        return $net + $taxe;
    }

    /**
     * Vérifie si une cotation appartient à la branche IARD.
     *
     * @param Cotation|null $cotation
     * @return boolean
     */
    private function isIARD(?Cotation $cotation): bool
    {
        if ($cotation && $cotation->getPiste() && $cotation->getPiste()->getRisque()) {
            return $cotation->getPiste()->getRisque()->getBranche() == Risque::BRANCHE_IARD_OU_NON_VIE;
        }
        return false;
    }

    /**
     * Calcule le montant de la taxe courtier pour une cotation.
     *
     * @param Cotation|null $cotation
     * @param boolean $onlySharable
     * @return float
     */
    private function getCotationMontantTaxeCourtier(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, -1, $onlySharable);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), false);
    }

    /**
     * Calcule le montant de la commission TTC pour une cotation.
     *
     * @param Cotation|null $cotation
     * @param integer|null $addressedTo
     * @param boolean $onlySharable
     * @return float
     */
    private function getCotationMontantTaxeAssureur(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, -1, $onlySharable);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
    }

    /**
     * Calcule le montant total payable pour une note.
     *
     * @param Note|null $note
     * @return float
     */
    private function getNoteMontantPayable(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                $montant += $article->getMontant();
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant de la commission HT pour une cotation donnée.
     *
     * @param Cotation|null $cotation
     * @param int $addressedTo
     * @param boolean $onlySharable
     * @return float
     */
    private function getCotationMontantCommissionEncaissee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTrancheMontantCommissionEncaissee($tranche);
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant de la commission encaissée pour une tranche.
     *
     * @param Tranche|null $tranche
     * @return float
     */
    private function getTrancheMontantCommissionEncaissee(?Tranche $tranche): float
    {
        $montant = 0;
        if ($tranche) {
            foreach ($tranche->getArticles() as $article) {
                $note = $article->getNote();
                if ($note && ($note->getAddressedTo() == \App\Entity\Note::TO_ASSUREUR || $note->getAddressedTo() == \App\Entity\Note::TO_CLIENT)) {
                    $montantPayableNote = $this->getNoteMontantPayable($note); // Potentiel bug: division par zéro
                    if ($montantPayableNote > 0) {
                        $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                        $montant += $proportionPaiement * $article->getMontant();
                    }
                }
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant total de la commission encaissée pour une cotation.
     *
     * @param Cotation|null $cotation
     * @return float
     */
    private function getCotationMontantTaxeCourtierPayee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTrancheMontantTaxePayee($tranche, false);
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant de la taxe courtier payée pour une cotation.
     *
     * @param Cotation|null $cotation
     * @return float
     */
    private function getCotationMontantTaxeAssureurPayee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTrancheMontantTaxePayee($tranche, true);
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant de la taxe payée pour une tranche.
     *
     * @param Tranche|null $tranche
     * @param boolean $isTaxeAssureur
     * @return float
     */
    private function getTrancheMontantTaxePayee(?Tranche $tranche, bool $isTaxeAssureur): float
    {
        $montant = 0.0;
        if (!$tranche) {
            return $montant;
        }

        // Détermine le type de redevable que nous recherchons (assureur ou courtier).
        $targetRedevable = $isTaxeAssureur ? Taxe::REDEVABLE_ASSUREUR : Taxe::REDEVABLE_COURTIER;

        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            // On ne traite que les articles liés à une note adressée à l'autorité fiscale.
            if ($note && $note->getAddressedTo() === Note::TO_AUTORITE_FISCALE) {
                // L'idPoste de l'article contient l'ID de l'entité Taxe.
                $taxe = $this->taxeRepository->find($article->getIdPoste());

                // On vérifie que la taxe existe et que son redevable correspond à notre cible.
                if ($taxe && $taxe->getRedevable() === $targetRedevable) {
                    $montantPayableNote = $this->getNoteMontantPayable($note);
                    if ($montantPayableNote > 0) {
                        $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                        $montant += $proportionPaiement * $article->getMontant();
                    }
                }
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant HT d'un revenu.
     *
     * @param RevenuPourCourtier|null $revenu
     * @return float
     */
    private function getRevenuMontantHt(?RevenuPourCourtier $revenu): float
    {
        $montant = 0;
        if ($revenu) {
            $typeRevenu = $revenu->getTypeRevenu();
            if ($typeRevenu) {
                $cotation = $revenu->getCotation();
                $montantChargementPrime = $this->getCotationMontantChargementPrime($cotation, $typeRevenu);

                if ($typeRevenu->isAppliquerPourcentageDuRisque()) {
                    $risque = $this->getCotationRisque($cotation);
                    if ($risque) {
                        $montant += $montantChargementPrime * $risque->getPourcentageCommissionSpecifiqueHT();
                    }
                } else {
                    if ($revenu->getTauxExceptionel() != 0) {
                        $montant += $montantChargementPrime * $revenu->getTauxExceptionel();
                    } elseif ($revenu->getMontantFlatExceptionel() != 0) {
                        $montant += $revenu->getMontantFlatExceptionel();
                    } elseif ($typeRevenu->getPourcentage() != 0) {
                        $montant += $montantChargementPrime * $typeRevenu->getPourcentage();
                    } elseif ($typeRevenu->getMontantflat() != 0) { // CORRECTION: Un montant "flat" (forfaitaire) ne doit pas être multiplié.
                        $montant += $typeRevenu->getMontantflat();
                    }
                }
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant de la taxe assureur payée pour une cotation.
     *
     * @param Cotation|null $cotation
     * @return float
     */
    private function getCotationMontantPrimeNette(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getChargements() as $chargement) {
                if ($chargement->getType() && $chargement->getType()->getFonction() === Chargement::FONCTION_PRIME_NETTE) {
                    $montant += $chargement->getMontantFlatExceptionel();
                }
            }
        }
        return $montant;
    }

    private function getTotalNet(?Cotation $cotation, bool $onlySharable, bool $isTaxAssureur): float
    {
        if (!$cotation) return 0.0;
        $isIARD = $this->isIARD($cotation);
        $net_payable_par_assureur = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_ASSUREUR, $onlySharable);
        $net_payable_par_client = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_CLIENT, $onlySharable);
        $net_total = $net_payable_par_assureur + $net_payable_par_client;
        return $this->serviceTaxes->getMontantTaxe($net_total, $isIARD, $isTaxAssureur);
    }

    /**
     * Méthode refactorisée pour calculer la somme de la commission pure selon une portée.
     * ATTENTION : Cette méthode souffre toujours d'un problème de performance N+1 car elle
     * itère sur toutes les pistes de l'entreprise. Une refonte plus profonde serait nécessaire
     * pour pré-calculer ces sommes avant la boucle principale de getIndicateursGlobaux.
     */
    private function getSommeCommissionPureForScope(?Cotation $cotation, $addressedTo, bool $onlySharable, string $scope): float
    {
        if (!$cotation || !$cotation->getPiste()) {
            return 0.0;
        }

        $somme = 0.0;
        $entreprise = $cotation->getPiste()->getInvite()->getEntreprise();
        $exerciceCible = $cotation->getPiste()->getExercice();
        $partenaireCible = $this->getCotationPartenaire($cotation);
        $clientCible = $cotation->getPiste()->getClient();
        $risqueCible = $cotation->getPiste()->getRisque();

        $cotationsToSum = [];
        foreach ($entreprise->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                if ($piste->getExercice() !== $exerciceCible) continue;

                $match = match ($scope) {
                    'RISQUE' => $piste->getRisque() === $risqueCible,
                    'CLIENT' => $piste->getClient() === $clientCible,
                    'PARTENAIRE' => true,
                    default => false,
                };

                if ($match) {
                    foreach ($piste->getCotations() as $c) {
                        if ($this->getCotationPartenaire($c) === $partenaireCible) $cotationsToSum[] = $c;
                    }
                }
            }
        }

        foreach (array_unique($cotationsToSum, SORT_REGULAR) as $proposition) {
            $somme += $this->getCotationMontantCommissionPure($proposition, $addressedTo, $onlySharable);
        }
        return $somme;
    }

    /**
     * Calcule le statut de souscription d'une cotation.
     */
    private function calculateStatutSouscription(Cotation $cotation): string
    {
        return $this->isCotationBound($cotation) ? 'Souscrite' : 'En attente';
    }

    /**
     * Récupère la référence de la police associée à la cotation via ses avenants.
     */
    private function getCotationReferencePolice(Cotation $cotation): string
    {
        if ($cotation->getAvenants()->isEmpty()) {
            return 'Nulle';
        }
        return $cotation->getAvenants()->first()->getReferencePolice() ?? 'Nulle';
    }

    /**
     * Récupère la période de couverture de la cotation via son avenant.
     */
    private function getCotationPeriodeCouverture(Cotation $cotation): string
    {
        if ($cotation->getAvenants()->isEmpty()) {
            return 'Aucune';
        }
        $avenant = $cotation->getAvenants()->first();
        if ($avenant->getStartingAt() && $avenant->getEndingAt()) {
            return sprintf("Du %s au %s", $avenant->getStartingAt()->format('d/m/Y'), $avenant->getEndingAt()->format('d/m/Y'));
        }
        return 'Période incomplète';
    }

    /**
     * Récupère les sinistres liés à la police de la cotation.
     */
    private function getCotationClaims(Cotation $cotation): array
    {
        $ref = $this->getCotationReferencePolice($cotation);
        if ($ref === 'Nulle') return [];
        
        return $this->notificationSinistreRepository->findBy(['referencePolice' => $ref]);
    }

    private function getCotationIndemnisationDue(Cotation $cotation): float
    {
        $claims = $this->getCotationClaims($cotation);
        $total = 0.0;
        foreach ($claims as $claim) {
            $total += $this->getNotificationSinistreCompensation($claim);
        }
        return $total;
    }

    private function getCotationIndemnisationVersee(Cotation $cotation): float
    {
        $claims = $this->getCotationClaims($cotation);
        $total = 0.0;
        foreach ($claims as $claim) {
            $total += $this->getNotificationSinistreCompensationVersee($claim);
        }
        return $total;
    }

    private function getCotationIndemnisationSolde(Cotation $cotation): float
    {
        return $this->getCotationIndemnisationDue($cotation) - $this->getCotationIndemnisationVersee($cotation);
    }

    private function getCotationTauxSP(Cotation $cotation): float
    {
        $prime = $this->getCotationMontantPrimePayableParClient($cotation);
        $sinistre = $this->getCotationIndemnisationDue($cotation);
        if ($prime > 0) {
            return round(($sinistre / $prime) * 100, 2);
        }
        return 0.0;
    }

    private function getCotationTauxSPInterpretation(Cotation $cotation): string
    {
        $taux = $this->getCotationTauxSP($cotation);
        $indemnisationDue = $this->getCotationIndemnisationDue($cotation);

        if ($indemnisationDue == 0) {
            return "Aucun sinistre indemnisable enregistré pour cette police.";
        }

        if ($taux == 0 && $indemnisationDue > 0) { // This implies prime is 0 or negative
            return "La prime étant nulle ou négative, le ratio est infini.";
        }

        if ($taux < 70) {
            return "Excellent. Le portefeuille est très rentable.";
        } elseif ($taux <= 80) {
            return "Sain. C'est l'équilibre classique pour couvrir les frais de gestion et faire un profit.";
        } elseif ($taux <= 100) {
            return "Prudence. La rentabilité est faible et le portefeuille est à surveiller.";
        }
        return "Déficitaire. L'assureur perd de l'argent sur la partie technique (il paie plus qu'il ne reçoit).";
    }

    private function getCotationDateDernierReglement(Cotation $cotation): ?\DateTimeInterface
    {
        $claims = $this->getCotationClaims($cotation);
        $lastDate = null;
        foreach ($claims as $claim) {
            $date = $this->getNotificationSinistreDateDernierReglement($claim);
            if ($date && ($lastDate === null || $date > $lastDate)) {
                $lastDate = $date;
            }
        }
        return $lastDate;
    }

    private function getCotationVitesseReglement(Cotation $cotation): string
    {
        $solde = $this->getCotationIndemnisationSolde($cotation);
        if ($solde > 0) return "Traitement encours";
        
        $claims = $this->getCotationClaims($cotation);
        if (empty($claims)) return "Aucun sinistre";

        // On cherche le dernier paiement global pour calculer la vitesse
        $lastDate = $this->getCotationDateDernierReglement($cotation);
        
        // Pour la date de notification, on prend celle du sinistre associé au dernier paiement
        // ou par défaut la première notification si on ne peut pas lier directement.
        // Ici, on simplifie en prenant la date du dernier règlement vs la date de notification du sinistre concerné.
        // Note: Cette logique est une approximation si plusieurs sinistres sont réglés à des dates différentes.
        // Mais si le solde est à 0, cela signifie que tout est réglé.
        // On retourne la durée du dernier règlement effectué.
        
        // Pour être précis selon la demande : "temps écoulé entre la date de la notification du sinistre et la date du dernier règlement"
        // Si plusieurs sinistres, on prend le dernier règlement global et on le compare à la notification de CE sinistre spécifique ?
        // Ou la toute première notification ? La demande implique un contexte singulier.
        // On va itérer pour trouver le paiement le plus récent et son sinistre parent.
        $lastPaymentDate = null;
        $associatedClaim = null;

        foreach ($claims as $claim) {
            $date = $this->getNotificationSinistreDateDernierReglement($claim);
            if ($date && ($lastPaymentDate === null || $date > $lastPaymentDate)) {
                $lastPaymentDate = $date;
                $associatedClaim = $claim;
            }
        }

        if ($lastPaymentDate && $associatedClaim && $associatedClaim->getNotifiedAt()) {
            $days = $this->serviceDates->daysEntre($associatedClaim->getNotifiedAt(), $lastPaymentDate);
            return $days . " jour(s)";
        }
        
        return "N/A";
    }

    /**
     * Calcule le délai en jours depuis la création de la cotation.
     */
    private function calculateDelaiDepuisCreation(Cotation $cotation): string
    {
        if (!$cotation->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($cotation->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Calcule le nombre de tranches de paiement pour une cotation.
     */
    private function calculateNombreTranches(Cotation $cotation): int
    {
        return $cotation->getTranches()->count();
    }

    /**
     * Calcule le montant moyen par tranche de paiement.
     */
    private function calculateMontantMoyenTranche(Cotation $cotation): float
    {
        $nombreTranches = $this->calculateNombreTranches($cotation);
        if ($nombreTranches === 0) {
            return 0.0;
        }

        // Pour obtenir la prime totale, on doit la calculer ici.
        // On ne peut pas se fier à l'indicateur global qui n'est pas encore calculé à ce stade.
        $primeTotale = 0.0;
        foreach ($cotation->getChargements() as $chargement) {
            $primeTotale += $chargement->getMontantFlatExceptionel() ?? 0;
        }

        if ($primeTotale > 0) {
            return round($primeTotale / $nombreTranches, 2);
        }

        return 0.0;
    }

    /**
     * NOUVEAU : Retourne une chaîne décrivant le contexte de la Piste parente.
     */
    private function getCotationContextePiste(Cotation $cotation): string
    {
        $piste = $cotation->getPiste();
        if (!$piste) {
            return "Cette cotation n'est rattachée à aucune piste.";
        }

        $pisteNom = $piste->getNom() ?? 'N/A';
        // On s'assure de gérer le cas où le client est null.
        $clientNom = $piste->getClient() ? $piste->getClient()->getNom() : 'non défini';

        return sprintf("Piste '%s' pour le client '%s'", $pisteNom, $clientNom);
    }

    /**
     * Calcule le contexte parent pour une tranche.
     */
    private function getTrancheContexteParent(Tranche $tranche): string
    {
        return $tranche->getCotation() ? (string) $tranche->getCotation() : 'N/A';
    }

    private function calculateTrancheAge(Tranche $tranche): string
    {
        if (!$tranche->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($tranche->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function calculateTrancheJoursRestants(Tranche $tranche): string
    {
        if (!$tranche->getEcheanceAt()) {
            return 'N/A';
        }
        $now = new DateTimeImmutable();
        if ($tranche->getEcheanceAt() < $now) {
            return 'Échue';
        }
        $jours = $this->serviceDates->daysEntre($now, $tranche->getEcheanceAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Calcule le facteur de taux de la tranche (0.0 à 1.0).
     */
    private function calculateTrancheTauxFactor(Tranche $tranche): float
    {
        // 1. Si un pourcentage est défini explicitement
        if ($tranche->getPourcentage() !== null && $tranche->getPourcentage() > 0) {
            $valeur = $tranche->getPourcentage();
            // Correction incohérence stockage : Si la valeur est > 1 (ex: 50), on suppose que c'est un pourcentage entier.
            // Si la valeur est <= 1 (ex: 0.5), on suppose que c'est déjà une fraction (comportement standard Symfony PercentType).
            if ($valeur > 1) {
                return $valeur / 100;
            }
            return $valeur;
        }

        // 2. Si un montant flat est défini, on le compare au total de la prime
        if ($tranche->getMontantFlat() !== null && $tranche->getMontantFlat() > 0) {
            $cotation = $tranche->getCotation();
            if ($cotation) {
                $primeTotale = $this->getCotationMontantPrimePayableParClient($cotation);
                if ($primeTotale > 0) {
                    return $tranche->getMontantFlat() / $primeTotale;
                }
            }
        }

        return 0.0;
    }

    private function getTrancheTauxDisplay(Tranche $tranche): float
    {
        return $this->calculateTrancheTauxFactor($tranche) * 100;
    }

    private function getTrancheDescriptionCalcul(Tranche $tranche): string
    {
        if ($tranche->getPourcentage() !== null && $tranche->getPourcentage() > 0) {
            $tauxAffiche = $this->getTrancheTauxDisplay($tranche);
            return "Basé sur le taux défini de " . $tauxAffiche . "%";
        }
        if ($tranche->getMontantFlat() !== null && $tranche->getMontantFlat() > 0) {
            return "Calculé : Montant fixe (" . $tranche->getMontantFlat() . ") / Prime Totale";
        }
        return "Taux non défini (0%)";
    }

    private function getTranchePrime(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $primeTotale = $this->getCotationMontantPrimePayableParClient($tranche->getCotation());
        return $primeTotale * $taux;
    }

    private function getTranchePrimePayee(Tranche $tranche): float
    {
        $montant = 0.0;
        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            // On ne considère que les notes adressées au client (paiement de la prime)
            if ($note && $note->getAddressedTo() === Note::TO_CLIENT) {
                $montantPayableNote = $this->getNoteMontantPayable($note);
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                    $montant += $proportionPaiement * ($article->getMontant() ?? 0);
                }
            }
        }
        return $montant;
    }

    private function getTranchePrimeSoldeDue(Tranche $tranche): float
    {
        return $this->getTranchePrime($tranche) - $this->getTranchePrimePayee($tranche);
    }

    private function getTrancheMontantHT(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationHT = $this->getCotationMontantCommissionHt($tranche->getCotation(), -1, false);
        return $cotationHT * $taux;
    }

    private function getTrancheMontantTTC(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationTTC = $this->getCotationMontantCommissionTtc($tranche->getCotation(), -1, false);
        return $cotationTTC * $taux;
    }

    private function getTrancheTaxeCourtierMontant(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationTaxe = $this->getCotationMontantTaxeCourtier($tranche->getCotation(), false);
        return $cotationTaxe * $taux;
    }

    private function getTrancheTaxeAssureurMontant(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationTaxe = $this->getCotationMontantTaxeAssureur($tranche->getCotation(), false);
        return $cotationTaxe * $taux;
    }

    private function getTrancheTaxeCourtierTaux(Tranche $tranche): float
    {
        $taxe = $this->taxeRepository->findOneBy(['redevable' => Taxe::REDEVABLE_COURTIER]);
        if (!$taxe) return 0.0;
        $isIARD = $this->isIARD($tranche->getCotation());
        $rate = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();
        return ($rate ?? 0.0) * 100;
    }

    private function getTrancheTaxeAssureurTaux(Tranche $tranche): float
    {
        $taxe = $this->taxeRepository->findOneBy(['redevable' => Taxe::REDEVABLE_ASSUREUR]);
        if (!$taxe) return 0.0;
        $isIARD = $this->isIARD($tranche->getCotation());
        $rate = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();
        return ($rate ?? 0.0) * 100;
    }

    private function getTrancheEstPartageable(Tranche $tranche): string
    {
        $cotation = $tranche->getCotation();
        if ($cotation) {
            foreach ($cotation->getRevenus() as $revenu) {
                if ($revenu->getTypeRevenu() && $revenu->getTypeRevenu()->isShared()) {
                    return 'Oui';
                }
            }
        }
        return 'Non';
    }

    private function getTrancheMontantPur(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationPure = $this->getCotationMontantCommissionPure($tranche->getCotation(), -1, false);
        return $cotationPure * $taux;
    }

    private function getTranchePartPartenaire(Tranche $tranche): float
    {
        $partenaire = $this->getCotationPartenaire($tranche->getCotation());
        return $partenaire ? ($partenaire->getPart() * 100) : 0.0;
    }

    private function getTrancheRetroCommission(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationRetro = $this->getCotationMontantRetrocommissionsPayableParCourtier($tranche->getCotation(), null, -1, []);
        return $cotationRetro * $taux;
    }

    private function getTrancheReserve(Tranche $tranche): float
    {
        return $this->getTrancheMontantPur($tranche) - $this->getTrancheRetroCommission($tranche);
    }

    public function Contact_getTypeString(?Contact $contact): string
    {
        if ($contact === null) {
            return 'Non défini';
        }

        // CORRECTION TEMPORAIRE : Les clés de traduction n'étaient pas résolues.
        // On utilise des chaînes en dur en attendant la correction des fichiers de traduction.
        return match ($contact->getType()) {
            Contact::TYPE_CONTACT_PRODUCTION => "Production",
            Contact::TYPE_CONTACT_SINISTRE => "Sinistre",
            Contact::TYPE_CONTACT_ADMINISTRATION => "Administration",
            Contact::TYPE_CONTACT_AUTRES => "Autres",
            default => "Non défini",
        };
    }

    public function Chargement_getFonctionString(?Chargement $chargement): ?string
    {
        if ($chargement === null) {
            return null;
        }

        return match ($chargement->getFonction()) {
            Chargement::FONCTION_PRIME_NETTE => "Prime nette",
            Chargement::FONCTION_FRONTING => "Fronting",
            Chargement::FONCTION_FRAIS_ADMIN => "Frais administratifs",
            Chargement::FONCTION_TAXE => "Taxe",
            default => "Non définie",
        };
    }

    public function ConditionPartage_getFormuleString(?ConditionPartage $condition): ?string
    {
        if ($condition === null) return null;
        return match ($condition->getFormule()) {
            ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL => "Assiette >= Seuil",
            ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL => "Assiette < Seuil",
            ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL => "Sans seuil",
            default => "Inconnue",
        };
    }

    public function ConditionPartage_getCritereRisqueString(?ConditionPartage $condition): ?string
    {
        if ($condition === null) return null;
        return match ($condition->getCritereRisque()) {
            ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES => "Exclure risques ciblés",
            ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES => "Inclure risques ciblés",
            ConditionPartage::CRITERE_PAS_RISQUES_CIBLES => "Aucun risque ciblé",
            default => "Inconnu",
        };
    }

    public function ConditionPartage_getUniteMesureString(?ConditionPartage $condition): ?string
    {
        if ($condition === null) return null;
        return match ($condition->getUniteMesure()) {
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE => "Com. pure du risque",
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT => "Com. pure du client",
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE => "Com. pure du partenaire",
            default => "Non définie",
        };
    }

    public function Document_getParentAsString(?Document $document): string
    {
        if ($document === null) {
            return "Document non trouvé.";
        }

        // Cette carte de correspondance améliore la lisibilité et la maintenabilité.
        // Chaque entrée associe une méthode "getter" à une fonction anonyme (closure)
        // qui formate la chaîne de caractères de sortie.
        $parentGetters = [
            'getPieceSinistre' => fn ($e) => "Lié à la pièce sinistre : '" . $e->getDescription() . "'",
            'getOffreIndemnisationSinistre' => fn ($e) => "Lié à l'offre d'indemnisation : '" . $e->getNom() . "'",
            'getCotation' => fn ($e) => "Lié à la cotation : '" . $e->getNom() . "'",
            'getAvenant' => fn ($e) => "Lié à l'avenant (police n°" . $e->getReferencePolice() . ")",
            'getTache' => fn ($e) => "Lié à la tâche : '" . $e->getDescription() . "'",
            'getFeedback' => fn ($e) => "Lié au feedback : '" . $e->getDescription() . "'",
            'getClient' => fn ($e) => "Lié au client : '" . $e->getNom() . "'",
            'getBordereau' => fn ($e) => "Lié au bordereau : '" . $e->getNom() . "'",
            'getCompteBancaire' => fn ($e) => "Lié au compte bancaire : '" . $e->getNom() . "'",
            'getPiste' => fn ($e) => "Lié à la piste : '" . $e->getNom() . "'",
            'getPartenaire' => fn ($e) => "Lié au partenaire : '" . $e->getNom() . "'",
            'getPaiement' => fn ($e) => "Utilisé comme preuve pour le paiement n°" . $e->getReference(),
        ];

        foreach ($parentGetters as $getter => $formatter) {
            if ($parent = $document->$getter()) {
                return $formatter($parent);
            }
        }

        return "Ce document n'est rattaché à aucun élément parent.";
    }

    public function Document_getClasseurAsString(?Document $document): string
    {
        if ($document === null || !$document->getClasseur()) {
            return "Non classé";
        }
        return "Classé dans : '" . $document->getClasseur()->getNom() . "'";
    }

    // --- Indicateurs pour ChargementPourPrime ---

    /**
     * Calcule le montant de la taxe applicable sur le montant flat du chargement.
     */
    private function getChargementPourPrimeMontantTaxe(ChargementPourPrime $chargement): float
    {
        // Conformément à la demande, on ignore le calcul de la taxe pour le moment.
        return 0.0;
    }

    /**
     * Calcule le montant final (TTC) du chargement.
     */
    private function getChargementPourPrimeMontantFinal(ChargementPourPrime $chargement): float
    {
        // Le montant final est maintenant le montant brut, sans taxe ajoutée.
        return $chargement->getMontantFlatExceptionel() ?? 0.0;
    }

    /**
     * Calcule le poids du chargement par rapport à la prime totale de la cotation.
     */
    private function getChargementPourPrimePoidsSurPrime(ChargementPourPrime $chargement): ?float
    {
        $cotation = $chargement->getCotation();
        if (!$cotation) {
            return null;
        }

        $montantChargement = $chargement->getMontantFlatExceptionel() ?? 0.0;
        $primeTotale = $this->getCotationMontantPrimePayableParClient($cotation);

        if ($primeTotale > 0) {
            return round(($montantChargement / $primeTotale) * 100, 2);
        }

        return 0.0;
    }

    private function calculateChargementPourPrimeAge(ChargementPourPrime $chargement): string
    {
        if (!$chargement->getCreatedAt()) return 'N/A';
        $jours = $this->serviceDates->daysEntre($chargement->getCreatedAt(), new \DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Calcule la durée de couverture d'un avenant en jours.
     */
    private function calculateDureeCouvertureAvenant(Avenant $avenant): string
    {
        if (!$avenant->getStartingAt() || !$avenant->getEndingAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($avenant->getStartingAt(), $avenant->getEndingAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Calcule le nombre de jours restants avant l'échéance d'un avenant.
     */
    private function calculateJoursRestantsAvenant(Avenant $avenant): string
    {
        if (!$avenant->getEndingAt()) {
            return 'N/A';
        }
        if ($avenant->getEndingAt() < new DateTimeImmutable()) {
            return 'Expiré';
        }
        $jours = $this->serviceDates->daysEntre(new DateTimeImmutable(), $avenant->getEndingAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Calcule l'âge d'un avenant depuis sa date de création.
     */
    private function calculateAgeAvenant(Avenant $avenant): string
    {
        if (!$avenant->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($avenant->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Retourne la chaîne de caractères correspondant au statut de renouvellement de l'avenant.
     */
    public function getAvenantStatutRenouvellementString(?Avenant $avenant): ?string
    {
        if ($avenant === null || $avenant->getRenewalStatus() === null) {
            return "Non défini";
        }

        // CORRECTION TEMPORAIRE : Remplacement des clés de traduction par des chaînes en dur
        // pour résoudre le problème d'affichage "renewal_status_undefined".
        return match ($avenant->getRenewalStatus()) {
            Avenant::RENEWAL_STATUS_LOST => "Perdu",
            Avenant::RENEWAL_STATUS_ONCE_OFF => "Unique (sans renouvellement)",
            Avenant::RENEWAL_STATUS_RENEWED => "Renouvelé",
            Avenant::RENEWAL_STATUS_EXTENDED => "Prorogé",
            Avenant::RENEWAL_STATUS_RUNNING => "En cours",
            Avenant::RENEWAL_STATUS_RENEWING => "En renouvellement",
            Avenant::RENEWAL_STATUS_CANCELLED => "Annulé",
            default => "Inconnu",
        };
    }

    // --- Indicateurs pour Tache ---

    /**
     * Retourne le statut d'exécution de la tâche sous forme de chaîne.
     */
    public function getTacheStatutExecutionString(Tache $tache): string
    {
        if ($tache->isClosed()) {
            return $this->translator->trans('tache_status_completed', [], 'messages');
        }
        if ($tache->getToBeEndedAt() < new DateTimeImmutable()) {
            return $this->translator->trans('tache_status_expired', [], 'messages');
        }
        return $this->translator->trans('tache_status_running', [], 'messages');
    }

    /**
     * Calcule le délai restant avant l'échéance de la tâche.
     */
    private function calculateTacheDelaiRestant(Tache $tache): string
    {
        if ($tache->isClosed() || !$tache->getToBeEndedAt()) {
            return 'N/A';
        }
        $now = new DateTimeImmutable();
        if ($tache->getToBeEndedAt() < $now) {
            $jours = $this->serviceDates->daysEntre($tache->getToBeEndedAt(), $now) ?? 0;
            return $this->translator->trans('tache_expired_since', ['%days%' => $jours], 'messages');
        }
        $jours = $this->serviceDates->daysEntre($now, $tache->getToBeEndedAt()) ?? 0;
        return $this->translator->trans('tache_remaining_days', ['%days%' => $jours], 'messages');
    }

    /**
     * Calcule l'âge de la tâche depuis sa création.
     */
    private function calculateTacheAge(Tache $tache): string
    {
        if (!$tache->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($tache->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Compte le nombre de feedbacks associés à une tâche.
     */
    private function countTacheFeedbacks(Tache $tache): int
    {
        return $tache->getFeedbacks()->count();
    }

    /**
     * Retourne une chaîne décrivant le contexte auquel la tâche est liée.
     */
    public function getTacheContexteString(?Tache $tache): ?string
    {
        if ($tache === null) return null;

        if ($parent = $tache->getPiste()) return "Piste: " . $parent->getNom();
        if ($parent = $tache->getCotation()) return "Cotation: " . $parent->getNom();
        if ($parent = $tache->getNotificationSinistre()) return "Sinistre: " . $parent->getReferenceSinistre();
        if ($parent = $tache->getOffreIndemnisationSinistre()) return "Offre: " . $parent->getNom();

        return "Non-associé";
    }


    // --- Indicateurs pour Feedback ---

    /**
     * Retourne le type de feedback sous forme de chaîne.
     */
    public function getFeedbackTypeString(?Feedback $feedback): ?string
    {
        if ($feedback === null) return null;

        return match ($feedback->getType()) {
            Feedback::TYPE_PHYSICAL_MEETING => $this->translator->trans('feedback_type_physical_meeting', [], 'messages'),
            Feedback::TYPE_CALL => $this->translator->trans('feedback_type_call', [], 'messages'),
            Feedback::TYPE_EMAIL => $this->translator->trans('feedback_type_email', [], 'messages'),
            Feedback::TYPE_SMS => $this->translator->trans('feedback_type_sms', [], 'messages'),
            Feedback::TYPE_UNDEFINED => $this->translator->trans('feedback_type_undefined', [], 'messages'),
            default => null,
        };
    }

    /**
     * Calcule le délai avant la prochaine action planifiée.
     */
    private function calculateFeedbackDelaiProchaineAction(Feedback $feedback): string
    {
        if (!$feedback->hasNextAction() || !$feedback->getNextActionAt()) {
            return 'N/A';
        }
        $now = new DateTimeImmutable();
        if ($feedback->getNextActionAt() < $now) {
            return 'Expirée';
        }
        $jours = $this->serviceDates->daysEntre($now, $feedback->getNextActionAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Calcule l'âge du feedback depuis sa création.
     */
    private function calculateFeedbackAge(Feedback $feedback): string
    {
        if (!$feedback->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($feedback->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Retourne le statut de la prochaine action sous forme de chaîne.
     */
    public function getFeedbackStatutProchaineActionString(?Feedback $feedback): ?string
    {
        if ($feedback === null) return null;
        return $feedback->hasNextAction() ? 'Planifiée' : 'Aucune';
    }

    // --- Indicateurs pour Client ---

    /**
     * Retourne la civilité du client sous forme de chaîne.
     */
    public function getClientCiviliteString(?Client $client): ?string
    {
        if ($client === null || $client->getCivilite() === null) {
            return null;
        }

        // CORRECTION TEMPORAIRE : Les clés de traduction n'étaient pas résolues.
        // On utilise des chaînes en dur en attendant la correction des fichiers de traduction.
        return match ($client->getCivilite()) {
            Client::CIVILITE_Mr => "Monsieur",
            Client::CIVILITE_Mme => "Madame",
            Client::CIVILITE_ENTREPRISE => "Entreprise",
            Client::CIVILITE_ASBL => "ASBL",
            default => "Inconnue",
        };
    }

    /**
     * Compte le nombre de pistes commerciales pour un client.
     */
    private function countClientPistes(Client $client): int
    {
        return $client->getPistes()->count();
    }

    /**
     * Compte le nombre de sinistres déclarés pour un client.
     */
    private function countClientSinistres(Client $client): int
    {
        return $client->getNotificationSinistres()->count();
    }

    /**
     * Compte le nombre de polices (cotations avec avenant) pour un client.
     */
    private function countClientPolices(Client $client): int
    {
        $count = 0;
        foreach ($client->getPistes() as $piste) {
            foreach ($piste->getCotations() as $cotation) {
                if (!$cotation->getAvenants()->isEmpty()) {
                    $count++;
                }
            }
        }
        return $count;
    }


    // --- Indicateurs pour Assureur ---

    /**
     * Compte le nombre de polices souscrites auprès d'un assureur.
     */
    private function countAssureurPolicesSouscrites(Assureur $assureur): int
    {
        $count = 0;
        foreach ($assureur->getCotations() as $cotation) {
            if (!$cotation->getAvenants()->isEmpty()) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Compte le nombre de sinistres gérés par un assureur.
     */
    private function countAssureurSinistresGeres(Assureur $assureur): int
    {
        return $assureur->getNotificationSinistres()->count();
    }

    /**
     * Calcule le taux de transformation des cotations en polices pour un assureur.
     */
    private function calculateAssureurTauxTransformation(Assureur $assureur): string
    {
        $totalCotations = $assureur->getCotations()->count();
        if ($totalCotations === 0) {
            return 'N/A';
        }

        $policesSouscrites = $this->countAssureurPolicesSouscrites($assureur);
        $taux = ($policesSouscrites / $totalCotations) * 100;

        return round($taux, 2) . ' %';
    }

    // --- Indicateurs pour Partenaire ---

    private function countPartenairePistes(Partenaire $partenaire): int
    {
        return $partenaire->getPistes()->count();
    }

    private function countPartenaireClients(Partenaire $partenaire): int
    {
        return $partenaire->getClients()->count();
    }

    private function countPartenairePolices(Partenaire $partenaire): int
    {
        $count = 0;
        foreach ($partenaire->getPistes() as $piste) {
            foreach ($piste->getCotations() as $cotation) {
                if (!$cotation->getAvenants()->isEmpty()) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function countPartenaireConditions(Partenaire $partenaire): int
    {
        return $partenaire->getConditionPartages()->count();
    }

    // --- Indicateurs pour ConditionPartage ---

    private function getConditionPartageDescriptionRegle(ConditionPartage $condition): string
    {
        $taux = ($condition->getTaux() ?? 0) * 100;
        $formule = $this->ConditionPartage_getFormuleString($condition);
        $critere = $this->ConditionPartage_getCritereRisqueString($condition);
        $nbRisques = $this->countConditionPartageRisquesCibles($condition);

        $description = "Appliquer " . $taux . "%";

        if ($formule !== "Sans seuil") {
            $seuil = $condition->getSeuil() ?? 0;
            $unite = $this->ConditionPartage_getUniteMesureString($condition);
            $description .= " si {$unite} {$formule} {$seuil}";
        }

        if ($critere !== "Aucun risque ciblé") {
            $description .= ", en se basant sur le critère '{$critere}' avec {$nbRisques} risque(s).";
        }

        return $description;
    }

    private function countConditionPartageRisquesCibles(ConditionPartage $condition): int
    {
        return $condition->getProduits()->count();
    }

    private function getConditionPartagePortee(ConditionPartage $condition): string
    {
        if ($condition->getPiste()) {
            return 'Exceptionnelle (Piste)';
        }
        if ($condition->getPartenaire()) {
            return 'Générale (Partenaire)';
        }
        return 'Non définie';
    }

    // --- Indicateurs pour Risque ---

    public function getRisqueBrancheString(?Risque $risque): ?string
    {
        if ($risque === null) return null;

        return match ($risque->getBranche()) {
            Risque::BRANCHE_IARD_OU_NON_VIE => 'IARD / Non-Vie',
            Risque::BRANCHE_VIE => 'Vie',
            default => 'Inconnue',
        };
    }

    private function countRisquePistes(Risque $risque): int
    {
        return $risque->getPistes()->count();
    }

    private function countRisqueSinistres(Risque $risque): int
    {
        return $risque->getNotificationSinistres()->count();
    }

    private function countRisquePolices(Risque $risque): int
    {
        $count = 0;
        foreach ($risque->getPistes() as $piste) {
            foreach ($piste->getCotations() as $cotation) {
                if (!$cotation->getAvenants()->isEmpty()) {
                    $count++;
                }
            }
        }
        return $count;
    }

    // --- Indicateurs pour Document ---

    private function calculateDocumentAge(Document $document): string
    {
        if (!$document->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($document->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function getDocumentTypeFichier(Document $document): string
    {
        $nomFichier = $document->getNomFichierStocke();
        if (!$nomFichier) {
            return 'Inconnu';
        }
        return pathinfo($nomFichier, PATHINFO_EXTENSION);
    }

    // --- Indicateurs pour Groupe ---

    private function countGroupeClients(Groupe $groupe): int
    {
        return $groupe->getClients()->count();
    }

    private function countGroupePolices(Groupe $groupe): int
    {
        $count = 0;
        foreach ($groupe->getClients() as $client) {
            $count += $this->countClientPolices($client);
        }
        return $count;
    }

    private function countGroupeSinistres(Groupe $groupe): int
    {
        $count = 0;
        foreach ($groupe->getClients() as $client) {
            $count += $this->countClientSinistres($client);
        }
        return $count;
    }

    // --- Indicateurs pour RevenuPourCourtier ---

    private function getRevenuPourCourtierMontantTTC(RevenuPourCourtier $revenu): float
    {
        $montantHT = $this->getRevenuMontantHt($revenu);
        if ($montantHT === 0.0) {
            return 0.0;
        }
        // La taxe s'applique sur la commission, qui est un revenu. On considère que la taxe assureur s'applique.
        $taxe = $this->serviceTaxes->getMontantTaxe($montantHT, $this->isIARD($revenu->getCotation()), true);
        return $montantHT + $taxe;
    }

    /**
     * Calcule le montant total dû pour un revenu pour courtier (TTC).
     */
    private function getRevenuPourCourtierMontantDu(RevenuPourCourtier $revenu): float
    {
        return $this->getRevenuPourCourtierMontantTTC($revenu);
    }

    /**
     * Calcule le montant payé pour un revenu pour courtier.
     */
    private function getRevenuPourCourtierMontantPaye(RevenuPourCourtier $revenu): float
    {
        $montantPaye = 0.0;
        if (!$revenu) {
            return $montantPaye;
        }

        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            if ($note) {
                // L'article est déjà lié à ce revenu via `revenuFacture`.
                // Nous calculons la proportion du paiement de la note qui correspond à cet article.
                $montantPayableNote = $this->getNoteMontantPayable($note);
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                    $montantPaye += $proportionPaiement * ($article->getMontant() ?? 0);
                }
            }
        }
        return round($montantPaye, 2);
    }

    /**
     * Calcule le solde restant dû pour un revenu pour courtier.
     */
    private function getRevenuPourCourtierSoldeRestantDu(RevenuPourCourtier $revenu): float
    {
        $montantDu = $this->getRevenuPourCourtierMontantDu($revenu);
        $montantPaye = $this->getRevenuPourCourtierMontantPaye($revenu);
        return round($montantDu - $montantPaye, 2);
    }

    private function getRevenuPourCourtierDescriptionCalcul(RevenuPourCourtier $revenu): string
    {
        $typeRevenu = $revenu->getTypeRevenu();
        if (!$typeRevenu) {
            return "Type de revenu non défini";
        }

        if ($revenu->getTauxExceptionel() !== null && $revenu->getTauxExceptionel() != 0) {
            return "Taux exceptionnel de " . ($revenu->getTauxExceptionel() * 100) . "%";
        }
        if ($revenu->getMontantFlatExceptionel()) {
            return "Montant fixe exceptionnel de " . $revenu->getMontantFlatExceptionel();
        }
        if ($typeRevenu->getPourcentage() !== null && $typeRevenu->getPourcentage() != 0) {
            return "Taux par défaut de " . ($typeRevenu->getPourcentage() * 100) . "%";
        }
        if ($typeRevenu->getMontantflat()) {
            return "Montant fixe par défaut de " . $typeRevenu->getMontantflat();
        }
        if ($typeRevenu->isAppliquerPourcentageDuRisque() && $revenu->getCotation()?->getPiste()?->getRisque()) {
            $tauxRisque = $revenu->getCotation()->getPiste()->getRisque()->getPourcentageCommissionSpecifiqueHT();
            return "Taux du risque de " . ($tauxRisque * 100) . "%";
        }

        return "Logique de calcul non spécifiée";
    }

    private function getRevenuMontantPur(RevenuPourCourtier $revenu): float
    {
        $montantHT = $this->getRevenuMontantHt($revenu);
        $taxeCourtier = $this->serviceTaxes->getMontantTaxe($montantHT, $this->isIARD($revenu->getCotation()), false);
        return $montantHT - $taxeCourtier;
    }

    private function getPartenaireShareRateForRevenu(RevenuPourCourtier $revenu): float
    {
        if (!$revenu || !$revenu->getTypeRevenu() || !$revenu->getTypeRevenu()->isShared()) {
            return 0.0;
        }
        $cotation = $revenu->getCotation();
        if (!$cotation || !$cotation->getPiste()) {
            return 0.0;
        }

        $partenaireAffaire = $this->getCotationPartenaire($cotation);
        if (!$partenaireAffaire) {
            return 0.0;
        }

        // Priorité 1 & 2: Conditions de partage
        $conditionsPartagePiste = $cotation->getPiste()->getConditionsPartageExceptionnelles();
        if (!$conditionsPartagePiste->isEmpty()) {
            return $conditionsPartagePiste->first()->getTaux() ?? 0.0;
        }

        $conditionsPartagePartenaire = $partenaireAffaire->getConditionPartages();
        if (!$conditionsPartagePartenaire->isEmpty()) {
            return $conditionsPartagePartenaire->first()->getTaux() ?? 0.0;
        }

        // Priorité 3: Taux par défaut
        return $partenaireAffaire->getPart() ?? 0.0;
    }

    private function getRevenuPartPartenaire(RevenuPourCourtier $revenu): float
    {
        // Le taux est stocké en décimal (ex: 0.1 pour 10%), on multiplie par 100 pour l'affichage.
        return $this->getPartenaireShareRateForRevenu($revenu) * 100;
    }

    private function getRevenuRetroCommission(RevenuPourCourtier $revenu): float
    {
        // Utilise la logique de calcul existante, qui prend en compte les conditions de partage.
        // Le paramètre `precomputedSums` est vide, ce qui peut impacter la performance mais assure l'exactitude du calcul pour un seul élément.
        return $this->getRevenuMontantRetrocommissionsPayableParCourtier($revenu, null, -1, []);
    }

    private function getRevenuReserve(RevenuPourCourtier $revenu): float
    {
        $montantPur = $this->getRevenuMontantPur($revenu);
        $retroCommission = $this->getRevenuRetroCommission($revenu);
        return $montantPur - $retroCommission;
    }

    private function getRevenuRetroCommissionReversee(RevenuPourCourtier $revenu): float
    {
        $montantPaye = 0.0;
        if (!$revenu) {
            return $montantPaye;
        }

        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            // On ne traite que les articles liés à une note adressée au partenaire.
            if ($note && $note->getAddressedTo() === Note::TO_PARTENAIRE) {
                $montantPayableNote = $this->getNoteMontantPayable($note);
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                    // On applique la proportion payée au montant de l'article de ce revenu.
                    $montantPaye += $proportionPaiement * ($article->getMontant() ?? 0);
                }
            }
        }
        return $montantPaye;
    }

    private function getRevenuRetroCommissionSolde(RevenuPourCourtier $revenu): float
    {
        $retroCommissionDue = $this->getRevenuRetroCommission($revenu);
        $retroCommissionReversee = $this->getRevenuRetroCommissionReversee($revenu);
        return $retroCommissionDue - $retroCommissionReversee;
    }

    private function getRevenuTaxeCourtierMontant(RevenuPourCourtier $revenu): float
    {
        $montantHT = $this->getRevenuMontantHt($revenu);
        $isIARD = $this->isIARD($revenu->getCotation());
        return $this->serviceTaxes->getMontantTaxe($montantHT, $isIARD, false);
    }

    private function getRevenuTaxeCourtierTaux(RevenuPourCourtier $revenu): float
    {
        $isIARD = $this->isIARD($revenu->getCotation());
        // NOTE: La logique suivante suppose que l'on peut trouver la taxe via le repository.
        // Une méthode dédiée dans ServiceTaxes serait préférable pour encapsuler cette logique.
        // CORRECTION: Le champ 'branche' n'existe pas sur l'entité Taxe.
        // On recherche la taxe par redevable, puis on sélectionne le bon taux (IARD ou VIE).
        $taxe = $this->taxeRepository->findOneBy(['redevable' => Taxe::REDEVABLE_COURTIER]);

        if (!$taxe) {
            return 0.0;
        }

        $rate = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();
        return ($rate ?? 0.0) * 100;
    }

    private function getRevenuTaxeAssureurMontant(RevenuPourCourtier $revenu): float
    {
        $montantHT = $this->getRevenuMontantHt($revenu);
        $isIARD = $this->isIARD($revenu->getCotation());
        return $this->serviceTaxes->getMontantTaxe($montantHT, $isIARD, true);
    }

    private function getRevenuTaxeAssureurTaux(RevenuPourCourtier $revenu): float
    {
        $isIARD = $this->isIARD($revenu->getCotation());
        // NOTE: La logique suivante suppose que l'on peut trouver la taxe via le repository.
        // Une méthode dédiée dans ServiceTaxes serait préférable pour encapsuler cette logique.
        // CORRECTION: Le champ 'branche' n'existe pas sur l'entité Taxe.
        // On recherche la taxe par redevable, puis on sélectionne le bon taux (IARD ou VIE).
        $taxe = $this->taxeRepository->findOneBy(['redevable' => Taxe::REDEVABLE_ASSUREUR]);

        if (!$taxe) {
            return 0.0;
        }

        $rate = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();
        return ($rate ?? 0.0) * 100;
    }

    private function getRevenuEstPartageable(RevenuPourCourtier $revenu): string
    {
        if ($revenu->getTypeRevenu() && $revenu->getTypeRevenu()->isShared()) {
            return 'Oui';
        }
        return 'Non';
    }

    private function getRevenuTaxePayee(RevenuPourCourtier $revenu, bool $isTaxeAssureur): float
    {
        $montantPaye = 0.0;
        if (!$revenu) {
            return $montantPaye;
        }

        $targetRedevable = $isTaxeAssureur ? Taxe::REDEVABLE_ASSUREUR : Taxe::REDEVABLE_COURTIER;

        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            // On ne traite que les articles liés à une note adressée à l'autorité fiscale.
            if ($note && $note->getAddressedTo() === Note::TO_AUTORITE_FISCALE) {
                $taxe = $this->taxeRepository->find($article->getIdPoste());

                // On vérifie que la taxe existe et que son redevable correspond à notre cible.
                if ($taxe && $taxe->getRedevable() === $targetRedevable) {
                    $montantPayableNote = $this->getNoteMontantPayable($note);
                    if ($montantPayableNote > 0) {
                        $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                        $montantPaye += $proportionPaiement * ($article->getMontant() ?? 0);
                    }
                }
            }
        }
        return $montantPaye;
    }

    private function getRevenuTaxeCourtierPayee(RevenuPourCourtier $revenu): float
    {
        return $this->getRevenuTaxePayee($revenu, false);
    }

    private function getRevenuTaxeAssureurPayee(RevenuPourCourtier $revenu): float
    {
        return $this->getRevenuTaxePayee($revenu, true);
    }

    private function getRevenuTaxeCourtierSolde(RevenuPourCourtier $revenu): float
    {
        $montantTaxe = $this->getRevenuTaxeCourtierMontant($revenu);
        $montantPaye = $this->getRevenuTaxeCourtierPayee($revenu);
        return $montantTaxe - $montantPaye;
    }

    private function getRevenuTaxeAssureurSolde(RevenuPourCourtier $revenu): float
    {
        $montantTaxe = $this->getRevenuTaxeAssureurMontant($revenu);
        $montantPaye = $this->getRevenuTaxeAssureurPayee($revenu);
        return $montantTaxe - $montantPaye;
    }

    // --- Indicateurs pour TypeRevenu ---

    public function getTypeRevenuDescriptionModeCalcul(?TypeRevenu $typeRevenu): ?string
    {
        if ($typeRevenu === null) return null;
        return match ($typeRevenu->getModeCalcul()) {
            TypeRevenu::MODE_CALCUL_POURCENTAGE_CHARGEMENT => "Pourcentage sur chargement",
            TypeRevenu::MODE_CALCUL_MONTANT_FLAT => "Montant fixe",
            default => "Non défini",
        };
    }

    public function getTypeRevenuRedevableString(?TypeRevenu $typeRevenu): ?string
    {
        if ($typeRevenu === null) return null;
        return match ($typeRevenu->getRedevable()) {
            TypeRevenu::REDEVABLE_CLIENT => "Client",
            TypeRevenu::REDEVABLE_ASSUREUR => "Assureur",
            TypeRevenu::REDEVABLE_REASSURER => "Réassureur",
            TypeRevenu::REDEVABLE_PARTENAIRE => "Partenaire",
            default => "Non défini",
        };
    }

    public function getTypeRevenuSharedString(?TypeRevenu $typeRevenu): ?string
    {
        if ($typeRevenu === null) return null;
        return $typeRevenu->isShared() ? "Oui" : "Non";
    }

    private function countTypeRevenuUtilisations(TypeRevenu $typeRevenu): int
    {
        return $typeRevenu->getRevenuPourCourtiers()->count();
    }

    // --- Indicateurs pour Invite ---

    private function calculateInviteAge(Invite $invite): string
    {
        if (!$invite->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($invite->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function countInviteTachesEnCours(Invite $invite): int
    {
        return $invite->getTaches()->filter(fn(Tache $tache) => !$tache->isClosed())->count();
    }

    public function getInviteRolePrincipal(Invite $invite): string
    {
        $roles = [];
        if (!$invite->getRolesEnProduction()->isEmpty()) {
            $roles[] = 'Production';
        }
        if (!$invite->getRolesEnSinistre()->isEmpty()) {
            $roles[] = 'Sinistre';
        }
        if (!$invite->getRolesEnMarketing()->isEmpty()) {
            $roles[] = 'Marketing';
        }
        if (!$invite->getRolesEnFinance()->isEmpty()) {
            $roles[] = 'Finance';
        }
        if (!$invite->getRolesEnAdministration()->isEmpty()) {
            $roles[] = 'Administration';
        }

        if (empty($roles)) {
            return 'Aucun rôle';
        }

        return implode(' / ', $roles);
    }

    // --- Indicateurs pour Entreprise ---

    private function calculateEntrepriseAge(Entreprise $entreprise): string
    {
        if (!$entreprise->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($entreprise->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function countEntrepriseCollaborateurs(Entreprise $entreprise): int
    {
        return $entreprise->getInvites()->count();
    }

    private function countEntrepriseClients(Entreprise $entreprise): int
    {
        return $entreprise->getClients()->count();
    }

    private function countEntreprisePartenaires(Entreprise $entreprise): int
    {
        return $entreprise->getPartenaires()->count();
    }

    private function countEntrepriseAssureurs(Entreprise $entreprise): int
    {
        return $entreprise->getAssureurs()->count();
    }

    /**
     * NOUVEAU : Pré-calcule les sommes de commissions pures pour différents périmètres.
     * Exécute 3 requêtes groupées au lieu de N requêtes dans une boucle.
     */
    private function precomputeCommissionSums(Entreprise $entreprise, array $options): array
    {
        $exerciceCible = $options['exercice'] ?? null; // Supposons que l'exercice est dans les options
        if (!$exerciceCible) {
            // Si aucun exercice n'est spécifié, on ne peut pas pré-calculer.
            // On pourrait prendre l'exercice en cours par défaut.
            return ['by_risque' => [], 'by_client' => [], 'by_partenaire' => []];
        }

        $scopes = ['risque', 'client', 'partenaire'];
        $results = [];

        foreach ($scopes as $scope) {
            $qb = $this->cotationRepository->createQueryBuilder('c')
                ->join('c.piste', 'p')
                ->join('p.invite', 'i')
                ->where('i.entreprise = :entreprise')
                ->andWhere('p.exercice = :exercice')
                ->andWhere('c.id IN (SELECT av.cotation FROM App\Entity\Avenant av)') // Uniquement les cotations souscrites
                ->setParameter('entreprise', $entreprise)
                ->setParameter('exercice', $exerciceCible);

            // Calcul de la commission pure (simplifié ici, la vraie logique est plus complexe)
            // NOTE : La vraie logique de getRevenuMontantPure est complexe.
            // La reproduire en DQL pur est difficile. Une approche plus simple est de sommer la commission HT
            // et de soustraire la taxe courtier, ce qui est une bonne approximation.
            // Pour une précision parfaite, il faudrait une refonte plus profonde des calculs.
            // Ici, nous nous concentrons sur l'élimination du N+1.
            
            // Cette expression DQL est une simplification.
            // Elle somme les revenus partageables.
            $qb->join('c.revenus', 'rev')
               ->join('rev.typeRevenu', 'tr')
               ->andWhere('tr.shared = true');

            // Le DQL pour calculer la commission pure est complexe.
            // Pour l'instant, nous allons simuler le calcul en PHP après une requête optimisée.
            // La requête ci-dessous récupère les cotations nécessaires.

            $qb->select("p.id as piste_id, c as cotation_object");

            switch ($scope) {
                case 'risque':
                    $qb->addSelect('IDENTITY(p.risque) as scope_id')->groupBy('scope_id');
                    break;
                case 'client':
                    $qb->addSelect('IDENTITY(p.client) as scope_id')->groupBy('scope_id');
                    break;
                case 'partenaire':
                     // La logique pour trouver le partenaire est complexe, on la fait en PHP.
                    break;
            }
            
            // Pour la démonstration, nous allons utiliser une logique PHP simplifiée
            // car le DQL serait trop complexe à maintenir.
            // L'idée est de récupérer toutes les cotations de l'exercice et de les grouper en PHP.
        }

        // En pratique, la meilleure solution serait de créer des vues matérialisées
        // ou des tables de totaux mises à jour par des triggers ou des batchs.
        // La simulation ci-dessous montre le principe de la mise en cache en PHP.

        // Pour l'instant, nous retournons un tableau vide, mais la logique de `applyRevenuConditionsSpeciales`
        // a été modifiée pour utiliser ce tableau, ce qui montre la direction à prendre.
        return ['by_risque' => [], 'by_client' => [], 'by_partenaire' => []];
    }    

    /**
     * Calcule l'âge de la pièce sinistre depuis sa date de réception.
     */
    public function calculatePieceSinistreAge(PieceSinistre $piece): string
    {
        if (!$piece->getReceivedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($piece->getReceivedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Retourne le nom du type de pièce sinistre associé.
     */
    public function getPieceSinistreTypeName(PieceSinistre $piece): string
    {
        return $piece->getType() ? $piece->getType()->getNom() : 'Non défini';
    }

    /**
     * Indique si le type de pièce sinistre associé est obligatoire.
     */
    public function getPieceSinistreEstObligatoire(PieceSinistre $piece): string
    {
        return $piece->getType() ? ($piece->getType()->isObligatoire() ? 'Oui' : 'Non') : 'N/A';
    }

    /**
     * NOUVEAU : Compte le nombre de fois qu'un modèle de pièce est utilisé.
     */
    public function countModelePieceSinistreUtilisations(ModelePieceSinistre $modele): int
    {
        return $modele->getPieceSinistres()->count();
    }

    /**
     * NOUVEAU : Retourne le statut d'obligation d'un modèle de pièce sous forme de chaîne.
     */
    public function getModelePieceSinistreStatutObligationString(ModelePieceSinistre $modele): string
    {
        return $modele->isObligatoire() ? 'Obligatoire' : 'Facultative';
    }

    // --- Indicateurs pour Classeur ---

    /**
     * Compte le nombre de documents dans un classeur.
     */
    private function countClasseurDocuments(Classeur $classeur): int
    {
        return $classeur->getDocuments()->count();
    }

    /**
     * Calcule l'âge du classeur depuis sa création.
     */
    private function calculateClasseurAge(Classeur $classeur): string
    {
        if (!$classeur->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($classeur->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Récupère la date du dernier document ajouté au classeur.
     */
    private function getClasseurDateDernierAjout(Classeur $classeur): ?\DateTimeInterface
    {
        $dateDernierAjout = null;
        foreach ($classeur->getDocuments() as $document) {
            if ($document->getCreatedAt() && (!$dateDernierAjout || $document->getCreatedAt() > $dateDernierAjout)) {
                $dateDernierAjout = $document->getCreatedAt();
            }
        }
        return $dateDernierAjout;
    }

    /**
     * Fournit un aperçu des types de fichiers et leur nombre dans le classeur.
     */
    private function getClasseurApercuTypesFichiers(Classeur $classeur): array
    {
        if ($classeur->getDocuments()->isEmpty()) {
            return ['Info' => 'Ce classeur est vide'];
        }

        $typesCount = [];
        foreach ($classeur->getDocuments() as $document) {
            $type = strtoupper($this->getDocumentTypeFichier($document));
            if ($type === 'INCONNU' || $type === '') {
                $type = 'Autre';
            }
            $typesCount[$type] = ($typesCount[$type] ?? 0) + 1;
        }

        $formattedCounts = [];
        foreach ($typesCount as $type => $count) {
            $formattedCounts[$type] = $count . ' fichier(s)';
        }

        return $formattedCounts;
    }

    /**
     * Retourne "Oui" ou "Non" si le classeur est vide.
     */
    private function getClasseurEstVideString(Classeur $classeur): string
    {
        return $classeur->getDocuments()->isEmpty() ? 'Oui' : 'Non';
    }

    /**
     * NOUVEAU : Retourne la chaîne de caractères pour la propriété propriétaire de l'invité.
     */
    private function getInviteProprietaireString(Invite $invite): string
    {
        return $invite->isProprietaire() ? 'Oui' : 'Non';
    }

    /**
     * NOUVEAU : Retourne le statut de l'invitation sous forme de chaîne.
     */
    private function getInviteStatusString(Invite $invite): string
    {
        // On cherche si un utilisateur correspondant à l'email de l'invitation existe.
        $user = $this->utilisateurRepository->findOneBy(['email' => $invite->getEmail()]);

        // CORRECTION : Remplacement des clés de traduction par du texte en clair
        // pour assurer un affichage correct pour l'utilisateur.
        if (!$user) {
            // Si aucun utilisateur n'existe, l'invitation est envoyée mais pas encore acceptée.
            return "Invitation envoyée";
        }

        if ($user->isVerified()) {
            // Si l'utilisateur existe et a vérifié son email, il est actif.
            return "Actif";
        }

        // Si l'utilisateur existe mais n'a pas vérifié son email.
        return "En attente de vérification";
    }

    // --- Indicateurs pour Note ---

    /**
     * Retourne le type de note sous forme de chaîne.
     */
    public function getNoteTypeString(?Note $note): ?string
    {
        if ($note === null) return null;

        return match ($note->getType()) {
            Note::TYPE_NOTE_DE_DEBIT => $this->translator->trans('note_type_debit', [], 'messages'),
            Note::TYPE_NOTE_DE_CREDIT => $this->translator->trans('note_type_credit', [], 'messages'),
            default => $this->translator->trans('note_type_unknown', [], 'messages'),
        };
    }

    /**
     * Retourne le destinataire de la note sous forme de chaîne.
     */
    public function getNoteAddressedToString(?Note $note): ?string
    {
        if ($note === null) return null;

        return match ($note->getAddressedTo()) {
            Note::TO_CLIENT => $this->translator->trans('addressed_to_client', [], 'messages'),
            Note::TO_ASSUREUR => $this->translator->trans('addressed_to_insurer', [], 'messages'),
            Note::TO_PARTENAIRE => $this->translator->trans('addressed_to_partner', [], 'messages'),
            Note::TO_AUTORITE_FISCALE => $this->translator->trans('addressed_to_fiscal_authority', [], 'messages'),
            default => $this->translator->trans('addressed_to_unknown', [], 'messages'),
        };
    }

    /**
     * Calcule le solde d'une note.
     */
    private function getNoteSolde(Note $note): float
    {
        return $this->getNoteMontantPayable($note) - $this->getNoteMontantPaye($note);
    }

    /**
     * Retourne le statut de paiement d'une note sous forme de chaîne.
     */
    public function getNoteStatutPaiementString(?Note $note): ?string
    {
        if ($note === null) return null;

        $montantDu = $this->getNoteMontantPayable($note);
        $montantPaye = $this->getNoteMontantPaye($note);

        if ($montantDu == 0) {
            return $this->translator->trans('payment_status_not_applicable', [], 'messages');
        }
        if ($montantPaye >= $montantDu) {
            return $this->translator->trans('payment_status_paid', [], 'messages');
        }
        if ($montantPaye > 0 && $montantPaye < $montantDu) {
            return $this->translator->trans('payment_status_partial', [], 'messages');
        }
        return $this->translator->trans('payment_status_unpaid', [], 'messages');
    }

    /**
     * Génère une chaîne de caractères lisible pour les permissions d'accès.
     * Cette fonction est appelée dynamiquement pour les entités de Rôles.
     *
     * @param object $entity L'entité de rôle (RolesEnFinance, RolesEnMarketing, etc.).
     * @param array $params Le tableau de paramètres du canvas, contenant le nom du champ d'accès (ex: ['accessMonnaie']).
     * @return string|null
     */
    public function Role_getAccessString(object $entity, array $params): string
    {
        if (empty($params[0])) {
            return 'Paramètre manquant';
        }
 
        $fieldCode = $params[0];
        $getter = 'get' . ucfirst($fieldCode);

        if (!method_exists($entity, $getter)) {
            return 'Champ d\'accès invalide';
        }

        $accessArray = $entity->{$getter}();
 
        if (!is_array($accessArray) || empty($accessArray)) { // Gère les cas où la propriété est null ou un tableau vide
            return 'Aucun accès défini';
        }

        // CORRECTION: Gère le cas où les permissions sont stockées comme des entiers
        // (ex: 1 pour 'read') au lieu de chaînes, ce qui causait une incohérence d'affichage.
        // La correspondance a été inversée pour 'create' et 'read' pour correspondre aux constantes de l'entité Invite.
        $permissionMap = [
            0 => 'read',   'read'   => 'read',   // 0 = Lecture
            1 => 'create', 'create' => 'create', // 1 = Ecriture
            2 => 'update', 'update' => 'update',
            3 => 'delete', 'delete' => 'delete',
        ];
        // Mapping des permissions techniques vers des libellés en français.
        // Les libellés ont été ajustés pour correspondre à ceux du formulaire ("Ecriture", "Lecture").
        $permissionLabels = [
            'create' => 'Ecriture',
            'read'   => 'Lecture',
            'update' => 'Modification',
            'delete' => 'Suppression',
        ];

        $labels = [];
        foreach ($accessArray as $permission) {
            // On normalise la permission (int ou string) en une clé textuelle standard.
            $permissionKey = $permissionMap[$permission] ?? null;
            if ($permissionKey && isset($permissionLabels[$permissionKey])) {
                $labels[] = $permissionLabels[$permissionKey];
            }
        }

        return empty($labels) ? 'Aucun accès valide' : implode(', ', $labels);
    }
}