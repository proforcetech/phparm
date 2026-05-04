<?php

namespace App\Models;

/**
 * Issued security credential — Phase 16 / S1 of docs/woms-expansion-plan.md.
 *
 * One row per credential (card, fob, PIN, mobile token, biometric ID, license
 * plate). Scoped to a customer. Door assignments live in the
 * `credential_doors` table; the per-credential history view joins through to
 * `programming_logs` filtered on (target_type='credential', target_id=this.id).
 *
 * See migration 166_security_pos_verticals.sql.
 */
class CredentialRegister extends BaseModel
{
    public const TYPE_CARD = 'card';
    public const TYPE_FOB = 'fob';
    public const TYPE_PIN = 'pin';
    public const TYPE_MOBILE = 'mobile';
    public const TYPE_BIOMETRIC = 'biometric';
    public const TYPE_PLATE = 'plate';

    public const ALLOWED_TYPES = [
        self::TYPE_CARD,
        self::TYPE_FOB,
        self::TYPE_PIN,
        self::TYPE_MOBILE,
        self::TYPE_BIOMETRIC,
        self::TYPE_PLATE,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_LOST = 'lost';

    public const ALLOWED_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_REVOKED,
        self::STATUS_LOST,
    ];

    public int $id = 0;
    public int $customer_id = 0;
    public ?int $site_id = null;
    public string $holder_name = '';
    public ?string $holder_email = null;
    public ?string $holder_phone = null;
    public ?string $holder_employee_id = null;
    public string $credential_type = self::TYPE_CARD;
    public string $credential_code = '';
    public ?string $credential_format = null;
    public string $status = self::STATUS_ACTIVE;
    public ?string $issued_at = null;
    public ?string $expires_at = null;
    public ?string $suspended_at = null;
    public ?string $revoked_at = null;
    public ?string $revoke_reason = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
    public ?int $created_by_user_id = null;
    public ?int $updated_by_user_id = null;

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_REVOKED, self::STATUS_LOST], true);
    }

    public function isExpired(?string $now = null): bool
    {
        if ($this->expires_at === null || $this->expires_at === '') {
            return false;
        }
        $now ??= date('Y-m-d H:i:s');
        return $this->expires_at < $now;
    }

    /**
     * PIN credentials must never be returned to clients with the raw code.
     * Repositories call this to redact before serialization.
     */
    public function redactCodeIfSensitive(): void
    {
        if ($this->credential_type === self::TYPE_PIN) {
            $this->credential_code = '***';
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
