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
    private TCPDF $tcpdf;
    private Utilisateur $utilisateur;

    public function __construct(
        private Security $security,
    ) {
        $portrait = "P"; //Portrait = "P", Landscape = "L"
        /** @var MyCustomTCPDF */
        $this->tcpdf = new MyCustomTCPDF($portrait, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        /** @var Utilisateur $utilisateur */
        $this->utilisateur = $this->security->getUser();
        $this->tcpdf->setEntreprise($this->utilisateur->getConnectedTo());
    }

    public function getTcpdf(Entreprise $entreprise, ?string $titre, bool $withHeader = true, bool $withFooter = true): TCPDF
    {
        $this->tcpdf->SetCreator(PDF_CREATOR);
        $this->tcpdf->SetAuthor('User: ' . $this->utilisateur->getNom());
        $this->tcpdf->SetTitle($titre);

        //Autorisation d'afficher ou non le header et footer de la page
        $this->tcpdf->setPrintHeader($withHeader);
        $this->tcpdf->setPrintFooter($withFooter);

        // set default header data
        $this->tcpdf->SetHeaderData(
            "", //header image logo
            0,  //header image logo width in mm
            $entreprise->getNom(),  //string to print as title on document header
            "Adresse: " . $entreprise->getAdresse() . " • Site: " . $entreprise->getSiteweb()
            // "Tél.: " . $entreprise->getTelephone()
            // " • Licence.: " . $entreprise->getLicence().
            // " • RCCM.: " . $entreprise->getRccm().
            // " • IDNAT.: " . $entreprise->getIdnat().
            // " • N.Impôt.: " . $entreprise->getNumimpot()
            ,   //string to print on document header
            array(0, 0, 0), //Text color (RGB)
            array(0, 0, 0)  //Line color (RGB)
        );
        
        // set margins
        $this->tcpdf->SetMargins(PDF_MARGIN_LEFT, 20, PDF_MARGIN_RIGHT);
        $this->tcpdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $this->tcpdf->SetFooterMargin(PDF_MARGIN_FOOTER);

        // set auto page breaks
        $this->tcpdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

        // set image scale factor
        $this->tcpdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // Add a page
        $this->tcpdf->AddPage();
        
        return $this->tcpdf;
    }
}
