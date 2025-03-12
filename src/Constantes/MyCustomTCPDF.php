<?php

namespace App\Constantes;

use App\Entity\Entreprise;
use App\Entity\Monnaie;
use TCPDF;

class MyCustomTCPDF extends TCPDF
{
    private ?Entreprise $entreprise;

    public function setEntreprise(?Entreprise $ese)
    {
        $this->entreprise = $ese;
    }

    // //Page header
    public function Header() {
        // Logo
        // $image_file = K_PATH_IMAGES.'logo_example.jpg';
        // $this->Image($image_file, 10, 10, 15, '', 'JPG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        // // Set font
        // $this->SetFont('helvetica', 'B', 20);
        // // Title
        // $this->Cell(0, 15, '<< TCPDF Example 003 >>', 0, false, 'C', 0, '', 0, false, 'M', 'M');
    }

    // Page footer
    public function Footer()
    {
        $pageHeight = $this->getPageHeight();
        $footerHeight = 10; // Hauteur du pied de page
        $noPage = $this->getAliasNumPage();
        $nbPage = $this->getAliasNbPages();
        
        $this->SetFont('Times', 'N', 8);
        $ligne1 = '<div style="font-weight: bold;text-align:center;">' . $this->entreprise->getNom() . '</div>';
        
        $codeMonnaieLocale = "";
        /** @var Monnaie $monnaie */
        foreach ($this->entreprise->getMonnaies() as $monnaie) {
            if ($monnaie->isLocale() == true) {
                $codeMonnaieLocale = $monnaie->getCode();
                break;
            }
        }

        $ligne2 = '<div style="text-align:center;">';
        $ligne2 .= 'Adresse: <span style="font-weight: bold;">' . $this->entreprise->getAdresse() . '</span>';
        $ligne2 .= ' • Tél.: <span style="font-weight: bold;">' . $this->entreprise->getTelephone() . '</span>';
        $ligne2 .= ' • Licence: <span style="font-weight: bold;">' . $this->entreprise->getLicence() . '</span>';
        $ligne2 .= ' • Capital Social: <span style="font-weight: bold;">' . $codeMonnaieLocale . ' ' . number_format($this->entreprise->getCapitalSociale(), 2, ',', ".") . '</span>';
        $ligne2 .= '</div>';

        $ligne3 = '<div style="text-align:center;">';
        $ligne3 .= 'Rccm: <span style="font-weight: bold;">' . $this->entreprise->getRccm() . '</span>';
        $ligne3 .= ' • Id.Nat: <span style="font-weight: bold;">' . $this->entreprise->getIdnat() . '</span>';
        $ligne3 .= ' • N°.Impôt: <span style="font-weight: bold;">' . $this->entreprise->getNumimpot() . '</span>';
        $ligne3 .= '</div>';
        
        $ligne4 = '<a href="'.$this->entreprise->getSiteweb().'" style="text-align:center;">' . $this->entreprise->getSiteweb() . '</a>';
        
        $numeroDeBasePage = '<div style="font-weight: bold;text-align:right;">Page ' . $noPage . '/' . $nbPage . '</div>';
        $this->writeHTMLCell(0, 0, 15, $pageHeight - $footerHeight - 15, '<hr/>', 0, 1, 0, true, 'L', true);
        $this->writeHTMLCell(0, 0, 15, $pageHeight - $footerHeight - 14, $ligne1, 0, 1, 0, true, 'C', true);
        $this->writeHTMLCell(0, 0, 15, $pageHeight - $footerHeight - 11, $ligne2, 0, 1, 0, true, 'C', true);
        $this->writeHTMLCell(0, 0, 15, $pageHeight - $footerHeight - 8, $ligne3, 0, 1, 0, true, 'C', true);
        $this->writeHTMLCell(0, 0, 15, $pageHeight - $footerHeight - 5, $ligne4, 0, 1, 0, true, 'C', true);
        $this->writeHTMLCell(0, 0, 15, $pageHeight - $footerHeight - 2, $numeroDeBasePage, 0, 1, 0, true, 'R', true);
    }
}
