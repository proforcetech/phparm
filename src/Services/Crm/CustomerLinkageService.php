<?php

namespace App\Services\Crm;

use App\Database\Connection;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Site;
use App\Services\Customer\CustomerRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Resolves legacy `customers` rows into the Phase 1 CRM model
 * (companies + sites) without a destructive backfill — Phase 1.5 of
 * docs/expansion-plan.md.
 *
 * Two operations:
 *   - resolve(): read-only lookup. Returns the linked company + primary site
 *     if the customer has been promoted, or synthesized view-only placeholders
 *     derived from the customer row itself for legacy customers. Nothing is
 *     written in either case.
 *   - promote(): one-shot staff action. Atomically creates a Company and a
 *     primary Site from the customer's fields, stamps `customers.company_id`
 *     and `sites.legacy_customer_id` so the link is discoverable both ways.
 *     Idempotent — calling it on an already-promoted customer just returns
 *     the existing linkage.
 *
 * Customers are never deleted; downstream tables that hold `customer_id`
 * continue to work. Callers that want the CRM view can ask this service for
 * the company/site pair whenever they need it.
 */
class CustomerLinkageService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CustomerRepository $customers,
        private readonly CompanyRepository $companies,
        private readonly SiteRepository $sites,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array{customer: Customer, company: ?Company, site: ?Site, is_legacy: bool}
     */
    public function resolve(int $customerId): array
    {
        $customer = $this->customers->find($customerId);
        if ($customer === null) {
            throw new InvalidArgumentException("Customer {$customerId} not found");
        }

        // Fast path: customer already linked.
        if ($customer->company_id !== null && $customer->company_id > 0) {
            $company = $this->companies->findById((int) $customer->company_id);
            $site = $this->sites->findByLegacyCustomer($customerId)
                ?? ($company ? $this->sites->findPrimaryForCompany($company->id) : null);
            return [
                'customer' => $customer,
                'company' => $company,
                'site' => $site,
                'is_legacy' => false,
            ];
        }

        // Sometimes Phase 1 data-loading jobs write sites.legacy_customer_id
        // before stamping customers.company_id. Cover that case.
        $site = $this->sites->findByLegacyCustomer($customerId);
        if ($site !== null) {
            $company = $this->companies->findById((int) $site->company_id);
            return [
                'customer' => $customer,
                'company' => $company,
                'site' => $site,
                'is_legacy' => false,
            ];
        }

        return [
            'customer' => $customer,
            'company' => null,
            'site' => null,
            'is_legacy' => true,
        ];
    }

    /**
     * Creates a Company + primary Site for the legacy customer and links the
     * customer row to both. Safe to call repeatedly — returns the existing
     * linkage if the customer has already been promoted.
     *
     * @return array{customer: Customer, company: Company, site: Site, promoted: bool}
     */
    public function promote(int $customerId, int $actorId): array
    {
        $resolved = $this->resolve($customerId);
        if (!$resolved['is_legacy'] && $resolved['company'] !== null && $resolved['site'] !== null) {
            return [
                'customer' => $resolved['customer'],
                'company' => $resolved['company'],
                'site' => $resolved['site'],
                'promoted' => false,
            ];
        }

        $customer = $resolved['customer'];
        $pdo = $this->connection->pdo();

        $pdo->beginTransaction();
        try {
            $company = $this->companies->create([
                'name' => $this->companyNameFor($customer),
                'company_type' => $customer->is_commercial ? 'commercial' : 'residential',
                'tax_exempt' => $customer->tax_exempt,
                'primary_phone' => $customer->phone,
                'primary_email' => $customer->email,
                'external_reference' => $customer->external_reference,
                'notes' => $customer->notes,
                'status' => 'active',
            ]);

            $site = $this->sites->create([
                'company_id' => $company->id,
                'legacy_customer_id' => $customer->id,
                'name' => 'Primary Location',
                'is_primary' => 1,
                'status' => 'active',
                'street' => $customer->street,
                'city' => $customer->city,
                'state' => $customer->state,
                'postal_code' => $customer->postal_code,
                'country' => $customer->country,
                'phone' => $customer->phone,
            ]);

            $stmt = $pdo->prepare(
                'UPDATE customers SET company_id = :cid, updated_at = NOW() WHERE id = :id'
            );
            $stmt->execute(['cid' => $company->id, 'id' => $customer->id]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException(
                'Failed to promote customer to company: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $this->audit->log(new AuditEntry(
            'customer.promoted_to_company',
            'customer',
            $customer->id,
            $actorId,
            [
                'company_id' => $company->id,
                'site_id' => $site->id,
            ]
        ));

        // CustomerRepository caches by id, so re-fetching returns the stale
        // row. The single field that changed is company_id — patch the
        // in-memory object to match what was persisted.
        $customer->company_id = $company->id;

        return [
            'customer' => $customer,
            'company' => $company,
            'site' => $site,
            'promoted' => true,
        ];
    }

    private function companyNameFor(Customer $customer): string
    {
        $business = trim((string) ($customer->business_name ?? ''));
        if ($business !== '') {
            return $business;
        }
        $full = trim($customer->first_name . ' ' . $customer->last_name);
        return $full !== '' ? $full : 'Customer #' . $customer->id;
    }
}
