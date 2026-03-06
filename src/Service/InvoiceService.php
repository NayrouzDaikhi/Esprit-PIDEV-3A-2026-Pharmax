<?php

namespace App\Service;

use App\Entity\Commande;
use Dompdf\Dompdf;
use Twig\Environment;

class InvoiceService
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    /**
     * Generate invoice PDF for an order
     */
    public function generateInvoicePdf(Commande $commande, ?string $stripePaymentIntentId = null): \Symfony\Component\HttpFoundation\Response
    {
        $invoiceNumber = $this->generateInvoiceNumber($commande);
        $invoiceDate = new \DateTime();

        $html = $this->twig->render('invoice/pdf.html.twig', [
            'commande' => $commande,
            'invoiceNumber' => $invoiceNumber,
            'invoiceDate' => $invoiceDate,
            'dueDate' => (clone $invoiceDate)->modify('+30 days'),
            'stripePaymentId' => $stripePaymentIntentId,
            'companyInfo' => [
                'name' => 'Pharmax',
                'address' => '123 Rue de la Pharmacie, Tunis',
                'phone' => '+216 71 123 456',
                'email' => 'info@pharmax.tn',
                'taxId' => 'TN1234567890',
            ],
        ]);

        // Verify GD extension
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('GD extension required for PDF generation');
        }

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new \Symfony\Component\HttpFoundation\Response(
            $dompdf->output(),
            \Symfony\Component\HttpFoundation\Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="invoice_' . $invoiceNumber . '.pdf"',
            ]
        );
    }

    /**
     * Generate invoice HTML for email
     */
    public function generateInvoiceHtml(Commande $commande, ?string $stripePaymentIntentId = null): string
    {
        $invoiceNumber = $this->generateInvoiceNumber($commande);
        $invoiceDate = new \DateTime();

        return $this->twig->render('invoice/email.html.twig', [
            'commande' => $commande,
            'invoiceNumber' => $invoiceNumber,
            'invoiceDate' => $invoiceDate,
            'dueDate' => (clone $invoiceDate)->modify('+30 days'),
            'stripePaymentId' => $stripePaymentIntentId,
            'companyInfo' => [
                'name' => 'Pharmax',
                'address' => '123 Rue de la Pharmacie, Tunis',
                'phone' => '+216 71 123 456',
                'email' => 'info@pharmax.tn',
                'taxId' => 'TN1234567890',
            ],
        ]);
    }

    /**
     * Generate unique invoice number
     */
    public function generateInvoiceNumber(Commande $commande): string
    {
        $year = $commande->getCreatedAt()->format('Y');
        $month = $commande->getCreatedAt()->format('m');
        $id = str_pad((string)($commande->getId() ?? 0), 6, '0', STR_PAD_LEFT);

        return "INV-{$year}-{$month}-{$id}";
    }

    /**
     * Generate invoice data array for API responses
     */
    public function getInvoiceData(Commande $commande): array
    {
        $invoiceNumber = $this->generateInvoiceNumber($commande);
        $createdAt = $commande->getCreatedAt();
        
        // Cast to DateTime for method calls
        $createdDateTime = $createdAt instanceof \DateTime 
            ? $createdAt 
            : new \DateTime($createdAt->format('Y-m-d H:i:s'));

        return [
            'invoiceNumber' => $invoiceNumber,
            'invoiceDate' => $createdAt->format('Y-m-d'),
            'dueDate' => (clone $createdDateTime)->modify('+30 days')->format('Y-m-d'),
            'orderId' => $commande->getId(),
            'totalAmount' => $commande->getTotales(),
            'status' => $commande->getStatut(),
        ];
    }
}
