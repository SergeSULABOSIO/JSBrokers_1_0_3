<?php

namespace App\Services;

use App\Constantes\Constante;
use Symfony\Bundle\SecurityBundle\Security;
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
    }


    public function newTabCashflow(): array
    {
        $data = $this->constante->Entreprise_getDataTabCashFlow();
        // dd($data);
        return $data;
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
