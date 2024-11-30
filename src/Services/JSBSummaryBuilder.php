<?php

namespace App\Services;

use App\Entity\ReportSet\ReportSummary;
use Symfony\Bundle\SecurityBundle\Security;

class JSBSummaryBuilder
{

    public function __construct(
        private Security $security,
        private ServiceDates $serviceDates,
    ) {}

    /**
     * Undocumented function
     *
     * @return ReportSummary
     */
    public function newPoliciesSummary(): ReportSummary
    {
        $summary = (new ReportSummary())
            ->setIcone("emojione-monotone:umbrella-with-rain-drops")
            ->setIcone_color("text-primary")
            ->setTitre("Summary of policies placed")
            ->setPrincipal([
                ReportSummary::RUBRIQUE => 'GW Prem.:',
                ReportSummary::VALEUR => 100000000.45,
            ])
            ->setItems(
                [
                    ReportSummary::RUBRIQUE => 'Net Prem.:',
                    ReportSummary::VALEUR => 100000000.45,
                ],
                [
                    ReportSummary::RUBRIQUE => 'Arca:',
                    ReportSummary::VALEUR => 100000000.45,
                ],
                [
                    ReportSummary::RUBRIQUE => 'Fronting:',
                    ReportSummary::VALEUR => 100000000.45,
                ],
                [
                    ReportSummary::RUBRIQUE => 'Facility:',
                    ReportSummary::VALEUR => 100000000.45,
                ],
            );

        dd($summary);
        return $summary;
    }

    public function getProductionSummaries(): array
    { //
        return [
            'policiesSummary' => $this->newPoliciesSummary(),
        ];
    }
}
