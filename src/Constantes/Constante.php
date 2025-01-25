<?php

namespace App\Constantes;

use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Entity\Note;
use App\Entity\RevenuPourCourtier;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use Doctrine\Common\Collections\Collection;
use Symfony\Contracts\Translation\TranslatorInterface;

class Constante
{
    public function __construct(
        private TranslatorInterface $translator,
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

    public function getTypeNote(int $type): string
    {
        switch ($type) {
            case 0:
                return $this->translator->trans("note_de_debit");
                break;
            case 1:
                return $this->translator->trans("note_de_credit");
                break;
        }
    }

    public function getTabAddressedTo(): array
    {
        return [
            $this->translator->trans("addressed_to_client") => 0,
            $this->translator->trans("addressed_to_insurer") => 1,
            $this->translator->trans("addressed_to_partner") => 2,
        ];
    }

    public function getAddressedTo(int $addressedTo): string
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


    public function Tranche_getMontant_prime_payable_par_client(?Tranche $tranche): float
    {
        $montant = 0;

        return $montant;
    }


    public function Tranche_getMontant_commission_payable_par_assureur(?Tranche $tranche, ?Collection $typesrevenus = null): float
    {
        $montant = 0;

        return $montant;
    }

    public function Tranche_getMontant_commission_payable_par_client(?Tranche $tranche, ?Collection $typesrevenus = null): float
    {
        $montant = 0;

        return $montant;
    }

    public function Tranche_getMontant_taxe_payable_par_courtier(?Tranche $tranche, ?Collection $autoritesFiscales = null): float
    {
        $montant = 0;

        return $montant;
    }

    public function Tranche_getMontant_retrocommissions_payable_par_courtier(?Tranche $tranche, ?Collection $partenaires = null): float
    {
        $montant = 0;

        return $montant;
    }


    public function Cotation_getMontant_prime_payable_par_client(?Cotation $cotation): float
    {
        $montant = 0;

        return $montant;
    }


    public function Cotation_getMontant_commission_payable_par_assureur(?Cotation $cotation, ?Collection $typesrevenus = null): float
    {
        $montant = 0;
        $primeTotal = 0;
        if ($cotation) {
            foreach ($cotation->getRevenus() as $revenu) {
                /** @var RevenuPourCourtier $revenuPourCourtier*/
                $revenuPourCourtier = $revenu;

                /** @var TypeRevenu $typeRevenu */
                $typeRevenu = $revenuPourCourtier->getTypeRevenu();

                //Uniquement pour les revenus qui sont redevebles à nous par l'assureur
                if ($typeRevenu->getRedevable() == TypeRevenu::REDEVABLE_ASSUREUR) {
                    switch ($typeRevenu->getFormule()) {
                        case TypeRevenu::FORMULE_POURCENTAGE_FRONTING:
                            # code...
                            break;

                        case TypeRevenu::FORMULE_POURCENTAGE_PRIME_NETTE:
                            # code...
                            break;

                        case TypeRevenu::FORMULE_POURCENTAGE_PRIME_TOTALE:
                            # code...
                            break;

                        default:
                            # code...
                            break;
                    }
                }
                dd($revenuPourCourtier, $typeRevenu);
            }
        }
        dd("Je dois calculer ici la commission payable par l'assureur dans cette proposition");
        return $montant;
    }

    public function Cotation_getMontant_commission_payable_par_client(?Cotation $cotation, ?Collection $typesrevenus = null): float
    {
        $montant = 0;

        return $montant;
    }

    public function Cotation_getMontant_taxe_payable_par_courtier(?Cotation $cotation, ?Collection $autoritesFiscales = null): float
    {
        $montant = 0;

        return $montant;
    }

    public function Cotation_getMontant_retrocommissions_payable_par_courtier(?Cotation $cotation, ?Collection $partenaires = null): float
    {
        $montant = 0;

        return $montant;
    }

    public function Note_getMontant_payable(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                if ($article->getTranche()) {
                    /** @var Tranche $tranche */
                    $tranche = $article->getTranche();
                    if ($tranche->getCotation()) {
                        /** @var Cotation $cotation */
                        $cotation = $tranche->getCotation();

                        switch ($note->getAddressedTo()) {
                            case Note::TO_ASSUREUR:
                                // dd("On facture à l'assureur les commissions payables par lui-même.");
                                $montant = $this->Cotation_getMontant_commission_payable_par_assureur($cotation);
                                break;

                            case Note::TO_CLIENT:
                                dd("On facture au client les frais de gestion payables par lui-même.");
                                $montant = $this->Cotation_getMontant_commission_payable_par_client($cotation);
                                break;

                            case Note::TO_PARTENAIRE:
                                dd("Le partenaire nous facture les retrocommissions payable par nous.");
                                $montant = $this->Cotation_getMontant_retrocommissions_payable_par_courtier($cotation);
                                break;

                            case Note::TO_AUTORITE_FISCALE:
                                dd("L'autorité fiscale nous facture nous factures ses taxes auxquelles nous sommes redevables.");
                                $montant = $this->Cotation_getMontant_taxe_payable_par_courtier($cotation);
                                break;

                            default:
                                # code...
                                break;
                        }
                    }
                    $montant = $montant * $tranche->getPourcentage();
                }
            }
        }
        return $montant;
    }
}
