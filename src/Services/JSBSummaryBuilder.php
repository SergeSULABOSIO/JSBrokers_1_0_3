<?php

namespace App\Services;

use App\Entity\ReportSet\ReportSummary;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

class JSBSummaryBuilder
{
    public function __construct(
        private Security $security,
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
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
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_policies_net_prem"),
            ReportSummary::VALEUR => 100000000.45,
        ];
        //Ici ces valeurs seront chargées automatiquement ce sont des chargements des factures client
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
            ->setTitre($this->translator->trans("company_dashboard_summary_policies_titre"))
            ->setPrincipal([
                ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_policies_gross_prem"),
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
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_revenues_net"),
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
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_revenues_pur"),
            ReportSummary::VALEUR => 100000000.45,
        ];

        $summary = (new ReportSummary())
            ->setIcone("healthicons:money-bag")
            ->setIcone_color("text-success")
            ->setCurrency_code("$")
            ->setTitre($this->translator->trans("company_dashboard_summary_revenues_titre"))
            ->setPrincipal([
                ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_revenues_ttc"),
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
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_collecte_revenues_invoiced"),
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_collecte_revenues_received"),
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_collecte_revenues_balance"),
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_collecte_revenues_not_invoiced"),
            ReportSummary::VALEUR => 100000000.45,
        ];

        $summary = (new ReportSummary())
            ->setIcone("game-icons:receive-money")
            ->setIcone_color("text-black")
            ->setCurrency_code("$")
            ->setTitre($this->translator->trans("company_dashboard_summary_collecte_revenues_titre"))
            ->setPrincipal([
                ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_collecte_revenues_due"),
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
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_retrocom_revenu_assiette"),
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_retrocom_due_partenaire"),
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_retrocom_payee"),
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_retrocom_due"),
            ReportSummary::VALEUR => 100000000.45,
        ];

        $summary = (new ReportSummary())
            ->setIcone("carbon:partnership")
            ->setIcone_color("text-secondary")
            ->setCurrency_code("$")
            ->setTitre($this->translator->trans("company_dashboard_summary_retrocom_titre"))
            ->setPrincipal([
                ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_retrocom_due"),
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
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_tax_revenu_net"),
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_tax_payable"),
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_tax_payee"),
            ReportSummary::VALEUR => 100000000.45,
        ];
        $items[] = [
            ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_tax_due"),
            ReportSummary::VALEUR => 100000000.45,
        ];

        $summary = (new ReportSummary())
            ->setIcone("carbon:finance")
            ->setIcone_color("text-warning")
            ->setCurrency_code("$")
            ->setTitre($this->translator->trans("company_dashboard_summary_tax_titre"))
            ->setPrincipal([
                ReportSummary::RUBRIQUE => $this->translator->trans("company_dashboard_summary_tax_due"),
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
