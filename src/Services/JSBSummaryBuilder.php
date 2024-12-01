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
        $items = [];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Net Prem.:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Arca:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Fronting:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Facility:',
            ReportSummary::VALEUR => 100000000.45,
        ];

        $summary = (new ReportSummary())
            ->setIcone("emojione-monotone:umbrella-with-rain-drops")
            ->setIcone_color("text-primary")
            ->setCurrency_code("$")
            ->setTitre("Summary of policies placed")
            ->setPrincipal([
                ReportSummary::RUBRIQUE => 'GW Prem.:',
                ReportSummary::VALEUR => 100000000.45,
            ])
            ->setItems($items);

        // dd($summary);
        return $summary;
    }

    /**
     * Undocumented function
     *
     * @return ReportSummary
     */
    public function newRevenuesSummary(): ReportSummary
    {
        $items = [];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Net Com.:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Arca (2%):',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Vat (16%):',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Pure Com:',
            ReportSummary::VALEUR => 100000000.45,
        ];

        $summary = (new ReportSummary())
            ->setIcone("healthicons:money-bag")
            ->setIcone_color("text-success")
            ->setCurrency_code("$")
            ->setTitre("Summary of revenue generated")
            ->setPrincipal([
                ReportSummary::RUBRIQUE => 'Gros Com.:',
                ReportSummary::VALEUR => 100000000.45,
            ])
            ->setItems($items);

        return $summary;
    }


    /**
     * Undocumented function
     *
     * @return ReportSummary
     */
    public function newRevenueCollectionsSummary(): ReportSummary
    {
        $items = [];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Invoiced:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Received:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Balance:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Not invoiced yet:',
            ReportSummary::VALEUR => 100000000.45,
        ];

        $summary = (new ReportSummary())
            ->setIcone("game-icons:receive-money")
            ->setIcone_color("text-black")
            ->setCurrency_code("$")
            ->setTitre("Summary of Commission collection")
            ->setPrincipal([
                ReportSummary::RUBRIQUE => 'Com. due:',
                ReportSummary::VALEUR => 100000000.45,
            ])
            ->setItems($items);

        return $summary;
    }


    /**
     * Undocumented function
     *
     * @return ReportSummary
     */
    public function newCoBrokerageSummary(): ReportSummary
    {
        $items = [];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Net revenue:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Co-brokerage:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Amounts paid:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Balance:',
            ReportSummary::VALEUR => 100000000.45,
        ];

        $summary = (new ReportSummary())
            ->setIcone("carbon:partnership")
            ->setIcone_color("text-secondary")
            ->setCurrency_code("$")
            ->setTitre("Summary of Co-brokerages")
            ->setPrincipal([
                ReportSummary::RUBRIQUE => 'Balance:',
                ReportSummary::VALEUR => 100000000.45,
            ])
            ->setItems($items);

        return $summary;
    }


    /**
     * Undocumented function
     *
     * @return ReportSummary
     */
    public function newTaxSummary(): ReportSummary
    {
        $items = [];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Net revenue:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Tax due:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Tax paid:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Balance payable:',
            ReportSummary::VALEUR => 100000000.45,
        ];

        $summary = (new ReportSummary())
            ->setIcone("carbon:finance")
            ->setIcone_color("text-warning")
            ->setCurrency_code("$")
            ->setTitre("Summary of tax")
            ->setPrincipal([
                ReportSummary::RUBRIQUE => 'Tax payable:',
                ReportSummary::VALEUR => 100000000.45,
            ])
            ->setItems($items);

        return $summary;
    }


    /**
     * Undocumented function
     *
     * @return ReportSummary
     */
    public function newClaimsSummary(): ReportSummary
    {
        $items = [];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Total damage:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Total due:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Total settled:',
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => 'Balance payable:',
            ReportSummary::VALEUR => 100000000.45,
        ];

        $summary = (new ReportSummary())
            ->setIcone("hugeicons:accident")
            ->setIcone_color("text-danger")
            ->setTitre("Summary of claims")
            ->setCurrency_code("$")
            ->setPrincipal([
                ReportSummary::RUBRIQUE => 'Balance:',
                ReportSummary::VALEUR => 100000000.45,
            ])
            ->setItems($items);

        return $summary;
    }


    public function getProductionSummaries(): array
    { //
        return [
            'policiesSummary' => $this->newPoliciesSummary(),
            'revenuesSummary' => $this->newRevenuesSummary(),
            'revenueCollectionsSummary' => $this->newRevenueCollectionsSummary(),
            'coBrokerageSummary' => $this->newCoBrokerageSummary(),
            'taxSummary' => $this->newTaxSummary(),
            'claimsSummary' => $this->newClaimsSummary(),
        ];
    }
}
