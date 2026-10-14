<?php

namespace App\Constantes;

use App\Entity\Note;
use App\Entity\Taxe;
use App\Entity\Piste;
use App\Entity\Client;
use App\Entity\Invite;
use App\Entity\Risque;
use DateTimeImmutable;
use App\Entity\Avenant;
use App\Entity\Contact;
use App\Entity\Tranche;
use App\Entity\Assureur;
use App\Entity\Classeur;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Feedback;
use App\Entity\Paiement;
use App\Entity\Bordereau;
use App\Entity\Chargement;
use App\Entity\Entreprise;
use App\Entity\Partenaire;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use App\Entity\ConditionPartage;
use App\Services\ServiceMonnaies;
use App\Entity\RevenuPourCourtier;
use App\Repository\NoteRepository;
use App\Repository\TaxeRepository;
use App\Repository\ModelePieceSinistreRepository;
use App\Repository\InviteRepository;
use App\Repository\ArticleRepository;
use Proxies\__CG__\App\Entity\Revenu;
use App\Repository\CotationRepository;
use App\Repository\PartenaireRepository;
use App\Repository\UtilisateurRepository;
use Symfony\Bundle\SecurityBundle\Security;
use App\Repository\AutoriteFiscaleRepository;
use App\Repository\RevenuPourCourtierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use App\Services\AvenantRenouvellementResolver;
use Symfony\Contracts\Translation\TranslatorInterface;


class Constante
{
    public function __construct(
        private TranslatorInterface $translator,
        private ServiceTaxes $serviceTaxes,
        private Security $security,
        private CotationRepository $cotationRepository,
        private AutoriteFiscaleRepository $autoriteFiscaleRepository,
        private RevenuPourCourtierRepository $revenuPourCourtierRepository,
        private NoteRepository $noteRepository,
        private ArticleRepository $articleRepository,
        private TaxeRepository $taxeRepository,
        private PartenaireRepository $partenaireRepository,
        private UtilisateurRepository $utilisateurRepository,
        private ServiceMonnaies $serviceMonnaies,
        private InviteRepository $inviteRepository,
        private ServiceDates $serviceDates,
        private ModelePieceSinistreRepository $modelePieceSinistreRepository,
        private AvenantRenouvellementResolver $renouvellementResolver,
    ) {}

    public const STATUS_DUE = "AmountDue";
    public const STATUS_INVOICED = "AmountInvoiced";
    public const STATUS_PAID = "AmountPaid";
    public const STATUS_NOT_INVOICED = "AmountNotInvoiced";
    public const STATUS_BALANCE_DUE = "BalanceDue";




    public function getAddressedTo(int $addressedTo)
    {
        switch ($addressedTo) {
            case 0:
                return $this->translator->trans("addressed_to_client");
                break;
            case 1:
                return $this->translator->trans("addressed_to_insurer");
                break;
            case 2:
                return $this->translator->trans("addressed_to_partner");
                break;
        }
    }

    public function Contact_getTypeString(?Contact $contact): ?string
    {
        if ($contact === null) {
            return null;
        }

        return match ($contact->getType()) {
            Contact::TYPE_CONTACT_PRODUCTION => $this->translator->trans("contact_type_production"),
            Contact::TYPE_CONTACT_SINISTRE => $this->translator->trans("contact_type_sinistre"),
            Contact::TYPE_CONTACT_ADMINISTRATION => $this->translator->trans("contact_type_administration"),
            Contact::TYPE_CONTACT_AUTRES => $this->translator->trans("contact_type_autres"),
            default => null,
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

    public function Document_getParentAsString(?Document $document): ?string
    {
        if ($document === null) return null;

        if ($document->getClasseur()) return "Classeur: " . $document->getClasseur()->getNom();
        if ($document->getPieceSinistre()) return "Pièce Sinistre: " . $document->getPieceSinistre()->getDescription();
        if ($document->getOffreIndemnisationSinistre()) return "Offre: " . $document->getOffreIndemnisationSinistre()->getNom();
        if ($document->getCotation()) return "Cotation: " . $document->getCotation()->getNom();
        if ($document->getAvenant()) return "Avenant: " . $document->getAvenant()->getReferencePolice();
        if ($document->getTache()) return "Tâche: " . $document->getTache()->getDescription();
        if ($document->getFeedback()) return "Feedback: " . $document->getFeedback()->getDescription();
        if ($document->getClient()) return "Client: " . $document->getClient()->getNom();
        if ($document->getBordereau()) return "Bordereau: " . $document->getBordereau()->getNom();
        if ($document->getCompteBancaire()) return "Cpt. Bancaire: " . $document->getCompteBancaire()->getNom();
        if ($document->getPiste()) return "Piste: " . $document->getPiste()->getNom();
        if ($document->getPartenaire()) return "Partenaire: " . $document->getPartenaire()->getNom();
        if ($document->getPaiement()) return "Paiement: " . $document->getPaiement()->getReference();

        return "Non-associé";
    }


    public function getTypeAvenant(int $code): string
    {
        switch ($code) {
            case 0:
                return $this->translator->trans("avenant_souscription");
                break;
            case 1:
                return $this->translator->trans("avenant_incorporation");
                break;
            case 2:
                return $this->translator->trans("avenant_prorogation");
                break;
            case 3:
                return $this->translator->trans("avenant_annulation");
                break;
            case 4:
                return $this->translator->trans("avenant_resiliation");
                break;
            case 5:
                return $this->translator->trans("avenant_renouvellement");
                break;

            default:
                return $this->translator->trans("avenant_inconnu");
                break;
        }
    }

    
    public const TAB_LANGUES = [
        self::LANGUE_ANGLAIS => "en",
        self::LANGUE_FRANCAIS => "fr"
    ];
    public const LANGUE_FRANCAIS = "Français";
    public const LANGUE_ANGLAIS = "English";


    public const TAB_MONNAIE_MONNAIE_LOCALE = [
        'Non' => 0,
        'Oui' => 1
    ];

    public const FONCTION_SAISIE_ET_AFFICHAGE = "currency_function_display_and_intput_currency";
    public const FONCTION_SAISIE_UNIQUEMENT = "Saisie Uniquement";
    public const FONCTION_AFFICHAGE_UNIQUEMENT = "Affichage Uniquement";
    public const FONCTION_AUCUNE = "Aucune";

    public const TAB_MONNAIE_FONCTIONS = [
        self::FONCTION_AUCUNE => -1,
        self::FONCTION_SAISIE_ET_AFFICHAGE => 0,
        self::FONCTION_SAISIE_UNIQUEMENT => 1,
        self::FONCTION_AFFICHAGE_UNIQUEMENT => 2,
    ];

    public const TAB_MONNAIES = [
        "XUA - ADB Unit of Account" => "XUA",
        "DZD - Algerian dinar" => "DZD",
        "AOA - Angolan kwanza" => "AOA",
        "AMD - Armenian dram" => "AMD",
        "AWG - Aruban florin" => "AWG",
        "AUD - Australian dollar" => "AUD",
        "AZN - Azerbaijani manat" => "AZN",
        "BSD - Bahamian dollar" => "BSD",
        "BHD - Bahraini dinar" => "BHD",
        "BDT - Bangladeshi taka" => "BDT",
        "BZD - Belize dollar" => "BZD",
        "BWP - Botswana pula" => "BWP",
        "BRL - Brazilian real" => "BRL",
        "BND - Brunei dollar" => "BND",
        "BGN - Bulgarian lev" => "BGN",
        "BIF - Burundian franc" => "BIF",
        "KHR - Cambodian riel" => "KHR",
        "CAD - Canadian dollar" => "CAD",
        "XOF - CFA franc BCEAO" => "XOF",
        "XAF - CFA franc BEAC" => "XAF",
        "XPF - CFP franc (franc Pacifique)" => "XPF",
        "CDF - Congolese franc" => "CDF",
        "DJF - Djiboutian franc" => "DJF",
        "DOP - Dominican peso" => "DOP",
        "EGP - Egyptian pound" => "EGP",
        "ERN - Eritrean nakfa" => "ERN",
        "ETB - Ethiopian birr" => "ETB",
        "EUR - Euro" => "EUR",
        "GEL - Georgian lari" => "GEL",
        "GHS - Ghanaian cedi" => "GHS",
        "GNF - Guinean franc" => "GNF",
        "GYD - Guyanese dollar" => "GYD",
        "HTG - Haitian gourde" => "HTG",
        "HKD - Hong Kong dollar" => "HKD",
        "HUF - Hungarian forint" => "HUF",
        "ISK - Icelandic króna (plural: krónur)" => "ISK",
        "INR - Indian rupee" => "INR",
        "IDR - Indonesian rupiah" => "IDR",
        "IRR - Iranian rial" => "IRR",
        "IQD - Iraqi dinar" => "IQD",
        "ILS - Israeli new shekel" => "ILS",
        "JMD - Jamaican dollar" => "JMD",
        "JPY - Japanese yen" => "JPY",
        "CHF - Swiss franc" => "CHF",
        "UGX - Ugandan shilling" => "UGX",
        "USD - United States dollar" => "USD",
        "ZMW - Zambian kwacha" => "ZMW",
        "ZWL - Zimbabwean dollar (fifth)[e]" => "ZWL"
    ];


   







    public function Avenant_getRenewalStatus(Avenant $avenantEncours)
    {
        $renewalStatus = [
            'text' => "Default status",
            'code' => -1,
            'remaining days' => 0,
            'effect date' => null,
            'expiry date' => null,
        ];
        $renewalStatus['effect date'] = $avenantEncours->getStartingAt();
        $renewalStatus['expiry date'] = $avenantEncours->getEndingAt();
        $renewalStatus['remaining days'] = $this->serviceDates->daysEntre(new DateTimeImmutable("now"), $avenantEncours->getEndingAt());

        if ($renewalStatus['remaining days'] >= 0) {
            //En cours de couverture
            $renewalStatus['text'] = "Expire dans " . $renewalStatus['remaining days'] . " jrs";
            $renewalStatus['code'] = Avenant::RENEWAL_STATUS_RUNNING;
        } else {
            $renewalStatus['text'] = "Expiré il y a " . (-1 * $renewalStatus['remaining days']) . " jrs";
            // Ce qu'est DEVENUE la police vient d'une source unique, partagée avec les
            // indicateurs calculés, les fiches de l'assistante et ses résultats de
            // recherche : le badge de la liste et la réponse de Ket ne peuvent plus
            // diverger. Auparavant ce bloc balayait TOUTES les pistes de l'entreprise
            // pour retrouver le lien, et son match sans bras par défaut levait
            // \UnhandledMatchError sur une piste dérivée typée « Souscription ».
            $renewalStatus['code'] = $this->renouvellementResolver->code($avenantEncours);
        }
        $statusDescription = match ($renewalStatus['code']) {
            Avenant::RENEWAL_STATUS_CANCELLED => "Résilié",
            Avenant::RENEWAL_STATUS_EXTENDED => "Prorogé",
            Avenant::RENEWAL_STATUS_LOST => "Perdu",
            Avenant::RENEWAL_STATUS_ONCE_OFF => "Temporaire",
            Avenant::RENEWAL_STATUS_RENEWED => "Renouvellé",
            Avenant::RENEWAL_STATUS_RENEWING => "En cours de renouvellement",
            Avenant::RENEWAL_STATUS_RUNNING => "En cours...",
        };
        $renewalStatus['text'] = $statusDescription . ": " . $renewalStatus['text'];
        // dd($renewalStatus);
        return $renewalStatus;
    }
    
    



    public function getEnterprise(): ?Entreprise
    {
        /** @var \App\Entity\Utilisateur $user */
        $user = $this->security->getUser();
        return $user?->getConnectedTo();
    }

    public function Entreprise_getAvenants()
    {
        $avenants = new ArrayCollection();
        /** @var Invite $invite */
        foreach ($this->getEnterprise()->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                if ($this->Piste_isBound($piste)) {
                    foreach ($piste->getCotations() as $cotation) {
                        if ($this->Cotation_isBound($cotation)) {
                            foreach ($cotation->getAvenants() as $avenant) {
                                if (!$avenants->contains($avenant)) {
                                    $avenants->add($avenant);
                                }
                            }
                        }
                    }
                }
            }
        }
        return $avenants;
    }


    public function getPrefixeEtSuffixe($iterateur, $tailleTableau)
    {
        $prefixe = "";
        $suffixe = "";
        if ($iterateur == ($tailleTableau - 1)) {
            $prefixe = " et ";
            $suffixe = ".";
        } else {
            $prefixe = "";
            if ($iterateur == ($tailleTableau - 2)) {
                $suffixe = "";
            } else {
                $suffixe = ", ";
            }
        }
        return [
            "prefixe" => $prefixe,
            "suffixe" => $suffixe,
        ];
    }

    public function Entreprise_getDataProductionPerMonth()
    {
        $data = [
            "Mois" => ['Janvier', 'Février', 'Mars', 'Avril', 'Mais', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Decembre'],
            "Montants" => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            'MinAndMax' => [
                'suggestedMin' => 0,
                'suggestedMax' => 0,
            ],
            "Titre" => "Revenu par mois",
            "Notes" => "",
            "Total" => 0,
        ];
        for ($i = 0; $i < count($data['Mois']); $i++) {
            /** @var Avenant $avenant */
            foreach ($this->Entreprise_getAvenants() as $avenant) {
                $mois = $this->serviceDates->getMois($avenant->getStartingAt()) - 1;
                if ($mois == $i) {
                    $revenu = $this->Cotation_getMontant_commission_ttc($avenant->getCotation(), -1, false);
                    $data['Montants'][$i] += $revenu;
                    if ($data['MinAndMax']['suggestedMax'] < $revenu) {
                        $data['MinAndMax']['suggestedMax'] = $revenu;
                    }
                    $data['Total'] += $revenu;
                }
            }
        }
        $data = $this->Graphs_writeNotes($data['Montants'], $data['Mois'], "par mois", $data);
        // dd($data);
        return $data;
    }

    public function Graphs_writeNotes($tableauMontants, $tableauUnite, $strParUnite, $data)
    {
        $codeMonnaie = $this->serviceMonnaies->getMonnaieAffichage()->getCode();
        try {
            $data['Titre'] .= " (en moyenne " . $codeMonnaie . " " . number_format(($data['Total'] / count($tableauMontants)), 2, ",", ".") . ").";
        } catch (\Throwable $th) {
            //throw $th;
        }
        //Ecritures des notes
        $data['Notes'] = "Le revenu total de " . $codeMonnaie . " " . number_format($data['Total'], 2, ",", ".") . " se reparti " . $strParUnite . " comme suit : ";
        for ($i = 0; $i < count($tableauMontants); $i++) {
            $separateur = $this->getPrefixeEtSuffixe($i, count($tableauMontants));
            $pourcentage = 0;
            if ($data['Total'] != 0) {
                $pourcentage = round(($tableauMontants[$i] / $data['Total']) * 100, 2);
            }
            $data['Notes'] .= $separateur['prefixe'] . $tableauUnite[$i] . " " . $codeMonnaie . " " . number_format($tableauMontants[$i], 2, ",", ".") . " (soit " . $pourcentage . "%)" . $separateur['suffixe'];
        }
        // dd($tableauMontants);
        // dd($data);
        return $data;
    }

    public function Entreprise_getDataProductionPerInsurer()
    {
        $data = [
            "Assureurs" => [],
            "Montants" => [],
            "Titre" => "Revenu par Assureur",
            "Notes" => "",
            "Total" => 0,
        ];
        foreach ($this->Entreprise_getAssureurs() as $assureur) {
            $data['Assureurs'][] = $assureur->getNom();
        }
        for ($i = 0; $i < count($data['Assureurs']); $i++) {
            $revenuCumul = 0;
            /** @var Avenant $avenant */
            foreach ($this->Entreprise_getAvenants() as $avenant) {
                $revenu = 0;
                if ($avenant->getCotation() != null) {
                    if ($avenant->getCotation()->getAssureur()->getNom() == $data['Assureurs'][$i]) {
                        $revenu += $this->Cotation_getMontant_commission_ttc($avenant->getCotation(), -1, false);
                    }
                }
                $revenuCumul += $revenu;
                $data['Total'] += $revenu;
            }
            $data['Montants'][] = $revenuCumul;
        }
        // dd("Je suis ici.", $data['Montants'], $data['Assureurs']);
        $data = $this->Graphs_writeNotes($data['Montants'], $data['Assureurs'], "par assureur", $data);
        // dd($data);
        return $data;
    }

    public function Entreprise_getDataProductionPerPartner()
    {
        $data = [
            "Partners" => [],
            "Montants" => [],
            "Titre" => "Revenu par Intemédiaire",
            "Total" => 0,
        ];
        /** @var Partenaire $partenaire */
        foreach ($this->Entreprise_getPartenaires() as $partenaire) {
            $data['Partners'][] = $partenaire->getNom();
        }

        for ($i = 0; $i < count($data['Partners']); $i++) {
            $revenuCumul = 0;
            /** @var Avenant $avenant */
            foreach ($this->Entreprise_getAvenants() as $avenant) {
                $revenu = 0;
                $cotation = $avenant->getCotation();
                if ($cotation != null) {
                    $partenaireCotation = $this->Cotation_getPartenaire($cotation);
                    if ($partenaireCotation) {
                        if ($partenaireCotation->getNom() == $data['Partners'][$i]) {
                            $revenu += $this->Cotation_getMontant_commission_ttc($cotation, -1, false);
                        }
                    }
                }
                $revenuCumul += $revenu;
                $data['Total'] += $revenu;
            }
            $data['Montants'][] = $revenuCumul;
        }
        $data = $this->Graphs_writeNotes($data['Montants'], $data['Partners'], "par intermédiaire", $data);
        // dd($data);
        return $data;
    }


    public function Entreprise_getDataProductionPerRenewalStatus()
    {
        $data = [
            "Renewal Status" => [
                Avenant::RENEWAL_STATUS_CANCELLED,
                Avenant::RENEWAL_STATUS_EXTENDED,
                Avenant::RENEWAL_STATUS_LOST,
                Avenant::RENEWAL_STATUS_ONCE_OFF,
                Avenant::RENEWAL_STATUS_RENEWED,
                Avenant::RENEWAL_STATUS_RENEWING,
                Avenant::RENEWAL_STATUS_RUNNING,
            ],
            "Renewal Status Definitions" => [
                "ANNULE",
                "PROROGE",
                "PERDU",
                "TEMPORAIRE",
                "RENOUVELLE",
                "RENOUV. EN COURS",
                "VALIDE",
            ],
            "Montants" => [],
            "Titre" => "Revenu par status de renouv.",
            "Total" => 0,
        ];

        for ($i = 0; $i < count($data['Renewal Status']); $i++) {
            $revenu = 0;
            /** @var Avenant $avenant */
            foreach ($this->Entreprise_getAvenants() as $avenant) {
                $status = $this->Avenant_getRenewalStatus($avenant);
                if ($status['code'] == $data['Renewal Status'][$i]) {
                    $revenu += $this->Cotation_getMontant_commission_ttc($avenant->getCotation(), -1, false);
                }
            }
            $data['Montants'][] = $revenu;
            $data['Total'] += $revenu;
        }
        $data = $this->Graphs_writeNotes($data['Montants'], $data['Renewal Status Definitions'], "par status de renouvellement", $data);
        // dd($data);
        return $data;
    }

    public function Entreprise_getDataProductionPerRisk()
    {
        $data = [
            "Risks" => [],
            "Montants" => [],
            "Titre" => "Revenu par Risque",
            "Total" => 0,
        ];
        /** @var Avenant $avenant */
        foreach ($this->Entreprise_getRisques() as $risque) {
            $data['Risks'][] = $risque->getCode();
        }

        for ($i = 0; $i < count($data['Risks']); $i++) {
            $revenuCumul = 0;
            /** @var Avenant $avenant */
            foreach ($this->Entreprise_getAvenants() as $avenant) {
                $revenu = 0;
                $cotation = $avenant->getCotation();
                if ($cotation != null) {
                    $risqueCotation = $cotation->getPiste()->getRisque();
                    if ($risqueCotation->getCode() == $data['Risks'][$i]) {
                        $revenu += $this->Cotation_getMontant_commission_ttc($cotation, -1, false);
                    }
                }
                $revenuCumul += $revenu;
                $data['Total'] += $revenu;
            }
            $data['Montants'][] = $revenuCumul;
        }
        $data = $this->Graphs_writeNotes($data['Montants'], $data['Risks'], "par risque", $data);
        // dd($data);
        return $data;
    }


    /**
     * Les entités liées (assureurs, clients, risques…) ne sont plus des collections
     * directes de l'Entreprise : on les dérive du graphe Invites → Pistes → Cotations,
     * exactement comme le fait DashboardDataProvider.
     */
    public function Entreprise_getAssureurs()
    {
        $assureurs = new ArrayCollection();
        /** @var Invite $invite */
        foreach ($this->getEnterprise()->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                foreach ($piste->getCotations() as $cotation) {
                    $assureur = $cotation->getAssureur();
                    if ($assureur != null && !$assureurs->contains($assureur)) {
                        $assureurs->add($assureur);
                    }
                }
            }
        }
        return $assureurs;
    }

    public function Entreprise_getPartenaires()
    {
        $partenaires = new ArrayCollection();
        /** @var Invite $invite */
        foreach ($this->getEnterprise()->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                foreach ($piste->getCotations() as $cotation) {
                    $partenaire = $this->Cotation_getPartenaire($cotation);
                    if ($partenaire != null && !$partenaires->contains($partenaire)) {
                        $partenaires->add($partenaire);
                    }
                }
            }
        }
        return $partenaires;
    }

    public function Entreprise_getRisques()
    {
        $risques = new ArrayCollection();
        /** @var Invite $invite */
        foreach ($this->getEnterprise()->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                $risque = $piste->getRisque();
                if ($risque != null && !$risques->contains($risque)) {
                    $risques->add($risque);
                }
            }
        }
        return $risques;
    }







    public function isIARD(?Cotation $cotation): bool
    {
        if ($cotation) {
            if ($cotation->getPiste()) {
                if ($cotation->getPiste()->getRisque()) {
                    return $cotation->getPiste()->getRisque()->getBranche() == Risque::BRANCHE_IARD_OU_NON_VIE;
                }
            }
        }
        return false;
    }

    public function Cotation_getRisque(?Cotation $cotation)
    {
        if ($cotation) {
            if ($cotation->getPiste()) {
                return $cotation->getPiste()->getRisque();
            }
        }
        return null;
    }

    public function Cotation_getTrancheDiagnostic(?Cotation $cotation)
    {
        $totPourcTranches = 0;
        /**@var Tranche $tranche */
        foreach ($cotation->getTranches() as $tranche) {
            $totPourcTranches += $tranche->getFraction();
        }
        $txt = "";
        if ($totPourcTranches != 1) {
            $txt = '<span class="badge text-bg-danger m-2">La somme des tranches n\'est pas égale à 100% (il reste une tranche équivalant à ' . round(((1 - $totPourcTranches) * 100), 2) . '%)</span><br/>';
        }
        return $txt;
    }

    public function Cotation_getPartenaire(?Cotation $cotation)
    {
        if ($cotation != null) {
            if ($cotation->getPiste() != null) {
                if ($cotation->getPiste()->getPartenaire() !== null) {
                    // dd($cotation->getPiste()->getPartenaires()[0]);
                    return $cotation->getPiste()->getPartenaire();
                } else if (count($cotation->getPiste()->getClient()->getPartenaires()) != 0) {
                    return $cotation->getPiste()->getClient()->getPartenaires()[0];
                }
            }
        }
        return null;
    }

    public function Note_getMontant_solde(?Note $note): float
    {
        $solde = $this->Note_getMontant_payable($note) - $this->Note_getMontant_paye($note);
        // dd(round($solde, 4));
        return round($solde, 4);
    }

    public function Note_getMontant_paye(?Note $note): float
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

    public function Note_getNameOfAddressedTo(?Note $note): string
    {
        if ($note) {
            switch ($note->getAddressedTo()) {
                case Note::TO_ASSUREUR:
                    return $note->getAssureur() != null ? $note->getAssureur()->getNom() : "";
                    break;
                case Note::TO_CLIENT:
                    return $note->getClient() != null ? $note->getClient()->getNom() : "";
                    break;
                case Note::TO_PARTENAIRE:
                    return $note->getPartenaire() != null ? $note->getPartenaire()->getNom() : "";
                    break;
                case Note::TO_AUTORITE_FISCALE:
                    return $note->getAutoritefiscale() != null ? $note->getAutoritefiscale()->getAbreviation() : "";
                    break;
                default:
                    # code...
                    break;
            }
        }
        return "";
    }

    public function Note_getNameOfTypeNote(?Note $note)
    {
        switch ($note->getType()) {
            case Note::TYPE_NOTE_DE_DEBIT:
                return $this->translator->trans("note_de_debit");
                break;
            case Note::TYPE_NOTE_DE_CREDIT:
                return $this->translator->trans("note_de_credit");
                break;
        }
        return null;
    }

    public function Note_getMontant_payable(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                $montant += $article->getMontant();
            }
        }
        return $montant;
    }

    public function Revenu_getMontant_pure(?RevenuPourCourtier $revenu, $addressedTo, bool $onlySharable): float
    {
        if ($addressedTo != -1) {
            if ($revenu->getTypeRevenu()->getRedevable() == $addressedTo) {
                return $this->calculateComPure($revenu, $onlySharable);
            }
            return 0;
        } else {
            return $this->calculateComPure($revenu, $onlySharable);
        }
    }

    private function calculateComPure(RevenuPourCourtier $revenu, bool $onlySharable)
    {
        $taxeCourtier = 0;
        $taxeAssureur = false;
        $comNette = 0;
        $isIARD = $this->isIARD($revenu->getCotation());
        $commissionPure = 0;


        if ($onlySharable == true) {
            if ($revenu->getTypeRevenu()->isShared() == true) {
                // dd($revenu->getTypeRevenu()->isShared(), $revenu);
                $comNette = $this->Revenu_getMontant_ht($revenu);
                $taxeCourtier = $this->serviceTaxes->getMontantTaxeSurCommission($comNette, $isIARD, $taxeAssureur, $revenu->getCotation());
                $commissionPure = $comNette - $taxeCourtier;
            }
        } else {
            $comNette = $this->Revenu_getMontant_ht($revenu);
            $taxeCourtier = $this->serviceTaxes->getMontantTaxeSurCommission($comNette, $isIARD, $taxeAssureur, $revenu->getCotation());
            $commissionPure = $comNette - $taxeCourtier;
        }
        return $commissionPure;
    }

    public function Cotation_getMontant_taxe_payable_par_courtier(?Cotation $cotation, bool $onlySharable): float
    {
        return $this->getTotalNet($cotation, $onlySharable, false);
    }

    private function getTotalNet(Cotation $cotation, bool $onlySharable, bool $isTaxAssureur)
    {
        $isIARD = $this->isIARD($cotation);
        $net_payable_par_assureur = $this->Cotation_getMontant_commission_ht($cotation, TypeRevenu::REDEVABLE_ASSUREUR, $onlySharable);
        $net_payable_par_client = $this->Cotation_getMontant_commission_ht($cotation, TypeRevenu::REDEVABLE_CLIENT, $onlySharable);
        $net_total = $net_payable_par_assureur + $net_payable_par_client;
        return $this->serviceTaxes->getMontantTaxeSurCommission($net_total, $isIARD, $isTaxAssureur, $cotation);
    }

    public function Cotation_getMontant_commission_pure(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $comHT = $this->Cotation_getMontant_commission_ht($cotation, $addressedTo, $onlySharable);
        $taxeCourtier = $this->Cotation_getMontant_taxe_payable_par_courtier($cotation, $onlySharable);
        return $comHT - $taxeCourtier;
    }

    public function Cotation_getMontant_commission_ttc(?Cotation $cotation, ?int $addressedTo, bool $onlySharable): float
    {
        switch ($addressedTo) {
            case Note::TO_ASSUREUR:
                return $this->Cotation_getMontant_commission_ttc_payable_par_assureur($cotation, $onlySharable);
                break;
            case Note::TO_CLIENT:
                return $this->Cotation_getMontant_commission_ttc_payable_par_client($cotation, $onlySharable);
                break;
            case Note::TO_PARTENAIRE:
                $comTTCAssureur = $this->Cotation_getMontant_commission_ttc_payable_par_assureur($cotation, true);
                $comTTCClient = $this->Cotation_getMontant_commission_ttc_payable_par_client($cotation, true);
                return round($comTTCAssureur + $comTTCClient, 2);
                break;
            default:
                $comTTCAssureur = $this->Cotation_getMontant_commission_ttc_payable_par_assureur($cotation, $onlySharable);
                $comTTCClient = $this->Cotation_getMontant_commission_ttc_payable_par_client($cotation, $onlySharable);
                return round($comTTCAssureur + $comTTCClient, 2);
                break;
        }
    }

    public function Cotation_getMontant_commission_ttc_payable_par_client(?Cotation $cotation, bool $onlySharable): float
    {
        $net = $this->Cotation_getMontant_commission_ht($cotation, TypeRevenu::REDEVABLE_CLIENT, $onlySharable);
        $taxe = $this->serviceTaxes->getMontantTaxeSurCommission($net, $this->isIARD($cotation), true, $cotation);
        return $net + $taxe;
    }

    public function Cotation_getMontant_commission_ttc_payable_par_assureur(?Cotation $cotation, bool $onlySharable): float
    {
        $net = $this->Cotation_getMontant_commission_ht($cotation, TypeRevenu::REDEVABLE_ASSUREUR, $onlySharable);
        $taxe = $this->serviceTaxes->getMontantTaxeSurCommission($net, $this->isIARD($cotation), true, $cotation);
        return $net + $taxe;
    }

    public function Cotation_getMontant_commission_ht(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($cotation) {
            //Pour chaque revenu configuré dans cette cotation
            foreach ($cotation->getRevenus() as $revenu) {
                if ($onlySharable == true) {
                    if ($revenu->getTypeRevenu()->isShared() == $onlySharable) {
                        $montant += $this->Revenu_getMontant_ht_addressedTo($addressedTo, $revenu);
                    }
                } else {
                    $montant += $this->Revenu_getMontant_ht_addressedTo($addressedTo, $revenu);
                }
            }
        }
        return $montant;
    }

    private function Revenu_getMontant_ht_addressedTo($addressedTo, RevenuPourCourtier $revenu)
    {
        $montant = 0;
        if ($addressedTo != -1) {
            if ($revenu->getTypeRevenu()->getRedevable() == $addressedTo) {
                $montant += $this->Revenu_getMontant_ht($revenu);
            }
        } else {
            $montant += $this->Revenu_getMontant_ht($revenu);
        }
        return $montant;
    }

    private function Cotation_getMontant_chargement_prime(Cotation $cotation, TypeRevenu $typeRevenu)
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

    public function Revenu_getMontant_ht(?RevenuPourCourtier $revenu): float
    {
        $montant = 0;
        if ($revenu != null) {
            /** @var TypeRevenu $typeRevenu */
            $typeRevenu = $revenu->getTypeRevenu();

            /** @var Cotation $cotation */
            $cotation = $revenu->getCotation();

            $montantChargementPrime = $this->Cotation_getMontant_chargement_prime($cotation, $revenu->getTypeRevenu());

            // ⚠ CE QUI EST SAISI SUR LE REVENU L'EMPORTE SUR LE TAUX DU RISQUE, TOUJOURS.
            //
            // Les deux dérogations — taux ET montant forfaitaire — passaient APRÈS
            // `isAppliquerPourcentageDuRisque()`, donc jamais : sur un type adossé au
            // risque — « Commission Ordinaire » en tête —, une dérogation était enregistrée
            // puis ignorée au calcul. L'utilisateur la voyait à la fiche et ne la
            // retrouvait dans aucun montant.
            //
            // ⚠ TROIS TÉMOINS DISAIENT DÉJÀ CETTE RÈGLE, ET LE CODE SEUL DISAIT L'INVERSE :
            //  - le formulaire — « Privilégier le taux du risque ? Oui, s'il existe ET
            //    QU'IL EST DIFFÉRENT DU POURCENTAGE DE CE REVENU »
            //    ({@see \App\Form\TypeRevenuType}) ;
            //  - le parcours de saisie de l'assistant — « À défaut de taux exceptionnel, le
            //    taux prescrit par la configuration du RISQUE s'applique, puis celui du
            //    type » ({@see \App\Ai\Parcours\ParcoursCatalogue}), que Ket récite à
            //    l'utilisateur depuis toujours ;
            //  - `RevenuCourtierPrescrit`, qui écrit un `tauxExceptionel` quand un taux est
            //    DICTÉ — et le voyait ignoré.
            //
            // Ket annonçait donc « taux exceptionnel dicté de 17,50 % » pendant que
            // l'application facturait les 10 % du risque. La parité est tenue par
            // `RevenuCourtierPrescritPariteTest`, et non plus par un commentaire.
            //
            // La reprise en dépend entièrement : chaque ligne de classeur apporte son taux.
            if ($revenu->getTauxExceptionel() != 0) {
                $montant += $montantChargementPrime * $revenu->getFraction();
            } else if ($revenu->getMontantFlatExceptionel() != 0) {
                // ⚠ ET LE FORFAIT SUIT LE TAUX, PAR SYMÉTRIE VOULUE.
                //
                // Il a un temps été laissé derrière le risque, le classeur de reprise ne
                // transportant que des taux. Mais rien ne justifiait qu'une dérogation
                // compte selon la forme qu'elle prend : « exceptionnel » veut dire la même
                // chose des deux côtés, et un forfait saisi puis ignoré est aussi
                // déroutant qu'un taux saisi puis ignoré.
                $montant += $revenu->getMontantFlatExceptionel();
            } else if ($typeRevenu->isAppliquerPourcentageDuRisque()) {
                /** @var Risque $couverture */
                $couverture = $this->Cotation_getRisque($cotation);
                if ($couverture != null) {
                    $montant += $montantChargementPrime * $couverture->getFraction();
                }
            } else {
                //Auncune formule définie sur le revenu situé dans la cotation
                //On doit appliquer la formule par défaut pour ce type de revenu
                if ($typeRevenu->getPourcentage() != 0) {
                    $montant += $montantChargementPrime * $typeRevenu->getFraction();
                } else if ($typeRevenu->getMontantflat() != 0) {
                    // ⚠ UN MONTANT FIXE S'AJOUTE, IL NE SE MULTIPLIE PAS. Cette ligne
                    // faisait `assiette × forfait` : un type « montant fixe » à 5 000 sur
                    // une prime de 10 000 facturait 50 000 000. Le cas jumeau du revenu
                    // (ci-dessus) l'ajoute tel quel, et
                    // {@see \App\Ai\Proposition\RevenuCourtierPrescrit::prescription()}
                    // annonce « montant forfaitaire de X » sans multiplier : c'est lui qui
                    // disait la bonne lecture.
                    $montant += $typeRevenu->getMontantflat();
                }
            }
        }
        return $montant;
    }

    /**
     * LE TARIF EFFECTIF D'UN REVENU, ET D'OÙ IL VIENT.
     *
     * ⚠ JUMELLE DE `Revenu_getMontant_ht()`, ET ELLE DOIT LE RESTER. Le classeur d'échange
     * doit dire à quel taux une affaire tourne réellement — sans quoi un courtier exporte
     * son portefeuille et n'y lit rien. Mais un taux affiché qui ne serait pas celui qui
     * calcule serait pire que pas de taux du tout : on lirait 10 % sur une commission
     * facturée à 17,5.
     *
     * Les deux méthodes suivent donc la MÊME cascade, dans le MÊME ordre, et toute
     * retouche de l'une appelle la retouche de l'autre. C'est le prix d'une colonne qui
     * dit la vérité ; le payer une fois vaut mieux que laisser l'export deviner.
     *
     * `origine` dit si la valeur DÉROGE (elle appartient au revenu) ou si elle est
     * HÉRITÉE — auquel cas la réimporter la figerait, et la commission cesserait de
     * suivre sa source ({@see \App\Echange\Reprise\ValeursMultiples}).
     *
     * @return array{taux: ?float, forfait: ?float, origine: string}
     *         `taux` en POINTS (12 = 12 %) ; `origine` vaut 'revenu', 'risque' ou 'type'
     */
    public function Revenu_getTarif_effectif(?RevenuPourCourtier $revenu): array
    {
        $rien = ['taux' => null, 'forfait' => null, 'origine' => 'revenu'];

        if ($revenu === null) {
            return $rien;
        }

        $typeRevenu = $revenu->getTypeRevenu();
        if ($typeRevenu === null) {
            return $rien;
        }

        if ($revenu->getTauxExceptionel() != 0) {
            return ['taux' => (float) $revenu->getTauxExceptionel(), 'forfait' => null, 'origine' => 'revenu'];
        }

        // ⚠ LES DEUX DÉROGATIONS D'ABORD, DANS L'ORDRE DE LA CASCADE. Intervertir avec le
        // risque ferait annoncer un taux que la facture ne porte pas — c'est précisément
        // ce que cette méthode existe pour empêcher.
        if ($revenu->getMontantFlatExceptionel() != 0) {
            return ['taux' => null, 'forfait' => (float) $revenu->getMontantFlatExceptionel(), 'origine' => 'revenu'];
        }

        if ($typeRevenu->isAppliquerPourcentageDuRisque()) {
            $couverture = $this->Cotation_getRisque($revenu->getCotation());
            $taux = (float) ($couverture?->getPourcentageCommissionSpecifiqueHT() ?? 0.0);

            // Un risque sans taux prescrit ne tarife rien : le dire vaut mieux que
            // d'écrire un zéro qui se lirait comme une commission nulle voulue.
            return $taux == 0.0
                ? $rien
                : ['taux' => $taux, 'forfait' => null, 'origine' => 'risque'];
        }

        if ($typeRevenu->getPourcentage() != 0) {
            return ['taux' => (float) $typeRevenu->getPourcentage(), 'forfait' => null, 'origine' => 'type'];
        }

        if ($typeRevenu->getMontantflat() != 0) {
            return ['taux' => null, 'forfait' => (float) $typeRevenu->getMontantflat(), 'origine' => 'type'];
        }

        return $rien;
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

    public function Cotation_isBound(?Cotation $cotation): bool
    {
        return $cotation->getAvenants()[0] != null || count($cotation->getAvenants()) != 0;
    }

    private function Cotation_getSommeCommissionPureRisque(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $somme = 0;
        /** @var Entreprise $entreprise */
        $entreprise = $cotation->getPiste()->getInvite()->getEntreprise();
        $cotationsDuPartenaire = $this->cotationRepository->loadCotationsWithPartnerRisque($cotation->getPiste()->getExercice(), $entreprise, $cotation->getPiste()->getRisque(), $this->Cotation_getPartenaire($cotation));
        foreach ($cotationsDuPartenaire as $proposition) {
            $somme += $this->Cotation_getMontant_commission_pure($proposition, $addressedTo, $onlySharable);
        }
        return $somme;
    }

    private function Cotation_getSommeCommissionPureClient(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        // dd($cotation->getAvenants()[0]);
        // dd("Unité de mésure: ", $cotation);

        $somme = 0;
        /** @var Entreprise $entreprise */
        $entreprise = $cotation->getPiste()->getInvite()->getEntreprise();
        $cotationsDuPartenaire = $this->cotationRepository->loadCotationsWithPartnerClient($cotation->getPiste()->getExercice(), $entreprise, $cotation->getPiste()->getClient(), $this->Cotation_getPartenaire($cotation));
        foreach ($cotationsDuPartenaire as $proposition) {
            $somme += $this->Cotation_getMontant_commission_pure($proposition, $addressedTo, $onlySharable);
        }
        return $somme;
    }

    private function Cotation_getSommeCommissionPurePartenaire(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $somme = 0;
        /** @var Entreprise $entreprise */
        $entreprise = $cotation->getPiste()->getInvite()->getEntreprise();
        $cotationsDuPartenaire = $this->cotationRepository->loadCotationsWithPartnerAll($cotation->getPiste()->getExercice(), $entreprise, $this->Cotation_getPartenaire($cotation));
        foreach ($cotationsDuPartenaire as $proposition) {
            $somme += $this->Cotation_getMontant_commission_pure($proposition, $addressedTo, $onlySharable);
        }
        return $somme;
    }

    private function appliquerConditions(?ConditionPartage $conditionPartage, ?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        //Assiette de l'affaire individuelle
        $assiette_commission_pure = $this->Cotation_getMontant_commission_pure($cotation, $addressedTo, $onlySharable);
        // dd("Je suis ici ", $assiette_commission_pure);

        //Application de l'unité de mésure
        $uniteMesure = match ($conditionPartage->getUniteMesure()) {
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE => $this->Cotation_getSommeCommissionPureRisque($cotation, $addressedTo, $onlySharable),
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT => $this->Cotation_getSommeCommissionPureClient($cotation, $addressedTo, $onlySharable),
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE => $this->Cotation_getSommeCommissionPurePartenaire($cotation, $addressedTo, $onlySharable),
        };

        // dd("Unité de mésure: " . $uniteMesure);

        $formule = $conditionPartage->getFormule();
        $seuil = $conditionPartage->getSeuil();
        $risque = $cotation->getPiste()->getRisque();
        //formule
        switch ($formule) {
            case ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL:
                // dd("ici");
                return $this->calculerRetroCommission($risque, $conditionPartage, $assiette_commission_pure);
                break;
            case ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL:
                if ($uniteMesure < $seuil) {
                    // dd("On partage car l'assiette de " . $assiette_commission_pure . " est inférieur au seuil de " . $seuil);
                    return $this->calculerRetroCommission($risque, $conditionPartage, $assiette_commission_pure);
                } else {
                    // dd("La condition n'est pas respectée ", "Assiette:" . $assiette_commission_pure, "Seuil:" . $seuil);
                    return 0;
                }
                break;
            case ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL:
                // dd("Ici ", $montant, $uniteMesure, $seuil);
                if ($uniteMesure >= $seuil) {
                    // dd("On partage car l'assiette de " . $assiette_commission_pure . " est au moins égal (soit supérieur ou égal) au seuil de " . $seuil);
                    return $this->calculerRetroCommission($risque, $conditionPartage, $assiette_commission_pure);
                } else {
                    // dd("On ne partage pas");
                    return 0;
                }
                break;

            default:
                # code...
                break;
        }
        return $montant;
    }

    private function calculerRetroCommission(?Risque $risque, ?ConditionPartage $conditionPartage, $assiette): float
    {
        $montant = 0;
        $taux = $conditionPartage->getFraction();
        $produitsCible = $conditionPartage->getProduits();

        switch ($conditionPartage->getCritereRisque()) {
            case ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES:
                $canShare = true;
                foreach ($produitsCible as $produitCible) {
                    if ($produitCible == $risque) {
                        //Ketourah / Ketura, je t'aime.
                        // dd("On ne partage pas car " . $risque . " est dans ", $produitsCible);
                        $canShare = false;
                    }
                }
                $montant = $canShare == true ? ($assiette * $taux) : 0;
                break;
            case ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES:
                foreach ($produitsCible as $produitCible) {
                    if ($produitCible == $risque) {
                        // dd("Oui, on partage car " . $risque . " est dans ", $produitsCible);
                        $montant = $assiette * $taux;
                    }
                }
                break;
            case ConditionPartage::CRITERE_PAS_RISQUES_CIBLES:
                //On applique le taux à l'assiette
                $montant = $assiette * $taux;
                break;

            default:
                # code...
                break;
        }
        return $montant;
    }

    public function Piste_isBound(?Piste $piste): bool
    {
        $rep = false;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    $tempo = $cotation->getAvenants()[0] != null || count($cotation->getAvenants()) != 0;
                    if ($tempo == true) {
                        return true;
                    }
                }
            }
        }
        return $rep;
    }

    /**
     * Taux combiné des taxes sur la commission, en FRACTION (0,16 pour 16 %) — et
     * NON en pourcentage entier. Les taux sont stockés en pourcentage entier ; la
     * conversion passe par le VO Pourcentage. Ses consommateurs l'utilisent en
     * fraction : « comHT × tauxTaxe » (montant de taxe) et « comTTC / (1 + tauxTaxe) »
     * (retrait de la taxe). Avant, cette méthode renvoyait 16 au lieu de 0,16, d'où
     * une taxe ×100 trop grande et un comTTC/(16+1) au lieu de /1,16.
     */
    public function getTauxTaxe(?Cotation $cotation, bool $forAssureur)
    {
        $isIARD = $this->isIARD($cotation);
        $tauxTaxe = 0.0;
        $taxes = $forAssureur
            ? $this->serviceTaxes->getTaxesPayableParAssureur()
            : $this->serviceTaxes->getTaxesPayableParCourtier();
        foreach ($taxes as $taxe) {
            $tauxTaxe += $taxe->tauxPourcentage($isIARD)->fraction();
        }
        return $tauxTaxe;
    }

    public function Revenu_getMontant_retrocommissions_payable_par_courtier(?RevenuPourCourtier $revenu, ?Partenaire $partenaireCible, $addressedTo): float
    {
        $retrocom = 0;
        if ($revenu != null) {
            /** @var Cotation $cotation */
            $cotation = $revenu->getCotation();
            if ($cotation != null) {
                if ($partenaireCible != null) {
                    if ($revenu->getTypeRevenu()->isShared() == true) {

                        /** @var Partenaire $partenaire */
                        $partenaire = $this->Cotation_getPartenaire($cotation);

                        if ($partenaire != null) {
                            //On s'assurer que nous parlons du même partenaire
                            if ($this->isSamePartenaire($partenaire, $partenaireCible)) {
                                //D'abord, on traite les conditions spéciale attachées à la piste
                                $conditionsPartagePiste = $cotation->getPiste()->getConditionsPartageExceptionnelles();
                                if (count($conditionsPartagePiste) != 0) {
                                    $retrocom = $this->Revenu_appliquerConditionsSpeciales($conditionsPartagePiste[0], $revenu, $addressedTo);
                                } else {
                                    $conditionsPartagePartenaire = $partenaire->getConditionPartages();
                                    if (count($conditionsPartagePartenaire) != 0) {
                                        $retrocom = $this->Revenu_appliquerConditionsSpeciales($conditionsPartagePartenaire[0], $revenu, $addressedTo);
                                    } else if ($partenaire->getPart() != 0) {
                                        $assiette = $this->Revenu_getMontant_pure($revenu, $addressedTo, true);
                                        $retrocom = $assiette * $partenaire->getFraction();
                                    }
                                }
                            }
                        }
                        // dd("Montant Retrocom: " . $retrocom);
                    }
                }
            }
        }
        return $retrocom;
    }

    private function Revenu_appliquerConditionsSpeciales(?ConditionPartage $conditionPartage, RevenuPourCourtier $revenu, $addressedTo): float
    {
        $montant = 0;
        //Assiette de l'affaire individuelle
        $assiette = $this->Revenu_getMontant_pure($revenu, $addressedTo, true);
        // dd("Je suis ici ", $assiette_commission_pure);

        //Application de l'unité de mésure
        $uniteMesure = match ($conditionPartage->getUniteMesure()) {
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE => $this->Cotation_getSommeCommissionPureRisque($revenu->getCotation(), $addressedTo, true),
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT => $this->Cotation_getSommeCommissionPureClient($revenu->getCotation(), $addressedTo, true),
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE => $this->Cotation_getSommeCommissionPurePartenaire($revenu->getCotation(), $addressedTo, true),
        };

        // dd("Unité de mésure: " . $uniteMesure);

        $formule = $conditionPartage->getFormule();
        $seuil = $conditionPartage->getSeuil();
        $risque = $revenu->getCotation()->getPiste()->getRisque();
        //formule
        switch ($formule) {
            case ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL:
                // dd("ici");
                return $this->calculerRetroCommission($risque, $conditionPartage, $assiette);
                break;
            case ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL:
                if ($uniteMesure < $seuil) {
                    // dd("On partage car l'assiette de " . $assiette_commission_pure . " est inférieur au seuil de " . $seuil);
                    return $this->calculerRetroCommission($risque, $conditionPartage, $assiette);
                } else {
                    // dd("La condition n'est pas respectée ", "Assiette:" . $assiette_commission_pure, "Seuil:" . $seuil);
                    return 0;
                }
                break;
            case ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL:
                // dd("Ici ", $montant, $uniteMesure, $seuil);
                if ($uniteMesure >= $seuil) {
                    // dd("On partage car l'assiette de " . $assiette_commission_pure . " est au moins égal (soit supérieur ou égal) au seuil de " . $seuil);
                    return $this->calculerRetroCommission($risque, $conditionPartage, $assiette);
                } else {
                    // dd("On ne partage pas");
                    return 0;
                }
                break;

            default:
                # code...
                break;
        }
        return $montant;
    }

}
