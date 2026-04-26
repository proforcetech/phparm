<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\PortalAccount;
use App\Models\Site;
use App\Models\SiteAsset;
use App\Models\User;
use App\Services\Assets\SiteAssetRepository;
use App\Services\Crm\SiteRepository;
use App\Services\Portal\PortalAssetViewService;
use App\Services\Portal\PortalAuthService;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 6.4 of docs/expansion-plan.md — portal-facing read-only view of
 * sites + site_assets.
 *
 * Covers scope enforcement (three layers), asset→site cross-company
 * rejection, allowed_site_ids whitelist, and serialization omitting
 * staff-only fields (alarm/gate codes, internal notes).
 */

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class AvFakeSites extends SiteRepository
{
    /** @var array<int, Site> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct() {}
    public function findById(int $id): ?Site
    {
        return $this->store[$id] ?? null;
    }
    public function listForCompany(int $companyId, bool $activeOnly = true): array
    {
        $out = [];
        foreach ($this->store as $s) {
            if ($s->company_id !== $companyId) {
                continue;
            }
            if ($activeOnly && $s->status !== 'active') {
                continue;
            }
            $out[] = $s;
        }
        usort($out, fn($a, $b) => strcmp($a->name, $b->name));
        return $out;
    }
    public function seed(array $row): Site
    {
        $s = new Site();
        $s->id = $row['id'] ?? $this->nextId++;
        $s->company_id = (int) ($row['company_id'] ?? 0);
        $s->name = $row['name'] ?? ('Site ' . $s->id);
        $s->code = $row['code'] ?? null;
        $s->status = $row['status'] ?? 'active';
        $s->alarm_code_encrypted = $row['alarm_code_encrypted'] ?? null;
        $s->gate_code_encrypted = $row['gate_code_encrypted'] ?? null;
        $s->notes = $row['notes'] ?? null;
        $this->store[$s->id] = $s;
        return $s;
    }
}

class AvFakeAssets extends SiteAssetRepository
{
    /** @var array<int, SiteAsset> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct() {}
    public function findById(int $id): ?SiteAsset
    {
        return $this->store[$id] ?? null;
    }
    public function search(array $filters = []): array
    {
        $out = [];
        foreach ($this->store as $a) {
            if (!empty($filters['site_id']) && $a->site_id !== (int) $filters['site_id']) {
                continue;
            }
            if (!empty($filters['status']) && $a->status !== $filters['status']) {
                continue;
            }
            $out[] = $a;
        }
        usort($out, fn($a, $b) => strcmp($a->name, $b->name));
        return ['data' => $out, 'total' => count($out)];
    }
    public function seed(array $row): SiteAsset
    {
        $a = new SiteAsset();
        $a->id = $row['id'] ?? $this->nextId++;
        $a->site_id = (int) ($row['site_id'] ?? 0);
        $a->name = $row['name'] ?? ('Asset ' . $a->id);
        $a->status = $row['status'] ?? 'active';
        $this->store[$a->id] = $a;
        return $a;
    }
}

function makePortalAuthStubForAsset(): PortalAuthService
{
    $ref = new ReflectionClass(PortalAuthService::class);
    return $ref->newInstanceWithoutConstructor();
}

function makeAssetFixture(
    int $companyId = 10,
    ?array $allowedSites = null,
    bool $isActive = true,
    ?string $revokedAt = null,
): array {
    $sites = new AvFakeSites();
    $assets = new AvFakeAssets();
    $auth = makePortalAuthStubForAsset();
    $service = new PortalAssetViewService($sites, $assets, $auth);

    $user = new User();
    $user->id = 42;

    $account = new PortalAccount();
    $account->id = 1;
    $account->user_id = 42;
    $account->company_id = $companyId;
    $account->allowed_site_ids = $allowedSites;
    $account->is_active = $isActive;
    $account->revoked_at = $revokedAt;

    return compact('service', 'sites', 'assets', 'user', 'account');
}

function assertAvThrows(callable $fn, string $exceptionClass, string $msgNeedle, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $exceptionClass)) {
            throw new RuntimeException("{$label}: expected {$exceptionClass}, got " . $e::class . ' — ' . $e->getMessage());
        }
        if ($msgNeedle !== '' && stripos($e->getMessage(), $msgNeedle) === false) {
            throw new RuntimeException("{$label}: expected message [{$msgNeedle}], got [{$e->getMessage()}]");
        }
        return;
    }
    throw new RuntimeException("{$label}: expected {$exceptionClass} but nothing was thrown");
}

function avPass(string $label): void
{
    echo "  ok — {$label}\n";
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

echo "PortalAssetViewServiceTest\n";

// listSites
(function () {
    $fx = makeAssetFixture(10);
    /** @var AvFakeSites $sites */
    $sites = $fx['sites'];
    $sites->seed(['id' => 1, 'company_id' => 10, 'name' => 'A']);
    $sites->seed(['id' => 2, 'company_id' => 10, 'name' => 'B']);
    $sites->seed(['id' => 3, 'company_id' => 11, 'name' => 'OtherCo']);
    $sites->seed(['id' => 4, 'company_id' => 10, 'name' => 'C', 'status' => 'archived']);

    $out = $fx['service']->listSites($fx['account']);
    $ids = array_map(fn($s) => $s['id'], $out);
    sort($ids);
    if ($ids !== [1, 2]) {
        throw new RuntimeException('expected [1,2] active same-company sites, got ' . json_encode($ids));
    }
    avPass('listSites scopes by company_id and hides inactive + cross-company');
})();

(function () {
    $fx = makeAssetFixture(10, [2]);
    /** @var AvFakeSites $sites */
    $sites = $fx['sites'];
    $sites->seed(['id' => 1, 'company_id' => 10, 'name' => 'A']);
    $sites->seed(['id' => 2, 'company_id' => 10, 'name' => 'B']);

    $out = $fx['service']->listSites($fx['account']);
    if (count($out) !== 1 || $out[0]['id'] !== 2) {
        throw new RuntimeException('expected only [2] after whitelist, got ' . json_encode($out));
    }
    avPass('listSites honors allowed_site_ids whitelist');
})();

(function () {
    $fx = makeAssetFixture(10);
    /** @var AvFakeSites $sites */
    $sites = $fx['sites'];
    $sites->seed([
        'id' => 1, 'company_id' => 10, 'name' => 'A',
        'alarm_code_encrypted' => 'SECRET_ALARM_ENC',
        'gate_code_encrypted' => 'SECRET_GATE_ENC',
        'notes' => 'internal: customer is behind on payments',
    ]);
    $out = $fx['service']->listSites($fx['account']);
    $payload = $out[0];
    foreach (['alarm_code_encrypted', 'gate_code_encrypted', 'notes'] as $staffOnly) {
        if (array_key_exists($staffOnly, $payload)) {
            throw new RuntimeException("serialized site leaked {$staffOnly}");
        }
    }
    avPass('serializeSite strips alarm/gate codes + internal notes');
})();

(function () {
    $fx = makeAssetFixture(10, null, false);
    assertAvThrows(
        fn() => $fx['service']->listSites($fx['account']),
        UnauthorizedException::class, 'not usable',
        'listSites on inactive account'
    );
    avPass('listSites rejects inactive account');
})();

(function () {
    $fx = makeAssetFixture(10, null, true, '2026-04-15 00:00:00');
    assertAvThrows(
        fn() => $fx['service']->listSites($fx['account']),
        UnauthorizedException::class, 'not usable',
        'listSites on revoked account'
    );
    avPass('listSites rejects revoked account');
})();

// getSite
(function () {
    $fx = makeAssetFixture(10);
    /** @var AvFakeSites $sites */
    $sites = $fx['sites'];
    $sites->seed(['id' => 1, 'company_id' => 11]);
    assertAvThrows(
        fn() => $fx['service']->getSite($fx['account'], 1),
        UnauthorizedException::class, 'does not belong',
        'getSite cross-company'
    );
    avPass('getSite rejects cross-company site');
})();

(function () {
    $fx = makeAssetFixture(10, [2]); // only site 2 allowed
    /** @var AvFakeSites $sites */
    $sites = $fx['sites'];
    $sites->seed(['id' => 1, 'company_id' => 10]); // same company, outside whitelist
    $sites->seed(['id' => 2, 'company_id' => 10]);
    assertAvThrows(
        fn() => $fx['service']->getSite($fx['account'], 1),
        UnauthorizedException::class, 'cannot access',
        'getSite outside allowed_site_ids'
    );
    // Allowed site 2 works
    $out = $fx['service']->getSite($fx['account'], 2);
    if ($out['id'] !== 2) {
        throw new RuntimeException('getSite(2) failed');
    }
    avPass('getSite enforces allowed_site_ids even within same company');
})();

(function () {
    $fx = makeAssetFixture(10);
    assertAvThrows(
        fn() => $fx['service']->getSite($fx['account'], 9999),
        InvalidArgumentException::class, 'not found',
        'getSite unknown'
    );
    assertAvThrows(
        fn() => $fx['service']->getSite($fx['account'], 0),
        InvalidArgumentException::class, 'site id',
        'getSite id=0'
    );
    avPass('getSite rejects unknown/non-positive ids');
})();

// listAssetsAtSite
(function () {
    $fx = makeAssetFixture(10);
    /** @var AvFakeSites $sites */
    $sites = $fx['sites'];
    /** @var AvFakeAssets $assets */
    $assets = $fx['assets'];
    $sites->seed(['id' => 1, 'company_id' => 10]);
    $sites->seed(['id' => 2, 'company_id' => 10]);
    $assets->seed(['id' => 100, 'site_id' => 1, 'name' => 'Boiler']);
    $assets->seed(['id' => 101, 'site_id' => 1, 'name' => 'Chiller']);
    $assets->seed(['id' => 200, 'site_id' => 2, 'name' => 'RTU']);

    $out = $fx['service']->listAssetsAtSite($fx['account'], 1);
    $ids = array_map(fn($a) => $a['id'], $out['data']);
    sort($ids);
    if ($ids !== [100, 101]) {
        throw new RuntimeException('expected [100,101], got ' . json_encode($ids));
    }
    if ($out['total'] !== 2) {
        throw new RuntimeException('expected total=2');
    }
    avPass('listAssetsAtSite returns only the given site\'s assets');
})();

(function () {
    $fx = makeAssetFixture(10);
    /** @var AvFakeSites $sites */
    $sites = $fx['sites'];
    $sites->seed(['id' => 1, 'company_id' => 11]); // sibling company
    assertAvThrows(
        fn() => $fx['service']->listAssetsAtSite($fx['account'], 1),
        UnauthorizedException::class, 'does not belong',
        'listAssetsAtSite cross-company'
    );
    avPass('listAssetsAtSite rejects cross-company site before enumerating assets');
})();

// getAsset
(function () {
    $fx = makeAssetFixture(10);
    /** @var AvFakeSites $sites */
    $sites = $fx['sites'];
    /** @var AvFakeAssets $assets */
    $assets = $fx['assets'];
    $sites->seed(['id' => 1, 'company_id' => 10]);
    $assets->seed(['id' => 500, 'site_id' => 1, 'name' => 'Pump']);
    $out = $fx['service']->getAsset($fx['account'], 500);
    if ($out['id'] !== 500 || $out['name'] !== 'Pump') {
        throw new RuntimeException('getAsset payload wrong: ' . json_encode($out));
    }
    avPass('getAsset returns asset after site-scope check');
})();

(function () {
    $fx = makeAssetFixture(10);
    /** @var AvFakeSites $sites */
    $sites = $fx['sites'];
    /** @var AvFakeAssets $assets */
    $assets = $fx['assets'];
    $sites->seed(['id' => 1, 'company_id' => 11]); // sibling company
    $assets->seed(['id' => 500, 'site_id' => 1, 'name' => 'Pump']);
    assertAvThrows(
        fn() => $fx['service']->getAsset($fx['account'], 500),
        UnauthorizedException::class, 'does not belong',
        'getAsset via sibling-company site'
    );
    avPass('getAsset rejects asset whose site belongs to sibling company');
})();

(function () {
    $fx = makeAssetFixture(10, [2]); // only site 2 allowed
    /** @var AvFakeSites $sites */
    $sites = $fx['sites'];
    /** @var AvFakeAssets $assets */
    $assets = $fx['assets'];
    $sites->seed(['id' => 1, 'company_id' => 10]);
    $sites->seed(['id' => 2, 'company_id' => 10]);
    $assets->seed(['id' => 500, 'site_id' => 1]); // asset at disallowed site
    assertAvThrows(
        fn() => $fx['service']->getAsset($fx['account'], 500),
        UnauthorizedException::class, 'cannot access',
        'getAsset at disallowed site'
    );
    avPass('getAsset honors allowed_site_ids via asset\'s site');
})();

(function () {
    $fx = makeAssetFixture(10);
    assertAvThrows(
        fn() => $fx['service']->getAsset($fx['account'], 99999),
        InvalidArgumentException::class, 'not found',
        'getAsset unknown'
    );
    avPass('getAsset rejects unknown id');
})();

echo "\nAll PortalAssetViewServiceTest cases passed.\n";
