<?php

namespace App\Services;

use App\Entity\ReportSet\ClaimReportSet;
use App\Entity\ReportSet\InsurerReportSet;
use App\Entity\ReportSet\PartnerReportSet;
use App\Entity\ReportSet\RenewalReportSet;
use App\Entity\ReportSet\TaskReportSet;
use App\Entity\ReportSet\Top20ClientReportSet;
use App\Entity\Utilisateur;
use DateInterval;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraints\Range;

class JSBTabBuilder
{

    public function __construct(
        private Security $security,
        private ServiceDates $serviceDates,
    ) {}

    public function newTabProductionPerInsurerPerMonth(): array
    {
        $tabAssureurs = [
            "SFA Congo",
            "SUNU Assurance IARD",
            "RAWSUR SA",
            "ACTIVA",
            "MAYFAIR",
        ];
        $tabReportSets = [];
        for ($m = 1; $m <= 12; $m++) {
            $month = date('F', mktime(0, 0, 0, $m, 1, date('Y')));
            // echo $month . '<br>';
            $datasetMois = (new InsurerReportSet())
                ->setType(PartnerReportSet::TYPE_SUBTOTAL)
                ->setCurrency_code("$")
                ->setLabel($month)
                ->setGw_premium(rand(1, 100000000))
                ->setNet_com(rand(1, 100000000))
                ->setTaxes(rand(1, 100000000))
                ->setGros_commission(rand(1, 100000000))
                ->setCommission_received(rand(1, 100000000))
                ->setBalance_due(rand(1, 100000000));

            $tabReportSets[] = $datasetMois;
            foreach ($tabAssureurs as $assureur) {
                $datasetAssureur = (new InsurerReportSet())
                    ->setType(PartnerReportSet::TYPE_ELEMENT)
                    ->setCurrency_code("$")
                    ->setLabel($assureur)
                    ->setGw_premium(rand(1, 100000000))
                    ->setNet_com(rand(1, 100000000))
                    ->setTaxes(rand(1, 100000000))
                    ->setGros_commission(rand(1, 100000000))
                    ->setCommission_received(rand(1, 100000000))
                    ->setBalance_due(rand(1, 100000000));

                $tabReportSets[] = $datasetAssureur;
            }
        }
        $datasetTotal = (new InsurerReportSet())
            ->setType(PartnerReportSet::TYPE_TOTAL)
            ->setCurrency_code("$")
            ->setLabel("TOTAL")
            ->setGw_premium(rand(1, 100000000))
            ->setNet_com(rand(1, 100000000))
            ->setTaxes(rand(1, 100000000))
            ->setGros_commission(rand(1, 100000000))
            ->setCommission_received(rand(1, 100000000))
            ->setBalance_due(rand(1, 100000000));

        $tabReportSets[] = $datasetTotal;
        // dd($tabReportSets);

        return $tabReportSets;
    }

    public function newTabProductionPerPartnerPerMonth(): array
    {
        $tabPartners = [
            "MARSH",
            "AFINBRO",
            "AGL",
            "O'NEILS",
            "OLEA",
            "MONT-BLANC",
            "WP BROKERS",
        ];

        $tabPartnerRates = [
            "MARSH" => 35,
            "AFINBRO" => 50,
            "AGL" => 45,
            "O'NEILS" => 50,
            "OLEA" => 35,
            "MONT-BLANC" => 50,
            "WP BROKERS" => 35,
        ];

        $tabReportSets = [];
        for ($m = 1; $m <= 12; $m++) {
            $month = date('F', mktime(0, 0, 0, $m, 1, date('Y')));
            // echo $month . '<br>';
            $datasetMois = (new PartnerReportSet())
                ->setType(PartnerReportSet::TYPE_SUBTOTAL)
                ->setCurrency_code("$")
                ->setLabel($month)
                ->setGw_premium(rand(1, 100000000))
                ->setNet_com(rand(1, 100000000))
                ->setTaxes(rand(1, 100000000))
                ->setCo_brokerage(rand(1, 100000000))
                ->setAmount_paid(rand(1, 100000000))
                ->setBalance_due(rand(1, 100000000));

            $tabReportSets[] = $datasetMois;
            foreach ($tabPartners as $partner) {
                $datasetAssureur = (new PartnerReportSet())
                    ->setType(PartnerReportSet::TYPE_ELEMENT)
                    ->setCurrency_code("$")
                    ->setLabel($partner)
                    // ->setPartner_rate(rand(0, count($tabPartnerRates) - 1))
                    ->setPartner_rate($tabPartnerRates[$partner])
                    ->setGw_premium(rand(1, 100000000))
                    ->setNet_com(rand(1, 100000000))
                    ->setTaxes(rand(1, 100000000))
                    ->setCo_brokerage(rand(1, 100000000))
                    ->setAmount_paid(rand(1, 100000000))
                    ->setBalance_due(rand(1, 100000000));
                $tabReportSets[] = $datasetAssureur;
            }
        }
        $datasetTotal = (new PartnerReportSet())
            ->setType(PartnerReportSet::TYPE_TOTAL)
            ->setCurrency_code("$")
            ->setLabel("TOTAL")
            ->setGw_premium(rand(1, 100000000))
            ->setNet_com(rand(1, 100000000))
            ->setTaxes(rand(1, 100000000))
            ->setCo_brokerage(rand(1, 100000000))
            ->setAmount_paid(rand(1, 100000000))
            ->setBalance_due(rand(1, 100000000));

        $tabReportSets[] = $datasetTotal;
        // dd($tabReportSets);

        return $tabReportSets;
    }

    public function newTabTop20Clients(): array
    {
        $tabClients = [
            "GLENCORE / KCC",
            "GLENCORE / MUMI",
            "IVANHOE / KAMOA",
            "ERG / METALKOL",
            "ERG / BOSS MINING",
            "ERG / FRONTIER",
            "ERG / COMIDE",
            "TENKE FUNGURUME",
            "CHEMAF / ETOILE",
            "CHEMAF / MUTOSHI",
            "RUASHI MINING",
            "TRANS AIR CARGO",
            "KIBALI GOLD MINES",
            "CAA RDC SA",
            "LEREXCOM",
            "BGFIBANK",
            "GSA",
            "MALU AVIATION",
            "STANDARD BANK",
            "HELIOS TOWERS",
        ];
        $tabReportSets = [];
        $index = 1;
        foreach ($tabClients as $client) {
            $dataSet = (new Top20ClientReportSet())
                ->setType(Top20ClientReportSet::TYPE_ELEMENT)
                ->setCurrency_code("$")
                ->setLabel($index . ". " . $client)
                ->setGw_premium(rand(0, 1000000))
                ->setOther_loadings(rand(0, 1000000))
                ->setNet_premium(rand(0, 1000000))
                ->setG_commission(rand(0, 1000000));

            $tabReportSets[] = $dataSet;
            $index++;
        }

        $datasetTotal = (new Top20ClientReportSet())
            ->setType(Top20ClientReportSet::TYPE_TOTAL)
            ->setCurrency_code("$")
            ->setLabel("TOTAL")
            ->setGw_premium(rand(0, 1000000))
            ->setOther_loadings(rand(0, 1000000))
            ->setNet_premium(rand(0, 1000000))
            ->setG_commission(rand(0, 1000000));

        $tabReportSets[] = $datasetTotal;
        // dd($tabReportSets);

        return $tabReportSets;
    }


    public function newTabTasks(): array
    {
        $tabClients = [
            "GLENCORE / KCC",
            "GLENCORE / MUMI",
            "IVANHOE / KAMOA",
            "ERG / METALKOL",
            "ERG / BOSS MINING",
            "ERG / FRONTIER",
            "ERG / COMIDE",
            "TENKE FUNGURUME",
            "CHEMAF / ETOILE",
            "CHEMAF / MUTOSHI",
            "RUASHI MINING",
            "TRANS AIR CARGO",
            "KIBALI GOLD MINES",
            "CAA RDC SA",
            "LEREXCOM",
            "BGFIBANK",
            "GSA",
            "MALU AVIATION",
            "STANDARD BANK",
            "HELIOS TOWERS",
        ];

        $tabUsersAM = [
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

        $tabUsersASS = [
            (new Utilisateur())
                ->setNom("VICTOR ESAFE")
                ->setEmail("vesafe@gmail.com"),
            (new Utilisateur())
                ->setNom("ARMANDE ISAMENE")
                ->setEmail("isamene@gmail.com"),
            (new Utilisateur())
                ->setNom("TYCHIQUE LUNDA")
                ->setEmail("tlunda@gmail.com"),
        ];

        $tabTasks = [
            "Récupérer le formulaire de proposition rempli et produire la cotation",
            "Collecter la prime",
            "Relancer pour le renouvellement",
            "Suivre le client pour avoir la binding instruction",
        ];

        $tabEndrosements = [
            "Incorporation",
            "Prorogation",
            "Annulation",
            "Résiliation",
            "Renouvellement",
            "Autre modification"
        ];

        $tabReportSets = [];
        $index = 1;
        foreach ($tabClients as $client) {
            $dataSet = (new TaskReportSet())
                ->setType(TaskReportSet::TYPE_ELEMENT)
                ->setCurrency_code("$")
                ->setTask_description("<strong>Task #" . $index . ":<br>" . $tabTasks[rand(0, count($tabTasks) - 1)] . "</strong>")
                ->setClient($client)
                ->setContacts(
                    [
                        "Olivier MUTOMBO",
                        "Olivier OBE",
                        "Serge SULA",
                        "Julien MVUMU",
                    ]
                )
                ->setOwner($tabUsersAM[rand(0, count($tabUsersAM) - 1)])
                ->setEndorsement($tabEndrosements[rand(0, count($tabEndrosements) - 1)])
                ->setExcutor($tabUsersASS[rand(0, count($tabUsersASS) - 1)])
                ->setEffect_date(new DateTimeImmutable("now"))
                ->setPotential_premium(rand(0, 1000000))
                ->setPotential_commission(rand(0, 150))
                ->setDays_passed(rand(-3, 10));

            // dd($dataSet);
            $tabReportSets[] = $dataSet;
            $index++;
        }

        $tabReportSets[] = (new TaskReportSet())
            ->setType(TaskReportSet::TYPE_TOTAL)
            ->setCurrency_code("$")
            ->setTask_description("TOTAL")
            ->setPotential_premium(rand(0, 1000000))
            ->setPotential_commission(rand(0, 150));

        // dd($tabReportSets);

        return $tabReportSets;
    }


    public function newTabRenewals(): array
    {
        $tabClients = [
            "GLENCORE / KCC",
            "GLENCORE / MUMI",
            "IVANHOE / KAMOA",
            "ERG / METALKOL",
            "ERG / BOSS MINING",
            "ERG / FRONTIER",
            "ERG / COMIDE",
            "TENKE FUNGURUME",
            "CHEMAF / ETOILE",
            "CHEMAF / MUTOSHI",
            "RUASHI MINING",
            "TRANS AIR CARGO",
            "KIBALI GOLD MINES",
            "CAA RDC SA",
            "LEREXCOM",
            "BGFIBANK",
            "GSA",
            "MALU AVIATION",
            "STANDARD BANK",
            "HELIOS TOWERS",
        ];

        $tabUsersAM = [
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

        $tabCovers = [
            "Motor TPL",
            "Motor Comp",
            "GPA",
            "PI",
            "GL",
            "BBB",
            "PDBI",
            "GIT"
        ];

        $tabInsurers = [
            "SFA Congo",
            "ACTIVA",
            "SUNU",
            "MAYFAIR",
            "RAWSUR",
            "GPA"
        ];

        $tabPolicies = [
            "W12457878-200-2024",
            "012457878-200-2024",
            "012457555-200-2024",
            "012457525-125-2024",
            "012457556-222-2024",
            "012457546-222-2024",
            "012400000-222-2024"
        ];

        $tabEndrosements = [
            "Incorporation",
            "Prorogation",
            "Annulation",
            "Résiliation",
            "Renouvellement",
            "Autre modification"
        ];

        $tabStatus = [
            "Renewal Ongoing...",
            "Renewed",
            "Cancelled",
            "Once-off",
            "Extended",
            "Still running"
        ];

        $tabReportSets = [];
        for ($i = 0; $i < 20; $i++) {
            $dataSet = (new RenewalReportSet())
                ->setType(RenewalReportSet::TYPE_ELEMENT)
                ->setCurrency_code("$")
                ->setEndorsement_id(rand(0,15))
                ->setLabel($tabPolicies[rand(0, count($tabPolicies) - 1)])
                ->setInsurer($tabInsurers[rand(0, count($tabInsurers) - 1)])
                ->setClient($tabClients[rand(0, count($tabClients) - 1)])
                ->setEndorsement($tabEndrosements[rand(0, count($tabEndrosements) - 1)])
                ->setCover($tabCovers[rand(0, count($tabCovers) - 1)])
                ->setAccount_manager($tabUsersAM[rand(0, count($tabUsersAM) - 1)])
                ->setGw_premium(rand(1000, 100000))
                ->setG_commission(rand(100, 10000))
                ->setEffect_date(new DateTimeImmutable("now - " . ($i + 365) . " days"))
                ->setExpiry_date(new DateTimeImmutable("now + " . ($i) . " days"));

            $diff = $this->serviceDates->daysEntre($dataSet->getExpiry_date(), new DateTimeImmutable("now"));
            $hours = $this->serviceDates->hoursEntre($dataSet->getExpiry_date(), new DateTimeImmutable("now"));
            $minutes = $this->serviceDates->minutesEntre($dataSet->getExpiry_date(), new DateTimeImmutable("now"));
            
            $txt = "";
            if($diff == 0){
                if($hours == 0 && $minutes == 0){
                    $txt = "Expired.";
                }else{
                    $txt = "Expiring in " . $hours . ":" . $minutes;
                }
            }else if($diff == 1){
                $txt = "Expiring in " . $diff . " day.";
            }else if($diff > 1){
                $txt = "Expiring in " . $diff . " days.";
            }
            $dataSet->setRemaining_days($txt);
            
            if ($diff == 0) {
                $dataSet->setStatus($tabStatus[rand(0, count($tabStatus)-1)]);
            } else {
                $dataSet->setStatus("Still Running");
            }
            if($diff == 0){
                $dataSet->setBg_color("text-bg-danger");
            }else{
                $dataSet->setBg_color("text-bg-success");
            }
            $tabReportSets[] = $dataSet;
        }

        //Ligne totale
        $dataSet = (new RenewalReportSet())
            ->setType(RenewalReportSet::TYPE_TOTAL)
            ->setCurrency_code("$")
            ->setLabel("TOTAL")
            ->setGw_premium(rand(1000, 100000))
            ->setG_commission(rand(100, 10000));
        $tabReportSets[] = $dataSet;
        // dd($tabReportSets);

        return $tabReportSets;
    }


    public function newTabClaims(): array
    {
        $tabInsurers = [
            "SFA Congo",
            "ACTIVA",
            "SUNU",
            "MAYFAIR",
            "RAWSUR",
            "GPA"
        ];

        $tabClients = [
            "GLENCORE / KCC",
            "GLENCORE / MUMI",
            "IVANHOE / KAMOA",
            "ERG / METALKOL",
            "ERG / BOSS MINING",
            "ERG / FRONTIER",
            "ERG / COMIDE",
            "TENKE FUNGURUME",
            "CHEMAF / ETOILE",
            "CHEMAF / MUTOSHI",
            "RUASHI MINING",
            "TRANS AIR CARGO",
            "KIBALI GOLD MINES",
            "CAA RDC SA",
            "LEREXCOM",
            "BGFIBANK",
            "GSA",
            "MALU AVIATION",
            "STANDARD BANK",
            "HELIOS TOWERS",
        ];

        $tabUsersAM = [
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

        $tabCovers = [
            "Motor TPL",
            "Motor Comp",
            "GPA",
            "PI",
            "GL",
            "BBB",
            "PDBI",
            "GIT"
        ];

        $tabClaims = [
            "W12457878-CLM-2024",
            "012457555-CLM-2024",
            "012457525-CLM-2024",
            "012457556-CLM-2024",
            "012457546-CLM-2024",
            "012400000-CLM-2024"
        ];

        $tabPolicies = [
            "W12457878-200-2024",
            "012457878-200-2024",
            "012457555-200-2024",
            "012457525-125-2024",
            "012457556-222-2024",
            "012457546-222-2024",
            "012400000-222-2024"
        ];

        $tabClaimStatus = [
            "Processing...",
            "Settled",
            "Cancelled",
            "Awaiting docs."
        ];

        $tabReportSets = [];
        $number = 1;
        foreach ($tabClaims as $claim_reference) {
            $dataSet = (new ClaimReportSet())
                ->setType(ClaimReportSet::TYPE_ELEMENT)
                ->setCurrency_code("$")
                ->setNumber($number)
                ->setPolicy_reference($tabPolicies[rand(0, count($tabPolicies) - 1)])
                ->setInsurer($tabInsurers[rand(0, count($tabInsurers) - 1)])
                ->setClient($tabClients[rand(0, count($tabClients) - 1)])
                ->setCover($tabCovers[rand(0, count($tabCovers) - 1)])
                ->setGw_premium(rand(1000, 100000))
                ->setPolicy_limit(rand(1000, 100000))
                ->setPolicy_deductible(rand(10, 1000))
                ->setEffect_date(new DateTimeImmutable("now - 20 days"))
                ->setExpiry_date(new DateTimeImmutable("now + 200 days"))
                ->setClaim_reference($claim_reference)
                ->setVictim($tabClients[rand(0, count($tabClients) - 1)])
                ->setClaims_status($tabClaimStatus[rand(0, count($tabClaimStatus) - 1)])
                ->setAccount_manager($tabUsersAM[rand(0, count($tabUsersAM) - 1)])
                ->setDamage_cost(rand(1000, 100000))
                // ->setCompensation_paid(rand(0, 100000))
                // ->setCompensation_balance(rand(0, 100000))
                ->setNotification_date(new DateTimeImmutable("now - " . (rand(0, 30)) . " days"))
                ->setSettlement_date(new DateTimeImmutable("now - " . (rand(0, 3)) . " days"))
            ;

            $dataSet->setCompensation_paid(rand(0, ($dataSet->getDamage_cost())));
            $dataSet->setCompensation_balance($dataSet->getDamage_cost() - $dataSet->getCompensation_paid());

            $speed_settlement_days = $this->serviceDates->daysEntre($dataSet->getNotification_date(), $dataSet->getSettlement_date());
            $days_past = $this->serviceDates->daysEntre($dataSet->getNotification_date(), new DateTimeImmutable("now"));
            
            $dataSet->setCompensation_speed("Settled in " . $speed_settlement_days . " days.");
            $dataSet->setDays_passed($days_past . " days passed since the notification date.");
            
            // $dataSet->setBg_color("text-bg-danger");
            
            $tabReportSets[] = $dataSet;
            $number++;
        }

        //Ligne totale
        $dataSet = (new ClaimReportSet())
            ->setType(ClaimReportSet::TYPE_TOTAL)
            ->setCurrency_code("$")
            ->setPolicy_reference("TOTAL")
            ->setDamage_cost(rand(1000, 100000))
            ->setCompensation_paid(rand(1000, 10000))
            ->setCompensation_balance(rand(1000, 10000));

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
        ];
    }
}
