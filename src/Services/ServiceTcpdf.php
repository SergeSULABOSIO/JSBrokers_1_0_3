<?php

namespace App\Services;

use Symfony\Bundle\SecurityBundle\Security;
use TCPDF;

class ServiceTcpdf
{
    private TCPDF $tcpdf;

    public function __construct(
        private Security $security,
    ) {
        $this->tcpdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    }

    public function getTcpdf(): TCPDF
    {
        return $this->tcpdf;
    }
}
