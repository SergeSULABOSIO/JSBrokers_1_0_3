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
use App\Entity\Cotation;
use App\Entity\Paiement;
use App\Entity\Chargement;
use App\Entity\Entreprise;
use App\Entity\Partenaire;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Entity\CompteBancaire;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use App\Entity\AutoriteFiscale;
use App\Entity\ConditionPartage;
use App\Services\ServiceMonnaies;
use PhpParser\Node\Expr\FuncCall;
use App\Entity\RevenuPourCourtier;
use App\Repository\NoteRepository;
use App\Repository\TaxeRepository;
use App\Entity\ChargementPourPrime;
use App\Entity\NotificationSinistre;
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
use Doctrine\Common\Collections\Collection;
use phpDocumentor\Reflection\Types\Integer;
use Symfony\Bundle\SecurityBundle\Security;
use App\Repository\AutoriteFiscaleRepository;
use App\Entity\ReportSet\Top20ClientReportSet;
use App\Repository\RevenuPourCourtierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use App\Controller\Admin\RevenuCourtierController;
use App\Entity\ReportSet\CashflowReportSet;
use Doctrine\ORM\Query\Expr\Func;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Notifier\Notification\Notification;


class Constante
{
    public function __construct(
        private TranslatorInterface $translator,
        private ServiceTaxes $serviceTaxes,
        private ServiceDates $serviceDates,
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
        // private ChargementsLoader $chargementsLoader,
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

    public function getFonctionMonnaie($codeMonnaie)
    {
        foreach ($this->getTabFonctionsMonnaies() as $fonction => $code) {
            if ($codeMonnaie == $code) {
                return $fonction;
            }
        }
        return "Null";
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





    /**
     * RISQUE ou COUVERTURE
     */
    public function Cotation_getRisque(?Cotation $cotation)
    {
        if ($cotation) {
            if ($cotation->getPiste()) {
                return $cotation->getPiste()->getRisque();
            }
        }
        return null;
    }






    /**
     * CLIENT
     */
    public function Cotation_getClient(?Cotation $cotation)
    {
        if ($cotation) {
            if ($cotation->getPiste()) {
                return $cotation->getPiste()->getClient();
            }
        }
        return null;
    }



    /**
     * PARTENAIRE
     */
    public function Cotation_getTrancheDiagnostic(?Cotation $cotation)
    {
        $totPourcTranches = 0;
        /**@var Tranche $tranche */
        foreach ($cotation->getTranches() as $tranche) {
            $totPourcTranches += $tranche->getPourcentage();
        }
        $txt = "";
        if ($totPourcTranches != 1) {
            $txt = '<span class="badge text-bg-danger m-2">La somme des tranches n\'est pas égale à 100% (il reste une tranche équivalant à ' . round(((1 - $totPourcTranches) * 100), 2) . '%)</span><br/>';
        }
        return $txt;
    }
    public function Piste_getTrancheDiagnostic(?Piste $piste)
    {
        /**@var Cotation $cotation */
        foreach ($piste->getCotations() as $cotation) {
            if ($this->Cotation_isBound($cotation)) {
                return $this->Cotation_getTrancheDiagnostic($cotation);
            }
        }
        return "";
    }
    public function Tranche_getPartenaire(?Tranche $tranche)
    {
        if ($tranche != null) {
            if ($tranche->getCotation() != null) {
                return $this->Cotation_getPartenaire($tranche->getCotation());
            }
        }
        return null;
    }
    public function Cotation_getPartenaire(?Cotation $cotation)
    {
        if ($cotation != null) {
            if ($cotation->getPiste() != null) {
                if (count($cotation->getPiste()->getPartenaires()) != 0) {
                    // dd($cotation->getPiste()->getPartenaires()[0]);
                    return $cotation->getPiste()->getPartenaires()[0];
                } else if (count($cotation->getPiste()->getClient()->getPartenaires()) != 0) {
                    return $cotation->getPiste()->getClient()->getPartenaires()[0];
                }
            }
        }
        return null;
    }
    public function Cotation_hasConditionsSpeciales(?Cotation $cotation): bool
    {
        if ($cotation) {
            if ($cotation->getPiste()) {
                if (count($cotation->getPiste()->getPartenaires()) >= 1) {
                    // dd($cotation->getPiste()->getPartenaires()[0]);
                    // dd($cotation->getPiste()->getConditionsPartageExceptionnelles()[0]);
                    return count($cotation->getPiste()->getConditionsPartageExceptionnelles()) != 0;
                }
            }
        }
        return false;
    }
    public function Tranche_hasConditionsSpeciales(?Tranche $tranche): bool
    {
        if ($tranche) {
            if ($tranche->getCotation()) {
                return $this->Cotation_hasConditionsSpeciales($tranche->getCotation());
            }
        }
        return false;
    }





    /**
     * GROUPE / SECTEUR D'ACTIVITE
     */
    public function Groupe_getPartenaires(?Groupe $groupe): ArrayCollection
    {
        $partenaires = new ArrayCollection();

        foreach ($groupe->getClients() as $client) {
            foreach ($this->Client_getPartenaires($client) as $partenaire) {
                if ($partenaires->contains($partenaire) == false && $partenaire != null) {
                    $partenaires->add($partenaire);
                }
            }
        }
        return $partenaires;
    }
    public function Groupe_getMontant_retrocommissions_payable_par_courtier(?Groupe $groupe, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_retrocommissions_payable_par_courtier($client, $partenaireCible, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Groupe_getMontant_retrocommissions_payable_par_courtier_payee(?Groupe $groupe, ?Partenaire $partenaireCible): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_retrocommissions_payable_par_courtier_payee($client, $partenaireCible);
            }
        }
        return $tot;
    }
    public function Groupe_getMontant_retrocommissions_payable_par_courtier_solde(?Groupe $groupe, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable): float
    {
        $tot = $this->Groupe_getMontant_retrocommissions_payable_par_courtier($groupe, $partenaireCible, $addressedTo, $onlySharable) - $this->Groupe_getMontant_retrocommissions_payable_par_courtier_payee($groupe, $partenaireCible);
        return round($tot, 4);
    }
    public function Groupe_getMontant_prime_payable_par_client(?Groupe $groupe): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_prime_payable_par_client($client);
            }
        }
        return $tot;
    }
    public function Groupe_getMontant_prime_payable_par_client_payee(?Groupe $groupe): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_prime_payable_par_client_payee($client);
            }
        }
        return $tot;
    }
    public function Groupe_getMontant_prime_payable_par_client_solde(?Groupe $groupe): float
    {
        $tot = $this->Groupe_getMontant_prime_payable_par_client($groupe) - $this->Groupe_getMontant_prime_payable_par_client_payee($groupe);
        return round($tot, 4);
    }
    public function Groupe_getMontant_commission_pure(?Groupe $groupe, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_commission_pure($client, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Groupe_getMontant_commission_ht(?Groupe $groupe, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_commission_ht($client, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Groupe_getMontant_commission_ttc(?Groupe $groupe, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_commission_ttc($client, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Groupe_getMontant_commission_ttc_collectee(?Groupe $groupe): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_commission_ttc_collectee($client);
            }
        }
        return $tot;
    }
    public function Groupe_getMontant_commission_ttc_solde(?Groupe $groupe, $addressedTo, bool $onlySharable): float
    {
        $tot = $this->Groupe_getMontant_commission_ttc($groupe, $addressedTo, $onlySharable) - $this->Groupe_getMontant_commission_ttc_collectee($groupe);
        return round($tot, 4);
    }
    public function Groupe_getMontant_taxe_payable_par_assureur(?Groupe $groupe, bool $onlySharable): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_taxe_payable_par_assureur($client, $onlySharable);
            }
        }
        return $tot;
    }
    public function Groupe_getMontant_taxe_payable_par_assureur_payee(?Groupe $groupe): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_taxe_payable_par_assureur_payee($client);
            }
        }
        return $tot;
    }
    public function Groupe_getMontant_taxe_payable_par_assureur_solde(?Groupe $groupe, bool $onlySharable): float
    {
        $tot = $this->Groupe_getMontant_taxe_payable_par_assureur($groupe, $onlySharable) - $this->Groupe_getMontant_taxe_payable_par_assureur_payee($groupe);
        return round($tot, 4);
    }
    public function Groupe_getMontant_taxe_payable_par_courtier(?Groupe $groupe, bool $onlySharable): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_taxe_payable_par_courtier($client, $onlySharable);
            }
        }
        return $tot;
    }
    public function Groupe_getMontant_taxe_payable_par_courtier_payee(?Groupe $groupe): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_taxe_payable_par_courtier_payee($client);
            }
        }
        return $tot;
    }
    public function Groupe_getMontant_taxe_payable_par_courtier_solde(?Groupe $groupe, bool $onlySharable): float
    {
        $tot = $this->Groupe_getMontant_taxe_payable_par_courtier($groupe, $onlySharable) - $this->Groupe_getMontant_taxe_payable_par_courtier_payee($groupe);
        return round($tot, 4);
    }



    /**
     * CLIENT
     */
    public function Client_getPartenaires(?Client $client)
    {
        $partenaires = new ArrayCollection();

        foreach ($client->getPistes() as $piste) {
            if ($this->Piste_isBound($piste)) {
                /** @var Partenaire $partenaire */
                $Tabpartenaires = $this->Piste_getPartenaires($piste);
                foreach ($Tabpartenaires as $partenaire) {
                    if ($partenaires->contains($partenaire) == false && $partenaire != null) {
                        $partenaires->add($partenaire);
                    }
                }
            }
        }
        return $partenaires;
    }
    public function Client_getMontant_retrocommissions_payable_par_courtier(?Client $client, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($client != null) {
            foreach ($client->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_retrocommissions_payable_par_courtier($piste, $partenaireCible, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Client_getMontant_prime_payable_par_client(?Client $client): float
    {
        $tot = 0;
        if ($client != null) {
            foreach ($client->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_prime_payable_par_client($piste);
            }
        }
        return $tot;
    }
    public function Client_getMontant_retrocommissions_payable_par_courtier_payee(?Client $client, ?Partenaire $partenaireCible = null): float
    {
        $tot = 0;
        if ($client != null) {
            foreach ($client->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_retrocommissions_payable_par_courtier_payee($piste, $partenaireCible);
            }
        }
        return $tot;
    }
    public function Client_getMontant_prime_payable_par_client_payee(?Client $client): float
    {
        $tot = 0;
        if ($client != null) {
            foreach ($client->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_prime_payable_par_client_payee($piste);
            }
        }
        return $tot;
    }
    public function Client_getMontant_prime_payable_par_client_solde(?Client $client): float
    {
        $tot = $this->Client_getMontant_prime_payable_par_client($client) - $this->Client_getMontant_prime_payable_par_client_payee($client);
        return round($tot, 4);
    }
    public function Client_getMontant_commission_pure(?Client $client, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($client != null) {
            foreach ($client->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_commission_pure($piste, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Client_getMontant_commission_ht(?Client $client, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($client != null) {
            foreach ($client->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_commission_ht($piste, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Client_getMontant_commission_ttc(?Client $client, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($client != null) {
            foreach ($client->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_commission_ttc($piste, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Client_getMontant_commission_ttc_collectee(?Client $client): float
    {
        $tot = 0;
        if ($client != null) {
            foreach ($client->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_commission_collectee($piste);
            }
        }
        return $tot;
    }
    public function Client_getMontant_commission_ttc_solde(?Client $client, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($client != null) {
            foreach ($client->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_commission_ttc_solde($piste, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Client_getMontant_taxe_payable_par_assureur(?Client $client, bool $onlySharable): float
    {
        $tot = 0;
        if ($client != null) {
            foreach ($client->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_taxe_payable_par_assureur($piste, $onlySharable);
            }
        }
        return $tot;
    }
    public function Client_getMontant_taxe_payable_par_assureur_payee(?Client $client): float
    {
        $tot = 0;
        if ($client != null) {
            foreach ($client->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_taxe_payable_par_assureur_payee($piste);
            }
        }
        return $tot;
    }
    public function Client_getMontant_taxe_payable_par_assureur_solde(?Client $client, bool $onlySharable): float
    {
        $tot = $this->Client_getMontant_taxe_payable_par_assureur($client, $onlySharable) - $this->Client_getMontant_taxe_payable_par_assureur_payee($client);
        return round($tot, 4);
    }
    public function Client_getMontant_taxe_payable_par_courtier(?Client $client, bool $onlySharable): float
    {
        $tot = 0;
        if ($client != null) {
            foreach ($client->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_taxe_payable_par_courtier($piste, $onlySharable);
            }
        }
        return $tot;
    }
    public function Client_getMontant_taxe_payable_par_courtier_payee(?Client $client): float
    {
        $tot = 0;
        if ($client != null) {
            foreach ($client->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_taxe_payable_par_courtier_payee($piste);
            }
        }
        return $tot;
    }
    public function Client_getMontant_taxe_payable_par_courtier_solde(?Client $client, bool $onlySharable): float
    {
        $tot = $this->Client_getMontant_taxe_payable_par_courtier($client, $onlySharable) - $this->Client_getMontant_taxe_payable_par_courtier_payee($client);
        return round($tot, 4);
    }
    public function Client_getMontant_retrocommissions_payable_par_courtier_solde(?Client $client, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable): float
    {
        $tot = $this->Client_getMontant_retrocommissions_payable_par_courtier($client, $partenaireCible, $addressedTo, $onlySharable) - $this->Client_getMontant_retrocommissions_payable_par_courtier_payee($client, $partenaireCible);
        return round($tot, 4);
    }







    /**
     * ASSUREUR
     */
    public function Assureur_getPartenaires(?Assureur $assureur)
    {
        $partenaires = new ArrayCollection();

        foreach ($assureur->getCotations() as $cotation) {
            if ($this->Cotation_isBound($cotation)) {
                /** @var Partenaire $partenaire */
                $partenaire = $this->Cotation_getPartenaire($cotation);
                if ($partenaires->contains($partenaire) == false && $partenaire != null) {
                    $partenaires->add($partenaire);
                }
            }
        }

        return $partenaires;
    }
    public function Cotation_getAssureur(?Cotation $cotation)
    {
        if ($cotation) {
            if ($cotation->getAssureur()) {
                return $cotation->getAssureur();
            }
        }
        return null;
    }
    public function Assureur_getMontant_prime_payable_par_client(?Assureur $assureur): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_prime_payable_par_client($cotation);
                }
            }
        }
        return $tot;
    }
    public function Assureur_getMontant_prime_payable_par_client_payee(?Assureur $assureur): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_prime_payable_par_client_payee($cotation);
                }
            }
        }
        return $tot;
    }
    public function Assureur_getMontant_prime_payable_par_client_solde(?Assureur $assureur): float
    {
        $tot = $this->Assureur_getMontant_prime_payable_par_client($assureur) - $this->Assureur_getMontant_prime_payable_par_client_payee($assureur);
        return round($tot, 4);
    }
    public function Assureur_getMontant_commission_pure(?Assureur $assureur, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_commission_pure($cotation, $addressedTo, $onlySharable);
                }
            }
        }
        return $tot;
    }
    public function Assureur_getMontant_commission_ht(?Assureur $assureur, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_commission_ht($cotation, $addressedTo, $onlySharable);
                }
            }
        }
        return $tot;
    }
    public function Assureur_getMontant_commission_ttc(?Assureur $assureur, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_commission_ttc($cotation, $addressedTo, $onlySharable);
                }
            }
        }
        return $tot;
    }
    public function Assureur_getMontant_commission_ttc_collectee(?Assureur $assureur): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_commission_ttc_collectee($cotation);
                }
            }
        }
        return $tot;
    }
    public function Assureur_getMontant_commission_ttc_solde(?Assureur $assureur, $addressedTo, bool $onlySharable): float
    {
        $tot = $this->Assureur_getMontant_commission_ttc($assureur, $addressedTo, $onlySharable) - $this->Assureur_getMontant_commission_ttc_collectee($assureur);
        return round($tot, 4);
    }
    public function Assureur_getMontant_taxe_payable_par_assureur(?Assureur $assureur, bool $onlySharable): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_taxe_payable_par_assureur($cotation, $onlySharable);
                }
            }
        }
        return $tot;
    }
    public function Assureur_getMontant_taxe_payable_par_assureur_payee(?Assureur $assureur): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_taxe_payable_par_assureur_payee($cotation);
                }
            }
        }
        return $tot;
    }
    public function Assureur_getMontant_taxe_payable_par_assureur_solde(?Assureur $assureur, bool $onlySharable): float
    {
        $tot = $this->Assureur_getMontant_taxe_payable_par_assureur($assureur, $onlySharable) - $this->Assureur_getMontant_taxe_payable_par_assureur_payee($assureur);
        return round($tot, 4);
    }
    public function Assureur_getMontant_taxe_payable_par_courtier(?Assureur $assureur, bool $onlySharable): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_taxe_payable_par_courtier($cotation, $onlySharable);
                }
            }
        }
        return $tot;
    }
    public function Assureur_getMontant_taxe_payable_par_courtier_payee(?Assureur $assureur): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_taxe_payable_par_courtier_payee($cotation);
                }
            }
        }
        return $tot;
    }
    public function Assureur_getMontant_taxe_payable_par_courtier_solde(?Assureur $assureur, bool $onlySharable): float
    {
        $tot = $this->Assureur_getMontant_taxe_payable_par_courtier($assureur, $onlySharable) - $this->Assureur_getMontant_taxe_payable_par_courtier_payee($assureur);
        return round($tot, 4);
    }
    public function Assureur_getMontant_retrocommissions_payable_par_courtier(?Assureur $assureur, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_retrocommissions_payable_par_courtier($cotation, $partenaireCible, $addressedTo, $onlySharable);
                }
            }
        }
        return $tot;
    }
    public function Assureur_getMontant_retrocommissions_payable_par_courtier_payee(?Assureur $assureur, ?Partenaire $partenaireCible = null): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_retrocommissions_payable_par_courtier_payee($cotation, $partenaireCible);
                }
            }
        }
        return $tot;
    }
    public function Assureur_getMontant_retrocommissions_payable_par_courtier_solde(?Assureur $assureur, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable): float
    {
        $tot = $this->Assureur_getMontant_retrocommissions_payable_par_courtier($assureur, $partenaireCible, $addressedTo, $onlySharable) - $this->Assureur_getMontant_retrocommissions_payable_par_courtier_payee($assureur, $partenaireCible);
        return round($tot, 4);
    }





    /**
     * NOTE - NOTE DE DEBIT OU NOTE DE CREDIT
     */
    public function Note_getMontant_ht(?Note $note, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                if ($note->getAddressedTo() == Note::TO_ASSUREUR || $note->getAddressedTo() == Note::TO_CLIENT) {
                    // $montant += $this->Tranche_getMontant_commission_ht($article->getTranche());
                    $montant += $this->ARTICLE_getComHT($article, $addressedTo, $onlySharable);
                } else if ($note->getAddressedTo() == Note::TO_AUTORITE_FISCALE) {
                    $montant += $this->ARTICLE_getComHT($article, $addressedTo, $onlySharable);
                } else if ($note->getAddressedTo() == Note::TO_PARTENAIRE) {
                    $montant += $this->ARTICLE_getComHT($article, $addressedTo, $onlySharable);
                }
            }
        }
        // dd("ici", $montant);
        return $montant;
    }
    public function Note_getMontant_taxes(?Note $note, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                if ($note->getAddressedTo() == Note::TO_ASSUREUR || $note->getAddressedTo() == Note::TO_CLIENT) {
                    // $montant += $this->Tranche_getMontant_taxe_payable_par_assureur($article->getTranche());
                    $montant += $this->ARTICLE_getTaxeAssureur($article, $addressedTo, $onlySharable);
                } else if ($note->getAddressedTo() == Note::TO_AUTORITE_FISCALE) {
                    // $montant += $this->Tranche_getMontant_taxe_payable_par_assureur($article->getTranche());
                    $montant += $this->ARTICLE_getMontantTaxeFacturee($article, $onlySharable);
                } else if ($note->getAddressedTo() == Note::TO_PARTENAIRE) {
                    $montant += $this->ARTICLE_getTaxeCourtier($article, $addressedTo, $onlySharable);
                }
            }
        }
        return $montant;
    }
    public function Note_getNames_taxes_assureur()
    {
        $nomsTaxesAssureurs = "";
        $multiple = count($this->serviceTaxes->getTaxesPayableParAssureur()) > 1 ? true : false;
        // dd($multiple);
        foreach ($this->serviceTaxes->getTaxesPayableParAssureur() as $taxe) {
            $strTaux = "";
            if ($taxe->getTauxIARD() == $taxe->getTauxVIE()) {
                $strTaux = " (" . ($taxe->getTauxIARD() * 100) . "%)";
            }
            $nomsTaxesAssureurs .= "" . $taxe->getCode() . $strTaux;
            if ($multiple == true) {
                $nomsTaxesAssureurs .= ", ";
            }
        }
        return $nomsTaxesAssureurs;
    }

    public function Note_getNames_taxes_courtier()
    {
        $nomsTaxesCourtiers = "";
        $multiple = count($this->serviceTaxes->getTaxesPayableParCourtier()) > 1 ? true : false;
        // dd($multiple);
        foreach ($this->serviceTaxes->getTaxesPayableParCourtier() as $taxe) {
            $strTaux = "";
            if ($taxe->getTauxIARD() == $taxe->getTauxVIE()) {
                $strTaux = " (" . ($taxe->getTauxIARD() * 100) . "%)";
            }
            $nomsTaxesCourtiers .= "" . $taxe->getCode() . $strTaux;
            if ($multiple == true) {
                $nomsTaxesCourtiers .= ", ";
            }
        }
        return strtoupper($nomsTaxesCourtiers);
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
    public function Note_getMontant_commissions_ht(?Note $note, $addressedTo, bool $onlySharable)
    {
        $com_ht = 0;
        if ($note != null) {
            /** @var Article $article */
            foreach ($note->getArticles() as $article) {
                $com_ht += $this->Tranche_getMontant_commission_ht($article->getTranche(), $addressedTo, $onlySharable);
                // dd($article);
            }
        }
        // dd($com_ht);
        return round($com_ht, 2);
    }

    public function Note_getMontant_commission_assiette(?Note $note)
    {
        $com_ht = 0;
        if ($note != null) {
            /** @var Article $article */
            foreach ($note->getArticles() as $article) {
                $com_ht += $this->Tranche_getMontant_commission_pure($article->getTranche(), -1, true);
                // dd($article);
            }
        }
        // dd($com_ht);
        return round($com_ht, 2);
    }

    public function Note_getMontant_taxe_facturee(?Note $note, $addressedTo, bool $onlySharable)
    {
        $montantTaxe = 0;
        /** @var Taxe $taxe */
        $taxe = $this->Note_getTaxeFacturee($note);
        if ($note != null && $taxe != null) {
            /** @var Article $article */
            foreach ($note->getArticles() as $article) {
                $montantTaxe += match ($taxe->getRedevable()) {
                    Taxe::REDEVABLE_ASSUREUR => $this->Tranche_getMontant_taxe_payable_par_assureur($article->getTranche(), $onlySharable),
                    Taxe::REDEVABLE_COURTIER => $this->Tranche_getMontant_taxe_payable_par_courtier($article->getTranche(), $onlySharable),
                };
            }
        }
        return round($montantTaxe, 2);
    }

    public function Note_toStringTaxeFacturee(?Note $note)
    {
        /** @var Taxe $taxe */
        $taxe = $this->Note_getTaxeFacturee($note);
        if ($taxe != null) {
            $txt = "";
            if ($taxe->getTauxIARD() == $taxe->getTauxVIE()) {
                $txt = $taxe->getCode() . " (" . ($taxe->getTauxIARD() * 100) . "%)";
            } else {
                $txt = $taxe->getCode() . " (Iard@" . ($taxe->getTauxIARD() * 100) . "%) & (Vie@" . ($taxe->getTauxVIE() * 100) . "%)";
            }
            return strtoupper($txt);
        } else {
            return null;
        }
    }

    public function Note_getMontant_Retrocom(?Note $note, $addressedTo, bool $onlySharable)
    {
        $montantRetrocom = 0;
        /** @var Partenaire $partenaire */
        $partenaire = $this->Note_getPartenaireFacture($note);
        if ($note != null && $partenaire != null) {
            /** @var Article $article */
            foreach ($note->getArticles() as $article) {
                $montantRetrocom += $this->Tranche_getMontant_retrocommissions_payable_par_courtier($article->getTranche(), $partenaire, $addressedTo, $onlySharable);
            }
        }
        return round($montantRetrocom, 2);
    }

    public function Note_toStringPartenaireFacture(?Note $note)
    {
        /** @var Partenaire $partenaire */
        $partenaire = $this->Note_getPartenaireFacture($note);
        if ($partenaire != null) {
            $txt = "Rétrocom. " . $partenaire->getNom() . " " . ($partenaire->getPart() != 0 ? "(" . ($partenaire->getPart() * 100) . "%)" : "");
            return $txt;
        } else {
            return null;
        }
    }

    public function Note_getTaxeFacturee(?Note $note): Taxe
    {
        /** @var Taxe $taxe */
        $taxe = null;
        if ($note->getAddressedTo() == note::TO_AUTORITE_FISCALE) {
            /** @var Article $article */
            foreach ($note->getArticles() as $article) {
                $taxe = $this->taxeRepository->find($article->getIdPoste());
                // dd($article, $taxe);
                break;
            }
        }
        return $taxe;
    }

    public function Note_getAssiette(?Note $note)
    {
        $assiette = 0;
        if ($note->getAddressedTo() == note::TO_PARTENAIRE) {
            /** @var Article $article */
            foreach ($note->getArticles() as $article) {
                $assiette += $this->ARTICLE_getAssiette($article, -1, true);
            }
        }
        return round($assiette, 2);
    }

    public function Note_getPartenaireFacture(?Note $note): Partenaire
    {
        /** @var Partenaire $partenaire */
        $partenaire = null;
        if ($note->getAddressedTo() == note::TO_PARTENAIRE) {
            $partenaire = $note->getPartenaire();
        }
        return $partenaire;
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
    public function Note_getMontant_fronting(?Note $note): float
    {
        $tabCotation = new ArrayCollection();
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                // $montant += $this->ARTICLE_getFronting($article);
                if (!$tabCotation->contains($article->getTranche()->getCotation())) {
                    $tabCotation->add($article->getTranche()->getCotation());
                }
            }
            /** @var Cotation $cotation */
            foreach ($tabCotation as $cotation) {
                foreach ($cotation->getChargements() as $chargement) {
                    if ($chargement->getType()->getFonction() == Chargement::FONCTION_FRONTING) {
                        // dd($chargement->getMontantFlatExceptionel());
                        $montant += $chargement->getMontantFlatExceptionel();
                    }
                }
            }
        }
        return $montant;
    }
    public function Note_getMontant_prime_ht(?Note $note): float
    {
        $tabCotation = new ArrayCollection();
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                // $montant += $this->ARTICLE_getPrimeHT($article);
                if (!$tabCotation->contains($article->getTranche()->getCotation())) {
                    $tabCotation->add($article->getTranche()->getCotation());
                }
            }
            /** @var Cotation $cotation */
            foreach ($tabCotation as $cotation) {
                foreach ($cotation->getChargements() as $chargement) {
                    if ($chargement->getType()->getFonction() == Chargement::FONCTION_PRIME_NETTE) {
                        // dd($chargement->getMontantFlatExceptionel());
                        $montant += $chargement->getMontantFlatExceptionel();
                    }
                }
            }
        }
        return $montant;
    }
    public function Note_getMontant_prime_ttc(?Note $note): float
    {
        $tabTranches = new ArrayCollection();
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                // $montant += $this->ARTICLE_getPrimeTTC($article);
                if (!$tabTranches->contains($article->getTranche())) {
                    $tabTranches->add($article->getTranche());
                }
            }
            foreach ($tabTranches as $tranche) {
                $montant += $this->Tranche_getMontant_prime_payable_par_client($tranche);
                # code...
            }
        }
        return $montant;
    }





    /**
     * REVENU POUR COURTIER
     */
    public function Type_revenu_getPartenaires(?TypeRevenu $typeRevenu)
    {
        $partenaires = new ArrayCollection();
        if ($typeRevenu) {
            if (count($typeRevenu->getRevenuPourCourtiers())) {
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    if ($revenu->getCotation() != null) {
                        /** @var Cotation $cotation */
                        $cotation = $this->Cotation_getPartenaire($revenu->getCotation());
                        if ($partenaires->contains($cotation) == false) {
                            $partenaires[] = $this->Cotation_getPartenaire($revenu->getCotation());
                        }
                    }
                }
            }
        }
        // dd($partenaires);
        return $partenaires;
    }
    public function Type_revenu_getMontant_retrocommissions_payable_par_courtier(?TypeRevenu $typeRevenu, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable)
    {
        $assietteMicro = 0;
        $assiette100 = 0;
        $retro100 = 0;
        $tabCotations = new ArrayCollection();
        /** @var RevenuPourCourtier $revenu */
        foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
            if (!$tabCotations->contains($revenu->getCotation()) && $this->Cotation_isBound($revenu->getCotation())) {
                $tabCotations->add($revenu->getCotation());
                $retro100 += $this->Cotation_getMontant_retrocommissions_payable_par_courtier($revenu->getCotation(), $partenaireCible, $addressedTo, $onlySharable);
                $assiette100 += $this->Cotation_getMontant_commission_pure($revenu->getCotation(), $addressedTo, $onlySharable);
            }
            $assietteMicro += $this->Revenu_getMontant_pure($revenu, $addressedTo, $onlySharable);
        }
        if ($assiette100 != 0) {
            $tauxMicro = $retro100 / $assiette100;
            $retroMicro = $tauxMicro * $assietteMicro;
        }

        // dd($onlySharable, "Assiette 100%: " . $assiette100, "Retro 100%: " . $retro100, "Taux micro: " . $tauxMicro, "Assiette Micro: " . $assietteMicro, "Retro Micro: " . $retroMicro);
        return $retroMicro;
    }
    public function Type_revenu_getMontant_retrocommissions_payable_par_courtier_payee(?TypeRevenu $typeRevenu, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable)
    {
        $tot = 0;
        if ($typeRevenu != null) {
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                if ($onlySharable == true) {
                    if ($typeRevenu->isShared() == true) {
                        $tot += $this->loadRetrocomPaid($typeRevenu, $partenaireCible, $addressedTo, $onlySharable);
                    }
                } else {
                    $tot += $this->loadRetrocomPaid($typeRevenu, $partenaireCible, $addressedTo, $onlySharable);
                }
            }
        }

        return $tot;
    }
    private function loadRetrocomPaid(?TypeRevenu $typeRevenu, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable)
    {
        $tot = 0;
        /** @var RevenuPourCourtier $revenu */
        foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
            $tot += $this->Revenu_getMontant_retrocommissions_payable_par_courtier_payee($revenu, $partenaireCible, $addressedTo, $onlySharable);
        }
        return $tot;
    }
    public function Type_revenu_getMontant_retrocommissions_payable_par_courtier_solde(?TypeRevenu $typeRevenu, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable)
    {
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_retrocommissions_payable_par_courtier_solde($revenu, $partenaireCible, $addressedTo, $onlySharable);
                }
            }
        }
        return $tot;
    }
    public function Type_revenu_getMontant_pure(?TypeRevenu $typeRevenu, $addressedTo, bool $onlySharable): float
    {
        // dd($typeRevenu->isShared(), $typeRevenu);
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_pure($revenu, $addressedTo, $onlySharable);
                }
            }
        }
        return $tot;
    }
    public function Type_revenu_getMontant_ht(?TypeRevenu $typeRevenu): float
    {
        // dd($typeRevenu);
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_ht($revenu);
                }
            }
        }
        return $tot;
    }
    public function Type_revenu_getMontant_ttc(?TypeRevenu $typeRevenu): float
    {
        // dd($typeRevenu);
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_ttc($revenu);
                }
            }
        }
        return $tot;
    }
    public function Type_revenu_getMontant_ttc_collecte(?TypeRevenu $typeRevenu): float
    {
        // dd($typeRevenu);
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_ttc_collecte($revenu);
                }
            }
        }
        return $tot;
    }
    public function Type_revenu_getMontant_ttc_solde(?TypeRevenu $typeRevenu): float
    {
        // dd($typeRevenu);
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_ttc_solde($revenu);
                }
            }
        }
        return $tot;
    }
    public function Type_revenu_getMontant_taxe_payable_par_assureur(?TypeRevenu $typeRevenu): float
    {
        // dd($typeRevenu);
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_taxe_payable_par_assureur($revenu);
                }
            }
        }
        return $tot;
    }
    public function Type_revenu_getMontant_taxe_payable_par_assureur_payee(?TypeRevenu $typeRevenu): float
    {
        // dd($typeRevenu);
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $taxeRevPayee = $this->Revenu_getMontant_taxe_payable_par_assureur_payee($revenu);
                    // dd($revenu, $taxeRevPayee);
                    $tot += $taxeRevPayee;
                }
            }
        }
        return $tot;
    }
    public function Type_revenu_getMontant_taxe_payable_par_assureur_solde(?TypeRevenu $typeRevenu): float
    {
        // dd($typeRevenu);
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_taxe_payable_par_assureur_solde($revenu);
                }
            }
        }
        return $tot;
    }
    public function Type_revenu_getMontant_taxe_payable_par_courtier(?TypeRevenu $typeRevenu): float
    {
        // dd($typeRevenu);
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_taxe_payable_par_courtier($revenu);
                }
            }
        }
        return $tot;
    }
    public function Type_revenu_getMontant_taxe_payable_par_courtier_payee(?TypeRevenu $typeRevenu): float
    {
        // dd($typeRevenu);
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_taxe_payable_par_courtier_payee($revenu);
                }
            }
        }
        return $tot;
    }
    public function Type_revenu_getMontant_taxe_payable_par_courtier_solde(?TypeRevenu $typeRevenu): float
    {
        // dd($typeRevenu);
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_taxe_payable_par_courtier_solde($revenu);
                }
            }
        }
        return $tot;
    }
    public function Revenu_getNomRedevable(?TypeRevenu $typeRevenu)
    {
        return match ($typeRevenu->getRedevable()) {
            TypeRevenu::REDEVABLE_ASSUREUR => "Redevable par l'assureur",
            TypeRevenu::REDEVABLE_CLIENT => "Redevable par le client",
            TypeRevenu::REDEVABLE_PARTENAIRE => "Redevable par le partenaire",
            TypeRevenu::REDEVABLE_REASSURER => "Redevable par le réassureur",
        };
    }
    public function Revenu_getMontant_ttc_tranche(?RevenuPourCourtier $revenu, ?Tranche $tranche): float
    {
        if ($tranche != null && $revenu != null) {
            return $this->Revenu_getMontant_ttc($revenu) * $tranche->getPourcentage();
        } else {
            return 0;
        }
    }
    public function Revenu_getMontant_ttc(?RevenuPourCourtier $revenu): float
    {
        $net = $this->Revenu_getMontant_ht($revenu);
        $taxe = $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($revenu->getCotation()), true);
        return $net + $taxe;
    }
    public function Revenu_getNomPayable(?RevenuPourCourtier $revenu): string
    {
        if ($revenu != null) {
            if ($revenu->getTypeRevenu() != null) {
                return match ($revenu->getTypeRevenu()->getRedevable()) {
                    TypeRevenu::REDEVABLE_ASSUREUR => $revenu->getCotation()->getAssureur()->getNom(),
                    TypeRevenu::REDEVABLE_CLIENT => $revenu->getCotation()->getPiste()->getClient()->getNom(),
                };
            }
        }
        return "Indéfini";
    }
    public function Revenu_getMontant_ttc_collecte(?RevenuPourCourtier $revenu): float
    {
        $montantCollecte = 0;
        $allNotesComDueByInsurerAndClient = $this->noteRepository->findAllNotesDueByInsurerAndClient($revenu);
        /** @var Note $note */
        foreach ($allNotesComDueByInsurerAndClient as $note) {
            /** @var Article $article */
            foreach ($note->getArticles() as $article) {
                if ($article->getIdPoste() == $revenu->getId()) {
                    $proportionPaiement = $this->Note_getMontant_paye($note) / $this->Note_getMontant_payable($note);
                    $montantCollecte += $proportionPaiement * $article->getMontant();
                    // dd("Montant facturé: " . $montantFacture, "Montant collectée: " . $montantCollecte, $article, $note);
                }
            }
        }
        return $montantCollecte;
    }
    public function Revenu_getMontant_ttc_solde(?RevenuPourCourtier $revenu): float
    {
        $solde = 0;
        if ($this->Cotation_isBound($revenu->getCotation())) {
            $solde = $this->Revenu_getMontant_ttc($revenu) - $this->Revenu_getMontant_ttc_collecte($revenu);
        }
        return round($solde, 4);
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
                $taxeCourtier = $this->serviceTaxes->getMontantTaxe($comNette, $isIARD, $taxeAssureur);
                $commissionPure = $comNette - $taxeCourtier;
            }
        } else {
            $comNette = $this->Revenu_getMontant_ht($revenu);
            $taxeCourtier = $this->serviceTaxes->getMontantTaxe($comNette, $isIARD, $taxeAssureur);
            $commissionPure = $comNette - $taxeCourtier;
        }
        return $commissionPure;
    }
    public function Revenu_getMontant_taxe_payable_par_assureur(?RevenuPourCourtier $revenu): float
    {
        $netRev = $this->Revenu_getMontant_ht($revenu);
        return $this->serviceTaxes->getMontantTaxe($netRev, $this->isIARD($revenu->getCotation()), true);
    }
    public function Revenu_getMontant_taxe_payable_par_courtier(?RevenuPourCourtier $revenu): float
    {
        $netRev = $this->Revenu_getMontant_ht($revenu);
        return $this->serviceTaxes->getMontantTaxe($netRev, $this->isIARD($revenu->getCotation()), false);
    }
    public function Revenu_getMontant_taxe_payable_par_assureur_payee(RevenuPourCourtier $revenu): float
    {
        /** @var Cotation $cotation */
        $cotation = $revenu->getCotation();
        $txAssRevPaye = 0;
        $txAssRevDue = 0;
        $prop = 0;
        if ($revenu != null) {
            if ($cotation != null) {
                if ($this->Cotation_isBound($cotation) == true) {
                    $txAssCotDue = $this->Cotation_getMontant_taxe_payable_par_assureur($revenu->getCotation(), false);
                    // dd("ici", $txAssCotDue);
                    $txAssCotPaye = $this->Cotation_getMontant_taxe_payable_par_assureur_payee($cotation);
                    // dd("Txt100: " . $txAssCotDue, "Txt100 paid: " . $txAssCotPaye);

                    $txAssRevDue = $this->Revenu_getMontant_taxe_payable_par_assureur($revenu);
                    $txAssRevPaye = $txAssRevDue * ($txAssCotPaye / $txAssCotDue);
                    $prop = $txAssRevPaye / $txAssRevDue;
                }
            }
        }
        // dd('Ici');
        // dd("Taxe due: " . $txAssRevDue, "Taxe payée: " . $txAssRevPaye, "Prop: " . $prop);
        return $this->Revenu_getMontant_taxe_payable_par_assureur($revenu) * $prop;
    }
    public function Revenu_getMontant_taxe_payable_par_courtier_payee(?RevenuPourCourtier $revenu): float
    {
        /** @var Cotation $cotation */
        $cotation = $revenu->getCotation();
        $txAssCotPaye = 0;
        $txAssCotDue = 0;
        $prop = 0;
        if ($revenu != null) {
            if ($cotation != null) {
                if ($this->Cotation_isBound($cotation) == true) {
                    $txAssCotDue = $this->Cotation_getMontant_taxe_payable_par_courtier($cotation, false);
                    $txAssCotPaye = $this->Cotation_getMontant_taxe_payable_par_courtier_payee($cotation);
                    $prop = $txAssCotPaye / $txAssCotDue;
                }
            }
        }
        // dd("Tx de ". $revenu->getNom() .": " . $txAssRevDue, "Tx due: " . $txAssCotDue, "Tx payée: " . $txAssCotPaye, "Prop: " . $prop);
        return $this->Revenu_getMontant_taxe_payable_par_courtier($revenu) * $prop;
    }
    public function Revenu_getMontant_taxe_payable_par_assureur_solde(?RevenuPourCourtier $revenu)
    {
        $solde = $this->Revenu_getMontant_taxe_payable_par_assureur($revenu) - $this->Revenu_getMontant_taxe_payable_par_assureur_payee($revenu);
        return round($solde, 4);
    }
    public function Revenu_getMontant_taxe_payable_par_courtier_solde(?RevenuPourCourtier $revenu)
    {
        $solde = $this->Revenu_getMontant_taxe_payable_par_courtier($revenu) - $this->Revenu_getMontant_taxe_payable_par_courtier_payee($revenu);
        return round($solde, 4);
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
                                        $retrocom = $assiette * $partenaire->getPart();
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
    public function Revenu_getMontant_retrocommissions_payable_par_courtier_payee(?RevenuPourCourtier $revenu, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable): float
    {
        $retCotPaye = 0;
        $retCotDue = 0;
        $prop = 0;
        if ($revenu != null) {
            /** @var Cotation $cotation */
            $cotation = $revenu->getCotation();
            if ($cotation != null) {
                if ($this->Cotation_isBound($cotation) == true) {
                    $retCotDue = $this->Cotation_getMontant_retrocommissions_payable_par_courtier($cotation, $partenaireCible, $addressedTo, $onlySharable);
                    $retCotPaye = $this->Cotation_getMontant_retrocommissions_payable_par_courtier_payee($cotation, $partenaireCible);

                    if ($retCotDue != 0) {
                        $prop = $retCotPaye / $retCotDue;
                    }

                    // try {
                    //     $prop = $retCotPaye / $retCotDue;
                    // } catch (\Throwable $th) {
                    //     //throw $th;
                    //     dd($th, $retCotDue, $retCotPaye);
                    // }
                }
            }
        }
        // dd("Retro due: " . $retCotDue, "Retro payee: " . $retCotPaye, "Prop: " . $prop);
        return $this->Revenu_getMontant_retrocommissions_payable_par_courtier($revenu, $partenaireCible, $addressedTo, $onlySharable) * $prop;
    }
    public function Revenu_getMontant_retrocommissions_payable_par_courtier_solde(?RevenuPourCourtier $revenu, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable)
    {
        $solde = $this->Revenu_getMontant_retrocommissions_payable_par_courtier($revenu, $partenaireCible, $addressedTo, $onlySharable) - $this->Revenu_getMontant_retrocommissions_payable_par_courtier_payee($revenu, $partenaireCible, $addressedTo, $onlySharable);
        return round($solde, 4);
    }



    /**
     * RISQUE
     */
    public function Risque_getPartenaires(?Risque $risque): ArrayCollection
    {
        $Tabpartenaires = new ArrayCollection();
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                /** @var Partenaire $partenaire */
                foreach ($this->Piste_getPartenaires($piste) as $partenaire) {
                    if (!$Tabpartenaires->contains($partenaire)) {
                        $Tabpartenaires->add($partenaire);
                    }
                }
            }
        }
        // dd($Tabpartenaires);
        return $Tabpartenaires;
    }
    public function Risque_getNomBranche($branche)
    {
        return match ($branche) {
            Risque::BRANCHE_IARD_OU_NON_VIE => "IARD",
            Risque::BRANCHE_VIE => "VIE",
        };
    }
    public function Risque_getNomImposable($branche)
    {
        return match ($branche) {
            true => "Imposable",
            false => "Non Imposable",
        };
    }
    public function Risque_getMontant_prime_payable_par_client(?Risque $risque)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_prime_payable_par_client($piste);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_prime_payable_par_client_payee(?Risque $risque)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_prime_payable_par_client_payee($piste);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_prime_payable_par_client_solde(?Risque $risque)
    {
        $tot = $this->Risque_getMontant_prime_payable_par_client($risque) - $this->Risque_getMontant_prime_payable_par_client_payee($risque);
        return round($tot, 4);
    }
    public function Risque_getMontant_commission_pure(?Risque $risque, $addressedTo, bool $onlySharable)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_commission_pure($piste, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_commission_ht(?Risque $risque, $addressedTo, bool $onlySharable)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_commission_ht($piste, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_commission_ttc(?Risque $risque, $addressedTo, bool $onlySharable)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_commission_ttc($piste, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_commission_collectee(?Risque $risque)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_commission_collectee($piste);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_commission_ttc_solde(?Risque $risque, $addressedTo, bool $onlySharable)
    {
        $tot = $this->Risque_getMontant_commission_ttc($risque, $addressedTo, $onlySharable) - $this->Risque_getMontant_commission_collectee($risque);
        return round($tot, 4);
    }
    public function Risque_getMontant_taxe_payable_par_assureur(?Risque $risque, bool $onlySharable)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_taxe_payable_par_assureur($piste, $onlySharable);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_taxe_payable_par_assureur_payee(?Risque $risque)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_taxe_payable_par_assureur_payee($piste);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_taxe_payable_par_courtier(?Risque $risque, bool $onlySharable)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_taxe_payable_par_courtier($piste, $onlySharable);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_taxe_payable_par_courtier_payee(?Risque $risque)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_taxe_payable_par_courtier_payee($piste);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_taxe_payable_par_assureur_solde(?Risque $risque, bool $onlySharable)
    {
        $tot = $this->Risque_getMontant_taxe_payable_par_assureur($risque, $onlySharable) - $this->Risque_getMontant_taxe_payable_par_assureur_payee($risque);
        return round($tot, 4);
    }
    public function Risque_getMontant_taxe_payable_par_courtier_solde(?Risque $risque, bool $onlySharable)
    {
        $tot = $this->Risque_getMontant_taxe_payable_par_courtier($risque, $onlySharable) - $this->Risque_getMontant_taxe_payable_par_courtier_payee($risque);
        return round($tot, 4);
    }
    public function Risque_getMontant_retrocommissions_payable_par_courtier(?Risque $risque, ?Partenaire $partenaire, $addressedTo, bool $onlySharable)
    {
        // dd($risque, $partenaire);
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_retrocommissions_payable_par_courtier($piste, $partenaire, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_retrocommissions_payable_par_courtier_payee(?Risque $risque, ?Partenaire $partenaire)
    {
        // dd($risque, $partenaire);
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_retrocommissions_payable_par_courtier_payee($piste, $partenaire);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_retrocommissions_payable_par_courtier_solde(?Risque $risque, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable)
    {
        $tot = $this->Risque_getMontant_retrocommissions_payable_par_courtier($risque, $partenaireCible, $addressedTo, $onlySharable) - $this->Risque_getMontant_retrocommissions_payable_par_courtier_payee($risque, $partenaireCible);
        return round($tot, 4);
    }





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
        $str = "<br/><small class='text-secondary m-2'>Postes: ";
        foreach ($tabPosteFacturables as $posteFacturable) {
            $str .= '<span class="badge text-bg-secondary m-2">' . $posteFacturable['poste'] . " @ " . number_format($posteFacturable['montantPayable'], 2, ',', ".") . " " . $this->serviceMonnaies->getMonnaieAffichage()->getCode() . '</span>';
        }
        return $str . '</small>';
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
    public function Tranche_getMontant_taxe_payable_par_courtier_solde(?Tranche $tranche, bool $onlySharable): float
    {
        $solde = $this->Tranche_getMontant_taxe_payable_par_courtier($tranche, $onlySharable) - $this->Tranche_getMontant_taxe_payable_par_courtier_payee($tranche);
        return round($solde, 4);
    }

    public function Cotation_getMontant_taxe_payable_par_courtier_payee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation != null) {
            /** @var Tranche $tranche */
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->Tranche_getMontant_taxe_payable_par_courtier_payee($tranche);
            }
        }
        return $montant;
    }
    public function Cotation_getMontant_taxe_payable_par_courtier_solde(?Cotation $cotation, bool $onlySharable): float
    {
        $solde = $this->Cotation_getMontant_taxe_payable_par_courtier($cotation, $onlySharable) - $this->Cotation_getMontant_taxe_payable_par_courtier_payee($cotation);
        return round($solde, 4);
    }






    /**
     * LA TVA - TAXES PAYABLES PAR L'ASSUREUR
     */
    public function Tranche_getMontant_taxe_payable_par_assureur(?Tranche $tranche, bool $onlySharable): float
    {
        $montant = 0;
        if ($tranche != null) {
            if ($tranche->getCotation()) {
                $montant = $this->Cotation_getMontant_taxe_payable_par_assureur($tranche->getCotation(), $onlySharable) * $tranche->getPourcentage();
            }
        }
        return $montant;
    }
    public function Tranche_getMontant_taxe_payable_par_assureur_payee(?Tranche $tranche): float
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



                //Qui est-ce qu'on a facturé?
                if ($note->getAddressedTo() == Note::TO_AUTORITE_FISCALE) {
                    /** @var Taxe $taxe */
                    $taxe = $this->taxeRepository->find($article->getIdPoste());
                    if ($taxe) {
                        if ($taxe->getRedevable() == Taxe::REDEVABLE_ASSUREUR) {
                            // dd("Paiement Tax", $article);
                            $montant += $proportionPaiement * $article->getMontant();
                            // dd("Prop payée: " . $proportionPaiement, "Taxe: ", "Montant Article: ", $proportionPaiement * $article->getMontant());
                        }
                    }
                }
            }
        }
        return $montant;
    }
    public function Tranche_getMontant_taxe_payable_par_assureur_solde(?Tranche $tranche, bool $onlySharable): float
    {
        $solde = $this->Tranche_getMontant_taxe_payable_par_assureur($tranche, $onlySharable) - $this->Tranche_getMontant_taxe_payable_par_assureur_payee($tranche);
        return $solde;
    }
    public function Cotation_getMontant_taxe_payable_par_assureur(?Cotation $cotation, bool $onlySharable): float
    {
        return $this->getTotalNet($cotation, $onlySharable, true);
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
        return $this->serviceTaxes->getMontantTaxe($net_total, $isIARD, $isTaxAssureur);
    }
    public function Cotation_getMontant_taxe_payable_par_assureur_payee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation != null) {
            /** @var Tranche $tranche */
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->Tranche_getMontant_taxe_payable_par_assureur_payee($tranche);
            }
        }
        return $montant;
    }
    public function Cotation_getMontant_taxe_payable_par_assureur_solde(?Cotation $cotation, bool $onlySharable): float
    {
        $solde = $this->Cotation_getMontant_taxe_payable_par_assureur($cotation, $onlySharable) - $this->Cotation_getMontant_taxe_payable_par_assureur_payee($cotation);
        return round($solde, 4);
    }




    /**
     * LES COMMISSIONS
     */
    public function Tranche_getMontant_commission_pure(?Tranche $tranche, $addressedTo, bool $onlySharable): float
    {
        // dd($onlySharable);
        $montant = 0;
        if ($tranche != null) {
            if ($tranche->getCotation()) {
                $montant = $this->Cotation_getMontant_commission_pure($tranche->getCotation(), $addressedTo, $onlySharable) * $tranche->getPourcentage();
            }
        }
        return $montant;
    }



    public function Tranche_getMontant_commission_ttc(?Tranche $tranche, ?int $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($tranche != null) {
            if ($tranche->getCotation()) {
                $montant = $this->Cotation_getMontant_commission_ttc($tranche->getCotation(), $addressedTo, $onlySharable) * $tranche->getPourcentage();
            }
        }
        return $montant;
    }
    public function Tranche_getMontant_commission_ht(?Tranche $tranche, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($tranche != null) {
            if ($tranche->getCotation()) {
                $montant = $this->Cotation_getMontant_commission_ht($tranche->getCotation(), $addressedTo, $onlySharable) * $tranche->getPourcentage();
            }
        }
        // dd($montant);
        return $montant;
    }
    public function Tranche_getReserve(Tranche $tranche)
    {
        return round(($this->Tranche_getMontant_commission_pure($tranche, -1, false) - $this->Tranche_getMontant_retrocommissions_payable_par_courtier($tranche, $this->Tranche_getPartenaire($tranche), -1, true)), 2);
    }
    public function Cotation_getReserve(Cotation $cotation)
    {
        $reserve = 0;
        foreach ($cotation->getTranches() as $tranche) {
            $reserve += $this->Tranche_getReserve($tranche);
        }
        return round($reserve, 2);
    }
    public function Piste_getReserve(Piste $piste)
    {
        $reserve = 0;
        /** @var Cotation $cotation */
        foreach ($piste->getCotations() as $cotation) {
            if ($this->Cotation_isBound($cotation)) {
                foreach ($cotation->getTranches() as $tranche) {
                    $reserve += $this->Tranche_getReserve($tranche);
                }
            }
        }
        return round($reserve, 2);
    }
    public function Client_getReserve(Client $client)
    {
        $reserve = 0;
        /** @var Piste $piste */
        foreach ($client->getPistes() as $piste) {
            if ($this->Piste_isBound($piste)) {
                $reserve += $this->Piste_getReserve($piste);
            }
        }
        return round($reserve, 2);
    }
    public function Groupe_getReserve(Groupe $groupe)
    {
        $reserve = 0;
        /** @var Client $client */
        foreach ($groupe->getClients() as $client) {
            $reserve += $this->Client_getReserve($client);
        }
        return round($reserve, 2);
    }
    public function Assureur_getReserve(Assureur $assureur)
    {
        $reserve = 0;
        /** @var Cotation $cotation */
        foreach ($assureur->getCotations() as $cotation) {
            if ($this->Cotation_isBound($cotation)) {
                foreach ($cotation->getTranches() as $tranche) {
                    $reserve += $this->Tranche_getReserve($tranche);
                }
            }
        }
        return round($reserve, 2);
    }
    public function Partenaire_getReserve(Partenaire $partenaire)
    {
        /** @var Utilisateur $user */
        $user = $this->security->getUser();

        /** @var Entreprise $ese */
        $ese = $user->getConnectedTo();

        $reserve = 0;

        /** @var Piste $piste */
        foreach ($ese->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                if ($this->Piste_isBound($piste)) {
                    foreach ($piste->getCotations() as $cotation) {
                        if ($this->Cotation_getPartenaire($cotation) == $partenaire) {
                            $reserve += $this->Cotation_getReserve($cotation);
                        }
                    }
                }
            }
        }
        return round($reserve, 2);
    }
    public function Avenant_getReserve(Avenant $avenant)
    {
        return round($this->Cotation_getReserve($avenant->getCotation()), 2);
    }
    public function TypeRevenu_getReserve(TypeRevenu $typeRevenu, $addressedTo)
    {
        $reserve = 0;
        /** @var RevenuPourCourtier $revenu */
        foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
            $reserve += $this->Revenu_getReserve($revenu, $addressedTo);
        }
        return round($reserve, 2);
    }
    public function Revenu_getReserve(RevenuPourCourtier $revenu, $addressedTo)
    {
        /** @var Utilisateur $user */
        $user = $this->security->getUser();

        /** @var Entreprise $ese */
        $ese = $user->getConnectedTo();

        $comPure = $this->Revenu_getMontant_pure($revenu, $addressedTo, false);
        $retrocomGlobale = 0;
        foreach ($ese->getPartenaires() as $partenaire) {
            $retrocomGlobale += $this->Revenu_getMontant_retrocommissions_payable_par_courtier($revenu, $partenaire, -1, true);
        }
        return round($comPure - $retrocomGlobale, 2);
    }
    public function Risque_getReserve(Risque $risque)
    {
        $reserve = 0;
        foreach ($risque->getPistes() as $piste) {
            if ($this->Piste_isBound($piste)) {
                foreach ($piste->getCotations() as $cotation) {
                    $reserve += $this->Cotation_getReserve($cotation);
                }
            }
        }
        return round($reserve, 2);
    }
    public function Tranche_getPanierStatus(Tranche $tranche, PanierNotes $panier)
    {
        /**
         * -1 = N'est pas eligible pour le panier
         * 0 = est éligible pour le panier et peut y être ajouté
         * 1 = est éligible pour le panier et mais ne peut plys y être ajouté car déjà dans le panier
         */
        // dd($panier->getAddressedTo());
        if ($panier != null && $tranche != null) {
            $tabPosteFacturables = $this->Tranche_getPostesFacturables($tranche, $panier, -1, false);

            if (count($tabPosteFacturables) != 0) {
                foreach ($tabPosteFacturables as $posteFacturable) {
                    if ($panier->isInvoiced($tranche->getId(), $posteFacturable['montantPayable'], $posteFacturable['poste']) == true) {
                        return 1;
                    } else if ($this->Tranche_isAlreadyInADifferentNote($tranche, $posteFacturable) == false) {
                        return 0;
                    }
                }
            }
        }
        return -1;
    }
    public function Tranche_getMontant_commission_ttc_collectee(?Tranche $tranche): float
    {
        $montant = 0;
        if (count($tranche->getArticles())) {
            /** @var Article $article */
            foreach ($tranche->getArticles() as $articleTranche) {
                /** @var Article $article */
                $article = $articleTranche;

                /** @var Note $note */
                $note = $article->getNote();

                //Qu'est-ce qu'on a facturé?
                if ($note->getAddressedTo() == Note::TO_ASSUREUR || $note->getAddressedTo() == Note::TO_CLIENT) {
                    /** @var RevenuPourCourtier $revenu */
                    $revenu = $this->revenuPourCourtierRepository->find($article->getIdPoste());
                    if ($revenu->getTypeRevenu()) {
                        if ($revenu->getTypeRevenu()->getRedevable() == TypeRevenu::REDEVABLE_ASSUREUR || $revenu->getTypeRevenu()->getRedevable() == TypeRevenu::REDEVABLE_CLIENT) {
                            //Quelle proportion de la note a-t-elle été payée (100%?)
                            $proportionPaiement = $this->Note_getMontant_paye($note) / $this->Note_getMontant_payable($note);
                            $montant += $proportionPaiement * $article->getMontant();
                        }
                    }
                }
                // dd($article, "Porportion de paiement = " . $proportionPaiement);
            }
        }
        return $montant;
    }
    public function Cotation_getMontant_commission_pure(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $comHT = $this->Cotation_getMontant_commission_ht($cotation, $addressedTo, $onlySharable);
        $taxeCourtier = $this->Cotation_getMontant_taxe_payable_par_courtier($cotation, $onlySharable);
        return $comHT - $taxeCourtier;
    }

    public function Tranche_getMontant_commission_ttc_solde(?Tranche $tranche, $addressedTo, bool $onlySharable): float
    {
        $solde = $this->Tranche_getMontant_commission_ttc($tranche, $addressedTo, $onlySharable) - $this->Tranche_getMontant_commission_ttc_collectee($tranche);
        return $solde;
    }
    public function Cotation_getMontant_commission_ttc_solde(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $solde = $this->Cotation_getMontant_commission_ttc($cotation, $addressedTo, $onlySharable) - $this->Cotation_getMontant_commission_ttc_collectee($cotation);
        return round($solde, 4);
    }
    public function Cotation_getMontant_commission_ttc_collectee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation != null) {
            /** @var Tranche $tranche */
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->Tranche_getMontant_commission_ttc_collectee($tranche);
            }
        }
        return $montant;
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
        $taxe = $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
        return $net + $taxe;
    }
    public function Cotation_getMontant_commission_ttc_payable_par_assureur(?Cotation $cotation, bool $onlySharable): float
    {
        $net = $this->Cotation_getMontant_commission_ht($cotation, TypeRevenu::REDEVABLE_ASSUREUR, $onlySharable);
        $taxe = $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
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
            //Comment s'applique le taux sur de commission sur le montant du chargement / composant?
            if ($typeRevenu->isAppliquerPourcentageDuRisque()) {
                /** @var Risque $couverture */
                $couverture = $this->Cotation_getRisque($cotation);
                if ($couverture != null) {
                    $montant += $montantChargementPrime * $couverture->getPourcentageCommissionSpecifiqueHT();
                }
            } else {
                //On cherche à appliquer d'abord le taux du revenu sur la cotation
                if ($revenu->getTauxExceptionel() != 0) {
                    $montant += $montantChargementPrime * $revenu->getTauxExceptionel();
                } else if ($revenu->getMontantFlatExceptionel() != 0) {
                    $montant += $revenu->getMontantFlatExceptionel();
                } else {
                    //Auncune formule définie sur le revenu situé dans la cotation
                    //On doit appliquer la formule par défaut pour ce type de revenu
                    if ($typeRevenu->getPourcentage() != 0) {
                        // dd("On applique le pourcentage spécifique à " . $revenuPourCourtier->getNom(),);
                        $montant += $montantChargementPrime * $typeRevenu->getPourcentage();
                    } else if ($typeRevenu->getMontantflat() != 0) {
                        // dd("On applique le montant flat qui est de " . $revenuPourCourtier->getMontantFlatExceptionel());
                        $montant += $montantChargementPrime * $typeRevenu->getMontantflat();
                    }
                }
            }
        }
        return $montant;
    }






    /**
     * RETRO-COMMISSION DUE AU PARTENAIRE
     */
    public function Cotation_getMontant_retrocommissions_payable_par_courtier(?Cotation $cotation, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($cotation != null) {
            foreach ($cotation->getRevenus() as $revenu) {
                $montant += $this->Revenu_getMontant_retrocommissions_payable_par_courtier($revenu, $partenaireCible, $addressedTo);
            }
        }
        return $montant;
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

    public function Tranche_getMontant_retrocommissions_payable_par_courtier(?Tranche $tranche, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($tranche != null) {
            if ($tranche->getCotation() != null) {
                $montant = $this->Cotation_getMontant_retrocommissions_payable_par_courtier($tranche->getCotation(), $partenaireCible, $addressedTo, $onlySharable) * $tranche->getPourcentage();
            }
        }
        return $montant;
    }
    public function Cotation_getTauxConditionsSpecialePiste(?Cotation $cotation)
    {
        if (count($cotation->getPiste()->getConditionsPartageExceptionnelles()) != 0) {
            /** @var ConditionPartage $conditionPartagePiste */
            $conditionPartagePiste = $cotation->getPiste()->getConditionsPartageExceptionnelles()[0];
            return ($conditionPartagePiste->getTaux() * 100) . "%";
        } else {
            return null;
        }
    }
    public function Tranche_getTauxConditionsSpecialePiste(?Tranche $tranche)
    {
        if ($tranche) {
            if ($tranche->getCotation()) {
                return $this->Cotation_getTauxConditionsSpecialePiste($tranche->getCotation());
            }
        }
        return null;
    }
    public function Cotation_isBound(?Cotation $cotation): bool
    {
        return $cotation->getAvenants()[0] != null || count($cotation->getAvenants()) != 0;
    }
    public function RevenuPourCourtier_isBound(?RevenuPourCourtier $revenuPourCourtier)
    {
        if ($revenuPourCourtier != null) {
            if ($revenuPourCourtier->getCotation() != null) {
                return $revenuPourCourtier->getCotation()->getAvenants()[0] != null || count($revenuPourCourtier->getCotation()->getAvenants()) != 0;
            }
        }
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
    private function calculerRetroCommission(?Risque $risque, ?ConditionPartage $conditionPartage, $assiette): float
    {
        $montant = 0;
        $taux = $conditionPartage->getTaux();
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
    public function Cotation_getMontant_retrocommissions_payable_par_courtier_payee(?Cotation $cotation, ?Partenaire $partenaireCible): float
    {
        $montant = 0;
        if ($cotation != null) {
            // $partenaire = $cotation->getPiste()->getPartenaires()[0];
            $partenaire = $this->Cotation_getPartenaire($cotation);


            if ($partenaire) {
                //On doit d'abord s'assurer que nous parlons du même partenaire
                if ($this->isSamePartenaire($partenaire, $partenaireCible)) {
                    /** @var Tranche $tranche */
                    foreach ($cotation->getTranches() as $tranche) {
                        $montant += $this->Tranche_getMontant_retrocommissions_payable_par_courtier_payee($tranche, $partenaireCible);
                    }
                }
            }
        }
        // dd($montant, $partenaire, $partenaireCible);
        return $montant;
    }
    public function Tranche_getMontant_retrocommissions_payable_par_courtier_payee(?Tranche $tranche, ?Partenaire $partenaireCible = null): float
    {
        $montant = 0;
        if (count($tranche->getArticles())) {
            //On doit d'abord s'assurer que nous parlons du même partenaire
            // if ($this->isSamePartenaire($tranche->getCotation()->getPiste()->getPartenaires()[0], $partenaireCible)) {
            if ($this->isSamePartenaire($this->Tranche_getPartenaire($tranche), $partenaireCible)) {
                /** @var Article $article */
                foreach ($tranche->getArticles() as $articleTranche) {

                    /** @var Article $article */
                    $article = $articleTranche;

                    /** @var Note $note */
                    $note = $article->getNote();

                    //Quelle proportion de la note a-t-elle été payée (100%?)
                    $proportionPaiement = $this->Note_getMontant_paye($note) / $this->Note_getMontant_payable($note);
                    // dd("Ici");

                    //Qu'est-ce qu'on a facturé?
                    if ($note->getAddressedTo() == Note::TO_PARTENAIRE) {
                        $montant += $proportionPaiement * $article->getMontant();
                    }
                }
            }
        }
        return $montant;
    }
    public function Cotation_getMontant_retrocommissions_payable_par_courtier_solde(?Cotation $cotation, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable): float
    {
        $retrocom = $this->Cotation_getMontant_retrocommissions_payable_par_courtier($cotation, $partenaireCible, $addressedTo, $onlySharable);
        $retrocom_paye = $this->Cotation_getMontant_retrocommissions_payable_par_courtier_payee($cotation, $partenaireCible);
        return round($retrocom - $retrocom_paye, 4);
    }
    public function Tranche_getMontant_retrocommissions_payable_par_courtier_solde(?Tranche $tranche, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable): float
    {
        $retrocom = $this->Tranche_getMontant_retrocommissions_payable_par_courtier($tranche, $partenaireCible, $addressedTo, $onlySharable);
        $retrocom_paye = $this->Tranche_getMontant_retrocommissions_payable_par_courtier_payee($tranche, $partenaireCible);
        return round($retrocom - $retrocom_paye, 4);
    }


    /**
     * 
     * INTERMEDIAIRE
     */
    public function Partenaire_hasConditionsSpeciales(?Partenaire $partenaire): bool
    {
        if ($partenaire) {
            return count($partenaire->getConditionPartages()) != 0;
        } else {
            return false;
        }
    }
    public function Partenaire_getDescriptionsConditionSpecialePartage(?Partenaire $partenaire): ArrayCollection
    {
        $tabDescription = new ArrayCollection();
        if ($partenaire != null) {
            if (count($partenaire->getConditionPartages()) != 0) {
                foreach ($partenaire->getConditionPartages() as $condition) {
                    $description = $this->Partenaire_getDescriptionConditionSpecialePartage($condition);
                    if (!$tabDescription->contains($description)) {
                        $tabDescription->add($description);
                    }
                }
            }
        }
        return $tabDescription;
    }
    public function Partenaire_getDescriptionConditionSpecialePartage(?ConditionPartage $conditionPartage): string
    {
        $texte = "";
        if ($conditionPartage != null) {
            $unite = match ($conditionPartage->getUniteMesure()) {
                ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT => "(du client)",
                ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE => "(du risque)",
                ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE => "(du partenaire)",
            };

            // dd($conditionPartage);
            switch ($conditionPartage->getFormule()) {
                case ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL:
                    if ($conditionPartage->getSeuil()) {
                        $texte = ($conditionPartage->getTaux() * 100) . "% de l'assiette " . $unite . " si celle-ci est au moins égale à " . $conditionPartage->getSeuil() . " " . $this->serviceMonnaies->getCodeMonnaieAffichage();
                    }
                    break;
                case ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL:
                    if ($conditionPartage->getSeuil()) {
                        $texte = ($conditionPartage->getTaux() * 100) . "% de l'assiette " . $unite . " si celle-ci est inférieur au seuil à " . $conditionPartage->getSeuil() . " " . $this->serviceMonnaies->getCodeMonnaieAffichage();
                    }
                    break;
                case ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL:
                    $texte = "";
                    break;

                default:
                    # code...
                    break;
            }
            switch ($conditionPartage->getCritereRisque()) {
                case ConditionPartage::CRITERE_PAS_RISQUES_CIBLES:
                    $texte .= ".";
                    break;
                case ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES:
                    $texte .= $this->Partenaire_getTexteRisqueCibles(", uniquement sur ", 1, $conditionPartage);
                    break;
                case ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES:
                    $texte .= $this->Partenaire_getTexteRisqueCibles(", sur tous les autres risques sauf ", 1, $conditionPartage);
                    break;

                default:
                    # code...
                    break;
            }
        }
        return $texte;
    }
    private function Partenaire_getTexteRisqueCibles($texte, $index, ConditionPartage $conditionPartage)
    {
        /** @var Risque $risque */
        foreach ($conditionPartage->getProduits() as $risque) {
            if (count($conditionPartage->getProduits()) == $index && $index > 1) {
                $texte .= " et " . $risque->getCode() . ".";
            } else if ($index < count($conditionPartage->getProduits()) && $index != 1) {
                $texte .= ", " . $risque->getCode() . "";
            } else if (count($conditionPartage->getProduits()) == 1) {
                $texte .= " " . $risque->getCode() . ".";
            } else {
                $texte .= " " . $risque->getCode() . "";
            }
            $index++;
        }
        return $texte;
    }
    public function Partenaire_getMontant_prime_payable_par_client(Partenaire $partenaire): float
    {
        $montant = 0;
        if ($partenaire->getEntreprise()) {
            if ($partenaire->getEntreprise()) {
                /** @var Invite $invite */
                foreach ($partenaire->getEntreprise()->getInvites() as $invite) {
                    /** @var Piste $piste */
                    foreach ($invite->getPistes() as $piste) {
                        /** @var Cotation $cotation */
                        foreach ($piste->getCotations() as $cotation) {
                            if ($this->Cotation_getPartenaire($cotation) == $partenaire) {
                                if ($this->Cotation_isBound($cotation)) {
                                    // dd("J'ai trouvé quelques chose", $cotation);
                                    $montant += $this->Cotation_getMontant_prime_payable_par_client($cotation);
                                }
                            }
                        }
                    }
                }
            }
        }
        return $montant;
    }
    public function Partenaire_getMontant_prime_payable_par_client_payee(Partenaire $partenaire): float
    {
        $montant = 0;
        if ($partenaire->getEntreprise()) {
            if ($partenaire->getEntreprise()) {
                /** @var Invite $invite */
                foreach ($partenaire->getEntreprise()->getInvites() as $invite) {
                    /** @var Piste $piste */
                    foreach ($invite->getPistes() as $piste) {
                        /** @var Cotation $cotation */
                        foreach ($piste->getCotations() as $cotation) {
                            if ($this->Cotation_getPartenaire($cotation) == $partenaire) {
                                if ($this->Cotation_isBound($cotation)) {
                                    // dd("J'ai trouvé quelques chose", $cotation);
                                    $montant += $this->Cotation_getMontant_prime_payable_par_client_payee($cotation);
                                }
                            }
                        }
                    }
                }
            }
        }
        return $montant;
    }
    public function Partenaire_getMontant_prime_payable_par_client_solde(Partenaire $partenaire): float
    {
        $montant = $this->Partenaire_getMontant_prime_payable_par_client($partenaire) - $this->Partenaire_getMontant_prime_payable_par_client_payee($partenaire);
        return round($montant, 4);
    }
    public function Partenaire_getMontant_commission_pure(Partenaire $partenaire, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($partenaire->getEntreprise()) {
            if ($partenaire->getEntreprise()) {
                /** @var Invite $invite */
                foreach ($partenaire->getEntreprise()->getInvites() as $invite) {
                    /** @var Piste $piste */
                    foreach ($invite->getPistes() as $piste) {
                        /** @var Cotation $cotation */
                        foreach ($piste->getCotations() as $cotation) {
                            if ($this->Cotation_getPartenaire($cotation) == $partenaire) {
                                if ($this->Cotation_isBound($cotation)) {
                                    // dd("J'ai trouvé quelques chose", $cotation);
                                    $montant += $this->Cotation_getMontant_commission_pure($cotation, $addressedTo, $onlySharable);
                                }
                            }
                        }
                    }
                }
            }
        }
        return $montant;
    }
    public function Partenaire_getMontant_commission_ht(Partenaire $partenaire, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($partenaire->getEntreprise()) {
            if ($partenaire->getEntreprise()) {
                /** @var Invite $invite */
                foreach ($partenaire->getEntreprise()->getInvites() as $invite) {
                    /** @var Piste $piste */
                    foreach ($invite->getPistes() as $piste) {
                        /** @var Cotation $cotation */
                        foreach ($piste->getCotations() as $cotation) {
                            if ($this->Cotation_getPartenaire($cotation) == $partenaire) {
                                if ($this->Cotation_isBound($cotation)) {
                                    // dd("J'ai trouvé quelques chose", $cotation);
                                    $montant += $this->Cotation_getMontant_commission_ht($cotation, $addressedTo, $onlySharable);
                                }
                            }
                        }
                    }
                }
            }
        }
        return $montant;
    }
    public function Partenaire_getMontant_commission_ttc(Partenaire $partenaire, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($partenaire->getEntreprise()) {
            if ($partenaire->getEntreprise()) {
                /** @var Invite $invite */
                foreach ($partenaire->getEntreprise()->getInvites() as $invite) {
                    /** @var Piste $piste */
                    foreach ($invite->getPistes() as $piste) {
                        /** @var Cotation $cotation */
                        foreach ($piste->getCotations() as $cotation) {
                            if ($this->Cotation_getPartenaire($cotation) == $partenaire) {
                                if ($this->Cotation_isBound($cotation)) {
                                    // dd("J'ai trouvé quelques chose", $cotation);
                                    $montant += $this->Cotation_getMontant_commission_ttc($cotation, $addressedTo, $onlySharable);
                                }
                            }
                        }
                    }
                }
            }
        }
        return $montant;
    }
    public function Partenaire_getMontant_commission_ttc_collectee(Partenaire $partenaire): float
    {
        $montant = 0;
        if ($partenaire->getEntreprise()) {
            if ($partenaire->getEntreprise()) {
                /** @var Invite $invite */
                foreach ($partenaire->getEntreprise()->getInvites() as $invite) {
                    /** @var Piste $piste */
                    foreach ($invite->getPistes() as $piste) {
                        /** @var Cotation $cotation */
                        foreach ($piste->getCotations() as $cotation) {
                            if ($this->Cotation_getPartenaire($cotation) == $partenaire) {
                                if ($this->Cotation_isBound($cotation)) {
                                    // dd("J'ai trouvé quelques chose", $cotation);
                                    $montant += $this->Cotation_getMontant_commission_ttc_collectee($cotation);
                                }
                            }
                        }
                    }
                }
            }
        }
        return $montant;
    }
    public function Partenaire_getMontant_commission_ttc_solde(Partenaire $partenaire, $addressedTo, bool $onlySharable): float
    {
        $montant = $this->Partenaire_getMontant_commission_ttc($partenaire, $addressedTo, $onlySharable) - $this->Partenaire_getMontant_commission_ttc_collectee($partenaire);
        return round($montant, 4);
    }
    public function Partenaire_getMontant_taxe_payable_par_assureur(Partenaire $partenaire, bool $onlySharable): float
    {
        $montant = 0;
        if ($partenaire->getEntreprise()) {
            if ($partenaire->getEntreprise()) {
                /** @var Invite $invite */
                foreach ($partenaire->getEntreprise()->getInvites() as $invite) {
                    /** @var Piste $piste */
                    foreach ($invite->getPistes() as $piste) {
                        /** @var Cotation $cotation */
                        foreach ($piste->getCotations() as $cotation) {
                            if ($this->Cotation_getPartenaire($cotation) == $partenaire) {
                                if ($this->Cotation_isBound($cotation)) {
                                    // dd("J'ai trouvé quelques chose", $cotation);
                                    $montant += $this->Cotation_getMontant_taxe_payable_par_assureur($cotation, $onlySharable);
                                }
                            }
                        }
                    }
                }
            }
        }
        return $montant;
    }
    public function Partenaire_getMontant_taxe_payable_par_assureur_payee(Partenaire $partenaire): float
    {
        $montant = 0;
        if ($partenaire->getEntreprise()) {
            if ($partenaire->getEntreprise()) {
                /** @var Invite $invite */
                foreach ($partenaire->getEntreprise()->getInvites() as $invite) {
                    /** @var Piste $piste */
                    foreach ($invite->getPistes() as $piste) {
                        /** @var Cotation $cotation */
                        foreach ($piste->getCotations() as $cotation) {
                            if ($this->Cotation_getPartenaire($cotation) == $partenaire) {
                                if ($this->Cotation_isBound($cotation)) {
                                    // dd("J'ai trouvé quelques chose", $cotation);
                                    $montant += $this->Cotation_getMontant_taxe_payable_par_assureur_payee($cotation);
                                }
                            }
                        }
                    }
                }
            }
        }
        return $montant;
    }
    public function Partenaire_getMontant_taxe_payable_par_assureur_solde(Partenaire $partenaire, bool $onlySharable): float
    {
        $montant = $this->Partenaire_getMontant_taxe_payable_par_assureur($partenaire, $onlySharable) - $this->Partenaire_getMontant_taxe_payable_par_assureur_payee($partenaire);
        return round($montant, 4);
    }
    public function Partenaire_getMontant_taxe_payable_par_courtier(Partenaire $partenaire, bool $onlySharable): float
    {
        $montant = 0;
        if ($partenaire->getEntreprise()) {
            if ($partenaire->getEntreprise()) {
                /** @var Invite $invite */
                foreach ($partenaire->getEntreprise()->getInvites() as $invite) {
                    /** @var Piste $piste */
                    foreach ($invite->getPistes() as $piste) {
                        /** @var Cotation $cotation */
                        foreach ($piste->getCotations() as $cotation) {
                            if ($this->Cotation_getPartenaire($cotation) == $partenaire) {
                                if ($this->Cotation_isBound($cotation)) {
                                    // dd("J'ai trouvé quelques chose", $cotation);
                                    $montant += $this->Cotation_getMontant_taxe_payable_par_courtier($cotation, $onlySharable);
                                }
                            }
                        }
                    }
                }
            }
        }
        return $montant;
    }
    public function Partenaire_getMontant_taxe_payable_par_courtier_payee(Partenaire $partenaire): float
    {
        $montant = 0;
        if ($partenaire->getEntreprise()) {
            if ($partenaire->getEntreprise()) {
                /** @var Invite $invite */
                foreach ($partenaire->getEntreprise()->getInvites() as $invite) {
                    /** @var Piste $piste */
                    foreach ($invite->getPistes() as $piste) {
                        /** @var Cotation $cotation */
                        foreach ($piste->getCotations() as $cotation) {
                            if ($this->Cotation_getPartenaire($cotation) == $partenaire) {
                                if ($this->Cotation_isBound($cotation)) {
                                    // dd("J'ai trouvé quelques chose", $cotation);
                                    $montant += $this->Cotation_getMontant_taxe_payable_par_courtier_payee($cotation);
                                }
                            }
                        }
                    }
                }
            }
        }
        return $montant;
    }
    public function Partenaire_getMontant_taxe_payable_par_courtier_solde(Partenaire $partenaire, bool $onlySharable): float
    {
        $montant = $this->Partenaire_getMontant_taxe_payable_par_courtier($partenaire, $onlySharable) - $this->Partenaire_getMontant_taxe_payable_par_courtier_payee($partenaire);
        return round($montant, 4);
    }
    public function Partenaire_getMontant_retrocommissions_payable_par_courtier(Partenaire $partenaire, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($partenaire->getEntreprise()) {
            if ($partenaire->getEntreprise()) {
                /** @var Invite $invite */
                foreach ($partenaire->getEntreprise()->getInvites() as $invite) {
                    /** @var Piste $piste */
                    foreach ($invite->getPistes() as $piste) {
                        /** @var Cotation $cotation */
                        foreach ($piste->getCotations() as $cotation) {
                            if ($this->Cotation_getPartenaire($cotation) == $partenaire) {
                                if ($this->Cotation_isBound($cotation)) {
                                    // dd("J'ai trouvé quelques chose", $cotation);
                                    $montant += $this->Cotation_getMontant_retrocommissions_payable_par_courtier($cotation, $partenaire, $addressedTo, $onlySharable);
                                }
                            }
                        }
                    }
                }
            }
        }
        return $montant;
    }
    public function Partenaire_getMontant_retrocommissions_payable_par_courtier_payee(Partenaire $partenaire): float
    {
        $montant = 0;
        if ($partenaire->getEntreprise()) {
            if ($partenaire->getEntreprise()) {
                /** @var Invite $invite */
                foreach ($partenaire->getEntreprise()->getInvites() as $invite) {
                    /** @var Piste $piste */
                    foreach ($invite->getPistes() as $piste) {
                        /** @var Cotation $cotation */
                        foreach ($piste->getCotations() as $cotation) {
                            if ($this->Cotation_getPartenaire($cotation) == $partenaire) {
                                if ($this->Cotation_isBound($cotation)) {
                                    // dd("J'ai trouvé quelques chose", $cotation);
                                    $montant += $this->Cotation_getMontant_retrocommissions_payable_par_courtier_payee($cotation, $partenaire);
                                }
                            }
                        }
                    }
                }
            }
        }
        return $montant;
    }
    public function Partenaire_getMontant_retrocommissions_payable_par_courtier_solde(Partenaire $partenaire, $addressedTo, bool $onlySharable): float
    {
        $montant = $this->Partenaire_getMontant_retrocommissions_payable_par_courtier($partenaire, $addressedTo, $onlySharable) - $this->Partenaire_getMontant_retrocommissions_payable_par_courtier_payee($partenaire);
        return round($montant, 4);
    }



    /**
     * PISTE / PROPOSITION
     */
    public function Piste_getPartenaires(?Piste $piste)
    {
        $partenaires = new ArrayCollection();
        if ($piste) {
            if (count($piste->getCotations())) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    if ($this->Cotation_isBound($cotation)) {
                        $partenaire = $this->Cotation_getPartenaire($cotation);
                        if ($partenaires->contains($cotation) == false) {
                            $partenaires[] = $partenaire;
                        }
                    }
                }
            }
        }
        // dd($partenaires);
        return $partenaires;
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
    public function Piste_getMontant_prime_payable_par_client(?Piste $piste)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    // if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_prime_payable_par_client($cotation);
                    // }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_prime_payable_par_client_payee(?Piste $piste)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_prime_payable_par_client_payee($cotation);
                    }
                }
            }
        }
        return $tot;
    }
    public function Piste_getReferencePolice(?Piste $piste)
    {
        $reference = "";
        if ($piste != null) {
            /** @var Cotation $cotation */
            foreach ($piste->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    if (count($cotation->getAvenants()) != 0) {
                        /** @var Avenant $avenant */
                        $avenant = $cotation->getAvenants()[0];
                        $reference = $avenant->getReferencePolice();
                    }
                }
            }
        }
        return $reference;
    }
    public function Piste_getAvenant(?Piste $piste)
    {
        $num_avenant = "";
        if ($piste != null) {
            /** @var Cotation $cotation */
            foreach ($piste->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    if (count($cotation->getAvenants()) != 0) {
                        /** @var Avenant $avenant */
                        $avenant = $cotation->getAvenants()[0];
                        $num_avenant = $avenant->getNumero();
                    }
                }
            }
        }
        return $num_avenant;
    }
    public function Piste_getMontant_prime_payable_par_client_solde(?Piste $piste)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    // if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_prime_payable_par_client_solde($cotation);
                    // }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_commission_pure(?Piste $piste, $addressedTo, bool $onlySharable)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    // if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_commission_pure($cotation, $addressedTo, $onlySharable);
                    // }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_commission_ht(?Piste $piste, $addressedTo, bool $onlySharable)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    // if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_commission_ht($cotation, $addressedTo, $onlySharable);
                    // }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_commission_ttc(?Piste $piste, $addressedTo, bool $onlySharable)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    // if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_commission_ttc($cotation, $addressedTo, $onlySharable);
                    // }
                }
            }
        }
        return $tot;
    }
    public function Revenu_getMiniInvoicingStatus(?RevenuPourCourtier $revenuPourCourtier): array
    {
        $data = [
            self::STATUS_DUE => 0,
            self::STATUS_INVOICED => 0,
            self::STATUS_NOT_INVOICED => 0,
        ];
        /** @var Cotation $cotation */
        $cotation = $revenuPourCourtier->getCotation();
        if ($cotation) {
            if ($this->Cotation_isBound($cotation)) {
                foreach ($cotation->getTranches() as $tranche) {
                    /** @var Article $article */
                    foreach ($tranche->getArticles() as $article) {
                        if ($article->getIdPoste() == $revenuPourCourtier->getId()) {
                            // dd("j'ai trouvé l'article", $article, $revenu, $article->getMontant());
                            $data[self::STATUS_INVOICED] += $article->getMontant();
                        }
                    }
                }
                $data[self::STATUS_DUE] = $this->Revenu_getMontant_ttc($revenuPourCourtier);
                $data[self::STATUS_NOT_INVOICED] = $data[self::STATUS_DUE] - $data[self::STATUS_INVOICED];
            }
        }
        // dd("Ici", $statusCollection);
        return $data;
    }
    public function TypeRevennu_getMiniInvoicingStatus(?TypeRevenu $typeRevenu): array
    {
        $miniStatus = [
            self::STATUS_DUE => 0,
            self::STATUS_INVOICED => 0,
            self::STATUS_NOT_INVOICED => 0,
        ];
        if ($typeRevenu != null) {
            if ($typeRevenu->getRedevable() == TypeRevenu::REDEVABLE_ASSUREUR || $typeRevenu->getRedevable() == TypeRevenu::REDEVABLE_CLIENT) {
                // dd("Redevable par l'assureur ou par le client:", $typeRevenu);
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenuPourCourtier) {
                    // dd($revenuStatus);
                    $miniStatusTempo = $this->Revenu_getMiniInvoicingStatus($revenuPourCourtier);
                    $miniStatus[self::STATUS_DUE] += $miniStatusTempo[self::STATUS_DUE];
                    $miniStatus[self::STATUS_INVOICED] += $miniStatusTempo[self::STATUS_INVOICED];
                    $miniStatus[self::STATUS_NOT_INVOICED] += $miniStatusTempo[self::STATUS_NOT_INVOICED];
                }
            }
        }
        // dd($status);
        return $miniStatus;
    }
    public function Piste_getMontant_commission_collectee(?Piste $piste)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    // if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_commission_ttc_collectee($cotation);
                    // }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_commission_ttc_solde(?Piste $piste, $addressedTo, bool $onlySharable)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    // if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_commission_ttc_solde($cotation, $addressedTo, $onlySharable);
                    // }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_taxe_payable_par_assureur(?Piste $piste, bool $onlySharable)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    // if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_taxe_payable_par_assureur($cotation, $onlySharable);
                    // }
                }
            }
        }
        return $tot;
    }
    public function Piste_getTexteRenewalCondition(Piste $piste)
    {
        $strRenewalCondition = match ($piste->getRenewalCondition()) {
            Piste::RENEWAL_CONDITION_ADJUSTABLE_AT_EXPIRY => "Assurance avec ajustement",
            Piste::RENEWAL_CONDITION_ONCE_OFF_AND_EXTENDABLE => "Assurance Temporaire non renouvellable",
            Piste::RENEWAL_CONDITION_RENEWABLE => "Assurance à terme renouvellable",
        };
        return $strRenewalCondition;
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

    public function Piste_getMontant_taxe_payable_par_assureur_payee(?Piste $piste)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    // if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_taxe_payable_par_assureur_payee($cotation);
                    // }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_taxe_payable_par_assureur_solde(?Piste $piste, bool $onlySharable)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    // if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_taxe_payable_par_assureur_solde($cotation, $onlySharable);
                    // }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_taxe_payable_par_courtier(?Piste $piste, bool $onlySharable)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    // if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_taxe_payable_par_courtier($cotation, $onlySharable);
                    // }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_taxe_payable_par_courtier_payee(?Piste $piste)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    // if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_taxe_payable_par_courtier_payee($cotation);
                    // }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_taxe_payable_par_courtier_solde(?Piste $piste, bool $onlySharable)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    // if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_taxe_payable_par_courtier_solde($cotation, $onlySharable);
                    // }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_retrocommissions_payable_par_courtier(?Piste $piste, ?Partenaire $partenaire, $addressedTo, bool $onlySharable)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_retrocommissions_payable_par_courtier($cotation, $partenaire, $addressedTo, $onlySharable);
                    }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_retrocommissions_payable_par_courtier_payee(?Piste $piste, ?Partenaire $partenaire)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_retrocommissions_payable_par_courtier_payee($cotation, $partenaire);
                    }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_retrocommissions_payable_par_courtier_solde(?Piste $piste, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_retrocommissions_payable_par_courtier_solde($cotation, $partenaireCible, $addressedTo, $onlySharable);
                    }
                }
            }
        }
        return $tot;
    }




    /**
     * PRIME D'ASSURANCE
     */
    public function Tranche_getMontant_prime_payable_par_client(?Tranche $tranche): float
    {
        $montant = 0;
        if ($tranche != null) {
            if ($tranche->getCotation()) {
                $montant = $this->Cotation_getMontant_prime_payable_par_client($tranche->getCotation()) * $tranche->getPourcentage();
            }
        }
        return $montant;
    }
    public function Tranche_getMontant_prime_payable_par_client_payee(?Tranche $tranche): float
    {
        $montant = 0;
        return $montant;
    }
    public function Tranche_getMontant_prime_payable_par_client_solde(?Tranche $tranche): float
    {
        $montant =
            $this->Tranche_getMontant_prime_payable_par_client($tranche)
            - $this->Tranche_getMontant_prime_payable_par_client_payee($tranche);
        return round($montant, 4);
    }
    public function Cotation_getMontant_prime_payable_par_client(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation != null) {
            foreach ($cotation->getChargements() as $loading) {
                /** @var ChargementPourPrime $chargement */
                $chargement = $loading;
                $montant += $chargement->getMontantFlatExceptionel();
                // dd("ici", $loading);
            }
        }
        return $montant;
    }
    public function Cotation_getMontant_prime_payable_par_client_payee(?Cotation $cotation): float
    {
        $montant = 0;

        return $montant;
    }
    public function Cotation_getMontant_prime_payable_par_client_solde(?Cotation $cotation): float
    {
        $montant =
            $this->Cotation_getMontant_prime_payable_par_client($cotation)
            - $this->Cotation_getMontant_prime_payable_par_client_payee($cotation);
        return round($montant, 4);
    }



    /**
     * TAXES
     */
    public function Taxe_getNomRedevable(?Taxe $taxe)
    {
        if ($taxe != null) {
            return match ($taxe->getRedevable()) {
                Taxe::REDEVABLE_ASSUREUR => "Supportée par l'assureur et collectée en même temps que les commissions.",
                Taxe::REDEVABLE_COURTIER => "Supportée par le courtier",
            };
        }
        return "";
    }
    public function Taxe_getNomAutoriteFiscale(?Taxe $taxe)
    {
        if ($taxe != null) {
            return count($taxe->getAutoriteFiscales()) != 0 ? $taxe->getAutoriteFiscales()[0] : "Aucune autorité fiscale définie";
        }
        return "";
    }
    public function Taxe_getMontant_commission_pure(?Taxe $taxe, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($taxe != null) {
            foreach ($taxe->getEntreprise()->getGroupes() as $groupe) {
                $tot += $this->Groupe_getMontant_commission_pure($groupe, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Taxe_getMontant_commission_ht(?Taxe $taxe, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($taxe != null) {
            foreach ($taxe->getEntreprise()->getGroupes() as $groupe) {
                $tot += $this->Groupe_getMontant_commission_ht($groupe, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Taxe_getMontant_commission_ttc(?Taxe $taxe, $addressedTo, bool $onlySharable): float
    {
        $tot = 0;
        if ($taxe != null) {
            foreach ($taxe->getEntreprise()->getGroupes() as $groupe) {
                $tot += $this->Groupe_getMontant_commission_ttc($groupe, $addressedTo, $onlySharable);
            }
        }
        return $tot;
    }
    public function Taxe_getMontant_commission_ttc_collectee(?Taxe $taxe): float
    {
        $tot = 0;
        if ($taxe != null) {
            foreach ($taxe->getEntreprise()->getGroupes() as $groupe) {
                $tot += $this->Groupe_getMontant_commission_ttc_collectee($groupe);
            }
        }
        return $tot;
    }
    public function Taxe_getMontant_commission_ttc_solde(?Taxe $taxe, $addressedTo, bool $onlySharable): float
    {
        $tot = $this->Taxe_getMontant_commission_ttc($taxe, $addressedTo, $onlySharable) - $this->Taxe_getMontant_commission_ttc_collectee($taxe);
        return round($tot, 4);
    }
    public function Taxe_getMontant_taxe_payable_par_assureur(?Taxe $taxe, bool $onlySharable): float
    {
        $tot = 0;
        if ($taxe != null) {
            foreach ($taxe->getEntreprise()->getGroupes() as $groupe) {
                $tot += $this->Groupe_getMontant_taxe_payable_par_assureur($groupe, $onlySharable);
            }
        }
        return $tot;
    }
    public function Taxe_getMontant_taxe_payable_par_assureur_payee(?Taxe $taxe): float
    {
        $tot = 0;
        if ($taxe != null) {
            foreach ($taxe->getEntreprise()->getGroupes() as $groupe) {
                $tot += $this->Groupe_getMontant_taxe_payable_par_assureur_payee($groupe);
            }
        }
        return $tot;
    }
    public function Taxe_getMontant_taxe_payable_par_assureur_solde(?Taxe $taxe, bool $onlySharable): float
    {
        $tot = $this->Taxe_getMontant_taxe_payable_par_assureur($taxe, $onlySharable) - $this->Taxe_getMontant_taxe_payable_par_assureur_payee($taxe);
        return round($tot, 4);
    }

    public function Taxe_getMontant_taxe_payable_par_courtier(?Taxe $taxe, bool $onlySharable): float
    {
        $tot = 0;
        if ($taxe != null) {
            foreach ($taxe->getEntreprise()->getGroupes() as $groupe) {
                $tot += $this->Groupe_getMontant_taxe_payable_par_courtier($groupe, $onlySharable);
            }
        }
        return $tot;
    }
    public function Taxe_getMontant_taxe_payable_par_courtier_payee(?Taxe $taxe): float
    {
        $tot = 0;
        if ($taxe != null) {
            foreach ($taxe->getEntreprise()->getGroupes() as $groupe) {
                $tot += $this->Groupe_getMontant_taxe_payable_par_courtier_payee($groupe);
            }
        }
        return $tot;
    }
    public function Taxe_getMontant_taxe_payable_par_courtier_solde(?Taxe $taxe, bool $onlySharable): float
    {
        $tot = $this->Taxe_getMontant_taxe_payable_par_courtier($taxe, $onlySharable) - $this->Taxe_getMontant_taxe_payable_par_courtier_payee($taxe);
        return round($tot, 4);
    }


    /**
     * COMPTE BANCAIRE
     */
    public function CompteBancaire_getMontantDebit(?CompteBancaire $compteBancaire): float
    {
        $montant = 0;
        // dd($compteBancaire);
        if ($compteBancaire != null) {
            /** @var Paiement $paiement */
            foreach ($compteBancaire->getPaiements() as $paiement) {
                // dd($paiement);
                if ($paiement->getNote() != null) {
                    if ($paiement->getNote()->getType() == Note::TYPE_NOTE_DE_DEBIT) {
                        $montant += $paiement->getMontant();
                    }
                }
            }
        }
        return $montant;
    }
    public function CompteBancaire_getMontantCredit(?CompteBancaire $compteBancaire): float
    {
        $montant = 0;
        // dd($compteBancaire);
        if ($compteBancaire != null) {
            /** @var Paiement $paiement */
            foreach ($compteBancaire->getPaiements() as $paiement) {
                // dd($paiement);
                if ($paiement->getNote() != null) {
                    if ($paiement->getNote()->getType() == Note::TYPE_NOTE_DE_CREDIT) {
                        $montant += $paiement->getMontant();
                    }
                }
            }
        }
        return $montant;
    }
    public function CompteBancaire_getMontantSolde(?CompteBancaire $compteBancaire): float
    {
        $montant = 0;
        // dd($compteBancaire);
        if ($compteBancaire != null) {
            /** @var Paiement $paiement */
            foreach ($compteBancaire->getPaiements() as $paiement) {
                // dd($paiement);
                if ($paiement->getNote() != null) {
                    if ($paiement->getNote()->getType() == Note::TYPE_NOTE_DE_DEBIT) {
                        $montant += $paiement->getMontant();
                    } else if ($paiement->getNote()->getType() == Note::TYPE_NOTE_DE_CREDIT) {
                        $montant -= $paiement->getMontant();
                    }
                }
            }
        }
        return $montant;
    }


    /**
     * ARTICLE
     */
    public function ARTICLE_getReferencePolice(Article $article)
    {
        return $this->Piste_getReferencePolice($article->getTranche()->getCotation()->getPiste());
    }

    public function ARTICLE_getNomTranche(Article $article)
    {
        /** @var Tranche $tranche */
        $tranche = $article->getTranche();
        return $tranche->getNom() . " (" . $tranche->getPourcentage() * 100 . "%)";
    }

    public function ARTICLE_getCodeRisque(Article $article)
    {
        return $article->getTranche()->getCotation()->getPiste()->getRisque()->getCode();
    }

    public function ARTICLE_getNumAvenant(Article $article)
    {
        return $this->Piste_getAvenant($article->getTranche()->getCotation()->getPiste());
    }

    public function ARTICLE_getPeriode(Article $article)
    {
        $periode = "";
        /** @var Cotation $cotation */
        $cotation = $article->getTranche()->getCotation();
        if ($this->Cotation_isBound($cotation)) {
            if (count($cotation->getAvenants()) != 0) {
                /** @var Avenant $avenant */
                $avenant = $cotation->getAvenants()[0];
                $dateEffet = $avenant->getStartingAt();
                $dateEcheance = $avenant->getEndingAt();
                $periode = $this->serviceDates->getTexteSimple($dateEffet) . "-" . $this->serviceDates->getTexteSimple($dateEcheance);
            }
        }
        return $periode;
    }

    public function ARTICLE_getNomClient(Article $article)
    {
        $nomClient = "";
        /** @var Cotation $cotation */
        $cotation = $article->getTranche()->getCotation();
        if ($this->Cotation_isBound($cotation)) {
            if (count($cotation->getAvenants()) != 0) {
                $nomClient = $cotation->getPiste()->getClient()->getNom();
            }
        }
        return $nomClient;
    }

    public function ARTICLE_getPrimeTTC(Article $article)
    {
        $primeTTC = 0;
        /** @var Cotation $cotation */
        $cotation = $article->getTranche()->getCotation();
        if ($this->Cotation_isBound($cotation)) {
            $primeTTC = $this->Cotation_getMontant_prime_payable_par_client($cotation);
        }
        // dd($primeTTC);
        return $primeTTC * $article->getTranche()->getPourcentage();
    }



    public function ARTICLE_getPrimeHT(Article $article)
    {
        $primeHT = 0;
        /** @var Cotation $cotation */
        $cotation = $article->getTranche()->getCotation();
        if ($this->Cotation_isBound($cotation)) {
            if (count($cotation->getChargements()) != 0) {
                /** @var ChargementPourPrime $chargementPourPrime */
                foreach ($cotation->getChargements() as $chargementPourPrime) {
                    if ($chargementPourPrime->getType()->getFonction() == Chargement::FONCTION_PRIME_NETTE) {
                        $primeHT += $chargementPourPrime->getMontantFlatExceptionel();
                    }
                }
            }
        }
        return $primeHT * $article->getTranche()->getPourcentage();
    }

    public function ARTICLE_getFronting(Article $article)
    {
        $primeHT = 0;
        /** @var Cotation $cotation */
        $cotation = $article->getTranche()->getCotation();
        if ($this->Cotation_isBound($cotation)) {
            if (count($cotation->getChargements()) != 0) {
                /** @var ChargementPourPrime $chargementPourPrime */
                foreach ($cotation->getChargements() as $chargementPourPrime) {
                    if ($chargementPourPrime->getType()->getFonction() == Chargement::FONCTION_FRONTING) {
                        $primeHT += $chargementPourPrime->getMontantFlatExceptionel();
                    }
                }
            }
        }
        return $primeHT * $article->getTranche()->getPourcentage();
    }

    public function ARTICLE_getComHT(Article $article)
    {
        /** @var Note $note */
        $note = $article->getNote();
        $res = 0;
        if ($note->getAddressedTo() == Note::TO_AUTORITE_FISCALE) {
            /** @var Taxe $taxeFacturee */
            $taxeFacturee = $this->Note_getTaxeFacturee($article->getNote());
            $res = match ($article->getTranche()->getCotation()->getPiste()->getRisque()->getBranche()) {
                Risque::BRANCHE_IARD_OU_NON_VIE => ($article->getMontant() / $taxeFacturee->getTauxIARD()),
                Risque::BRANCHE_VIE => ($article->getMontant() / $taxeFacturee->getTauxVIE()),
            };
        }
        if ($note->getAddressedTo() == Note::TO_ASSUREUR || $note->getAddressedTo() == Note::TO_CLIENT) {
            $comTTC = $this->ARTICLE_getComTTC($article);
            $taxe = $this->getTauxTaxe($article->getTranche()->getCotation(), true);
            $res = ($comTTC / ($taxe + 1));
        }
        if ($note->getAddressedTo() == Note::TO_PARTENAIRE) {
            // dd("ici");
            $res = $this->Tranche_getMontant_commission_ht($article->getTranche(), -1, true);
            // dd($res);
        }
        // dd($res);
        return round($res, 2);
    }

    public function getTauxTaxe(?Cotation $cotation, bool $forAssureur)
    {
        $tauxTaxe = 0;
        if ($forAssureur == true) {
            foreach ($this->serviceTaxes->getTaxesPayableParAssureur() as $taxeAssureur) {
                $tauxTaxe += $this->isIARD($cotation) ? $taxeAssureur->getTauxIARD() : $taxeAssureur->getTauxVIE();
            }
        } else {
            foreach ($this->serviceTaxes->getTaxesPayableParCourtier() as $taxeCourtier) {
                $tauxTaxe += $this->isIARD($cotation) ? $taxeCourtier->getTauxIARD() : $taxeCourtier->getTauxVIE();
            }
        }
        return $tauxTaxe;
    }

    public function ARTICLE_getTauxComHT(Article $article, $addressedTo, bool $onlySharable)
    {
        return round(($this->ARTICLE_getComHT($article, $addressedTo, $onlySharable) / $this->ARTICLE_getPrimeHT($article)) * 100, 2) . "%";
    }

    public function ARTICLE_getTaxeAssureur(Article $article, $addressedTo, bool $onlySharable)
    {
        $taxe = $this->ARTICLE_getComHT($article, $addressedTo, $onlySharable) * $this->getTauxTaxe($article->getTranche()->getCotation(), true);
        return round($taxe, 2);
    }

    public function ARTICLE_getAssiette(Article $article)
    {
        $taxe = $this->ARTICLE_getComHT($article, -1, true) - $this->ARTICLE_getTaxeCourtier($article, -1, true);
        return round($taxe, 2);
    }

    public function ARTICLE_getTaxeCourtier(Article $article, $addressedTo, bool $onlySharable)
    {
        $taxe = $this->ARTICLE_getComHT($article, $addressedTo, $onlySharable) * $this->getTauxTaxe($article->getTranche()->getCotation(), false);
        return round($taxe, 2);
    }

    public function ARTICLE_getMontantTaxeFacturee(Article $article, bool $onlySharable)
    {
        $montantTaxe = 0;
        if ($article != null) {
            /** @var Note $note */
            $note = $article->getNote();
            /** @var Taxe $taxe */
            $taxe = $this->Note_getTaxeFacturee($note);

            if ($note != null && $taxe != null) {
                $montantTaxe = match ($taxe->getRedevable()) {
                    Taxe::REDEVABLE_ASSUREUR => $this->Tranche_getMontant_taxe_payable_par_assureur($article->getTranche(), $onlySharable),
                    Taxe::REDEVABLE_COURTIER => $this->Tranche_getMontant_taxe_payable_par_courtier($article->getTranche(), $onlySharable),
                };
                // dd($montantTaxe);
            }
        }
        return $montantTaxe;
    }

    public function ARTICLE_getComTTC(Article $article)
    {
        return $article->getMontant();
    }

    public function Taxe_getNomTaxeAssureur()
    {
        $taxe = $this->serviceTaxes->getTaxesPayableParAssureur()[0];
        if ($taxe != null) {
            $codeTaxe = $taxe->getCode();
            if ($taxe->getTauxIARD() == $taxe->getTauxVIE()) {
                return strtoupper($codeTaxe . " (" . (round($taxe->getTauxIARD() * 100, 2)) . "%)");
            } else {
                return strtoupper($codeTaxe . "");
            }
        }
        return "Null";
    }

    public function Taxe_getNomTaxeCourtier()
    {
        $taxe = $this->serviceTaxes->getTaxesPayableParCourtier()[0];
        if ($taxe != null) {
            $codeTaxe = $taxe->getCode();
            if ($taxe->getTauxIARD() == $taxe->getTauxVIE()) {
                return $codeTaxe . " (" . (round($taxe->getTauxIARD() * 100, 2)) . "%)";
            } else {
                return $codeTaxe;
            }
        }
        return "Null";
    }


    public function getEnterprise(): Entreprise
    {
        /** @var Utilisateur $user */
        $user = $this->security->getUser();

        return $user->getConnectedTo();
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
            "Docs_attendus" => [],
            "Docs_fournis" => [],
            "Docs_manquants" => [],
        ];
        if ($notification_sinistre != null) {
            $tabDocuments['Docs_attendus'] = $this->getEnterprise()->getModelePieceSinistres();
            $tabDocuments['Docs_fournis'] = $notification_sinistre->getPieces();

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
                        $manquants->add($typePiece);
                    }
                }
            }
            $tabDocuments['Docs_manquants'] = $manquants;
            // dd($tabDocuments, count($tabDocuments['Docs_attendus']), count($tabDocuments['Docs_fournis']));
        }
        return $tabDocuments;
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
            'email' => $invite->getEmail(),
        ]);
        return count($users) != 0 ? $users[0] : null;
    }

    public function Utilisateur_getUtilisateurByEmail($email): ?Utilisateur
    {
        $users = $this->utilisateurRepository->findBy([
            'email' => $email,
        ]);
        return count($users) != 0 ? $users[0] : null;
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
            //On n'affiche que les tâches qui ne sont pas encore accomplies
            if ($this->Tache_getExecutionStatus($tache)['code'] != Tache::EXECUTION_STATUS_COMPLETED) {
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

    public function Avenant_getCommissionTTC(Avenant $avenant, int $addressedTo, bool $onlySharable): float
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
        $status['texte'] = "Pièces (" . count($statusPieces['Docs_fournis']) . "/" . count($statusPieces['Docs_attendus']) . ")"; //" . count($statusPieces['Docs_manquants']);

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
                $tabDetails['effectDate'] = $effectDates[0];
                $tabDetails['expiryDate'] = $expiryDates[0];
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
        return (new CashflowReportSet())
            ->setIndex($index)
            ->setType(CashflowReportSet::TYPE_ELEMENT)
            ->setCurrency_code($this->serviceMonnaies->getCodeMonnaieAffichage())
            ->setDescription($note->getDescription())
            ->setDebtor($this->Note_getNameOfAddressedTo($note))
            ->setStatus($status['Texte'])
            ->setInvoice_reference($note->getReference())
            ->setNet_amount(0)
            ->setTaxes(0)
            ->setGross_due(0)
            ->setAmount_paid(0)
            ->setBalance_due(0)
            ->setUser($this->Utilisateur_getUtilisateurByInvite($note->getInvite()))
            ->setDate_submition(new DateTimeImmutable("now"))
            ->setDate_payment($status['Date dernier paiement']);
    }

    public function Note_getNoteStatus(Note $note)
    {
        $status = [
            "Date dernier paiement" => null,
            "Texte" => "RAS",
        ];

        if ($note != null) {
            $totalDu = $this->Note_getMontant_payable($note);
            $totalPaye = $this->Note_getMontant_paye($note);
            foreach ($note->getPaiements() as $paiement) {
                $status['Date dernier paiement'] = $paiement->getPaidAt();
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
        $cumulNet = 0;
        $cumulTaxe = 0;
        $cumulGross = 0;
        $cumulPaid = 0;
        $cumulBalance = 0;
        $tabReportSets = [];
        $index = 1;
        foreach ($this->Entreprise_getNotes() as $note) {
            $dataSet = $this->createCashflowReportSet($index, $note);
            $dataSet->setGross_due($dataSet->getNet_amount() + $dataSet->getTaxes());
            $dataSet->setAmount_paid(rand(0, $dataSet->getGross_due()));
            $dataSet->setBalance_due($dataSet->getGross_due() - $dataSet->getAmount_paid());

            $days = $this->serviceDates->daysEntre(new DateTimeImmutable("now"), $dataSet->getDate_submition());
            
            if ($days == 0) {
                $dataSet->setDays_passed("Sent to "  . $dataSet->getDebtor() . " today.");
            }else{
                $dataSet->setDays_passed("Sent to "  . $dataSet->getDebtor() . " " . $days .  " days ago.");
            }

            $tabReportSets[] = $dataSet;

            $cumulNet += $dataSet->getNet_amount();
            $cumulTaxe += $dataSet->getTaxes();
            $cumulGross += $dataSet->getGross_due();
            $cumulPaid += $dataSet->getAmount_paid();
            $cumulBalance += $dataSet->getGross_due() - $dataSet->getAmount_paid();

            $index++;

            //Préparation des tableaux pour faire le tri
            $tabReportSets[$note->getId()] = $dataSet;
            // //on doit transformer la date en String afin que la fonction uasort de tri fasse bien son travail
            $tabAOrdonner[$note->getId()] = $days;
        }

        arsort($tabAOrdonner); //tri décroissante = du plus grand au plus pétit.

        $tabFinaleOrdonne = [];
        foreach ($tabAOrdonner as $key => $value) {
            $tabFinaleOrdonne[] = $tabReportSets[$key];
        }

        // //Ligne totale
        $dataSet = (new CashflowReportSet())
            ->setType(CashflowReportSet::TYPE_TOTAL)
            ->setCurrency_code($this->serviceMonnaies->getCodeMonnaieAffichage())
            ->setDescription("TOTAL")
            ->setNet_amount($cumulNet)
            ->setTaxes($cumulTaxe)
            ->setGross_due($cumulGross)
            ->setAmount_paid($cumulPaid)
            ->setBalance_due($cumulBalance);

        $tabFinaleOrdonne[] = $dataSet;
        // // dd($tabReportSets);

        return $tabFinaleOrdonne;
        // return [];
    }
}
