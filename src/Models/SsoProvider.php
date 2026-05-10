<?php

namespace App\Models;

class SsoProvider extends BaseModel
{
    public const TYPE_OIDC = 'oidc';
    public const TYPE_SAML = 'saml';

    public const TYPES = [self::TYPE_OIDC, self::TYPE_SAML];

    public ?int $id = null;
    public string $slug = '';
    public ?int $company_id = null;
    public string $name = '';
    public string $type = self::TYPE_OIDC;
    public ?string $issuer_url = null;
    public ?string $client_id = null;
    public ?string $client_secret = null;
    public ?string $redirect_uri = null;
    public ?string $authorize_endpoint = null;
    public ?string $token_endpoint = null;
    public ?string $userinfo_endpoint = null;
    public ?string $jwks_uri = null;
    public string $scopes = 'openid email profile';
    public bool $is_active = true;
    public bool $portal_enabled = false;
    public bool $staff_enabled = true;
    public bool $auto_provision = false;
    public ?string $default_role = null;
    public bool $sync_profile_on_login = true;
    public ?array $metadata = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
