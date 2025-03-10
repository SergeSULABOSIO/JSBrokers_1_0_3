<?php

namespace App\Services;

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
        $this->tcpdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        /** @var Utilisateur $utilisateur */
        $this->utilisateur = $this->security->getUser();
    }

    public function getTcpdf(Entreprise $entreprise, ?string $titre): TCPDF
    {
        $this->tcpdf->SetCreator(PDF_CREATOR);
        $this->tcpdf->SetAuthor('User: ' . $this->utilisateur->getNom());
        $this->tcpdf->SetTitle($titre);

        // set default header data
        $this->tcpdf->SetHeaderData(
            "", //header image logo
            0,  //header image logo width in mm
            $entreprise->getNom(),  //string to print as title on document header
            "By " . $this->utilisateur->getNom(),   //string to print on document header
            array(0, 0, 0), //Text color (RGB)
            array(0, 0, 0)  //Line color (RGB)
        );
        $this->tcpdf->setFooterData(array(0, 0, 0), array(array(0, 0, 0)));

        // set header and footer fonts
        $this->tcpdf->setHeaderFont(array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
        $this->tcpdf->setFooterFont(array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

        // set default monospaced font
        $this->tcpdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

        // set margins
        $this->tcpdf->SetMargins(PDF_MARGIN_LEFT, 20, PDF_MARGIN_RIGHT);
        $this->tcpdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $this->tcpdf->SetFooterMargin(PDF_MARGIN_FOOTER);

        // set auto page breaks
        $this->tcpdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

        // set image scale factor
        $this->tcpdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // ---------------------------------------------------------

        // set default font subsetting mode
        $this->tcpdf->setFontSubsetting(true);
        // Set font
        // dejavusans is a UTF-8 Unicode font, if you only need to
        // print standard ASCII chars, you can use core fonts like
        // helvetica or times to reduce file size.
        $this->tcpdf->SetFont('times', '', 14, '', true);

        // Add a page
        // This method has several options, check the source code documentation for more information.
        $this->tcpdf->AddPage();
        return $this->tcpdf;
    }
}
