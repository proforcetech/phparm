<?php

namespace App\Support\Auth;

/**
 * Single source of truth for which setting keys carry credentials and
 * therefore require a fresh TOTP step-up before being read or written.
 *
 * Why an explicit allow-list rather than a regex on `*_secret` / `*_key`:
 * some keys ending in `_key` are PUBLIC (recaptcha.site_key,
 * stripe.public_key) and gating them would be surprising. Explicit > clever.
 *
 * When you add a new third-party integration with a credential, add the
 * key here so it inherits the step-up gate. Tests in
 * tests/SettingsStepUpProtectionTest.php exercise representative cases.
 */
final class SensitiveSettings
{
    /**
     * Setting keys that contain credentials. Saving any one of these via
     * the settings endpoints requires the caller to have a fresh step-up
     * verification.
     *
     * @var array<int, string>
     */
    private const KEYS = [
        // Payment gateways — secrets and webhooks.
        'integrations.stripe.secret_key',
        'integrations.stripe.webhook_secret',
        'integrations.square.token',
        'integrations.square.webhook_signature_key',
        'integrations.paypal.client_id',
        'integrations.paypal.client_secret',
        'integrations.paypal.webhook_id',

        // Notifications — outbound credentials.
        'integrations.smtp.password',
        'integrations.twilio.sid',
        'integrations.twilio.token',

        // Third-party integrations.
        'integrations.zoho.client_id',
        'integrations.zoho.client_secret',
        'integrations.zoho.refresh_token',
        'integrations.zoho.org_id',
        'integrations.partstech.api_key',
        'integrations.bank_feeds.client_id',
        'integrations.bank_feeds.client_secret',
        'integrations.bank_feeds.access_token',
        'integrations.recaptcha.secret_key',

        // Map providers.
        'integrations.mapbox.access_token',
        'integrations.google_maps.api_key',

        // Dispatch ETA provider — same access token model as the above.
        'dispatch.eta.provider',
        'dispatch.eta.api_key',
    ];

    public static function isSensitive(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /**
     * @param array<int|string, mixed> $keys
     */
    public static function anyAreSensitive(array $keys): bool
    {
        foreach ($keys as $key) {
            if (is_string($key) && self::isSensitive($key)) {
                return true;
            }
        }

        return false;
    }
}
