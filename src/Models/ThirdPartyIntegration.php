<?php

namespace App\Models;

class ThirdPartyIntegration extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ERROR = 'error';
    public const STATUS_DISABLED = 'disabled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONNECTED,
        self::STATUS_ERROR,
        self::STATUS_DISABLED,
    ];

    public const CATEGORY_ACCOUNTING = 'accounting';
    public const CATEGORY_MAPPING = 'mapping';
    public const CATEGORY_IOT = 'iot';
    public const CATEGORY_TELECOM = 'telecom';
    public const CATEGORY_ACCESS_CONTROL = 'access_control';

    public const CATEGORIES = [
        self::CATEGORY_ACCOUNTING,
        self::CATEGORY_MAPPING,
        self::CATEGORY_IOT,
        self::CATEGORY_TELECOM,
        self::CATEGORY_ACCESS_CONTROL,
    ];

    public ?int $id = null;
    public string $provider_key = '';
    public string $name = '';
    public string $category = '';
    public string $status = self::STATUS_PENDING;
    public ?string $credentials = null;
    public ?array $settings = null;
    public ?int $sync_cadence_minutes = null;
    public ?string $last_sync_at = null;
    public ?string $last_sync_status = null;
    public ?string $last_sync_error = null;
    public ?string $next_sync_at = null;
    public ?int $owner_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
