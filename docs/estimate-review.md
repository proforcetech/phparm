# Estimate Functionality Review

**STATUS: ✅ COMPLETE**

Reviewed the WordPress plugin implementation under `arm-main` against the standalone system. The following gaps were identified and should be tracked as follow-up tasks.

## Missing status lifecycle coverage
- The plugin exposes a richer status set for estimates (`DRAFT`, `SENT`, `APPROVED`, `DECLINED`, `EXPIRED`, `NEEDS_REAPPROVAL`).【F:arm-main/templates/estimate-view.php†L1-L16】
- The standalone repository currently limits status transitions to `sent`, `approved`, `rejected`, and `expired`, and rejects any other value during updates.【F:src/Services/Estimate/EstimateRepository.php†L103-L118】
- There is no handling for a pending/draft state before first send, a customer-declined state distinct from internal rejection, a “needs re-approval” state for changes after approval, or a converted status after invoicing.

### Tasks
1. [x] **Align estimate lifecycle with plugin statuses**
   - Expand allowed status values to cover draft/pending, sent, approved, declined, expired, needs re-approval, and converted.
   - Update status validation, persistence, and audit logging in `src/Services/Estimate/EstimateRepository.php` and any client-side status filters to reflect the full set.

2. [x] **Support decline and re-approval flows**
   - Introduce explicit handling for customer declines (separate from internal rejections) and the “needs re-approval” state when estimates change after approval.
   - Ensure public link responses and internal updates propagate the correct status, mirroring the plugin’s behavior surfaced in the public estimate view.

3. [x] **Track post-conversion status**
   - Preserve and expose a `converted`/similar status when an estimate is turned into an invoice so reporting can distinguish expired/abandoned estimates from converted ones.
