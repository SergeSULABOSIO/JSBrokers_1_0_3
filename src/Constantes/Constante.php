<?php

namespace App\Constantes;

use App\Entity\Note;
use App\Entity\Risque;
use DateTimeImmutable;
use App\Entity\Avenant;
use App\Entity\Tranche;
use App\Entity\Cotation;
use App\Entity\Paiement;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use App\Services\ServiceMonnaies;
use App\Repository\NoteRepository;
use App\Repository\TaxeRepository;
use App\Repository\ModelePieceSinistreRepository;
use App\Repository\InviteRepository;
use App\Repository\ArticleRepository;
use App\Repository\CotationRepository;
use App\Repository\PartenaireRepository;
use App\Repository\UtilisateurRepository;
use Symfony\Bundle\SecurityBundle\Security;
use App\Repository\AutoriteFiscaleRepository;
use App\Repository\RevenuPourCourtierRepository;
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

}
