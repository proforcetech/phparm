<?php

namespace App\Services\Crm;

use App\Models\BillingContact;
use App\Models\Company;
use App\Models\Site;
use App\Models\SiteBlackoutWindow;
use App\Models\SiteContact;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Crypto\FieldCipher;
use InvalidArgumentException;
use RuntimeException;

/**
 * CRM surface for companies/sites/contacts — Phase 1.1 of docs/expansion-plan.md.
 *
 * Permissions:
 *   - crm.companies.view / crm.companies.manage
 *   - crm.sites.view / crm.sites.manage
 *   - crm.contacts.view / crm.contacts.manage
 *
 * Every mutation writes to the audit log with entity_type of company|site|
 * site_contact|billing_contact so Phase 1.4 can surface a unified trail.
 */
class CrmController
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly SiteRepository $sites,
        private readonly SiteContactRepository $siteContacts,
        private readonly BillingContactRepository $billingContacts,
        private readonly SiteBlackoutWindowRepository $blackouts,
        private readonly FieldCipher $fieldCipher,
        private readonly CustomerLinkageService $linkage,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
    ) {
    }

    // ───────────────────────────────────────── Companies ─────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchCompanies(User $user, array $filters): array
    {
        $this->gate->assert($user, 'crm.companies.view');

        $result = $this->companies->search($filters);

        return [
            'data' => array_map(static fn(Company $c) => $c->toArray(), $result['data']),
            'meta' => ['total' => $result['total']],
        ];
    }

    public function getCompany(User $user, int $id): array
    {
        $this->gate->assert($user, 'crm.companies.view');

        $company = $this->companies->findById($id);
        if ($company === null) {
            throw new InvalidArgumentException("Company {$id} not found");
        }

        // Expand one level of children so UIs can render the card without a second round trip.
        $sites = $this->sites->listForCompany($id);
        $billing = $this->billingContacts->listForCompany($id);

        return [
            'data' => [
                'company' => $company->toArray(),
                'sites' => array_map(fn(Site $s) => $this->redactSite($s->toArray()), $sites),
                'billing_contacts' => array_map(static fn(BillingContact $b) => $b->toArray(), $billing),
            ],
        ];
    }

    public function createCompany(User $user, array $body): array
    {
        $this->gate->assert($user, 'crm.companies.manage');

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('name is required');
        }

        $company = $this->companies->create(['name' => $name] + $body);
        $this->logEvent('company.created', 'company', $company->id, $user, ['name' => $company->name]);

        return ['data' => $company->toArray()];
    }

    public function updateCompany(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'crm.companies.manage');

        $company = $this->companies->update($id, $body);
        $this->logEvent('company.updated', 'company', $id, $user, ['fields' => array_keys($body)]);

        return ['data' => $company->toArray()];
    }

    public function deleteCompany(User $user, int $id): void
    {
        $this->gate->assert($user, 'crm.companies.manage');
        $this->companies->delete($id);
        $this->logEvent('company.deleted', 'company', $id, $user, []);
    }

    // ───────────────────────────────────────── Sites ─────────────────────────

    public function listSites(User $user, int $companyId, bool $includeInactive): array
    {
        $this->gate->assert($user, 'crm.sites.view');

        $sites = $this->sites->listForCompany($companyId, !$includeInactive);

        return ['data' => array_map(fn(Site $s) => $this->redactSite($s->toArray()), $sites)];
    }

    public function getSite(User $user, int $id): array
    {
        $this->gate->assert($user, 'crm.sites.view');

        $site = $this->sites->findById($id);
        if ($site === null) {
            throw new InvalidArgumentException("Site {$id} not found");
        }
        $contacts = $this->siteContacts->listForSite($id);
        $windows = $this->blackouts->listForSite($id, false);

        return [
            'data' => [
                'site' => $this->redactSite($site->toArray()),
                'contacts' => array_map(static fn(SiteContact $c) => $c->toArray(), $contacts),
                'blackout_windows' => array_map(static fn(SiteBlackoutWindow $w) => $w->toArray(), $windows),
            ],
        ];
    }

    public function createSite(User $user, array $body): array
    {
        $this->gate->assert($user, 'crm.sites.manage');

        $companyId = (int) ($body['company_id'] ?? 0);
        $name = trim((string) ($body['name'] ?? ''));
        if ($companyId <= 0 || $name === '') {
            throw new InvalidArgumentException('company_id and name are required');
        }
        if ($this->companies->findById($companyId) === null) {
            throw new InvalidArgumentException("Company {$companyId} not found");
        }

        $body = $this->encodeSiteCodesIfPresent($user, $body);
        $site = $this->sites->create($body);
        $this->logEvent('site.created', 'site', $site->id, $user, [
            'company_id' => $site->company_id,
            'name' => $site->name,
        ]);

        return ['data' => $this->redactSite($site->toArray())];
    }

    public function updateSite(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'crm.sites.manage');

        $body = $this->encodeSiteCodesIfPresent($user, $body);
        $site = $this->sites->update($id, $body);
        $this->logEvent('site.updated', 'site', $id, $user, ['fields' => array_keys($body)]);

        return ['data' => $this->redactSite($site->toArray())];
    }

    /**
     * Return the decrypted alarm/gate codes for a site. Gated independently so
     * techs with general site access don't see codes unless provisioned.
     *
     * @return array<string, mixed>
     */
    public function revealSiteCodes(User $user, int $id): array
    {
        $this->gate->assert($user, 'crm.sites.codes.view');

        $site = $this->sites->findById($id);
        if ($site === null) {
            throw new InvalidArgumentException("Site {$id} not found");
        }

        $alarm = $this->decryptCodeField($site->alarm_code_encrypted, 'alarm_code', $id, $user);
        $gate = $this->decryptCodeField($site->gate_code_encrypted, 'gate_code', $id, $user);

        $this->logEvent('site.codes.viewed', 'site', $id, $user, [
            'fields' => array_values(array_filter([
                $alarm['value'] !== null ? 'alarm_code' : null,
                $gate['value'] !== null ? 'gate_code' : null,
            ])),
        ]);

        return [
            'data' => [
                'site_id' => $site->id,
                'alarm_code' => $alarm['value'],
                'alarm_code_status' => $alarm['status'],
                'gate_code' => $gate['value'],
                'gate_code_status' => $gate['status'],
            ],
        ];
    }

    // ───────────────────────────────────────── Blackout windows ──────────────

    public function listBlackoutWindows(User $user, int $siteId, bool $includeInactive): array
    {
        $this->gate->assert($user, 'crm.sites.view');

        $windows = $this->blackouts->listForSite($siteId, !$includeInactive);

        return ['data' => array_map(static fn(SiteBlackoutWindow $w) => $w->toArray(), $windows)];
    }

    public function createBlackoutWindow(User $user, array $body): array
    {
        $this->gate->assert($user, 'crm.sites.manage');

        $siteId = (int) ($body['site_id'] ?? 0);
        if ($siteId <= 0) {
            throw new InvalidArgumentException('site_id is required');
        }
        if ($this->sites->findById($siteId) === null) {
            throw new InvalidArgumentException("Site {$siteId} not found");
        }
        $this->assertBlackoutRange($body);

        $window = $this->blackouts->create($body);
        $this->logEvent('site_blackout.created', 'site_blackout_window', $window->id, $user, [
            'site_id' => $window->site_id,
        ]);

        return ['data' => $window->toArray()];
    }

    public function updateBlackoutWindow(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'crm.sites.manage');

        if (isset($body['starts_at']) || isset($body['ends_at'])) {
            $existing = $this->blackouts->findById($id);
            if ($existing === null) {
                throw new InvalidArgumentException("Blackout window {$id} not found");
            }
            $this->assertBlackoutRange([
                'starts_at' => $body['starts_at'] ?? $existing->starts_at,
                'ends_at' => $body['ends_at'] ?? $existing->ends_at,
            ]);
        }

        $window = $this->blackouts->update($id, $body);
        $this->logEvent('site_blackout.updated', 'site_blackout_window', $id, $user, [
            'fields' => array_keys($body),
        ]);

        return ['data' => $window->toArray()];
    }

    public function deleteBlackoutWindow(User $user, int $id): void
    {
        $this->gate->assert($user, 'crm.sites.manage');
        $this->blackouts->delete($id);
        $this->logEvent('site_blackout.deleted', 'site_blackout_window', $id, $user, []);
    }

    public function deleteSite(User $user, int $id): void
    {
        $this->gate->assert($user, 'crm.sites.manage');
        $this->sites->delete($id);
        $this->logEvent('site.deleted', 'site', $id, $user, []);
    }

    // ───────────────────────────────────────── Site contacts ─────────────────

    public function listSiteContacts(User $user, int $siteId, bool $includeInactive): array
    {
        $this->gate->assert($user, 'crm.contacts.view');

        $contacts = $this->siteContacts->listForSite($siteId, !$includeInactive);

        return ['data' => array_map(static fn(SiteContact $c) => $c->toArray(), $contacts)];
    }

    public function createSiteContact(User $user, array $body): array
    {
        $this->gate->assert($user, 'crm.contacts.manage');

        $siteId = (int) ($body['site_id'] ?? 0);
        if ($siteId <= 0) {
            throw new InvalidArgumentException('site_id is required');
        }
        if ($this->sites->findById($siteId) === null) {
            throw new InvalidArgumentException("Site {$siteId} not found");
        }

        $this->assertContactNameEmail($body);
        $this->assertPermissionScope($body);

        $contact = $this->siteContacts->create($body);
        $this->logEvent('site_contact.created', 'site_contact', $contact->id, $user, [
            'site_id' => $contact->site_id,
        ]);

        return ['data' => $contact->toArray()];
    }

    public function updateSiteContact(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'crm.contacts.manage');

        $this->assertPermissionScope($body);

        $contact = $this->siteContacts->update($id, $body);
        $this->logEvent('site_contact.updated', 'site_contact', $id, $user, ['fields' => array_keys($body)]);

        return ['data' => $contact->toArray()];
    }

    public function deleteSiteContact(User $user, int $id): void
    {
        $this->gate->assert($user, 'crm.contacts.manage');
        $this->siteContacts->delete($id);
        $this->logEvent('site_contact.deleted', 'site_contact', $id, $user, []);
    }

    // ───────────────────────────────────────── Billing contacts ──────────────

    public function listBillingContacts(User $user, int $companyId, bool $includeInactive): array
    {
        $this->gate->assert($user, 'crm.contacts.view');

        $contacts = $this->billingContacts->listForCompany($companyId, !$includeInactive);

        return ['data' => array_map(static fn(BillingContact $c) => $c->toArray(), $contacts)];
    }

    public function createBillingContact(User $user, array $body): array
    {
        $this->gate->assert($user, 'crm.contacts.manage');

        $companyId = (int) ($body['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new InvalidArgumentException('company_id is required');
        }
        if ($this->companies->findById($companyId) === null) {
            throw new InvalidArgumentException("Company {$companyId} not found");
        }

        $this->assertContactNameEmail($body);
        $this->assertPermissionScope($body);

        $contact = $this->billingContacts->create($body);
        $this->logEvent('billing_contact.created', 'billing_contact', $contact->id, $user, [
            'company_id' => $contact->company_id,
        ]);

        return ['data' => $contact->toArray()];
    }

    public function updateBillingContact(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'crm.contacts.manage');

        $this->assertPermissionScope($body);

        $contact = $this->billingContacts->update($id, $body);
        $this->logEvent('billing_contact.updated', 'billing_contact', $id, $user, ['fields' => array_keys($body)]);

        return ['data' => $contact->toArray()];
    }

    public function deleteBillingContact(User $user, int $id): void
    {
        $this->gate->assert($user, 'crm.contacts.manage');
        $this->billingContacts->delete($id);
        $this->logEvent('billing_contact.deleted', 'billing_contact', $id, $user, []);
    }

    // ───────────────────────────────────────── Customer linkage (Phase 1.5) ──

    /**
     * Read the CRM linkage for a legacy customer. Non-destructive. If the
     * customer has not been promoted, returns company/site = null and
     * is_legacy = true so the UI can render a "promote" CTA.
     *
     * @return array<string, mixed>
     */
    public function getCustomerLinkage(User $user, int $customerId): array
    {
        $this->gate->assert($user, 'crm.companies.view');

        $resolved = $this->linkage->resolve($customerId);

        return [
            'data' => [
                'customer' => $resolved['customer']->toArray(),
                'company' => $resolved['company']?->toArray(),
                'site' => $resolved['site'] !== null
                    ? $this->redactSite($resolved['site']->toArray())
                    : null,
                'is_legacy' => $resolved['is_legacy'],
            ],
        ];
    }

    /**
     * Promotes a legacy customer to a full CRM company + primary site.
     * Idempotent; a second call just returns the existing linkage.
     *
     * @return array<string, mixed>
     */
    public function promoteCustomer(User $user, int $customerId): array
    {
        $this->gate->assert($user, 'crm.companies.manage');

        $result = $this->linkage->promote($customerId, (int) ($user->id ?? 0));

        return [
            'data' => [
                'customer' => $result['customer']->toArray(),
                'company' => $result['company']->toArray(),
                'site' => $this->redactSite($result['site']->toArray()),
                'promoted' => $result['promoted'],
            ],
        ];
    }

    // ───────────────────────────────────────── Helpers ───────────────────────

    /**
     * @param array<string, mixed> $body
     */
    private function assertContactNameEmail(array $body): void
    {
        $first = trim((string) ($body['first_name'] ?? ''));
        $last = trim((string) ($body['last_name'] ?? ''));
        if ($first === '' || $last === '') {
            throw new InvalidArgumentException('first_name and last_name are required');
        }
        if (!empty($body['email']) && !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('email is invalid');
        }
    }

    /**
     * Validates permission_scope JSON through ContactPermissionScope::fromArray()
     * so unknown entitlements or malformed site_ids are rejected before hitting
     * the DB (Phase 1.3 of docs/expansion-plan.md). A null/missing scope is fine
     * — it just means the contact has no portal entitlements.
     *
     * @param array<string, mixed> $body
     */
    private function assertPermissionScope(array $body): void
    {
        if (!array_key_exists('permission_scope', $body) || $body['permission_scope'] === null) {
            return;
        }
        $raw = $body['permission_scope'];
        if (!is_array($raw)) {
            throw new InvalidArgumentException('permission_scope must be an object');
        }
        ContactPermissionScope::fromArray($raw);
    }

    /**
     * Replace ciphertext columns with boolean flags so API consumers know the
     * code exists but can't recover it without hitting revealSiteCodes().
     *
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function redactSite(array $site): array
    {
        $site['alarm_code_set'] = !empty($site['alarm_code_encrypted']);
        $site['gate_code_set'] = !empty($site['gate_code_encrypted']);
        unset($site['alarm_code_encrypted'], $site['gate_code_encrypted']);

        return $site;
    }

    /**
     * Accept plaintext alarm_code/gate_code from the body, encrypt them, and
     * swap them for the *_encrypted columns the repository understands. Empty
     * string clears the column; null or absence leaves it unchanged.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function encodeSiteCodesIfPresent(User $user, array $body): array
    {
        foreach (['alarm_code' => 'alarm_code_encrypted', 'gate_code' => 'gate_code_encrypted'] as $plainKey => $cipherKey) {
            if (!array_key_exists($plainKey, $body)) {
                continue;
            }
            // Writing codes requires the dedicated permission even though the
            // parent site is already editable — codes are a sensitive field.
            $this->gate->assert($user, 'crm.sites.codes.manage');

            $value = $body[$plainKey];
            unset($body[$plainKey]);

            if ($value === null || $value === '') {
                $body[$cipherKey] = null;
                continue;
            }
            if (!$this->fieldCipher->isAvailable()) {
                throw new RuntimeException(
                    'Cannot store alarm/gate codes: SITE_CODES_ENCRYPTION_KEY is not configured'
                );
            }
            $body[$cipherKey] = $this->fieldCipher->encrypt((string) $value);
        }
        return $body;
    }

    /**
     * Decrypt one of the alarm/gate code fields, distinguishing absent
     * (NULL ciphertext) from present-but-undecryptable. The latter case
     * emits a high-severity audit event so operators see tampering or
     * key-rotation breakage instead of a silent "no code set" UI.
     *
     * @return array{value: ?string, status: string}
     *   status is one of: absent | ok | key_unavailable | decrypt_failed
     */
    private function decryptCodeField(
        ?string $ciphertext,
        string $field,
        int $siteId,
        User $user,
    ): array {
        if ($ciphertext === null || $ciphertext === '') {
            return ['value' => null, 'status' => 'absent'];
        }
        if (!$this->fieldCipher->isAvailable()) {
            $this->logEvent('site.codes.decrypt_failed', 'site', $siteId, $user, [
                'field' => $field,
                'reason' => 'key_unavailable',
            ]);
            return ['value' => null, 'status' => 'key_unavailable'];
        }
        try {
            return ['value' => $this->fieldCipher->decrypt($ciphertext), 'status' => 'ok'];
        } catch (\Throwable $e) {
            $this->logEvent('site.codes.decrypt_failed', 'site', $siteId, $user, [
                'field' => $field,
                'reason' => 'authentication_failed',
                'error' => $e->getMessage(),
            ]);
            return ['value' => null, 'status' => 'decrypt_failed'];
        }
    }

    /**
     * @param array{starts_at?: mixed, ends_at?: mixed} $body
     */
    private function assertBlackoutRange(array $body): void
    {
        $start = (string) ($body['starts_at'] ?? '');
        $end = (string) ($body['ends_at'] ?? '');
        if ($start === '' || $end === '') {
            throw new InvalidArgumentException('starts_at and ends_at are required');
        }
        if (strtotime($start) === false || strtotime($end) === false) {
            throw new InvalidArgumentException('starts_at and ends_at must be valid datetimes');
        }
        if (strtotime($end) <= strtotime($start)) {
            throw new InvalidArgumentException('ends_at must be after starts_at');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logEvent(string $event, string $entityType, int $entityId, User $user, array $context): void
    {
        $this->audit->log(new AuditEntry(
            $event,
            $entityType,
            (string) $entityId,
            $user->id ?? null,
            $context
        ));
    }
}
