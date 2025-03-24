<?php

namespace App\Constantes;

use App\Entity\Note;
use App\Entity\Taxe;
use App\Entity\Piste;
use App\Entity\Client;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Entity\Article;
use App\Entity\Avenant;
use App\Entity\Tranche;
use App\Entity\Assureur;
use App\Entity\Cotation;
use App\Entity\Paiement;
use App\Entity\Chargement;
use App\Entity\Entreprise;
use App\Entity\Partenaire;
use App\Entity\TypeRevenu;
use App\Services\ServiceTaxes;
use App\Entity\AutoriteFiscale;
use App\Entity\ConditionPartage;
use App\Services\ServiceMonnaies;
use App\Entity\RevenuPourCourtier;
use App\Repository\NoteRepository;
use App\Repository\TaxeRepository;
use App\Entity\ChargementPourPrime;
use App\Repository\ArticleRepository;
use App\Repository\CotationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use App\Repository\AutoriteFiscaleRepository;
use App\Repository\RevenuPourCourtierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use App\Controller\Admin\RevenuCourtierController;
use App\Entity\CompteBancaire;
use App\Entity\Groupe;
use App\Entity\ReportSet\PartnerReportSet;
use App\Repository\PartenaireRepository;
use App\Services\ServiceDates;
use phpDocumentor\Reflection\Types\Integer;
use Symfony\Contracts\Translation\TranslatorInterface;


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
        private ServiceMonnaies $serviceMonnaies,
    ) {}


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
    public function Groupe_getMontant_retrocommissions_payable_par_courtier(?Groupe $groupe, ?Partenaire $partenaireCible): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_retrocommissions_payable_par_courtier($client, $partenaireCible);
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
    public function Groupe_getMontant_retrocommissions_payable_par_courtier_solde(?Groupe $groupe, ?Partenaire $partenaireCible): float
    {
        $tot = $this->Groupe_getMontant_retrocommissions_payable_par_courtier($groupe, $partenaireCible) - $this->Groupe_getMontant_retrocommissions_payable_par_courtier_payee($groupe, $partenaireCible);
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
    public function Groupe_getMontant_commission_ttc(?Groupe $groupe): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_commission_ttc($client);
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
    public function Groupe_getMontant_commission_ttc_solde(?Groupe $groupe): float
    {
        $tot = $this->Groupe_getMontant_commission_ttc($groupe) - $this->Groupe_getMontant_commission_ttc_collectee($groupe);
        return round($tot, 4);
    }
    public function Groupe_getMontant_taxe_payable_par_assureur(?Groupe $groupe): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_taxe_payable_par_assureur($client);
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
    public function Groupe_getMontant_taxe_payable_par_assureur_solde(?Groupe $groupe): float
    {
        $tot = $this->Groupe_getMontant_taxe_payable_par_assureur($groupe) - $this->Groupe_getMontant_taxe_payable_par_assureur_payee($groupe);
        return round($tot, 4);
    }
    public function Groupe_getMontant_taxe_payable_par_courtier(?Groupe $groupe): float
    {
        $tot = 0;
        if ($groupe != null) {
            foreach ($groupe->getClients() as $client) {
                $tot += $this->Client_getMontant_taxe_payable_par_courtier($client);
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
    public function Groupe_getMontant_taxe_payable_par_courtier_solde(?Groupe $groupe): float
    {
        $tot = $this->Groupe_getMontant_taxe_payable_par_courtier($groupe) - $this->Groupe_getMontant_taxe_payable_par_courtier_payee($groupe);
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
    public function Assureur_getMontant_commission_pure(?Assureur $assureur): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_commission_pure($cotation);
                }
            }
        }
        return $tot;
    }
    public function Assureur_getMontant_commission_ht(?Assureur $assureur): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_commission_ht($cotation);
                }
            }
        }
        return $tot;
    }
    public function Assureur_getMontant_commission_ttc(?Assureur $assureur): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_commission_ttc($cotation);
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
    public function Assureur_getMontant_commission_ttc_solde(?Assureur $assureur): float
    {
        $tot = $this->Assureur_getMontant_commission_ttc($assureur) - $this->Assureur_getMontant_commission_ttc_collectee($assureur);
        return round($tot, 4);
    }
    public function Assureur_getMontant_taxe_payable_par_assureur(?Assureur $assureur): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_taxe_payable_par_assureur($cotation);
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
    public function Assureur_getMontant_taxe_payable_par_assureur_solde(?Assureur $assureur): float
    {
        $tot = $this->Assureur_getMontant_taxe_payable_par_assureur($assureur) - $this->Assureur_getMontant_taxe_payable_par_assureur_payee($assureur);
        return round($tot, 4);
    }
    public function Assureur_getMontant_taxe_payable_par_courtier(?Assureur $assureur): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_taxe_payable_par_courtier($cotation);
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
    public function Assureur_getMontant_taxe_payable_par_courtier_solde(?Assureur $assureur): float
    {
        $tot = $this->Assureur_getMontant_taxe_payable_par_courtier($assureur) - $this->Assureur_getMontant_taxe_payable_par_courtier_payee($assureur);
        return round($tot, 4);
    }
    public function Assureur_getMontant_retrocommissions_payable_par_courtier(?Assureur $assureur, ?Partenaire $partenaireCible = null): float
    {
        $tot = 0;
        if ($assureur != null) {
            foreach ($assureur->getCotations() as $cotation) {
                if ($this->Cotation_isBound($cotation)) {
                    $tot += $this->Cotation_getMontant_retrocommissions_payable_par_courtier($cotation, $partenaireCible);
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
    public function Assureur_getMontant_retrocommissions_payable_par_courtier_solde(?Assureur $assureur, ?Partenaire $partenaireCible = null): float
    {
        $tot = $this->Assureur_getMontant_retrocommissions_payable_par_courtier($assureur, $partenaireCible) - $this->Assureur_getMontant_retrocommissions_payable_par_courtier_payee($assureur, $partenaireCible);
        return round($tot, 4);
    }





    /**
     * NOTE - NOTE DE DEBIT OU NOTE DE CREDIT
     */
    public function Note_getMontant_ht(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                if ($note->getAddressedTo() == Note::TO_ASSUREUR || $note->getAddressedTo() == Note::TO_CLIENT) {
                    // $montant += $this->Tranche_getMontant_commission_ht($article->getTranche());
                    $montant += $this->ARTICLE_getComHT($article);
                } else if ($note->getAddressedTo() == Note::TO_AUTORITE_FISCALE) {
                    $montant += $this->ARTICLE_getComHT($article);
                } else if ($note->getAddressedTo() == Note::TO_PARTENAIRE) {
                    $montant += $this->ARTICLE_getComHT($article);
                }
            }
        }
        return $montant;
    }
    public function Note_getMontant_taxes(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                if ($note->getAddressedTo() == Note::TO_ASSUREUR || $note->getAddressedTo() == Note::TO_CLIENT) {
                    // $montant += $this->Tranche_getMontant_taxe_payable_par_assureur($article->getTranche());
                    $montant += $this->ARTICLE_getTaxeAssureur($article);
                } else if ($note->getAddressedTo() == Note::TO_AUTORITE_FISCALE) {
                    // $montant += $this->Tranche_getMontant_taxe_payable_par_assureur($article->getTranche());
                    $montant += $this->ARTICLE_getMontantTaxeFacturee($article);
                } else if ($note->getAddressedTo() == Note::TO_PARTENAIRE) {
                    $montant += $this->ARTICLE_getTaxeCourtier($article);
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
    public function Note_getMontant_commissions_ht(?Note $note)
    {
        $com_ht = 0;
        if ($note != null) {
            /** @var Article $article */
            foreach ($note->getArticles() as $article) {
                $com_ht += $this->Tranche_getMontant_commission_ht($article->getTranche());
                // dd($article);
            }
        }
        return round($com_ht, 2);
    }

    public function Note_getMontant_taxe_facturee(?Note $note)
    {
        $montantTaxe = 0;
        /** @var Taxe $taxe */
        $taxe = $this->Note_getTaxeFacturee($note);
        if ($note != null && $taxe != null) {
            /** @var Article $article */
            foreach ($note->getArticles() as $article) {
                $montantTaxe += match ($taxe->getRedevable()) {
                    Taxe::REDEVABLE_ASSUREUR => $this->Tranche_getMontant_taxe_payable_par_assureur($article->getTranche()),
                    Taxe::REDEVABLE_COURTIER => $this->Tranche_getMontant_taxe_payable_par_courtier($article->getTranche()),
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
                $assiette += $this->ARTICLE_getAssiette($article);
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
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                $montant += $this->ARTICLE_getFronting($article);
            }
        }
        return $montant;
    }
    public function Note_getMontant_prime_ht(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                $montant += $this->ARTICLE_getPrimeHT($article);
            }
        }
        return $montant;
    }
    public function Note_getMontant_prime_ttc(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                $montant += $this->ARTICLE_getPrimeTTC($article);
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
    public function Type_revenu_getMontant_retrocommissions_payable_par_courtier(?TypeRevenu $typeRevenu, ?Partenaire $partenaireCible = null)
    {
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_retrocommissions_payable_par_courtier($revenu, $partenaireCible);
                }
            }
        }
        return $tot;
    }
    public function Type_revenu_getMontant_retrocommissions_payable_par_courtier_payee(?TypeRevenu $typeRevenu, ?Partenaire $partenaireCible = null)
    {
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_retrocommissions_payable_par_courtier_payee($revenu, $partenaireCible);
                }
            }
        }
        return $tot;
    }
    public function Type_revenu_getMontant_retrocommissions_payable_par_courtier_solde(?TypeRevenu $typeRevenu, ?Partenaire $partenaireCible = null)
    {
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_retrocommissions_payable_par_courtier_solde($revenu, $partenaireCible);
                }
            }
        }
        return $tot;
    }
    public function Type_revenu_getMontant_pure(?TypeRevenu $typeRevenu): float
    {
        // dd($typeRevenu);
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_pure($revenu);
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
                    $tot += $this->Revenu_getMontant_taxe_payable_par_assureur_payee($revenu);
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
        $solde = $this->Revenu_getMontant_ttc($revenu) - $this->Revenu_getMontant_ttc_collecte($revenu);
        return round($solde, 4);
    }
    public function Revenu_getMontant_ht(?RevenuPourCourtier $revenu): float
    {
        return $this->Cotation_getMontant_commission($revenu->getTypeRevenu(), $revenu, $revenu->getCotation());
    }

    public function Revenu_getMontant_pure(?RevenuPourCourtier $revenu): float
    {
        $comNette = $this->Cotation_getMontant_commission($revenu->getTypeRevenu(), $revenu, $revenu->getCotation());
        $taxeCourtier = $this->serviceTaxes->getMontantTaxe($comNette, $this->isIARD($revenu->getCotation()), false);
        return $comNette - $taxeCourtier;
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
    public function Revenu_getMontant_taxe_payable_par_assureur_payee(?RevenuPourCourtier $revenu): float
    {
        $montantPaye = 0;
        if ($revenu != null) {
            if ($revenu->getCotation() != null) {
                if (count($revenu->getCotation()->getTranches()) != 0) {
                    /** @var Tranche $tranche */
                    // dd($revenu->getCotation()->getTranches());
                    foreach ($revenu->getCotation()->getTranches() as $tranche) {
                        $montantPaye += $this->Tranche_getMontant_taxe_payable_par_assureur_payee($tranche);
                        // dd($tranche);
                    }
                }
            }
        }
        return $montantPaye;
    }
    public function Revenu_getMontant_taxe_payable_par_courtier_payee(?RevenuPourCourtier $revenu): float
    {
        $montantPaye = 0;
        if ($revenu != null) {
            if ($revenu->getCotation() != null) {
                if (count($revenu->getCotation()->getTranches()) != 0) {
                    /** @var Tranche $tranche */
                    // dd($revenu->getCotation()->getTranches());
                    foreach ($revenu->getCotation()->getTranches() as $tranche) {
                        $montantPaye += $this->Tranche_getMontant_taxe_payable_par_courtier_payee($tranche);
                        // dd($tranche);
                    }
                }
            }
        }
        return $montantPaye;
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
    public function Revenu_getMontant_retrocommissions_payable_par_courtier(?RevenuPourCourtier $revenu, ?Partenaire $partenaireCible): float
    {
        $montant = 0;
        if ($revenu != null) {
            if ($revenu->getCotation() != null) {
                $montant = $this->Cotation_getMontant_retrocommissions_payable_par_courtier($revenu->getCotation(), $partenaireCible);
            }
        }
        return $montant;
    }
    public function Revenu_getMontant_retrocommissions_payable_par_courtier_payee(?RevenuPourCourtier $revenu, ?Partenaire $partenaireCible = null): float
    {
        return $this->Cotation_getMontant_retrocommissions_payable_par_courtier_payee($revenu->getCotation(), $partenaireCible);
    }
    public function Revenu_getMontant_retrocommissions_payable_par_courtier_solde(?RevenuPourCourtier $revenu, ?Partenaire $partenaireCible = null)
    {
        $solde = $this->Revenu_getMontant_retrocommissions_payable_par_courtier($revenu, $partenaireCible) - $this->Revenu_getMontant_retrocommissions_payable_par_courtier_payee($revenu, $partenaireCible);
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
    public function Risque_getMontant_commission_pure(?Risque $risque)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_commission_pure($piste);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_commission_ht(?Risque $risque)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_commission_ht($piste);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_commission_ttc(?Risque $risque)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_commission_ttc($piste);
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
    public function Risque_getMontant_commission_ttc_solde(?Risque $risque)
    {
        $tot = $this->Risque_getMontant_commission_ttc($risque) - $this->Risque_getMontant_commission_collectee($risque);
        return round($tot, 4);
    }
    public function Risque_getMontant_taxe_payable_par_assureur(?Risque $risque)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_taxe_payable_par_assureur($piste);
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
    public function Risque_getMontant_taxe_payable_par_courtier(?Risque $risque)
    {
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_taxe_payable_par_courtier($piste);
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
    public function Risque_getMontant_taxe_payable_par_assureur_solde(?Risque $risque)
    {
        $tot = $this->Risque_getMontant_taxe_payable_par_assureur($risque) - $this->Risque_getMontant_taxe_payable_par_assureur_payee($risque);
        return round($tot, 4);
    }
    public function Risque_getMontant_taxe_payable_par_courtier_solde(?Risque $risque)
    {
        $tot = $this->Risque_getMontant_taxe_payable_par_courtier($risque) - $this->Risque_getMontant_taxe_payable_par_courtier_payee($risque);
        return round($tot, 4);
    }
    public function Risque_getMontant_retrocommissions_payable_par_courtier(?Risque $risque, ?Partenaire $partenaire = null)
    {
        // dd($risque, $partenaire);
        $tot = 0;
        if ($risque) {
            foreach ($risque->getPistes() as $piste) {
                $tot += $this->Piste_getMontant_retrocommissions_payable_par_courtier($piste, $partenaire);
            }
        }
        return $tot;
    }
    public function Risque_getMontant_retrocommissions_payable_par_courtier_payee(?Risque $risque, ?Partenaire $partenaire = null)
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
    public function Risque_getMontant_retrocommissions_payable_par_courtier_solde(?Risque $risque, ?Partenaire $partenaireCible)
    {
        $tot = $this->Risque_getMontant_retrocommissions_payable_par_courtier($risque, $partenaireCible) - $this->Risque_getMontant_retrocommissions_payable_par_courtier_payee($risque, $partenaireCible);
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
                                    $montant = $this->Tranche_getMontant_retrocommissions_payable_par_courtier($tranche, $partenaire, $addressedTo, $onlySharable);
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
                                $net = $this->Tranche_getMontant_commission_ht($tranche);
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
    public function Tranche_getPostesFacturablesText(?Tranche $tranche, ?PanierNotes $panier)
    {
        $tabPosteFacturables = $this->Tranche_getPostesFacturables($tranche, $panier);
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
    public function Cotation_getMontant_taxe_payable_par_courtier(?Cotation $cotation, bool $onlySharable): float
    {
        $net = $this->Cotation_getMontant_commission_payable_par_assureur($cotation, $onlySharable) + $this->Cotation_getMontant_commission_payable_par_client($cotation, $onlySharable);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), false);
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

                //Qu'est-ce qu'on a facturé?
                if ($note->getAddressedTo() == Note::TO_AUTORITE_FISCALE) {
                    /** @var Taxe $taxe */
                    $taxe = $this->taxeRepository->find($article->getIdPoste());
                    if ($taxe) {
                        if ($taxe->getRedevable() == Taxe::REDEVABLE_ASSUREUR) {
                            // dd("Paiement Tax", $article);
                            $montant += $proportionPaiement * $article->getMontant();
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
        $net = $this->Cotation_getMontant_commission_payable_par_assureur($cotation, $onlySharable) + $this->Cotation_getMontant_commission_payable_par_client($cotation, $onlySharable);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
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
        return $montant;
    }
    public function Tranche_getPanierStatus(Tranche $tranche, PanierNotes $panier)
    {
        /**
         * -1 = N'est pas eligible pour le panier
         * 0 = est éligible pour le panier et peut y être ajouté
         * 1 = est éligible pour le panier et mais ne peut plys y être ajouté car déjà dans le panier
         */
        // dd($panier, $tranche);
        if ($panier != null && $tranche != null) {
            $tabPosteFacturables = $this->Tranche_getPostesFacturables($tranche, $panier);

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
    public function Cotation_getMontant_commission_ht(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $comTTC = $this->Cotation_getMontant_commission_ttc($cotation, $addressedTo, $onlySharable);
        $taxeAssureur = $this->Cotation_getMontant_taxe_payable_par_assureur($cotation, $onlySharable);
        return $comTTC - $taxeAssureur;
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

            default:
                $comTTCAssureur = $this->Cotation_getMontant_commission_ttc_payable_par_assureur($cotation, $onlySharable);
                $comTTCClient = $this->Cotation_getMontant_commission_ttc_payable_par_client($cotation, $onlySharable);
                return $comTTCAssureur + $comTTCClient;
                break;
        }
    }
    public function Cotation_getMontant_commission_ttc_payable_par_client(?Cotation $cotation, bool $onlySharable): float
    {
        $net = $this->Cotation_getMontant_commission_payable_par_client($cotation, $onlySharable);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true) + $net;
    }
    public function Cotation_getMontant_commission_ttc_payable_par_assureur(?Cotation $cotation, bool $onlySharable): float
    {
        $net = $this->Cotation_getMontant_commission_payable_par_assureur($cotation, $onlySharable);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true) + $net;
    }
    public function Cotation_getMontant_commission_payable_par_client(?Cotation $cotation, bool $onlySharable): float
    {
        $montant = 0;
        if ($cotation) {
            //Pour chaque revenu configuré dans cette cotation
            foreach ($cotation->getRevenus() as $revenu) {
                /** @var RevenuPourCourtier $revenuPourCourtier*/
                $revenuPourCourtier = $revenu;

                /** @var TypeRevenu $typeRevenu */
                $typeRevenu = $revenuPourCourtier->getTypeRevenu();

                if ($onlySharable == true) {
                    if ($typeRevenu->isShared() == true) {
                        $montant += $this->loadComClient($typeRevenu, $revenuPourCourtier, $cotation);
                    }
                } else {
                    $montant += $this->loadComClient($typeRevenu, $revenuPourCourtier, $cotation);
                }
            }
        }
        // dd("Je dois calculer ici la commission payable par l'assureur dans cette proposition");
        return $montant;
    }
    public function Cotation_getMontant_commission_payable_par_assureur(?Cotation $cotation, bool $onlySharable): float
    {
        $montant = 0;
        if ($cotation) {
            //Pour chaque revenu configuré dans cette cotation
            foreach ($cotation->getRevenus() as $revenu) {
                /** @var RevenuPourCourtier $revenuPourCourtier*/
                $revenuPourCourtier = $revenu;

                /** @var TypeRevenu $typeRevenu */
                $typeRevenu = $revenuPourCourtier->getTypeRevenu();

                if ($onlySharable == true) {
                    if ($typeRevenu->isShared() == true) {
                        $montant += $this->loadComAssureur($typeRevenu, $revenuPourCourtier, $cotation);
                    }
                } else {
                    $montant += $this->loadComAssureur($typeRevenu, $revenuPourCourtier, $cotation);
                }
            }
        }
        // dd("Je dois calculer ici la commission payable par l'assureur dans cette proposition");
        return $montant;
    }

    private function loadComAssureur(TypeRevenu $typeRevenu, RevenuPourCourtier $revenuPourCourtier, Cotation $cotation)
    {
        $montant = 0;
        //Uniquement pour les revenus qui sont redevebles à nous par l'assureur
        if ($typeRevenu->getRedevable() == TypeRevenu::REDEVABLE_ASSUREUR) {
            $montant = $this->Cotation_getMontant_commission($typeRevenu, $revenuPourCourtier, $cotation);
        }
        return $montant;
    }

    private function loadComClient(TypeRevenu $typeRevenu, RevenuPourCourtier $revenuPourCourtier, Cotation $cotation)
    {
        $montant = 0;
        //Uniquement pour les revenus qui sont redevebles à nous par l'assureur
        if ($typeRevenu->getRedevable() == TypeRevenu::REDEVABLE_CLIENT) {
            $montant = $this->Cotation_getMontant_commission($typeRevenu, $revenuPourCourtier, $cotation);
        }
        return $montant;
    }

    private function Cotation_getMontant_commission(?TypeRevenu $typeRevenu, ?RevenuPourCourtier $revenuPourCourtier, ?Cotation $cotation): float
    {
        $montant = 0;
        if ($typeRevenu->getTypeChargement()) {
            /** @var Chargement $typeChargementCible */
            $typeChargementCible = $typeRevenu->getTypeChargement();
            $montantChargementCible = 0;
            //On doit récupérer le montant ou la valeur de ce composant
            foreach ($cotation->getChargements() as $loading) {
                if ($loading->getType()) {
                    if ($loading->getType()->getId() == $typeChargementCible->getId()) {
                        /** @var ChargementPourPrime $chargement */
                        $chargement = $loading;
                        $montantChargementCible = $chargement->getMontantFlatExceptionel();
                    }
                }
            }
            //Comment s'applique le taux sur de commission sur le montant du chargement / composant?
            if ($typeRevenu->isAppliquerPourcentageDuRisque()) {
                if ($cotation->getPiste()) {
                    if ($cotation->getPiste()->getRisque()) {
                        /** @var Risque $couverture */
                        $couverture = $cotation->getPiste()->getRisque();
                        $montant += $montantChargementCible * $couverture->getPourcentageCommissionSpecifiqueHT();
                        // dd("Chargement: " . $montantChargementCible, "On applique le taux de com lié au risque " . $couverture->getNomComplet() . " qui est de " . $couverture->getPourcentageCommissionSpecifiqueHT(), "Commission: " . $montant);
                    }
                }
            } else {
                // dd("Non, on applique le % spécifique à ce type de Revenu", $typeRevenu, $typeRevenu->isAppliquerPourcentageDuRisque());
                //On cherche à appliquer d'abord le taux du revenu sur la cotation
                if ($revenuPourCourtier->getTauxExceptionel() != 0) {
                    $montant += $montantChargementCible * $revenuPourCourtier->getTauxExceptionel();
                } else if ($revenuPourCourtier->getMontantFlatExceptionel() != 0) {
                    $montant += $revenuPourCourtier->getMontantFlatExceptionel();
                } else {
                    //Auncune formule définie sur le revenu situé dans la cotation
                    //On doit appliquer la formule par défaut pour ce type de revenu
                    if ($typeRevenu->getPourcentage() != 0) {
                        // dd("On applique le pourcentage spécifique à " . $revenuPourCourtier->getNom(),);
                        $montant += $montantChargementCible * $typeRevenu->getPourcentage();
                    } else if ($typeRevenu->getMontantflat() != 0) {
                        // dd("On applique le montant flat qui est de " . $revenuPourCourtier->getMontantFlatExceptionel());
                        $montant += $montantChargementCible * $typeRevenu->getMontantflat();
                    } else {
                        // dd("Il n'y a malheuresement aucun revenu fixé!");
                    }
                }
            }
        }
        return $montant;
    }






    /**
     * RETRO-COMMISSION DUE AU PARTENAIRE
     */
    private function Cotation_appliquerTauxRetrocomPartenaire(?Partenaire $partenaire, ?Cotation $cotation, $addressedTo, bool $onlySharable)
    {
        $montant = 0;
        if (count($partenaire->getConditionPartages()) != 0) {
            //On traite les conditions spéciales attachées au partenaire
            $montant = $this->appliquerConditions($partenaire->getConditionPartages()[0], $cotation, $addressedTo, $onlySharable);
        } else if ($partenaire->getPart() != 0) {
            $montant = $this->Cotation_getMontant_commission_pure($cotation, Note::TO_PARTENAIRE, true) * $partenaire->getPart();
        }
        return $montant;
    }
    public function Cotation_getMontant_retrocommissions_payable_par_courtier(?Cotation $cotation, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($cotation->getPiste()) {
            //On cherche à appliquer les conditions de partage attachées à la piste
            if (count($cotation->getPiste()->getPartenaires()) != 0) {
                /** @var Partenaire $partenaire */
                $partenaire = $cotation->getPiste()->getPartenaires()[0];
                if ($partenaire) {

                    //On doit d'abord s'assurer que nous parlons du même partenaire
                    if ($this->isSamePartenaire($partenaire, $partenaireCible)) {
                        if (count($cotation->getPiste()->getConditionsPartageExceptionnelles()) != 0) {
                            //On traite les conditions spéciale attachées à la piste
                            $montant = $this->appliquerConditions($cotation->getPiste()->getConditionsPartageExceptionnelles()[0], $cotation, $addressedTo, $onlySharable);
                        } else {
                            $montant = $this->Cotation_appliquerTauxRetrocomPartenaire($partenaire, $cotation, $addressedTo, $onlySharable);
                        }
                    }
                }
            } else if (count($cotation->getPiste()->getClient()->getPartenaires()) != 0) {
                /** @var Partenaire $partenaire */
                $partenaire = $cotation->getPiste()->getClient()->getPartenaires()[0];

                //On doit d'abord s'assurer que nous parlons du même partenaire
                if ($this->isSamePartenaire($partenaire, $partenaireCible)) {
                    $montant = $this->Cotation_appliquerTauxRetrocomPartenaire($partenaire, $cotation, $addressedTo, $onlySharable);
                }
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
    private function Cotation_getSommeCommissionPurePartenaire(?Cotation $cotation): float
    {
        $somme = 0;
        /** @var Entreprise $entreprise */
        $entreprise = $cotation->getPiste()->getInvite()->getEntreprise();
        $cotationsDuPartenaire = $this->cotationRepository->loadCotationsWithPartnerAll($cotation->getPiste()->getExercice(), $entreprise, $this->Cotation_getPartenaire($cotation));
        foreach ($cotationsDuPartenaire as $proposition) {
            $somme += $this->Cotation_getMontant_commission_pure($proposition);
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
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE => $this->Cotation_getSommeCommissionPurePartenaire($cotation),
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
    public function Cotation_getMontant_retrocommissions_payable_par_courtier_payee(?Cotation $cotation, ?Partenaire $partenaireCible = null): float
    {
        $montant = 0;
        if ($cotation != null) {
            $partenaire = $cotation->getPiste()->getPartenaires()[0];
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
        return $montant;
    }
    public function Tranche_getMontant_retrocommissions_payable_par_courtier_payee(?Tranche $tranche, ?Partenaire $partenaireCible = null): float
    {
        $montant = 0;
        if (count($tranche->getArticles())) {
            //On doit d'abord s'assurer que nous parlons du même partenaire
            if ($this->isSamePartenaire($tranche->getCotation()->getPiste()->getPartenaires()[0], $partenaireCible)) {
                /** @var Article $article */
                foreach ($tranche->getArticles() as $articleTranche) {

                    /** @var Article $article */
                    $article = $articleTranche;

                    /** @var Note $note */
                    $note = $article->getNote();

                    //Quelle proportion de la note a-t-elle été payée (100%?)
                    $proportionPaiement = $this->Note_getMontant_paye($note) / $this->Note_getMontant_payable($note);

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
                        $texte = ($conditionPartage->getTaux() * 100) . "% de la commission pure " . $unite . " si celle-ci (assiette) est au moins égale à " . $conditionPartage->getSeuil() . " " . $this->serviceMonnaies->getCodeMonnaieAffichage();
                    }
                    break;
                case ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL:
                    if ($conditionPartage->getSeuil()) {
                        $texte = ($conditionPartage->getTaux() * 100) . "% de la commission pure " . $unite . " si celle-ci (assiette) est inférieur au seuil à " . $conditionPartage->getSeuil() . " " . $this->serviceMonnaies->getCodeMonnaieAffichage();
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
    public function Partenaire_getMontant_commission_pure(Partenaire $partenaire): float
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
                                    $montant += $this->Cotation_getMontant_commission_pure($cotation);
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
    public function Partenaire_getMontant_commission_ttc(Partenaire $partenaire): float
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
                                    $montant += $this->Cotation_getMontant_commission_ttc($cotation);
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
    public function Partenaire_getMontant_commission_ttc_solde(Partenaire $partenaire): float
    {
        $montant = $this->Partenaire_getMontant_commission_ttc($partenaire) - $this->Partenaire_getMontant_commission_ttc_collectee($partenaire);
        return round($montant, 4);
    }
    public function Partenaire_getMontant_taxe_payable_par_assureur(Partenaire $partenaire): float
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
                                    $montant += $this->Cotation_getMontant_taxe_payable_par_assureur($cotation);
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
    public function Partenaire_getMontant_taxe_payable_par_assureur_solde(Partenaire $partenaire): float
    {
        $montant = $this->Partenaire_getMontant_taxe_payable_par_assureur($partenaire) - $this->Partenaire_getMontant_taxe_payable_par_assureur_payee($partenaire);
        return round($montant, 4);
    }
    public function Partenaire_getMontant_taxe_payable_par_courtier(Partenaire $partenaire): float
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
                                    $montant += $this->Cotation_getMontant_taxe_payable_par_courtier($cotation);
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
    public function Partenaire_getMontant_taxe_payable_par_courtier_solde(Partenaire $partenaire): float
    {
        $montant = $this->Partenaire_getMontant_taxe_payable_par_courtier($partenaire) - $this->Partenaire_getMontant_taxe_payable_par_courtier_payee($partenaire);
        return round($montant, 4);
    }
    public function Partenaire_getMontant_retrocommissions_payable_par_courtier(Partenaire $partenaire): float
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
                                    $montant += $this->Cotation_getMontant_retrocommissions_payable_par_courtier($cotation);
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
                                    $montant += $this->Cotation_getMontant_retrocommissions_payable_par_courtier_payee($cotation);
                                }
                            }
                        }
                    }
                }
            }
        }
        return $montant;
    }
    public function Partenaire_getMontant_retrocommissions_payable_par_courtier_solde(Partenaire $partenaire): float
    {
        $montant = $this->Partenaire_getMontant_retrocommissions_payable_par_courtier($partenaire) - $this->Partenaire_getMontant_retrocommissions_payable_par_courtier_payee($partenaire);
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
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_prime_payable_par_client($cotation);
                    }
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
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_prime_payable_par_client_solde($cotation);
                    }
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
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_commission_pure($cotation, $addressedTo, $onlySharable);
                    }
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
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_commission_ht($cotation, $addressedTo, $onlySharable);
                    }
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
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_commission_ttc($cotation, $addressedTo, $onlySharable);
                    }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_commission_collectee(?Piste $piste)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_commission_ttc_collectee($cotation);
                    }
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
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_commission_ttc_solde($cotation, $addressedTo, $onlySharable);
                    }
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
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_taxe_payable_par_assureur($cotation, $onlySharable);
                    }
                }
            }
        }
        return $tot;
    }
    public function Piste_getMontant_taxe_payable_par_assureur_payee(?Piste $piste)
    {
        $tot = 0;
        if ($piste) {
            if (count($piste->getCotations()) != 0) {
                /** @var Cotation $cotation */
                foreach ($piste->getCotations() as $cotation) {
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_taxe_payable_par_assureur_payee($cotation);
                    }
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
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_taxe_payable_par_assureur_solde($cotation, $onlySharable);
                    }
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
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_taxe_payable_par_courtier($cotation, $onlySharable);
                    }
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
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_taxe_payable_par_courtier_payee($cotation);
                    }
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
                    if ($this->Cotation_isBound($cotation)) {
                        $tot += $this->Cotation_getMontant_taxe_payable_par_courtier_solde($cotation, $onlySharable);
                    }
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
        foreach ($cotation->getChargements() as $loading) {
            /** @var ChargementPourPrime $chargement */
            $chargement = $loading;
            $montant += $chargement->getMontantFlatExceptionel();
            // dd("ici", $loading);
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
    public function Taxe_getMontant_commission_pure(?Taxe $taxe): float
    {
        $tot = 0;
        if ($taxe != null) {
            foreach ($taxe->getEntreprise()->getGroupes() as $groupe) {
                $tot += $this->Groupe_getMontant_commission_pure($groupe);
            }
        }
        return $tot;
    }
    public function Taxe_getMontant_commission_ht(?Taxe $taxe): float
    {
        $tot = 0;
        if ($taxe != null) {
            foreach ($taxe->getEntreprise()->getGroupes() as $groupe) {
                $tot += $this->Groupe_getMontant_commission_ht($groupe);
            }
        }
        return $tot;
    }
    public function Taxe_getMontant_commission_ttc(?Taxe $taxe): float
    {
        $tot = 0;
        if ($taxe != null) {
            foreach ($taxe->getEntreprise()->getGroupes() as $groupe) {
                $tot += $this->Groupe_getMontant_commission_ttc($groupe);
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
    public function Taxe_getMontant_commission_ttc_solde(?Taxe $taxe): float
    {
        $tot = $this->Taxe_getMontant_commission_ttc($taxe) - $this->Taxe_getMontant_commission_ttc_collectee($taxe);
        return round($tot, 4);
    }
    public function Taxe_getMontant_taxe_payable_par_assureur(?Taxe $taxe): float
    {
        $tot = 0;
        if ($taxe != null) {
            foreach ($taxe->getEntreprise()->getGroupes() as $groupe) {
                $tot += $this->Groupe_getMontant_taxe_payable_par_assureur($groupe);
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
    public function Taxe_getMontant_taxe_payable_par_assureur_solde(?Taxe $taxe): float
    {
        $tot = $this->Taxe_getMontant_taxe_payable_par_assureur($taxe) - $this->Taxe_getMontant_taxe_payable_par_assureur_payee($taxe);
        return round($tot, 4);
    }

    public function Taxe_getMontant_taxe_payable_par_courtier(?Taxe $taxe): float
    {
        $tot = 0;
        if ($taxe != null) {
            foreach ($taxe->getEntreprise()->getGroupes() as $groupe) {
                $tot += $this->Groupe_getMontant_taxe_payable_par_courtier($groupe);
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
    public function Taxe_getMontant_taxe_payable_par_courtier_solde(?Taxe $taxe): float
    {
        $tot = $this->Taxe_getMontant_taxe_payable_par_courtier($taxe) - $this->Taxe_getMontant_taxe_payable_par_courtier_payee($taxe);
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
        } else if ($note->getAddressedTo() == Note::TO_ASSUREUR || $note->getAddressedTo() == Note::TO_CLIENT) {
            $comTTC = $this->ARTICLE_getComTTC($article);
            $res = ($comTTC / ($this->getTauxTaxe($article->getTranche()->getCotation(), true) + 1));
        } else if ($note->getAddressedTo() == Note::TO_PARTENAIRE) {
            // dd("Ici");
            $onlySharable = true;
            $res += $this->Tranche_getMontant_commission_ht($article->getTranche(), $onlySharable);
        }

        return round($res);
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

    public function ARTICLE_getTauxComHT(Article $article)
    {
        return round(($this->ARTICLE_getComHT($article) / $this->ARTICLE_getPrimeHT($article)) * 100, 2) . "%";
    }

    public function ARTICLE_getTaxeAssureur(Article $article)
    {
        $taxe = $this->ARTICLE_getComHT($article) * $this->getTauxTaxe($article->getTranche()->getCotation(), true);
        return round($taxe, 2);
    }

    public function ARTICLE_getAssiette(Article $article)
    {
        $taxe = $this->ARTICLE_getComHT($article) - $this->ARTICLE_getTaxeCourtier($article);
        return round($taxe, 2);
    }

    public function ARTICLE_getTaxeCourtier(Article $article)
    {
        $taxe = $this->ARTICLE_getComHT($article) * $this->getTauxTaxe($article->getTranche()->getCotation(), false);
        return round($taxe, 2);
    }

    public function ARTICLE_getMontantTaxeFacturee(Article $article)
    {
        $montantTaxe = 0;
        if ($article != null) {
            /** @var Note $note */
            $note = $article->getNote();
            /** @var Taxe $taxe */
            $taxe = $this->Note_getTaxeFacturee($note);

            if ($note != null && $taxe != null) {
                $montantTaxe = match ($taxe->getRedevable()) {
                    Taxe::REDEVABLE_ASSUREUR => $this->Tranche_getMontant_taxe_payable_par_assureur($article->getTranche()),
                    Taxe::REDEVABLE_COURTIER => $this->Tranche_getMontant_taxe_payable_par_courtier($article->getTranche()),
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
}
