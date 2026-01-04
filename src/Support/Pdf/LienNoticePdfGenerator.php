<?php

namespace App\Support\Pdf;

use DateTimeInterface;
use App\Support\Notifications\TemplateEngine;

class LienNoticePdfGenerator extends PdfService
{
    private const DEFAULT_NOTICE_OF_CLAIM_TEMPLATE = <<<'HTML'
{{styles}}
<div class="header">
    <table style="width: 100%; border: none;">
        <tr>
            <td style="width: 60%; border: none;">
                <h1>{{shop_name}}</h1>
                <div class="company-info">
                    {{shop_address}}<br>
                    {{shop_phone}}
                </div>
            </td>
            <td style="width: 40%; border: none; text-align: right;">
                <h2>NOTICE OF CLAIM</h2>
                <div class="document-info">
                    <strong>Case #:</strong> {{case_number}}<br>
                    <strong>Date:</strong> {{notice_date}}<br>
                    <strong>Status:</strong> <span class="status status-{{status}}">{{status}}</span>
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
                <strong>{{owner_name}}</strong><br>
                {{owner_address}}<br>
                {{owner_city}}, {{owner_state}} {{owner_zip}}<br>
                {{owner_phone}}
            </td>
            <td style="width: 50%; border: none; vertical-align: top;">
                <h3>Vehicle Information</h3>
                <strong>{{vehicle_year}} {{vehicle_make}} {{vehicle_model}}</strong><br>
                VIN: {{vehicle_vin}}<br>
                Plate: {{vehicle_license_plate}}<br>
                Stored At: {{intake_location}}
            </td>
        </tr>
    </table>
</div>

<div class="fee-summary">
    <h3>Storage Fee Summary</h3>
    <table>
        <tbody>
            <tr>
                <td>Daily Rate ({{fees_billable_days}} days @ {{fees_daily_rate}})</td>
                <td class="text-right">{{fees_daily_amount}}</td>
            </tr>
            <tr>
                <td>Gate Fee</td>
                <td class="text-right">{{fees_gate_fee}}</td>
            </tr>
            <tr class="total-row">
                <td><strong>Total Due</strong></td>
                <td class="text-right"><strong>{{fees_total}}</strong></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="notice-body">
    <p>This notice serves as a formal claim for the above-referenced vehicle. Storage charges will continue to accrue until the vehicle is released.</p>
    <p>Please contact our office to arrange payment and release. Failure to respond may result in lien processing under state law.</p>
</div>

<div class="footer">
    This notice was generated on {{notice_date}}. Keep a copy for your records.
</div>
HTML;

    private const DEFAULT_LIEN_NOTICE_TEMPLATE = <<<'HTML'
{{styles}}
<div class="header">
    <table style="width: 100%; border: none;">
        <tr>
            <td style="width: 60%; border: none;">
                <h1>{{shop_name}}</h1>
                <div class="company-info">
                    {{shop_address}}<br>
                    {{shop_phone}}
                </div>
            </td>
            <td style="width: 40%; border: none; text-align: right;">
                <h2>{{notice_type}}</h2>
                <div class="document-info">
                    <strong>Case #:</strong> {{case_number}}<br>
                    <strong>Date:</strong> {{notice_date}}<br>
                    <strong>Due:</strong> {{due_date}}
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
                <strong>{{owner_name}}</strong><br>
                {{owner_address}}<br>
                {{owner_city}}, {{owner_state}} {{owner_zip}}<br>
                {{owner_phone}}
            </td>
            <td style="width: 50%; border: none; vertical-align: top;">
                <h3>Vehicle Information</h3>
                <strong>{{vehicle_year}} {{vehicle_make}} {{vehicle_model}}</strong><br>
                VIN: {{vehicle_vin}}<br>
                Plate: {{vehicle_license_plate}}
            </td>
        </tr>
    </table>
</div>

<div class="notice-body">
    <p>This notice informs you that a lien is being processed for the stored vehicle listed above. The current balance as of {{notice_date}} is <strong>{{fees_total}}</strong>.</p>
    <p>To avoid lien sale proceedings, payment and release arrangements must be made by <strong>{{due_date}}</strong>.</p>
</div>

<div class="fee-summary">
    <h3>Balance Summary</h3>
    <table>
        <tbody>
            <tr>
                <td>Daily Storage</td>
                <td class="text-right">{{fees_daily_amount}}</td>
            </tr>
            <tr>
                <td>Gate Fee</td>
                <td class="text-right">{{fees_gate_fee}}</td>
            </tr>
            <tr class="total-row">
                <td><strong>Total Due</strong></td>
                <td class="text-right"><strong>{{fees_total}}</strong></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="footer">
    Please contact {{shop_name}} immediately if you have questions or wish to dispute this notice.
</div>
HTML;

    private TemplateEngine $templateEngine;

    public function __construct()
    {
        parent::__construct();
        $this->templateEngine = new TemplateEngine();
    }

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
        $template = $this->resolveTemplate(
            $settings,
            'storage.notice.notice_of_claim',
            self::DEFAULT_NOTICE_OF_CLAIM_TEMPLATE
        );

        $data = $this->buildNoticeTemplateData($case, $owner, $vehicle, $fees, $settings, false);
        $data['styles'] = $this->getBaseStyles() . $this->getLienStyles();

        return $this->templateEngine->render($template, $data);
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
        $template = $this->resolveTemplate(
            $settings,
            'storage.notice.lien_notice',
            self::DEFAULT_LIEN_NOTICE_TEMPLATE
        );

        $data = $this->buildNoticeTemplateData($case, $owner, $vehicle, $fees, $settings, true, $notice);
        $data['styles'] = $this->getBaseStyles() . $this->getLienStyles();

        return $this->templateEngine->render($template, $data);
    }

    /**
     * @param array<string, mixed> $case
     * @param array<string, mixed> $owner
     * @param array<string, mixed> $vehicle
     * @param array<string, mixed> $fees
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $notice
     * @return array<string, string>
     */
    private function buildNoticeTemplateData(
        array $case,
        array $owner,
        array $vehicle,
        array $fees,
        array $settings,
        bool $includeNoticeInfo,
        array $notice = []
    ): array {
        $shopName = $this->resolveSetting($settings, ['shop_name', 'shop.name'], 'Storage Facility');
        $shopAddress = $this->resolveShopAddress($settings);
        $shopPhone = $this->resolveSetting($settings, ['shop_phone', 'shop.phone'], '');

        $noticeDate = $this->formatDate($notice['notice_date'] ?? $case['notice_date'] ?? new \DateTimeImmutable('now'));
        $caseNumber = (string) ($case['case_number'] ?? 'N/A');
        $status = (string) ($case['status'] ?? 'open');

        $data = [
            'shop_name' => $shopName,
            'shop_address' => $shopAddress,
            'shop_phone' => $shopPhone,
            'notice_date' => $noticeDate,
            'case_number' => $caseNumber,
            'status' => $status,
            'owner_name' => (string) ($owner['name'] ?? ''),
            'owner_address' => (string) ($owner['address'] ?? ''),
            'owner_city' => (string) ($owner['city'] ?? ''),
            'owner_state' => (string) ($owner['state'] ?? ''),
            'owner_zip' => (string) ($owner['zip'] ?? ''),
            'owner_phone' => (string) ($owner['phone'] ?? ''),
            'vehicle_year' => (string) ($vehicle['year'] ?? ''),
            'vehicle_make' => (string) ($vehicle['make'] ?? ''),
            'vehicle_model' => (string) ($vehicle['model'] ?? ''),
            'vehicle_vin' => (string) ($vehicle['vin'] ?? ''),
            'vehicle_license_plate' => (string) ($vehicle['license_plate'] ?? ''),
            'intake_location' => (string) ($case['intake_location'] ?? ''),
            'fees_billable_days' => (string) ($fees['billable_days'] ?? '0'),
            'fees_daily_rate' => $this->formatCurrency($fees['daily_rate'] ?? 0),
            'fees_daily_amount' => $this->formatCurrency($fees['daily_amount'] ?? 0),
            'fees_gate_fee' => $this->formatCurrency($fees['gate_fee'] ?? 0),
            'fees_total' => $this->formatCurrency($fees['total'] ?? 0),
        ];

        if ($includeNoticeInfo) {
            $data['notice_type'] = (string) ($notice['notice_type'] ?? 'Lien Notice');
            $data['due_date'] = $this->formatDate($notice['due_date'] ?? null);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveTemplate(array $settings, string $key, string $fallback): string
    {
        $template = $settings[$key] ?? null;
        if (is_string($template) && $template !== '') {
            return $template;
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<int, string> $keys
     */
    private function resolveSetting(array $settings, array $keys, string $fallback): string
    {
        foreach ($keys as $key) {
            if (isset($settings[$key])) {
                $value = $settings[$key];
                if (is_string($value)) {
                    return $value;
                }
                if ($value !== null) {
                    return (string) $value;
                }
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveShopAddress(array $settings): string
    {
        if (!empty($settings['shop_address'])) {
            return (string) $settings['shop_address'];
        }

        $address = $settings['shop.address'] ?? null;
        if (is_array($address)) {
            $street = $address['street'] ?? '';
            $city = $address['city'] ?? '';
            $state = $address['state'] ?? '';
            $postal = $address['postal_code'] ?? '';
            $country = $address['country'] ?? '';

            $lines = array_filter([
                trim((string) $street),
                trim(sprintf('%s%s%s', $city, $state ? ', ' . $state : '', $postal ? ' ' . $postal : '')),
                trim((string) $country),
            ]);

            return implode('<br>', $lines);
        }

        if (is_string($address)) {
            return $address;
        }

        return '';
    }

    private function formatCurrency(mixed $amount): string
    {
        if (is_string($amount) && str_contains($amount, '$')) {
            return $amount;
        }

        if (!is_numeric($amount)) {
            return '$0.00';
        }

        return '$' . number_format((float) $amount, 2);
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
