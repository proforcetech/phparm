<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\PortalNotificationPreference;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

/**
 * Phase 2f — per-portal-account notification preferences.
 *
 * Five fixed pref keys across three channels = a 5x3 matrix the user can
 * toggle freely. Defaults: email + in_app on for everything except
 * csat_request (in_app only by default — surveys feel pushy as email).
 * SMS is always opt-in.
 *
 * The dispatch path elsewhere should call shouldDeliver(account, key,
 * channel) to honor a user's choice. Stored rows are the source of truth;
 * absence falls back to the per-(key, channel) default in DEFAULTS so a
 * brand-new account doesn't have to seed every row before it can receive
 * email notifications.
 */
class PortalNotificationPreferenceService
{
    public const KEY_REQUEST_STATUS = 'request_status';
    public const KEY_INVOICE_ISSUED = 'invoice_issued';
    public const KEY_WORK_COMPLETE = 'work_complete';
    public const KEY_CSAT_REQUEST = 'csat_request';
    public const KEY_MESSAGE_RECEIVED = 'message_received';

    public const PREF_KEYS = [
        self::KEY_REQUEST_STATUS,
        self::KEY_INVOICE_ISSUED,
        self::KEY_WORK_COMPLETE,
        self::KEY_CSAT_REQUEST,
        self::KEY_MESSAGE_RECEIVED,
    ];

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_IN_APP = 'in_app';

    public const CHANNELS = [
        self::CHANNEL_EMAIL,
        self::CHANNEL_SMS,
        self::CHANNEL_IN_APP,
    ];

    /**
     * Default-on matrix. Indexed [pref_key][channel] => bool.
     *
     * @var array<string, array<string, bool>>
     */
    private const DEFAULTS = [
        self::KEY_REQUEST_STATUS => [
            self::CHANNEL_EMAIL => true,
            self::CHANNEL_SMS => false,
            self::CHANNEL_IN_APP => true,
        ],
        self::KEY_INVOICE_ISSUED => [
            self::CHANNEL_EMAIL => true,
            self::CHANNEL_SMS => false,
            self::CHANNEL_IN_APP => true,
        ],
        self::KEY_WORK_COMPLETE => [
            self::CHANNEL_EMAIL => true,
            self::CHANNEL_SMS => false,
            self::CHANNEL_IN_APP => true,
        ],
        self::KEY_CSAT_REQUEST => [
            self::CHANNEL_EMAIL => false,
            self::CHANNEL_SMS => false,
            self::CHANNEL_IN_APP => true,
        ],
        self::KEY_MESSAGE_RECEIVED => [
            self::CHANNEL_EMAIL => true,
            self::CHANNEL_SMS => false,
            self::CHANNEL_IN_APP => true,
        ],
    ];

    public function __construct(
        private readonly PortalNotificationPreferenceRepository $prefs,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Returns the full matrix as a list, merging stored overrides on top
     * of defaults. Always 5 keys * 3 channels = 15 rows so the UI doesn't
     * need to know which combinations exist server-side.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listMatrix(User $user, PortalAccount $account): array
    {
        $this->assertUsable($account);
        $stored = [];
        foreach ($this->prefs->listForAccount($account->id) as $row) {
            $stored[$row->pref_key][$row->channel] = (bool) $row->enabled;
        }
        $out = [];
        foreach (self::PREF_KEYS as $key) {
            foreach (self::CHANNELS as $channel) {
                $default = self::DEFAULTS[$key][$channel];
                $enabled = $stored[$key][$channel] ?? $default;
                $out[] = [
                    'pref_key' => $key,
                    'channel' => $channel,
                    'enabled' => $enabled,
                    'is_default' => !isset($stored[$key][$channel]),
                ];
            }
        }
        return $out;
    }

    /**
     * Replace a single matrix cell. Logs to audit so account holders can
     * later see who flipped what (sub-users may toggle each other's prefs
     * via shared accounts in some deployments).
     */
    public function set(
        User $user,
        PortalAccount $account,
        string $prefKey,
        string $channel,
        bool $enabled
    ): PortalNotificationPreference {
        $this->assertUsable($account);
        $this->assertKnownKey($prefKey);
        $this->assertKnownChannel($channel);

        $row = $this->prefs->upsert($account->id, $prefKey, $channel, $enabled);

        $this->audit->log(new AuditEntry(
            'portal.notification_pref.set',
            'portal_notification_preference',
            $row->id,
            $user->id,
            [
                'portal_account_id' => $account->id,
                'pref_key' => $prefKey,
                'channel' => $channel,
                'enabled' => $enabled,
            ]
        ));

        return $row;
    }

    /**
     * Dispatch-side helper. Call before sending a notification to honor the
     * user's matrix. Returns the default if nothing is stored, so brand-new
     * accounts behave predictably.
     */
    public function shouldDeliver(int $accountId, string $prefKey, string $channel): bool
    {
        if (!in_array($prefKey, self::PREF_KEYS, true)) {
            return false;
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            return false;
        }
        $stored = $this->prefs->findOne($accountId, $prefKey, $channel);
        if ($stored !== null) {
            return (bool) $stored->enabled;
        }
        return self::DEFAULTS[$prefKey][$channel];
    }

    private function assertUsable(PortalAccount $account): void
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }
    }

    private function assertKnownKey(string $prefKey): void
    {
        if (!in_array($prefKey, self::PREF_KEYS, true)) {
            throw new InvalidArgumentException(
                'pref_key must be one of: ' . implode(', ', self::PREF_KEYS)
            );
        }
    }

    private function assertKnownChannel(string $channel): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException(
                'channel must be one of: ' . implode(', ', self::CHANNELS)
            );
        }
    }
}
