<?php

namespace App\Models;

/**
 * Software CMDB catalog row — Phase 14 / M9 of docs/woms-expansion-plan.md.
 *
 * One row per SKU we want to track. Customer-scoped so each MSP customer's
 * catalog stays isolated; NULL customer_id means a shared catalog row
 * curated by staff (vendor master list).
 *
 * See migration 163_software_cmdb.sql.
 */
class SoftwareAsset extends BaseModel
{
    public const CATEGORY_OS = 'os';
    public const CATEGORY_PRODUCTIVITY = 'productivity';
    public const CATEGORY_SECURITY = 'security';
    public const CATEGORY_UTILITY = 'utility';
    public const CATEGORY_DEVELOPMENT = 'development';
    public const CATEGORY_BUSINESS = 'business';
    public const CATEGORY_OTHER = 'other';

    public const ALLOWED_CATEGORIES = [
        self::CATEGORY_OS,
        self::CATEGORY_PRODUCTIVITY,
        self::CATEGORY_SECURITY,
        self::CATEGORY_UTILITY,
        self::CATEGORY_DEVELOPMENT,
        self::CATEGORY_BUSINESS,
        self::CATEGORY_OTHER,
    ];

    public const PLATFORM_WINDOWS = 'windows';
    public const PLATFORM_MACOS = 'macos';
    public const PLATFORM_LINUX = 'linux';
    public const PLATFORM_IOS = 'ios';
    public const PLATFORM_ANDROID = 'android';
    public const PLATFORM_WEB = 'web';
    public const PLATFORM_CROSS = 'cross_platform';

    public const ALLOWED_PLATFORMS = [
        self::PLATFORM_WINDOWS,
        self::PLATFORM_MACOS,
        self::PLATFORM_LINUX,
        self::PLATFORM_IOS,
        self::PLATFORM_ANDROID,
        self::PLATFORM_WEB,
        self::PLATFORM_CROSS,
    ];

    public int $id = 0;
    public ?int $customer_id = null;
    public string $publisher = '';
    public string $title = '';
    public ?string $version = null;
    public ?string $edition = null;
    public ?string $category = null;
    public ?string $platform = null;
    public ?string $sku = null;
    public ?string $description = null;
    public int $is_active = 1;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
