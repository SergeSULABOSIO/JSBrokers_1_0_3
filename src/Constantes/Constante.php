<?php

namespace App\Constantes;

use DateTime;
use App\Entity\Note;
use App\Entity\Taxe;
use App\Entity\Piste;
use App\Entity\Tache;
use App\Entity\Client;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\Risque;
use DateTimeImmutable;
use App\Entity\Article;
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
use App\Entity\PieceSinistre;
use App\Entity\CompteBancaire;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use App\Entity\AutoriteFiscale;
use App\Services\Canvas\CalculationProvider;
use App\Entity\ConditionPartage;
use App\Services\ServiceMonnaies;
use Doctrine\ORM\Query\Expr\Func;
use PhpParser\Node\Expr\FuncCall;
use App\Entity\RevenuPourCourtier;
use App\Repository\NoteRepository;
use App\Repository\TaxeRepository;
use App\Entity\ChargementPourPrime;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use PhpParser\Node\Expr\Cast\Array_;
use App\Repository\ArticleRepository;
use Proxies\__CG__\App\Entity\Revenu;
use App\Repository\CotationRepository;
use PhpParser\ErrorHandler\Collecting;
use App\Entity\ReportSet\ReportSummary;
use App\Entity\ReportSet\TaskReportSet;
use App\Entity\ReportSet\ClaimReportSet;
use App\Repository\PartenaireRepository;
use App\Repository\UtilisateurRepository;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\ReportSet\InsurerReportSet;
use App\Entity\ReportSet\PartnerReportSet;
use App\Entity\ReportSet\RenewalReportSet;
use App\Entity\ReportSet\CashflowReportSet;
use Doctrine\Common\Collections\Collection;
use phpDocumentor\Reflection\Types\Integer;
use Symfony\Bundle\SecurityBundle\Security;
use App\Repository\AutoriteFiscaleRepository;
use App\Entity\ReportSet\Top20ClientReportSet;
use App\Repository\RevenuPourCourtierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use App\Controller\Admin\RevenuCourtierController;
use App\Repository\NotificationSinistreRepository;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Notifier\Notification\Notification;


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
    ) {}

    public const STATUS_DUE = "AmountDue";
    public const STATUS_INVOICED = "AmountInvoiced";
    public const STATUS_PAID = "AmountPaid";
    public const STATUS_NOT_INVOICED = "AmountNotInvoiced";
    public const STATUS_BALANCE_DUE = "BalanceDue";


    public function getTabTypeAvenants(): array
    {
        return [
            $this->translator->trans("avenant_souscription") => 0,
            $this->translator->trans("avenant_incorporation") => 1,
            $this->translator->trans("avenant_prorogation") => 2,
            $this->translator->trans("avenant_annulation") => 3,
            $this->translator->trans("avenant_resiliation") => 4,
            $this->translator->trans("avenant_renouvellement") => 5,
        ];
    }

    public function getTabTypeNotes(): array
    {
        return [
            $this->translator->trans("note_de_debit") => 0,
            $this->translator->trans("note_de_credit") => 1,
        ];
    }



    public function getTabAddressedTo(): array
    {
        return [
            $this->translator->trans("addressed_to_client") => 0,
            $this->translator->trans("addressed_to_insurer") => 1,
            $this->translator->trans("addressed_to_partner") => 2,
        ];
    }

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

    public function getTabLangues(): array
    {
        return [
            $this->translator->trans("constante_language_english") => "en",
            $this->translator->trans("constante_language_french") => "fr",
        ];
    }

    public function getTabFonctionsMonnaies(): array
    {
        return [
            $this->translator->trans("currency_function_none") => -1,
            $this->translator->trans("currency_function_display_and_intput_currency") => 0,
            $this->translator->trans("currency_function_intput_currency") => 1,
            $this->translator->trans("currency_function_display_currency") => 2,
        ];
    }

    public function getTabIsMonnaieLocale(): array
    {
        return [
            $this->translator->trans("constante_no") => 0,
            $this->translator->trans("constante_yes") => 1,
        ];
    }

    public function getTabMonnaies(): array
    {
        return [
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


   







    /**
     * LE FRAIS ARCA - TAXES PAYABLES PAR LE COURTIER
     */
    public function Tranche_getPostesFacturables(?Tranche $tranche, ?PanierNotes $panier, $addressedTo, bool $onlySharable)
    {
        $tabPostesFacturables = [];
        // dd($panier->getIdNote());
        // dd("Je suis ici", $panier->getAddressedTo());
        if ($tranche) {
            if ($tranche->getCotation()) {
                if ($this->Cotation_isBound($tranche->getCotation())) {

                    switch ($panier->getAddressedTo()) {
                        case Note::TO_ASSUREUR:
                            /** @var RevenuPourCourtier $revenu */
                            foreach ($tranche->getCotation()->getRevenus() as $revenu) {
                                if ($revenu->getTypeRevenu()->getRedevable() == TypeRevenu::REDEVABLE_ASSUREUR) {
                                    //On doit s'assurer que l'assureur sur la liste est bien celui qui est dans le panier
                                    if ($tranche->getCotation()->getAssureur()->getId() == $panier->getIdAssureur()) {
                                        $montant = $this->Revenu_getMontant_ttc_tranche($revenu, $tranche);
                                        // $montant = $this->Tranche_getMontant_commission_ttc($tranche);
                                        if ($montant != 0) {
                                            /**
                                             * Analyse des possibles paiements antérieurs et éventuellement des montants payés
                                             */
                                            $montantPaye = $this->Revenu_getMontant_ttc_collecte($revenu, $tranche);
                                            $isInvoiced = false;
                                            $montantInvoiced = 0;
                                            /** @var Article $article */
                                            foreach ($tranche->getArticles() as $article) {
                                                $addressedToAssureur = $article->getNote()->getAddressedTo() == Note::TO_ASSUREUR;
                                                $samePoste = $revenu->getId() == $article->getIdPoste();
                                                $inADiffrentNote = $article->getNote()->getId() != $panier->getIdNote();
                                                if ($addressedToAssureur == true & $samePoste == true && $inADiffrentNote) {
                                                    $isInvoiced = true;
                                                    $montantInvoiced += $article->getMontant();
                                                }
                                            }
                                            $canInvoice = $montant != $montantInvoiced;
                                            if ($canInvoice == true) {
                                                $tabPostesFacturables[] = [
                                                    "poste" => $revenu->getNom(),
                                                    "addressedTo" => Note::TO_ASSUREUR,
                                                    "pourcentage" => $tranche->getPourcentage(),
                                                    "montantPayable" => $montant,
                                                    "montantPaye" => $montantPaye,
                                                    "montantFacturé" => $montantInvoiced,
                                                    "isInvoiced" => $isInvoiced,
                                                    "canInvoice" => $canInvoice,
                                                    "idCible" => $panier->getIdAssureur(),
                                                    "idPoste" => $revenu->getId(),
                                                    "idNote" => $panier->getIdNote() == null ? -1 : $panier->getIdNote(),
                                                    "idTranche" => $tranche->getId(),
                                                ];
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                        case Note::TO_CLIENT:
                            /** @var RevenuPourCourtier $revenu */
                            foreach ($tranche->getCotation()->getRevenus() as $revenu) {
                                if ($revenu->getTypeRevenu()->getRedevable() == TypeRevenu::REDEVABLE_CLIENT) {
                                    //On doit s'assurer que le client sur la liste est bien celui qui est dans le panier
                                    if ($tranche->getCotation()->getPiste()->getClient()->getId() == $panier->getIdClient()) {
                                        $montant = $this->Revenu_getMontant_ttc_tranche($revenu, $tranche);
                                        // $montant = $this->Tranche_getMontant_commission_ttc($tranche);
                                        if ($montant != 0) {
                                            /**
                                             * Analyse des possibles paiements antérieurs et éventuellement des montants payés
                                             */
                                            $montantPaye = $this->Revenu_getMontant_ttc_collecte($revenu, $tranche);
                                            $isInvoiced = false;
                                            $montantInvoiced = 0;
                                            /** @var Article $article */
                                            foreach ($tranche->getArticles() as $article) {
                                                $addressedToClient = $article->getNote()->getAddressedTo() == Note::TO_CLIENT;
                                                $samePoste = $revenu->getId() == $article->getIdPoste();
                                                $inADiffrentNote = $article->getNote()->getId() != $panier->getIdNote();
                                                if ($addressedToClient == true & $samePoste == true && $inADiffrentNote) {
                                                    $isInvoiced = true;
                                                    $montantInvoiced += $article->getMontant();
                                                }
                                            }
                                            $canInvoice = $montant != $montantInvoiced;
                                            if ($canInvoice == true) {
                                                $tabPostesFacturables[] = [
                                                    "poste" => $revenu->getNom(),
                                                    "addressedTo" => Note::TO_CLIENT,
                                                    "pourcentage" => $tranche->getPourcentage(),
                                                    "montantPayable" => $montant,
                                                    "montantPaye" => $montantPaye,
                                                    "montantFacturé" => $montantInvoiced,
                                                    "isInvoiced" => $isInvoiced,
                                                    "canInvoice" => $canInvoice,
                                                    "idCible" => $panier->getIdClient(),
                                                    "idPoste" => $revenu->getId(),
                                                    "idNote" => $panier->getIdNote() == null ? -1 : $panier->getIdNote(),
                                                    "idTranche" => $tranche->getId(),
                                                ];
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                        case Note::TO_PARTENAIRE:
                            //On doit s'assurer que la tranche sur la liste est bien celle qui est dans le panier
                            /** @var Partenaire $partenaire */
                            $partenaire = $this->Tranche_getPartenaire($tranche);
                            if ($partenaire != null) {
                                if ($partenaire->getId() == $panier->getIdPartenaire()) {
                                    $montant = $this->Tranche_getMontant_retrocommissions_payable_par_courtier($tranche, $partenaire, -1, true);
                                    if ($montant != 0) {
                                        /**
                                         * Analyse des possibles paiements antérieurs et éventuellement des montants payés
                                         */
                                        $montantPaye = $this->Tranche_getMontant_retrocommissions_payable_par_courtier_payee($tranche);
                                        $isInvoiced = false;
                                        $montantInvoiced = 0;
                                        /** @var Article $article */
                                        foreach ($tranche->getArticles() as $article) {
                                            $addressedToPartenaire = $article->getNote()->getAddressedTo() == Note::TO_PARTENAIRE;
                                            $samePoste = $panier->getIdPartenaire() == $article->getIdPoste();
                                            $inADiffrentNote = $article->getNote()->getId() != $panier->getIdNote();
                                            if ($addressedToPartenaire == true & $samePoste == true && $inADiffrentNote) {
                                                $isInvoiced = true;
                                                $montantInvoiced += $article->getMontant();
                                            }
                                        }
                                        $canInvoice = $montant != $montantInvoiced;
                                        if ($canInvoice == true) {
                                            $tabPostesFacturables[] = [
                                                "poste" => "Rétrocommission",
                                                "addressedTo" => Note::TO_PARTENAIRE,
                                                "pourcentage" => $tranche->getPourcentage(),
                                                "montantPayable" => $montant,
                                                "montantPaye" => $montantPaye,
                                                "montantFacturé" => $montantInvoiced,
                                                "isInvoiced" => $isInvoiced,
                                                "canInvoice" => $canInvoice,
                                                "idCible" => $panier->getIdPartenaire(),
                                                "idPoste" => $panier->getIdPartenaire(),
                                                "idNote" => $panier->getIdNote() == null ? -1 : $panier->getIdNote(),
                                                "idTranche" => $tranche->getId(),
                                            ];
                                        }
                                    }
                                }
                            }
                            break;
                        case Note::TO_AUTORITE_FISCALE:
                            /** @var AutoriteFiscale $autorite */
                            $autorite = $this->autoriteFiscaleRepository->find($panier->getIdAutoriteFiscale());
                            if ($autorite != null) {
                                $net = $this->Tranche_getMontant_commission_ht($tranche, $addressedTo, $onlySharable);
                                $isIARD = match ($tranche->getCotation()->getPiste()->getRisque()->getBranche()) {
                                    Risque::BRANCHE_IARD_OU_NON_VIE => true,
                                    Risque::BRANCHE_VIE => false,
                                };
                                $montant = $this->serviceTaxes->getMontantTaxeAutorite($net, $isIARD, $autorite);

                                /**
                                 * Analyse des possibles paiements antérieurs et éventuellement des montants payés
                                 */
                                $montantPaye = match ($autorite->getTaxe()->getRedevable()) {
                                    Taxe::REDEVABLE_ASSUREUR => $this->Tranche_getMontant_taxe_payable_par_assureur_payee($tranche),
                                    Taxe::REDEVABLE_COURTIER => $this->Tranche_getMontant_taxe_payable_par_courtier_payee($tranche),
                                };
                                $isInvoiced = false;
                                $montantInvoiced = 0;
                                /** @var Article $article */
                                foreach ($tranche->getArticles() as $article) {
                                    $addressedToAutFiscale = $article->getNote()->getAddressedTo() == Note::TO_AUTORITE_FISCALE;
                                    $samePoste = $autorite->getTaxe()->getId() == $article->getIdPoste();
                                    $inADiffrentNote = $article->getNote()->getId() != $panier->getIdNote();
                                    if ($addressedToAutFiscale == true & $samePoste == true && $inADiffrentNote) {
                                        $isInvoiced = true;
                                        $montantInvoiced += $article->getMontant();
                                    }
                                }
                                $canInvoice = $montant != $montantInvoiced;

                                if ($canInvoice == true) {
                                    $tabPostesFacturables[] = [
                                        "poste" => $autorite->getTaxe()->getCode(),
                                        "addressedTo" => Note::TO_AUTORITE_FISCALE,
                                        "pourcentage" => $tranche->getPourcentage(),
                                        "montantPayable" => $montant,
                                        "montantPaye" => $montantPaye,
                                        "montantFacturé" => $montantInvoiced,
                                        "isInvoiced" => $isInvoiced,
                                        "canInvoice" => $canInvoice,
                                        "idCible" => $panier->getIdAutoriteFiscale(),
                                        "idPoste" => $autorite->getTaxe()->getId(),
                                        "idNote" => $panier->getIdNote() == null ? -1 : $panier->getIdNote(),
                                        "idTranche" => $tranche->getId(),
                                    ];
                                }
                            }
                            break;

                        default:
                            # code...
                            break;
                    }
                } else {
                    // dd("On ne peux pas facturer un poste qui dont la cotation n'est pas validée par le client");
                }
            }
        }
        // dd($tabPostesFacturables);
        return $tabPostesFacturables;
    }

    public function Tranche_getPostesFacturablesText(?Tranche $tranche, ?PanierNotes $panier, $addressedTo, bool $onlySharable)
    {
        $tabPosteFacturables = $this->Tranche_getPostesFacturables($tranche, $panier, $addressedTo, $onlySharable);
        // dd($tabPosteFacturables);
        $str = "Postes: ";
        foreach ($tabPosteFacturables as $posteFacturable) {
            $str .= $posteFacturable['poste'] . " @ " . number_format($posteFacturable['montantPayable'], 2, ',', ".") . " " . $this->serviceMonnaies->getMonnaieAffichage()->getCode();
        }
        return $str;
    }
    public function Tranche_isAlreadyInADifferentNote(?Tranche $tranche, $posteFacturable)
    {
        $rep = false;
        // dd($tranche->getArticles());
        /** @var Article $article */
        foreach ($tranche->getArticles() as $article) {
            if ($article->getNote() != null) {
                switch ($posteFacturable['addressedTo']) {
                    case Note::TO_AUTORITE_FISCALE:
                        if ($article->getNote()->getAutoritefiscale()) {
                            $rep = $article->getNote()->getAutoritefiscale()->getId() == $posteFacturable['idCible'];
                        }
                        break;
                    case Note::TO_ASSUREUR:
                        if ($article->getNote()->getAssureur()) {
                            $rep = $article->getNote()->getAssureur()->getId() == $posteFacturable['idCible'];
                        }
                        break;
                    case Note::TO_CLIENT:
                        if ($article->getNote()->getClient()) {
                            $rep = $article->getNote()->getClient()->getId() == $posteFacturable['idCible'];
                        }
                        break;
                    case Note::TO_PARTENAIRE:
                        if ($article->getNote()->getPartenaire()) {
                            $rep = $article->getNote()->getPartenaire()->getId() == $posteFacturable['idCible'];
                        }
                        break;
                    default:
                        # code...
                        break;
                }
            }
        }
        // dd("Il faut trouver si le poste ", $posteFacturable, " avait déjà été payée dans la tranche " . $tranche->getNom());
        return $rep;
    }
    public function Tranche_getMontant_taxe_payable_par_courtier(?Tranche $tranche, bool $onlySharable): float
    {
        $montant = 0;
        if ($tranche != null) {
            if ($tranche->getCotation()) {
                $montant = $this->Cotation_getMontant_taxe_payable_par_courtier($tranche->getCotation(), $onlySharable) * $tranche->getPourcentage();
            }
        }
        return $montant;
    }
    public function Tranche_getMontant_taxe_payable_par_courtier_payee(?Tranche $tranche): float
    {
        $montant = 0;
        if (count($tranche->getArticles())) {
            /** @var Article $article */
            foreach ($tranche->getArticles() as $articleTranche) {
                /** @var Article $article */
                $article = $articleTranche;

                /** @var Note $note */
                $note = $article->getNote();

                //Quelle proportion de la note a-t-elle été payée (100%?)
                $proportionPaiement = $this->Note_getMontant_paye($note) / $this->Note_getMontant_payable($note);

                //Qu'est-ce qu'on a facturé?
                if ($note->getAddressedTo() == Note::TO_AUTORITE_FISCALE) {
                    /** @var Taxe $taxe */
                    $taxe = $this->taxeRepository->find($article->getIdPoste());
                    if ($taxe) {
                        if ($taxe->getRedevable() == Taxe::REDEVABLE_COURTIER) {
                            // dd("Paiement Tax", $article);
                            $montant += $proportionPaiement * $article->getMontant();
                        }
                    }
                }
            }
        }
        return $montant;
    }
    
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
            if ($avenantEncours->getCotation()->getPiste()->getRenewalCondition() == Piste::RENEWAL_CONDITION_ONCE_OFF_AND_EXTENDABLE) {
                $renewalStatus['code'] = Avenant::RENEWAL_STATUS_ONCE_OFF;
            } else {
                /**
                 * Cherche des pistes (bound ou non), qui sont liés à cet avénant "AvenantEncours"
                 */
                $foundPiste = false;
                /** @var Piste $pisteExistante */
                foreach ($this->Entreprise_getPistes() as $pisteExistante) {
                    if ($pisteExistante->getAvenantDeBase() == $avenantEncours) {
                        $renewalStatus['code'] = match ($pisteExistante->getTypeAvenant()) {
                            Piste::AVENANT_RENOUVELLEMENT => $this->Piste_isBound($pisteExistante) == true ? Avenant::RENEWAL_STATUS_RENEWED : Avenant::RENEWAL_STATUS_RENEWING,
                            Piste::AVENANT_ANNULATION => Avenant::RENEWAL_STATUS_CANCELLED,
                            Piste::AVENANT_RESILIATION => Avenant::RENEWAL_STATUS_CANCELLED,
                            Piste::AVENANT_PROROGATION => Avenant::RENEWAL_STATUS_EXTENDED,
                        };
                        $foundPiste = true;
                    }
                }
                if ($foundPiste == false) {
                    $renewalStatus['code'] = Avenant::RENEWAL_STATUS_LOST;
                }
            }
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
    
    



    /**
     * ENTREPRISE
     */
    public function Entreprise_getSynthseCollecteRevenus()
    {
        $status = [
            self::STATUS_DUE => 0,
            self::STATUS_INVOICED => 0,
            self::STATUS_PAID => 0,
            self::STATUS_NOT_INVOICED => 0,
            self::STATUS_BALANCE_DUE => 0,
        ];
        $syntheseRevenu = [];
        $tabTypeRevenus = $this->getEnterprise()->getTypeRevenus();
        foreach ($tabTypeRevenus as $typeRevenu) {
            $miniInvoicingStatus = $this->TypeRevennu_getMiniInvoicingStatus($typeRevenu);
            //Nouvel algorithme
            $status[self::STATUS_DUE] += $miniInvoicingStatus[self::STATUS_DUE];
            $status[self::STATUS_INVOICED] += $miniInvoicingStatus[self::STATUS_INVOICED];
            $status[self::STATUS_NOT_INVOICED] += $miniInvoicingStatus[self::STATUS_NOT_INVOICED];
            $status[self::STATUS_PAID] += $this->Type_revenu_getMontant_ttc_collecte($typeRevenu);
            $status[self::STATUS_BALANCE_DUE] += $this->Type_revenu_getMontant_ttc_solde($typeRevenu);
        }
        // dd($status);
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => "Comm. ttc:",
            ReportSummary::VALEUR => $status[self::STATUS_DUE],
        ];
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_collecte_revenues_invoiced"),
            ReportSummary::VALEUR => $status[self::STATUS_INVOICED],
        ];
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_collecte_revenues_received"),
            ReportSummary::VALEUR => $status[self::STATUS_PAID],
        ];
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_collecte_revenues_balance"),
            ReportSummary::VALEUR => $status[self::STATUS_BALANCE_DUE],
        ];
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_collecte_revenues_not_invoiced"),
            ReportSummary::VALEUR => $status[self::STATUS_NOT_INVOICED],
        ];
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_collecte_revenues_not_invoiced"),
            ReportSummary::VALEUR => $status[self::STATUS_NOT_INVOICED],
        ];

        return $syntheseRevenu;
    }
    public function Entreprise_getSynthseRevenus()
    {
        $reserve = 0;
        $assiette = 0;
        $commPure = 0;
        $commHT = 0;
        $commTTC = 0;
        $syntheseRevenu = [];
        /** @var Invite $invite */
        foreach ($this->getEnterprise()->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                if ($this->Piste_isBound($piste)) {
                    $assiette += $this->Piste_getMontant_commission_pure($piste, -1, true);
                    $commPure += $this->Piste_getMontant_commission_pure($piste, -1, false);
                    $commHT += $this->Piste_getMontant_commission_ht($piste, -1, false);
                    $commTTC += $this->Piste_getMontant_commission_ttc($piste, -1, false);
                    $reserve += $this->Piste_getReserve($piste);
                }
            }
        }
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => "Réserve",
            ReportSummary::VALEUR => $reserve,
        ];
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => "Assiette",
            ReportSummary::VALEUR => $assiette,
        ];
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => "Com. pure",
            ReportSummary::VALEUR => $commPure,
        ];
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => "Com. ht",
            ReportSummary::VALEUR => $commHT,
        ];
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => "Com. ttc",
            ReportSummary::VALEUR => $commTTC,
        ];
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => "Com. ttc",
            ReportSummary::VALEUR => $commTTC,
        ];
        return $syntheseRevenu;
    }


    public function Entreprise_getSynthseRetrocommission()
    {
        $assiette = 0;
        $retrocommission = 0;
        $retrocommissionPayee = 0;
        $soldeRestant = 0;

        $syntheseRevenu = [];

        /** @var Invite $invite */
        foreach ($this->getEnterprise()->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                if ($this->Piste_isBound($piste)) {
                    $assiette += $this->Piste_getMontant_commission_pure($piste, -1, true);
                    $partenaires = $this->Piste_getPartenaires($piste);
                    if (count($partenaires) != 0) {
                        // dd($partenaires);
                        foreach ($partenaires as $partenaire) {
                            // dd($partenaire);
                            $retrocommission += $this->Piste_getMontant_retrocommissions_payable_par_courtier($piste, $partenaire, -1, true);
                            $retrocommissionPayee += $this->Piste_getMontant_retrocommissions_payable_par_courtier_payee($piste, $partenaire);
                            $soldeRestant += $this->Piste_getMontant_retrocommissions_payable_par_courtier_solde($piste, $partenaire, -1, true);
                        }
                    }
                }
            }
        }
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_retrocom_revenu_assiette"),
            ReportSummary::VALEUR => $assiette,
        ];
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_retrocom_due_partenaire"),
            ReportSummary::VALEUR => $retrocommission,
        ];
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_retrocom_payee"),
            ReportSummary::VALEUR => $retrocommissionPayee,
        ];
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_retrocom_due"),
            ReportSummary::VALEUR => $soldeRestant,
        ];
        $syntheseRevenu[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_retrocom_due"),
            ReportSummary::VALEUR => $soldeRestant,
        ];
        // dd($syntheseRevenu);
        return $syntheseRevenu;
    }

    public function Entreprise_getSynthseTaxes()
    {
        $comht = 0;
        $taxeDue = 0;
        $taxePayee = 0;
        $soldeRestant = 0;

        $syntheseTaxes = [];
        /** @var Invite $invite */
        foreach ($this->getEnterprise()->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                if ($this->Piste_isBound($piste)) {
                    $comht += $this->Piste_getMontant_commission_ht($piste, -1, false);
                    $taxeDue += $this->Piste_getMontant_taxe_payable_par_assureur($piste, false) + $this->Piste_getMontant_taxe_payable_par_courtier($piste, false);
                    $taxePayee += $this->Piste_getMontant_taxe_payable_par_assureur_payee($piste, false) + $this->Piste_getMontant_taxe_payable_par_courtier_payee($piste, false);
                    $soldeRestant += $this->Piste_getMontant_taxe_payable_par_assureur_solde($piste, false) + $this->Piste_getMontant_taxe_payable_par_courtier_solde($piste, false);
                    // dd($partenaire);
                }
            }
        }
        $syntheseTaxes[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_tax_revenu_net"),
            ReportSummary::VALEUR => $comht,
        ];
        $syntheseTaxes[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_tax_payable"),
            ReportSummary::VALEUR => $taxeDue,
        ];
        $syntheseTaxes[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_tax_payee"),
            ReportSummary::VALEUR => $taxePayee,
        ];
        $syntheseTaxes[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_tax_due"),
            ReportSummary::VALEUR => $soldeRestant,
        ];
        $syntheseTaxes[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_tax_due"),
            ReportSummary::VALEUR => $soldeRestant,
        ];
        // dd($syntheseRevenu);
        return $syntheseTaxes;
    }

    public function Entreprise_getSynthesePrimes()
    {
        $chargementsPrimesGroupes = [];
        $chargementsPrimes = [];
        $primeTTC = 0;

        /** @var Invite $invite */
        foreach ($this->getEnterprise()->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                if ($this->Piste_isBound($piste)) {
                    foreach ($piste->getCotations() as $cotation) {
                        if ($this->Cotation_isBound($cotation)) {
                            foreach ($cotation->getChargements() as $chargement) {
                                $chargementsPrimes[] = $chargement;
                            }
                        }
                    }
                }
            }
        }

        foreach ($this->getEnterprise()->getChargements() as $typeChargement) {
            $montant = 0;
            foreach ($chargementsPrimes as $chargementExistant) {
                if ($chargementExistant->getType() == $typeChargement) {
                    $montant += $chargementExistant->getMontantFlatExceptionel();
                }
            }
            $chargementsPrimesGroupes[] = [
                ReportSummary::RUBRIQUE => $typeChargement->getNom(),
                ReportSummary::VALEUR => $montant,
            ];
            $primeTTC += $montant;
        }

        $chargementsPrimesGroupes[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_policies_gross_prem"),
            ReportSummary::VALEUR => $primeTTC,
        ];
        return $chargementsPrimesGroupes;
    }

    public function Entreprise_getClaimsNotifications()
    {
        $tabClaimsNotifications = [];
        /** @var Invite $invite */
        foreach ($this->getEnterprise()->getInvites() as $invite) {
            foreach ($invite->getNotificationSinistres() as $notification) {
                $tabClaimsNotifications[] = $notification;
            }
        }
        return $tabClaimsNotifications;
    }

    public function Entreprise_getSynthesSinistres()
    {
        $montDommage = 0;
        $montDommagePayable = 0;
        $montDommagePaye = 0;
        $montDommageSolde = 0;

        $syntheseTaxes = [];
        /** @var Invite $invite */
        foreach ($this->getEnterprise()->getInvites() as $invite) {
            foreach ($invite->getNotificationSinistres() as $notification) {
                // dd("Suis ici.");
                $montDommage += $notification->getDommage();
                $montDommagePayable += $this->Notification_Sinistre_getCompensation($notification);
                $montDommagePaye += $this->Notification_Sinistre_getCompensationVersee($notification);
                $montDommageSolde += $this->Notification_Sinistre_getSoldeAVerser($notification);
            }
        }
        $syntheseTaxes[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_claims_domage"),
            ReportSummary::VALEUR => $montDommage,
        ];
        $syntheseTaxes[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_claims_compensation"),
            ReportSummary::VALEUR => $montDommagePayable,
        ];
        $syntheseTaxes[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_claims_paye"),
            ReportSummary::VALEUR => $montDommagePaye,
        ];
        $syntheseTaxes[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_claims_due"),
            ReportSummary::VALEUR => $montDommageSolde,
        ];
        $syntheseTaxes[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_claims_due"),
            ReportSummary::VALEUR => $montDommageSolde,
        ];
        // dd($syntheseRevenu);
        return $syntheseTaxes;
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

    public function Entreprise_getAvenantsByReference(string $reference)
    {
        $avenants = new ArrayCollection();
        /** @var Invite $invite */
        foreach ($this->getEnterprise()->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                if ($this->Piste_isBound($piste)) {
                    foreach ($piste->getCotations() as $cotation) {
                        foreach ($cotation->getAvenants() as $avenant) {
                            if (!$avenants->contains($avenant) && $avenant->getReferencePolice() == $reference) {
                                $avenants->add($avenant);
                            }
                        }
                    }
                }
            }
        }
        return $avenants;
    }

    public function Entreprise_getPistes(bool $onlyBound = false)
    {
        $pistes = new ArrayCollection();
        /** @var Invite $invite */
        foreach ($this->getEnterprise()->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                if ($onlyBound == true) {
                    if ($this->Piste_isBound($piste)) {
                        if (!$pistes->contains($piste)) {
                            $pistes->add($piste);
                        }
                    }
                } else {
                    if (!$pistes->contains($piste)) {
                        $pistes->add($piste);
                    }
                }
            }
        }
        return $pistes;
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
        foreach ($this->getEnterprise()->getAssureurs() as $assureur) {
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
        foreach ($this->getEnterprise()->getPartenaires() as $partenaire) {
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
        foreach ($this->getEnterprise()->getRisques() as $risque) {
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

    public function Offre_Indemnisation_getCompensationVersee(?OffreIndemnisationSinistre $offre_indemnisation)
    {
        $montant = 0;
        if ($offre_indemnisation != null) {
            // dd("Ici: ", $offre_indemnisation);
            foreach ($offre_indemnisation->getPaiements() as $paiement) {
                $montant += $paiement->getMontant();
            }
        }
        return $montant;
    }

    public function Offre_Indemnisation_getSoldeAVerser(?OffreIndemnisationSinistre $offre_indemnisation)
    {
        $montant = 0;
        if ($offre_indemnisation != null) {
            $compensation = 0;
            if ($offre_indemnisation->getNotificationSinistre() != null) {
                $compensation = $offre_indemnisation->getMontantPayable();
                // dd($compensation);
            }
            $compensationVersee = $this->Offre_Indemnisation_getCompensationVersee($offre_indemnisation);
            $montant = $compensation - $compensationVersee;
        }
        return $montant;
    }

    public function Notification_Sinistre_getCompensation(?NotificationSinistre $notification_sinistre)
    {
        $compensation = 0;
        if ($notification_sinistre != null) {
            foreach ($notification_sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $compensation += $offre_indemnisation->getMontantPayable();
            }
        }
        return $compensation;
    }

    public function Notification_Sinistre_getCompensationVersee(?NotificationSinistre $notification_sinistre)
    {
        $montant = 0;
        if ($notification_sinistre != null) {
            // dd("Ici: ", $offre_indemnisation);
            foreach ($notification_sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $montant += $this->Offre_Indemnisation_getCompensationVersee($offre_indemnisation);
            }
        }
        return $montant;
    }

    public function Notification_Sinistre_getSoldeAVerser(?NotificationSinistre $notification_sinistre)
    {
        $montant = 0;
        if ($notification_sinistre != null) {
            foreach ($notification_sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $montant += $this->Offre_Indemnisation_getSoldeAVerser($offre_indemnisation);
            }
        }
        return $montant;
    }

    public function Notification_Sinistre_getFranchise(?NotificationSinistre $notification_sinistre)
    {
        $montant = 0;
        if ($notification_sinistre != null) {
            foreach ($notification_sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $montant += $offre_indemnisation->getFranchiseAppliquee();
            }
        }
        return $montant;
    }

    public function Notification_Sinistre_getStatusDocumentsAttendus(?NotificationSinistre $notification_sinistre)
    {
        $tabDocuments = [
            "Attendus" => [],
            "Fournis" => [],
            "Manquants" => [],
        ];
        if ($notification_sinistre != null) {
            $strgDocs = "";
            foreach ($this->getEnterprise()->getModelePieceSinistres() as $modele) {
                $strgDocs .= $modele->getNom() . ", ";
            }
            $tabDocuments['Attendus'] = $this->getEnterprise()->getModelePieceSinistres();
            // $tabDocuments['Docs_attendus'] = $this->getEnterprise()->getModelePieceSinistres();

            $strgDocs = "";
            foreach ($notification_sinistre->getPieces() as $pieceSinistre) {
                if ($pieceSinistre->getType()) {
                    $strgDocs .= $pieceSinistre->getType()->getNom() . ", ";
                }
            }
            $tabDocuments['Fournis'] = $notification_sinistre->getPieces();
            // $tabDocuments['Docs_fournis'] = $notification_sinistre->getPieces();

            $manquants = new ArrayCollection();
            foreach ($this->getEnterprise()->getModelePieceSinistres() as $typePiece) {
                $isFournis = false;
                foreach ($notification_sinistre->getPieces() as $pieceSinistre) {
                    if ($pieceSinistre->getType() == $typePiece) {
                        $isFournis = true;
                    }
                }
                if ($isFournis == false) {
                    if (!$manquants->contains($typePiece)) {
                        $manquants->add($typePiece->getNom());
                    }
                }
            }
            $tabDocuments['Manquants'] = $manquants;
            // dd($tabDocuments, count($tabDocuments['Docs_attendus']), count($tabDocuments['Docs_fournis']));
        }
        return $tabDocuments;
    }

    public function Notification_Sinistre_getStatusDocumentsAttendusNumbers(?NotificationSinistre $notification_sinistre)
    {
        $tabDocuments = $this->Notification_Sinistre_getStatusDocumentsAttendus($notification_sinistre);

        return [
            "Attendus" => count($tabDocuments["Attendus"]) . " pc(s)",
            "Fournis" => count($tabDocuments["Fournis"]) . " pc(s)",
            "Manquants" => count($tabDocuments["Manquants"]) . " pc(s)",
        ];
    }


    public function Notification_Sinistre_getDureeReglement(?NotificationSinistre $notification_sinistre)
    {
        $duree = -1;
        $dateNotfication = $notification_sinistre->getNotifiedAt();
        $dateRgelement = null;
        if ($this->Notification_Sinistre_getSoldeAVerser($notification_sinistre) == 0) {
            $offres = $notification_sinistre->getOffreIndemnisationSinistres();
            if (count($offres) != 0) {
                $reglements = ($offres[count($offres) - 1])->getPaiements();
                $dateRgelement = ($reglements[count($reglements) - 1])->getPaidAt();
                $duree = $this->serviceDates->daysEntre($dateNotfication, $dateRgelement);
            }
        }
        return $duree;
    }
    public function Notification_Sinistre_getTest(?NotificationSinistre $notification_sinistre)
    {
        return 1986;
    }
    public function Notification_Sinistre_getDateDernierRgelement(?NotificationSinistre $notification_sinistre)
    {
        $dateDernierRgelement = null;
        if ($this->Notification_Sinistre_getSoldeAVerser($notification_sinistre) == 0) {
            $offres = $notification_sinistre->getOffreIndemnisationSinistres();
            if (count($offres) != 0) {
                $reglements = ($offres[count($offres) - 1])->getPaiements();
                $dateDernierRgelement = ($reglements[count($reglements) - 1])->getPaidAt();
            }
        }
        return $dateDernierRgelement;
    }

    public function calculerPeriodeCouverture($mouvement, Avenant $avenantDeBase)
    {
        //calcul du numéro de l'avenant
        $numAvenant = 0;
        $lastEffectDate = null;
        $lastExpiryDate = null;
        /** @var Avenant $avenantExistant */
        foreach ($this->Entreprise_getAvenants() as $avenantExistant) {
            if ($avenantExistant->getReferencePolice() == $avenantDeBase->getReferencePolice()) {
                $numAvenant = $avenantExistant->getNumero();
                $lastEffectDate = $avenantExistant->getStartingAt();
                $lastExpiryDate = $avenantExistant->getEndingAt();
            }
        }
        $numAvenant++;

        $newEffectDate = null;
        $newExpiryDate = null;
        if ($mouvement == Piste::AVENANT_PROROGATION || $mouvement == Piste::AVENANT_RENOUVELLEMENT) {
            $newEffectDate = $lastExpiryDate;
            $newExpiryDate = $this->serviceDates->ajouterAnnees($lastExpiryDate, 1);
        } else if ($mouvement == Piste::AVENANT_INCORPORATION || $mouvement == Piste::AVENANT_SOUSCRIPTION) {
            $newEffectDate = new DateTimeImmutable("now");
            if ($mouvement == Piste::AVENANT_SOUSCRIPTION) {
                $newExpiryDate = $this->serviceDates->ajouterJours($newEffectDate, 364);
            } else {
                //On maintien la dernière date d'expiration trouvé, puisqu'il ne s'agit que d'une incorporation
                $newExpiryDate = $lastExpiryDate;
            }
        } else if ($mouvement == Piste::AVENANT_ANNULATION || $mouvement == Piste::AVENANT_RESILIATION) {
            $newEffectDate = new DateTimeImmutable("now");
            $newExpiryDate = new DateTimeImmutable("now");
        }
        return [
            'Effect Date' => $newEffectDate,
            'Expiry Date' => $newExpiryDate,
            'New Numero Avenant' => $numAvenant,
        ];
    }


    public function Entreprise_getDataTabProductionPerInsurerPerMonth()
    {
        $data = [];
        $ligneDernierSubTotal = -1;
        $ligne = 0;
        $grandTotal = ['prime' => 0, 'comHT' => 0, 'taxe' => 0, 'comTTC' => 0, 'comReceived' => 0, 'comBalance' => 0];

        //Chargement de la liste des assureurs
        for ($moisEncours = 1; $moisEncours <= 12; $moisEncours++) {
            $monthName = date('F', mktime(0, 0, 0, $moisEncours, 1, date('Y')));
            $totalMois = ['prime' => 0, 'comHT' => 0, 'taxe' => 0, 'comTTC' => 0, 'comReceived' => 0, 'comBalance' => 0];
            $data[] = $this->loadDataToReportSet(0, InsurerReportSet::TYPE_SUBTOTAL, $monthName, $totalMois);
            $ligneDernierSubTotal = $ligne;
            $ligne += 1;

            //Pour chaque assureur
            foreach ($this->Entreprise_getAssureurs() as $assureur) {
                $totalAssureur = ['prime' => 0, 'comHT' => 0, 'taxe' => 0, 'comTTC' => 0, 'comReceived' => 0, 'comBalance' => 0];

                /** @var Avenant $avenant */
                foreach ($this->Entreprise_getAvenants() as $avenant) {
                    //On ne traite que les avenant du mois "moisEncours" en cours
                    if ($moisEncours == $avenant->getStartingAt()->format('n') && $avenant->getCotation()->getAssureur() == $assureur) {
                        //retire de la base de données les vraies valeurs
                        $totalAssureur['prime'] += $this->Cotation_getMontant_prime_payable_par_client($avenant->getCotation());
                        $totalAssureur['comHT'] += $this->Cotation_getMontant_commission_ht($avenant->getCotation(), -1, false);
                        $totalAssureur['taxe'] += $this->Cotation_getMontant_taxe_payable_par_assureur($avenant->getCotation(), false);
                        $totalAssureur['comTTC'] += $this->Cotation_getMontant_commission_ttc($avenant->getCotation(), -1, false);
                        $totalAssureur['comReceived'] += $this->Cotation_getMontant_commission_ttc_collectee($avenant->getCotation());
                        $totalAssureur['comBalance'] += $this->Cotation_getMontant_commission_ttc_solde($avenant->getCotation(), -1, false);
                    }
                }
                $data[] = $this->loadDataToReportSet(0, InsurerReportSet::TYPE_ELEMENT, $assureur->getNom(), $totalAssureur);
                $ligne += 1;

                //On cumule le total du mois
                $totalMois['prime'] += $totalAssureur['prime'];
                $totalMois['comHT'] += $totalAssureur['comHT'];
                $totalMois['taxe'] += $totalAssureur['taxe'];
                $totalMois['comTTC'] += $totalAssureur['comTTC'];
                $totalMois['comReceived'] += $totalAssureur['comReceived'];
                $totalMois['comBalance'] += $totalAssureur['comBalance'];

                //On cumul aussi le grand total
                $grandTotal['prime'] += $totalAssureur['prime'];
                $grandTotal['comHT'] += $totalAssureur['comHT'];
                $grandTotal['taxe'] += $totalAssureur['taxe'];
                $grandTotal['comTTC'] += $totalAssureur['comTTC'];
                $grandTotal['comReceived'] += $totalAssureur['comReceived'];
                $grandTotal['comBalance'] += $totalAssureur['comBalance'];
            }
            $data[$ligneDernierSubTotal] = $this->loadDataToReportSet(0, InsurerReportSet::TYPE_SUBTOTAL, $monthName, $totalMois);
        }
        $data[] = $this->loadDataToReportSet(0, InsurerReportSet::TYPE_TOTAL, "TOTAL", $grandTotal);
        // dd($data);
        return $data;
    }

    public function Entreprise_getDataTabProductionPerPartnerPerMonth()
    {
        $data = [];
        $ligneDernierSubTotal = -1;
        $ligne = 0;
        $grandTotal = ['partnerRate' => 0, 'prime' => 0, 'comPURE' => 0, 'taxe' => 0, 'retroCom' => 0, 'retroComPaid' => 0, 'retroComBalance' => 0];

        //Chargement de la liste des assureurs
        for ($moisEncours = 1; $moisEncours <= 12; $moisEncours++) {
            $monthName = date('F', mktime(0, 0, 0, $moisEncours, 1, date('Y')));
            $totalMois = ['partnerRate' => 0, 'prime' => 0, 'comPURE' => 0, 'taxe' => 0, 'retroCom' => 0, 'retroComPaid' => 0, 'retroComBalance' => 0];
            $data[] = $this->loadDataToReportSet(1, PartnerReportSet::TYPE_SUBTOTAL, $monthName, $totalMois);
            $ligneDernierSubTotal = $ligne;
            $ligne += 1;

            //Pour chaque intermédiaire partenaire
            foreach ($this->Entreprise_getPartenaires() as $partenaire) {
                $totalPartenaire = ['partnerRate' => 0, 'prime' => 0, 'comPURE' => 0, 'taxe' => 0, 'retroCom' => 0, 'retroComPaid' => 0, 'retroComBalance' => 0];

                /** @var Avenant $avenant */
                foreach ($this->Entreprise_getAvenants() as $avenant) {
                    //On ne traite que les avenant du mois "moisEncours" en cours
                    if ($moisEncours == $avenant->getStartingAt()->format('n') && $this->Cotation_getPartenaire($avenant->getCotation()) == $partenaire) {
                        //retire de la base de données les vraies valeurs
                        $totalPartenaire['prime'] += $this->Cotation_getMontant_prime_payable_par_client($avenant->getCotation());
                        $totalPartenaire['comPURE'] += $this->Cotation_getMontant_commission_pure($avenant->getCotation(), -1, true);
                        $totalPartenaire['taxe'] += $this->Cotation_getMontant_taxe_payable_par_courtier($avenant->getCotation(), true) + $this->Cotation_getMontant_taxe_payable_par_assureur($avenant->getCotation(), true);
                        $totalPartenaire['retroCom'] += $this->Cotation_getMontant_retrocommissions_payable_par_courtier($avenant->getCotation(), $partenaire, -1, true);
                        $totalPartenaire['retroComPaid'] += $this->Cotation_getMontant_retrocommissions_payable_par_courtier_payee($avenant->getCotation(), $partenaire);
                        $totalPartenaire['retroComBalance'] += $this->Cotation_getMontant_retrocommissions_payable_par_courtier_solde($avenant->getCotation(), $partenaire, -1, false);
                    }
                }
                $totalPartenaire['partnerRate'] = $partenaire->getPart() * 100;
                $data[] = $this->loadDataToReportSet(1, PartnerReportSet::TYPE_ELEMENT, $partenaire->getNom(), $totalPartenaire);
                $ligne += 1;

                //On cumule le total du mois
                $totalMois['partnerRate'] = $totalPartenaire['partnerRate'];
                $totalMois['prime'] += $totalPartenaire['prime'];
                $totalMois['comPURE'] += $totalPartenaire['comPURE'];
                $totalMois['taxe'] += $totalPartenaire['taxe'];
                $totalMois['retroCom'] += $totalPartenaire['retroCom'];
                $totalMois['retroComPaid'] += $totalPartenaire['retroComPaid'];
                $totalMois['retroComBalance'] += $totalPartenaire['retroComBalance'];

                //On cumul aussi le grand total
                $grandTotal['partnerRate'] += $totalPartenaire['partnerRate'];
                $grandTotal['prime'] += $totalPartenaire['prime'];
                $grandTotal['comPURE'] += $totalPartenaire['comPURE'];
                $grandTotal['taxe'] += $totalPartenaire['taxe'];
                $grandTotal['retroCom'] += $totalPartenaire['retroCom'];
                $grandTotal['retroComPaid'] += $totalPartenaire['retroComPaid'];
                $grandTotal['retroComBalance'] += $totalPartenaire['retroComBalance'];
            }
            $data[$ligneDernierSubTotal] = $this->loadDataToReportSet(1, PartnerReportSet::TYPE_SUBTOTAL, $monthName, $totalMois);
        }
        $data[] = $this->loadDataToReportSet(1, PartnerReportSet::TYPE_TOTAL, "TOTAL", $grandTotal);
        // dd($data);
        return $data;
    }

    public function Entreprise_getDataTabTop20Clients()
    {
        $data = [];
        $dataCopie = [];
        $grandTotal = ['primeTTC' => 0, 'autresChargements' => 0, 'primeHT' => 0, 'comTTC' => 0];

        /**
         * TRI PAR ORDRE DECROISSANT
         */
        //Pour chaque client
        foreach ($this->getEnterprise()->getClients() as $client) {
            $elementClient = ['primeTTC' => 0, 'autresChargements' => 0, 'primeHT' => 0, 'comTTC' => 0];

            foreach ($client->getPistes() as $piste) {
                foreach ($piste->getCotations() as $cotation) {
                    if ($this->Cotation_isBound($cotation)) {
                        $primeHt = 0;
                        $autresChargements = 0;
                        foreach ($cotation->getChargements() as $chargementPourPrime) {
                            if ($chargementPourPrime->getType()->getFonction() == Chargement::FONCTION_PRIME_NETTE) {
                                $primeHt += $chargementPourPrime->getMontantFlatExceptionel();
                            } else {
                                $autresChargements += $chargementPourPrime->getMontantFlatExceptionel();
                            }
                        }
                        $elementClient['primeHT'] = $primeHt;
                        $elementClient['autresChargements'] = $autresChargements;
                        $elementClient['primeTTC'] = $primeHt + $autresChargements;
                        $elementClient['comTTC'] = $this->Cotation_getMontant_commission_ttc($cotation, -1, false);
                    }
                }
            }
            $nomClient = $client->getNom();
            $data[$nomClient] = $elementClient['primeTTC'];
            $dataCopie[$nomClient] = $this->loadDataToReportSet(2, Top20ClientReportSet::TYPE_ELEMENT, $nomClient, $elementClient);
        }
        //Exécution du tri
        arsort($data);

        /**
         * SELECTION DES 20 PREMIERS REPORTSET
         */
        $newOrderedData = [];
        $nbMax = 20;
        foreach ($data as $client => $valeur) {
            /** @var Top20ClientReportSet $reportSet */
            $reportSet = $dataCopie[$client];

            $newOrderedData[] = $reportSet;

            //On cumul aussi pour avoir le grand total
            $grandTotal['primeHT'] += $reportSet->getNet_premium();
            $grandTotal['autresChargements'] += $reportSet->getOther_loadings();
            $grandTotal['primeTTC'] += $reportSet->getGw_premium();
            $grandTotal['comTTC'] += $reportSet->getG_commission();

            $nbMax--;
            if ($nbMax == 0) {
                break;
            }
        }
        $newOrderedData[] = $this->loadDataToReportSet(2, Top20ClientReportSet::TYPE_TOTAL, "TOTAL", $grandTotal);

        /** @var Top20ClientReportSet $reportPlusGrand */
        $reportPlusGrand = $newOrderedData[0];

        $notes = "Aucun client.";
        if (count($newOrderedData) != 0) {
            $notes = "<span class='fw-bold'>" . $reportPlusGrand->getLabel() . "</span> est le plus grand client avec une prime annuelle de <span class='fw-bold'>" . number_format($reportPlusGrand->getGw_premium(), 2, ",", " ") . " " . $this->serviceMonnaies->getCodeMonnaieAffichage() . "</span>, qui a généré un revenu total de <span class='fw-bold'>" . number_format($reportPlusGrand->getG_commission(), 2, ",", " ") . " " . $this->serviceMonnaies->getCodeMonnaieAffichage() . "</span>";
        }
        return [
            'data' => $newOrderedData,
            'notes' => $notes,
        ];
    }

    private function loadDataToReportSet($reportSet, $type, string $label, $dataTab)
    {
        $data = null;
        $data = match ($reportSet) {
            0 => $this->createInsurerReportSet($type, $label, $dataTab['prime'], $dataTab['comHT'], $dataTab['taxe'], $dataTab['comTTC'], $dataTab['comReceived'], $dataTab['comBalance']),
            1 => $this->createPartnerReportSet($type, $label, $dataTab['partnerRate'], $dataTab['prime'], $dataTab['comPURE'], $dataTab['taxe'], $dataTab['retroCom'], $dataTab['retroComPaid'], $dataTab['retroComBalance']),
            2 => $this->createTop20ClientsReportSet($type, $label, $dataTab['primeTTC'], $dataTab['primeHT'], $dataTab['autresChargements'], $dataTab['comTTC']),
        };
        return $data;
    }

    public function createInsurerReportSet(int $type, string $label, $prime, $netcom, $taxe, $grosscom, $comreceived, $combalance): InsurerReportSet
    {
        return (new InsurerReportSet())
            ->setType($type)
            ->setCurrency_code($this->serviceMonnaies->getCodeMonnaieAffichage())
            ->setLabel($label)
            ->setGw_premium($prime)
            ->setNet_com($netcom)
            ->setTaxes($taxe)
            ->setGros_commission($grosscom)
            ->setCommission_received($comreceived)
            ->setBalance_due($combalance);
    }

    public function createPartnerReportSet(int $type, string $label, $partnerRate, $prime, $netcom, $taxe, $retroCom, $retroComPaid, $retroComBalance): PartnerReportSet
    {
        return (new PartnerReportSet())
            ->setType($type)
            ->setCurrency_code($this->serviceMonnaies->getCodeMonnaieAffichage())
            ->setLabel($label)
            ->setPartner_rate($partnerRate)
            ->setGw_premium($prime)
            ->setNet_com($netcom)
            ->setTaxes($taxe)
            ->setCo_brokerage($retroCom)
            ->setAmount_paid($retroComPaid)
            ->setBalance_due($retroComBalance);
    }

    public function createTop20ClientsReportSet(int $type, string $label, $primeTTC, $primeHT, $autresChargements, $comTTC): Top20ClientReportSet
    {
        return (new Top20ClientReportSet())
            ->setType($type)
            ->setCurrency_code($this->serviceMonnaies->getCodeMonnaieAffichage())
            ->setLabel($label)
            ->setGw_premium($primeTTC)
            ->setOther_loadings($autresChargements)
            ->setNet_premium($primeHT)
            ->setG_commission($comTTC);
    }

    public function Entreprise_getAssureurs()
    {
        return $this->getEnterprise()->getAssureurs();
    }

    public function Entreprise_getPartenaires()
    {
        return $this->getEnterprise()->getPartenaires();
    }

    public function Entreprise_getClients()
    {
        return $this->getEnterprise()->getClients();
    }

    public function Entreprise_getTaches()
    {
        $tabTaches = [];
        foreach ($this->getEnterprise()->getInvites() as $invite) {
            foreach ($invite->getTaches() as $tache) {
                $tabTaches[] = $tache;
            }
        }
        return $tabTaches;
    }

    public function Tache_getClient(Tache $tache): ?Client
    {
        if ($tache != null) {
            if ($this->Tache_getPiste($tache) != null) {
                return $this->Tache_getPiste($tache)->getClient();
            }
        }
        return null;
    }

    public function Tache_getInvite(Tache $tache): ?Invite
    {
        if ($tache != null) {
            if ($this->Tache_getPiste($tache) != null) {
                return $this->Tache_getPiste($tache)->getInvite();
            }
        }
        return null;
    }

    public function Utilisateur_getUtilisateurByInvite(?Invite $invite): ?Utilisateur
    {
        $users = $this->utilisateurRepository->findBy([
            'email' => $invite  != null ? $invite->getEmail() : "",
        ]);
        return count($users) != 0 ? $users[0] : null;
    }

    public function Invite_getInviteByUtilisateur($idEntreprise, ?Utilisateur $utilisateur): ?Invite
    {
        $invites = $this->inviteRepository->findBy([
            'email' => $utilisateur->getEmail(),
            'entreprise' => $idEntreprise,
        ]);
        return count($invites) != 0 ? $invites[0] : null;
    }

    public function Utilisateur_getUtilisateurByEmail($email): ?Utilisateur
    {
        $users = $this->utilisateurRepository->findBy([
            'email' => $email,
        ]);
        return count($users) != 0 ? $users[0] : null;
    }

    public function Invite_getInviteByEmail($idEntreprise, string $email): ?Invite
    {
        $invites = $this->inviteRepository->findBy([
            'email' => $email,
            'entreprise' => $idEntreprise,
        ]);
        return count($invites) != 0 ? $invites[0] : null;
    }

    public function Tache_getContacts(Tache $tache)
    {
        if ($tache != null) {
            if ($this->Tache_getClient($tache) != null) {
                return $this->Tache_getClient($tache)->getContacts()->toArray();
            }
        }
        return null;
    }

    public function Tache_getPiste(Tache $tache): ?Piste
    {
        if ($tache != null) {
            if ($this->Tache_getCotation($tache) != null) {
                return $this->Tache_getCotation($tache)->getPiste();
            } else if ($tache->getPiste() != null) {
                return $tache->getPiste();
            }
        }
        return null;
    }

    public function Tache_getTypeAvenant(Tache $tache)
    {
        if ($tache != null) {
            if ($this->Tache_getPiste($tache) != null) {
                return match ($this->Tache_getPiste($tache)->getTypeAvenant()) {
                    Piste::AVENANT_ANNULATION => "ANNULATION",
                    Piste::AVENANT_INCORPORATION => "INCORPORATION",
                    Piste::AVENANT_PROROGATION => "PROROGATION",
                    Piste::AVENANT_RENOUVELLEMENT => "RENOUVELLEMENT",
                    Piste::AVENANT_RESILIATION => "RESILIATION",
                    Piste::AVENANT_SOUSCRIPTION => "SOUSCRIPTION",
                };
            }
        }
        return null;
    }



    public function Tache_getCotation(Tache $tache): ?Cotation
    {
        return $tache->getCotation();
    }

    public function createTaskReportSet($index, Tache $tache)
    {
        return (new TaskReportSet())
            ->setType(TaskReportSet::TYPE_ELEMENT)
            ->setCurrency_code($this->serviceMonnaies->getCodeMonnaieAffichage())
            ->setLead($this->Tache_getPiste($tache))
            ->setTask_description("<strong>(" . $index . ") \"" . $tache->getDescription() . "\"</strong>")
            ->setClient($this->Tache_getClient($tache))
            ->setContacts($this->Tache_getContacts($tache))
            ->setOwner($this->Utilisateur_getUtilisateurByInvite($this->Tache_getInvite($tache)))
            ->setEndorsement($this->Tache_getTypeAvenant($tache))
            ->setExcutor($this->Utilisateur_getUtilisateurByInvite($tache->getExecutor()))
            ->setEffect_date($tache->getToBeEndedAt())
            ->setPotential_premium($this->Cotation_getMontant_prime_payable_par_client($this->Tache_getCotation($tache)))
            ->setPotential_commission($this->Cotation_getMontant_commission_ttc($this->Tache_getCotation($tache), -1, false))
            ->setNBFeedbacks(count($tache->getFeedbacks()))
            ->setStatusExecution($this->Tache_getExecutionStatus($tache)['text'])
            ->setDays_remaining($this->Tache_getExecutionStatus($tache)['remaining days']);
    }



    public function Entreprise_getDataTabTasks()
    {
        $cumulPrime = 0;
        $cumulCom = 0;
        $tabReportSets = [];
        $index = 1;
        /** @var Tache $tache */
        foreach ($this->Entreprise_getTaches() as $tache) {
            $taskStatus = $this->Tache_getExecutionStatus($tache);
            //On n'affiche que les tâches qui ne sont pas encore accomplies
            if ($taskStatus['code'] != Tache::EXECUTION_STATUS_COMPLETED) {
                $dataSet = $this->createTaskReportSet($index, $tache);
                $cumulPrime += $this->Cotation_getMontant_prime_payable_par_client($this->Tache_getCotation($tache));
                $cumulCom += $this->Cotation_getMontant_commission_ttc($this->Tache_getCotation($tache), -1, false);
                // dd($tache, $dataSet);
                if ($dataSet->getDays_remaining() == 0) {
                    $dataSet->setEffect_date_comment($this->translator->trans("company_dashboard_section_principale_tasks_today"));
                }
                if ($dataSet->getDays_remaining() < 0) {
                    $dataSet->setEffect_date_comment($this->translator->trans("company_dashboard_section_principale_tasks_since") . ($dataSet->getDays_remaining() * (-1)) . $this->translator->trans("company_dashboard_section_principale_tasks_days"));
                }
                if ($dataSet->getDays_remaining() > 0) {
                    $dataSet->setEffect_date_comment($this->translator->trans("company_dashboard_section_principale_tasks_in") . ($dataSet->getDays_remaining()) . $this->translator->trans("company_dashboard_section_principale_tasks_days"));
                }
                // dd($dataSet);
                $tabReportSets[] = $dataSet;
                $index++;
            }
        }
        $tabReportSets[] = (new TaskReportSet())
            ->setType(TaskReportSet::TYPE_TOTAL)
            ->setCurrency_code($this->serviceMonnaies->getCodeMonnaieAffichage())
            ->setTask_description("TOTAL")
            ->setPotential_premium($cumulPrime)
            ->setPotential_commission($cumulCom);

        return $tabReportSets;
    }

    public function Avenant_getNomAssureur(Avenant $avenant)
    {
        if ($avenant != null) {
            if ($avenant->getCotation() != null) {
                return $avenant->getCotation()->getAssureur()->getNom();
            }
        }
        return "Non défini";
    }

    public function Avenant_getNomClient(Avenant $avenant)
    {
        if ($avenant != null) {
            if ($avenant->getCotation() != null) {
                if ($avenant->getCotation()->getPiste() != null) {
                    if ($avenant->getCotation()->getPiste()->getClient() != null) {
                        return $avenant->getCotation()->getPiste()->getClient()->getNom();
                    }
                }
            }
        }
        return "Non défini";
    }

    public function Avenant_getStringTypeAvenant(Avenant $avenant)
    {
        if ($avenant != null) {
            if ($avenant->getCotation() != null) {
                if ($avenant->getCotation()->getPiste() != null) {
                    return $this->getTypeAvenant($avenant->getCotation()->getPiste()->getTypeAvenant());
                }
            }
        }
        return "Non défini";
    }

    public function Avenant_getCodeCover(Avenant $avenant)
    {
        if ($avenant != null) {
            if ($avenant->getCotation() != null) {
                if ($avenant->getCotation()->getPiste() != null) {
                    if ($avenant->getCotation()->getPiste()->getRisque() != null) {
                        return $avenant->getCotation()->getPiste()->getRisque()->getCode();
                    }
                }
            }
        }
        return "Non défini";
    }

    public function Avenant_getNomAccountManager(Avenant $avenant)
    {
        if ($avenant != null) {
            if ($avenant->getCotation() != null) {
                if ($avenant->getCotation()->getPiste() != null) {
                    if ($avenant->getCotation()->getPiste()->getInvite() != null) {
                        return $this->Utilisateur_getUtilisateurByEmail($avenant->getCotation()->getPiste()->getInvite()->getEmail());
                    }
                }
            }
        }
        return "Non défini";
    }

    public function Avenant_getPrimeTTC(Avenant $avenant): float
    {
        if ($avenant != null) {
            if ($avenant->getCotation() != null) {
                return $this->Cotation_getMontant_prime_payable_par_client($avenant->getCotation());
            }
        }
        return 0;
    }

    public function Avenant_getCommissionTTC(Avenant $avenant): float
    {
        // Wrapper pour l'affichage en liste, sans filtre.
        return $this->Avenant_getCommissionTTC_details($avenant, -1, false);
    }

    public function Avenant_getCommissionTTC_details(Avenant $avenant, int $addressedTo, bool $onlySharable): float
    {
        if ($avenant != null) {
            if ($avenant->getCotation() != null) {
                return $this->Cotation_getMontant_commission_ttc($avenant->getCotation(), $addressedTo, $onlySharable);
            }
        }
        return 0;
    }

    public function createRenewalReportSet(Avenant $avenant)
    {
        return (new RenewalReportSet())
            ->setType(RenewalReportSet::TYPE_ELEMENT)
            ->setIdAvenant($avenant->getId())
            ->setCurrency_code($this->serviceMonnaies->getCodeMonnaieAffichage())
            ->setEndorsement_id($avenant->getNumero())
            ->setLabel($avenant->getReferencePolice())
            ->setInsurer($this->Avenant_getNomAssureur($avenant))
            ->setClient($this->Avenant_getNomClient($avenant))
            ->setEndorsement($this->Avenant_getStringTypeAvenant($avenant))
            ->setCover($this->Avenant_getCodeCover($avenant))
            ->setAccount_manager($this->Avenant_getNomAccountManager($avenant))
            ->setGw_premium($this->Avenant_getPrimeTTC($avenant))
            ->setG_commission($this->Avenant_getCommissionTTC($avenant, -1, false))
            ->setEffect_date($avenant->getStartingAt())
            ->setExpiry_date($avenant->getEndingAt());
    }

    public function Entreprise_getDataTabRenewals()
    {
        $cumulPrime = 0;
        $cumulCom = 0;
        $tabReportSets = [];
        $tabAOrdonner = [];

        /** @var Avenant $avenant */
        foreach ($this->Entreprise_getAvenants() as $avenant) {
            //On n'affiche que les Avenant dont le remainingDays est inférieur ou égale à 60 jours.
            $remainingDays = $this->Avenant_getRenewalStatus($avenant)['remaining days'];
            if ($remainingDays <= 60) {
                $dataSet = $this->createRenewalReportSet($avenant);
                $cumulPrime += $this->Avenant_getPrimeTTC($avenant);
                $cumulCom += $this->Avenant_getCommissionTTC($avenant, -1, false);
                // dd($tache, $dataSet);
                $dataSet->setRemaining_days($remainingDays);
                $dataSet->setStatus($this->Avenant_getRenewalStatus($avenant)['text']);

                if ($remainingDays <= 30) {
                    $dataSet->setBg_color("text-danger");
                } else if ($remainingDays > 30 && $remainingDays <= 60) {
                    $dataSet->setBg_color("text-warning");
                } else {
                    $dataSet->setBg_color("text-success");
                }

                $tabReportSets[$avenant->getId()] = $dataSet;
                //on doit transformer la date en String afinque la fonction uasort de tri fasse bien son travail
                $tabAOrdonner[$avenant->getId()] = date_format($avenant->getEndingAt(), 'd/m/Y');
            }
        }


        /**
         * TRI PAR ORDRE CROISSANT PAR RAPPORT 
         * AU NB DE JOUR RESTANT AVANT EXPIRATION
         */
        // Trie le tableau associatif en utilisant la fonction de comparaison
        uasort($tabAOrdonner, function ($dateA, $dateB) {
            $date_a = DateTime::createFromFormat('d/m/Y', $dateA);
            $date_b = DateTime::createFromFormat('d/m/Y', $dateB);
            if ($date_a == $date_b) {
                return 0;
            }
            return ($date_a < $date_b) ? -1 : 1;
        });

        $tabFinaleOrdonne = [];
        foreach ($tabAOrdonner as $key => $value) {
            $tabFinaleOrdonne[] = $tabReportSets[$key];
        }

        //Après le tri on ajoute la Ligne de totale
        $dataSetTotal = (new RenewalReportSet())
            ->setType(RenewalReportSet::TYPE_TOTAL)
            ->setCurrency_code($this->serviceMonnaies->getCodeMonnaieAffichage())
            ->setLabel("TOTAL")
            ->setGw_premium($cumulPrime)
            ->setG_commission($cumulCom);
        $tabFinaleOrdonne[] = $dataSetTotal;

        // dd("ICI");
        return $tabFinaleOrdonne;
    }

    public function Tache_getExecutionStatus(Tache $tache)
    {
        $executionStatus = [
            'text' => "Default status",
            'code' => -1,
            'remaining days' => 0,
            'effect date' => null,
            'expiry date' => null,
        ];
        $executionStatus['effect date'] = $tache->getCreatedAt();
        $executionStatus['expiry date'] = $tache->getToBeEndedAt();
        $executionStatus['remaining days'] = $this->serviceDates->daysEntre(new DateTimeImmutable("now"), $tache->getToBeEndedAt());

        if ($tache->isClosed() == true) {
            $executionStatus['text'] = "Clôturée";
            $executionStatus['code'] = Tache::EXECUTION_STATUS_COMPLETED;
        } else {
            if ($executionStatus['remaining days'] >= 0) {
                $executionStatus['text'] = "Expire dans " . $executionStatus['remaining days'] . " jrs";
                $executionStatus['code'] = Tache::EXECUTION_STATUS_STILL_VALID;
            } else {
                $executionStatus['text'] = "Expiré il y a " . (-1 * $executionStatus['remaining days']) . " jrs";
                $executionStatus['code'] = Tache::EXECUTION_STATUS_EXPIRED;
            }
        }

        $statusDescription = match ($executionStatus['code']) {
            Tache::EXECUTION_STATUS_STILL_VALID => "En cours",
            Tache::EXECUTION_STATUS_EXPIRED => "Expirée",
            Tache::EXECUTION_STATUS_COMPLETED => "Accomplie",
        };

        $executionStatus['text'] = $statusDescription . ": " . $executionStatus['text'];

        // dd($executionStatus);
        return $executionStatus;
    }

    public function Claim_getClaimStatus(NotificationSinistre $notification): array
    {
        $status = [
            'code' => -1,
            'speed' => -1,
            'pastDays' => -1,
            'status' => -1,
            'texte' => "Blabla!!!",
            'limite' => 0,
            'primeTTC' => 0,
            'franchise' => 0,
            'compensationPaid' => 0,
            'compensationBalance' => 0,
            'settlementDernièreDate' => null,
            'dateDebutPolice' => null,
            'dateEchéancePolice' => null,
        ];

        foreach ($notification->getOffreIndemnisationSinistres() as $offreIndemnisation) {
            $status['limite'] += $offreIndemnisation->getMontantPayable();
            $status['franchise'] += $offreIndemnisation->getFranchiseAppliquee();
            $reglements = 0;
            foreach ($offreIndemnisation->getPaiements() as $paiement) {
                $reglements += $paiement->getMontant();
                $status['settlementDernièreDate'] = $paiement->getPaidAt();
            }
            $status['compensationPaid'] += $reglements;
            $status['compensationBalance'] += $offreIndemnisation->getMontantPayable() - $reglements;
        }

        if ($status['settlementDernièreDate'] != null && $notification->getNotifiedAt() != null) {
            $status['speed'] = $this->serviceDates->daysEntre($notification->getNotifiedAt(), $status['settlementDernièreDate']);
        }
        if ($notification->getNotifiedAt() != null) {
            $status['pastDays'] = $this->serviceDates->daysEntre($notification->getNotifiedAt(), new DateTimeImmutable("now"));
        }

        $statusPieces = $this->Notification_Sinistre_getStatusDocumentsAttendus($notification);
        $status['texte'] = "Pièces (" . count($statusPieces['Fournis']) . "/" . count($statusPieces['Attendus']) . ")"; //" . count($statusPieces['Docs_manquants']);

        //extraction d'informations sur la police d'assurance se basant sur la référence fournie
        $policyDetails = $this->Police_getPolicyArrayDetails($notification->getReferencePolice());
        // dd($policyDetails);
        $status['primeTTC'] = $policyDetails['primeTTC'];
        $status['dateDebutPolice'] = $policyDetails['effectDate'];
        $status['dateEchéancePolice'] = $policyDetails['expiryDate'];

        return $status;
    }

    public function Police_getPolicyArrayDetails(?string $referencePolice): array
    {
        $tabDetails = [
            'primeTTC' => 0,
            'effectDate' => null,
            'expiryDate' => null,
        ];

        if ($referencePolice != null) {
            $effectDates = [];
            $expiryDates = [];

            /** @var Avenant $avenant */
            foreach ($this->Entreprise_getAvenantsByReference($referencePolice) as $avenant) {
                $tabDetails['primeTTC'] += $this->Avenant_getPrimeTTC($avenant);
                $effectDates[] = $avenant->getStartingAt();
                $expiryDates[] = $avenant->getEndingAt();
            }
            if (count($expiryDates) == 1) {
                //On n'a trouvé qu'un seul avenant
                $tabDetails['effectDate'] = $effectDates[0];
                $tabDetails['expiryDate'] = $expiryDates[0];
            } else {
                //On ordonnes les dates d'effet
                usort($effectDates, function ($dateA, $dateB) {
                    if ($dateA == $dateB) {
                        return 0;
                    }
                    return ($dateA < $dateB) ? -1 : 1;
                });
                //On ordonnes les dates d'expiration
                usort($expiryDates, function ($dateA, $dateB) {
                    if ($dateA == $dateB) {
                        return 0;
                    }
                    return ($dateA > $dateB) ? -1 : 1;
                });

                //On récupère les dates
                if (count($effectDates) != 0) {
                    $tabDetails['effectDate'] = $effectDates[0];
                    $tabDetails['expiryDate'] = $expiryDates[0];
                }
            }
            // dd($effectDates, $expiryDates);
        }
        return $tabDetails;
    }



    public function createClaimReportSet($number, NotificationSinistre $notification): ClaimReportSet
    {
        $status = $this->Claim_getClaimStatus($notification);
        // dd($status);
        return (new ClaimReportSet())
            ->setType(ClaimReportSet::TYPE_ELEMENT)
            ->setIdNotification($notification->getId())
            ->setCurrency_code($this->serviceMonnaies->getCodeMonnaieAffichage())
            ->setNumber($number)
            ->setPolicy_reference($notification->getReferencePolice())
            ->setInsurer($notification->getAssureur()->getNom())
            ->setClient($notification->getAssure()->getNom())
            ->setCover($notification->getRisque()->getCode())
            ->setNotification_date($notification->getNotifiedAt())
            ->setDamage_cost($notification->getDommage())
            ->setClaim_reference($notification->getReferenceSinistre())
            ->setVictim(substr($notification->getDescriptionVictimes(), 0, 15) . "[..]")
            ->setCirconstance(substr($notification->getDescriptionDeFait(), 0, 15) . "[..]")
            ->setAccount_manager($this->Utilisateur_getUtilisateurByInvite($notification->getInvite()))
            ->setGw_premium($status['primeTTC'])
            ->setPolicy_limit($status['limite'])
            ->setPolicy_deductible($status['franchise'])
            ->setEffect_date($status['dateDebutPolice'])
            ->setExpiry_date($status['dateEchéancePolice'])
            ->setClaims_status($status['texte'])
            ->setCompensation_paid($status['compensationPaid'])
            ->setCompensation_balance($status['compensationBalance'])
            ->setSettlement_date($status['settlementDernièreDate'])
            ->setCompensation_speed($status['speed'] != -1 ? "Reglé en " . $status['speed'] . " jr." : "Pas encore reglé.")
            ->setDays_passed("Déclaré il y a " . $status['pastDays'] . " jr.")
            ->setBg_color("bg-secondary")
        ;
    }

    public function Entreprise_getDataTabClaims()
    {
        $number = 1;
        $cumulDamagrCost = 0;
        $cumulCompaPaid = 0;
        $cumulCompaBalance = 0;
        $tabReportSets = [];
        $tabAOrdonner = [];

        /** @var Avenant $avenant */
        foreach ($this->Entreprise_getClaimsNotifications() as $claimNotification) {
            //On n'affiche ici que ceux qui ne sont pas encore renouvellé
            $claimStatus = $this->Claim_getClaimStatus($claimNotification);
            // dd($claimStatus);
            if ($claimStatus['compensationBalance'] != 0) {
                $settlementSpeed = $claimStatus['speed'];
                if ($settlementSpeed != -1) {
                    $dataSet = $this->createClaimReportSet($number, $claimNotification);
                    $cumulDamagrCost += $dataSet->getDamage_cost();
                    $cumulCompaPaid += $dataSet->getCompensation_paid();
                    $cumulCompaBalance += $dataSet->getCompensation_balance();
                    // $tabReportSets[] = $dataSet;
                    $number++;

                    //Préparation des tableaux pour faire le tri
                    $tabReportSets[$claimNotification->getId()] = $dataSet;
                    // //on doit transformer la date en String afin que la fonction uasort de tri fasse bien son travail
                    $tabAOrdonner[$claimNotification->getId()] = date_format($claimNotification->getNotifiedAt(), 'd/m/Y');
                }
            }
        }
        /**
         * TRI PAR ORDRE CROISSANT PAR RAPPORT 
         * AU NB DE JOUR DEPUIS LA DATE DE NOTIFICATION DU SINISTRE
         */
        // Trie le tableau associatif en utilisant la fonction de comparaison
        uasort($tabAOrdonner, function ($dateA, $dateB) {
            $date_a = DateTime::createFromFormat('d/m/Y', $dateA);
            $date_b = DateTime::createFromFormat('d/m/Y', $dateB);
            if ($date_a == $date_b) {
                return 0;
            }
            return ($date_a < $date_b) ? -1 : 1;
        });

        $tabFinaleOrdonne = [];
        foreach ($tabAOrdonner as $key => $value) {
            $tabFinaleOrdonne[] = $tabReportSets[$key];
        }
        //Après le tri on ajoute la Ligne de totale
        $dataSetTotal = (new ClaimReportSet())
            ->setType(ClaimReportSet::TYPE_TOTAL)
            ->setCurrency_code($this->serviceMonnaies->getCodeMonnaieAffichage())
            ->setPolicy_reference("TOTAL")
            ->setDamage_cost($cumulDamagrCost)
            ->setCompensation_paid($cumulCompaPaid)
            ->setCompensation_balance($cumulCompaBalance);

        $tabFinaleOrdonne[] = $dataSetTotal;

        // dd($tabFinaleOrdonne);
        return $tabFinaleOrdonne;
    }

    public function createCashflowReportSet($index, Note $note): CashflowReportSet
    {
        $status = $this->Note_getNoteStatus($note);
        // dd($note);
        return (new CashflowReportSet())
            ->setIndex($index)
            ->setType(CashflowReportSet::TYPE_ELEMENT)
            ->setCurrency_code($this->serviceMonnaies->getCodeMonnaieAffichage())
            ->setDescription($note->getDescription())
            ->setDebtor($this->Note_getNameOfAddressedTo($note))
            ->setStatus($status['Texte'])
            ->setInvoice_reference($note->getReference())
            ->setGross_due($status['Total due'])
            ->setAmount_paid($status['Total paid'])
            ->setBalance_due($status['Total balance'])
            ->setUser($this->Utilisateur_getUtilisateurByInvite($note->getInvite()))
            ->setDate_submition($note->getSentAt())
            ->setDate_payment($status['Date dernier paiement']);
    }

    public function Note_getNoteStatus(Note $note)
    {
        $status = [
            "Date dernier paiement" => null,
            "Texte" => "RAS",
            "Total due" => 0,
            "Total paid" => 0,
            "Total balance" => 0,
        ];

        if ($note != null) {
            $totalDu = $this->Note_getMontant_payable($note);
            $totalPaye = $this->Note_getMontant_paye($note);
            $status['Total due'] = $totalDu;
            foreach ($note->getPaiements() as $paiement) {
                $status['Date dernier paiement'] = $paiement->getPaidAt();
                $status['Total paid'] += $paiement->getMontant();
            }
            if (($totalDu - $totalPaye) == 0) {
                $status['Texte'] = "Soldée";
            } else if ($totalPaye == 0) {
                $status['Texte'] =  "Impayée";
            } else {
                $status['Texte'] = "Payée en partie";
            }
        } else {
            $status['Texte'] = "Null";
        }
        $status['Total balance'] = $status['Total due'] - $status['Total paid'];
        // dd($status);
        return $status;
    }

    public function Entreprise_getNotes()
    {
        $tab = [];
        foreach ($this->getEnterprise()->getInvites() as $invite) {
            foreach ($invite->getNotes() as $note) {
                $tab[] = $note;
            }
        }
        return $tab;
    }

    public function Entreprise_getDataTabCashFlow()
    {
        $tabAOrdonner = [];
        $cumulGross = 0;
        $cumulPaid = 0;
        $cumulBalance = 0;
        $tabReportSets = [];
        $index = 1;
        foreach ($this->Entreprise_getNotes() as $note) {
            $dataSet = $this->createCashflowReportSet($index, $note);
            // dd($dataSet->getBalance_due());
            if ($dataSet->getBalance_due() > 0) {
                $days = $this->serviceDates->daysEntre(new DateTimeImmutable("now"), $dataSet->getDate_submition());
                if ($days == 0) {
                    $dataSet->setDays_passed($dataSet->getDate_submition() != null ? "Transmise à "  . $dataSet->getDebtor() . " aujourd'hui." : "Note non transmise.");
                } else {
                    if ($days < 0) {
                        $days = $days * -1;
                    }
                    $dataSet->setDays_passed($dataSet->getDate_submition() != null ? "Transmise à "  . $dataSet->getDebtor() . " il y a " . $days .  " jrs." : "Note non transmise.");
                }
                //On charge le tableau désordonné
                $tabReportSets[] = $dataSet;
                //On cumul
                $cumulGross += $dataSet->getGross_due();
                $cumulPaid += $dataSet->getAmount_paid();
                $cumulBalance += $dataSet->getGross_due() - $dataSet->getAmount_paid();
                //On incrémente le compteur
                $index++;

                //Préparation des tableaux pour faire le tri
                $tabReportSets[$note->getId()] = $dataSet;
                //on doit transformer la date en String afin que la fonction uasort de tri fasse bien son travail
                $tabAOrdonner[$note->getId()] = $days;
            }
        }

        arsort($tabAOrdonner); //tri décroissante = du plus grand au plus pétit.

        $tabFinaleOrdonne = [];
        foreach ($tabAOrdonner as $key => $value) {
            $tabFinaleOrdonne[] = $tabReportSets[$key];
        }
        //Ligne totale
        $dataSet = (new CashflowReportSet())
            ->setType(CashflowReportSet::TYPE_TOTAL)
            ->setCurrency_code($this->serviceMonnaies->getCodeMonnaieAffichage())
            ->setDescription("TOTAL")
            ->setGross_due($cumulGross)
            ->setAmount_paid($cumulPaid)
            ->setBalance_due($cumulBalance);

        $tabFinaleOrdonne[] = $dataSet;

        return $tabFinaleOrdonne;
    }
}