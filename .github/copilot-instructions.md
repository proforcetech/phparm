# Copilot Instructions: PHPArm

## Project Overview
PHPArm is a full-stack automotive repair shop & towing company ERP built with **PHP 8.1 backend** and **React 19 frontend**. Two core workflows: **(1) Repair** (Estimate → Workorder → Invoice) and **(2) Towing/Dispatch** (Job → Driver Assignment → Completion).

---

## Architecture Fundamentals

### Backend Architecture (PHP 8.1)
- **No framework**: Custom router/middleware stack in `src/Support/Http/`
- **Service Layer**: Business logic in `src/Services/{Feature}/` (e.g., `ServiceTypeRepository`, `EstimateService`)
- **Models**: Plain PHP objects in `src/Models/` (hydrated from DB via repositories)
- **Routes**: Defined in `routes/api.php` (~9000 lines) and `routes/cms.php`
- **Database**: MySQL 8.0+, 90+ migrations in `database/migrations/`
- **Entry Point**: `public/index.php` → routes request to API or CMS, handles asset serving

**Example: Service → Repository → Database pattern:**
```php
// routes/api.php
$router->post('/service-types', function(Request $req, ...) {
    $controller = new ServiceTypeController(...);
    return $controller->store($user, $req->payload());
});

// src/Services/ServiceType/ServiceTypeController.php
class ServiceTypeController {
    public function store(User $user, array $data): ?array {
        $this->gate->assert($user, 'service_types.create'); // Auth check
        $serviceType = $this->repository->create($data, $user->id); // DB operation
        return $serviceType?->toArray(); // Return DTO
    }
}

// src/Services/ServiceType/ServiceTypeRepository.php
class ServiceTypeRepository {
    public function create(array $data, ?int $actorId = null): ServiceType {
        // Validate, insert, cache, audit log, return model
    }
}
```

### Frontend Architecture (React 19 + Vite)
- **SPA at**: `src/react/` with build output to `/dist`
- **API Layer**: `src/services/*.service.js` (axios-based HTTP client in `src/services/api.js`)
- **Views**: React components in `src/react/views/{Feature}/`
- **Build**: `npm run build` (Vite), `npm run dev` (local dev server)
- **Routing**: React Router 6 at `src/react/index.jsx`
- **UI**: Tailwind CSS (not Bootstrap), Chart.js for analytics, FullCalendar for scheduling

**HTTP Client Convention:**
```js
// src/services/api.js
const api = axios.create({ baseURL: '/api' });
api.interceptors.response.use(...); // Global error handling

// src/services/service-types.service.js
export function fetchServiceTypes(params = {}) {
  return api.get('/service-types', { params }).then((r) => r.data);
}

// In components:
const [types, setTypes] = useState([]);
useEffect(() => {
  fetchServiceTypes().then(setTypes);
}, []);
```

---

## Critical Data Flows

### 1. Estimate Workflow: Accept/Decline → E-Signature → Workorder → Invoice
```
Estimate (draft)
  ↓
Estimate (sent) → [Customer Reviews]
  ├─ [Decline] → Gather rejection reason → Estimate (declined)
  │             Optional: Request reapproval with new estimate
  │
  └─ [Accept some/all jobs] → Customer approval recorded per job
       ↓
       E-Signature (optional legal consent) → Estimate (approved)
       ↓
       Workorder (created) → Technician assigned
       ├─ [Discover additional work] → Sub-Estimate
       │  ├─ [Customer rejects] → Rejection logged, work continues
       │  └─ [Customer accepts] → Sub-estimate merged into workorder
       │
       └─ [Work completed] → QC checklist + GOA signature
            ↓
            Invoice (created from all approved jobs + sub-estimates)
            ↓
            Financial Entry (GL posting)
```

**Key Tables & Fields:**
- `estimates.status` (draft → sent → approved → declined → converted)
- `estimate_jobs.customer_status` (pending → approved/rejected per job)
- `estimate_signatures` with `legal_consent`, `ip_address`, `document_hash`, `consent_text`
- `approval_audit_log` tracks every action with signer name, IP, device fingerprint
- `workorders.status` (pending → in_progress → completed)
- `workorder_jobs` linked to estimate jobs
- `invoices.workorder_id` (one invoice per completed workorder)

**Related Services:**
- `EstimatePublicLinkService::approveJob()` / `rejectJob()` — Customer approval via public link
- `EstimateEditorService::reject()` — Full estimate rejection with reason
- `WorkorderService::createFromEstimate()` — Workorder creation from approved estimate
- `WorkorderService::createSubEstimate()` — Additional work estimation

### 2. Parts Integration (PartsTech API)
```
Estimate/Workorder --[parts selected]--> PartsCart
PartsCart --[submit]--> PartsTechService (decode VINs, search parts)
  ↓
External API response --[cached]--> Approval workflow --[AP entry created]
```
- **Service**: `src/Services/Integrations/PartsTechService.php`
- **Settings**: Credentials stored in `settings` table with key `integrations.partstech.api_key`
- **Caching**: Results cached in storage (check `storage/` structure)

### 3. Dispatch (Towing) Workflow
```
Job created --[DispatchRecommendationService]--> Driver ranked by workload/availability
Assignment --[websocket update]--> Driver mobile app notification
```
- **Key Service**: `src/Services/Dispatch/DispatchRecommendationService.php`
- **Availability**: `driver_profiles.availability_status`, `technician_schedule` tables
- **Waterfall**: Multiple assignment attempts if driver declines

---

## Database Patterns

### Naming Conventions
- **Tables**: `snake_case` (e.g., `customer_vehicles`)
- **Columns**: `snake_case`; booleans prefix with `is_` or `has_`
- **Foreign Keys**: `{entity}_id` (e.g., `customer_id`, `workorder_id`)
- **Timestamps**: `created_at`, `updated_at` (always included)
- **JSON columns**: Used for structured data (skills, certifications, settings)

### Audit Logging Pattern
All mutations should log to `audit_logs` table:
```php
private function log(?int $actorId, string $event, $entityId, array $context = []): void {
    if ($this->audit === null) return;
    $this->audit->log(new AuditEntry($event, 'service_type', $entityId, $actorId, $context));
}
```
Inject `?AuditLogger $auditLogger` into repositories; initialize in bootstrap.

### Referential Integrity Checks
Before deleting entities, check for dependent records:
```php
private function assertNotReferenced(int $id): void {
    $tables = [
        'estimate_jobs' => 'estimate jobs',
        'invoices' => 'invoices',
    ];
    // SELECT COUNT(*) on each; throw if > 0
}
```

---

## Authentication & Authorization

### JWT + Session Flow
1. **Login**: `POST /auth/login` → JWT token issued (+ refresh token in HTTPOnly cookie)
2. **Middleware**: `JwtService::verify()` extracts user from token
3. **Authorization**: `AccessGate::assert($user, 'permission.name')` checks role-based permission
4. **Rate Limiting**: `Middleware::rateLimit(...)` uses file-based storage in `storage/temp/ratelimits/`

**Key Files:**
- `src/Support/Auth/JwtService.php` — Token creation/verification
- `src/Support/Auth/AccessGate.php` — Permission checks
- `src/Support/Auth/RolePermissions.php` — Role → Permission mappings
- `src/Support/Http/Middleware.php` — Request auth/validation

### Role Hierarchy
Roles defined in config or DB (check `roles` table and `config/auth.php`). Common roles: `admin`, `manager`, `technician`, `customer`. Permissions are dot-notation (e.g., `service_types.view`, `service_types.create`, `service_types.delete`).

---

## Development Workflows

### Build & Run
```bash
# Backend (no build needed; PHP runs directly)
php -S localhost:8000 -t public/ router.php

# Frontend
npm run dev       # Vite dev server (proxies /api to localhost:8000)
npm run build     # Production build to dist/
npm run test:react # Vitest

# Database
php migrate.php   # Run pending migrations
php bin/seed.php  # Seed demo data
```

### Database Migrations
- Location: `database/migrations/`
- Format: `NNN_description.sql` (numeric prefix for ordering)
- **Running**: `php migrate.php` reads from `migrations_completed` table
- **Rollback Strategy**: Not supported; rollbacks use new migrations following these patterns:
  - **Schema rollback**: Create new migration (e.g., `045_undo_column_changes.sql`) with `ALTER TABLE` to revert
  - **Data loss prevention**: Always create backup migration before destructive changes:
    ```sql
    -- 045_undo_column_changes.sql
    -- Before removing a column, archive it or back up data:
    CREATE TABLE IF NOT EXISTS {table}_archive AS SELECT * FROM {table};
    ALTER TABLE {table} DROP COLUMN old_column;
    ```
  - **Example rollback flow**:
    ```sql
    -- 044_add_feature.sql (original)
    ALTER TABLE users ADD COLUMN feature_flag TINYINT(1) DEFAULT 0;
    
    -- 045_remove_feature.sql (rollback)
    ALTER TABLE users DROP COLUMN feature_flag;
    ```
- **Pattern**: Prefer individual migrations over monolithic files; test rollback migrations in dev first

### Testing
- **Test Files**: `tests/*.php` (PHPUnit-style, but manual setup in test_bootstrap.php)
- **In-Memory DB**: Tests use SQLite `:memory:` for isolation (see `DispatchRecommendationWorkloadTest.php`, `InventoryItemRepositoryTest.php`)
- **React Testing**: Vitest configured in `vite.config.js`, use `src/react/**/*.test.jsx`
- **Coverage**: Run tests with `php tests/ServiceTypeRepositoryTest.php` (manual execution)
- **Test Bootstrap**: `tests/test_bootstrap.php` provides common setup; includes vendor autoload

**Example Test Pattern:**
```php
require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Services\ServiceType\ServiceTypeRepository;

class FakeConnection extends Connection {
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    public function pdo(): PDO { return $this->pdo; }
}

// In test:
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE service_types (id INT PRIMARY KEY, name VARCHAR(255))');
$connection = new FakeConnection($pdo);
$repo = new ServiceTypeRepository($connection);
// Test repo methods
```

**React Test Example:**
```javascript
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import MyComponent from './MyComponent';

describe('MyComponent', () => {
  it('renders with data', () => {
    render(<MyComponent data={{}} />);
    expect(screen.getByText(/expected text/i)).toBeTruthy();
  });
});
```

---

## Scheduled Tasks (Cron Jobs)

All cron jobs are run through a unified entry point: `php bin/cron/run.php`

**Recommended crontab entry:**
```bash
* * * * * php /path/to/bin/cron/run.php >> /var/log/phparm-cron.log 2>&1
```

**Available Jobs:**

| Job | Schedule | Purpose | File |
|-----|----------|---------|------|
| **reminders** | Every 15 min | Sends due reminder campaigns to customers | `reminder-campaigns.php` |
| **appointments** | Every hour | Sends reminders for upcoming appointments | `appointment-reminders.php` |
| **inventory** | Daily 8 AM | Sends low stock alerts to staff | `inventory-low-stock.php` |
| **lien-notices** | Daily 7 AM | Flags impound cases for lien notice processing | `storage-lien-notices.php` |
| **cleanup** | Daily 2 AM | Cleans up expired passwords, sessions, temp data | `data-cleanup.php` |
| **waterfall-dispatch** | Every minute | Processes expired job offers, advances driver assignments | `waterfall-dispatch.php` |
| **geofence-processor** | Every minute | Monitors driver geofences and idle detection | `geofence-processor.php` |
| **job-density** | Every hour | Generates heatmap data for dispatcher dashboard | `job-density-snapshot.php` |
| **cms-reindex** | Daily 1 AM | Reindexes CMS pages for search | `cms-search-reindex.php` |

**Running specific jobs:**
```bash
php bin/cron/run.php --job=reminders           # Run reminders job
php bin/cron/run.php --force --job=inventory   # Force run without schedule check
php bin/cron/run.php --list                    # List all available jobs
```

**Implementation Pattern:**
- Each cron script connects to database and performs specific task
- Jobs should be idempotent (safe to run multiple times)
- Use `approval_audit_log`, `audit_logs`, or task-specific tables to track completion
- Errors logged to `storage/logs/app.log` and cron output
- Long-running jobs should check for timeout and break into chunks

---

## Code Style & Conventions

### PHP
- **Namespace**: `App\Services\{Feature}\` for features, `App\Support\` for utilities, `App\Models\` for entities
- **Visibility**: Use `private`, `protected` sparingly; public methods are API surface
- **Type Hints**: Required on method signatures (PHP 8.1+); use nullable types `?Type`
- **Error Handling**: Throw exceptions, don't return error codes; middleware catches and formats responses
- **Logging**: Use `error_log()` for debugging (routed to `storage/logs/app.log`); consider `AuditLogger` for business events

### JavaScript/React
- **Imports**: ESM (`import/export`), no CommonJS in React code
- **Naming**: `camelCase` for variables/functions, `PascalCase` for React components
- **API Calls**: Always wrap in try-catch or `.catch()` chain; handle 401/403 by redirecting to login
- **State**: Prefer `useState` + `useEffect` over class components; custom hooks in `src/react/hooks/`
- **Styling**: Tailwind classes inline (`className="px-4 py-2 ..."`); no inline `style=` objects unless dynamic
- **Service Calls**: Async/await pattern with loading and error states (see existing components for patterns)

**React Component Pattern:**
```jsx
import { useState, useEffect } from 'react';
import { fetchData } from '@/services/data.service';

export default function MyComponent() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    const loadData = async () => {
      setLoading(true);
      setError(null);
      try {
        const result = await fetchData();
        setData(result);
      } catch (err) {
        setError(err.message);
        // Optionally: navigate to login if 401
      } finally {
        setLoading(false);
      }
    };
    loadData();
  }, []);

  if (loading) return <div>Loading...</div>;
  if (error) return <div className="text-red-600">{error}</div>;
  return <div>{/* render data */}</div>;
}
```

**Frontend Error Handling Pattern:**
- Global interceptor in `src/services/api.js` handles 401/403 → redirect to login
- Network errors: Use toast notifications (search codebase for toast patterns)
- Per-component errors: Set local state and display user-friendly message
- Avoid generic "An error occurred" — provide context when possible

---

## API Examples

### Create Estimate
```
POST /api/estimates
Content-Type: application/json
Authorization: Bearer {jwt_token}

{
  "customer_id": 123,
  "vehicle_id": 456,
  "service_type_id": 1,
  "jobs": [
    {
      "title": "Oil Change",
      "description": "Standard oil and filter",
      "quantity": 1,
      "unit_price": 45.00
    }
  ]
}

Response 201:
{
  "id": 789,
  "number": "EST-001",
  "status": "draft",
  "customer_id": 123,
  "vehicle_id": 456,
  "subtotal": 45.00,
  "tax": 3.60,
  "grand_total": 48.60,
  "created_at": "2026-01-19T10:30:00Z"
}
```

### Get Estimate (with approval link for customer)
```
GET /api/estimates/{id}
Authorization: Bearer {jwt_token}

Response 200:
{
  "estimate": {
    "id": 789,
    "number": "EST-001",
    "status": "sent",
    "jobs": [
      {
        "id": 999,
        "title": "Oil Change",
        "customer_status": "pending",
        "amount": 45.00
      }
    ]
  },
  "public_link": "https://app.com/estimate/abc123def456"
}
```

### Customer Approves Job (via public link)
```
POST /api/estimates/links/{token}/approve-job
Content-Type: application/json

{
  "job_id": 999,
  "signer_name": "John Doe",
  "signer_email": "john@example.com",
  "signature_data": "data:image/png;base64,...",
  "legal_consent": true,
  "comment": "Looks good"
}

Response 200:
{
  "success": true,
  "job_id": 999,
  "customer_status": "approved"
}
```

### Create Workorder from Approved Estimate
```
POST /api/workorders/from-estimate
Authorization: Bearer {jwt_token}
Content-Type: application/json

{
  "estimate_id": 789,
  "technician_id": 456
}

Response 201:
{
  "id": 111,
  "number": "WO-001",
  "estimate_id": 789,
  "status": "pending",
  "technician_id": 456,
  "jobs": [
    {
      "id": 999,
      "title": "Oil Change",
      "status": "pending"
    }
  ],
  "created_at": "2026-01-19T11:00:00Z"
}
```

### Create Sub-Estimate (discovered during workorder)
```
POST /api/workorders/{workorder_id}/sub-estimate
Authorization: Bearer {jwt_token}
Content-Type: application/json

{
  "title": "Additional diagnosis required",
  "jobs": [
    {
      "title": "Computer Diagnostic",
      "quantity": 1,
      "unit_price": 85.00
    }
  ]
}

Response 201:
{
  "id": 790,
  "number": "EST-001-SUB",
  "estimate_type": "sub_estimate",
  "parent_estimate_id": 789,
  "workorder_id": 111,
  "status": "draft",
  "public_link": "https://app.com/estimate/xyz789abc123"
}
```

### Convert Workorder to Invoice
```
POST /api/workorders/{workorder_id}/to-invoice
Authorization: Bearer {jwt_token}
Content-Type: application/json

{
  "due_date": "2026-02-19"
}

Response 201:
{
  "id": 555,
  "number": "INV-001",
  "workorder_id": 111,
  "status": "pending",
  "subtotal": 130.00,
  "tax": 10.40,
  "grand_total": 140.40,
  "due_date": "2026-02-19",
  "created_at": "2026-01-19T14:30:00Z"
}
```

---

## Common Gotchas & Patterns

### Exception Handling (Backend)
PHP uses exceptions for all error conditions. Routes and middleware catch exceptions and convert to HTTP responses:
```php
// In service:
if (!$found) {
    throw new RuntimeException("Resource not found", 404);
}

// In middleware (routes/api.php):
try {
    return $controller->method(...);
} catch (RuntimeException $e) {
    return Response::json(['error' => $e->getMessage()], $e->getCode());
}
```
**Common exceptions**: `InvalidArgumentException` (400), `RuntimeException` (500), `LogicException` (logical errors)

### Service Instantiation
Services often depend on `Connection`, `AuditLogger`, other services. **Always pass dependencies via constructor** (no global singletons except `$GLOBALS['env']`). Example:
```php
$connection = new Connection($config['database']);
$audit = new AuditLogger($connection, $config['audit']);
$serviceTypeRepo = new ServiceTypeRepository($connection, null, $audit);
```

### Hydration from Database Rows
Repositories fetch raw associative arrays (`PDO::FETCH_ASSOC`) and hydrate into Models:
```php
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$model = new ServiceType($row); // Model constructor accepts array of data
return $model->toArray(); // For API response
```

### Caching Patterns
Repositories maintain in-memory caches for individual entities (`$cache[$id]`) to avoid repeated DB hits within a request. For list operations, use `$listCache[$filterKey]` with cache invalidation on mutation.

### Frontend Service Pattern
All API communication goes through `src/services/*.service.js` files that wrap the axios client:
```js
// src/services/estimate.service.js
import api from './api';

export function createEstimate(payload) {
  return api.post('/estimates', payload).then(r => r.data);
}

export function fetchEstimate(id) {
  return api.get(`/estimates/${id}`).then(r => r.data);
}
```
Benefits: **Centralized API URLs, consistent error handling, easy mocking in tests**

### CMS Integration
`src/CMS/` contains CMS-specific logic (pages, templates, media). Routes are in `routes/cms.php`. Frontend can render both API-driven React and CMS template-driven pages.

**CMS Structure:**
- **Models**: `src/CMS/Models/` — Page, Template, Category, Menu, Component, Media entities
- **Controllers**: `src/CMS/Controllers/` — PageController, CategoryController, MenuController, MediaController
- **Bootstrap**: `src/CMS/CMSBootstrap.php` — Initializes CMS system, constants, helpers
- **Views/Templates**: CMS pages rendered server-side or as SPA routes

**CMS Features:**
- **Pages**: Create/manage public-facing pages with optional template assignment
- **Templates**: Reusable template structures with dynamic component slots
- **Categories**: Organize pages by section (e.g., "About Us", "Services")
- **Menus**: Build navigation menus with links to pages or external URLs
- **Components**: Reusable content blocks (e.g., hero, testimonials, call-to-action)
- **Media**: Upload and manage images, PDFs, and other assets
- **Search Reindex**: Daily cron job (`cms-search-reindex.php`) updates search index

**API Endpoints (CMS):**
```
GET    /cms/pages                    - List all published pages
GET    /cms/pages/{slug}             - Get page by slug with template rendering
GET    /cms/categories               - List all categories
POST   /cms/admin/pages              - Create page (admin only)
PUT    /cms/admin/pages/{id}         - Edit page (admin only)
DELETE /cms/admin/pages/{id}         - Delete page (admin only)
GET    /cms/menus/{name}             - Get menu by name
POST   /cms/admin/menus              - Create/update menu (admin only)
GET    /cms/media                    - List uploaded media
POST   /cms/admin/media/upload       - Upload file (admin only)
```

**Template Variables:**
CMS templates have access to these variables:
- `$page` — Current page object (title, slug, content, meta_description)
- `$categories` — All available categories
- `$menus` — All available menus
- `$components` — Associated content blocks
- `$site_settings` — Site-wide settings from `settings` table

**Example Page Creation (Admin):**
```
POST /cms/admin/pages
{
  "title": "Services",
  "slug": "services",
  "template_id": 5,
  "is_published": true,
  "content": "Our repair services...",
  "meta_description": "Professional auto repair services",
  "category_id": 2
}
```

**Frontend Rendering:**
- Pages rendered as React components with `src/react/views/CMS/CmsPage.jsx`
- Page content fetched via `GET /cms/pages/{slug}`
- Fallback to CMS page if no React route matches

### Service Ecosystem & Module System
The system has **40+ service modules** in `src/Services/` organized by domain. Common modules:
- **Core**: `Estimate/`, `Workorder/`, `Invoice/`, `Customer/`, `Vehicle/`
- **Operations**: `Dispatch/`, `Appointment/`, `Inspection/`, `QualityControl/`
- **Inventory**: `Inventory/`, `ImportExport/`, `Integrations/` (PartsTech)
- **Financial**: `Financial/`, `Payment/`, `Credit/`, `Reports/`
- **HR**: `Employee/`, `Payroll/`, `Leave/`, `TimeTracking/`
- **Admin**: `User/`, `Role/`, `Settings/`, `Audit/`, `Notification/`

**Service Module Pattern:**
Each service module typically contains:
- `{Service}Repository.php` — Data access & CRUD (with caching)
- `{Service}Service.php` — Business logic & workflows
- `{Service}Controller.php` — REST API endpoints
- Supporting services (e.g., `{Service}ValidationService`, `{Service}StatusNotificationService`)

**Module Toggle System:**
Modules can be enabled/disabled in `config/modules.php`. Check `Roadside/`, `Towing/`, `Warranty/` for optional features that may be disabled on some installations.

### Import/Export & Data Migration
`src/Services/ImportExport/` handles CSV imports and data migrations:
- **Base Class**: `CsvImportService.php` — Template for import workflows
- **Implementations**: `InventoryCsvService`, `CustomerCsvService`, `VendorCsvService`
- **Endpoints**: `POST /api/import/{entity}` with validation and dry-run support
- **Pattern**: Validate on upload → Preview results → Confirm import → Process in chunks

### Frontend Module System
React modules are feature-based:
- `src/react/views/{Feature}/` — Full-page views & layouts
- `src/react/components/{Feature}/` — Reusable components within feature
- `src/react/hooks/` — Custom hooks (useAuth, usePagination, etc.)
- `src/services/{feature}.service.js` — API integration for feature

---

## Key Files to Know

### Models (Database Entities)
- `src/Models/User.php` — Auth user with roles
- `src/Models/Estimate.php` — Estimate (parent or standalone)
- `src/Models/Workorder.php` — Work-order linked to estimate
- `src/Models/Invoice.php` — Invoice with line items
- `src/Models/Customer.php`, `CustomerVehicle.php` — Customer & vehicle records
- `src/Models/JobDamageReport.php`, `JobSignature.php` — Evidence & proof
- `src/Models/InventoryItem.php` — Part/SKU records with vehicle compatibility

### Services (Business Logic) - Core Features
- `src/Services/ServiceType/` — Service catalog CRUD with audit
- `src/Services/Estimate/` — Estimate creation, approval, sub-estimate logic, public links
- `src/Services/Workorder/` — Workorder creation, job assignment, completion, evidence
- `src/Services/Invoice/` — Invoice generation from workorder, payment processing
- `src/Services/Financial/` — GL posting, ledger entries, credit tracking
- `src/Services/Customer/` — Customer records, vehicle history, retention
- `src/Services/Inventory/` — Parts catalog, SKU management, vehicle compatibility

### Services - Operations & Advanced Features
- `src/Services/Dispatch/` — Driver recommendation engine, geofencing, waterfall assignment
- `src/Services/Appointment/` — Scheduling, reminders, availability
- `src/Services/Inspection/` — Vehicle inspections, damage reports, service recommendations
- `src/Services/QualityControl/` — QC checklists, inspections, GOA workflow
- `src/Services/Integrations/` — PartsTech API, VIN decoder, bank feeds
- `src/Services/ImportExport/` — CSV imports for inventory, customers, vendors

### Support (Cross-cutting Concerns)
- `src/Support/Auth/JwtService.php` — Token creation/verification (6-hour expiry)
- `src/Support/Auth/AccessGate.php` — Permission checks (gate->assert)
- `src/Support/Auth/RolePermissions.php` — Role → Permission mappings
- `src/Support/Http/Router.php` — Route matching and middleware dispatch
- `src/Support/Http/Middleware.php` — Auth, validation, rate limiting
- `src/Support/Audit/AuditLogger.php` — Mutation logging with actor/context
- `src/Support/SettingsRepository.php` — Configuration KV store in DB (cache per request)

### Database
- `database/migrations/` — All schema changes (numbered sequentially, 90+ migrations)
- `database/seed_data.sql` — Initial seed data for testing
- Key tables: `users`, `customers`, `customer_vehicles`, `estimates`, `estimate_jobs`, `workorders`, `invoices`, `financial_entries`, `audit_logs`, `approval_audit_log`

### Frontend
- `src/react/index.jsx` — App root, router setup, provider wrappers
- `src/react/views/{Feature}/` — Feature-specific React components (layouts, pages)
- `src/react/components/{Feature}/` — Reusable feature-specific UI components
- `src/react/hooks/` — Custom hooks (useAuth, useApi, usePagination, etc.)
- `src/services/api.js` — Axios instance with global error handling & auth
- `src/services/{feature}.service.js` — API wrappers for each backend feature
- `src/react/context/` — React Context for auth, settings, global state

---

## Debugging Tips

### Backend
- Check `storage/logs/app.log` for PHP errors and application logs
- Add `error_log("Debug message")` in PHP code; output appears in CLI or log file
- Use SQLite `:memory:` DB in tests to isolate issues
- Review middleware chain in `routes/api.php` to ensure route matches and permissions are enforced
- Trace audit logs: `SELECT * FROM audit_logs WHERE entity_type = 'estimate' ORDER BY created_at DESC LIMIT 10`
- Check approval audit trail: `SELECT * FROM approval_audit_log WHERE entity_id = ? ORDER BY created_at DESC`
- Database schema: Use MySQL CLI `DESCRIBE {table}` or review migrations

### Frontend
- Browser DevTools → Network tab to inspect API requests and response status codes
- Check `src/services/api.js` interceptors for auth or response handling issues
- Use React DevTools for component state inspection and hook values
- Test with `npm run test:react` for unit test failures with Vitest
- Console errors: Look for `Failed to [action]: [error message]` patterns
- API mocking: Search for `mock` or `vi.mock` in existing test files for patterns

### Common Issues & Solutions
| Issue | Diagnosis | Solution |
|-------|-----------|----------|
| 401 Unauthorized on API calls | JWT token expired or missing | Clear localStorage, re-login; check token in DevTools Network → Headers |
| Estimate not converting to workorder | Estimate status not "approved" or jobs pending | Verify `estimate_jobs.customer_status = 'approved'` for all jobs |
| Inventory search returns no results | SKU/name not matching LIKE pattern | Check for whitespace, encoding issues; run `php tests/INVENTORY_SEARCH_DEBUGGING.md` |
| Dispatch recommendation empty | No drivers with matching certifications | Verify `driver_certifications` has entries and `availability_status = 'available'` |
| Audit logs not created | AuditLogger injected but not initialized | Ensure AuditLogger is instantiated in bootstrap before service instantiation |

---

## Quick Checklist: Adding a New Feature

1. **Database Schema**: Add migration in `database/migrations/NNN_feature.sql`
2. **Model**: Create `src/Models/FeatureName.php` with properties matching DB columns
3. **Repository**: Create `src/Services/FeatureName/FeatureRepository.php` with CRUD + audit
4. **Controller**: Create `src/Services/FeatureName/FeatureController.php` to expose API methods
5. **Routes**: Add route in `routes/api.php` → call controller method
6. **Frontend Service**: Create `src/services/feature.service.js` → axios calls to `/api/feature`
7. **React Component**: Create `src/react/views/Feature/FeatureView.jsx` → use service + display
8. **Tests**: Add `tests/FeatureRepositoryTest.php` with in-memory DB, test CRUD operations
9. **Permissions**: Define new roles/permissions in `config/auth.php` if needed
10. **Migrations**: Run `php migrate.php` to apply schema

---

## References
- **Agent Briefing**: `.github/AGENT_BRIEFING.md` — Coordination notes, task templates
- **Implementation Plan**: `WORKFLOW_IMPLEMENTATION_PLAN.md` — Detailed feature specs
- **Feature Status**: `.github/IMPLEMENTATION_STATUS.md` — Comprehensive checklist
- **README**: `README.md` — Installation, tech stack, project structure
