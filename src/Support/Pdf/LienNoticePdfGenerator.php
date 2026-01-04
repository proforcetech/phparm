<?php

namespace App\Support\Pdf;

use DateTimeInterface;

class LienNoticePdfGenerator extends PdfService
{
    /**
     * @param array<string, mixed> $case
     * @param array<string, mixed> $owner
     * @param array<string, mixed> $vehicle
     * @param array<string, mixed> $fees
     * @param array<string, mixed> $settings
     */
    public function generateNoticeOfClaim(
        array $case,
        array $owner,
        array $vehicle,
        array $fees,
        array $settings = []
    ): string {
        $html = $this->buildNoticeOfClaimHtml($case, $owner, $vehicle, $fees, $settings);

        return $this->generateFromHtml($html);
    }

    /**
     * @param array<string, mixed> $notice
     * @param array<string, mixed> $case
     * @param array<string, mixed> $owner
     * @param array<string, mixed> $vehicle
     * @param array<string, mixed> $fees
     * @param array<string, mixed> $settings
     */
    public function generateLienNotice(
        array $notice,
        array $case,
        array $owner,
        array $vehicle,
        array $fees,
        array $settings = []
    ): string {
        $html = $this->buildLienNoticeHtml($notice, $case, $owner, $vehicle, $fees, $settings);

        return $this->generateFromHtml($html);
    }

    /**
     * @param array<string, mixed> $case
     * @param array<string, mixed> $owner
     * @param array<string, mixed> $vehicle
     * @param array<string, mixed> $fees
     * @param array<string, mixed> $settings
     */
    private function buildNoticeOfClaimHtml(
        array $case,
        array $owner,
        array $vehicle,
        array $fees,
        array $settings
    ): string {
        $shopName = $settings['shop_name'] ?? 'Storage Facility';
        $shopAddress = $settings['shop_address'] ?? '';
        $shopPhone = $settings['shop_phone'] ?? '';

        $noticeDate = $this->formatDate($case['notice_date'] ?? new \DateTimeImmutable('now'));
        $caseNumber = $case['case_number'] ?? 'N/A';
        $status = $case['status'] ?? 'open';

        $html = $this->getBaseStyles();
        $html .= $this->getLienStyles();

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
                <h2>NOTICE OF CLAIM</h2>
                <div class="document-info">
                    <strong>Case #:</strong> {$caseNumber}<br>
                    <strong>Date:</strong> {$noticeDate}<br>
                    <strong>Status:</strong> <span class="status status-{$status}">{$status}</span>
                </div>
            </td>
        </tr>
    </table>
</div>

<div class="info-section">
    <table style="width: 100%; border: none;">
        <tr>
            <td style="width: 50%; border: none; vertical-align: top;">
                <h3>Owner Information</h3>
                <strong>{$owner['name']}</strong><br>
                {$owner['address']}<br>
                {$owner['city']}, {$owner['state']} {$owner['zip']}<br>
                {$owner['phone']}
            </td>
            <td style="width: 50%; border: none; vertical-align: top;">
                <h3>Vehicle Information</h3>
                <strong>{$vehicle['year']} {$vehicle['make']} {$vehicle['model']}</strong><br>
                VIN: {$vehicle['vin']}<br>
                Plate: {$vehicle['license_plate']}<br>
                Stored At: {$case['intake_location']}
            </td>
        </tr>
    </table>
</div>

<div class="fee-summary">
    <h3>Storage Fee Summary</h3>
    <table>
        <tbody>
            <tr>
                <td>Daily Rate ({$fees['billable_days']} days @ \\${$fees['daily_rate']})</td>
                <td class="text-right">\\${$fees['daily_amount']}</td>
            </tr>
            <tr>
                <td>Gate Fee</td>
                <td class="text-right">\\${$fees['gate_fee']}</td>
            </tr>
            <tr class="total-row">
                <td><strong>Total Due</strong></td>
                <td class="text-right"><strong>\\${$fees['total']}</strong></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="notice-body">
    <p>This notice serves as a formal claim for the above-referenced vehicle. Storage charges will continue to accrue until the vehicle is released.</p>
    <p>Please contact our office to arrange payment and release. Failure to respond may result in lien processing under state law.</p>
</div>

<div class="footer">
    This notice was generated on {$noticeDate}. Keep a copy for your records.
</div>
HTML;

        return $html;
    }

    /**
     * @param array<string, mixed> $notice
     * @param array<string, mixed> $case
     * @param array<string, mixed> $owner
     * @param array<string, mixed> $vehicle
     * @param array<string, mixed> $fees
     * @param array<string, mixed> $settings
     */
    private function buildLienNoticeHtml(
        array $notice,
        array $case,
        array $owner,
        array $vehicle,
        array $fees,
        array $settings
    ): string {
        $shopName = $settings['shop_name'] ?? 'Storage Facility';
        $shopAddress = $settings['shop_address'] ?? '';
        $shopPhone = $settings['shop_phone'] ?? '';

        $noticeDate = $this->formatDate($notice['notice_date'] ?? new \DateTimeImmutable('now'));
        $dueDate = $this->formatDate($notice['due_date'] ?? null);
        $noticeType = $notice['notice_type'] ?? 'Lien Notice';
        $caseNumber = $case['case_number'] ?? 'N/A';

        $html = $this->getBaseStyles();
        $html .= $this->getLienStyles();

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
                <h2>{$noticeType}</h2>
                <div class="document-info">
                    <strong>Case #:</strong> {$caseNumber}<br>
                    <strong>Date:</strong> {$noticeDate}<br>
                    <strong>Due:</strong> {$dueDate}
                </div>
            </td>
        </tr>
    </table>
</div>

<div class="info-section">
    <table style="width: 100%; border: none;">
        <tr>
            <td style="width: 50%; border: none; vertical-align: top;">
                <h3>Owner Information</h3>
                <strong>{$owner['name']}</strong><br>
                {$owner['address']}<br>
                {$owner['city']}, {$owner['state']} {$owner['zip']}<br>
                {$owner['phone']}
            </td>
            <td style="width: 50%; border: none; vertical-align: top;">
                <h3>Vehicle Information</h3>
                <strong>{$vehicle['year']} {$vehicle['make']} {$vehicle['model']}</strong><br>
                VIN: {$vehicle['vin']}<br>
                Plate: {$vehicle['license_plate']}
            </td>
        </tr>
    </table>
</div>

<div class="notice-body">
    <p>This notice informs you that a lien is being processed for the stored vehicle listed above. The current balance as of {$noticeDate} is <strong>\\${$fees['total']}</strong>.</p>
    <p>To avoid lien sale proceedings, payment and release arrangements must be made by <strong>{$dueDate}</strong>.</p>
</div>

<div class="fee-summary">
    <h3>Balance Summary</h3>
    <table>
        <tbody>
            <tr>
                <td>Daily Storage</td>
                <td class="text-right">\\${$fees['daily_amount']}</td>
            </tr>
            <tr>
                <td>Gate Fee</td>
                <td class="text-right">\\${$fees['gate_fee']}</td>
            </tr>
            <tr class="total-row">
                <td><strong>Total Due</strong></td>
                <td class="text-right"><strong>\\${$fees['total']}</strong></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="footer">
    Please contact {$shopName} immediately if you have questions or wish to dispute this notice.
</div>
HTML;

        return $html;
    }

    private function formatDate(DateTimeInterface|string|null $date): string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('M d, Y');
        }

        if (is_string($date)) {
            try {
                $parsed = new \DateTimeImmutable($date);
                return $parsed->format('M d, Y');
            } catch (\Exception $exception) {
                return $date;
            }
        }

        return 'N/A';
    }

    private function getLienStyles(): string
    {
        return <<<CSS
<style>
    .info-section {
        margin-bottom: 20px;
    }
    .fee-summary {
        margin-top: 20px;
    }
    .fee-summary table td {
        border-bottom: 1px solid #eee;
        padding: 8px 6px;
    }
    .fee-summary .total-row td {
        border-top: 2px solid #ddd;
        font-size: 12pt;
    }
    .notice-body {
        margin: 20px 0;
        font-size: 11pt;
    }
</style>
CSS;
    }
}
