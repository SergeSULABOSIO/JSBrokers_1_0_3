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
        /** @var Utilisateur $utilisateur */
        $this->utilisateur = $this->security->getUser();

        /** @var Entreprise $entreprise */
        $entreprise = $this->utilisateur->getConnectedTo();

        //Portrait = "P", Landscape = "L"
        $this->tcpdf = new MyCustomTCPDF($page_orientation, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        $this->tcpdf->setEntreprise($entreprise);

        $this->tcpdf->SetCreator(PDF_CREATOR);
        $this->tcpdf->SetAuthor('User: ' . $this->utilisateur->getNom());
        $this->tcpdf->SetTitle($titre);

        //Autorisation d'afficher ou non le header et footer de la page
        $this->tcpdf->setPrintHeader($withHeader);
        $this->tcpdf->setPrintFooter($withFooter);

        // set margins
        $this->tcpdf->SetMargins(PDF_MARGIN_LEFT, 20, PDF_MARGIN_RIGHT);
        $this->tcpdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $this->tcpdf->SetFooterMargin(PDF_MARGIN_FOOTER);

        // set auto page breaks
        $this->tcpdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);

        // set image scale factor
        $this->tcpdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // Add a page
        $this->tcpdf->AddPage();

        $this->setLogo($entreprise);
        
        return $this->tcpdf;
    }

    public function setLogo(?Entreprise $entreprise)
    {
        // $image_file = K_PATH_IMAGES . 'logo_example.jpg';
        $image_file = "./images/entreprises/logo.jpg";
        $image_width = 15;
        $image_height = 15;
        $image_x = 15;
        $image_y = 10;
        $image_type = 'JPG';
        $image_link = 'http://www.aib-brokers.com';
        $image_align = "N"; //$align Indicates the alignment of the pointer next to image insertion relative to image height. The value can be:<ul><li>T: top-right for LTR or top-left for RTL</li><li>M: middle-right for LTR or middle-left for RTL</li><li>B: bottom-right for LTR or bottom-left for RTL</li><li>N: next line</li></ul>
        $image_resize = false;
        $image_dpi = 300;
        $image_palign = "C"; //$palign Allows to center or align the image on the current line. Possible values are:<ul><li>L : left align</li><li>C : center</li><li>R : right align</li><li>'' : empty string : left for LTR or right for RTL</li></ul>
        $this->tcpdf->Image($image_file, $image_x, $image_y, $image_width, $image_height, $image_type, $image_link, $image_align, $image_resize, $image_dpi, $image_palign, false, false, 0, false, false, false);

        // Set font
        $font_family = 'helvetica';
        $font_style = "N";
        $font_size = 10;
        $this->tcpdf->SetFont($font_family, $font_style, $font_size);

        // Title
        // $this->Cell(0, "", '<< TCPDF Example 003 >>', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $title_width = 0;
        $title_height = 0;
        $title_html_text = '<span style="font-weight: bold;">' . $entreprise->getNom() . '</span>';
        $title_cell_border = 0;
        $title_x = PDF_MARGIN_LEFT;
        $title_y = $image_height + 11;
        $title_align = "C";
        $this->tcpdf->writeHTMLCell($title_width, $title_height, $title_x, $title_y, $title_html_text, $title_cell_border, 1, 0, true, $title_align, true);
        $this->tcpdf->writeHTML('<hr style="border: 1px solid black;">', true, false, true, false, '');
    }
}
