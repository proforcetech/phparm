<?php

namespace App\Models;

/**
 * Phase 6.8 of docs/expansion-plan.md — see database/migrations/127_portal_themes.sql.
 */
class PortalTheme extends BaseModel
{
    public int $id = 0;
    public int $company_id = 0;
    public string $display_name = '';
    public ?string $custom_subdomain = null;
    public ?string $custom_domain = null;
    public ?string $primary_color = null;
    public ?string $secondary_color = null;
    public ?string $accent_color = null;
    public ?string $background_color = null;
    public ?string $text_color = null;
    public ?string $logo_url = null;
    public ?string $favicon_url = null;
    public ?string $email_logo_url = null;
    public ?string $email_from_name = null;
    public ?string $email_from_address = null;
    public ?string $email_reply_to = null;
    public ?string $support_phone = null;
    public ?string $support_email = null;
    public ?string $support_url = null;
    public ?string $footer_text = null;
    public int $is_active = 1;
    public int $updated_by_user_id = 0;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
