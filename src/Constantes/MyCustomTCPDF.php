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
        // $image_file = K_PATH_IMAGES . 'logo_example.jpg';
        // $image_file = "./images/entreprises/logo.jpg";
        // $image_width = 10;
        $image_height = 10;
        // $image_x = 15;
        // $image_y = 4;
        // $image_type = 'JPG';
        // $image_link = 'http://www.aib-brokers.com';
        // $image_align = "N"; //$align Indicates the alignment of the pointer next to image insertion relative to image height. The value can be:<ul><li>T: top-right for LTR or top-left for RTL</li><li>M: middle-right for LTR or middle-left for RTL</li><li>B: bottom-right for LTR or bottom-left for RTL</li><li>N: next line</li></ul>
        // $image_resize = false;
        // $image_dpi = 300;
        // $image_palign = "C"; //$palign Allows to center or align the image on the current line. Possible values are:<ul><li>L : left align</li><li>C : center</li><li>R : right align</li><li>'' : empty string : left for LTR or right for RTL</li></ul>
        // $this->Image($image_file, $image_x, $image_y, $image_width, $image_height, $image_type, $image_link, $image_align, $image_resize, $image_dpi, $image_palign, false, false, 0, false, false, false);
        
        // Set font
        $font_family = 'helvetica';
        $font_style = "N";
        $font_size = 10;
        $this->SetFont($font_family, $font_style, $font_size);
        
        // Title
        // $this->Cell(0, "", '<< TCPDF Example 003 >>', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $title_width = 0;
        $title_height = 0;
        $title_html_text = '<span style="font-weight: bold;">' . $this->entreprise->getNom() . '</span>';
        $title_cell_border = 0;
        $title_x = PDF_MARGIN_LEFT;
        $title_y = $image_height + 5;
        $title_align = "C";
        $this->writeHTMLCell($title_width, $title_height, $title_x, $title_y, $title_html_text, $title_cell_border, 1, 0, true, $title_align, true);
        $this->writeHTML('<hr style="border: 1px solid black;">', true, false, true, false, '');
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
        $this->writeHTMLCell(0, 0, 15, $pageHeight - $footerHeight - 14, $ligne1, 0, 1, 0, true, 'N', true);
        $this->writeHTMLCell(0, 0, 15, $pageHeight - $footerHeight - 11, $ligne2, 0, 1, 0, true, 'C', true);
        $this->writeHTMLCell(0, 0, 15, $pageHeight - $footerHeight - 8, $ligne3, 0, 1, 0, true, 'C', true);
        $this->writeHTMLCell(0, 0, 15, $pageHeight - $footerHeight - 5, $ligne4, 0, 1, 0, true, 'C', true);
        $this->writeHTMLCell(0, 0, 15, $pageHeight - $footerHeight - 2, $numeroDeBasePage, 0, 1, 0, true, 'R', true);
    }
}
