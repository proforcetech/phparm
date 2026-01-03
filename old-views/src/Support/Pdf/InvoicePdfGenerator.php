<?php

namespace App\Support\Pdf;

use App\Models\Invoice;
use App\Database\Connection;
use PDO;

class InvoicePdfGenerator extends PdfService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
    }

    /**
     * Generate invoice PDF
     *
     * @param array<string, mixed> $settings Shop settings
     */
    public function generate(Invoice $invoice, array $settings = []): string
    {
        $html = $this->buildInvoiceHtml($invoice, $settings);
        return $this->generateFromHtml($html);
    }

    /**
     * Save invoice PDF to file
     */
    public function saveInvoicePdf(Invoice $invoice, string $filePath, array $settings = []): bool
    {
        $html = $this->buildInvoiceHtml($invoice, $settings);
        return $this->saveToFile($html, $filePath);
    }

    /**
     * Build HTML for invoice
     *
     * @param array<string, mixed> $settings
     */
private function buildInvoiceHtml(Invoice $invoice, array $settings): string
{
    $customer = $this->fetchCustomer($invoice->customer_id);
    $items    = $this->fetchInvoiceItems($invoice->id);

    $shopName    = $settings['shop_name']    ?? 'Auto Repair Shop';
    $shopAddress = $settings['shop_address'] ?? '';
    $shopPhone   = $settings['shop_phone']   ?? '';
    $shopEmail   = $settings['shop_email']   ?? '';

    $html = $this->getBaseStyles();

    // Header
    $html .= <<<HTML
<div class="header">
    <table style="width: 100%; border: none;">
        <tr>
            <td style="width: 60%; border: none;">
                <h1>{$shopName}</h1>
                <div class="company-info">
                    {$shopAddress}<br>
                    {$shopPhone}<br>
                    {$shopEmail}
                </div>
            </td>
            <td style="width: 40%; border: none; text-align: right;">
                <h2>INVOICE</h2>
                <div class="document-info">
                    <strong>Invoice #:</strong> {$invoice->number}<br>
                    <strong>Date:</strong> {$invoice->created_at}<br>
                    <strong>Due Date:</strong> {$invoice->due_date}<br>
                    <strong>Status:</strong> <span class="status status-{$invoice->status}">{$invoice->status}</span>
                </div>
            </td>
        </tr>
    </table>
</div>

<div class="customer-section">
    <h3>Bill To:</h3>
    <strong>{$customer['first_name']} {$customer['last_name']}</strong><br>
HTML;

    if (!empty($customer['business_name'])) {
        $html .= $customer['business_name'] . '<br>';
    }

    $html .= <<<HTML
    {$customer['email']}<br>
    {$customer['phone']}<br>
HTML;

    if (!empty($customer['street'])) {
        $html .= $customer['street'] . '<br>';
        $html .= $customer['city'] . ', ' . $customer['state'] . ' ' . $customer['postal_code'] . '<br>';
    }

    $html .= '</div>';

    // Items table header
    $html .= <<<HTML
<div class="items-section">
    <h3>Items</h3>
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Unit Price</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
HTML;

    // Item rows (use number_format directly)
    foreach ($items as $item) {
        $lineTotal = $item['quantity'] * $item['unit_price'];

        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars((string) $item['description']) . '</td>';
        $html .= '<td class="text-right">' . (float) $item['quantity'] . '</td>';
        $html .= '<td class="text-right">$' . number_format((float) $item['unit_price'], 2) . '</td>';
        $html .= '<td class="text-right">$' . number_format((float) $lineTotal, 2) . '</td>';
        $html .= '</tr>';
    }

    $html .= <<<HTML
        </tbody>
    </table>
</div>
HTML;

    // Totals section
    $html .= '<div class="totals"><table>';

    $html .= '<tr>
        <td><strong>Subtotal:</strong></td>
        <td class="text-right">$' . number_format((float) $invoice->subtotal, 2) . '</td>
    </tr>';

    $html .= '<tr>
        <td><strong>Tax:</strong></td>
        <td class="text-right">$' . number_format((float) $invoice->tax, 2) . '</td>
    </tr>';

    $html .= '<tr style="border-top: 2px solid #333;">
        <td><strong>Total:</strong></td>
        <td class="text-right"><strong>$' . number_format((float) $invoice->total, 2) . '</strong></td>
    </tr>';

    if ($invoice->amount_paid > 0) {
        $html .= '<tr>
            <td>Amount Paid:</td>
            <td class="text-right">$' . number_format((float) $invoice->amount_paid, 2) . '</td>
        </tr>';

        $html .= '<tr style="border-top: 1px solid #ddd;">
            <td><strong>Balance Due:</strong></td>
            <td class="text-right"><strong>$' . number_format((float) $invoice->balance_due, 2) . '</strong></td>
        </tr>';
    }

    $html .= '</table></div>';

    // Terms
    if (!empty($settings['invoice_terms'])) {
        $html .= '<div class="footer">'
            . '<h3>Terms &amp; Conditions</h3>'
            . '<p>' . nl2br(htmlspecialchars((string) $settings['invoice_terms'])) . '</p>'
            . '</div>';
    }

    $html .= '<div class="footer">Thank you for your business!</div>';

    return $html;
}

    /**
     * @return array<string, mixed>
     */
    private function fetchCustomer(int $customerId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM customers WHERE id = :id');
        $stmt->execute(['id' => $customerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchInvoiceItems(int $invoiceId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM invoice_items WHERE invoice_id = :invoice_id ORDER BY id');
        $stmt->execute(['invoice_id' => $invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
