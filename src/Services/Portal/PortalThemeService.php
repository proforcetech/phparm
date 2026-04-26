<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalAccount;
use App\Models\PortalTheme;
use App\Models\User;
use App\Services\Crm\CompanyRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;
use PDOException;

/**
 * Phase 6.8 of docs/expansion-plan.md — white-label theming per company.
 *
 * Three surfaces:
 *   * Staff write (upsertTheme / deleteTheme): gated on users.create;
 *     upsert is idempotent by company_id so admin can republish theme
 *     edits without orphan rows.
 *   * Portal read (readForPortal): gated via portal_account scope;
 *     a portal user only ever gets their own company's theme.
 *   * Public read (resolveByHost): pre-auth, used by the login page to
 *     render branded before a JWT exists. Keyed on Host header; caller
 *     layer rate-limits to blunt enumeration.
 *
 * resolveByCompany + PortalTheme default fallback: callers that need a
 * guaranteed theme payload can use resolveOrDefault, which returns a
 * platform-default shape when the company has no custom theme. Keeps
 * the frontend from branching on null.
 */
class PortalThemeService
{
    public const SUBDOMAIN_MIN_LEN = 3;
    public const SUBDOMAIN_MAX_LEN = 63;
    public const DOMAIN_MAX_LEN = 255;
    public const URL_MAX_LEN = 512;
    public const DISPLAY_NAME_MAX_LEN = 120;
    public const FOOTER_MAX_LEN = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly PortalThemeRepository $themes,
        private readonly CompanyRepository $companies,
        private readonly PortalAuthService $portalAuth,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
    ) {
    }

    // ── Staff writes ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function upsertTheme(User $actor, int $companyId, array $input): array
    {
        $this->gate->assert($actor, 'users.create');
        if ($companyId <= 0) {
            throw new InvalidArgumentException('company id is required');
        }
        $company = $this->companies->findById($companyId);
        if ($company === null) {
            throw new InvalidArgumentException("company {$companyId} not found");
        }

        $fields = $this->validateInput($input);

        try {
            $themeId = $this->themes->upsert($companyId, $fields, $actor->id);
        } catch (PDOException $e) {
            // UNIQUE violation on subdomain/domain — surface the conflict
            // back to the caller with a legible message instead of a 500.
            if ($this->isUniqueViolation($e)) {
                throw new InvalidArgumentException(
                    'custom_subdomain or custom_domain is already claimed by another company'
                );
            }
            throw $e;
        }

        $this->audit->log(new AuditEntry(
            'portal.theme.upserted',
            'portal_theme',
            $themeId,
            $actor->id,
            [
                'company_id' => $companyId,
                'has_subdomain' => $fields['custom_subdomain'] !== null,
                'has_domain' => $fields['custom_domain'] !== null,
                'has_logo' => $fields['logo_url'] !== null,
                'is_active' => $fields['is_active'],
            ],
        ));

        $theme = $this->themes->findByCompany($companyId);
        if ($theme === null) {
            // Repository just wrote it — should not happen, but treat as
            // a hard failure rather than returning a stale payload.
            throw new \RuntimeException("theme for company {$companyId} vanished after upsert");
        }
        return $this->serialize($theme);
    }

    public function deleteTheme(User $actor, int $companyId): void
    {
        $this->gate->assert($actor, 'users.create');
        if ($companyId <= 0) {
            throw new InvalidArgumentException('company id is required');
        }
        $existing = $this->themes->findByCompany($companyId);
        if ($existing === null) {
            throw new InvalidArgumentException("no theme configured for company {$companyId}");
        }
        $this->themes->deleteByCompany($companyId);

        $this->audit->log(new AuditEntry(
            'portal.theme.deleted',
            'portal_theme',
            $existing->id,
            $actor->id,
            ['company_id' => $companyId],
        ));
    }

    /**
     * Staff-facing read — requires users.view (any staff can see a
     * company's branding). No default-fallback here; admin UI wants to
     * know "configured vs unconfigured".
     *
     * @return array<string, mixed>|null
     */
    public function readForCompanyStaff(User $actor, int $companyId): ?array
    {
        $this->gate->assert($actor, 'users.view');
        if ($companyId <= 0) {
            throw new InvalidArgumentException('company id is required');
        }
        $theme = $this->themes->findByCompany($companyId);
        return $theme === null ? null : $this->serialize($theme);
    }

    // ── Portal read ──────────────────────────────────────────────────────

    /**
     * Portal user reads their own company's theme. Falls back to
     * platform default if the company has no theme row so the
     * frontend never has to branch on null.
     *
     * @return array<string, mixed>
     */
    public function readForPortal(User $user, PortalAccount $account): array
    {
        $this->assertUsable($account);
        $theme = $this->themes->findByCompany($account->company_id);
        return $theme === null
            ? $this->defaultPayload($account->company_id)
            : $this->serialize($theme);
    }

    // ── Public read (pre-auth) ───────────────────────────────────────────

    /**
     * Resolve a theme from a request's Host header. Used by the login
     * page so that portal.acme.com / acme.portal.example.com renders
     * branded before any authentication has happened.
     *
     * Returns null if the host does not match any configured tenant —
     * callers should return the platform default payload in that case
     * rather than leaking "exists vs not" via status codes.
     *
     * @return array<string, mixed>|null
     */
    public function resolveByHost(?string $host): ?array
    {
        if ($host === null) {
            return null;
        }
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }
        // Strip ":port" if present.
        if (($colon = strpos($host, ':')) !== false) {
            $host = substr($host, 0, $colon);
        }

        // Exact FQDN match first — vanity domains win over subdomains.
        $byDomain = $this->themes->findByDomain($host);
        if ($byDomain !== null) {
            return $this->serialize($byDomain);
        }
        // Subdomain slug is the left-most label, e.g. "acme" in
        // "acme.portal.example.com". Only treat as a subdomain lookup
        // when there are at least two dots so we don't confuse a bare
        // hostname for a tenant slug.
        if (substr_count($host, '.') >= 2) {
            $slug = substr($host, 0, (int) strpos($host, '.'));
            if ($slug !== '' && preg_match('/^[a-z0-9-]+$/', $slug) === 1) {
                $bySub = $this->themes->findBySubdomain($slug);
                if ($bySub !== null) {
                    return $this->serialize($bySub);
                }
            }
        }
        return null;
    }

    /**
     * Public helper — returns the platform default payload for callers
     * that always want a theme shape back.
     *
     * @return array<string, mixed>
     */
    public function publicResolveOrDefault(?string $host): array
    {
        $resolved = $this->resolveByHost($host);
        return $resolved ?? $this->defaultPayload(null);
    }

    // ── internals: validation ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateInput(array $input): array
    {
        $displayName = $this->trimmedString($input, 'display_name');
        if ($displayName === null || $displayName === '') {
            throw new InvalidArgumentException('display_name is required');
        }
        if (strlen($displayName) > self::DISPLAY_NAME_MAX_LEN) {
            throw new InvalidArgumentException('display_name exceeds ' . self::DISPLAY_NAME_MAX_LEN . ' chars');
        }

        $subdomain = $this->trimmedString($input, 'custom_subdomain');
        if ($subdomain !== null && $subdomain !== '') {
            $subdomain = strtolower($subdomain);
            if (!$this->isValidSubdomain($subdomain)) {
                throw new InvalidArgumentException(
                    'custom_subdomain must be ' . self::SUBDOMAIN_MIN_LEN . '-' . self::SUBDOMAIN_MAX_LEN
                    . ' chars of [a-z0-9-] with no leading/trailing dash'
                );
            }
        } else {
            $subdomain = null;
        }

        $domain = $this->trimmedString($input, 'custom_domain');
        if ($domain !== null && $domain !== '') {
            $domain = strtolower($domain);
            if (!$this->isValidDomain($domain)) {
                throw new InvalidArgumentException(
                    'custom_domain must be a valid FQDN, <= ' . self::DOMAIN_MAX_LEN . ' chars'
                );
            }
        } else {
            $domain = null;
        }

        $colors = [
            'primary_color' => $this->hexOrNull($input, 'primary_color'),
            'secondary_color' => $this->hexOrNull($input, 'secondary_color'),
            'accent_color' => $this->hexOrNull($input, 'accent_color'),
            'background_color' => $this->hexOrNull($input, 'background_color'),
            'text_color' => $this->hexOrNull($input, 'text_color'),
        ];

        $urls = [
            'logo_url' => $this->urlOrNull($input, 'logo_url'),
            'favicon_url' => $this->urlOrNull($input, 'favicon_url'),
            'email_logo_url' => $this->urlOrNull($input, 'email_logo_url'),
            'support_url' => $this->urlOrNull($input, 'support_url'),
        ];

        $emailFromName = $this->trimmedString($input, 'email_from_name');
        if ($emailFromName !== null && strlen($emailFromName) > 120) {
            throw new InvalidArgumentException('email_from_name exceeds 120 chars');
        }
        $emailFromAddress = $this->emailOrNull($input, 'email_from_address');
        $emailReplyTo = $this->emailOrNull($input, 'email_reply_to');
        $supportEmail = $this->emailOrNull($input, 'support_email');

        $supportPhone = $this->trimmedString($input, 'support_phone');
        if ($supportPhone !== null && strlen($supportPhone) > 40) {
            throw new InvalidArgumentException('support_phone exceeds 40 chars');
        }

        $footerText = $this->trimmedString($input, 'footer_text');
        if ($footerText !== null && strlen($footerText) > self::FOOTER_MAX_LEN) {
            throw new InvalidArgumentException('footer_text exceeds ' . self::FOOTER_MAX_LEN . ' chars');
        }

        $isActive = 1;
        if (array_key_exists('is_active', $input)) {
            $isActive = (int) (bool) $input['is_active'];
        }

        return array_merge(
            [
                'display_name' => $displayName,
                'custom_subdomain' => $subdomain,
                'custom_domain' => $domain,
            ],
            $colors,
            $urls,
            [
                'email_from_name' => $emailFromName,
                'email_from_address' => $emailFromAddress,
                'email_reply_to' => $emailReplyTo,
                'support_phone' => $supportPhone,
                'support_email' => $supportEmail,
                'footer_text' => $footerText,
                'is_active' => $isActive,
            ]
        );
    }

    private function isValidSubdomain(string $sub): bool
    {
        $len = strlen($sub);
        if ($len < self::SUBDOMAIN_MIN_LEN || $len > self::SUBDOMAIN_MAX_LEN) {
            return false;
        }
        if ($sub[0] === '-' || $sub[$len - 1] === '-') {
            return false;
        }
        return preg_match('/^[a-z0-9-]+$/', $sub) === 1;
    }

    private function isValidDomain(string $dom): bool
    {
        if (strlen($dom) > self::DOMAIN_MAX_LEN) {
            return false;
        }
        // Require a dot so bare hostnames (e.g. "localhost") are rejected.
        if (strpos($dom, '.') === false) {
            return false;
        }
        // filter_var with FILTER_VALIDATE_DOMAIN + FILTER_FLAG_HOSTNAME
        // rejects leading/trailing dashes and enforces DNS label rules.
        return filter_var($dom, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function trimmedString(array $input, string $key): ?string
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }
        if (!is_string($input[$key])) {
            throw new InvalidArgumentException("{$key} must be a string");
        }
        $v = trim($input[$key]);
        return $v === '' ? null : $v;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function hexOrNull(array $input, string $key): ?string
    {
        $v = $this->trimmedString($input, $key);
        if ($v === null) {
            return null;
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $v) !== 1) {
            throw new InvalidArgumentException("{$key} must be a #RRGGBB hex color");
        }
        return strtolower($v);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function urlOrNull(array $input, string $key): ?string
    {
        $v = $this->trimmedString($input, $key);
        if ($v === null) {
            return null;
        }
        if (strlen($v) > self::URL_MAX_LEN) {
            throw new InvalidArgumentException("{$key} exceeds " . self::URL_MAX_LEN . ' chars');
        }
        // Only http/https accepted so we don't render javascript: or
        // data: in the portal shell.
        if (!preg_match('#^https?://#i', $v)) {
            throw new InvalidArgumentException("{$key} must be an http(s) URL");
        }
        if (filter_var($v, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("{$key} is not a valid URL");
        }
        return $v;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function emailOrNull(array $input, string $key): ?string
    {
        $v = $this->trimmedString($input, $key);
        if ($v === null) {
            return null;
        }
        if (strlen($v) > 255) {
            throw new InvalidArgumentException("{$key} exceeds 255 chars");
        }
        if (filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("{$key} is not a valid email");
        }
        return $v;
    }

    private function isUniqueViolation(PDOException $e): bool
    {
        // MySQL: SQLSTATE 23000 + errno 1062. SQLite: SQLSTATE 23000
        // (constraint failed) — both reach here in production and tests.
        if (($e->getCode() ?? '') === '23000') {
            return true;
        }
        $msg = $e->getMessage();
        return stripos($msg, 'duplicate') !== false
            || stripos($msg, 'UNIQUE constraint') !== false;
    }

    private function assertUsable(PortalAccount $account): void
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPayload(?int $companyId): array
    {
        return [
            'is_default' => true,
            'company_id' => $companyId,
            'display_name' => 'Customer Portal',
            'custom_subdomain' => null,
            'custom_domain' => null,
            'primary_color' => null,
            'secondary_color' => null,
            'accent_color' => null,
            'background_color' => null,
            'text_color' => null,
            'logo_url' => null,
            'favicon_url' => null,
            'email_logo_url' => null,
            'email_from_name' => null,
            'email_from_address' => null,
            'email_reply_to' => null,
            'support_phone' => null,
            'support_email' => null,
            'support_url' => null,
            'footer_text' => null,
            'is_active' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PortalTheme $t): array
    {
        return [
            'is_default' => false,
            'id' => $t->id,
            'company_id' => $t->company_id,
            'display_name' => $t->display_name,
            'custom_subdomain' => $t->custom_subdomain,
            'custom_domain' => $t->custom_domain,
            'primary_color' => $t->primary_color,
            'secondary_color' => $t->secondary_color,
            'accent_color' => $t->accent_color,
            'background_color' => $t->background_color,
            'text_color' => $t->text_color,
            'logo_url' => $t->logo_url,
            'favicon_url' => $t->favicon_url,
            'email_logo_url' => $t->email_logo_url,
            'email_from_name' => $t->email_from_name,
            'email_from_address' => $t->email_from_address,
            'email_reply_to' => $t->email_reply_to,
            'support_phone' => $t->support_phone,
            'support_email' => $t->support_email,
            'support_url' => $t->support_url,
            'footer_text' => $t->footer_text,
            'is_active' => $t->is_active,
            'updated_by_user_id' => $t->updated_by_user_id,
            'created_at' => $t->created_at,
            'updated_at' => $t->updated_at,
        ];
    }
}
