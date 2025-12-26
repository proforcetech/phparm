# Estimate → Workorder → Invoice Workflow Implementation Plan

## Overview

This document outlines the implementation of an enhanced workflow for managing repair estimates, work orders, and invoices. The workflow supports the following scenarios:

1. **Estimate Rejection** - Customer declines entire estimate
2. **Partial Acceptance** - Customer approves some jobs, rejects others
3. **Full Acceptance** - Customer approves entire estimate → workorder → invoice
4. **Additional Work (Rejected)** - Sub-estimate for additional work, customer declines
5. **Additional Work (Accepted)** - Sub-estimate accepted, merged into final invoice

## Current State Analysis

### Existing Components
- ✅ Estimates with job-based structure (`estimate_jobs`, `estimate_items`)
- ✅ Per-job customer approval tracking (`customer_status`)
- ✅ Digital signatures (`estimate_signatures`)
- ✅ Public shareable links (`estimate_public_links`)
- ✅ Invoice creation from estimates
- ✅ Audit logging framework

### Missing Components
- ❌ Workorder entity (intermediate step between estimate and invoice)
- ❌ Sub-estimate linking (for additional work during repairs)
- ❌ Enhanced audit trail for e-signing (IP, device info, legal compliance)
- ❌ Technician assignment to workorders (not just estimates)
- ❌ Workorder status workflow (pending → in_progress → completed)

---

## Phase 1: Database Schema & Models for Workorders

### New Tables

#### `workorders`
```sql
CREATE TABLE workorders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) NOT NULL UNIQUE,
    estimate_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'in_progress', 'on_hold', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    assigned_technician_id INT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    estimated_completion DATE NULL,
    subtotal DECIMAL(12,2) DEFAULT 0,
    tax DECIMAL(12,2) DEFAULT 0,
    shop_fee DECIMAL(12,2) DEFAULT 0,
    hazmat_disposal_fee DECIMAL(12,2) DEFAULT 0,
    grand_total DECIMAL(12,2) DEFAULT 0,
    internal_notes TEXT NULL,
    customer_notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (estimate_id) REFERENCES estimates(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (vehicle_id) REFERENCES customer_vehicles(id),
    FOREIGN KEY (assigned_technician_id) REFERENCES users(id)
);
```

#### `workorder_jobs`
```sql
CREATE TABLE workorder_jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    estimate_job_id INT UNSIGNED NOT NULL,
    service_type_id INT UNSIGNED NULL,
    title VARCHAR(160) NOT NULL,
    notes TEXT NULL,
    reference VARCHAR(120) NULL,
    status ENUM('pending', 'in_progress', 'completed') NOT NULL DEFAULT 'pending',
    assigned_technician_id INT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    subtotal DECIMAL(12,2) DEFAULT 0,
    tax DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    position INT NOT NULL DEFAULT 0,
    FOREIGN KEY (workorder_id) REFERENCES workorders(id),
    FOREIGN KEY (estimate_job_id) REFERENCES estimate_jobs(id),
    FOREIGN KEY (service_type_id) REFERENCES service_types(id),
    FOREIGN KEY (assigned_technician_id) REFERENCES users(id)
);
```

#### `workorder_items`
```sql
CREATE TABLE workorder_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_job_id INT UNSIGNED NOT NULL,
    estimate_item_id INT UNSIGNED NULL,
    type VARCHAR(40) NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    list_price DECIMAL(12,2) NULL,
    taxable TINYINT(1) DEFAULT 1,
    line_total DECIMAL(12,2) DEFAULT 0,
    position INT NOT NULL DEFAULT 0,
    FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs(id),
    FOREIGN KEY (estimate_item_id) REFERENCES estimate_items(id)
);
```

### Schema Updates

#### `estimates` table additions
```sql
ALTER TABLE estimates
    ADD COLUMN parent_estimate_id INT UNSIGNED NULL AFTER parent_id,
    ADD COLUMN workorder_id INT UNSIGNED NULL AFTER parent_estimate_id,
    ADD COLUMN estimate_type ENUM('standard', 'sub_estimate') NOT NULL DEFAULT 'standard' AFTER status,
    ADD FOREIGN KEY (parent_estimate_id) REFERENCES estimates(id),
    ADD FOREIGN KEY (workorder_id) REFERENCES workorders(id);
```

#### `invoices` table additions
```sql
ALTER TABLE invoices
    ADD COLUMN workorder_id INT UNSIGNED NULL AFTER estimate_id,
    ADD FOREIGN KEY (workorder_id) REFERENCES workorders(id);
```

### New Models
- `Workorder.php`
- `WorkorderJob.php`
- `WorkorderItem.php`

---

## Phase 2: Workorder Backend Services

### Services to Create
1. `WorkorderRepository.php` - Data access layer
2. `WorkorderService.php` - Business logic
3. `WorkorderController.php` - API endpoint handler

### API Endpoints
```
GET    /api/workorders                    - List all workorders
GET    /api/workorders/{id}               - View single workorder
POST   /api/workorders/from-estimate      - Create from approved estimate
PATCH  /api/workorders/{id}/status        - Update workorder status
PATCH  /api/workorders/{id}/assign        - Assign technician
POST   /api/workorders/{id}/to-invoice    - Convert to invoice
POST   /api/workorders/{id}/sub-estimate  - Create sub-estimate for additional work
GET    /api/workorders/{id}/timeline      - Get status history
```

### Workflow State Machine
```
Estimate (approved)
    ↓
Workorder (pending)
    ↓
Workorder (in_progress) ──→ [needs additional work] ──→ Sub-Estimate
    ↓                                                        ↓
Workorder (completed)  ←──────────────────────────── [if approved]
    ↓
Invoice (created)
```

---

## Phase 3: Enhanced Audit Trail for E-Signing

### New Table: `approval_audit_log`
```sql
CREATE TABLE approval_audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('estimate', 'workorder', 'sub_estimate') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    action ENUM('viewed', 'job_approved', 'job_rejected', 'fully_approved', 'fully_rejected', 'signature_captured') NOT NULL,
    job_id INT UNSIGNED NULL,
    signer_name VARCHAR(160) NULL,
    signer_email VARCHAR(160) NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    device_fingerprint VARCHAR(255) NULL,
    geo_location VARCHAR(255) NULL,
    signature_hash VARCHAR(64) NULL,
    comment TEXT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created (created_at)
);
```

### Enhanced Signature Model
Update `estimate_signatures` table:
```sql
ALTER TABLE estimate_signatures
    ADD COLUMN ip_address VARCHAR(45) NULL AFTER signature_data,
    ADD COLUMN user_agent TEXT NULL AFTER ip_address,
    ADD COLUMN device_fingerprint VARCHAR(255) NULL AFTER user_agent,
    ADD COLUMN document_hash VARCHAR(64) NULL AFTER device_fingerprint,
    ADD COLUMN legal_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER document_hash,
    ADD COLUMN consent_text TEXT NULL AFTER legal_consent;
```

### Legal Compliance Features
- Store document hash at time of signature
- Capture explicit consent acknowledgment
- Store consent text shown to signer
- Complete audit trail of all views and actions

---

## Phase 4: Sub-Estimate Support

### Workflow for Additional Work
1. Technician discovers additional work needed during repair
2. Sub-estimate created, linked to parent estimate and current workorder
3. Public link generated for sub-estimate
4. Customer reviews and approves/rejects sub-estimate
5. If approved: sub-estimate jobs added to current workorder
6. If rejected: rejection reason recorded, original work continues
7. Final invoice includes all approved work (original + sub-estimates)

### API Endpoints
```
POST   /api/estimates/{id}/sub-estimate   - Create sub-estimate from parent
GET    /api/estimates/{id}/sub-estimates  - List sub-estimates for parent
POST   /api/workorders/{id}/add-approved  - Add approved sub-estimate to workorder
```

### Database Query: Final Invoice Calculation
```sql
-- Get all approved jobs for final invoice (original + sub-estimates)
SELECT wj.*
FROM workorder_jobs wj
JOIN workorders w ON wj.workorder_id = w.id
WHERE w.id = :workorder_id

UNION ALL

SELECT ej.*
FROM estimate_jobs ej
JOIN estimates e ON ej.estimate_id = e.id
WHERE e.workorder_id = :workorder_id
  AND e.estimate_type = 'sub_estimate'
  AND e.status = 'approved'
  AND ej.customer_status = 'approved';
```

---

## Phase 5: Frontend Workorder Management

### New Views
1. `WorkorderList.vue` - List/filter workorders by status, technician, date
2. `WorkorderDetail.vue` - View/manage single workorder
3. `WorkorderCreate.vue` - Create from approved estimate (workflow trigger)

### UI Components
1. Status badge with color coding
2. Technician assignment dropdown
3. Job status progression indicators
4. Sub-estimate creation modal
5. Timeline/audit log viewer
6. Convert to invoice button

### Customer-Facing Views (Public)
1. Sub-estimate approval page (extends current estimate approval)
2. Enhanced signature capture with consent checkbox
3. Document viewing with timestamp

---

## Phase 6: Workflow Integration & Testing

### Integration Points
1. Estimate approval → Auto-create workorder option
2. Workorder completion → Invoice creation wizard
3. Sub-estimate approval → Add to workorder
4. Email notifications at each status change

### Status Synchronization
- Estimate status: `pending` → `sent` → `approved` → `converted`
- Workorder status: `pending` → `in_progress` → `completed`
- Invoice status: `pending` → `sent` → `paid`

### Test Scenarios (per requirements)
1. Full rejection flow
2. Partial acceptance flow
3. Full acceptance flow
4. Additional work - rejected
5. Additional work - accepted

---

## Implementation Order

### Sprint 1: Foundation (Phases 1-2)
- Database migrations
- Workorder models
- Basic CRUD operations
- Create workorder from estimate

### Sprint 2: Customer Flow (Phases 3-4)
- Enhanced audit trail
- Sub-estimate creation
- Public approval for sub-estimates
- Merge sub-estimate into workorder

### Sprint 3: Frontend & Polish (Phases 5-6)
- Workorder list/detail views
- Technician assignment UI
- Timeline visualization
- End-to-end testing

---

## Notes

### Backward Compatibility
- Existing estimates remain functional
- Direct estimate → invoice path still supported for simple cases
- Workorder is optional step (but recommended for complex repairs)

### Standalone Invoices
- Current standalone invoice creation preserved
- New path: Estimate → Workorder → Invoice (for tracked repairs)

### Technician Assignment
- Can assign at workorder level (all jobs)
- Can assign per-job for split work
- Time entries link to workorder_jobs for tracking
