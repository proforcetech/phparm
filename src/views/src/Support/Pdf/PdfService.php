<?php

namespace App\Support\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

class PdfService
{
    private Options $options;

    public function __construct()
    {
        if (!class_exists(Dompdf::class) || !class_exists(Options::class)) {
            throw new RuntimeException(
                'PDF generation requires dompdf. Install dependencies with "composer install" or "composer require dompdf/dompdf".'
            );
        }

        $this->options = new Options();
        $this->options->set('isHtml5ParserEnabled', true);
        $this->options->set('isRemoteEnabled', true);
        $this->options->set('defaultFont', 'Arial');
    }

    /**
     * Generate PDF from HTML content
     *
     * @return string Binary PDF content
     */
    public function generateFromHtml(string $html, string $paperSize = 'letter', string $orientation = 'portrait'): string
    {
        $dompdf = new Dompdf($this->options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($paperSize, $orientation);
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Save PDF to file
     */
    public function saveToFile(string $html, string $filePath, string $paperSize = 'letter', string $orientation = 'portrait'): bool
    {
        $pdfContent = $this->generateFromHtml($html, $paperSize, $orientation);

        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return file_put_contents($filePath, $pdfContent) !== false;
    }

    /**
     * Stream PDF to browser
     */
    public function streamToBrowser(string $html, string $filename, string $paperSize = 'letter', string $orientation = 'portrait'): void
    {
        $dompdf = new Dompdf($this->options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($paperSize, $orientation);
        $dompdf->render();
        $dompdf->stream($filename, ['Attachment' => false]);
    }

    /**
     * Download PDF (force download)
     */
    public function downloadPdf(string $html, string $filename, string $paperSize = 'letter', string $orientation = 'portrait'): void
    {
        $dompdf = new Dompdf($this->options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($paperSize, $orientation);
        $dompdf->render();
        $dompdf->stream($filename, ['Attachment' => true]);
    }

    /**
     * Get base CSS for PDFs
     */
    protected function getBaseStyles(): string
    {
        return <<<CSS
<style>
    body {
        font-family: Arial, sans-serif;
        font-size: 11pt;
        line-height: 1.4;
        color: #333;
        margin: 0;
        padding: 20px;
    }
    h1 {
        font-size: 24pt;
        margin: 0 0 10px 0;
        color: #1a1a1a;
    }
    h2 {
        font-size: 18pt;
        margin: 20px 0 10px 0;
        color: #1a1a1a;
        border-bottom: 2px solid #ddd;
        padding-bottom: 5px;
    }
    h3 {
        font-size: 14pt;
        margin: 15px 0 8px 0;
        color: #333;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
    }
    table th {
        background: #f5f5f5;
        padding: 8px;
        text-align: left;
        border-bottom: 2px solid #ddd;
        font-weight: bold;
    }
    table td {
        padding: 8px;
        border-bottom: 1px solid #eee;
    }
    .header {
        margin-bottom: 30px;
        border-bottom: 3px solid #333;
        padding-bottom: 15px;
    }
    .company-info {
        margin-bottom: 10px;
    }
    .document-info {
        text-align: right;
        font-size: 10pt;
        color: #666;
    }
    .totals {
        margin-top: 20px;
        text-align: right;
    }
    .totals table {
        width: 300px;
        margin-left: auto;
    }
    .footer {
        margin-top: 40px;
        padding-top: 20px;
        border-top: 1px solid #ddd;
        font-size: 9pt;
        color: #666;
        text-align: center;
    }
    .text-right {
        text-align: right;
    }
    .text-center {
        text-align: center;
    }
    .bold {
        font-weight: bold;
    }
    .status {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 3px;
        font-size: 9pt;
        font-weight: bold;
        text-transform: uppercase;
    }
    .status-pending { background: #fff3cd; color: #856404; }
    .status-paid { background: #d4edda; color: #155724; }
    .status-partial { background: #d1ecf1; color: #0c5460; }
    .status-void { background: #f8d7da; color: #721c24; }
</style>
CSS;
    }
}
