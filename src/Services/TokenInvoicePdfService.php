<?php

namespace App\Services;

use App\Entity\TokenPurchase;
use App\Repository\PlateformeParametresRepository;
use App\Token\ParametresTokenService;
use Dompdf\Dompdf;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Environment;

/**
 * @file Génération de la facture / avoir PDF d'un achat de tokens.
 * @description Calque le pattern DomPDF du projet (cf. BordereauAnalysisPdfService) :
 * rend un Twig → DomPDF → bytes. Le HT/TVA est dérivé du montant TTC payé via
 * ServiceTaxesVente (MÊME source que les écritures comptables et les KPI de
 * vente → cohérence garantie). Document généré à la volée, jamais stocké.
 */
class TokenInvoicePdfService
{
    public function __construct(
        private ParameterBagInterface $params,
        private Environment $twig,
        private ServiceTaxesVente $taxesVente,
        private ParametresTokenService $parametres,
        private PlateformeParametresRepository $plateformeParametres,
        private string $mailFrom,
    ) {
    }

    /** Bytes du PDF de la facture (ou de l'avoir si l'achat est remboursé). */
    public function generate(TokenPurchase $purchase): string
    {
        $ttc = round((float) $purchase->getMontantUsd(), 2);
        $ht  = round($this->taxesVente->revenuHorsTaxe($ttc), 2);

        // Ventilation des taxes (libellé + taux + montant), même base que la compta.
        $taxes = array_map(static fn (array $l) => [
            'libelle' => $l['taxe']->getLibelle(),
            'taux'    => $l['taxe']->getTauxFloat(),
            'montant' => round($l['montant'], 2),
        ], $this->taxesVente->ventilation($ttc));

        $isAvoir = $purchase->getStatus() === TokenPurchase::STATUS_REFUNDED;

        $html = $this->twig->render('admin/token/invoice_pdf.html.twig', [
            'purchase'    => $purchase,
            'packLabel'   => $this->parametres->pack((string) $purchase->getPack())['label'] ?? ucfirst((string) $purchase->getPack()),
            'ht'          => $ht,
            'taxes'       => $taxes,
            'tva'         => round($this->taxesVente->montantTaxes($ttc), 2),
            'tauxGlobal'  => $this->taxesVente->tauxGlobal(),
            'ttc'         => $ttc,
            'isAvoir'     => $isAvoir,
            'issuer'      => $this->issuer(),
            'logo'        => $this->logoDataUri(),
            'qr'          => $this->qrDataUri($purchase, $ttc, $isAvoir),
        ]);

        $dompdf = new Dompdf();
        $dompdf->getOptions()->setChroot($this->params->get('kernel.project_dir') . '/public');
        $dompdf->getOptions()->setIsRemoteEnabled(false);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /** Logo JS Brokers en data URI (embarqué : DomPDF est en mode non-distant). */
    private function logoDataUri(): ?string
    {
        $path = $this->params->get('kernel.project_dir') . '/public/images/entreprises/logofav.png';
        if (!is_file($path)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
    }

    /**
     * QR de contrôle (PNG data URI, aux couleurs JS Brokers) encodant les
     * éléments vérifiables de la facture : émetteur, n° de facture/avoir,
     * référence d'achat, montant TTC, date et client. Permet un rapprochement
     * rapide au scan (contrôle interne — sans valeur de normalisation fiscale).
     */
    private function qrDataUri(TokenPurchase $purchase, float $ttc, bool $isAvoir): string
    {
        $date = ($purchase->getPaidAt() ?? $purchase->getCreatedAt())?->format('Y-m-d H:i') ?? '';
        $payload = implode("\n", [
            'JS Brokers',
            ($isAvoir ? 'AVOIR' : 'FACTURE') . ' ' . ($purchase->getInvoiceNumber() ?? $purchase->getReference()),
            'Ref ' . $purchase->getReference(),
            'TTC ' . number_format($ttc, 2, '.', '') . ' USD',
            $date,
            'Client ' . ($purchase->getUtilisateur()?->getEmail() ?? '-'),
        ]);

        $qr = new QrCode(
            data: $payload,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 170,
            margin: 4,
            foregroundColor: new Color(0, 71, 171), // cobalt JS Brokers #0047AB
        );

        return (new PngWriter())->write($qr)->getDataUri();
    }

    /** Nom de fichier lisible : facture-FAC-2026-00001.pdf (ou avoir-…). */
    public function fileName(TokenPurchase $purchase): string
    {
        $prefix = $purchase->getStatus() === TokenPurchase::STATUS_REFUNDED ? 'avoir' : 'facture';

        return sprintf('%s-%s.pdf', $prefix, $purchase->getInvoiceNumber() ?? $purchase->getReference());
    }

    /**
     * Identité de l'émetteur (JS Brokers). Le capital social provient des
     * paramètres plateforme (même source que les documents comptables OHADA).
     *
     * @return array{nom:string, email:string, capitalSocial:float}
     */
    private function issuer(): array
    {
        return [
            'nom'           => 'JS Brokers',
            'email'         => $this->mailFrom,
            'capitalSocial' => $this->plateformeParametres->getSingleton()->getCapitalSocialFloat(),
        ];
    }
}
