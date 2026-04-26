<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "PortalThemeServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\Company;
use App\Models\PortalAccount;
use App\Models\User;
use App\Services\Crm\CompanyRepository;
use App\Services\Portal\PortalAuthService;
use App\Services\Portal\PortalThemeRepository;
use App\Services\Portal\PortalThemeService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 6.8 of docs/expansion-plan.md — portal theming. SQLite-in-memory
 * covers upsert idempotency, validation, host resolution (domain and
 * subdomain), portal three-layer scope, unique constraint conflicts,
 * default-payload fallback, and admin permission gating.
 */

// ---------------------------------------------------------------------------
// SQLite-backed Connection + schema
// ---------------------------------------------------------------------------

class ThemeInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner) {}
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function themeSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE portal_themes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL UNIQUE,
            display_name TEXT NOT NULL,
            custom_subdomain TEXT NULL UNIQUE,
            custom_domain TEXT NULL UNIQUE,
            primary_color TEXT NULL,
            secondary_color TEXT NULL,
            accent_color TEXT NULL,
            background_color TEXT NULL,
            text_color TEXT NULL,
            logo_url TEXT NULL,
            favicon_url TEXT NULL,
            email_logo_url TEXT NULL,
            email_from_name TEXT NULL,
            email_from_address TEXT NULL,
            email_reply_to TEXT NULL,
            support_phone TEXT NULL,
            support_email TEXT NULL,
            support_url TEXT NULL,
            footer_text TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            updated_by_user_id INTEGER NOT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )'
    );
    return $pdo;
}

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class ThemeFakeAudit extends AuditLogger
{
    /** @var AuditEntry[] */
    public array $entries = [];
    public function __construct() {}
    public function log(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

class ThemeFakeCompanies extends CompanyRepository
{
    /** @var array<int, Company> */
    public array $store = [];
    public function __construct() {}
    public function findById(int $id): ?Company
    {
        return $this->store[$id] ?? null;
    }
    public function seed(int $id, string $name = 'Acme Corp'): Company
    {
        $c = new Company();
        $c->id = $id;
        $c->name = $name;
        $this->store[$id] = $c;
        return $c;
    }
}

function themePortalAuthStub(): PortalAuthService
{
    return new class extends PortalAuthService {
        public function __construct() {}
    };
}

class ThemePermissiveGate extends AccessGate
{
    /** @var array<string, bool> */
    public array $denials = [];
    public function __construct() {}
    public function can(User $user, string $permission, mixed $resource = null): bool
    {
        return empty($this->denials[$permission]);
    }
    public function assert(User $user, string $permission, mixed $resource = null): void
    {
        if (!empty($this->denials[$permission])) {
            throw new UnauthorizedException('User lacks permission: ' . $permission);
        }
    }
}

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------

function makeThemeFixture(int $companyId = 10): array
{
    $pdo = themeSetUpDatabase();
    $conn = new ThemeInMemoryConnection($pdo);
    $audit = new ThemeFakeAudit();
    $repo = new PortalThemeRepository($conn);
    $companies = new ThemeFakeCompanies();
    $companies->seed($companyId);
    $portalAuth = themePortalAuthStub();
    $gate = new ThemePermissiveGate();
    $service = new PortalThemeService(
        $conn, $repo, $companies, $portalAuth, $gate, $audit,
    );

    $staff = new User();
    $staff->id = 42;

    $portalUser = new User();
    $portalUser->id = 999;

    $account = new PortalAccount();
    $account->id = 77;
    $account->user_id = 999;
    $account->company_id = $companyId;
    $account->is_active = true;

    return compact(
        'service', 'pdo', 'conn', 'repo', 'companies',
        'audit', 'gate', 'staff', 'portalUser', 'account',
    );
}

function assertThemeThrows(callable $fn, string $cls, string $needle, string $label): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!($e instanceof $cls)) {
            throw new RuntimeException(
                "[{$label}] expected {$cls}, got " . get_class($e) . ': ' . $e->getMessage()
            );
        }
        if ($needle !== '' && stripos($e->getMessage(), $needle) === false) {
            throw new RuntimeException(
                "[{$label}] message '{$e->getMessage()}' missing '{$needle}'"
            );
        }
        return;
    }
    throw new RuntimeException("[{$label}] expected {$cls} — none thrown");
}

function themeAssert(bool $cond, string $label): void
{
    if (!$cond) {
        throw new RuntimeException("[{$label}] assertion failed");
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;
$only = $argv[1] ?? null;

function testTheme(string $name, callable $fn, ?string $only, int &$passed, int &$failed): void
{
    if ($only !== null && stripos($name, $only) === false) {
        return;
    }
    try {
        $fn();
        echo "  ✓ {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  ✗ {$name}\n    " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "PortalThemeServiceTest\n";

// 1. Initial upsert creates theme
testTheme('upsertTheme creates initial theme row', function (): void {
    $f = makeThemeFixture();
    $out = $f['service']->upsertTheme($f['staff'], 10, [
        'display_name' => 'Acme Portal',
        'primary_color' => '#112233',
        'logo_url' => 'https://cdn.example.com/logo.png',
        'support_email' => 'support@acme.com',
        'custom_subdomain' => 'acme',
    ]);
    themeAssert($out['id'] > 0, 'id positive');
    themeAssert($out['display_name'] === 'Acme Portal', 'display_name');
    themeAssert($out['primary_color'] === '#112233', 'primary color');
    themeAssert($out['custom_subdomain'] === 'acme', 'subdomain stored');
    themeAssert($out['is_default'] === false, 'is_default false');
    themeAssert(count($f['audit']->entries) === 1, 'one audit');
    themeAssert($f['audit']->entries[0]->event === 'portal.theme.upserted', 'audit event');
}, $only, $passed, $failed);

// 2. Second upsert updates same row (idempotent by company)
testTheme('upsertTheme is idempotent by company', function (): void {
    $f = makeThemeFixture();
    $first = $f['service']->upsertTheme($f['staff'], 10, [
        'display_name' => 'Acme Portal',
        'primary_color' => '#111111',
    ]);
    $second = $f['service']->upsertTheme($f['staff'], 10, [
        'display_name' => 'Acme Rebrand',
        'primary_color' => '#222222',
    ]);
    themeAssert($first['id'] === $second['id'], 'same id on update');
    themeAssert($second['display_name'] === 'Acme Rebrand', 'updated name');
    themeAssert($second['primary_color'] === '#222222', 'updated color');

    $count = (int) $f['pdo']->query('SELECT COUNT(*) FROM portal_themes')->fetchColumn();
    themeAssert($count === 1, 'only one row');
}, $only, $passed, $failed);

// 3. Missing display_name
testTheme('upsertTheme rejects missing display_name', function (): void {
    $f = makeThemeFixture();
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 10, ['primary_color' => '#111111']),
        InvalidArgumentException::class,
        'display_name',
        'no display_name'
    );
}, $only, $passed, $failed);

// 4. Invalid hex color
testTheme('upsertTheme rejects malformed hex color', function (): void {
    $f = makeThemeFixture();
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 10, [
            'display_name' => 'X',
            'primary_color' => 'not-a-hex',
        ]),
        InvalidArgumentException::class,
        'primary_color',
        'bad hex'
    );
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 10, [
            'display_name' => 'X',
            'accent_color' => '#XYZ',
        ]),
        InvalidArgumentException::class,
        'accent_color',
        'invalid hex chars'
    );
}, $only, $passed, $failed);

// 5. Short-form 3-digit hex is rejected (we require 6 digits)
testTheme('upsertTheme requires #RRGGBB (not #RGB)', function (): void {
    $f = makeThemeFixture();
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 10, [
            'display_name' => 'X',
            'primary_color' => '#fff',
        ]),
        InvalidArgumentException::class,
        'primary_color',
        'three-digit hex'
    );
}, $only, $passed, $failed);

// 6. Subdomain rules: leading dash
testTheme('upsertTheme rejects subdomain with leading dash', function (): void {
    $f = makeThemeFixture();
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 10, [
            'display_name' => 'X',
            'custom_subdomain' => '-acme',
        ]),
        InvalidArgumentException::class,
        'custom_subdomain',
        'leading dash'
    );
}, $only, $passed, $failed);

// 7. Subdomain rules: too short
testTheme('upsertTheme rejects too-short subdomain', function (): void {
    $f = makeThemeFixture();
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 10, [
            'display_name' => 'X',
            'custom_subdomain' => 'ab',
        ]),
        InvalidArgumentException::class,
        'custom_subdomain',
        'too short'
    );
}, $only, $passed, $failed);

// 8. Subdomain rules: invalid chars
testTheme('upsertTheme rejects subdomain with invalid chars', function (): void {
    $f = makeThemeFixture();
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 10, [
            'display_name' => 'X',
            'custom_subdomain' => 'ACME_inc',
        ]),
        InvalidArgumentException::class,
        'custom_subdomain',
        'underscore'
    );
}, $only, $passed, $failed);

// 9. Subdomain is lowercased
testTheme('upsertTheme lowercases subdomain', function (): void {
    $f = makeThemeFixture();
    $out = $f['service']->upsertTheme($f['staff'], 10, [
        'display_name' => 'X',
        'custom_subdomain' => 'ACME-Corp',
    ]);
    themeAssert($out['custom_subdomain'] === 'acme-corp', 'lowercased');
}, $only, $passed, $failed);

// 10. Domain must look FQDN-ish
testTheme('upsertTheme rejects bare hostname as domain', function (): void {
    $f = makeThemeFixture();
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 10, [
            'display_name' => 'X',
            'custom_domain' => 'localhost',
        ]),
        InvalidArgumentException::class,
        'custom_domain',
        'bare hostname'
    );
}, $only, $passed, $failed);

// 11. Valid FQDN
testTheme('upsertTheme accepts valid FQDN', function (): void {
    $f = makeThemeFixture();
    $out = $f['service']->upsertTheme($f['staff'], 10, [
        'display_name' => 'X',
        'custom_domain' => 'Portal.Acme.Com',
    ]);
    themeAssert($out['custom_domain'] === 'portal.acme.com', 'lowercased domain');
}, $only, $passed, $failed);

// 12. URL must be http(s)
testTheme('upsertTheme rejects javascript: URL', function (): void {
    $f = makeThemeFixture();
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 10, [
            'display_name' => 'X',
            'logo_url' => 'javascript:alert(1)',
        ]),
        InvalidArgumentException::class,
        'logo_url',
        'javascript url'
    );
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 10, [
            'display_name' => 'X',
            'favicon_url' => 'data:image/png;base64,xxx',
        ]),
        InvalidArgumentException::class,
        'favicon_url',
        'data url'
    );
}, $only, $passed, $failed);

// 13. Email validation
testTheme('upsertTheme rejects malformed email', function (): void {
    $f = makeThemeFixture();
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 10, [
            'display_name' => 'X',
            'support_email' => 'not-an-email',
        ]),
        InvalidArgumentException::class,
        'support_email',
        'bad email'
    );
}, $only, $passed, $failed);

// 14. Length caps
testTheme('upsertTheme enforces display_name length cap', function (): void {
    $f = makeThemeFixture();
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 10, [
            'display_name' => str_repeat('a', 121),
        ]),
        InvalidArgumentException::class,
        'display_name',
        '121 char name'
    );
}, $only, $passed, $failed);

testTheme('upsertTheme enforces footer_text length cap', function (): void {
    $f = makeThemeFixture();
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 10, [
            'display_name' => 'X',
            'footer_text' => str_repeat('a', 501),
        ]),
        InvalidArgumentException::class,
        'footer_text',
        'long footer'
    );
}, $only, $passed, $failed);

// 15. Unknown company
testTheme('upsertTheme rejects unknown company', function (): void {
    $f = makeThemeFixture();
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 9999, ['display_name' => 'X']),
        InvalidArgumentException::class,
        'not found',
        'unknown company'
    );
}, $only, $passed, $failed);

testTheme('upsertTheme rejects non-positive company id', function (): void {
    $f = makeThemeFixture();
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 0, ['display_name' => 'X']),
        InvalidArgumentException::class,
        'company id',
        'zero company'
    );
}, $only, $passed, $failed);

// 16. users.create gate
testTheme('upsertTheme requires users.create', function (): void {
    $f = makeThemeFixture();
    $f['gate']->denials['users.create'] = true;
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 10, ['display_name' => 'X']),
        UnauthorizedException::class,
        'users.create',
        'gate denies'
    );
}, $only, $passed, $failed);

// 17. Duplicate subdomain across companies → unique violation
testTheme('upsertTheme rejects duplicate custom_subdomain', function (): void {
    $f = makeThemeFixture();
    $f['companies']->seed(20, 'Beta Inc');
    $f['service']->upsertTheme($f['staff'], 10, [
        'display_name' => 'Acme',
        'custom_subdomain' => 'shared',
    ]);
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 20, [
            'display_name' => 'Beta',
            'custom_subdomain' => 'shared',
        ]),
        InvalidArgumentException::class,
        'already claimed',
        'duplicate subdomain'
    );
}, $only, $passed, $failed);

// 18. Duplicate custom_domain across companies
testTheme('upsertTheme rejects duplicate custom_domain', function (): void {
    $f = makeThemeFixture();
    $f['companies']->seed(20, 'Beta Inc');
    $f['service']->upsertTheme($f['staff'], 10, [
        'display_name' => 'Acme',
        'custom_domain' => 'portal.acme.com',
    ]);
    assertThemeThrows(
        fn() => $f['service']->upsertTheme($f['staff'], 20, [
            'display_name' => 'Beta',
            'custom_domain' => 'portal.acme.com',
        ]),
        InvalidArgumentException::class,
        'already claimed',
        'duplicate domain'
    );
}, $only, $passed, $failed);

// 19. resolveByHost by exact domain
testTheme('resolveByHost finds theme by custom_domain', function (): void {
    $f = makeThemeFixture();
    $f['service']->upsertTheme($f['staff'], 10, [
        'display_name' => 'Acme',
        'custom_domain' => 'portal.acme.com',
    ]);
    $out = $f['service']->resolveByHost('portal.acme.com');
    themeAssert($out !== null, 'found by domain');
    themeAssert($out['display_name'] === 'Acme', 'correct theme');
    themeAssert($out['is_default'] === false, 'not default');
}, $only, $passed, $failed);

// 20. resolveByHost strips port
testTheme('resolveByHost strips :port suffix', function (): void {
    $f = makeThemeFixture();
    $f['service']->upsertTheme($f['staff'], 10, [
        'display_name' => 'Acme',
        'custom_domain' => 'portal.acme.com',
    ]);
    $out = $f['service']->resolveByHost('portal.acme.com:8443');
    themeAssert($out !== null && $out['display_name'] === 'Acme', 'port stripped');
}, $only, $passed, $failed);

// 21. resolveByHost by subdomain (leftmost label)
testTheme('resolveByHost finds theme by subdomain slug', function (): void {
    $f = makeThemeFixture();
    $f['service']->upsertTheme($f['staff'], 10, [
        'display_name' => 'Acme',
        'custom_subdomain' => 'acme',
    ]);
    $out = $f['service']->resolveByHost('acme.portal.example.com');
    themeAssert($out !== null, 'found');
    themeAssert($out['display_name'] === 'Acme', 'correct theme');
}, $only, $passed, $failed);

// 22. resolveByHost: one-dot hostname is not treated as subdomain lookup
testTheme('resolveByHost ignores one-dot hostname as slug', function (): void {
    $f = makeThemeFixture();
    $f['service']->upsertTheme($f['staff'], 10, [
        'display_name' => 'Acme',
        'custom_subdomain' => 'acme',
    ]);
    $out = $f['service']->resolveByHost('acme.localhost');
    themeAssert($out === null, 'no match on one-dot host');
}, $only, $passed, $failed);

// 23. resolveByHost returns null when no match
testTheme('resolveByHost returns null when no match', function (): void {
    $f = makeThemeFixture();
    themeAssert($f['service']->resolveByHost('unknown.example.com') === null, 'null');
    themeAssert($f['service']->resolveByHost(null) === null, 'null host');
    themeAssert($f['service']->resolveByHost('') === null, 'empty');
}, $only, $passed, $failed);

// 24. publicResolveOrDefault always returns a payload
testTheme('publicResolveOrDefault falls back to default', function (): void {
    $f = makeThemeFixture();
    $out = $f['service']->publicResolveOrDefault('unknown.example.com');
    themeAssert($out['is_default'] === true, 'default payload');
    themeAssert($out['display_name'] === 'Customer Portal', 'default name');
    themeAssert($out['company_id'] === null, 'no company');
}, $only, $passed, $failed);

// 25. resolveByHost skips inactive themes
testTheme('resolveByHost skips inactive themes', function (): void {
    $f = makeThemeFixture();
    $f['service']->upsertTheme($f['staff'], 10, [
        'display_name' => 'Acme',
        'custom_domain' => 'portal.acme.com',
        'is_active' => false,
    ]);
    $out = $f['service']->resolveByHost('portal.acme.com');
    themeAssert($out === null, 'inactive skipped');
}, $only, $passed, $failed);

// 26. Portal read uses account.company_id
testTheme('readForPortal returns portal user company theme', function (): void {
    $f = makeThemeFixture();
    $f['service']->upsertTheme($f['staff'], 10, [
        'display_name' => 'Acme Portal',
        'primary_color' => '#112233',
    ]);
    $out = $f['service']->readForPortal($f['portalUser'], $f['account']);
    themeAssert($out['is_default'] === false, 'not default');
    themeAssert($out['company_id'] === 10, 'correct company');
    themeAssert($out['display_name'] === 'Acme Portal', 'correct name');
}, $only, $passed, $failed);

// 27. Portal read falls back to default when company has no theme
testTheme('readForPortal returns default when no theme exists', function (): void {
    $f = makeThemeFixture();
    $out = $f['service']->readForPortal($f['portalUser'], $f['account']);
    themeAssert($out['is_default'] === true, 'default');
    themeAssert($out['company_id'] === 10, 'carries company_id');
    themeAssert($out['display_name'] === 'Customer Portal', 'default name');
}, $only, $passed, $failed);

// 28. Portal read: unusable account is rejected
testTheme('readForPortal rejects unusable account', function (): void {
    $f = makeThemeFixture();
    $f['account']->is_active = false;
    assertThemeThrows(
        fn() => $f['service']->readForPortal($f['portalUser'], $f['account']),
        UnauthorizedException::class,
        'not usable',
        'inactive account'
    );
}, $only, $passed, $failed);

// 29. Delete theme
testTheme('deleteTheme removes row and audits', function (): void {
    $f = makeThemeFixture();
    $f['service']->upsertTheme($f['staff'], 10, ['display_name' => 'Acme']);
    $f['service']->deleteTheme($f['staff'], 10);
    themeAssert($f['service']->readForCompanyStaff($f['staff'], 10) === null, 'gone');
    $events = array_map(fn($e) => $e->event, $f['audit']->entries);
    themeAssert(in_array('portal.theme.deleted', $events, true), 'audit deleted');
}, $only, $passed, $failed);

// 30. Delete: non-existent theme
testTheme('deleteTheme rejects when no theme configured', function (): void {
    $f = makeThemeFixture();
    assertThemeThrows(
        fn() => $f['service']->deleteTheme($f['staff'], 10),
        InvalidArgumentException::class,
        'no theme configured',
        'nothing to delete'
    );
}, $only, $passed, $failed);

// 31. Delete: users.create gate
testTheme('deleteTheme requires users.create', function (): void {
    $f = makeThemeFixture();
    $f['service']->upsertTheme($f['staff'], 10, ['display_name' => 'Acme']);
    $f['gate']->denials['users.create'] = true;
    assertThemeThrows(
        fn() => $f['service']->deleteTheme($f['staff'], 10),
        UnauthorizedException::class,
        'users.create',
        'gate denies delete'
    );
}, $only, $passed, $failed);

// 32. Staff read: users.view gate
testTheme('readForCompanyStaff requires users.view', function (): void {
    $f = makeThemeFixture();
    $f['gate']->denials['users.view'] = true;
    assertThemeThrows(
        fn() => $f['service']->readForCompanyStaff($f['staff'], 10),
        UnauthorizedException::class,
        'users.view',
        'staff read gate'
    );
}, $only, $passed, $failed);

// 33. Staff read: null when no theme
testTheme('readForCompanyStaff returns null when no theme', function (): void {
    $f = makeThemeFixture();
    themeAssert($f['service']->readForCompanyStaff($f['staff'], 10) === null, 'null');
}, $only, $passed, $failed);

// ---------------------------------------------------------------------------

echo "\n";
echo "  passed: {$passed}\n";
echo "  failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
