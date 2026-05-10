<?php

namespace App\Models;

class SsoLoginAttempt extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_EXPIRED,
    ];

    public const SIDE_STAFF = 'staff';
    public const SIDE_PORTAL = 'portal';

    public ?int $id = null;
    public int $provider_id = 0;
    public string $side = self::SIDE_STAFF;
    public string $state = '';
    public ?string $nonce = null;
    public ?string $redirect_uri = null;
    public ?int $user_id = null;
    public ?int $portal_account_id = null;
    public ?int $intended_company_id = null;
    public string $status = self::STATUS_PENDING;
    public ?string $error_message = null;
    public ?string $completed_at = null;
    public ?string $created_at = null;
}
