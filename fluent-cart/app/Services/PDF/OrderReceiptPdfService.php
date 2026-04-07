<?php

namespace FluentCart\App\Services\PDF;

use FluentCart\App\Models\Order;
use FluentCart\App\Services\Libs\Emogrifier\Emogrifier;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\Framework\Support\Arr;

class OrderReceiptPdfService
{
    /**
     * Generate a PDF receipt for the given order.
     *
     * @param Order $order Order model with relations loaded
     * @return string|null Absolute path to the generated temp PDF file, or null on failure
     */
    public function generateReceiptPdf(Order $order, string $templateId = 'order_receipt'): ?string
    {
        if (!defined('FLUENT_PDF')) {
            return null;
        }

        $templateService = new ReceiptPdfTemplateService();
        $pdfStructure = $templateService->getPdfStructure($templateId);

        if (empty($pdfStructure)) {
            fluent_cart_add_log('PDF Structure Empty', 'getPdfStructure() returned empty', 'warning');
            return null;
        }

        $content = Arr::get($pdfStructure, 'content', '');
        $meta = Arr::get($pdfStructure, 'meta', []);

        if (empty($content)) {
            fluent_cart_add_log('PDF Content Empty', 'Template content is empty. Structure keys: ' . implode(', ', array_keys($pdfStructure)), 'warning');
            return null;
        }

        // Build context data for shortcode parsing
        $order->loadMissing(['customer', 'order_items', 'billing_address', 'shipping_address', 'transactions', 'orderTaxRates.tax_rate']);

        $data = [
            'order'       => $order,
            'customer'    => $order->customer ?: [],
            'transaction' => $order->transactions ? $order->transactions->first() : [],
        ];

        // Parse blocks to HTML — extract innerHTML directly instead of using
        // the email-specific FluentBlockParser which wraps everything in tables
        $html = $this->parseBlocksToHtml($content);

        // Replace smartcodes
        $html = ShortcodeTemplateBuilder::make($html, $data);

        // Wrap in a simple HTML document for PDF
        $html = $this->wrapHtmlForPdf($html);

        // Inline CSS
        $html = (string) (new Emogrifier($html))->emogrify();

        // Strip z-index and position for PDF/A compatibility
        $html = preg_replace('/z-index\s*:\s*[^;"]+;?/i', '', $html) ?? $html;
        $html = preg_replace('/position\s*:\s*(relative|absolute|fixed);?/i', '', $html) ?? $html;

        // E-Invoice (ZUGFeRD/Factur-X) — handled by pro via filter
        $eInvoiceData = apply_filters('fluent_cart/pdf_einvoice_data', [
            'enabled'         => false,
            'xml'             => null,
            'pdfa_compatible' => false,
        ], $order, $meta);

        // Generate PDF
        $pdfService = new PdfGeneratorService();

        $font = Arr::get($meta, '_fluent_cart_font', 'DejaVuSans');
        $paperSize = Arr::get($meta, '_fluent_cart_paper_size', 'A4');
        $orientation = Arr::get($meta, '_fluent_cart_orientation', 'Portrait');

        $mpdf = $pdfService->getGenerator([
            'default_font' => strtolower(str_replace(' ', '', $font)),
            'format'       => $paperSize,
            'orientation'  => strtolower($orientation) === 'landscape' ? 'L' : 'P',
        ]);

        // Embed E-Invoice XML as PDF/A-3 associated file (when provided by pro)
        if ($eInvoiceData['enabled'] && $eInvoiceData['pdfa_compatible'] && $eInvoiceData['xml'] !== null) {
            $mpdf->PDFA     = true;
            $mpdf->PDFX     = false;
            $mpdf->PDFAauto = true;
            $mpdf->PDFAversion = '3-B';
            $mpdf->SetAssociatedFiles([
                [
                    'name'           => 'factur-x.xml',
                    'mime'           => 'text/xml',
                    'description'    => 'ZUGFeRD/Factur-X XML',
                    'AFRelationship' => 'Alternative',
                    'content'        => $eInvoiceData['xml'],
                ],
            ]);
        } else {
            $mpdf->PDFA = false;
            $mpdf->PDFX = false;
        }

        // Watermark
        $watermarkEnabled = Arr::get($meta, '_fluent_cart_watermark_enabled', 0);
        if ($watermarkEnabled) {
            $watermarkText = Arr::get($meta, '_fluent_cart_watermark_text', 'PAID');
            $mpdf->SetWatermarkText($watermarkText);
            $mpdf->showWatermarkText = true;
        }

        $htmlForPdf = $pdfService->prepareHtmlForPdf($html);

        // Suppress PHP 8.1+ deprecation notices from mpdf internals (e.g. Otl.php preg_split null)
        $prevLevel = error_reporting(error_reporting() & ~E_DEPRECATED);
        $mpdf->WriteHTML($htmlForPdf);
        error_reporting($prevLevel);

        $fileName = 'receipt-' . $order->id . '-' . time() . '_' . wp_rand(1000, 9999) . '.pdf';
        $outputPath = $pdfService->getTempDirectory() . '/' . $fileName;

        $mpdf->Output($outputPath, 'F');

        return $outputPath;
    }

    /**
     * Parse Gutenberg block content to HTML for PDF rendering.
     *
     * Dynamic blocks (fluent-cart/* PDF blocks) are rendered server-side via
     * render_block() so their render() methods generate HTML from attributes.
     * Static blocks (core/html, etc.) use innerHTML directly.
     */
    private function parseBlocksToHtml(string $content): string
    {
        $blocks = parse_blocks($content);
        $html = '';

        foreach ($blocks as $block) {
            $blockName = $block['blockName'] ?? '';

            // Dynamic PDF blocks: use render_block() so server-side render() is called.
            // This handles self-closing blocks (no innerHTML) saved by the block editor.
            if ($blockName && strpos($blockName, 'fluent-cart/') === 0) {
                $rendered = render_block($block);
                if (!empty(trim($rendered))) {
                    $html .= $rendered;
                    continue;
                }
            }

            // Static blocks (core/html, etc.): extract innerHTML directly
            $innerHTML = $block['innerHTML'] ?? '';

            if (empty(trim($innerHTML)) && !empty($block['innerContent'])) {
                $innerHTML = implode('', array_filter($block['innerContent'], 'is_string'));
            }

            if (empty(trim($innerHTML)) && !empty($block['innerBlocks'])) {
                foreach ($block['innerBlocks'] as $innerBlock) {
                    $inner = $innerBlock['innerHTML'] ?? '';
                    if (empty(trim($inner)) && !empty($innerBlock['innerContent'])) {
                        $inner = implode('', array_filter($innerBlock['innerContent'], 'is_string'));
                    }
                    $html .= $inner;
                }
                continue;
            }

            $html .= $innerHTML;
        }

        return $html;
    }

    /**
     * Wrap parsed block HTML in a simple document structure for PDF rendering.
     */
    private function wrapHtmlForPdf(string $body): string
    {
        return '<!DOCTYPE html>
        <html>
        <head>
        <meta charset="UTF-8">
        <style>
            body {
                font-family: DejaVu Sans, sans-serif;
                font-size: 12px;
                color: #0E121B;
                line-height: 1.5;
                margin: 0;
                padding: 30px;
            }
            table {
                border-collapse: collapse;
                width: 100%;
            }
            img {
                max-width: 100%;
                height: auto;
            }
        </style>
        </head>
        <body>' . $body . '</body>
        </html>';
    }
}
