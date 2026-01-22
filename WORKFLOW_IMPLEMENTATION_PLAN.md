# Estimate → Workorder → Invoice Workflow

## Overview

This document describes the workflow for managing repair estimates, work orders, and invoices. The workflow supports the following scenarios:

1. **Estimate Rejection** - Customer declines entire estimate
2. **Partial Acceptance** - Customer approves some jobs, rejects others
3. **Full Acceptance** - Customer approves entire estimate → workorder → invoice
4. **Additional Work (Rejected)** - Sub-estimate for additional work, customer declines
5. **Additional Work (Accepted)** - Sub-estimate accepted, merged into final invoice

## Implementation Status

| Phase | Description | Status |
|-------|-------------|--------|
| 1 | Database Schema & Models | ✅ Complete |
| 2 | Backend Services & API | ✅ Complete |
| 3 | Enhanced Audit Trail | ✅ Complete |
| 4 | Sub-Estimate Support | ✅ Complete |
| 5 | Frontend Components | ✅ Complete |
| 6 | Integration & Testing | ✅ Complete |

---

## Phase 1: Database Schema & Models ✅

### Tables Created

#### `workorders` (migration 043)
- Links to estimate, customer, vehicle
- Status workflow: pending → parts_pending → in_progress → awaiting_authorization → on_hold → completed → ready_for_pickup → goa → cancelled
- Priority levels: low, normal, high, urgent
- Technician assignment at workorder level
- Branch support for multi-location shops
- GOA (Gone On Arrival) fields for roadside scenarios
- Financial totals: subtotal, tax, fees, discounts, grand_total

#### `workorder_jobs`
- Links to workorder and source estimate_job
- Individual job status tracking
- Per-job technician assignment
- Time tracking via started_at/completed_at

#### `workorder_items`
- Links to workorder_job and source estimate_item
- Full line item details with pricing
- Core return tracking support

#### `workorder_status_history`
- Complete audit trail of status changes
- Records who changed status and when
- Optional notes for each transition

#### `workorder_signatures` (migration 044)
- Completion signatures with legal compliance
- IP address, user agent, device fingerprint
- Document hash for verification
- Legal consent tracking

### Schema Enhancements

#### `estimates` table
- `parent_estimate_id` - Links sub-estimates to parent
- `workorder_id` - Links sub-estimates to active workorder
- `estimate_type` - Distinguishes 'standard' vs 'sub_estimate'

#### `invoices` table
- `workorder_id` - Links invoice to source workorder

#### `time_entries` table
- `workorder_job_id` - Links time entries to specific workorder jobs

### Models
- `src/Models/Workorder.php`
- `src/Models/WorkorderJob.php`
- `src/Models/WorkorderItem.php`
- `src/Models/WorkorderSignature.php`
- `src/Models/WorkorderStatusHistory.php`

---

## Phase 2: Backend Services & API ✅

### Services

| Service | Location | Purpose |
|---------|----------|---------|
| WorkorderService | `src/Services/Workorder/` | Core business logic |
| WorkorderRepository | `src/Services/Workorder/` | Data access layer |
| WorkorderController | `src/Services/Workorder/` | API endpoint handler |
| WorkorderTimelineService | `src/Services/Workorder/` | Status history queries |
| WorkorderStatusNotificationService | `src/Services/Workorder/` | Email/SMS notifications |
| WorkorderJobEvidenceService | `src/Services/Workorder/` | Job evidence tracking |

### API Endpoints

```
GET    /api/workorders                              List all workorders
GET    /api/workorders/stats                        Workorder statistics
GET    /api/workorders/{id}                         View single workorder
POST   /api/workorders/from-estimate                Create from approved estimate
PATCH  /api/workorders/{id}/status                  Update workorder status
PATCH  /api/workorders/{id}/assign                  Assign technician
PATCH  /api/workorders/{id}/priority                Update priority
POST   /api/workorders/{id}/to-invoice              Convert to invoice
POST   /api/workorders/{id}/sub-estimate            Create sub-estimate
POST   /api/workorders/{id}/add-sub-estimate        Merge approved sub-estimate
GET    /api/workorders/{id}/timeline                Get status history
PATCH  /api/workorders/{id}/jobs/{jobId}/status     Update job status
PATCH  /api/workorders/{id}/jobs/{jobId}/assign     Assign job technician
POST   /api/workorders/{id}/jobs/{jobId}/signature  Capture job signature
GET    /api/workorders/{id}/pull-requests           Inventory pull requests
GET    /api/workorders/{id}/parts-cart              Parts cart management
GET    /api/workorders/{id}/qc-check                QC checklist status
POST   /api/workorders/{id}/qc-check/initialize     Initialize QC checklist
```

### Workflow State Machine

```
Estimate (approved/partial)
    ↓
Workorder (pending)
    ↓
Workorder (parts_pending) ←── waiting for parts
    ↓
Workorder (in_progress) ──→ [needs additional work] ──→ Sub-Estimate
    ↓                                                        ↓
Workorder (awaiting_authorization) ←───────────────── [customer notified]
    ↓                                                        ↓
Workorder (completed) ←────────────────────────────── [if approved]
    ↓
Workorder (ready_for_pickup)
    ↓
Invoice (created)
```

---

## Phase 3: Enhanced Audit Trail ✅

### Tables Created (migration 044)

#### `approval_audit_log`
Comprehensive e-signing audit trail for legal compliance:
- Entity type and ID (estimate, workorder, sub_estimate)
- Action tracking (viewed, job_approved, job_rejected, fully_approved, signature_captured)
- Signer identification (name, email)
- Device information (IP address, user agent, device fingerprint)
- Document verification (signature hash, document hash)
- Extensible metadata as JSON

#### `estimate_signatures`
Enhanced signature capture:
- IP address and user agent
- Device fingerprint
- Document hash at time of signing
- Legal consent checkbox tracking
- Consent text storage

#### `estimate_job_rejections`
Detailed rejection tracking:
- Rejection reason and details
- Signer information
- IP address tracking
- Timestamp

### Service
- `ApprovalAuditService` - Logs views, approvals, rejections, signatures

---

## Phase 4: Sub-Estimate Support ✅

### Workflow

1. Technician discovers additional work needed during repair
2. Creates sub-estimate via `POST /api/workorders/{id}/sub-estimate`
3. Sub-estimate linked to parent estimate and current workorder
4. Public link generated for customer approval
5. Customer reviews and approves/rejects via public page
6. If approved: jobs merged into workorder via `POST /api/workorders/{id}/add-sub-estimate`
7. If rejected: rejection recorded in audit log, original work continues
8. Final invoice includes all approved work (original + sub-estimates)

### Key Methods

```php
// WorkorderService
createSubEstimate(int $workorderId, array $payload, ?int $actorId): Estimate
addSubEstimateJobs(int $workorderId, int $subEstimateId, ?int $actorId): Workorder
getSubEstimates(int $workorderId): array
```

---

## Phase 5: Frontend Components ✅

### Staff Views

| Component | Location | Purpose |
|-----------|----------|---------|
| WorkorderList.jsx | `src/react/views/workorders/` | List/filter workorders |
| WorkorderDetail.jsx | `src/react/views/workorders/` | Manage single workorder |

### Features
- Status badges with color coding
- Technician assignment dropdown
- Job status progression indicators
- Sub-estimate creation modal
- Timeline/audit log viewer
- Convert to invoice button
- Parts cart integration
- Pull request management
- QC checklist integration
- Messaging/chat integration

### Customer Portal

| Component | Location | Purpose |
|-----------|----------|---------|
| Workorders.jsx | `src/react/views/customer-portal/` | Customer workorder list |
| WorkorderTimeline.jsx | `src/react/views/customer-portal/` | Status timeline view |

### Public Views
- PublicEstimateView with signature capture
- Per-job approval/rejection
- Consent checkbox for legal compliance
- Device fingerprint collection

---

## Phase 6: Integration & Testing ✅

### Integration Points

1. **Estimate Approval → Workorder Creation**
   - Auto-create workorder on full/partial approval
   - Triggered via signature capture endpoint

2. **Workorder Completion → Invoice Creation**
   - QC validation (if enabled)
   - Convert via `POST /api/workorders/{id}/to-invoice`

3. **Sub-estimate Approval → Workorder Update**
   - Merge approved jobs via `addSubEstimateJobs()`

4. **Status Change Notifications**
   - Email/SMS via WorkorderStatusNotificationService
   - Template-based notification rules

### Status Synchronization

```
Estimate:  pending → sent → approved/partial → converted
Workorder: pending → parts_pending → in_progress → completed → ready_for_pickup
Invoice:   pending → sent → paid
```

### Supported Scenarios

| Scenario | Status |
|----------|--------|
| Full rejection | ✅ Supported |
| Partial acceptance | ✅ Supported |
| Full acceptance | ✅ Supported |
| Additional work - rejected | ✅ Supported |
| Additional work - accepted | ✅ Supported |

---

## Additional Features

### GOA (Gone On Arrival) Workflow
For roadside/towing scenarios where customer is not present:
- Mark workorder as GOA via status update
- Configure GOA fee amount
- Select billing party (customer or motor club)
- Auto-create financial ledger entry
- Convert to invoice with GOA fee only

### Branch/Multi-Location Support
- `branch_id` on workorders, jobs, items
- Branch filtering in list views
- Branch-specific inventory pull requests

### Inventory Integration
- Auto-generate pull requests for in-stock parts
- Auto-generate stock orders for out-of-stock parts
- Core return tracking for eligible parts

### QC Checklist
- Optional QC requirement before invoicing
- Checklist initialization and completion
- Pass/fail status tracking

---

## Related Migrations

| Migration | Purpose |
|-----------|---------|
| 043_create_workorders.sql | Core workorder tables |
| 044_add_approval_audit_log.sql | E-signing audit trail |
| 071_workorder_status_notifications.sql | Notification rules |
| 077_add_branch_to_workorders.sql | Multi-location support |
| 083_goa_workorder_fields.sql | GOA workflow fields |

---

## Backward Compatibility

- Existing estimates remain fully functional
- Direct estimate → invoice path still supported for simple cases
- Workorder step is optional (but recommended for complex repairs)
- Standalone invoice creation preserved
