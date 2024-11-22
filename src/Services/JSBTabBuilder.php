<?php

namespace App\Services;

use App\Entity\ReportSet\InsurerReportSet;
use App\Entity\ReportSet\PartnerReportSet;
use App\Entity\ReportSet\Top20ClientReportSet;
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

    public function getProductionTabs(): array
    {
        return [
            'tabProductionPerInsurerPerMonth' => $this->newTabProductionPerInsurerPerMonth(),
            'tabProductionPerPartnerPerMonth' => $this->newTabProductionPerPartnerPerMonth(),
            'tabTop20Clients' => $this->newTabTop20Clients(),
        ];
    }
}
