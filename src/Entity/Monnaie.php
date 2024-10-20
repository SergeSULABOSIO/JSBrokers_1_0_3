<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\MonnaieRepository;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MonnaieRepository::class)]
class Monnaie
{

    public const TAB_MONNAIE_MONNAIE_LOCALE = [
        'Non' => 0,
        'Oui' => 1
    ];

    public const FONCTION_SAISIE_ET_AFFICHAGE = "Saisie et Affichage";
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
        "AFN - Afghan afghani" => "AFN",
        "ALL - Albanian lek" => "ALL",
        "DZD - Algerian dinar" => "DZD",
        "AOA - Angolan kwanza" => "AOA",
        "ARS - Argentine peso" => "ARS",
        "AMD - Armenian dram" => "AMD",
        "AWG - Aruban florin" => "AWG",
        "AUD - Australian dollar" => "AUD",
        "AZN - Azerbaijani manat" => "AZN",
        "BSD - Bahamian dollar" => "BSD",
        "BHD - Bahraini dinar" => "BHD",
        "BDT - Bangladeshi taka" => "BDT",
        "BBD - Barbados dollar" => "BBD",
        "BYN - Belarusian ruble" => "BYN",
        "BZD - Belize dollar" => "BZD",
        "BMD - Bermudian dollar" => "BMD",
        "BTN - Bhutanese ngultrum" => "BTN",
        "BOV - Bolivian Mvdol (funds code)" => "BOV",
        "BOB - Boliviano" => "BOB",
        "BAM - Bosnia and Herzegovina convertible mark" => "BAM",
        "BWP - Botswana pula" => "BWP",
        "BRL - Brazilian real" => "BRL",
        "BND - Brunei dollar" => "BND",
        "BGN - Bulgarian lev" => "BGN",
        "BIF - Burundian franc" => "BIF",
        "KHR - Cambodian riel" => "KHR",
        "CAD - Canadian dollar" => "CAD",
        "CVE - Cape Verdean escudo" => "CVE",
        "KYD - Cayman Islands dollar" => "KYD",
        "XOF - CFA franc BCEAO" => "XOF",
        "XAF - CFA franc BEAC" => "XAF",
        "XPF - CFP franc (franc Pacifique)" => "XPF",
        "CLP - Chilean peso" => "CLP",
        "XTS - Code reserved for testing" => "XTS",
        "COP - Colombian peso" => "COP",
        "KMF - Comoro franc" => "KMF",
        "CDF - Congolese franc" => "CDF",
        "CRC - Costa Rican colon" => "CRC",
        "CUC - Cuban convertible peso" => "CUC",
        "CUP - Cuban peso" => "CUP",
        "CZK - Czech koruna" => "CZK",
        "DKK - Danish krone" => "DKK",
        "DJF - Djiboutian franc" => "DJF",
        "DOP - Dominican peso" => "DOP",
        "XCD - East Caribbean dollar" => "XCD",
        "EGP - Egyptian pound" => "EGP",
        "ERN - Eritrean nakfa" => "ERN",
        "ETB - Ethiopian birr" => "ETB",
        "EUR - Euro" => "EUR",
        "XBA - European Composite Unit (EURCO) (bond market unit)" => "XBA",
        "XBB - European Monetary Unit (E.M.U.-6) (bond market unit)" => "XBB",
        "XBD - European Unit of Account 17 (E.U.A.-17) (bond market unit)" => "XBD",
        "XBC - European Unit of Account 9 (E.U.A.-9) (bond market unit)" => "XBC",
        "FKP - Falkland Islands pound" => "FKP",
        "FJD - Fiji dollar" => "FJD",
        "GMD - Gambian dalasi" => "GMD",
        "GEL - Georgian lari" => "GEL",
        "GHS - Ghanaian cedi" => "GHS",
        "GIP - Gibraltar pound" => "GIP",
        "XAU - Gold (one troy ounce)" => "XAU",
        "GTQ - Guatemalan quetzal" => "GTQ",
        "GNF - Guinean franc" => "GNF",
        "GYD - Guyanese dollar" => "GYD",
        "HTG - Haitian gourde" => "HTG",
        "HNL - Honduran lempira" => "HNL",
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
        "JOD - Jordanian dinar" => "JOD",
        "KZT - Kazakhstani tenge" => "KZT",
        "KES - Kenyan shilling" => "KES",
        "KWD - Kuwaiti dinar" => "KWD",
        "KGS - Kyrgyzstani som" => "KGS",
        "LAK - Lao kip" => "LAK",
        "LBP - Lebanese pound" => "LBP",
        "LSL - Lesotho loti" => "LSL",
        "LRD - Liberian dollar" => "LRD",
        "LYD - Libyan dinar" => "LYD",
        "MOP - Macanese pataca" => "MOP",
        "MKD - Macedonian denar" => "MKD",
        "MGA - Malagasy ariary" => "MGA",
        "MWK - Malawian kwacha" => "MWK",
        "MYR - Malaysian ringgit" => "MYR",
        "MVR - Maldivian rufiyaa" => "MVR",
        "MRU - Mauritanian ouguiya" => "MRU",
        "MUR - Mauritian rupee" => "MUR",
        "MXN - Mexican peso" => "MXN",
        "MXV - Mexican Unidad de Inversion (UDI) (funds code)" => "MXV",
        "MDL - Moldovan leu" => "MDL",
        "MNT - Mongolian tögrög" => "MNT",
        "MAD - Moroccan dirham" => "MAD",
        "MZN - Mozambican metical" => "MZN",
        "MMK - Myanmar kyat" => "MMK",
        "NAD - Namibian dollar" => "NAD",
        "NPR - Nepalese rupee" => "NPR",
        "ANG - Netherlands Antillean guilder" => "ANG",
        "TWD - New Taiwan dollar" => "TWD",
        "NZD - New Zealand dollar" => "NZD",
        "NIO - Nicaraguan córdoba" => "NIO",
        "NGN - Nigerian naira" => "NGN",
        "XXX - No currency" => "XXX",
        "KPW - North Korean won" => "KPW",
        "NOK - Norwegian krone" => "NOK",
        "OMR - Omani rial" => "OMR",
        "PKR - Pakistani rupee" => "PKR",
        "XPD - Palladium (one troy ounce)" => "XPD",
        "PAB - Panamanian balboa" => "PAB",
        "PGK - Papua New Guinean kina" => "PGK",
        "PYG - Paraguayan guaraní" => "PYG",
        "PEN - Peruvian sol" => "PEN",
        "PHP - Philippine peso[10]" => "PHP",
        "XPT - Platinum (one troy ounce)" => "XPT",
        "PLN - Polish złoty" => "PLN",
        "GBP - Pound sterling" => "GBP",
        "QAR - Qatari riyal" => "QAR",
        "CNY - Renminbi[11]" => "CNY",
        "RON - Romanian leu" => "RON",
        "RUB - Russian ruble" => "RUB",
        "RWF - Rwandan franc" => "RWF",
        "SHP - Saint Helena pound" => "SHP",
        "SVC - Salvadoran colón" => "SVC",
        "WST - Samoan tala" => "WST",
        "SAR - Saudi riyal" => "SAR",
        "RSD - Serbian dinar" => "RSD",
        "SCR - Seychelles rupee" => "SCR",
        "SLE - Sierra Leonean leone (new leone)[12][13][14]" => "SLE",
        "SLL - Sierra Leonean leone (old leone)[12][13][14][15]" => "SLL",
        "XAG - Silver (one troy ounce)" => "XAG",
        "SGD - Singapore dollar" => "SGD",
        "SBD - Solomon Islands dollar" => "SBD",
        "SOS - Somali shilling" => "SOS",
        "ZAR - South African rand" => "ZAR",
        "KRW - South Korean won" => "KRW",
        "SSP - South Sudanese pound" => "SSP",
        "XDR - Special drawing rights" => "XDR",
        "LKR - Sri Lankan rupee" => "LKR",
        "XSU - SUCRE" => "XSU",
        "SDG - Sudanese pound" => "SDG",
        "SRD - Surinamese dollar" => "SRD",
        "SZL - Swazi lilangeni" => "SZL",
        "SEK - Swedish krona (plural: kronor)" => "SEK",
        "CHF - Swiss franc" => "CHF",
        "SYP - Syrian pound" => "SYP",
        "TJS - Tajikistani somoni" => "TJS",
        "TZS - Tanzanian shilling" => "TZS",
        "THB - Thai baht" => "THB",
        "TOP - Tongan paʻanga" => "TOP",
        "TTD - Trinidad and Tobago dollar" => "TTD",
        "TND - Tunisian dinar" => "TND",
        "TRY - Turkish lira" => "TRY",
        "TMT - Turkmenistan manat" => "TMT",
        "UGX - Ugandan shilling" => "UGX",
        "UAH - Ukrainian hryvnia" => "UAH",
        "CLF - Unidad de Fomento (funds code)" => "CLF",
        "COU - Unidad de Valor Real (UVR) (funds code)[6]" => "COU",
        "UYW - Unidad previsional[17]" => "UYW",
        "AED - United Arab Emirates dirham" => "AED",
        "USD - United States dollar" => "USD",
        "USN - United States dollar (next day) (funds code)" => "USN",
        "UYI - Uruguay Peso en Unidades Indexadas (URUIURUI) (funds code)" => "UYI",
        "UYU - Uruguayan peso" => "UYU",
        "UZS - Uzbekistan sum" => "UZS",
        "VUV - Vanuatu vatu" => "VUV",
        "VED - Venezuelan digital bolívar[18]" => "VED",
        "VES - Venezuelan sovereign bolívar[10]" => "VES",
        "VND - Vietnamese đồng" => "VND",
        "CHE - WIR euro (complementary currency)" => "CHE",
        "CHW - WIR franc (complementary currency)" => "CHW",
        "YER - Yemeni rial" => "YER",
        "ZMW - Zambian kwacha" => "ZMW",
        "ZWL - Zimbabwean dollar (fifth)[e]" => "ZWL"
    ];

    
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[Assert\NotBlank(message: "Le code ne peut pas être vide.")]
    #[ORM\Column(length: 255)]
    private ?string $code = null;

    #[Assert\NotBlank(message: "Le taux ne peut pas être vide.")]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $tauxusd = null;

    #[ORM\Column]
    private ?int $fonction = null;

    #[ORM\Column]
    private ?bool $locale = null;

    #[ORM\ManyToOne(inversedBy: 'monnaies')]
    private ?Entreprise $entreprise = null;

    public function __construct()
    {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;
        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getTauxusd(): ?string
    {
        return $this->tauxusd;
    }

    public function setTauxusd(string $tauxusd): self
    {
        $this->tauxusd = $tauxusd;
        
        return $this;
    }

    public function __toString()
    {
        return $this->code . " / " . $this->nom;
    }

    public function getFonction(): ?int
    {
        return $this->fonction;
    }

    public function setFonction(int $fonction): self
    {
        $this->fonction = $fonction;
        
        return $this;
    }

    public function isLocale(): ?bool
    {
        return $this->locale;
    }

    public function setLocale(bool $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;

        return $this;
    }
}
