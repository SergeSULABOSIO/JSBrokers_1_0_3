<?php

namespace App\Services;

use App\Constantes\MyCustomTCPDF;
use App\Constantes\MyPDFHeaderAndFooter;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;
use TCPDF;

class ServiceTcpdf
{
    private MyCustomTCPDF $tcpdf;
    private Utilisateur $utilisateur;

    public function __construct(
        private Security $security,
    ) {}

    public function getTcpdf(?string $page_orientation, ?string $titre, bool $withHeader = true, bool $withFooter = true): TCPDF
    {
        //Portrait = "P", Landscape = "L"
        $this->tcpdf = new MyCustomTCPDF($page_orientation, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        /** @var Utilisateur $utilisateur */
        $this->utilisateur = $this->security->getUser();
        $this->tcpdf->setEntreprise($this->utilisateur->getConnectedTo());

        $this->tcpdf->SetCreator(PDF_CREATOR);
        $this->tcpdf->SetAuthor('User: ' . $this->utilisateur->getNom());
        $this->tcpdf->SetTitle($titre);

        //Autorisation d'afficher ou non le header et footer de la page
        $this->tcpdf->setPrintHeader($withHeader);
        $this->tcpdf->setPrintFooter($withFooter);
        
        // set margins
        $this->tcpdf->SetMargins(PDF_MARGIN_LEFT, 20, PDF_MARGIN_RIGHT);
        $this->tcpdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $this->tcpdf->SetFooterMargin(PDF_MARGIN_FOOTER + 15);

        // set auto page breaks
        $this->tcpdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

        // set image scale factor
        $this->tcpdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // Add a page
        $this->tcpdf->AddPage();
        
        return $this->tcpdf;
    }
}
