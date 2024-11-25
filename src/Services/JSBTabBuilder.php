<?php

namespace App\Services;

use App\Entity\ReportSet\InsurerReportSet;
use App\Entity\ReportSet\PartnerReportSet;
use App\Entity\ReportSet\TaskReportSet;
use App\Entity\ReportSet\Top20ClientReportSet;
use App\Entity\Utilisateur;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;


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
            $datasetMois = new InsurerReportSet(
                InsurerReportSet::TYPE_SUBTOTAL,
                "$",
                $month,
                3676011.63,
                3676011.63,
                3676011.63,
                3676011.63,
                3676011.63,
                3676011.63,
                3676011.63,
            );
            $tabReportSets[] = $datasetMois;
            foreach ($tabAssureurs as $assureur) {
                $datasetAssureur = new InsurerReportSet(
                    InsurerReportSet::TYPE_ELEMENT,
                    "$",
                    $assureur,
                    676.63,
                    676.63,
                    676.63,
                    676.63,
                    676.63,
                    676.63,
                    676.63,
                );
                $tabReportSets[] = $datasetAssureur;
            }
        }
        $datasetTotal = new InsurerReportSet(
            InsurerReportSet::TYPE_TOTAL,
            "$",
            "TOTAL",
            3676011.63,
            3676011.63,
            3676011.63,
            3676011.63,
            3676011.63,
            3676011.63,
            3676011.63,
        );
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
            $datasetMois = new PartnerReportSet(
                PartnerReportSet::TYPE_SUBTOTAL,
                "$",
                $month,
                3676011.63,
                3676011.63,
                3676011.63,
                3676011.63,
                3676011.63,
                3676011.63,
                3676011.63,
            );
            $tabReportSets[] = $datasetMois;
            foreach ($tabPartners as $partner) {
                $datasetAssureur = new PartnerReportSet(
                    PartnerReportSet::TYPE_ELEMENT,
                    "$",
                    $partner,
                    676.63,
                    676.63,
                    676.63,
                    676.63,
                    676.63,
                    676.63,
                    676.63,
                );
                $tabReportSets[] = $datasetAssureur;
            }
        }
        $datasetTotal = new PartnerReportSet(
            PartnerReportSet::TYPE_TOTAL,
            "$",
            "TOTAL",
            3676011.63,
            3676011.63,
            3676011.63,
            3676011.63,
            3676011.63,
            3676011.63,
            3676011.63,
        );
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
            $dataSet = new Top20ClientReportSet(
                Top20ClientReportSet::TYPE_ELEMENT,
                "$",
                $index . ". " . $client,
                3676011.63,
                3676011.63,
                3676011.63,
                3676011.63
            );
            $tabReportSets[] = $dataSet;
            $index++;
        }

        $datasetTotal = new Top20ClientReportSet(
            Top20ClientReportSet::TYPE_TOTAL,
            "$",
            "TOTAL",
            3676011.63,
            3676011.63,
            3676011.63,
            3676011.63
        );
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

        $tabReportSets = [];
        $index = 1;
        foreach ($tabClients as $client) {
            $dataSet = new TaskReportSet(
                TaskReportSet::TYPE_ELEMENT,
                "$",
                "<strong>Task #" . $index . ":<br>" . $tabTasks[rand(0, count($tabTasks)-1)] . "</strong>",
                $client,
                [
                    "Olivier MUTOMBO",
                    "Olivier OBE",
                    "Serge SULA",
                    "Julien MVUMU",
                ],
                $tabUsersAM[rand(0, count($tabUsersAM)-1)],
                $tabUsersASS[rand(0, count($tabUsersASS)-1)],
                new DateTimeImmutable("now"),
                rand(0, 1000000),
                rand(0, 150),
                rand(-3, 10)
            );

            $tabReportSets[] = $dataSet;
            $index++;
        }

        $datasetTotal = new TaskReportSet(
            TaskReportSet::TYPE_TOTAL,
            "",
            "TOTAL",
            "",
            [],
            null,
            null,
            null,
            1000000,
            15000,
            0
        );

        $tabReportSets[] = $datasetTotal;
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
