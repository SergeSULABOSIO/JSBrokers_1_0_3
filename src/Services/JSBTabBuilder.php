<?php

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use App\Entity\Utilisateur;
use App\Constantes\Constante;
use App\Entity\ReportSet\TaskReportSet;
use App\Entity\ReportSet\ClaimReportSet;
use App\Entity\ReportSet\InsurerReportSet;
use App\Entity\ReportSet\PartnerReportSet;
use App\Entity\ReportSet\RenewalReportSet;
use App\Entity\ReportSet\CashflowReportSet;
use Symfony\Bundle\SecurityBundle\Security;
use App\Entity\ReportSet\Top20ClientReportSet;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Contracts\Translation\TranslatorInterface;

class JSBTabBuilder
{

    public function __construct(
        private Security $security,
        private ServiceDates $serviceDates,
        private TranslatorInterface $translatorInterface,
        private Constante $constante,
    ) {}

    public function newTabProductionPerInsurerPerMonth(): array
    {
        $data = $this->constante->Entreprise_getDataTabProductionPerInsurerPerMonth();
        return $data;
    }

    public function newTabProductionPerPartnerPerMonth(): array
    {
        $data = $this->constante->Entreprise_getDataTabProductionPerPartnerPerMonth();
        return $data;
    }

    public function newTabTop20Clients(): array
    {
        $data = $this->constante->Entreprise_getDataTabTop20Clients();
        return $data;
    }


    public function newTabTasks(): array
    {
        $data = $this->constante->Entreprise_getDataTabTasks();
        return $data;
    }


    public function newTabRenewals(): array
    {
        $data = $this->constante->Entreprise_getDataTabRenewals();
        // dd($data);
        return $data;
    }


    public function newTabClaims(): array
    {
        $data = $this->constante->Entreprise_getDataTabClaims();
        // dd($data);
        return $data;

        // $tabInsurers = [
        //     "SFA Congo",
        //     "ACTIVA",
        //     "SUNU",
        //     "MAYFAIR",
        //     "RAWSUR",
        //     "GPA"
        // ];

        // $tabClients = [
        //     "GLENCORE / KCC",
        //     "GLENCORE / MUMI",
        //     "IVANHOE / KAMOA",
        //     "ERG / METALKOL",
        //     "ERG / BOSS MINING",
        //     "ERG / FRONTIER",
        //     "ERG / COMIDE",
        //     "TENKE FUNGURUME",
        //     "CHEMAF / ETOILE",
        //     "CHEMAF / MUTOSHI",
        //     "RUASHI MINING",
        //     "TRANS AIR CARGO",
        //     "KIBALI GOLD MINES",
        //     "CAA RDC SA",
        //     "LEREXCOM",
        //     "BGFIBANK",
        //     "GSA",
        //     "MALU AVIATION",
        //     "STANDARD BANK",
        //     "HELIOS TOWERS",
        // ];

        // $tabUsersAM = [
        //     (new Utilisateur())
        //         ->setNom("SERGE SULA")
        //         ->setEmail("ssula@gmail.com"),
        //     (new Utilisateur())
        //         ->setNom("ANDY SAMBI")
        //         ->setEmail("asambi@gmail.com"),
        //     (new Utilisateur())
        //         ->setNom("JULIEN MVUMU")
        //         ->setEmail("jmpukuta@gmail.com"),
        // ];

        // $tabCovers = [
        //     "Motor TPL",
        //     "Motor Comp",
        //     "GPA",
        //     "PI",
        //     "GL",
        //     "BBB",
        //     "PDBI",
        //     "GIT"
        // ];

        // $tabClaims = [
        //     "W12457878-CLM-2024",
        //     "012457555-CLM-2024",
        //     "012457525-CLM-2024",
        //     "012457556-CLM-2024",
        //     "012457546-CLM-2024",
        //     "012400000-CLM-2024"
        // ];

        // $tabPolicies = [
        //     "W12457878-200-2024",
        //     "012457878-200-2024",
        //     "012457555-200-2024",
        //     "012457525-125-2024",
        //     "012457556-222-2024",
        //     "012457546-222-2024",
        //     "012400000-222-2024"
        // ];

        // $tabClaimStatus = [
        //     "Processing...",
        //     "Settled",
        //     "Cancelled",
        //     "Awaiting docs."
        // ];

        // $tabReportSets = [];
        // $number = 1;
        // foreach ($tabClaims as $claim_reference) {
        //     $dataSet = (new ClaimReportSet())
        //         ->setType(ClaimReportSet::TYPE_ELEMENT)
        //         ->setCurrency_code("$")
        //         ->setNumber($number)
        //         ->setPolicy_reference($tabPolicies[rand(0, count($tabPolicies) - 1)])
        //         ->setInsurer($tabInsurers[rand(0, count($tabInsurers) - 1)])
        //         ->setClient($tabClients[rand(0, count($tabClients) - 1)])
        //         ->setCover($tabCovers[rand(0, count($tabCovers) - 1)])
        //         ->setGw_premium(rand(1000, 100000))
        //         ->setPolicy_limit(rand(1000, 100000))
        //         ->setPolicy_deductible(rand(10, 1000))
        //         ->setEffect_date(new DateTimeImmutable("now - 20 days"))
        //         ->setExpiry_date(new DateTimeImmutable("now + 200 days"))
        //         ->setClaim_reference($claim_reference)
        //         ->setVictim($tabClients[rand(0, count($tabClients) - 1)])
        //         ->setClaims_status($tabClaimStatus[rand(0, count($tabClaimStatus) - 1)])
        //         ->setAccount_manager($tabUsersAM[rand(0, count($tabUsersAM) - 1)])
        //         ->setDamage_cost(rand(1000, 100000))
        //         // ->setCompensation_paid(rand(0, 100000))
        //         // ->setCompensation_balance(rand(0, 100000))
        //         ->setNotification_date(new DateTimeImmutable("now - " . (rand(0, 30)) . " days"))
        //         ->setSettlement_date(new DateTimeImmutable("now - " . (rand(0, 3)) . " days"));

        //     $dataSet->setCompensation_paid(rand(0, ($dataSet->getDamage_cost())));
        //     $dataSet->setCompensation_balance($dataSet->getDamage_cost() - $dataSet->getCompensation_paid());

        //     $speed_settlement_days = $this->serviceDates->daysEntre($dataSet->getNotification_date(), $dataSet->getSettlement_date());
        //     $days_past = $this->serviceDates->daysEntre($dataSet->getNotification_date(), new DateTimeImmutable("now"));

        //     $dataSet->setCompensation_speed("Done in " . $speed_settlement_days . " days.");
        //     $dataSet->setDays_passed($days_past . " days passed since the notification date.");
        //     $dataSet->setBg_color("bg-secondary");

        //     // $dataSet->setBg_color("text-bg-danger");

        //     $tabReportSets[] = $dataSet;
        //     $number++;
        // }

        // //Ligne totale
        // $dataSet = (new ClaimReportSet())
        //     ->setType(ClaimReportSet::TYPE_TOTAL)
        //     ->setCurrency_code("$")
        //     ->setPolicy_reference("TOTAL")
        //     ->setDamage_cost(rand(1000, 100000))
        //     ->setCompensation_paid(rand(1000, 10000))
        //     ->setCompensation_balance(rand(1000, 10000));

        // $tabReportSets[] = $dataSet;
        // // dd($tabReportSets);

        // return $tabReportSets;
        // return [];
    }


    public function newTabCashflow(): array
    {
        $tabDebtors = [
            "SFA Congo",
            "ACTIVA",
            "SUNU",
            "MAYFAIR",
            "RAWSUR",
            "KAMOA COPPER COMPANY",
            "BGFIBANK RDC SA",
        ];

        $tabUsers = [
            (new Utilisateur())
                ->setNom("SERGE SULA")
                ->setEmail("ssula@gmail.com"),
            (new Utilisateur())
                ->setNom("ANDY SAMBI")
                ->setEmail("asambi@gmail.com"),
            (new Utilisateur())
                ->setNom("JULIEN MVUMU")
                ->setEmail("jmpukuta@gmail.com"),
        ];

        $tabDescriptions = [
            "Com - Janvier 2024",
            "Com - Février 2024",
            "Com - Mars 2024",
            "Com - Avril 2024",
            "Com - Mais 2024",
            "Com - Juin 2024",
            "Com - Juin 2024",
            "Com - Sept 2024",
            "Frais de gestion - Santé PATH",
            "Frais de gestion - Life PATH",
            "Frais de gestion - Asset KAMOA",
            "Frais de gestion - PDBI IVANHOE"
        ];

        $tabReferences = [
            "W12457878-200-2024",
            "012457878-200-2024",
            "012457555-200-2024",
            "012457525-125-2024",
            "012457556-222-2024",
            "012457546-222-2024",
            "012400000-222-2024"
        ];

        $tabStatus = [
            "Awaiting for POP",
            "Settled"
        ];

        $tabReportSets = [];
        $index = 1;
        foreach ($tabReferences as $invoice_reference) {
            $dataSet = (new CashflowReportSet())
                ->setIndex($index)
                ->setType(CashflowReportSet::TYPE_ELEMENT)
                ->setCurrency_code("$")
                ->setDescription($tabDescriptions[rand(0, count($tabDescriptions) - 1)])
                ->setDebtor($tabDebtors[rand(0, count($tabDebtors) - 1)])
                ->setStatus($tabStatus[rand(0, count($tabStatus) - 1)])
                ->setInvoice_reference($invoice_reference)
                ->setNet_amount(rand(1000, 100000))
                ->setTaxes(rand(100, 10000))
                // ->setGross_due(rand(1000, 100000))
                // ->setAmount_paid(rand(1000, 100000))
                // ->setBalance_due(rand(0, 100))
                ->setUser($tabUsers[rand(0, count($tabUsers) - 1)])
                ->setDate_submition(new DateTimeImmutable("now - " . (rand(2, 30)) . " days"))
                ->setDate_payment(new DateTimeImmutable("now -" . (rand(0, 1)) . " days"));

            $dataSet->setGross_due($dataSet->getNet_amount() + $dataSet->getTaxes());
            $dataSet->setAmount_paid(rand(0, $dataSet->getGross_due()));
            $dataSet->setBalance_due($dataSet->getGross_due() - $dataSet->getAmount_paid());
            $days = $this->serviceDates->daysEntre(new DateTimeImmutable("now"), $dataSet->getDate_submition());
            $dataSet->setDays_passed("The invoice was submitted " . $days .  " days ago to " . $dataSet->getDebtor());

            $tabReportSets[] = $dataSet;
            $index++;
        }

        //Ligne totale
        $dataSet = (new CashflowReportSet())
            ->setType(CashflowReportSet::TYPE_TOTAL)
            ->setCurrency_code("$")
            ->setDescription("TOTAL")
            ->setNet_amount(rand(1000, 100000))
            ->setTaxes(rand(1000, 100000))
            ->setGross_due(rand(1000, 100000))
            ->setAmount_paid(rand(1000, 100000))
            ->setBalance_due(rand(1000, 10000));

        $tabReportSets[] = $dataSet;
        // dd($tabReportSets);

        return $tabReportSets;
    }

    public function getProductionTabs(): array
    { //
        return [
            'tabProductionPerInsurerPerMonth' => $this->newTabProductionPerInsurerPerMonth(),
            'tabProductionPerPartnerPerMonth' => $this->newTabProductionPerPartnerPerMonth(),
            'tabTop20Clients' => $this->newTabTop20Clients(),
            'tabTasks' => $this->newTabTasks(),
            'tabRenewals' => $this->newTabRenewals(),
            'tabClaims' => $this->newTabClaims(),
            'tabCashflow' => $this->newTabCashflow(),
        ];
    }
}
