<?php

namespace App\Support\Pdf;

use App\Database\Connection;
use App\Models\Estimate;
use App\Support\HtmlSanitizer;
use PDO;

class EstimatePdfGenerator extends PdfService
{
    private Connection $connection;
    private HtmlSanitizer $htmlSanitizer;

    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
        $this->htmlSanitizer = new HtmlSanitizer();
    }

    /**
     * @param array<string, mixed> $settings Shop settings
     */
    public function generate(Estimate $estimate, array $settings = []): string
    {
        $html = $this->buildHtml($estimate, $settings);
        return $this->generateFromHtml($html);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function buildHtml(Estimate $estimate, array $settings): string
    {
        $customer = $this->fetchCustomer($estimate->customer_id);
        $vehicle  = $estimate->vehicle_id ? $this->fetchVehicle($estimate->vehicle_id) : null;
        $jobs     = $this->fetchJobsWithItems($estimate->id);

        $shopName    = htmlspecialchars((string) ($settings['shop_name']    ?? 'Auto Repair Shop'));
        $shopAddress = nl2br(htmlspecialchars((string) ($settings['shop_address'] ?? '')));
        $shopPhone   = htmlspecialchars((string) ($settings['shop_phone']   ?? ''));
        $shopEmail   = htmlspecialchars((string) ($settings['shop_email']   ?? ''));

        $expiration = $estimate->expiration_date
            ? htmlspecialchars((string) $estimate->expiration_date)
            : '—';
        $createdAt = htmlspecialchars((string) ($estimate->created_at ?? ''));
        $statusLabel = htmlspecialchars(ucfirst(str_replace('_', ' ', $estimate->status)));

        $html = $this->getBaseStyles();

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
                <h2>ESTIMATE</h2>
                <div class="document-info">
                    <strong>Estimate #:</strong> {$estimate->number}<br>
                    <strong>Date:</strong> {$createdAt}<br>
                    <strong>Expires:</strong> {$expiration}<br>
                    <strong>Status:</strong> <span class="status status-{$estimate->status}">{$statusLabel}</span>
                </div>
            </td>
        </tr>
    </table>
</div>

<div class="customer-section">
    <h3>Prepared For:</h3>
HTML;

        $customerName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
        if ($customerName === '') {
            $customerName = 'Customer #' . (int) $estimate->customer_id;
        }
        $html .= '<strong>' . htmlspecialchars($customerName) . '</strong><br>';

        if (!empty($customer['business_name'])) {
            $html .= htmlspecialchars((string) $customer['business_name']) . '<br>';
        }
        if (!empty($customer['email'])) {
            $html .= htmlspecialchars((string) $customer['email']) . '<br>';
        }
        if (!empty($customer['phone'])) {
            $html .= htmlspecialchars((string) $customer['phone']) . '<br>';
        }
        if (!empty($customer['street'])) {
            $html .= htmlspecialchars((string) $customer['street']) . '<br>';
            $html .= htmlspecialchars(trim(
                ($customer['city'] ?? '') . ', ' .
                ($customer['state'] ?? '') . ' ' .
                ($customer['postal_code'] ?? '')
            )) . '<br>';
        }

        if ($vehicle) {
            $vehicleLabel = trim(
                ($vehicle['year'] ?? '') . ' ' .
                ($vehicle['make'] ?? '') . ' ' .
                ($vehicle['model'] ?? '')
            );
            if ($vehicleLabel !== '') {
                $html .= '<br><strong>Vehicle:</strong> ' . htmlspecialchars($vehicleLabel);
            }
            if (!empty($vehicle['vin'])) {
                $html .= '<br><strong>VIN:</strong> ' . htmlspecialchars((string) $vehicle['vin']);
            }
            if (!empty($vehicle['license_plate'])) {
                $html .= '<br><strong>Plate:</strong> ' . htmlspecialchars((string) $vehicle['license_plate']);
            }
        }

        $html .= '</div>';

        $html .= '<div class="items-section"><h3>Line Items</h3>';

        if (empty($jobs)) {
            $html .= '<p>No line items on this estimate.</p>';
        } else {
            foreach ($jobs as $job) {
                $jobTitle = htmlspecialchars((string) ($job['title'] ?? ('Job #' . (int) $job['id'])));
                $html .= '<h4 style="margin-top: 16px;">' . $jobTitle . '</h4>';
                $html .= '<table><thead><tr>'
                    . '<th>Type</th>'
                    . '<th>Description</th>'
                    . '<th class="text-right">Qty</th>'
                    . '<th class="text-right">Unit Price</th>'
                    . '<th class="text-right">Total</th>'
                    . '</tr></thead><tbody>';

                foreach ($job['items'] as $item) {
                    $type = htmlspecialchars((string) ($item['type'] ?? 'LABOR'));
                    $description = htmlspecialchars((string) ($item['description'] ?? ''));
                    $sku = !empty($item['sku']) ? '<br><span style="color:#666;font-size:11px;">SKU: '
                        . htmlspecialchars((string) $item['sku']) . '</span>' : '';
                    $quantity = (float) ($item['quantity'] ?? 0);
                    $unitPrice = (float) ($item['unit_price'] ?? 0);
                    $lineTotal = isset($item['line_total']) && $item['line_total'] !== null
                        ? (float) $item['line_total']
                        : $quantity * $unitPrice;

                    $html .= '<tr>';
                    $html .= '<td>' . $type . '</td>';
                    $html .= '<td>' . $description . $sku . '</td>';
                    $html .= '<td class="text-right">' . rtrim(rtrim(number_format($quantity, 2), '0'), '.') . '</td>';
                    $html .= '<td class="text-right">$' . number_format($unitPrice, 2) . '</td>';
                    $html .= '<td class="text-right">$' . number_format($lineTotal, 2) . '</td>';
                    $html .= '</tr>';
                }

                $html .= '</tbody></table>';
            }
        }

        $html .= '</div>';

        // Totals
        $html .= '<div class="totals"><table>';
        $html .= '<tr><td><strong>Subtotal:</strong></td><td class="text-right">$'
            . number_format((float) $estimate->subtotal, 2) . '</td></tr>';

        if ($estimate->call_out_fee > 0) {
            $html .= '<tr><td>Call-out Fee:</td><td class="text-right">$'
                . number_format((float) $estimate->call_out_fee, 2) . '</td></tr>';
        }
        if ($estimate->mileage_total > 0) {
            $html .= '<tr><td>Mileage:</td><td class="text-right">$'
                . number_format((float) $estimate->mileage_total, 2) . '</td></tr>';
        }
        if ($estimate->discounts > 0) {
            $html .= '<tr><td>Discounts:</td><td class="text-right">-$'
                . number_format((float) $estimate->discounts, 2) . '</td></tr>';
        }

        $html .= '<tr><td><strong>Tax:</strong></td><td class="text-right">$'
            . number_format((float) $estimate->tax, 2) . '</td></tr>';
        $html .= '<tr style="border-top: 2px solid #333;">'
            . '<td><strong>Grand Total:</strong></td>'
            . '<td class="text-right"><strong>$' . number_format((float) $estimate->grand_total, 2) . '</strong></td>'
            . '</tr>';
        $html .= '</table></div>';

        if (!empty($estimate->customer_notes)) {
            $sanitized = $this->htmlSanitizer->sanitize((string) $estimate->customer_notes);
            if ($sanitized !== '') {
                $html .= '<div class="footer"><h3>Notes</h3>' . $sanitized . '</div>';
            }
        }

        if (!empty($settings['estimate_terms'])) {
            $sanitizedTerms = $this->htmlSanitizer->sanitize((string) $settings['estimate_terms']);
            if ($sanitizedTerms !== '') {
                $html .= '<div class="footer"><h3>Terms &amp; Conditions</h3>' . $sanitizedTerms . '</div>';
            }
        }

        $html .= '<div class="footer">This estimate is valid until ' . $expiration . '.</div>';

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
     * @return array<string, mixed>|null
     */
    private function fetchVehicle(int $vehicleId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, year, make, model, vin, license_plate FROM customer_vehicles WHERE id = :id'
        );
        $stmt->execute(['id' => $vehicleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchJobsWithItems(int $estimateId): array
    {
        $jobsStmt = $this->connection->pdo()->prepare(
            'SELECT * FROM estimate_jobs WHERE estimate_id = :estimate_id ORDER BY display_order ASC, id ASC'
        );
        $jobsStmt->execute(['estimate_id' => $estimateId]);
        $jobRows = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($jobRows)) {
            return [];
        }

        $itemsStmt = $this->connection->pdo()->prepare(
            'SELECT * FROM estimate_items WHERE estimate_job_id = :job_id ORDER BY id ASC'
        );

        foreach ($jobRows as &$jobRow) {
            $itemsStmt->execute(['job_id' => $jobRow['id']]);
            $jobRow['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $jobRows;
    }
}
