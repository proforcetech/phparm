<?php

namespace App\Support\Pdf;

use App\Models\InspectionReport;
use App\Database\Connection;
use PDO;

class InspectionPdfGenerator extends PdfService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
    }

    /**
     * Generate inspection report PDF
     *
     * @param array<string, mixed> $settings Shop settings
     */
    public function generate(InspectionReport $report, array $settings = []): string
    {
        $html = $this->buildInspectionHtml($report, $settings);
        return $this->generateFromHtml($html);
    }

    /**
     * Save inspection PDF to file
     */
    public function saveInspectionPdf(InspectionReport $report, string $filePath, array $settings = []): bool
    {
        $html = $this->buildInspectionHtml($report, $settings);
        return $this->saveToFile($html, $filePath);
    }

    /**
     * Build HTML for inspection report
     *
     * @param array<string, mixed> $settings
     */
    private function buildInspectionHtml(InspectionReport $report, array $settings): string
    {
        $template = $this->fetchTemplate($report->template_id);
        $customer = $this->fetchCustomer($report->customer_id);
        $vehicle = $this->fetchVehicle($report->vehicle_id);
        $items = $this->fetchReportItems($report->id);

        $shopName = $settings['shop_name'] ?? 'Auto Repair Shop';
        $shopAddress = $settings['shop_address'] ?? '';
        $shopPhone = $settings['shop_phone'] ?? '';

        $html = $this->getBaseStyles();
        $html .= $this->getInspectionStyles();

        $html .= <<<HTML
<div class="header">
    <table style="width: 100%; border: none;">
        <tr>
            <td style="width: 60%; border: none;">
                <h1>{$shopName}</h1>
                <div class="company-info">
                    {$shopAddress}<br>
                    {$shopPhone}
                </div>
            </td>
            <td style="width: 40%; border: none; text-align: right;">
                <h2>INSPECTION REPORT</h2>
                <div class="document-info">
                    <strong>Report #:</strong> {$report->id}<br>
                    <strong>Date:</strong> {$report->created_at}<br>
                    <strong>Status:</strong> <span class="status status-{$report->status}">{$report->status}</span>
                </div>
            </td>
        </tr>
    </table>
</div>

<div class="info-section">
    <table style="width: 100%; border: none;">
        <tr>
            <td style="width: 50%; border: none; vertical-align: top;">
                <h3>Customer Information</h3>
                <strong>{$customer['first_name']} {$customer['last_name']}</strong><br>
                {$customer['email']}<br>
                {$customer['phone']}
            </td>
            <td style="width: 50%; border: none; vertical-align: top;">
                <h3>Vehicle Information</h3>
                <strong>{$vehicle['year']} {$vehicle['make']} {$vehicle['model']}</strong><br>
HTML;

        if (!empty($vehicle['vin'])) {
            $html .= "VIN: {$vehicle['vin']}<br>";
        }
        if (!empty($vehicle['license_plate'])) {
            $html .= "License Plate: {$vehicle['license_plate']}<br>";
        }

        $html .= <<<HTML
            </td>
        </tr>
    </table>
</div>

<div class="template-info">
    <h3>Inspection: {$template['name']}</h3>
HTML;

        if (!empty($template['description'])) {
            $html .= "<p>{$template['description']}</p>";
        }

        $html .= '</div>';

        // Group items by section
        $itemsBySection = [];
        foreach ($items as $item) {
            $sectionName = $item['section_name'] ?? 'General';
            if (!isset($itemsBySection[$sectionName])) {
                $itemsBySection[$sectionName] = [];
            }
            $itemsBySection[$sectionName][] = $item;
        }

        $html .= '<div class="inspection-items">';
        foreach ($itemsBySection as $sectionName => $sectionItems) {
            $html .= <<<HTML
<div class="inspection-section">
    <h3>{$sectionName}</h3>
    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th class="text-center" style="width: 15%;">Status</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
HTML;

            foreach ($sectionItems as $item) {
                $statusClass = $this->getStatusClass($item['value'] ?? 'ok');
                $statusDisplay = $this->getStatusDisplay($item['value'] ?? 'ok');

                $html .= <<<HTML
            <tr>
                <td>{$item['item_name']}</td>
                <td class="text-center">
                    <span class="inspection-status {$statusClass}">{$statusDisplay}</span>
                </td>
                <td>{$item['notes']}</td>
            </tr>
HTML;
            }

            $html .= <<<HTML
        </tbody>
    </table>
</div>
HTML;
        }
        $html .= '</div>';

        // Technician notes
        if (!empty($report->notes)) {
            $html .= <<<HTML
<div class="notes-section">
    <h3>Technician Notes</h3>
    <p>{$report->notes}</p>
</div>
HTML;
        }

        // Signature section
        if (!empty($report->signature_data)) {
            $html .= <<<HTML
<div class="signature-section">
    <h3>Customer Signature</h3>
    <img src="{$report->signature_data}" alt="Signature" style="max-width: 300px; border: 1px solid #ddd; padding: 10px;">
    <p style="font-size: 9pt; color: #666;">Signed on: {$report->completed_at}</p>
</div>
HTML;
        }

        $html .= '<div class="footer">This inspection report is valid for 30 days from the date of inspection.</div>';

        return $html;
    }

    /**
     * Get additional styles for inspection reports
     */
    private function getInspectionStyles(): string
    {
        return <<<CSS
<style>
    .inspection-section {
        margin: 20px 0;
        page-break-inside: avoid;
    }
    .inspection-status {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 9pt;
        font-weight: bold;
        text-transform: uppercase;
    }
    .status-ok { background: #d4edda; color: #155724; }
    .status-attention { background: #fff3cd; color: #856404; }
    .status-critical { background: #f8d7da; color: #721c24; }
    .status-na { background: #e2e3e5; color: #383d41; }
    .info-section {
        margin: 20px 0;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 5px;
    }
    .signature-section {
        margin-top: 30px;
        padding-top: 20px;
        border-top: 2px solid #ddd;
    }
    .notes-section {
        margin: 20px 0;
        padding: 15px;
        background: #fffbf0;
        border-left: 4px solid #ffc107;
    }
</style>
CSS;
    }

    /**
     * Get CSS class for inspection status
     */
    private function getStatusClass(string $value): string
    {
        return match (strtolower($value)) {
            'pass', 'ok', 'good' => 'status-ok',
            'attention', 'warning', 'needs_attention' => 'status-attention',
            'fail', 'critical', 'urgent' => 'status-critical',
            default => 'status-na',
        };
    }

    /**
     * Get display text for inspection status
     */
    private function getStatusDisplay(string $value): string
    {
        return match (strtolower($value)) {
            'pass', 'ok', 'good' => 'OK',
            'attention', 'warning', 'needs_attention' => 'Attention',
            'fail', 'critical', 'urgent' => 'Critical',
            default => 'N/A',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTemplate(int $templateId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM inspection_templates WHERE id = :id');
        $stmt->execute(['id' => $templateId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Inspection', 'description' => ''];
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
     * @return array<string, mixed>
     */
    private function fetchVehicle(int $vehicleId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM customer_vehicles WHERE id = :id');
        $stmt->execute(['id' => $vehicleId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchReportItems(int $reportId): array
    {
        $sql = <<<SQL
SELECT
    iri.*,
    ii.name as item_name,
    iss.name as section_name
FROM inspection_report_items iri
JOIN inspection_items ii ON iri.item_id = ii.id
JOIN inspection_sections iss ON ii.section_id = iss.id
WHERE iri.report_id = :report_id
ORDER BY iss.display_order, ii.display_order
SQL;

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['report_id' => $reportId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
