<?php

namespace App\Services;

use App\Entity\InsurerReportSet;
use App\Entity\PartnerReportSet;
use App\Entity\ReportSet;
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
            "MARSH",
            "AFINBRO",
            "AGL",
            "O'NEILS",
            "OLEA",
            "MONT-BLANC",
            "WP BROKERS",
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

    public function getProductionTabs(): array
    {
        return [
            'tabProductionPerInsurerPerMonth' => $this->newTabProductionPerInsurerPerMonth(),
            'tabProductionPerPartnerPerMonth' => $this->newTabProductionPerPartnerPerMonth(),
        ];
    }
}
