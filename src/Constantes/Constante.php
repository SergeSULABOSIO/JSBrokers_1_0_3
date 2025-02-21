<?php

namespace App\Constantes;

use App\Controller\Admin\RevenuCourtierController;
use App\Entity\Article;
use App\Entity\AutoriteFiscale;
use App\Entity\Avenant;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Repository\ArticleRepository;
use App\Repository\AutoriteFiscaleRepository;
use App\Repository\CotationRepository;
use App\Repository\NoteRepository;
use App\Repository\RevenuPourCourtierRepository;
use App\Repository\TaxeRepository;
use App\Services\ServiceTaxes;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Bundle\SecurityBundle\Security;
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
     * ASSUREUR
     */
    public function Cotation_getAssureur(?Cotation $cotation)
    {
        if ($cotation) {
            if ($cotation->getAssureur()) {
                return $cotation->getAssureur();
            }
        }
        return null;
    }





    /**
     * NOTE - NOTE DE DEBIT OU NOTE DE CREDIT
     */
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
    public function Type_revenu_getMontant_retrocommissions_payable_par_courtier(?TypeRevenu $typeRevenu)
    {
        $tot = 0;
        if ($typeRevenu != null) {
            // dd($typeRevenu->getId());
            if (count($typeRevenu->getRevenuPourCourtiers()) != 0) {
                // dd("Jai du contenu");
                /** @var RevenuPourCourtier $revenu */
                foreach ($typeRevenu->getRevenuPourCourtiers() as $revenu) {
                    $tot += $this->Revenu_getMontant_retrocommissions_payable_par_courtier($revenu);
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
    public function Revenu_getMontant_retrocommissions_payable_par_courtier(?RevenuPourCourtier $revenu): float
    {
        $montant = 0;
        if ($revenu != null) {
            if ($revenu->getCotation() != null) {
                $montant = $this->Cotation_getMontant_retrocommissions_payable_par_courtier($revenu->getCotation());
            }
        }
        return $montant;
    }
    public function Revenu_getMontant_retrocommissions_payable_par_courtier_payee(?RevenuPourCourtier $revenu): float
    {
        return $this->Cotation_getMontant_retrocommissions_payable_par_courtier_payee($revenu->getCotation());
    }
    public function Revenu_getMontant_retrocommissions_payable_par_courtier_solde(?RevenuPourCourtier $revenu)
    {
        $solde = $this->Revenu_getMontant_retrocommissions_payable_par_courtier($revenu) - $this->Revenu_getMontant_retrocommissions_payable_par_courtier_payee($revenu);
        return round($solde, 4);
    }






    /**
     * LE FRAIS ARCA - TAXES PAYABLES PAR LE COURTIER
     */
    public function Tranche_getPostesFacturables(?Tranche $tranche, ?PanierNotes $panier)
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
                                        if ($montant != 0) {
                                            $tabPostesFacturables[] = [
                                                "poste" => $revenu->getNom(),
                                                "addressedTo" => Note::TO_ASSUREUR,
                                                "pourcentage" => $tranche->getPourcentage(),
                                                "montantPayable" => $montant,
                                                "idCible" => $panier->getIdAssureur(),
                                                "idPoste" => $revenu->getId(),
                                                "idNote" => $panier->getIdNote() == null ? -1 : $panier->getIdNote(),
                                                "idTranche" => $tranche->getId(),
                                            ];
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
                                        if ($montant != 0) {
                                            $tabPostesFacturables[] = [
                                                "poste" => $revenu->getNom(),
                                                "addressedTo" => Note::TO_CLIENT,
                                                "pourcentage" => $tranche->getPourcentage(),
                                                "montantPayable" => $montant,
                                                "idCible" => $panier->getIdClient(),
                                                "idPoste" => $revenu->getId(),
                                                "idNote" => $panier->getIdNote() == null ? -1 : $panier->getIdNote(),
                                                // "idNote" => $panier->getIdNote(),
                                                "idTranche" => $tranche->getId(),
                                            ];
                                        }
                                    }
                                }
                            }
                            break;
                        case Note::TO_PARTENAIRE:
                            //On doit s'assurer que la tranche sur la liste est bien celle qui est dans le panier
                            if ($this->Tranche_getPartenaire($tranche)->getId() == $panier->getIdPartenaire()) {
                                $montant = $this->Tranche_getMontant_retrocommissions_payable_par_courtier($tranche);
                                if ($montant != 0) {
                                    $tabPostesFacturables[] = [
                                        "poste" => "Rétrocommission",
                                        "addressedTo" => Note::TO_PARTENAIRE,
                                        "pourcentage" => $tranche->getPourcentage(),
                                        "montantPayable" => $montant,
                                        "idCible" => $panier->getIdPartenaire(),
                                        "idPoste" => $panier->getIdPartenaire(),
                                        "idNote" => $panier->getIdNote() == null ? -1 : $panier->getIdNote(),
                                        // "idNote" => $panier->getIdNote(),
                                        "idTranche" => $tranche->getId(),
                                    ];
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
                                $tabPostesFacturables[] = [
                                    "poste" => $autorite->getTaxe()->getCode(),
                                    "addressedTo" => Note::TO_AUTORITE_FISCALE,
                                    "pourcentage" => $tranche->getPourcentage(),
                                    "montantPayable" => $montant,
                                    "idCible" => $panier->getIdAutoriteFiscale(),
                                    "idPoste" => $autorite->getTaxe()->getId(),
                                    "idNote" => $panier->getIdNote() == null ? -1 : $panier->getIdNote(),
                                    // "idNote" => $panier->getIdNote(),
                                    "idTranche" => $tranche->getId(),
                                ];
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
        return $tabPostesFacturables;
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
    public function Tranche_getMontant_taxe_payable_par_courtier(?Tranche $tranche): float
    {
        $montant = 0;
        if ($tranche != null) {
            if ($tranche->getCotation()) {
                $montant = $this->Cotation_getMontant_taxe_payable_par_courtier($tranche->getCotation()) * $tranche->getPourcentage();
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
    public function Tranche_getMontant_taxe_payable_par_courtier_solde(?Tranche $tranche): float
    {
        $solde = $this->Tranche_getMontant_taxe_payable_par_courtier($tranche) - $this->Tranche_getMontant_taxe_payable_par_courtier_payee($tranche);
        return round($solde, 4);
    }
    public function Cotation_getMontant_taxe_payable_par_courtier(?Cotation $cotation): float
    {
        $net = $this->Cotation_getMontant_commission_payable_par_assureur($cotation) + $this->Cotation_getMontant_commission_payable_par_client($cotation);
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
    public function Cotation_getMontant_taxe_payable_par_courtier_solde(?Cotation $cotation): float
    {
        $solde = $this->Cotation_getMontant_taxe_payable_par_courtier($cotation) - $this->Cotation_getMontant_taxe_payable_par_courtier_payee($cotation);
        return round($solde, 4);
    }






    /**
     * LA TVA - TAXES PAYABLES PAR L'ASSUREUR
     */
    public function Tranche_getMontant_taxe_payable_par_assureur(?Tranche $tranche): float
    {
        $montant = 0;
        if ($tranche != null) {
            if ($tranche->getCotation()) {
                $montant = $this->Cotation_getMontant_taxe_payable_par_assureur($tranche->getCotation()) * $tranche->getPourcentage();
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
    public function Tranche_getMontant_taxe_payable_par_assureur_solde(?Tranche $tranche): float
    {
        $solde = $this->Tranche_getMontant_taxe_payable_par_assureur($tranche) - $this->Tranche_getMontant_taxe_payable_par_assureur_payee($tranche);
        return $solde;
    }
    public function Cotation_getMontant_taxe_payable_par_assureur(?Cotation $cotation): float
    {
        $net = $this->Cotation_getMontant_commission_payable_par_assureur($cotation) + $this->Cotation_getMontant_commission_payable_par_client($cotation);
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
    public function Cotation_getMontant_taxe_payable_par_assureur_solde(?Cotation $cotation): float
    {
        $solde = $this->Cotation_getMontant_taxe_payable_par_assureur($cotation) - $this->Cotation_getMontant_taxe_payable_par_assureur_payee($cotation);
        return round($solde, 4);
    }




    /**
     * LES COMMISSIONS
     */
    public function Tranche_getMontant_commission_pure(?Tranche $tranche): float
    {
        $montant = 0;
        if ($tranche != null) {
            if ($tranche->getCotation()) {
                $montant = $this->Cotation_getMontant_commission_pure($tranche->getCotation()) * $tranche->getPourcentage();
            }
        }
        return $montant;
    }
    public function Tranche_getMontant_commission_ttc(?Tranche $tranche, ?int $addressedTo = -1): float
    {
        $montant = 0;
        if ($tranche != null) {
            if ($tranche->getCotation()) {
                $montant = $this->Cotation_getMontant_commission_ttc($tranche->getCotation(), $addressedTo) * $tranche->getPourcentage();
            }
        }
        return $montant;
    }
    public function Tranche_getMontant_commission_ht(?Tranche $tranche): float
    {
        $montant = 0;
        if ($tranche != null) {
            if ($tranche->getCotation()) {
                $montant = $this->Cotation_getMontant_commission_ht($tranche->getCotation()) * $tranche->getPourcentage();
            }
        }
        return $montant;
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
    public function Cotation_getMontant_commission_pure(?Cotation $cotation): float
    {
        $comHT = $this->Cotation_getMontant_commission_ht($cotation);
        $taxeCourtier = $this->Cotation_getMontant_taxe_payable_par_courtier($cotation);
        return $comHT - $taxeCourtier;
    }
    public function Cotation_getMontant_commission_ht(?Cotation $cotation): float
    {
        $comTTC = $this->Cotation_getMontant_commission_ttc($cotation);
        $taxeAssureur = $this->Cotation_getMontant_taxe_payable_par_assureur($cotation);
        return $comTTC - $taxeAssureur;
    }
    public function Tranche_getMontant_commission_ttc_solde(?Tranche $tranche): float
    {
        $solde = $this->Tranche_getMontant_commission_ttc($tranche) - $this->Tranche_getMontant_commission_ttc_collectee($tranche);
        return $solde;
    }
    public function Cotation_getMontant_commission_ttc_solde(?Cotation $cotation): float
    {
        $solde = $this->Cotation_getMontant_commission_ttc($cotation) - $this->Cotation_getMontant_commission_ttc_collectee($cotation);
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
    public function Cotation_getMontant_commission_ttc(?Cotation $cotation, ?int $addressedTo = -1): float
    {
        switch ($addressedTo) {
            case Note::TO_ASSUREUR:
                return $this->Cotation_getMontant_commission_ttc_payable_par_assureur($cotation);
                break;
            case Note::TO_CLIENT:
                return $this->Cotation_getMontant_commission_ttc_payable_par_client($cotation);
                break;

            default:
                $comTTCAssureur = $this->Cotation_getMontant_commission_ttc_payable_par_assureur($cotation);
                $comTTCClient = $this->Cotation_getMontant_commission_ttc_payable_par_client($cotation);
                return $comTTCAssureur + $comTTCClient;
                break;
        }
    }
    public function Cotation_getMontant_commission_ttc_payable_par_client(?Cotation $cotation): float
    {
        $net = $this->Cotation_getMontant_commission_payable_par_client($cotation);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true) + $net;
    }
    public function Cotation_getMontant_commission_ttc_payable_par_assureur(?Cotation $cotation): float
    {
        $net = $this->Cotation_getMontant_commission_payable_par_assureur($cotation);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true) + $net;
    }
    public function Cotation_getMontant_commission_payable_par_client(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            //Pour chaque revenu configuré dans cette cotation
            foreach ($cotation->getRevenus() as $revenu) {
                /** @var RevenuPourCourtier $revenuPourCourtier*/
                $revenuPourCourtier = $revenu;

                /** @var TypeRevenu $typeRevenu */
                $typeRevenu = $revenuPourCourtier->getTypeRevenu();

                //Uniquement pour les revenus qui sont redevebles à nous par l'assureur
                if ($typeRevenu->getRedevable() == TypeRevenu::REDEVABLE_CLIENT) {
                    $montant += $this->Cotation_getMontant_commission($typeRevenu, $revenuPourCourtier, $cotation);
                }
            }
        }
        // dd("Je dois calculer ici la commission payable par l'assureur dans cette proposition");
        return $montant;
    }
    public function Cotation_getMontant_commission_payable_par_assureur(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            //Pour chaque revenu configuré dans cette cotation
            foreach ($cotation->getRevenus() as $revenu) {
                /** @var RevenuPourCourtier $revenuPourCourtier*/
                $revenuPourCourtier = $revenu;

                /** @var TypeRevenu $typeRevenu */
                $typeRevenu = $revenuPourCourtier->getTypeRevenu();

                //Uniquement pour les revenus qui sont redevebles à nous par l'assureur
                if ($typeRevenu->getRedevable() == TypeRevenu::REDEVABLE_ASSUREUR) {
                    $montant += $this->Cotation_getMontant_commission($typeRevenu, $revenuPourCourtier, $cotation);
                }
            }
        }
        // dd("Je dois calculer ici la commission payable par l'assureur dans cette proposition");
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
    private function Cotation_appliquerTauxRetrocomPartenaire(?Partenaire $partenaire, ?Cotation $cotation)
    {
        $montant = 0;
        if (count($partenaire->getConditionPartages()) != 0) {
            //On traite les conditions spéciales attachées au partenaire
            $montant = $this->appliquerConditions($partenaire->getConditionPartages()[0], $cotation);
        } else if ($partenaire->getPart() != 0) {
            $montant = $this->Cotation_getMontant_commission_pure($cotation) * $partenaire->getPart();
        }
        return $montant;
    }
    public function Cotation_getMontant_retrocommissions_payable_par_courtier(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation->getPiste()) {
            //On cherche à appliquer les conditions de partage attachées à la piste
            if (count($cotation->getPiste()->getPartenaires()) != 0) {
                /** @var Partenaire $partenaire */
                $partenaire = $cotation->getPiste()->getPartenaires()[0];
                if ($partenaire) {
                    if (count($cotation->getPiste()->getConditionsPartageExceptionnelles()) != 0) {
                        //On traite les conditions spéciale attachées à la piste
                        $montant = $this->appliquerConditions($cotation->getPiste()->getConditionsPartageExceptionnelles()[0], $cotation);
                    } else {
                        $montant = $this->Cotation_appliquerTauxRetrocomPartenaire($partenaire, $cotation);
                    }
                }
            } else if (count($cotation->getPiste()->getClient()->getPartenaires()) != 0) {
                /** @var Partenaire $partenaire */
                $partenaire = $cotation->getPiste()->getClient()->getPartenaires()[0];
                $montant = $this->Cotation_appliquerTauxRetrocomPartenaire($partenaire, $cotation);
            }
        }
        return $montant;
    }
    public function Tranche_getMontant_retrocommissions_payable_par_courtier(?Tranche $tranche): float
    {
        $montant = 0;
        if ($tranche != null) {
            if ($tranche->getCotation() != null) {
                $montant = $this->Cotation_getMontant_retrocommissions_payable_par_courtier($tranche->getCotation()) * $tranche->getPourcentage();
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
    public function RevenuPourCourtier_isBound(?RevenuPourCourtier $revenuPourCourtier): bool
    {
        if ($revenuPourCourtier != null) {
            if ($revenuPourCourtier->getCotation() != null) {
                return $revenuPourCourtier->getCotation()->getAvenants()[0] != null || count($revenuPourCourtier->getCotation()->getAvenants()) != 0;
            }
        }
    }
    private function Cotation_getSommeCommissionPureRisque(?Cotation $cotation): float
    {
        $somme = 0;
        /** @var Entreprise $entreprise */
        $entreprise = $cotation->getPiste()->getInvite()->getEntreprise();
        $cotationsDuPartenaire = $this->cotationRepository->loadCotationsWithPartnerRisque($cotation->getPiste()->getExercice(), $entreprise, $cotation->getPiste()->getRisque(), $this->Cotation_getPartenaire($cotation));
        foreach ($cotationsDuPartenaire as $proposition) {
            $somme += $this->Cotation_getMontant_commission_pure($proposition);
        }
        return $somme;
    }
    private function Cotation_getSommeCommissionPureClient(?Cotation $cotation): float
    {
        // dd($cotation->getAvenants()[0]);
        // dd("Unité de mésure: ", $cotation);

        $somme = 0;
        /** @var Entreprise $entreprise */
        $entreprise = $cotation->getPiste()->getInvite()->getEntreprise();
        $cotationsDuPartenaire = $this->cotationRepository->loadCotationsWithPartnerClient($cotation->getPiste()->getExercice(), $entreprise, $cotation->getPiste()->getClient(), $this->Cotation_getPartenaire($cotation));
        foreach ($cotationsDuPartenaire as $proposition) {
            $somme += $this->Cotation_getMontant_commission_pure($proposition);
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
    private function appliquerConditions(?ConditionPartage $conditionPartage, ?Cotation $cotation): float
    {
        $montant = 0;
        //Assiette de l'affaire individuelle
        $assiette_commission_pure = $this->Cotation_getMontant_commission_pure($cotation);
        // dd("Je suis ici ", $assiette_commission_pure);

        //Application de l'unité de mésure
        $uniteMesure = match ($conditionPartage->getUniteMesure()) {
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE => $this->Cotation_getSommeCommissionPureRisque($cotation),
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT => $this->Cotation_getSommeCommissionPureClient($cotation),
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
    public function Cotation_getMontant_retrocommissions_payable_par_courtier_payee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation != null) {
            /** @var Tranche $tranche */
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->Tranche_getMontant_retrocommissions_payable_par_courtier_payee($tranche);
            }
        }
        return $montant;
    }
    public function Tranche_getMontant_retrocommissions_payable_par_courtier_payee(?Tranche $tranche): float
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
                if ($note->getAddressedTo() == Note::TO_PARTENAIRE) {
                    $montant += $proportionPaiement * $article->getMontant();
                }
            }
        }
        return $montant;
    }
    public function Cotation_getMontant_retrocommissions_payable_par_courtier_solde(?Cotation $cotation): float
    {
        $retrocom = $this->Cotation_getMontant_retrocommissions_payable_par_courtier($cotation);
        $retrocom_paye = $this->Cotation_getMontant_retrocommissions_payable_par_courtier_payee($cotation);
        return round($retrocom - $retrocom_paye, 4);
    }
    public function Tranche_getMontant_retrocommissions_payable_par_courtier_solde(?Tranche $tranche): float
    {
        $retrocom = $this->Tranche_getMontant_retrocommissions_payable_par_courtier($tranche);
        $retrocom_paye = $this->Tranche_getMontant_retrocommissions_payable_par_courtier_payee($tranche);
        return round($retrocom - $retrocom_paye, 4);
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
}
