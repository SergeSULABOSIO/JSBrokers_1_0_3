<?php

namespace App\Services;

use App\Entity\ReportSet\InsurerReportSet;
use App\Entity\ReportSet\PartnerReportSet;
use App\Entity\ReportSet\TaskReportSet;
use App\Entity\ReportSet\Top20ClientReportSet;
use App\Entity\Utilisateur;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraints\Range;

class JSBTabBuilder
{

    public function __construct(
        private Security $security,
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
            "MARSH (35%)",
            "AFINBRO (50%)",
            "AGL (45%)",
            "O'NEILS (50%)",
            "OLEA (35%)",
            "MONT-BLANC (50%)",
            "WP BROKERS (35%)",
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

    public function getProductionTabs(): array
    {
        return [
            'tabProductionPerInsurerPerMonth' => $this->newTabProductionPerInsurerPerMonth(),
            'tabProductionPerPartnerPerMonth' => $this->newTabProductionPerPartnerPerMonth(),
            'tabTop20Clients' => $this->newTabTop20Clients(),
            'tabTasks' => $this->newTabTasks(),
        ];
    }
}
