<?php

namespace App\Support\Pdf;

use App\Support\Notifications\TemplateEngine;

class StorageFormPdfGenerator extends PdfService
{
    private const DEFAULT_TOW_AUTH_TEMPLATE = <<<'HTML'
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
                <h2>Tow Authorization</h2>
                <div class="document-info">
                    <strong>Case #:</strong> {{case_number}}<br>
                    <strong>Date:</strong> {{notice_date}}
                </div>
            </td>
        </tr>
    </table>
</div>

<div class="notice-body">
    <p>This document authorizes the release of the vehicle described below to the listed towing provider.</p>
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
    <p>Authorized tow provider: <strong>{{tow_provider}}</strong></p>
    <p>Release location: <strong>{{intake_location}}</strong></p>
</div>

<div class="footer">
    Authorized by {{shop_name}} on {{notice_date}}.
</div>
HTML;

    private const DEFAULT_LIEN_ACK_TEMPLATE = <<<'HTML'
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
                <h2>Lien Notice Acknowledgment</h2>
                <div class="document-info">
                    <strong>Case #:</strong> {{case_number}}<br>
                    <strong>Date:</strong> {{notice_date}}
                </div>
            </td>
        </tr>
    </table>
</div>

<div class="notice-body">
    <p>I, <strong>{{owner_name}}</strong>, acknowledge receipt of the lien notice for the vehicle listed below.</p>
</div>

<div class="info-section">
    <table style="width: 100%; border: none;">
        <tr>
            <td style="width: 50%; border: none; vertical-align: top;">
                <h3>Vehicle Information</h3>
                <strong>{{vehicle_year}} {{vehicle_make}} {{vehicle_model}}</strong><br>
                VIN: {{vehicle_vin}}<br>
                Plate: {{vehicle_license_plate}}
            </td>
            <td style="width: 50%; border: none; vertical-align: top;">
                <h3>Balance Summary</h3>
                <p>Total Due: <strong>{{fees_total}}</strong></p>
                <p>Notice Date: <strong>{{notice_date}}</strong></p>
            </td>
        </tr>
    </table>
</div>

<div class="notice-body">
    <p>Signature: ________________________________</p>
    <p>Date: ________________________________</p>
</div>

<div class="footer">
    Contact {{shop_name}} at {{shop_phone}} with questions about this acknowledgment.
</div>
HTML;

    private TemplateEngine $templateEngine;

    public function __construct()
    {
        parent::__construct();
        $this->templateEngine = new TemplateEngine();
    }

    /**
     * @param array<string, string> $data
     */
    public function generateTowAuthorization(array $data, ?string $template = null): string
    {
        return $this->renderTemplate($template, $data, self::DEFAULT_TOW_AUTH_TEMPLATE);
    }

    /**
     * @param array<string, string> $data
     */
    public function generateLienAcknowledgment(array $data, ?string $template = null): string
    {
        return $this->renderTemplate($template, $data, self::DEFAULT_LIEN_ACK_TEMPLATE);
    }

    /**
     * @param array<string, string> $data
     */
    private function renderTemplate(?string $template, array $data, string $fallback): string
    {
        $html = $this->templateEngine->render(
            $template && $template !== '' ? $template : $fallback,
            array_merge(['styles' => $this->getBaseStyles()], $data)
        );

        return $this->generateFromHtml($html);
    }
}
